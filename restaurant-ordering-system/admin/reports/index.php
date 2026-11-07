
<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

requireAdmin();

$pageTitle = 'Business Reports - Zest Restaurant';

// Validate date inputs.
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

function validReportDate($date)
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed && $parsed->format('Y-m-d') === $date;
}

if (!validReportDate($startDate)) {
    $startDate = date('Y-m-01');
}

if (!validReportDate($endDate)) {
    $endDate = date('Y-m-d');
}

if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$startDateTime = $startDate . ' 00:00:00';
$endDateTime = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';

// Order summary.
$orderStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_orders,
        COALESCE(SUM(CASE WHEN order_status = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmed_orders,
        COALESCE(SUM(CASE WHEN order_status = 'preparing' THEN 1 ELSE 0 END), 0) AS preparing_orders,
        COALESCE(SUM(CASE WHEN order_status IN ('delivered', 'picked_up') THEN 1 ELSE 0 END), 0) AS completed_orders,
        COALESCE(SUM(CASE WHEN order_status IN ('cancelled', 'rejected') THEN 1 ELSE 0 END), 0) AS cancelled_orders
    FROM orders
    WHERE created_at >= ?
      AND created_at < ?
");

$orderStmt->execute([$startDateTime, $endDateTime]);
$orderSummary = $orderStmt->fetch();

// Verified revenue.
$revenueStmt = $pdo->prepare("
    SELECT COALESCE(SUM(p.amount), 0)
    FROM payments p
    INNER JOIN orders o ON p.order_id = o.order_id
    WHERE p.payment_status = 'verified'
      AND o.created_at >= ?
      AND o.created_at < ?
");

$revenueStmt->execute([$startDateTime, $endDateTime]);
$totalRevenue = (float) $revenueStmt->fetchColumn();

// Payment summary.
$paymentStmt = $pdo->prepare("
    SELECT
        p.payment_status,
        COUNT(*) AS payment_count,
        COALESCE(SUM(p.amount), 0) AS payment_total
    FROM payments p
    INNER JOIN orders o ON p.order_id = o.order_id
    WHERE o.created_at >= ?
      AND o.created_at < ?
    GROUP BY p.payment_status
");

$paymentStmt->execute([$startDateTime, $endDateTime]);
$paymentRows = $paymentStmt->fetchAll();

$paymentSummary = [
    'verified' => ['count' => 0, 'amount' => 0],
    'submitted' => ['count' => 0, 'amount' => 0],
    'pending' => ['count' => 0, 'amount' => 0],
    'rejected' => ['count' => 0, 'amount' => 0],
    'refunded' => ['count' => 0, 'amount' => 0]
];

foreach ($paymentRows as $row) {
    $status = $row['payment_status'];

    if (isset($paymentSummary[$status])) {
        $paymentSummary[$status] = [
            'count' => (int) $row['payment_count'],
            'amount' => (float) $row['payment_total']
        ];
    }
}

// Popular food items.
$popularStmt = $pdo->prepare("
    SELECT
        oi.food_name,
        SUM(oi.quantity) AS total_quantity,
        SUM(oi.line_total) AS total_sales
    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.order_id
    WHERE o.created_at >= ?
      AND o.created_at < ?
      AND o.order_status NOT IN ('cancelled', 'rejected')
    GROUP BY oi.food_name
    ORDER BY total_quantity DESC, total_sales DESC
    LIMIT 10
");

$popularStmt->execute([$startDateTime, $endDateTime]);
$popularFoods = $popularStmt->fetchAll();

// Daily order and verified revenue summary.
$dailyStmt = $pdo->prepare("
    SELECT
        DATE(o.created_at) AS report_date,
        COUNT(DISTINCT o.order_id) AS total_orders,
        COALESCE(SUM(
            CASE
                WHEN p.payment_status = 'verified' THEN p.amount
                ELSE 0
            END
        ), 0) AS daily_revenue
    FROM orders o
    LEFT JOIN payments p ON p.order_id = o.order_id
    WHERE o.created_at >= ?
      AND o.created_at < ?
    GROUP BY DATE(o.created_at)
    ORDER BY report_date DESC
");

$dailyStmt->execute([$startDateTime, $endDateTime]);
$dailyReports = $dailyStmt->fetchAll();

// Prepare chart data.
$chartDates = [];
$chartRevenue = [];
$chartOrders = [];

foreach (array_reverse($dailyReports) as $day) {
    $chartDates[] = date('d M', strtotime($day['report_date']));
    $chartRevenue[] = (float) $day['daily_revenue'];
    $chartOrders[] = (int) $day['total_orders'];
}

$popularFoodNames = [];
$popularFoodQuantities = [];

foreach ($popularFoods as $food) {
    $popularFoodNames[] = $food['food_name'];
    $popularFoodQuantities[] = (float) $food['total_quantity'];
}

$orderStatusLabels = [
    'Pending',
    'Confirmed',
    'Preparing',
    'Completed',
    'Cancelled / Rejected'
];

$orderStatusValues = [
    (int) $orderSummary['pending_orders'],
    (int) $orderSummary['confirmed_orders'],
    (int) $orderSummary['preparing_orders'],
    (int) $orderSummary['completed_orders'],
    (int) $orderSummary['cancelled_orders']
];

$totalOrders = (int) $orderSummary['total_orders'];
$averageOrderValue = $totalOrders > 0
    ? $totalRevenue / $totalOrders
    : 0;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>

<div class="reports-container">

    <header class="reports-header">
        <div>
            <h1>Business Reports</h1>
            <p>Zest Restaurant - Sales and Business Analytics</p>
        </div>

        <a href="../dashboard.php" class="reports-back">
            Back to Dashboard
        </a>
    </header>

    <div class="reports-export-actions">

    <a
        href="export.php?start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>"
        class="reports-export-btn"
    >
        Download Excel Workbook (.xlsx)
    </a>

    <button
        type="button"
        class="reports-print-btn"
        onclick="window.print()"
    >
        Print / Save as PDF
    </button>

</div>

    <!-- Date Filter -->
    <section class="reports-filter">

        <h2>Filter Reports</h2>

        <form method="GET" action="index.php">

            <div class="filter-fields">

                <div>
                    <label for="start_date">Start Date</label>
                    <input
                        type="date"
                        id="start_date"
                        name="start_date"
                        value="<?= htmlspecialchars($startDate) ?>"
                        required
                    >
                </div>

                <div>
                    <label for="end_date">End Date</label>
                    <input
                        type="date"
                        id="end_date"
                        name="end_date"
                        value="<?= htmlspecialchars($endDate) ?>"
                        required
                    >
                </div>

                <button type="submit">Generate Report</button>

            </div>

        </form>

    </section>

    <!-- Sales Summary -->
    <section class="reports-section">

        <h2>Sales Summary</h2>

        <div class="reports-cards">

            <div class="report-card">
                <span>Total Orders</span>
                <strong><?= number_format($totalOrders) ?></strong>
            </div>

            <div class="report-card">
                <span>Verified Revenue</span>
                <strong>ETB <?= number_format($totalRevenue, 2) ?></strong>
            </div>

            <div class="report-card">
                <span>Average Revenue per Order</span>
                <strong>ETB <?= number_format($averageOrderValue, 2) ?></strong>
            </div>

            <div class="report-card">
                <span>Completed Orders</span>
                <strong><?= number_format((int)$orderSummary['completed_orders']) ?></strong>
            </div>

        </div>

    </section>

    <!-- Business Analytics Charts -->
<section class="reports-section">

    <h2>Business Analytics</h2>

    <div class="reports-chart-grid">

        <div class="reports-chart-card">
            <h3>Daily Revenue</h3>
            <canvas id="revenueChart"></canvas>
        </div>

        <div class="reports-chart-card">
            <h3>Order Status Distribution</h3>
            <canvas id="orderStatusChart"></canvas>
        </div>

        <div class="reports-chart-card">
            <h3>Popular Food Items</h3>
            <canvas id="popularFoodsChart"></canvas>
        </div>

    </div>

</section>

    <!-- Order Statistics -->
    <section class="reports-section">

        <h2>Order Statistics</h2>

        <div class="reports-cards">

            <div class="report-card">
                <span>Pending</span>
                <strong><?= number_format((int)$orderSummary['pending_orders']) ?></strong>
            </div>

            <div class="report-card">
                <span>Confirmed</span>
                <strong><?= number_format((int)$orderSummary['confirmed_orders']) ?></strong>
            </div>

            <div class="report-card">
                <span>Preparing</span>
                <strong><?= number_format((int)$orderSummary['preparing_orders']) ?></strong>
            </div>

            <div class="report-card">
                <span>Cancelled / Rejected</span>
                <strong><?= number_format((int)$orderSummary['cancelled_orders']) ?></strong>
            </div>

        </div>

    </section>

    <!-- Payment Report -->
    <section class="reports-section">

        <h2>Payment Report</h2>

        <div class="reports-table-wrapper">

            <table class="reports-table">

                <thead>
                    <tr>
                        <th>Payment Status</th>
                        <th>Number of Payments</th>
                        <th>Total Amount</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($paymentSummary as $status => $data): ?>

                        <tr>
                            <td>
                                <?= htmlspecialchars(ucfirst($status)) ?>
                            </td>

                            <td>
                                <?= number_format($data['count']) ?>
                            </td>

                            <td>
                                ETB <?= number_format($data['amount'], 2) ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- Popular Foods -->
    <section class="reports-section">

        <h2>Popular Food Items</h2>

        <div class="reports-table-wrapper">

            <table class="reports-table">

                <thead>
                    <tr>
                        <th>Food Name</th>
                        <th>Quantity Sold</th>
                        <th>Total Sales Value</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($popularFoods): ?>

                        <?php foreach ($popularFoods as $food): ?>

                            <tr>
                                <td>
                                    <?= htmlspecialchars($food['food_name'], ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <?= number_format((float)$food['total_quantity'], 0) ?>
                                </td>

                                <td>
                                    ETB <?= number_format((float)$food['total_sales'], 2) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="3">No food sales found for this period.</td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- Daily Report -->
    <section class="reports-section">

        <h2>Daily Performance</h2>

        <div class="reports-table-wrapper">

            <table class="reports-table">

                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Total Orders</th>
                        <th>Verified Revenue</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($dailyReports): ?>

                        <?php foreach ($dailyReports as $day): ?>

                            <tr>
                                <td>
                                    <?= htmlspecialchars(date('d M Y', strtotime($day['report_date']))) ?>
                                </td>

                                <td>
                                    <?= number_format((int)$day['total_orders']) ?>
                                </td>

                                <td>
                                    ETB <?= number_format((float)$day['daily_revenue'], 2) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="3">No orders found for this period.</td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <footer class="reports-footer">
        <p>
            Report period:
            <?= htmlspecialchars($startDate) ?>
            to
            <?= htmlspecialchars($endDate) ?>
        </p>
        <p>Zest Restaurant Business Reports</p>
    </footer>

</div>
<script>
const chartDates = <?= json_encode($chartDates) ?>;
const chartRevenue = <?= json_encode($chartRevenue) ?>;
const chartOrders = <?= json_encode($chartOrders) ?>;

const orderStatusLabels = <?= json_encode($orderStatusLabels) ?>;
const orderStatusValues = <?= json_encode($orderStatusValues) ?>;

const popularFoodNames = <?= json_encode($popularFoodNames) ?>;
const popularFoodQuantities = <?= json_encode($popularFoodQuantities) ?>;

// Daily revenue chart
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: chartDates,
        datasets: [{
            label: 'Revenue (ETB)',
            data: chartRevenue,
            borderColor: '#a65e2e',
            backgroundColor: 'rgba(166, 94, 46, 0.15)',
            fill: true,
            tension: 0.35,
            borderWidth: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: true }
        },
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Order status chart
new Chart(document.getElementById('orderStatusChart'), {
    type: 'doughnut',
    data: {
        labels: orderStatusLabels,
        datasets: [{
            data: orderStatusValues,
            backgroundColor: [
                '#e6a23c',
                '#2980b9',
                '#8e44ad',
                '#239b56',
                '#d9534f'
            ],
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Popular food chart
new Chart(document.getElementById('popularFoodsChart'), {
    type: 'bar',
    data: {
        labels: popularFoodNames,
        datasets: [{
            label: 'Quantity Sold',
            data: popularFoodQuantities,
            backgroundColor: '#a65e2e',
            borderRadius: 6
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            x: {
                beginAtZero: true
            }
        }
    }
});
</script>
</body>
</html>
