<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['manager', 'storekeeper', 'engineer']);

require_once __DIR__ . '/includes/db.php';

/* =========================================
   1. HELPER FUNCTIONS
========================================= */

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function validWastageDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function formatQuantity($value): string
{
    return number_format((float)$value, 2);
}

/* =========================================
   2. USER AND CSRF SETUP
========================================= */

$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$message = '';
$messageType = '';

/* =========================================
   3. HANDLE FORM SUBMISSIONS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $message = 'Security validation failed. Please refresh the page and try again.';
        $messageType = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        /* =================================
           SUBMIT NEW WASTAGE
        ================================== */

        if ($action === 'submit') {

            $allocationId = filter_var(
                $_POST['allocation_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $quantity = filter_var(
                $_POST['quantity_wasted'] ?? null,
                FILTER_VALIDATE_FLOAT
            );

            $reason = trim($_POST['reason'] ?? '');
            $recordedDate = $_POST['recorded_date'] ?? '';

            if (!$allocationId || $allocationId <= 0) {

                $message = 'Please select a valid material allocation.';
                $messageType = 'error';

            } elseif (
                $quantity === false ||
                $quantity <= 0
            ) {

                $message = 'Please enter a valid wastage quantity greater than zero.';
                $messageType = 'error';

            } elseif ($reason === '') {

                $message = 'Please provide a reason for the wastage.';
                $messageType = 'error';

            } elseif (strlen($reason) > 1000) {

                $message = 'The wastage reason must not exceed 1000 characters.';
                $messageType = 'error';

            } elseif (!validWastageDate($recordedDate)) {

                $message = 'Please select a valid wastage date.';
                $messageType = 'error';

            } else {

                try {

                    $pdo->beginTransaction();

                    /*
                     * Lock the allocation row to prevent
                     * simultaneous usage/wastage over-allocation.
                     */

                    $allocationStmt = $pdo->prepare("
                        SELECT allocation_id, quantity
                        FROM allocations
                        WHERE allocation_id = ?
                        FOR UPDATE
                    ");

                    $allocationStmt->execute([$allocationId]);

                    $allocation = $allocationStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$allocation) {

                        throw new Exception('The selected allocation was not found.');

                    }

                    $allocatedQuantity = (float)$allocation['quantity'];

                    /* Get quantity already used */

                    $usageStmt = $pdo->prepare("
                        SELECT COALESCE(SUM(quantity_used), 0)
                        FROM material_usage
                        WHERE allocation_id = ?
                    ");

                    $usageStmt->execute([$allocationId]);

                    $usedQuantity = (float)$usageStmt->fetchColumn();

                    /* Get pending and approved wastage */

                    $wastageStmt = $pdo->prepare("
                        SELECT COALESCE(SUM(quantity_wasted), 0)
                        FROM wastage_records
                        WHERE allocation_id = ?
                          AND approval_status IN ('pending', 'approved')
                    ");

                    $wastageStmt->execute([$allocationId]);

                    $reservedWastage = (float)$wastageStmt->fetchColumn();

                    /* Calculate remaining allocation */

                    $remainingQuantity =
                        $allocatedQuantity
                        - $usedQuantity
                        - $reservedWastage;

                    if ($quantity > ($remainingQuantity + 0.000001)) {

                        throw new Exception(
                            'Insufficient remaining allocation. Available quantity: '
                            . formatQuantity(max(0, $remainingQuantity))
                        );

                    }

                    /* Insert pending wastage */

                    $insertStmt = $pdo->prepare("
                        INSERT INTO wastage_records (
                            allocation_id,
                            submitted_by,
                            quantity_wasted,
                            reason,
                            approval_status,
                            recorded_date
                        )
                        VALUES (?, ?, ?, ?, 'pending', ?)
                    ");

                    $insertStmt->execute([
                        $allocationId,
                        $userId,
                        $quantity,
                        $reason,
                        $recordedDate
                    ]);

                    $pdo->commit();

                    $message = 'Wastage record submitted successfully and is awaiting review.';
                    $messageType = 'success';

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $message = $e instanceof PDOException
                        ? 'A database error occurred while submitting wastage. Please check the database and try again.'
                        : $e->getMessage();

                    $messageType = 'error';
                }
            }

        }

        /* =================================
           APPROVE OR REJECT WASTAGE
        ================================== */

        elseif ($action === 'review') {

            $wastageId = filter_var(
                $_POST['wastage_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $decision = $_POST['decision'] ?? '';

            if (!$wastageId || $wastageId <= 0) {

                $message = 'Invalid wastage record.';
                $messageType = 'error';

            } elseif (!in_array($decision, ['approved', 'rejected'], true)) {

                $message = 'Invalid review decision.';
                $messageType = 'error';

            } else {

                try {

                    $pdo->beginTransaction();

                    /*
                     * Find the allocation associated with
                     * this wastage record.
                     */

                    $findStmt = $pdo->prepare("
                        SELECT allocation_id
                        FROM wastage_records
                        WHERE wastage_id = ?
                    ");

                    $findStmt->execute([$wastageId]);

                    $wastageReference = $findStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$wastageReference) {

                        throw new Exception('Wastage record not found.');

                    }

                    $allocationId = (int)$wastageReference['allocation_id'];

                    /*
                     * Lock allocation first.
                     * This follows the same lock order used
                     * by the usage and wastage submission pages.
                     */

                    $allocationStmt = $pdo->prepare("
                        SELECT allocation_id, quantity
                        FROM allocations
                        WHERE allocation_id = ?
                        FOR UPDATE
                    ");

                    $allocationStmt->execute([$allocationId]);

                    $allocation = $allocationStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$allocation) {

                        throw new Exception('Related allocation not found.');

                    }

                    /* Lock the wastage record */

                    $wastageLockStmt = $pdo->prepare("
                        SELECT
                            wastage_id,
                            allocation_id,
                            quantity_wasted,
                            approval_status
                        FROM wastage_records
                        WHERE wastage_id = ?
                        FOR UPDATE
                    ");

                    $wastageLockStmt->execute([$wastageId]);

                    $wastage = $wastageLockStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$wastage) {

                        throw new Exception('Wastage record not found.');

                    }

                    if ($wastage['approval_status'] !== 'pending') {

                        throw new Exception(
                            'This wastage record has already been reviewed.'
                        );

                    }

                    /* Validate available quantity before approval */

                    if ($decision === 'approved') {

                        $usageStmt = $pdo->prepare("
                            SELECT COALESCE(SUM(quantity_used), 0)
                            FROM material_usage
                            WHERE allocation_id = ?
                        ");

                        $usageStmt->execute([$allocationId]);

                        $usedQuantity = (float)$usageStmt->fetchColumn();

                        /*
                         * Exclude the current wastage record
                         * because it is being reviewed now.
                         */

                        $otherWastageStmt = $pdo->prepare("
                            SELECT COALESCE(SUM(quantity_wasted), 0)
                            FROM wastage_records
                            WHERE allocation_id = ?
                              AND wastage_id <> ?
                              AND approval_status IN ('pending', 'approved')
                        ");

                        $otherWastageStmt->execute([
                            $allocationId,
                            $wastageId
                        ]);

                        $otherWastage = (float)$otherWastageStmt->fetchColumn();

                        $allocatedQuantity = (float)$allocation['quantity'];
                        $currentWastage = (float)$wastage['quantity_wasted'];

                        $remainingBeforeApproval =
                            $allocatedQuantity
                            - $usedQuantity
                            - $otherWastage;

                        if (
                            $currentWastage >
                            ($remainingBeforeApproval + 0.000001)
                        ) {

                            throw new Exception(
                                'This wastage cannot be approved because the remaining allocation quantity is insufficient.'
                            );

                        }
                    }

                    /* Update review status */

                    $updateStmt = $pdo->prepare("
                        UPDATE wastage_records
                        SET
                            approval_status = ?,
                            approved_by = ?,
                            approved_at = NOW()
                        WHERE wastage_id = ?
                          AND approval_status = 'pending'
                    ");

                    $updateStmt->execute([
                        $decision,
                        $userId,
                        $wastageId
                    ]);

                    if ($updateStmt->rowCount() !== 1) {

                        throw new Exception(
                            'The wastage record could not be updated.'
                        );

                    }

                    $pdo->commit();

                    if ($decision === 'approved') {

                        $message = 'Wastage record approved successfully.';

                    } else {

                        $message = 'Wastage record rejected successfully.';

                    }

                    $messageType = 'success';

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $message = $e instanceof PDOException
                        ? 'A database error occurred while reviewing wastage.'
                        : $e->getMessage();

                    $messageType = 'error';
                }
            }

        } else {

            $message = 'Invalid form action.';
            $messageType = 'error';

        }
    }
}

/* =========================================
   4. LOAD AVAILABLE ALLOCATIONS
========================================= */

$allocationSql = "
    SELECT
        al.allocation_id,
        al.quantity,
        al.allocation_date,

        p.project_name,
        m.material_name,
        m.unit,
        wa.area_name,

        COALESCE(u.used_quantity, 0) AS used_quantity,
        COALESCE(w.wasted_quantity, 0) AS wasted_quantity,

        (
            al.quantity
            - COALESCE(u.used_quantity, 0)
            - COALESCE(w.wasted_quantity, 0)
        ) AS remaining_quantity

    FROM allocations al

    INNER JOIN projects p
        ON al.project_id = p.project_id

    INNER JOIN materials m
        ON al.material_id = m.material_id

    INNER JOIN work_areas wa
        ON al.work_area_id = wa.work_area_id

    LEFT JOIN (
        SELECT
            allocation_id,
            SUM(quantity_used) AS used_quantity
        FROM material_usage
        GROUP BY allocation_id
    ) u ON al.allocation_id = u.allocation_id

    LEFT JOIN (
        SELECT
            allocation_id,
            SUM(quantity_wasted) AS wasted_quantity
        FROM wastage_records
        WHERE approval_status IN ('pending', 'approved')
        GROUP BY allocation_id
    ) w ON al.allocation_id = w.allocation_id

    WHERE (
        al.quantity
        - COALESCE(u.used_quantity, 0)
        - COALESCE(w.wasted_quantity, 0)
    ) > 0

    ORDER BY al.allocation_date DESC, al.allocation_id DESC
";

$allocationStmt = $pdo->query($allocationSql);
$availableAllocations = $allocationStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   5. LOAD WASTAGE HISTORY
========================================= */

$historySql = "
    SELECT
        w.wastage_id,
        w.quantity_wasted,
        w.reason,
        w.approval_status,
        w.recorded_date,
        w.approved_at,

        p.project_name,
        m.material_name,
        m.unit,
        wa.area_name,

        submitter.full_name AS submitted_by_name,
        reviewer.full_name AS approved_by_name

    FROM wastage_records w

    INNER JOIN allocations al
        ON w.allocation_id = al.allocation_id

    INNER JOIN projects p
        ON al.project_id = p.project_id

    INNER JOIN materials m
        ON al.material_id = m.material_id

    INNER JOIN work_areas wa
        ON al.work_area_id = wa.work_area_id

    INNER JOIN users submitter
        ON w.submitted_by = submitter.user_id

    LEFT JOIN users reviewer
        ON w.approved_by = reviewer.user_id

    ORDER BY w.recorded_date DESC, w.wastage_id DESC
";

$historyStmt = $pdo->query($historySql);
$wastageHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   6. SUMMARY COUNTS
========================================= */

$summarySql = "
    SELECT
        COUNT(*) AS total_records,

        COALESCE(SUM(
            CASE
                WHEN approval_status = 'pending'
                THEN 1 ELSE 0
            END
        ), 0) AS pending_count,

        COALESCE(SUM(
            CASE
                WHEN approval_status = 'approved'
                THEN 1 ELSE 0
            END
        ), 0) AS approved_count,

        COALESCE(SUM(
            CASE
                WHEN approval_status = 'rejected'
                THEN 1 ELSE 0
            END
        ), 0) AS rejected_count,

        COALESCE(SUM(
            CASE
                WHEN approval_status = 'approved'
                THEN quantity_wasted ELSE 0
            END
        ), 0) AS approved_quantity

    FROM wastage_records
";

$summaryStmt = $pdo->query($summarySql);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Wastage Tracking - Construction Material Management System</title>

    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body>

<!-- =====================================
     BLUE HEADER AND NAVIGATION
====================================== -->

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


<div class="wastage-page">

    <!-- =================================
         PAGE HEADER
    ================================== -->

    <div class="page-header">

        <h1>Material Wastage Tracking</h1>

        <p>
            Record, monitor, approve, and reject material wastage
            at construction project work areas.
        </p>

    </div>


    <!-- =================================
         USER INFORMATION
    ================================== -->

    <div class="user-info">

        Logged in as:
        <strong><?= h($_SESSION['full_name'] ?? 'User') ?></strong>

        <span class="role-label">
            <?= h(ucfirst($role)) ?>
        </span>

    </div>


    <!-- =================================
         FEEDBACK MESSAGE
    ================================== -->

    <?php if ($message !== ''): ?>

        <div class="wastage-message <?= h($messageType) ?>">

            <?= h($message) ?>

        </div>

    <?php endif; ?>


    <!-- =================================
         SUMMARY CARDS
    ================================== -->

    <div class="wastage-summary">

        <div class="summary-card">

            <span class="summary-label">Total Records</span>

            <strong><?= (int)$summary['total_records'] ?></strong>

        </div>

        <div class="summary-card pending-card">

            <span class="summary-label">Pending Review</span>

            <strong><?= (int)$summary['pending_count'] ?></strong>

        </div>

        <div class="summary-card approved-card">

            <span class="summary-label">Approved Records</span>

            <strong><?= (int)$summary['approved_count'] ?></strong>

        </div>

        <div class="summary-card rejected-card">

            <span class="summary-label">Rejected Records</span>

            <strong><?= (int)$summary['rejected_count'] ?></strong>

        </div>

    </div>


    <!-- =================================
         SUBMIT WASTAGE FORM
    ================================== -->

    <div class="wastage-panel">

        <div class="panel-heading">

            <h2>Record New Material Wastage</h2>

            <p>
                Select an allocation and enter the quantity of material wasted.
            </p>

        </div>

        <?php if (count($availableAllocations) > 0): ?>

            <form method="POST" action="wastage.php" class="wastage-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="submit"
                >

                <div class="wastage-form-grid">

                    <div class="form-group full-width">

                        <label for="allocation_id">
                            Material Allocation
                        </label>

                        <select
                            name="allocation_id"
                            id="allocation_id"
                            required
                        >

                            <option value="">Select material allocation</option>

                            <?php foreach ($availableAllocations as $allocation): ?>

                                <option
                                    value="<?= (int)$allocation['allocation_id'] ?>"
                                >
                                    <?= h($allocation['project_name']) ?>
                                    -
                                    <?= h($allocation['material_name']) ?>
                                    -
                                    <?= h($allocation['area_name']) ?>
                                    |
                                    Remaining:
                                    <?= formatQuantity($allocation['remaining_quantity']) ?>
                                    <?= h($allocation['unit']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="form-group">

                        <label for="quantity_wasted">
                            Wasted Quantity
                        </label>

                        <input
                            type="number"
                            name="quantity_wasted"
                            id="quantity_wasted"
                            min="0.01"
                            step="0.01"
                            placeholder="Enter wasted quantity"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label for="recorded_date">
                            Wastage Date
                        </label>

                        <input
                            type="date"
                            name="recorded_date"
                            id="recorded_date"
                            value="<?= date('Y-m-d') ?>"
                            max="<?= date('Y-m-d') ?>"
                            required
                        >

                    </div>


                    <div class="form-group full-width">

                        <label for="reason">
                            Reason for Wastage
                        </label>

                        <textarea
                            name="reason"
                            id="reason"
                            rows="4"
                            maxlength="1000"
                            placeholder="Explain why the material was wasted..."
                            required
                        ></textarea>

                    </div>

                </div>


                <div class="wastage-note">

                    <strong>Important:</strong>

                    Submitted wastage is marked as pending until reviewed.
                    Pending and approved wastage quantities are reserved
                    against the allocation to prevent over-reporting.

                </div>


                <button type="submit" class="wastage-submit-btn">

                    Submit Wastage Record

                </button>

            </form>

        <?php else: ?>

            <div class="empty-state">

                <p>
                    No allocations with remaining quantities are currently
                    available for wastage recording.
                </p>

            </div>

        <?php endif; ?>

    </div>


    <!-- =================================
         WASTAGE HISTORY
    ================================== -->

    <div class="wastage-panel">

        <div class="panel-heading">

            <h2>Wastage Records and Review</h2>

            <p>
                Review submitted wastage records and their current approval status.
            </p>

        </div>


        <?php if (count($wastageHistory) > 0): ?>

            <div class="table-container">

                <table class="wastage-table">

                    <thead>

                        <tr>

                            <th>ID</th>

                            <th>Project</th>

                            <th>Material</th>

                            <th>Work Area</th>

                            <th>Quantity</th>

                            <th>Reason</th>

                            <th>Submitted By</th>

                            <th>Date</th>

                            <th>Status</th>

                            <th>Reviewed By</th>

                            <th>Action</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($wastageHistory as $record): ?>

                            <tr>

                                <td>
                                    <?= (int)$record['wastage_id'] ?>
                                </td>

                                <td>
                                    <?= h($record['project_name']) ?>
                                </td>

                                <td>
                                    <?= h($record['material_name']) ?>
                                </td>

                                <td>
                                    <?= h($record['area_name']) ?>
                                </td>

                                <td>
                                    <?= formatQuantity($record['quantity_wasted']) ?>
                                    <?= h($record['unit']) ?>
                                </td>

                                <td class="reason-cell">
                                    <?= h($record['reason']) ?>
                                </td>

                                <td>
                                    <?= h($record['submitted_by_name']) ?>
                                </td>

                                <td>
                                    <?= h($record['recorded_date']) ?>
                                </td>

                                <td>

                                    <span class="status-badge <?= h($record['approval_status']) ?>">

                                        <?= h(ucfirst($record['approval_status'])) ?>

                                    </span>

                                </td>

                                <td>

                                    <?= $record['approved_by_name']
                                        ? h($record['approved_by_name'])
                                        : 'Not reviewed' ?>

                                </td>

                                <td>

                                    <?php if ($record['approval_status'] === 'pending'): ?>

                                        <form
                                            method="POST"
                                            action="wastage.php"
                                            class="review-form"
                                            onsubmit="return confirm('Are you sure you want to review this wastage record?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= h($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="review"
                                            >

                                            <input
                                                type="hidden"
                                                name="wastage_id"
                                                value="<?= (int)$record['wastage_id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="decision"
                                                value="approved"
                                                class="approve-btn"
                                            >
                                                Approve
                                            </button>

                                            <button
                                                type="submit"
                                                name="decision"
                                                value="rejected"
                                                class="reject-btn"
                                            >
                                                Reject
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="review-complete">
                                            Reviewed
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="empty-state">

                <p>No wastage records have been submitted yet.</p>

            </div>

        <?php endif; ?>

    </div>


    <!-- =================================
         FOOTER
    ================================== -->

    <div class="wastage-footer">

        <p>
            Construction Site Material Allocation and Wastage Tracking System
        </p>

    </div>

</div>

</body>
</html>
