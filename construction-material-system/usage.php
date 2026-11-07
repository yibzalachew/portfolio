
<?php

require_once __DIR__ . '/includes/auth.php';
requireRole(['manager', 'engineer']);

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

$old = [
    'allocation_id' => '',
    'quantity_used' => '',
    'usage_date' => date('Y-m-d')
];

$editingId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($old as $key => $value) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $old[$key] = trim($_POST[$key]);
        }
    }

    try {

        if (
            !isset($_POST['csrf_token']) ||
            !is_string($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new DomainException(
                'Invalid form token. Refresh the page and try again.'
            );
        }

        $action = $_POST['action'] ?? '';

        // DELETE USAGE
        if ($action === 'delete') {

            $usageId = filter_var(
                $_POST['usage_id'] ?? '',
                FILTER_VALIDATE_INT
            );

            if (!$usageId) {
                throw new DomainException('Invalid usage record.');
            }

            $pdo->beginTransaction();

            // Find the allocation connected to this usage record
            $findStmt = $pdo->prepare(
                'SELECT allocation_id
                 FROM material_usage
                 WHERE usage_id = ?'
            );

            $findStmt->execute([$usageId]);
            $usageAllocationId = $findStmt->fetchColumn();

            if (!$usageAllocationId) {
                throw new DomainException(
                    'The usage record was not found.'
                );
            }

            // Lock allocation first to protect balance calculations
            $lockAllocation = $pdo->prepare(
                'SELECT allocation_id
                 FROM allocations
                 WHERE allocation_id = ?
                 FOR UPDATE'
            );

            $lockAllocation->execute([$usageAllocationId]);

            if (!$lockAllocation->fetch()) {
                throw new DomainException(
                    'The connected allocation was not found.'
                );
            }

            $deleteStmt = $pdo->prepare(
                'DELETE FROM material_usage
                 WHERE usage_id = ?'
            );

            $deleteStmt->execute([$usageId]);

            if ($deleteStmt->rowCount() !== 1) {
                throw new DomainException(
                    'The usage record could not be deleted.'
                );
            }

            $pdo->commit();

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $message = 'Usage record deleted successfully.';
            $messageType = 'success';

            $old = [
                'allocation_id' => '',
                'quantity_used' => '',
                'usage_date' => date('Y-m-d')
            ];

        // SAVE NEW OR EDITED USAGE
        } elseif ($action === 'save') {

            $usageId = filter_var(
                $_POST['usage_id'] ?? '',
                FILTER_VALIDATE_INT
            );

            if ($usageId === false || $usageId < 0) {
                throw new DomainException('Invalid usage record.');
            }

            $allocationId = filter_var(
                $old['allocation_id'],
                FILTER_VALIDATE_INT
            );

            $quantityInput = $old['quantity_used'];

            $validQuantity = is_numeric($quantityInput)
                && is_finite((float) $quantityInput)
                && (float) $quantityInput > 0
                && (float) $quantityInput <= 9999999999.99;

            $quantityUsed = $validQuantity
                ? (float) $quantityInput
                : 0;

            if (!$allocationId || !$validQuantity) {
                throw new DomainException(
                    'Select an allocation and enter a valid quantity greater than zero.'
                );
            }

            $usageDate = $old['usage_date'];

            $dateObject = DateTime::createFromFormat(
                '!Y-m-d',
                $usageDate
            );

            if (
                !$dateObject ||
                $dateObject->format('Y-m-d') !== $usageDate ||
                $usageDate > date('Y-m-d')
            ) {
                throw new DomainException(
                    'Enter a valid usage date that is not in the future.'
                );
            }

            $pdo->beginTransaction();

            // Lock the allocation row
            $allocationStmt = $pdo->prepare(
                'SELECT
                    a.allocation_id,
                    a.quantity,
                    m.unit
                 FROM allocations a
                 JOIN materials m
                    ON m.material_id = a.material_id
                 WHERE a.allocation_id = ?
                 FOR UPDATE'
            );

            $allocationStmt->execute([$allocationId]);
            $allocation = $allocationStmt->fetch();

            if (!$allocation) {
                throw new DomainException(
                    'The selected allocation was not found.'
                );
            }

            // If editing, confirm the record belongs to this allocation
            if ($usageId > 0) {

                $editStmt = $pdo->prepare(
                    'SELECT usage_id, allocation_id
                     FROM material_usage
                     WHERE usage_id = ?
                     FOR UPDATE'
                );

                $editStmt->execute([$usageId]);
                $existingUsage = $editStmt->fetch();

                if (!$existingUsage) {
                    throw new DomainException(
                        'The usage record you are editing was not found.'
                    );
                }

                if (
                    (int) $existingUsage['allocation_id'] !==
                    (int) $allocationId
                ) {
                    throw new DomainException(
                        'You cannot change the allocation of an existing usage record.'
                    );
                }
            }

            // Calculate usage from OTHER records only
            if ($usageId > 0) {

                $usedStmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(quantity_used), 0)
                     FROM material_usage
                     WHERE allocation_id = ?
                     AND usage_id <> ?'
                );

                $usedStmt->execute([$allocationId, $usageId]);

            } else {

                $usedStmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(quantity_used), 0)
                     FROM material_usage
                     WHERE allocation_id = ?'
                );

                $usedStmt->execute([$allocationId]);
            }

            $alreadyUsed = (float) $usedStmt->fetchColumn();

            // Pending and approved wastage reserve balance
            $wasteStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(quantity_wasted), 0)
                 FROM wastage_records
                 WHERE allocation_id = ?
                 AND approval_status IN ('pending', 'approved')"
            );

            $wasteStmt->execute([$allocationId]);

            $reservedWastage = (float) $wasteStmt->fetchColumn();

            $allocatedQuantity = (float) $allocation['quantity'];

            $remaining = $allocatedQuantity
                - $alreadyUsed
                - $reservedWastage;

            if ($remaining < -0.000001) {
                throw new DomainException(
                    'Existing usage or reserved wastage exceeds the allocation quantity. Please review the records.'
                );
            }

            $remaining = max(0, $remaining);

            if ($quantityUsed > $remaining + 0.000001) {
                throw new DomainException(
                    'Usage exceeds the remaining allocated balance. Available for this record: ' .
                    number_format($remaining, 2) . ' ' .
                    $allocation['unit'] . '.'
                );
            }

            if ($usageId > 0) {

                // UPDATE EXISTING RECORD
                $updateStmt = $pdo->prepare(
                    'UPDATE material_usage
                     SET quantity_used = ?,
                         usage_date = ?
                     WHERE usage_id = ?'
                );

                $updateStmt->execute([
                    $quantityUsed,
                    $usageDate,
                    $usageId
                ]);

                $message = 'Usage record updated successfully.';

            } else {

                // INSERT NEW RECORD
                $insertStmt = $pdo->prepare(
                    'INSERT INTO material_usage
                    (
                        allocation_id,
                        recorded_by,
                        quantity_used,
                        usage_date
                    )
                    VALUES (?, ?, ?, ?)'
                );

                $insertStmt->execute([
                    $allocationId,
                    $_SESSION['user_id'],
                    $quantityUsed,
                    $usageDate
                ]);

                $message = 'Material usage recorded successfully.';
            }

            $pdo->commit();

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $messageType = 'success';

            $old = [
                'allocation_id' => '',
                'quantity_used' => '',
                'usage_date' => date('Y-m-d')
            ];

        } else {
            throw new DomainException('Invalid form action.');
        }

    } catch (Throwable $ex) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($ex instanceof DomainException) {
            $message = $ex->getMessage();
        } else {
            error_log($ex->getMessage());
            $message = 'An unexpected error occurred. Please try again.';
        }

        $messageType = 'error';
    }
}

