
<?php

require_once __DIR__ . '/includes/auth.php';
requireRole(['manager', 'storekeeper', 'engineer']);

require_once __DIR__ . '/includes/db.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function inputString($value): string
{
    return is_string($value) || is_numeric($value)
        ? trim((string) $value)
        : '';
}

function validDate(string $date): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $date);

    return $d !== false
        && $d->format('Y-m-d') === $date
        && $date <= date('Y-m-d');
}

/*
 * Lock the relevant material records before checking stock.
 * This also helps coordinate with allocation operations
 * that lock the material record.
 */
function lockMaterialRecords(PDO $pdo, array $materialIds): void
{
    $materialIds = array_values(array_unique(array_map('intval', $materialIds)));
    sort($materialIds);

    if (!$materialIds) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($materialIds), '?'));

    $stmt = $pdo->prepare(
        "SELECT material_id
         FROM materials
         WHERE material_id IN ($placeholders)
         ORDER BY material_id
         FOR UPDATE"
    );

    $stmt->execute($materialIds);
}

/*
 * Check that receipts remaining after a change are enough
 * to cover all allocations for a project/material pair.
 */
function stockIsSafe(
    PDO $pdo,
    int $projectId,
    int $materialId,
    ?int $excludeReceiptId,
    float $quantityToAdd
): bool {
    if ($excludeReceiptId !== null) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity), 0)
             FROM material_receipts
             WHERE project_id = ?
               AND material_id = ?
               AND receipt_id <> ?"
        );

        $stmt->execute([
            $projectId,
            $materialId,
            $excludeReceiptId
        ]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity), 0)
             FROM material_receipts
             WHERE project_id = ?
               AND material_id = ?"
        );

        $stmt->execute([$projectId, $materialId]);
    }

    $received = (float) $stmt->fetchColumn() + $quantityToAdd;

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(quantity), 0)
         FROM allocations
         WHERE project_id = ?
           AND material_id = ?"
    );

    $stmt->execute([$projectId, $materialId]);

    $allocated = (float) $stmt->fetchColumn();

    return $received + 0.000001 >= $allocated;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'error';

$form = [
    'project_name' => '',
    'material_name' => '',
    'supplier_id' => '',
    'quantity' => '',
    'unit_cost' => '0',
    'received_date' => date('Y-m-d'),
    'reference_no' => ''
];

$editingId = filter_var(
    $_GET['edit'] ?? '',
    FILTER_VALIDATE_INT
);

if (!$editingId || $editingId < 1) {
    $editingId = null;
}

// Load record for editing
if ($editingId !== null) {
    $stmt = $pdo->prepare(
        "SELECT
            r.receipt_id,
            r.project_id,
            r.material_id,
            r.supplier_id,
            r.quantity,
            r.unit_cost,
            r.received_date,
            r.reference_no,
            p.project_name,
            m.material_name
         FROM material_receipts r
         JOIN projects p ON p.project_id = r.project_id
         JOIN materials m ON m.material_id = r.material_id
         WHERE r.receipt_id = ?"
    );

    $stmt->execute([$editingId]);
    $editRecord = $stmt->fetch();

    if ($editRecord) {
        $form = [
            'project_name' => $editRecord['project_name'],
            'material_name' => $editRecord['material_name'],
            'supplier_id' => (string) $editRecord['supplier_id'],
            'quantity' => (string) $editRecord['quantity'],
            'unit_cost' => (string) $editRecord['unit_cost'],
            'received_date' => $editRecord['received_date'],
            'reference_no' => $editRecord['reference_no'] ?? ''
        ];
    } else {
        $editingId = null;
        $message = 'The selected delivery could not be found.';
    }
}

