
<?php
require_once __DIR__ . '/includes/auth.php';

requireRole(['manager', 'storekeeper', 'engineer']);

require_once __DIR__ . '/includes/db.php';

// Safely display values
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Get stock information
$sql = "
    SELECT
        p.project_name,
        m.material_name,
        m.unit,
        m.minimum_stock,

        COALESCE(r.total_received, 0) AS total_received,
        COALESCE(a.total_allocated, 0) AS total_allocated,
        COALESCE(u.total_used, 0) AS total_used,
        COALESCE(w.total_wasted, 0) AS total_wasted,

        COALESCE(r.total_received, 0)
            - COALESCE(a.total_allocated, 0) AS store_balance,

        COALESCE(a.total_allocated, 0)
            - COALESCE(u.total_used, 0)
            - COALESCE(w.total_wasted, 0) AS allocated_balance,

        COALESCE(r.total_received, 0)
            - COALESCE(u.total_used, 0)
            - COALESCE(w.total_wasted, 0) AS physical_balance

    FROM projects p
    CROSS JOIN materials m

    LEFT JOIN (
        SELECT
            project_id,
            material_id,
            SUM(quantity) AS total_received
        FROM material_receipts
        GROUP BY project_id, material_id
    ) r ON r.project_id = p.project_id
       AND r.material_id = m.material_id

    LEFT JOIN (
        SELECT
            project_id,
            material_id,
            SUM(quantity) AS total_allocated
        FROM allocations
        GROUP BY project_id, material_id
    ) a ON a.project_id = p.project_id
       AND a.material_id = m.material_id

    LEFT JOIN (
        SELECT
            a.project_id,
            a.material_id,
            SUM(u.quantity_used) AS total_used
        FROM material_usage u
        JOIN allocations a
            ON a.allocation_id = u.allocation_id
        GROUP BY a.project_id, a.material_id
    ) u ON u.project_id = p.project_id
       AND u.material_id = m.material_id

    LEFT JOIN (
        SELECT
            a.project_id,
            a.material_id,
            SUM(w.quantity_wasted) AS total_wasted
        FROM wastage_records w
        JOIN allocations a
            ON a.allocation_id = w.allocation_id
        WHERE w.approval_status = 'approved'
        GROUP BY a.project_id, a.material_id
    ) w ON w.project_id = p.project_id
       AND w.material_id = m.material_id

    WHERE p.status = 'active'
    ORDER BY p.project_name, m.material_name
";

$stockRows = $pdo->query($sql)->fetchAll();

// Calculate summary values
$totalMaterials = count($stockRows);
$totalLowStock = 0;
$totalReceived = 0;
$totalAllocated = 0;
$totalUsed = 0;
$totalWasted = 0;