// Load record being edited
$editRecord = null;

if ($editingId > 0) {

    $editStmt = $pdo->prepare(
        'SELECT
            usage_id,
            allocation_id,
            quantity_used,
            usage_date
         FROM material_usage
         WHERE usage_id = ?'
    );

    $editStmt->execute([$editingId]);
    $editRecord = $editStmt->fetch();

    if (!$editRecord) {
        $editingId = 0;
        $message = 'The usage record you selected no longer exists.';
        $messageType = 'error';
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $old = [
            'allocation_id' => $editRecord['allocation_id'],
            'quantity_used' => $editRecord['quantity_used'],
            'usage_date' => $editRecord['usage_date']
        ];
    }
}

// Load allocations and calculate remaining balances
$allocations = $pdo->query(
    "SELECT
        a.allocation_id,
        a.quantity,
        p.project_name,
        m.material_name,
        m.unit,
        wa.area_name,
        COALESCE(u.total_used, 0) AS total_used,
        COALESCE(w.total_wasted, 0) AS total_wasted,
        a.quantity
            - COALESCE(u.total_used, 0)
            - COALESCE(w.total_wasted, 0) AS remaining
     FROM allocations a
     JOIN projects p
        ON p.project_id = a.project_id
     JOIN materials m
        ON m.material_id = a.material_id
     JOIN work_areas wa
        ON wa.work_area_id = a.work_area_id
     LEFT JOIN (
         SELECT allocation_id, SUM(quantity_used) AS total_used
         FROM material_usage
         GROUP BY allocation_id
     ) u
        ON u.allocation_id = a.allocation_id
     LEFT JOIN (
         SELECT allocation_id, SUM(quantity_wasted) AS total_wasted
         FROM wastage_records
         WHERE approval_status IN ('pending', 'approved')
         GROUP BY allocation_id
     ) w
        ON w.allocation_id = a.allocation_id
     ORDER BY a.allocation_id DESC"
)->fetchAll();

