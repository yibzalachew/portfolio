<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['manager', 'engineer']);

require_once __DIR__ . '/includes/db.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Get selected project.
$projectId = filter_var(
    $_GET['project_id'] ?? 0,
    FILTER_VALIDATE_INT
);

if ($projectId === false || $projectId < 0) {
    http_response_code(400);
    exit('Invalid project selection. Return to Reports and choose a valid project.');
}

// Load projects.
$projectStmt = $pdo->query("
    SELECT project_id, project_name
    FROM projects
    ORDER BY project_name
");

$projects = $projectStmt->fetchAll();

// Determine selected project name.
if ($projectId === 0) {
    $projectName = 'All Projects';
} else {
    $projectStmt = $pdo->prepare("
        SELECT project_name
        FROM projects
        WHERE project_id = ?
    ");

    $projectStmt->execute([$projectId]);
    $selectedProject = $projectStmt->fetch();

    if (!$selectedProject) {
        http_response_code(400);
        exit('Selected project was not found. Return to Reports and choose a valid project.');
    }

    $projectName = $selectedProject['project_name'];
}

// Get selected dates.
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Validate dates.
$startObject = is_string($startDate)
    ? DateTime::createFromFormat('!Y-m-d', $startDate)
    : false;

$endObject = is_string($endDate)
    ? DateTime::createFromFormat('!Y-m-d', $endDate)
    : false;

if (
    !$startObject ||
    $startObject->format('Y-m-d') !== $startDate ||
    !$endObject ||
    $endObject->format('Y-m-d') !== $endDate ||
    $startDate > $endDate
) {
    http_response_code(400);
    exit('Invalid date range. Return to Reports and choose valid dates.');
}

// Summary totals for the selected project and period.
$sql = "
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
                ON al.allocation_id = mu.allocation_id
            WHERE mu.usage_date BETWEEN ? AND ?
              AND (? = 0 OR al.project_id = ?)
        ) AS total_used,

        (
            SELECT COALESCE(SUM(wr.quantity_wasted), 0)
            FROM wastage_records wr
            INNER JOIN allocations al
                ON al.allocation_id = wr.allocation_id
            WHERE wr.approval_status = 'approved'
              AND wr.recorded_date BETWEEN ? AND ?
              AND (? = 0 OR al.project_id = ?)
        ) AS total_wasted
";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId
]);

$summary = $stmt->fetch();

$totalCost = (float) $summary['total_cost'];
$totalReceived = (float) $summary['total_received'];
$totalAllocated = (float) $summary['total_allocated'];
$totalUsed = (float) $summary['total_used'];
$totalWasted = (float) $summary['total_wasted'];

$wastageDenominator = $totalUsed + $totalWasted;

$wastageRate = $wastageDenominator > 0
    ? ($totalWasted / $wastageDenominator) * 100
    : 0;

// Material activity and current store stock for the selected project.
$sql = "
    SELECT
        m.material_name,
        m.unit,
        m.minimum_stock,

        COALESCE(r.received_qty, 0) AS received_qty,
        COALESCE(r.material_cost, 0) AS material_cost,
        COALESCE(a.allocated_qty, 0) AS allocated_qty,
        COALESCE(u.used_qty, 0) AS used_qty,
        COALESCE(w.wasted_qty, 0) AS wasted_qty,

        (
            COALESCE(ar.received_all_time, 0)
            - COALESCE(aa.allocated_all_time, 0)
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
    ) r ON r.material_id = m.material_id

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS allocated_qty
        FROM allocations
        WHERE allocation_date BETWEEN ? AND ?
          AND (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) a ON a.material_id = m.material_id

    LEFT JOIN (
        SELECT
            al.material_id,
            SUM(mu.quantity_used) AS used_qty
        FROM material_usage mu
        INNER JOIN allocations al
            ON al.allocation_id = mu.allocation_id
        WHERE mu.usage_date BETWEEN ? AND ?
          AND (? = 0 OR al.project_id = ?)
        GROUP BY al.material_id
    ) u ON u.material_id = m.material_id

    LEFT JOIN (
        SELECT
            al.material_id,
            SUM(wr.quantity_wasted) AS wasted_qty
        FROM wastage_records wr
        INNER JOIN allocations al
            ON al.allocation_id = wr.allocation_id
        WHERE wr.approval_status = 'approved'
          AND wr.recorded_date BETWEEN ? AND ?
          AND (? = 0 OR al.project_id = ?)
        GROUP BY al.material_id
    ) w ON w.material_id = m.material_id

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS received_all_time
        FROM material_receipts
        WHERE (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) ar ON ar.material_id = m.material_id

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS allocated_all_time
        FROM allocations
        WHERE (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) aa ON aa.material_id = m.material_id

    ORDER BY m.material_name
