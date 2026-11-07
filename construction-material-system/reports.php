<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['manager', 'engineer']);

require_once __DIR__ . '/includes/db.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function validDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function numberFormat($value): string
{
    return number_format((float)$value, 2);
}

/* =========================================
   1. LOAD PROJECTS
========================================= */

$projectStmt = $pdo->query("
    SELECT project_id, project_name
    FROM projects
    ORDER BY project_name ASC
");

$projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   2. FILTER VALUES
========================================= */

$today = date('Y-m-d');
$defaultStart = date('Y-m-01');

$startDate = $_GET['start_date'] ?? $defaultStart;
$endDate = $_GET['end_date'] ?? $today;

$projectId = filter_var(
    $_GET['project_id'] ?? '0',
    FILTER_VALIDATE_INT
);

$error = '';

if ($projectId === false || $projectId < 0) {
    $projectId = 0;
    $error = 'Please select a valid project.';
}

if (!validDate($startDate) || !validDate($endDate)) {
    $error = 'Please select valid start and end dates.';
    $startDate = $defaultStart;
    $endDate = $today;
} elseif ($startDate > $endDate) {
    $error = 'The start date cannot be later than the end date.';
    $startDate = $defaultStart;
    $endDate = $today;
}

/* Confirm selected project exists */

if ($projectId > 0) {
    $checkProject = $pdo->prepare("
        SELECT project_id
        FROM projects
        WHERE project_id = ?
    ");

    $checkProject->execute([$projectId]);

    if (!$checkProject->fetchColumn()) {
        $projectId = 0;
        $error = 'The selected project does not exist.';
    }
}

/* =========================================
   3. SUMMARY REPORT
========================================= */

$summarySql = "
    SELECT

        (
            SELECT COALESCE(SUM(quantity * unit_cost), 0)
            FROM material_receipts
            WHERE received_date BETWEEN ? AND ?
              AND (? = 0 OR project_id = ?)
        ) AS total_cost,

        (
            SELECT COALESCE(SUM(quantity), 0)
            FROM material_receipts
            WHERE received_date BETWEEN ? AND ?
              AND (? = 0 OR project_id = ?)
        ) AS total_received,

        (
            SELECT COALESCE(SUM(quantity), 0)
            FROM allocations
            WHERE allocation_date BETWEEN ? AND ?
              AND (? = 0 OR project_id = ?)
        ) AS total_allocated,

        (
            SELECT COALESCE(SUM(mu.quantity_used), 0)
            FROM material_usage mu
            INNER JOIN allocations al
                ON mu.allocation_id = al.allocation_id
            WHERE mu.usage_date BETWEEN ? AND ?
              AND (? = 0 OR al.project_id = ?)
        ) AS total_used,

        (
            SELECT COALESCE(SUM(w.quantity_wasted), 0)
            FROM wastage_records w
            INNER JOIN allocations al
                ON w.allocation_id = al.allocation_id
            WHERE w.recorded_date BETWEEN ? AND ?
              AND w.approval_status = 'pending'
              AND (? = 0 OR al.project_id = ?)
        ) AS pending_wastage,

        (
            SELECT COALESCE(SUM(w.quantity_wasted), 0)
            FROM wastage_records w
            INNER JOIN allocations al
                ON w.allocation_id = al.allocation_id
            WHERE w.recorded_date BETWEEN ? AND ?
              AND w.approval_status = 'approved'
              AND (? = 0 OR al.project_id = ?)
        ) AS approved_wastage,

        (
            SELECT COALESCE(SUM(w.quantity_wasted), 0)
            FROM wastage_records w
            INNER JOIN allocations al
                ON w.allocation_id = al.allocation_id
            WHERE w.recorded_date BETWEEN ? AND ?
              AND w.approval_status = 'rejected'
              AND (? = 0 OR al.project_id = ?)
        ) AS rejected_wastage
";

$params = [];

for ($i = 0; $i < 7; $i++) {
    $params[] = $startDate;
    $params[] = $endDate;
    $params[] = $projectId;
    $params[] = $projectId;
}

$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($params);

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

/* =========================================
   4. MATERIAL SUMMARY
========================================= */

$materialSql = "
    SELECT
        m.material_id,
        m.material_name,
        m.category,
        m.unit,
        m.minimum_stock,

        COALESCE(r.received_qty, 0) AS received_qty,
        COALESCE(r.material_cost, 0) AS material_cost,

        COALESCE(a.allocated_qty, 0) AS allocated_qty,
        COALESCE(u.used_qty, 0) AS used_qty,

        COALESCE(w.pending_qty, 0) AS pending_qty,
        COALESCE(w.approved_qty, 0) AS approved_qty,
        COALESCE(w.rejected_qty, 0) AS rejected_qty,

        (
            COALESCE(ar.all_received, 0)
            - COALESCE(aa.all_allocated, 0)
        ) AS store_balance

    FROM materials m

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS received_qty,
            SUM(quantity * unit_cost) AS material_cost
        FROM material_receipts
        WHERE received_date BETWEEN ? AND ?
          AND (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) r ON m.material_id = r.material_id

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS allocated_qty
        FROM allocations
        WHERE allocation_date BETWEEN ? AND ?
          AND (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) a ON m.material_id = a.material_id

    LEFT JOIN (
        SELECT
            al.material_id,
            SUM(mu.quantity_used) AS used_qty
        FROM material_usage mu
        INNER JOIN allocations al
            ON mu.allocation_id = al.allocation_id
        WHERE mu.usage_date BETWEEN ? AND ?
          AND (? = 0 OR al.project_id = ?)
        GROUP BY al.material_id
    ) u ON m.material_id = u.material_id

    LEFT JOIN (
        SELECT
            al.material_id,

            SUM(
                CASE WHEN w.approval_status = 'pending'
                THEN w.quantity_wasted ELSE 0 END
            ) AS pending_qty,

            SUM(
                CASE WHEN w.approval_status = 'approved'
                THEN w.quantity_wasted ELSE 0 END
            ) AS approved_qty,

            SUM(
                CASE WHEN w.approval_status = 'rejected'
                THEN w.quantity_wasted ELSE 0 END
            ) AS rejected_qty

        FROM wastage_records w
        INNER JOIN allocations al
            ON w.allocation_id = al.allocation_id
        WHERE w.recorded_date BETWEEN ? AND ?
          AND (? = 0 OR al.project_id = ?)
        GROUP BY al.material_id
    ) w ON m.material_id = w.material_id

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS all_received
        FROM material_receipts
        WHERE (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) ar ON m.material_id = ar.material_id

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS all_allocated
        FROM allocations
        WHERE (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) aa ON m.material_id = aa.material_id

    ORDER BY m.material_name ASC
";

$materialParams = [
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId,
    $projectId, $projectId,
    $projectId, $projectId
];

$materialStmt = $pdo->prepare($materialSql);
$materialStmt->execute($materialParams);

$materials = $materialStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   5. LOW STOCK ALERTS
========================================= */

$lowStockSql = "
    SELECT
        m.material_name,
        m.unit,
        m.minimum_stock,

        (
            COALESCE(r.total_received, 0)
            - COALESCE(a.total_allocated, 0)
        ) AS current_stock

    FROM materials m

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS total_received
        FROM material_receipts
        WHERE (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) r ON m.material_id = r.material_id

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS total_allocated
        FROM allocations
        WHERE (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) a ON m.material_id = a.material_id

    WHERE (
        COALESCE(r.total_received, 0)
        - COALESCE(a.total_allocated, 0)
    ) < m.minimum_stock

    ORDER BY current_stock ASC
";

$lowStockStmt = $pdo->prepare($lowStockSql);
$lowStockStmt->execute([
    $projectId, $projectId,
    $projectId, $projectId
]);

$lowStockMaterials = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   6. WASTAGE RATE
========================================= */

$totalUsed = (float)$summary['total_used'];
$approvedWastage = (float)$summary['approved_wastage'];

$wastageRate = 0;

if (($totalUsed + $approvedWastage) > 0) {
    $wastageRate = (
        $approvedWastage / ($totalUsed + $approvedWastage)
    ) * 100;
}

$role = $_SESSION['role'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Material Reports - Construction Material Management System</title>
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

        <?php if ($role === 'manager'): ?>
            <a href="projects.php">Projects</a>
            <a href="materials.php">Materials</a>
            <a href="suppliers.php">Suppliers</a>
            <a href="deliveries.php">Deliveries</a>
            <a href="allocations.php">Allocations</a>
        <?php endif; ?>

        <a href="usage.php">Usage</a>
        <a href="wastage.php">Wastage</a>
        <a href="reports.php">Reports</a>
        <a href="logout.php">Logout</a>
    </div>

</nav>

<div class="reports-page">

    <div class="page-header">
        <h1>Material Reports</h1>
        <p>
            Welcome, <?= h($_SESSION['full_name'] ?? 'User') ?>.
            View material receipts, allocations, usage, wastage,
            and current stock information.
        </p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <h2>Filter Reports</h2>

        <p class="section-description">
            Select a project and reporting period.
        </p>

        <form method="GET" action="reports.php"
              class="report-filter-form">

            <div class="form-group">
                <label for="project_id">Project</label>

                <select id="project_id" name="project_id">
                    <option value="0"
                        <?= (int)$projectId === 0 ? 'selected' : '' ?>>
                        All Projects
                    </option>

                    <?php foreach ($projects as $project): ?>
                        <option
                            value="<?= (int)$project['project_id'] ?>"
                            <?= (int)$projectId === (int)$project['project_id'] ? 'selected' : '' ?>
                        >
                            <?= h($project['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="start_date">Start Date</label>
                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?= h($startDate) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="end_date">End Date</label>
                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?= h($endDate) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <button type="submit" class="btn">
                    Generate Report
                </button>
            </div>

        </form>

        <div class="report-actions">

            <a
                href="export_reports.php?project_id=<?= (int)$projectId ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>"
                class="btn btn-success"
            >
                Export CSV
            </a>

            <a
                href="print_reports.php?project_id=<?= (int)$projectId ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>"
                class="btn btn-secondary"
                target="_blank"
            >
                Print / Save PDF
            </a>

        </div>

    </div>

    <div class="report-summary-grid">

        <div class="summary-card">
            <h3>Total Material Cost</h3>
            <div class="summary-value">
                <?= numberFormat($summary['total_cost']) ?>
            </div>
            <p>Currency units</p>
        </div>

        <div class="summary-card">
            <h3>Total Received</h3>
            <div class="summary-value">
                <?= numberFormat($summary['total_received']) ?>
            </div>
            <p>Combined material units</p>
        </div>

        <div class="summary-card">
            <h3>Total Allocated</h3>
            <div class="summary-value">
                <?= numberFormat($summary['total_allocated']) ?>
            </div>
            <p>Combined material units</p>
        </div>

        <div class="summary-card">
            <h3>Total Used</h3>
            <div class="summary-value">
                <?= numberFormat($summary['total_used']) ?>
            </div>
            <p>Combined material units</p>
        </div>

        <div class="summary-card pending">
            <h3>Pending Wastage</h3>
            <div class="summary-value">
                <?= numberFormat($summary['pending_wastage']) ?>
            </div>
            <p>Awaiting review</p>
        </div>

        <div class="summary-card approved">
            <h3>Approved Wastage</h3>
            <div class="summary-value">
                <?= numberFormat($summary['approved_wastage']) ?>
            </div>
            <p>Approved quantity</p>
        </div>

        <div class="summary-card rejected">
            <h3>Rejected Wastage</h3>
            <div class="summary-value">
                <?= numberFormat($summary['rejected_wastage']) ?>
            </div>
            <p>Rejected quantity</p>
        </div>

        <div class="summary-card">
            <h3>Wastage Rate</h3>
            <div class="summary-value">
                <?= numberFormat($wastageRate) ?>%
            </div>
            <p>Approved wastage compared with used plus approved wastage</p>
        </div>

    </div>

    <div class="panel">

        <h2>Material Summary</h2>

        <p class="section-description">
            Material quantities and costs for the selected reporting period.
        </p>

        <div class="table-container">

            <table class="report-table">

                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Unit</th>
                        <th>Received</th>
                        <th>Material Cost</th>
                        <th>Allocated</th>
                        <th>Used</th>
                        <th>Pending Wastage</th>
                        <th>Approved Wastage</th>
                        <th>Rejected Wastage</th>
                        <th>Current Store Stock</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($materials) > 0): ?>

                        <?php foreach ($materials as $material): ?>
                            <tr>
                                <td><?= h($material['material_name']) ?></td>
                                <td><?= h($material['unit']) ?></td>
                                <td><?= numberFormat($material['received_qty']) ?></td>
                                <td><?= numberFormat($material['material_cost']) ?></td>
                                <td><?= numberFormat($material['allocated_qty']) ?></td>
                                <td><?= numberFormat($material['used_qty']) ?></td>
                                <td><?= numberFormat($material['pending_qty']) ?></td>
                                <td><?= numberFormat($material['approved_qty']) ?></td>
                                <td><?= numberFormat($material['rejected_qty']) ?></td>
                                <td>
                                    <?= numberFormat($material['store_balance']) ?>
                                    <?= h($material['unit']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center">
                                No material records found.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <p class="report-note">
            Note: Current store stock is calculated from all recorded
            receipts minus all recorded allocations for the selected
            project. It is not limited to the selected reporting period.
        </p>

    </div>

    <div class="panel">

        <h2>Low Stock Alerts</h2>

        <p class="section-description">
            Materials whose current store stock is below their minimum
            stock level.
        </p>

        <?php if (count($lowStockMaterials) > 0): ?>

            <div class="alert alert-warning">
                Warning: Some materials are below their minimum stock level.
                Please consider arranging replenishment.
            </div>

            <div class="table-container">

                <table class="low-stock-table">

                    <thead>
                        <tr>
                            <th>Material Name</th>
                            <th>Current Stock</th>
                            <th>Minimum Stock</th>
                            <th>Unit</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($lowStockMaterials as $item): ?>
                            <tr>
                                <td><?= h($item['material_name']) ?></td>
                                <td><?= numberFormat($item['current_stock']) ?></td>
                                <td><?= numberFormat($item['minimum_stock']) ?></td>
                                <td><?= h($item['unit']) ?></td>
                                <td>
                                    <span class="status status-low">
                                        Low Stock
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="alert alert-success">
                All materials are currently at or above their minimum stock levels.
            </div>

        <?php endif; ?>

    </div>

    <div class="footer">
        <p>
            Construction Site Material Allocation and Wastage Tracking System
        </p>

        <p>
            Report period:
            <?= h($startDate) ?> to <?= h($endDate) ?>
        </p>
    </div>

</div>

</body>
</html>