// Load usage history
$history = $pdo->query(
    'SELECT
        u.usage_id,
        u.quantity_used,
        u.usage_date,
        a.allocation_id,
        p.project_name,
        m.material_name,
        m.unit,
        wa.area_name,
        usr.full_name AS recorded_by_name
     FROM material_usage u
     JOIN allocations a
        ON a.allocation_id = u.allocation_id
     JOIN projects p
        ON p.project_id = a.project_id
     JOIN materials m
        ON m.material_id = a.material_id
     JOIN work_areas wa
        ON wa.work_area_id = a.work_area_id
     JOIN users usr
        ON usr.user_id = u.recorded_by
     ORDER BY u.usage_id DESC'
)->fetchAll();

$hasAvailableAllocation = false;

foreach ($allocations as $allocation) {
    if ((float) $allocation['remaining'] > 0.000001) {
        $hasAvailableAllocation = true;
        break;
    }
}

$isEditing = $editingId > 0 && $editRecord;

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Material Usage</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

<nav class="navbar">
    <div class="container">

        <div class="brand">
            <h2>Construction Material System</h2>
            <p>Material Usage</p>
        </div>

        <div class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="allocations.php">Allocations</a>
            <a href="stock.php">Stock</a>
            <a href="deliveries.php">Deliveries</a>
            <a href="reports.php">Reports</a>
            <a href="logout.php" class="btn btn-danger">Logout</a>
        </div>

    </div>
</nav>