";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId,
    $projectId, $projectId,
    $projectId, $projectId
]);

$materials = $stmt->fetchAll();

// Current low-stock alerts for the selected project.
$sql = "
    SELECT
        m.material_name,
        m.unit,
        m.minimum_stock,

        (
            COALESCE(r.received_qty, 0)
            - COALESCE(a.allocated_qty, 0)
        ) AS store_balance

    FROM materials m

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS received_qty
        FROM material_receipts
        WHERE (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) r ON r.material_id = m.material_id

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS allocated_qty
        FROM allocations
        WHERE (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) a ON a.material_id = m.material_id

    WHERE (
        COALESCE(r.received_qty, 0)
        - COALESCE(a.allocated_qty, 0)
    ) < m.minimum_stock

    ORDER BY store_balance ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    $projectId, $projectId,
    $projectId, $projectId
]);

$lowStockMaterials = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Construction Material Report</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            color: #222;
            margin: 25px;
            font-size: 12px;
        }

        .report-header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        h1 {
            margin-bottom: 8px;
            font-size: 24px;
            text-transform: uppercase;
        }

        h2 {
            font-size: 17px;
            margin-top: 25px;
        }

        .details {
            line-height: 1.8;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 20px 0;
        }

        .summary-item {
            border: 1px solid #aaa;
            padding: 12px;
            break-inside: avoid;
        }

        .summary-item strong {
            display: block;
            margin-top: 6px;
            font-size: 16px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            border: 1px solid #aaa;
            padding: 7px;
            text-align: left;
        }

        th {
            background: #eee;
        }

        .number {
            text-align: right;
        }

        .low {
            color: #a00000;
            font-weight: bold;
        }

        .ok {
            color: #176b35;
            font-weight: bold;
        }

        .actions {
            margin-bottom: 20px;
        }

        button, .back-link {
            display: inline-block;
            padding: 10px 14px;
            border: 0;
            background: #1f4e78;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            margin-right: 8px;
        }

        .footer {
            margin-top: 30px;
            border-top: 1px solid #aaa;
            padding-top: 10px;
            font-size: 11px;
        }

        @media print {
            body {
                margin: 10mm;
                font-size: 10px;
            }

            .actions {
                display: none;
            }

            .summary-item {
                break-inside: avoid;
            }

            table {
                page-break-inside: auto;
            }

            tr {
                page-break-inside: avoid;
            }

            thead {
                display: table-header-group;
            }

            th {
                background: #eee !important;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            @page {
                size: A4 landscape;
                margin: 12mm;
            }
        }

        @media screen and (max-width: 700px) {
            body {
                margin: 10px;
            }

            .summary {
                grid-template-columns: 1fr;
            }

            .table-container {
                overflow-x: auto;
            }

            table {
                min-width: 850px;
            }
        }
    </style>
</head>

<body>

<div class="actions">
    <button type="button" onclick="window.print()">
        Save as PDF / Print
    </button>

    <a class="back-link"
       href="reports.php?project_id=<?= urlencode((string) $projectId) ?>&amp;start_date=<?= urlencode($startDate) ?>&amp;end_date=<?= urlencode($endDate) ?>">
        Back to Reports
    </a>
</div>

<header class="report-header">
    <h1><?= h($projectName) ?></h1>
    <h2>Material Allocation and Wastage Report</h2>

    <div class="details">
        <strong>Reporting Period:</strong>
        <?= h($startDate) ?> to <?= h($endDate) ?><br>

        <strong>Generated By:</strong>
        <?= h($_SESSION['full_name'] ?? 'User') ?><br>

        <strong>Generated On:</strong>
        <?= h(date('Y-m-d H:i')) ?>
    </div>