// Process submitted forms
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals(
        $_SESSION['csrf_token'],
        inputString($_POST['csrf_token'] ?? '')
    )) {
        http_response_code(403);
        exit('Invalid request token. Refresh and try again.');
    }

    $action = inputString($_POST['action'] ?? '');

    // DELETE DELIVERY
    if ($action === 'delete') {

        $receiptId = filter_var(
            $_POST['receipt_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if (!$receiptId || $receiptId < 1) {
            $message = 'Invalid delivery selected.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "SELECT receipt_id, project_id, material_id
                     FROM material_receipts
                     WHERE receipt_id = ?
                     FOR UPDATE"
                );

                $stmt->execute([$receiptId]);
                $receipt = $stmt->fetch();

                if (!$receipt) {
                    throw new RuntimeException('Delivery not found.');
                }

                lockMaterialRecords($pdo, [
                    (int) $receipt['material_id']
                ]);

                $safe = stockIsSafe(
                    $pdo,
                    (int) $receipt['project_id'],
                    (int) $receipt['material_id'],
                    (int) $receiptId,
                    0
                );

                if (!$safe) {
                    throw new RuntimeException(
                        'Cannot delete this delivery because existing allocations depend on its stock. Reduce or adjust the allocations first.'
                    );
                }

                $stmt = $pdo->prepare(
                    "DELETE FROM material_receipts
                     WHERE receipt_id = ?"
                );

                $stmt->execute([$receiptId]);

                $pdo->commit();

                header('Location: deliveries.php?deleted=1');
                exit;

            } catch (RuntimeException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = $exception->getMessage();

            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($exception->getMessage());

                $message = 'Could not delete this delivery. It may be connected to other records.';
            }
        }

    // ADD OR UPDATE DELIVERY
    } elseif ($action === 'save') {

        $form['project_name'] = inputString($_POST['project_name'] ?? '');
        $form['material_name'] = inputString($_POST['material_name'] ?? '');
        $form['supplier_id'] = inputString($_POST['supplier_id'] ?? '');
        $form['quantity'] = inputString($_POST['quantity'] ?? '');
        $form['unit_cost'] = inputString($_POST['unit_cost'] ?? '');
        $form['received_date'] = inputString($_POST['received_date'] ?? '');
        $form['reference_no'] = inputString($_POST['reference_no'] ?? '');

        $supplierId = filter_var(
            $form['supplier_id'],
            FILTER_VALIDATE_INT
        );

        $quantity = $form['quantity'];
        $unitCost = $form['unit_cost'];

        $validQuantity = is_numeric($quantity)
            && is_finite((float) $quantity)
            && (float) $quantity > 0
            && (float) $quantity <= 9999999999.99;

        $validCost = is_numeric($unitCost)
            && is_finite((float) $unitCost)
            && (float) $unitCost >= 0
            && (float) $unitCost <= 9999999999.99;

        $validReference = strlen($form['reference_no']) <= 100;

        if (
            $form['project_name'] === '' ||
            $form['material_name'] === '' ||
            !$supplierId || $supplierId < 1 ||
            !$validQuantity ||
            !$validCost ||
            !validDate($form['received_date']) ||
            !$validReference
        ) {
            $message = 'Please enter valid project, material, supplier, quantity, cost, date and reference information.';

        } else {

            try {
                $pdo->beginTransaction();

                // Find an exact existing active project.
                $stmt = $pdo->prepare(
                    "SELECT project_id
                     FROM projects
                     WHERE project_name = ?
                       AND status = 'active'
                     LIMIT 1"
                );

                $stmt->execute([$form['project_name']]);
                $projectId = $stmt->fetchColumn();

                // Find an exact existing material.
                $stmt = $pdo->prepare(
                    "SELECT material_id
                     FROM materials
                     WHERE material_name = ?
                     LIMIT 1"
                );

                $stmt->execute([$form['material_name']]);
                $materialId = $stmt->fetchColumn();

                // Confirm supplier exists.
                $stmt = $pdo->prepare(
                    "SELECT supplier_id
                     FROM suppliers
                     WHERE supplier_id = ?"
                );

                $stmt->execute([$supplierId]);
                $supplierExists = $stmt->fetchColumn();

                if (
                    !$projectId ||
                    !$materialId ||
                    !$supplierExists
                ) {
                    throw new RuntimeException(
                        'Select an existing active project, existing material and existing supplier. New projects and materials must first be registered in their management pages.'
                    );
                }

                $projectId = (int) $projectId;
                $materialId = (int) $materialId;

                if ($editingId !== null) {

                    // Lock the original delivery.
                    $stmt = $pdo->prepare(
                        "SELECT receipt_id, project_id, material_id
                         FROM material_receipts
                         WHERE receipt_id = ?
                         FOR UPDATE"
                    );

                    $stmt->execute([$editingId]);
                    $oldRecord = $stmt->fetch();

                    if (!$oldRecord) {
                        throw new RuntimeException(
                            'The delivery being edited no longer exists.'
                        );
                    }

                    $oldProjectId = (int) $oldRecord['project_id'];
                    $oldMaterialId = (int) $oldRecord['material_id'];

                    lockMaterialRecords($pdo, [
                        $oldMaterialId,
                        $materialId
                    ]);

                    // Check the original project/material after removing
                    // the old receipt.
                    $oldPairSafe = stockIsSafe(
                        $pdo,
                        $oldProjectId,
                        $oldMaterialId,
                        (int) $editingId,
                        0
                    );

                    if (!$oldPairSafe) {
                        throw new RuntimeException(
                            'This edit would remove stock needed by existing allocations in the original project. Adjust those allocations first.'
                        );
                    }

                    // Check the destination project/material after
                    // adding the updated quantity.
                    $newPairSafe = stockIsSafe(
                        $pdo,
                        $projectId,
                        $materialId,
                        (int) $editingId,
                        (float) $quantity
                    );

                    if (!$newPairSafe) {
                        throw new RuntimeException(
                            'This edit would make allocated stock exceed received stock in the selected project and material.'
                        );
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE material_receipts
                         SET project_id = ?,
                             material_id = ?,
                             supplier_id = ?,
                             quantity = ?,
                             unit_cost = ?,
                             received_date = ?,
                             reference_no = ?
                         WHERE receipt_id = ?"
                    );

                    $stmt->execute([
                        $projectId,
                        $materialId,
                        $supplierId,
                        $quantity,
                        $unitCost,
                        $form['received_date'],
                        $form['reference_no'] !== ''
                            ? $form['reference_no']
                            : null,
                        $editingId
                    ]);

                    $pdo->commit();

                    header('Location: deliveries.php?updated=1');
                    exit;

                } else {

                    lockMaterialRecords($pdo, [$materialId]);

                    $safe = stockIsSafe(
                        $pdo,
                        $projectId,
                        $materialId,
                        null,
                        (float) $quantity
                    );

                    // A new receipt increases stock, so this check
                    // should normally pass. It also verifies consistency.
                    if (!$safe) {
                        throw new RuntimeException(
                            'The current project and material have an inconsistent stock balance. Please review allocations before adding this delivery.'
                        );
                    }

                    $stmt = $pdo->prepare(
                        "INSERT INTO material_receipts
                        (
                            project_id,
                            material_id,
                            supplier_id,
                            recorded_by,
                            quantity,
                            unit_cost,
                            received_date,
                            reference_no
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    $stmt->execute([
                        $projectId,
                        $materialId,
                        $supplierId,
                        $_SESSION['user_id'],
                        $quantity,
                        $unitCost,
                        $form['received_date'],
                        $form['reference_no'] !== ''
                            ? $form['reference_no']
                            : null
                    ]);

                    $pdo->commit();

                    header('Location: deliveries.php?added=1');
                    exit;
                }

            } catch (RuntimeException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = $exception->getMessage();

            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($exception->getMessage());

                $message = 'Could not save the delivery. Please check the database and try again.';
            }
        }
    }
}

// Success messages
if (isset($_GET['added'])) {
    $message = 'Delivery recorded successfully.';
    $messageType = 'success';
} elseif (isset($_GET['updated'])) {
    $message = 'Delivery updated successfully.';
    $messageType = 'success';
} elseif (isset($_GET['deleted'])) {
    $message = 'Delivery deleted successfully.';
    $messageType = 'success';
}

// Load projects dynamically
$projects = $pdo->query(
    "SELECT project_id, project_name
     FROM projects
     WHERE status = 'active'
     ORDER BY project_name"
)->fetchAll();

// Load materials dynamically
$materials = $pdo->query(
    "SELECT material_id, material_name, unit
     FROM materials
     ORDER BY material_name"
)->fetchAll();

// Load suppliers
$suppliers = $pdo->query(
    "SELECT supplier_id, supplier_name
     FROM suppliers
     ORDER BY supplier_name"
)->fetchAll();

// Load delivery history
$deliveries = $pdo->query(
    "SELECT
        r.receipt_id,
        r.project_id,
        r.material_id,
        r.supplier_id,
        p.project_name,
        m.material_name,
        m.unit,
        s.supplier_name,
        r.quantity,
        r.unit_cost,
        r.received_date,
        r.reference_no,
        u.full_name
     FROM material_receipts r
     JOIN projects p ON p.project_id = r.project_id
     JOIN materials m ON m.material_id = r.material_id
     JOIN suppliers s ON s.supplier_id = r.supplier_id
     JOIN users u ON u.user_id = r.recorded_by
     ORDER BY r.received_date DESC, r.receipt_id DESC"
)->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Material Deliveries | Construction Material Management System</title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

<nav class="navbar">
    <div class="brand">
        <h2>Construction Material Management System</h2>
        <p>Material Allocation and Wastage Tracking</p>
    </div>

    <div class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="stock.php">Stock</a>
        <a href="materials.php">Materials</a>
        <a href="suppliers.php">Suppliers</a>
        <a href="deliveries.php">Deliveries</a>
        <a href="allocations.php">Allocations</a>
        <a href="usage.php">Usage</a>
        <a href="wastage.php">Wastage</a>
        <a href="reports.php">Reports</a>
        <a href="logout.php">Logout</a>
    </div>
</nav>

<main class="container deliveries-page">

    <section class="page-header">
        <h1>Material Deliveries</h1>
        <p>Record, edit and monitor materials received at construction sites.</p>
    </section>

    <section class="card delivery-form-card">

        <h2><?= $editingId !== null ? 'Edit Delivery' : 'Record New Delivery' ?></h2>

        <p class="section-description">
            Enter the project, material and supplier details for the delivery.
        </p>

        <?php if ($message !== ''): ?>
            <div class="alert <?= $messageType === 'success' ? 'alert-success' : 'alert-error' ?>">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <?php if (!$projects || !$materials || !$suppliers): ?>

            <div class="alert alert-error">
                Before recording a delivery, make sure you have at least one
                active project, one material and one supplier.
            </div>

        <?php else: ?>

            <form method="POST" action="deliveries.php<?= $editingId !== null ? '?edit=' . (int) $editingId : '' ?>">

                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">

                <div class="form-grid">

                    <div class="form-group">
                        <label for="project_name">Project *</label>
                        <input
                            type="text"
                            id="project_name"
                            name="project_name"
                            list="project_options"
                            value="<?= e($form['project_name']) ?>"
                            placeholder="Type or select a project"
                            autocomplete="off"
                            required
                        >
                        <datalist id="project_options">
                            <?php foreach ($projects as $p): ?>
                                <option value="<?= e($p['project_name']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <small>Suggestions are loaded from registered active projects.</small>
                    </div>

                    <div class="form-group">
                        <label for="material_name">Material *</label>
                        <input
                            type="text"
                            id="material_name"
                            name="material_name"
                            list="material_options"
                            value="<?= e($form['material_name']) ?>"
                            placeholder="Type or select a material"
                            autocomplete="off"
                            required
                        >
                        <datalist id="material_options">
                            <?php foreach ($materials as $m): ?>
                                <option value="<?= e($m['material_name']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <small>Suggestions are loaded from registered materials.</small>
                    </div>

                    <div class="form-group">
                        <label for="supplier_id">Supplier *</label>
                        <select id="supplier_id" name="supplier_id" required>
                            <option value="">Select supplier</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option
                                    value="<?= e($s['supplier_id']) ?>"
                                    <?= $form['supplier_id'] == $s['supplier_id'] ? 'selected' : '' ?>
                                >
                                    <?= e($s['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="quantity">Quantity Received *</label>
                        <input
                            type="number"
                            id="quantity"
                            name="quantity"
                            min="0.01"
                            max="9999999999.99"
                            step="0.01"
                            value="<?= e($form['quantity']) ?>"
                            placeholder="Enter quantity"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="unit_cost">Cost Per Unit (ETB) *</label>
                        <input
                            type="number"
                            id="unit_cost"
                            name="unit_cost"
                            min="0"
                            max="9999999999.99"
                            step="0.01"
                            value="<?= e($form['unit_cost']) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="received_date">Received Date *</label>
                        <input
                            type="date"
                            id="received_date"
                            name="received_date"
                            max="<?= date('Y-m-d') ?>"
                            value="<?= e($form['received_date']) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="reference_no">Invoice / Reference Number</label>
                        <input
                            type="text"
                            id="reference_no"
                            name="reference_no"
                            maxlength="100"
                            value="<?= e($form['reference_no']) ?>"
                            placeholder="Optional reference number"
                        >
                    </div>

                </div>

                <div class="delivery-form-actions">

                    <button type="submit" class="btn">
                        <?= $editingId !== null ? 'Update Delivery' : 'Save Delivery' ?>
                    </button>

                    <?php if ($editingId !== null): ?>
                        <a href="deliveries.php" class="btn btn-secondary">
                            Cancel Editing
                        </a>
                    <?php endif; ?>

                </div>

            </form>

        <?php endif; ?>

    </section>

    <section class="card delivery-history-card">

        <h2>Delivery History</h2>

        <p class="section-description">
            View and manage all materials previously received.
        </p>

        <div class="table-container">

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Project</th>
                        <th>Material</th>
                        <th>Supplier</th>
                        <th>Quantity</th>
                        <th>Unit Cost</th>
                        <th>Total Cost</th>
                        <th>Reference</th>
                        <th>Recorded By</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($deliveries as $d): ?>

                        <tr>
                            <td><?= e($d['receipt_id']) ?></td>
                            <td><?= e($d['received_date']) ?></td>
                            <td><?= e($d['project_name']) ?></td>
                            <td><?= e($d['material_name']) ?></td>
                            <td><?= e($d['supplier_name']) ?></td>

                            <td>
                                <?= e($d['quantity']) ?>
                                <?= e($d['unit']) ?>
                            </td>

                            <td>
                                <?= number_format((float) $d['unit_cost'], 2) ?> ETB
                            </td>

                            <td>
                                <?= number_format(
                                    (float) $d['quantity'] * (float) $d['unit_cost'],
                                    2
                                ) ?> ETB
                            </td>

                            <td><?= e($d['reference_no'] ?: '-') ?></td>
                            <td><?= e($d['full_name']) ?></td>

                            <td class="delivery-actions">

                                <a
                                    href="deliveries.php?edit=<?= (int) $d['receipt_id'] ?>"
                                    class="edit-btn"
                                >
                                    Edit
                                </a>

                                <form
                                    method="POST"
                                    action="deliveries.php"
                                    onsubmit="return confirm('Are you sure you want to delete this delivery? This action cannot be undone.');"
                                >
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="receipt_id" value="<?= (int) $d['receipt_id'] ?>">

                                    <button type="submit" class="delete-btn">
                                        Delete
                                    </button>
                                </form>

                            </td>
                        </tr>

                    <?php endforeach; ?>

                    <?php if (!$deliveries): ?>
                        <tr>
                            <td colspan="11" class="empty-state">
                                No deliveries recorded yet.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <footer class="footer">
        Construction Site Material Allocation and Wastage Tracking System
    </footer>

</main>

</body>
</html>