<main class="container">

    <div class="page-header">
        <h1>Material Usage</h1>
        <p>
            Record the quantity of allocated material consumed
            at the construction site.
        </p>
    </div>

    <section class="card">

        <h2><?= $isEditing ? 'Edit Material Usage' : 'Record Material Usage' ?></h2>

        <?php if ($message !== ''): ?>
            <div class="alert <?= $messageType === 'success' ? 'alert-success' : 'alert-error' ?>">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <?php if (!$hasAvailableAllocation && !$isEditing): ?>

            <div class="alert alert-error">
                There are currently no allocations with an available
                balance for recording material usage.
            </div>

        <?php else: ?>

            <form
                method="POST"
                action="<?= $isEditing ? 'usage.php?edit=' . (int) $editingId : 'usage.php' ?>"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['csrf_token']) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="save"
                >

                <input
                    type="hidden"
                    name="usage_id"
                    value="<?= $isEditing ? (int) $editingId : 0 ?>"
                >

                <div class="form-grid">

                    <div class="form-group">

                        <label for="allocation_id">
                            Allocated Material *
                        </label>

                        <?php if ($isEditing): ?>

                            <?php
                            $selectedAllocation = null;

                            foreach ($allocations as $item) {
                                if ((int) $item['allocation_id'] === (int) $old['allocation_id']) {
                                    $selectedAllocation = $item;
                                    break;
                                }
                            }
                            ?>

                            <input
                                type="hidden"
                                name="allocation_id"
                                value="<?= e($old['allocation_id']) ?>"
                            >

                            <input
                                type="text"
                                value="<?php
                                if ($selectedAllocation) {
                                    echo e(
                                        '#' . $selectedAllocation['allocation_id'] .
                                        ' â€” ' . $selectedAllocation['material_name'] .
                                        ' â€” ' . $selectedAllocation['project_name'] .
                                        ' â€” ' . $selectedAllocation['area_name']
                                    );
                                } else {
                                    echo 'Allocation #' . e($old['allocation_id']);
                                }
                                ?>"
                                disabled
                            >

                        <?php else: ?>

                            <select
                                name="allocation_id"
                                id="allocation_id"
                                required
                            >

                                <option value="">
                                    Select an allocation
                                </option>

                                <?php foreach ($allocations as $allocation): ?>

                                    <?php if ((float) $allocation['remaining'] > 0.000001): ?>

                                        <option
                                            value="<?= e($allocation['allocation_id']) ?>"
                                            <?= $old['allocation_id'] == $allocation['allocation_id'] ? 'selected' : '' ?>
                                        >
                                            #<?= e($allocation['allocation_id']) ?>
                                            â€” <?= e($allocation['material_name']) ?>
                                            â€” <?= e($allocation['project_name']) ?>
                                            â€” <?= e($allocation['area_name']) ?>
                                            â€” Remaining:
                                            <?= number_format((float) $allocation['remaining'], 2) ?>
                                            <?= e($allocation['unit']) ?>
                                        </option>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                            </select>

                        <?php endif; ?>

                    </div>

                    <div class="form-group">

                        <label for="quantity_used">
                            Quantity Used *
                        </label>

                        <input
                            type="number"
                            id="quantity_used"
                            name="quantity_used"
                            min="0.01"
                            max="9999999999.99"
                            step="0.01"
                            value="<?= e($old['quantity_used']) ?>"
                            placeholder="Enter quantity used"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="usage_date">
                            Usage Date *
                        </label>

                        <input
                            type="date"
                            id="usage_date"
                            name="usage_date"
                            max="<?= date('Y-m-d') ?>"
                            value="<?= e($old['usage_date']) ?>"
                            required
                        >

                    </div>

                </div>

                <p class="section-description">
                    The system checks the remaining allocated balance
                    before saving. Previously recorded usage and pending
                    or approved wastage are deducted from the available
                    quantity. Rejected wastage is not deducted.
                </p>

                <button type="submit" class="btn btn-success">
                    <?= $isEditing ? 'Update Usage Record' : 'Save Usage Record' ?>
                </button>

                <?php if ($isEditing): ?>
                    <a href="usage.php" class="btn btn-secondary">
                        Cancel Editing
                    </a>
                <?php endif; ?>

            </form>

        <?php endif; ?>

    </section>

    <section class="card">

        <h2>Material Usage History</h2>

        <div class="table-container">

            <table>

                <thead>
                    <tr>
                        <th>Usage ID</th>
                        <th>Allocation ID</th>
                        <th>Project</th>
                        <th>Material</th>
                        <th>Work Area</th>
                        <th>Quantity Used</th>
                        <th>Date</th>
                        <th>Recorded By</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (!$history): ?>

                        <tr>
                            <td colspan="9" class="empty">
                                No usage records yet.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($history as $row): ?>

                            <tr>

                                <td><?= e($row['usage_id']) ?></td>
                                <td><?= e($row['allocation_id']) ?></td>
                                <td><?= e($row['project_name']) ?></td>
                                <td><?= e($row['material_name']) ?></td>
                                <td><?= e($row['area_name']) ?></td>

                                <td>
                                    <?= number_format((float) $row['quantity_used'], 2) ?>
                                    <?= e($row['unit']) ?>
                                </td>

                                <td><?= e($row['usage_date']) ?></td>
                                <td><?= e($row['recorded_by_name']) ?></td>

                                <td class="usage-actions">

                                    <a
                                        href="usage.php?edit=<?= (int) $row['usage_id'] ?>"
                                        class="btn btn-primary btn-small"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="usage.php"
                                        class="usage-delete-form"
                                        onsubmit="return confirm('Are you sure you want to delete this usage record? This will restore its quantity to the allocation balance.');"
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
                                            name="usage_id"
                                            value="<?= (int) $row['usage_id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-danger btn-small"
                                        >
                                            Delete
                                        </button>

                                    </form>

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

</body>
</html>
