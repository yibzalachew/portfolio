
<?php

require_once __DIR__ . '/includes/auth.php';
requireRole(['manager', 'storekeeper', 'engineer']);

require_once __DIR__ . '/includes/db.php';

// Escape output safely
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'success';
$editingMaterialId = 0;

// Default form values
$formData = [
    'material_name' => '',
    'category' => '',
    'unit' => '',
    'minimum_stock' => '0'
];

// Check whether an existing material is being edited
if (isset($_GET['edit']) && is_scalar($_GET['edit'])) {
    $editingMaterialId = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT
    ) ?: 0;

    if ($editingMaterialId > 0) {
        $editStmt = $pdo->prepare(
            "SELECT material_id, material_name, category, unit, minimum_stock
             FROM materials
             WHERE material_id = ?"
        );

        $editStmt->execute([$editingMaterialId]);
        $editingMaterial = $editStmt->fetch();

        if ($editingMaterial) {
            $formData = [
                'material_name' => $editingMaterial['material_name'],
                'category' => $editingMaterial['category'] ?? '',
                'unit' => $editingMaterial['unit'],
                'minimum_stock' => $editingMaterial['minimum_stock']
            ];
        } else {
            $editingMaterialId = 0;
            $message = 'The selected material could not be found.';
            $messageType = 'error';
        }
    }
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid request token. Refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    /*
     * DELETE MATERIAL
     */
    if ($action === 'delete') {

        $idValue = $_POST['material_id'] ?? '';

        $deleteId = (
            is_scalar($idValue) &&
            filter_var($idValue, FILTER_VALIDATE_INT)
        ) ? (int) $idValue : 0;

        if ($deleteId <= 0) {
            $message = 'Invalid material selected.';
            $messageType = 'error';

        } else {

            try {
                $pdo->beginTransaction();

                // Lock the material record
                $lockStmt = $pdo->prepare(
                    "SELECT material_id
                     FROM materials
                     WHERE material_id = ?
                     FOR UPDATE"
                );

                $lockStmt->execute([$deleteId]);

                if (!$lockStmt->fetch()) {
                    throw new RuntimeException('Material not found.');
                }

                // Check whether the material has delivery records
                $receiptStmt = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM material_receipts
                     WHERE material_id = ?"
                );

                $receiptStmt->execute([$deleteId]);
                $receiptCount = (int) $receiptStmt->fetchColumn();

                // Check whether the material has allocation records
                $allocationStmt = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM allocations
                     WHERE material_id = ?"
                );

                $allocationStmt->execute([$deleteId]);
                $allocationCount = (int) $allocationStmt->fetchColumn();

                if ($receiptCount > 0 || $allocationCount > 0) {
                    $pdo->rollBack();

                    $message = 'Cannot delete this material because it is used in existing delivery or allocation records.';
                    $messageType = 'error';

                } else {

                    $deleteStmt = $pdo->prepare(
                        "DELETE FROM materials
                         WHERE material_id = ?"
                    );

                    $deleteStmt->execute([$deleteId]);

                    if ($deleteStmt->rowCount() !== 1) {
                        throw new RuntimeException('Material could not be deleted.');
                    }

                    $pdo->commit();

                    header('Location: materials.php?deleted=1');
                    exit;
                }

            } catch (PDOException $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // Foreign-key protection
                if ($exception->getCode() === '23000') {
                    $message = 'Cannot delete this material because it is connected to existing records.';
                } else {
                    error_log($exception->getMessage());
                    $message = 'Could not delete the material. Please try again.';
                }

                $messageType = 'error';

            } catch (RuntimeException $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = $exception->getMessage();
                $messageType = 'error';
            }
        }

    /*
     * ADD OR UPDATE MATERIAL
     */
    } elseif (in_array($action, ['add', 'update'], true)) {

        $idValue = $_POST['material_id'] ?? '';

        $editingMaterialId = (
            is_scalar($idValue) &&
            filter_var($idValue, FILTER_VALIDATE_INT)
        ) ? (int) $idValue : 0;

        $nameValue = $_POST['material_name'] ?? '';
        $categoryValue = $_POST['category'] ?? '';
        $unitValue = $_POST['unit'] ?? '';
        $minimumStock = $_POST['minimum_stock'] ?? '';

        $name = is_string($nameValue) ? trim($nameValue) : '';
        $category = is_string($categoryValue) ? trim($categoryValue) : '';
        $unit = is_string($unitValue) ? trim($unitValue) : '';

        $formData = [
            'material_name' => $name,
            'category' => $category,
            'unit' => $unit,
            'minimum_stock' => is_scalar($minimumStock)
                ? (string) $minimumStock
                : ''
        ];

        $isValidMinimumStock =
            is_scalar($minimumStock) &&
            is_numeric($minimumStock) &&
            is_finite((float) $minimumStock) &&
            (float) $minimumStock >= 0 &&
            (float) $minimumStock <= 9999999999.99;

        if (
            ($action === 'update' && $editingMaterialId <= 0) ||
            $name === '' ||
            strlen($name) > 100 ||
            strlen($category) > 100 ||
            $unit === '' ||
            strlen($unit) > 30 ||
            !$isValidMinimumStock
        ) {
            $message = 'Enter valid material details and a non-negative minimum stock.';
            $messageType = 'error';

        } else {

            try {

                if ($action === 'add') {

                    $stmt = $pdo->prepare(
                        "INSERT INTO materials
                        (material_name, category, unit, minimum_stock)
                        VALUES (?, ?, ?, ?)"
                    );

                    $stmt->execute([
                        $name,
                        $category !== '' ? $category : null,
                        $unit,
                        $minimumStock
                    ]);

                    header('Location: materials.php?added=1');
                    exit;

                } else {

                    // Confirm that the material exists
                    $checkStmt = $pdo->prepare(
                        "SELECT material_id
                         FROM materials
                         WHERE material_id = ?"
                    );

                    $checkStmt->execute([$editingMaterialId]);

                    if (!$checkStmt->fetch()) {
                        throw new RuntimeException('Material not found.');
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE materials
                         SET material_name = ?,
                             category = ?,
                             unit = ?,
                             minimum_stock = ?
                         WHERE material_id = ?"
                    );

                    $stmt->execute([
                        $name,
                        $category !== '' ? $category : null,
                        $unit,
                        $minimumStock,
                        $editingMaterialId
                    ]);

                    header('Location: materials.php?updated=1');
                    exit;
                }

            } catch (PDOException $exception) {

                if ($exception->getCode() === '23000') {
                    $message = 'That material name already exists.';
                } else {
                    error_log($exception->getMessage());
                    $message = 'Could not save the material. Please try again.';
                }

                $messageType = 'error';

            } catch (RuntimeException $exception) {

                $message = $exception->getMessage();
                $messageType = 'error';
                $editingMaterialId = 0;
            }
        }

    } else {
        $message = 'Invalid request.';
        $messageType = 'error';
    }
}