</header>

<h2>1. Summary</h2>

<div class="summary">

    <div class="summary-item">
        Total Material Cost
        <strong><?= number_format($totalCost, 2) ?> ETB</strong>
    </div>

    <div class="summary-item">
        Total Received
        <strong><?= number_format($totalReceived, 2) ?></strong>
    </div>

    <div class="summary-item">
        Total Allocated
        <strong><?= number_format($totalAllocated, 2) ?></strong>
    </div>

    <div class="summary-item">
        Total Used
        <strong><?= number_format($totalUsed, 2) ?></strong>
    </div>

    <div class="summary-item">
        Approved Wastage
        <strong><?= number_format($totalWasted, 2) ?></strong>
    </div>

    <div class="summary-item">
        Wastage Rate
        <strong><?= number_format($wastageRate, 2) ?>%</strong>
    </div>

</div>

<p>
    <strong>Wastage rate formula:</strong>
    Approved wastage Ã· (recorded usage + approved wastage) Ã— 100.
    Quantities in summary cards combine different material units and should
    be interpreted as activity indicators, not as a single physical quantity.
</p>

<h2>2. Material-by-Material Report</h2>

<div class="table-container">
    <table>
        <thead>
        <tr>
            <th>Material</th>
            <th>Unit</th>
            <th class="number">Received</th>
            <th class="number">Cost (ETB)</th>
            <th class="number">Allocated</th>
            <th class="number">Used</th>
            <th class="number">Approved Wastage</th>
            <th class="number">Current Store Stock</th>
            <th class="number">Minimum Stock</th>
            <th>Status</th>
        </tr>
        </thead>

        <tbody>
        <?php if (empty($materials)): ?>
            <tr>
                <td colspan="10">No material records found.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($materials as $material): ?>
                <?php
                $storeBalance = (float) $material['store_balance'];
                $minimumStock = (float) $material['minimum_stock'];

                $isLow = $storeBalance < $minimumStock;
                ?>

                <tr>
                    <td><?= h($material['material_name']) ?></td>
                    <td><?= h($material['unit']) ?></td>

                    <td class="number">
                        <?= number_format((float) $material['received_qty'], 2) ?>
                    </td>

                    <td class="number">
                        <?= number_format((float) $material['material_cost'], 2) ?>
                    </td>

                    <td class="number">
                        <?= number_format((float) $material['allocated_qty'], 2) ?>
                    </td>

                    <td class="number">
                        <?= number_format((float) $material['used_qty'], 2) ?>
                    </td>

                    <td class="number">
                        <?= number_format((float) $material['wasted_qty'], 2) ?>
                    </td>

                    <td class="number">
                        <?= number_format($storeBalance, 2) ?>
                    </td>

                    <td class="number">
                        <?= number_format($minimumStock, 2) ?>
                    </td>

                    <td class="<?= $isLow ? 'low' : 'ok' ?>">
                        <?= $isLow ? 'LOW STOCK' : 'OK' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<h2>3. Low-Stock Alerts</h2>

<?php if (empty($lowStockMaterials)): ?>

    <p>No materials are currently below their minimum stock level.</p>

<?php else: ?>

    <div class="table-container">
        <table>
            <thead>
            <tr>
                <th>Material</th>
                <th>Current Store Stock</th>
                <th>Minimum Stock</th>
                <th>Status</th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($lowStockMaterials as $material): ?>
                <tr>
                    <td><?= h($material['material_name']) ?></td>

                    <td>
                        <?= number_format((float) $material['store_balance'], 2) ?>
                        <?= h($material['unit']) ?>
                    </td>

                    <td>
                        <?= number_format((float) $material['minimum_stock'], 2) ?>
                        <?= h($material['unit']) ?>
                    </td>

                    <td class="low">LOW STOCK</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<div class="footer">
    This report was generated from the Construction Site Material Allocation
    and Wastage Tracking System. Please verify records before making
    purchasing, payment, or inventory decisions.
</div>

</body>
</html>
