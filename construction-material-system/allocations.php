<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['manager', 'storekeeper', 'engineer']);

require_once __DIR__ . '/includes/db.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = '';

$today = date('Y-m-d');

$old = [
    'quantity' => '',
    'allocation_date' => $today
];

$editing = false;
$editId = 0;

// Process form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !is_string($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new RuntimeException(
                'Invalid form token. Refresh the page and try again.'
            );
        }

        $action = $_POST['action'] ?? '';

        // DELETE ALLOCATION
        if ($action === 'delete') {
            $allocationId = filter_var(
                $_POST['allocation_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$allocationId || $allocationId < 1) {
                throw new RuntimeException('Invalid allocation selected.');
            }

            $pdo->beginTransaction();

            $allocationStmt = $pdo->prepare("
                SELECT allocation_id, material_id
                FROM allocations
                WHERE allocation_id = ?
                FOR UPDATE
            ");
            $allocationStmt->execute([$allocationId]);
            $allocation = $allocationStmt->fetch();

            if (!$allocation) {
                throw new RuntimeException('Allocation not found.');
            }

            // Lock the material row to serialize stock changes.
            $materialLock = $pdo->prepare("
                SELECT material_id
                FROM materials
                WHERE material_id = ?
                FOR UPDATE
            ");
            $materialLock->execute([$allocation['material_id']]);

            // Do not delete allocations with dependent records.
            $dependencyStmt = $pdo->prepare("
                SELECT
                    (SELECT COUNT(*)
                     FROM material_usage
                     WHERE allocation_id = ?) AS usage_count,

                    (SELECT COUNT(*)
                     FROM wastage_records
                     WHERE allocation_id = ?) AS wastage_count
            ");
            $dependencyStmt->execute([
                $allocationId,
                $allocationId
            ]);
            $dependencies = $dependencyStmt->fetch();

            if (
                (int) $dependencies['usage_count'] > 0 ||
                (int) $dependencies['wastage_count'] > 0
            ) {
                throw new RuntimeException(
                    'Cannot delete this allocation because it has existing usage or wastage records.'
                );
            }

            $deleteStmt = $pdo->prepare("
                DELETE FROM allocations
                WHERE allocation_id = ?
            ");
            $deleteStmt->execute([$allocationId]);

            $pdo->commit();

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $message = 'Allocation deleted successfully.';
            $messageType = 'success';
        }

        // CREATE OR UPDATE ALLOCATION
        elseif ($action === 'save') {
            $editId = filter_var(
                $_POST['allocation_id'] ?? 0,
                FILTER_VALIDATE_INT
            );

            if ($editId === false || $editId < 0) {
                throw new RuntimeException('Invalid allocation ID.');
            }

            $quantityInput = trim((string) ($_POST['quantity'] ?? ''));
            $allocationDate = trim((string) ($_POST['allocation_date'] ?? ''));

            $validQuantity = is_numeric($quantityInput)
                && is_finite((float) $quantityInput)
                && (float) $quantityInput > 0
                && (float) $quantityInput <= 9999999999.99;

            $quantity = $validQuantity ? (float) $quantityInput : 0;

            $dateObject = DateTime::createFromFormat(
                '!Y-m-d',
                $allocationDate
            );

            if (!$validQuantity) {
                throw new RuntimeException(
                    'Enter a valid quantity greater than zero.'
                );
            }

            if (
                !$dateObject ||
                $dateObject->format('Y-m-d') !== $allocationDate ||
                $allocationDate > $today
            ) {
                throw new RuntimeException(
                    'Enter a valid allocation date that is not in the future.'
                );
            }

            $pdo->beginTransaction();

            if ($editId > 0) {
                // Lock the existing allocation.
                $allocationStmt = $pdo->prepare("
                    SELECT *
                    FROM allocations
                    WHERE allocation_id = ?
                    FOR UPDATE
                ");
                $allocationStmt->execute([$editId]);
                $allocation = $allocationStmt->fetch();

                if (!$allocation) {
                    throw new RuntimeException('Allocation not found.');
                }

                $projectId = (int) $allocation['project_id'];
                $materialId = (int) $allocation['material_id'];

                // Lock material row.
                $materialLock = $pdo->prepare("
                    SELECT material_id
                    FROM materials
                    WHERE material_id = ?
                    FOR UPDATE
                ");
                $materialLock->execute([$materialId]);

                // Calculate quantities already used and reserved.
                $dependencyStmt = $pdo->prepare("
                    SELECT
                        COALESCE((
                            SELECT SUM(quantity_used)
                            FROM material_usage
                            WHERE allocation_id = ?
                        ), 0) AS used_qty,

                        COALESCE((
                            SELECT SUM(quantity_wasted)
                            FROM wastage_records
                            WHERE allocation_id = ?
                              AND approval_status IN ('pending', 'approved')
                        ), 0) AS reserved_wastage
                ");
                $dependencyStmt->execute([$editId, $editId]);
                $dependency = $dependencyStmt->fetch();

                $usedQty = (float) $dependency['used_qty'];
                $reservedWastage = (float) $dependency['reserved_wastage'];

                $minimumAllowed = $usedQty + $reservedWastage;

                if ($quantity + 0.000001 < $minimumAllowed) {
                    throw new RuntimeException(
                        'The new allocation quantity cannot be less than material already used plus pending or approved wastage (' .
                        number_format($minimumAllowed, 2) . ').'
                    );
                }

                // Check store stock, excluding this allocation.
                $stockStmt = $pdo->prepare("
                    SELECT
                        COALESCE((
                            SELECT SUM(quantity)
                            FROM material_receipts
                            WHERE project_id = ?
                              AND material_id = ?
                        ), 0)
                        -
                        COALESCE((
                            SELECT SUM(quantity)
                            FROM allocations
                            WHERE project_id = ?
                              AND material_id = ?
                              AND allocation_id <> ?
                        ), 0) AS available_stock
                ");

                $stockStmt->execute([
                    $projectId,
                    $materialId,
                    $projectId,
                    $materialId,
                    $editId
                ]);

                $availableStock = (float) $stockStmt->fetchColumn();

                if ($quantity > $availableStock + 0.000001) {
                    throw new RuntimeException(
                        'Insufficient store stock. Available quantity for this allocation: ' .
                        number_format(max(0, $availableStock), 2) . '.'
                    );
                }

                $updateStmt = $pdo->prepare("
                    UPDATE allocations
                    SET quantity = ?,
                        allocation_date = ?
                    WHERE allocation_id = ?
                ");

                $updateStmt->execute([
                    $quantity,
                    $allocationDate,
                    $editId
                ]);

                $pdo->commit();

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                $message = 'Allocation updated successfully.';
                $messageType = 'success';
            } else {
                // Create a new allocation.
                $projectId = filter_var(
                    $_POST['project_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                $materialId = filter_var(
                    $_POST['material_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                $workAreaId = filter_var(
                    $_POST['work_area_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (!$projectId || !$materialId || !$workAreaId) {
                    throw new RuntimeException(
                        'Select a project, material and work area.'
                    );
                }

                $materialLock = $pdo->prepare("
                    SELECT material_id
                    FROM materials
                    WHERE material_id = ?
                    FOR UPDATE
                ");
                $materialLock->execute([$materialId]);

                if (!$materialLock->fetch()) {
                    throw new RuntimeException(
                        'The selected material does not exist.'
                    );
                }

                $projectStmt = $pdo->prepare("
                    SELECT project_id
                    FROM projects
                    WHERE project_id = ?
                      AND status = 'active'
                ");
                $projectStmt->execute([$projectId]);

                if (!$projectStmt->fetch()) {
                    throw new RuntimeException(
                        'Select a valid active project.'
                    );
                }

                $areaStmt = $pdo->prepare("
                    SELECT work_area_id
                    FROM work_areas
                    WHERE work_area_id = ?
                      AND project_id = ?
                ");
                $areaStmt->execute([$workAreaId, $projectId]);

                if (!$areaStmt->fetch()) {
                    throw new RuntimeException(
                        'The selected work area does not belong to this project.'
                    );
                }

                $stockStmt = $pdo->prepare("
                    SELECT
                        COALESCE((
                            SELECT SUM(quantity)
                            FROM material_receipts
                            WHERE project_id = ?
                              AND material_id = ?
                        ), 0)
                        -
                        COALESCE((
                            SELECT SUM(quantity)
                            FROM allocations
                            WHERE project_id = ?
                              AND material_id = ?
                        ), 0) AS available_stock
                ");

                $stockStmt->execute([
                    $projectId,
                    $materialId,
                    $projectId,
                    $materialId
                ]);

                $availableStock = (float) $stockStmt->fetchColumn();

                if ($quantity > $availableStock + 0.000001) {
                    throw new RuntimeException(
                        'Insufficient store stock. Available quantity: ' .
                        number_format(max(0, $availableStock), 2) . '.'
                    );
                }

                $insert = $pdo->prepare("
                    INSERT INTO allocations
                    (
                        project_id,
                        material_id,
                        work_area_id,
                        issued_by,
                        quantity,
                        allocation_date
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $insert->execute([
                    $projectId,
                    $materialId,
                    $workAreaId,
                    $_SESSION['user_id'],
                    $quantity,
                    $allocationDate
                ]);

                $pdo->commit();

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                $message = 'Material allocated successfully!';
                $messageType = 'success';
            }
        } else {
            throw new RuntimeException('Invalid form action.');
        }

    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($error instanceof RuntimeException) {
            $message = $error->getMessage();
        } else {
            error_log($error->getMessage());
            $message = 'An unexpected error occurred. Please try again.';
        }

        $messageType = 'error';

        if (isset($_POST['action']) && $_POST['action'] === 'save') {
            $old['quantity'] = $_POST['quantity'] ?? '';
            $old['allocation_date'] = $_POST['allocation_date'] ?? $today;
        }
    }
}

// Load active projects.
$projects = $pdo->query("
    SELECT project_id, project_name
    FROM projects
    WHERE status = 'active'
    ORDER BY project_name
")->fetchAll();

// Load materials.
$materials = $pdo->query("
    SELECT material_id, material_name, unit
    FROM materials
    ORDER BY material_name
")->fetchAll();

// Load work areas.
$workAreas = $pdo->query("
    SELECT work_area_id, project_id, area_name
    FROM work_areas
    ORDER BY area_name
")->fetchAll();

// Load allocation being edited.
$editAllocation = null;
$getEditId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

if ($getEditId && $getEditId > 0) {
    $editStmt = $pdo->prepare("
        SELECT
            a.*,
            p.project_name,
            m.material_name,
            m.unit,
            wa.area_name
        FROM allocations a
        INNER JOIN projects p ON p.project_id = a.project_id
        INNER JOIN materials m ON m.material_id = a.material_id
        INNER JOIN work_areas wa ON wa.work_area_id = a.work_area_id
        WHERE a.allocation_id = ?
    ");
    $editStmt->execute([$getEditId]);
    $editAllocation = $editStmt->fetch();

    if ($editAllocation) {
        $editing = true;
        $editId = (int) $editAllocation['allocation_id'];

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $old['quantity'] = $editAllocation['quantity'];
            $old['allocation_date'] = $editAllocation['allocation_date'];
        }
    } else {
        $message = 'Allocation not found.';
        $messageType = 'error';
    }
}

// Load allocation history.
$history = $pdo->query("
    SELECT
        a.allocation_id,
        a.project_id,
        a.material_id,
        a.work_area_id,
        p.project_name,
        m.material_name,
        m.unit,
        wa.area_name,
        a.quantity,
        a.allocation_date,
        u.full_name AS issued_by_name,

        COALESCE((
            SELECT SUM(mu.quantity_used)
            FROM material_usage mu
            WHERE mu.allocation_id = a.allocation_id
        ), 0) AS used_qty,

        COALESCE((
            SELECT SUM(wr.quantity_wasted)
            FROM wastage_records wr
            WHERE wr.allocation_id = a.allocation_id
              AND wr.approval_status IN ('pending', 'approved')
        ), 0) AS reserved_wastage,

        (
            SELECT COUNT(*)
            FROM material_usage mu
            WHERE mu.allocation_id = a.allocation_id
        ) AS usage_count,

        (
            SELECT COUNT(*)
            FROM wastage_records wr
            WHERE wr.allocation_id = a.allocation_id
        ) AS wastage_count

    FROM allocations a
    INNER JOIN projects p ON p.project_id = a.project_id
    INNER JOIN materials m ON m.material_id = a.material_id
    INNER JOIN work_areas wa ON wa.work_area_id = a.work_area_id
    INNER JOIN users u ON u.user_id = a.issued_by
    ORDER BY a.allocation_id DESC
")->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Material Allocation</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

<nav class="navbar">
    <div class="container">
        <div class="brand">
            <h2>Construction Material System</h2>
            <p>Material Allocation</p>
        </div>

        <div class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="projects.php">Projects</a>
            <a href="materials.php">Materials</a>
            <a href="deliveries.php">Deliveries</a>
            <a href="allocations.php">Allocations</a>
            <a href="usage.php">Usage</a>
            <a href="wastage.php">Wastage</a>
            <a href="stock.php">Stock</a>
            <a href="reports.php">Reports</a>
            <a href="logout.php" class="btn btn-danger">Logout</a>
        </div>
    </div>
</nav>

<main class="container allocations-page">

    <div class="page-header">
        <h1>Material Allocation</h1>
        <p>Issue construction materials from the store to a project work area.</p>
    </div>

    <section class="card">

        <h2><?= $editing ? 'Edit Allocation' : 'Allocate Material' ?></h2>

        <?php if ($message !== ''): ?>
            <div class="alert <?= $messageType === 'success' ? 'alert-success' : 'alert-error' ?>">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($editing && $editAllocation): ?>

            <div class="allocation-edit-info">
                <p><strong>Project:</strong> <?= e($editAllocation['project_name']) ?></p>
                <p><strong>Material:</strong> <?= e($editAllocation['material_name']) ?> (<?= e($editAllocation['unit']) ?>)</p>
                <p><strong>Work Area:</strong> <?= e($editAllocation['area_name']) ?></p>
                <p class="section-description">
                    Project, material and work area are kept unchanged to protect
                    existing usage and wastage records. You can edit quantity and date.
                </p>
            </div>

        <?php endif; ?>

        <?php if (!$editing && (!$projects || !$materials || !$workAreas)): ?>

            <div class="alert alert-error">
                Please make sure that an active project, at least one material
                and at least one work area exist before allocating materials.
            </div>

        <?php else: ?>

            <form method="POST" action="allocations.php<?= $editing ? '?edit=' . $editId : '' ?>">

                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="allocation_id" value="<?= $editing ? $editId : 0 ?>">

                <?php if (!$editing): ?>

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="project_id">Project *</label>
                            <select name="project_id" id="project_id" required>
                                <option value="">Select project</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= e($project['project_id']) ?>">
                                        <?= e($project['project_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="material_id">Material *</label>
                            <select name="material_id" id="material_id" required>
                                <option value="">Select material</option>
                                <?php foreach ($materials as $material): ?>
                                    <option value="<?= e($material['material_id']) ?>">
                                        <?= e($material['material_name']) ?> (<?= e($material['unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="work_area_id">Work Area *</label>
                            <select name="work_area_id" id="work_area_id" required>
                                <option value="">Select work area</option>
                                <?php foreach ($workAreas as $area): ?>
                                    <option
                                        value="<?= e($area['work_area_id']) ?>"
                                        data-project="<?= e($area['project_id']) ?>"
                                    >
                                        <?= e($area['area_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                <?php endif; ?>

                <div class="form-grid">

                    <div class="form-group">
                        <label for="quantity">Quantity to Allocate *</label>
                        <input
                            type="number"
                            name="quantity"
                            id="quantity"
                            min="0.01"
                            max="9999999999.99"
                            step="0.01"
                            value="<?= e($old['quantity']) ?>"
                            placeholder="Enter quantity"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="allocation_date">Allocation Date *</label>
                        <input
                            type="date"
                            name="allocation_date"
                            id="allocation_date"
                            max="<?= e($today) ?>"
                            value="<?= e($old['allocation_date']) ?>"
                            required
                        >
                    </div>

                </div>

                <p class="section-description">
                    The system checks available store stock before saving an
                    allocation. You cannot allocate more than the available quantity.
                </p>

                <button type="submit" class="btn btn-success">
                    <?= $editing ? 'Update Allocation' : 'Allocate Material' ?>
                </button>

                <?php if ($editing): ?>
                    <a href="allocations.php" class="btn btn-secondary">Cancel Edit</a>
                <?php endif; ?>

            </form>

        <?php endif; ?>

    </section>

    <section class="card">

        <h2>Allocation History</h2>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Project</th>
                        <th>Material</th>
                        <th>Work Area</th>
                        <th>Quantity</th>
                        <th>Used</th>
                        <th>Reserved Wastage</th>
                        <th>Remaining</th>
                        <th>Date</th>
                        <th>Issued By</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$history): ?>
                    <tr>
                        <td colspan="11" class="empty">No allocations recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($history as $row): ?>
                        <?php
                        $remaining = (float) $row['quantity']
                            - (float) $row['used_qty']
                            - (float) $row['reserved_wastage'];

                        $hasDependencies =
                            (int) $row['usage_count'] > 0 ||
                            (int) $row['wastage_count'] > 0;
                        ?>

                        <tr>
                            <td><?= e($row['allocation_id']) ?></td>
                            <td><?= e($row['project_name']) ?></td>
                            <td><?= e($row['material_name']) ?></td>
                            <td><?= e($row['area_name']) ?></td>

                            <td>
                                <?= number_format((float) $row['quantity'], 2) ?>
                                <?= e($row['unit']) ?>
                            </td>

                            <td>
                                <?= number_format((float) $row['used_qty'], 2) ?>
                            </td>

                            <td>
                                <?= number_format((float) $row['reserved_wastage'], 2) ?>
                            </td>

                            <td>
                                <?= number_format(max(0, $remaining), 2) ?>
                                <?= e($row['unit']) ?>
                            </td>

                            <td><?= e($row['allocation_date']) ?></td>
                            <td><?= e($row['issued_by_name']) ?></td>

                            <td class="allocation-actions">
                                <a
                                    href="allocations.php?edit=<?= e($row['allocation_id']) ?>"
                                    class="btn btn-primary btn-sm"
                                >Edit</a>

                                <?php if (!$hasDependencies): ?>
                                    <form
                                        method="POST"
                                        action="allocations.php"
                                        class="allocation-delete-form"
                                        onsubmit="return confirm('Are you sure you want to delete this allocation? This will return its quantity to store stock.');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="allocation_id" value="<?= e($row['allocation_id']) ?>">

                                        <button type="submit" class="btn btn-danger btn-sm">
                                            Delete
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="allocation-protected" title="This allocation has usage or wastage records.">
                                        Protected
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>

    <footer class="footer">
        Construction Site Material Allocation and Wastage Tracking System
    </footer>

</main>

<script>
const projectSelect = document.getElementById('project_id');
const areaSelect = document.getElementById('work_area_id');

function filterWorkAreas() {
    if (!projectSelect || !areaSelect) {
        return;
    }

    const projectId = projectSelect.value;

    for (const option of areaSelect.options) {
        if (!option.value) {
            option.hidden = false;
            continue;
        }

        const matches = option.dataset.project === projectId;
        option.hidden = !matches;

        if (!matches && option.selected) {
            areaSelect.value = '';
        }
    }
}

if (projectSelect && areaSelect) {
    projectSelect.addEventListener('change', filterWorkAreas);
    filterWorkAreas();
}
</script>

</body>
</html>