// Success messages
if (isset($_GET['added'])) {
    $message = 'Material added successfully.';
    $messageType = 'success';
}

if (isset($_GET['updated'])) {
    $message = 'Material updated successfully.';
    $messageType = 'success';
}

if (isset($_GET['deleted'])) {
    $message = 'Material deleted successfully.';
    $messageType = 'success';
}

// Get registered materials
$stmt = $pdo->query(
    "SELECT material_id, material_name, category, unit, minimum_stock
     FROM materials
     ORDER BY material_name"
);

$materials = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Materials | Construction Material Management System</title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="materials-page">

<nav class="navbar">

    <div class="brand">
        <h2>Construction Material Management System</h2>
        <p>Material Allocation and Wastage Tracking</p>
    </div>

    <div class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="stock.php">Stock</a>
        <a href="materials.php" class="active">Materials</a>
        <a href="suppliers.php">Suppliers</a>
        <a href="deliveries.php">Deliveries</a>
        <a href="allocations.php">Allocations</a>
        <a href="usage.php">Usage</a>
        <a href="wastage.php">Wastage</a>
        <a href="reports.php">Reports</a>
        <a href="logout.php">Logout</a>
    </div>

</nav>

<main class="container materials-page-content">

    <section class="page-header">
        <h1>Material Management</h1>
        <p>
            Register, view, update, and delete construction materials
            and their minimum stock levels.
        </p>
    </section>

    <?php if ($message !== ''): ?>

        <div class="alert <?= $messageType === 'error' ? 'alert-error' : 'alert-success' ?>">
            <?= e($message) ?>
        </div>

    <?php endif; ?>

    <section class="card materials-form-card">

        <h2>
            <?= $editingMaterialId > 0 ? 'Edit Material' : 'Add New Material' ?>
        </h2>

        <p class="materials-description">
            <?= $editingMaterialId > 0
                ? 'Update the selected material information below.'
                : 'Enter the details of the construction material you want to register.' ?>
        </p>

        <form method="POST" action="materials.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="<?= $editingMaterialId > 0 ? 'update' : 'add' ?>"
            >

            <?php if ($editingMaterialId > 0): ?>
                <input
                    type="hidden"
                    name="material_id"
                    value="<?= e($editingMaterialId) ?>"
                >
            <?php endif; ?>

            <div class="form-grid">

                <div class="form-group">
                    <label for="material_name">Material Name *</label>

                    <input
                        id="material_name"
                        name="material_name"
                        type="text"
                        maxlength="100"
                        value="<?= e($formData['material_name']) ?>"
                        placeholder="e.g. Cement"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="category">Category</label>

                    <input
                        id="category"
                        name="category"
                        type="text"
                        maxlength="100"
                        value="<?= e($formData['category']) ?>"
                        placeholder="e.g. Building Materials"
                    >
                </div>

                <div class="form-group">
                    <label for="unit">Unit *</label>

                    <input
                        id="unit"
                        name="unit"
                        type="text"
                        maxlength="30"
                        value="<?= e($formData['unit']) ?>"
                        placeholder="bag, kg, m3, piece"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="minimum_stock">Minimum Stock *</label>

                    <input
                        id="minimum_stock"
                        name="minimum_stock"
                        type="number"
                        min="0"
                        max="9999999999.99"
                        step="0.01"
                        value="<?= e($formData['minimum_stock']) ?>"
                        required
                    >
                </div>

            </div>

            <div class="materials-form-actions">

                <button type="submit" class="btn">
                    <?= $editingMaterialId > 0 ? 'Update Material' : 'Save Material' ?>
                </button>

                <?php if ($editingMaterialId > 0): ?>
                    <a href="materials.php" class="btn btn-secondary">
                        Cancel
                    </a>
                <?php endif; ?>

            </div>

        </form>

    </section>

    <section class="card materials-table-card">

        <div class="materials-table-heading">
            <div>
                <h2>Registered Materials</h2>
                <p>
                    Total registered materials:
                    <strong><?= count($materials) ?></strong>
                </p>
            </div>
        </div>

        <div class="table-container materials-table-container">

            <table class="materials-table">

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Material</th>
                        <th>Category</th>
                        <th>Unit</th>
                        <th>Minimum Stock</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($materials as $material): ?>

                        <tr>

                            <td><?= e($material['material_id']) ?></td>

                            <td class="materials-name">
                                <?= e($material['material_name']) ?>
                            </td>

                            <td>
                                <?= e($material['category'] ?? '-') ?>
                            </td>

                            <td><?= e($material['unit']) ?></td>

                            <td>
                                <?= number_format((float) $material['minimum_stock'], 2) ?>
                            </td>

                            <td class="materials-action-cell">

                                <a
                                    href="materials.php?edit=<?= e($material['material_id']) ?>"
                                    class="materials-edit-btn"
                                >
                                    Edit
                                </a>

                                <form
                                    method="POST"
                                    action="materials.php"
                                    class="materials-delete-form"
                                    onsubmit="return confirm('Are you sure you want to delete this material? This action cannot be undone.');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($_SESSION['csrf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >

                                    <input
                                        type="hidden"
                                        name="material_id"
                                        value="<?= e($material['material_id']) ?>"
                                    >

                                    <button type="submit" class="materials-delete-btn">
                                        Delete
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (!$materials): ?>

                        <tr>
                            <td colspan="6" class="materials-empty">
                                No materials registered yet.
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