foreach ($stockRows as $row) {
    $storeBalance = (float) $row['store_balance'];
    $minimumStock = (float) $row['minimum_stock'];

    if ($storeBalance < $minimumStock) {
        $totalLowStock++;
    }

    $totalReceived += (float) $row['total_received'];
    $totalAllocated += (float) $row['total_allocated'];
    $totalUsed += (float) $row['total_used'];
    $totalWasted += (float) $row['total_wasted'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Stock Balance Report</title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="stock-page">

<nav class="navbar">
    <div class="brand">
        <h2>Construction Material Management System</h2>
        <p>Material Allocation and Wastage Tracking</p>
    </div>

    <div class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="stock.php" class="active">Stock</a>
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

<main class="container stock-page-content">

    <div class="page-header">
        <h1>Stock Balance Report</h1>
        <p>
            Monitor received materials, store stock, allocations,
            usage, and approved wastage across active projects.
        </p>
    </div>

    <section class="stock-summary">

        <div class="stock-summary-card">
            <span class="stock-card-label">Material Records</span>
            <strong><?= number_format($totalMaterials) ?></strong>
            <small>Active project and material combinations</small>
        </div>

        <div class="stock-summary-card">
            <span class="stock-card-label">Total Received</span>
            <strong><?= number_format($totalReceived, 2) ?></strong>
            <small>Combined material units</small>
        </div>

        <div class="stock-summary-card">
            <span class="stock-card-label">Total Allocated</span>
            <strong><?= number_format($totalAllocated, 2) ?></strong>
            <small>Combined material units</small>
        </div>

        <div class="stock-summary-card">
            <span class="stock-card-label">Total Used</span>
            <strong><?= number_format($totalUsed, 2) ?></strong>
            <small>Combined material units</small>
        </div>

        <div class="stock-summary-card">
            <span class="stock-card-label">Approved Wastage</span>
            <strong><?= number_format($totalWasted, 2) ?></strong>
            <small>Combined material units</small>
        </div>

        <div class="stock-summary-card warning-card">
            <span class="stock-card-label">Low Stock Records</span>
            <strong><?= number_format($totalLowStock) ?></strong>
            <small>Below minimum store-stock level</small>
        </div>

    </section>

    <section class="panel stock-panel">

        <div class="stock-section-heading">
            <div>
                <h2>Material Stock Summary</h2>
                <p>Detailed stock balance for each active project.</p>
            </div>
        </div>

        <div class="table-container stock-table-container">

            <table class="stock-table">

                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Material</th>
                        <th>Received</th>
                        <th>Allocated</th>
                        <th>Available in Store</th>
                        <th>Used</th>
                        <th>Approved Wastage</th>
                        <th>Allocated Balance</th>
                        <th>Total Physical Balance</th>
                        <th>Minimum Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$stockRows): ?>

                    <tr>
                        <td colspan="11" class="empty-stock">
                            No active projects or materials found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($stockRows as $row): ?>

                        <?php
                        $storeBalance = (float) $row['store_balance'];
                        $minimumStock = (float) $row['minimum_stock'];

                        $isLow = $storeBalance < $minimumStock;
                        ?>

                        <tr>
                            <td><?= e($row['project_name']) ?></td>

                            <td class="stock-material-name">
                                <?= e($row['material_name']) ?>
                            </td>

                            <td>
                                <?= number_format((float) $row['total_received'], 2) ?>
                                <?= e($row['unit']) ?>
                            </td>

                            <td>
                                <?= number_format((float) $row['total_allocated'], 2) ?>
                                <?= e($row['unit']) ?>
                            </td>

                            <td class="stock-balance">
                                <?= number_format($storeBalance, 2) ?>
                                <?= e($row['unit']) ?>
                            </td>

                            <td>
                                <?= number_format((float) $row['total_used'], 2) ?>
                                <?= e($row['unit']) ?>
                            </td>

                            <td>
                                <?= number_format((float) $row['total_wasted'], 2) ?>
                                <?= e($row['unit']) ?>
                            </td>

                            <td>
                                <?= number_format((float) $row['allocated_balance'], 2) ?>
                                <?= e($row['unit']) ?>
                            </td>

                            <td>
                                <?= number_format((float) $row['physical_balance'], 2) ?>
                                <?= e($row['unit']) ?>
                            </td>

                            <td>
                                <?= number_format($minimumStock, 2) ?>
                                <?= e($row['unit']) ?>
                            </td>

                            <td>
                                <?php if ($isLow): ?>
                                    <span class="stock-status low">
                                        Low Stock
                                    </span>
                                <?php else: ?>
                                    <span class="stock-status normal">
                                        Sufficient
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="stock-note">
            <h3>How to Read This Report</h3>

            <p>
                <strong>Available in Store:</strong>
                Received âˆ’ Allocated.
            </p>

            <p>
                <strong>Allocated Balance:</strong>
                Allocated âˆ’ Used âˆ’ Approved Wastage.
            </p>

            <p>
                <strong>Total Physical Balance:</strong>
                Received âˆ’ Used âˆ’ Approved Wastage.
            </p>

            <p>
                <strong>Low Stock:</strong>
                The store balance is below the material's minimum-stock level.
            </p>

            <p class="stock-warning-note">
                Note: Summary card quantities combine different material units.
                Use the detailed table to check individual material quantities.
            </p>
        </div>

    </section>

    <footer class="stock-footer">
        Construction Material Management System
    </footer>

</main>

</body>
</html>
