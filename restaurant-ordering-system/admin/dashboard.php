
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdmin();

// Total orders
$totalOrders = (int) $pdo->query("
    SELECT COUNT(*)
    FROM orders
")->fetchColumn();

// Orders received today
$todayOrders = (int) $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE DATE(created_at) = CURDATE()
")->fetchColumn();

// Revenue from verified payments
$totalRevenue = (float) $pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE payment_status = 'verified'
")->fetchColumn();

// Available foods
$availableFoods = (int) $pdo->query("
    SELECT COUNT(*)
    FROM foods
    WHERE is_available = 1
")->fetchColumn();

// Payments awaiting action
$pendingPayments = (int) $pdo->query("
    SELECT COUNT(*)
    FROM payments
    WHERE payment_status IN ('pending', 'submitted')
")->fetchColumn();

// Latest 10 orders
$stmt = $pdo->query("
    SELECT
        o.order_number,
        c.full_name AS customer_name,
        o.order_type,
        o.total_amount,
        o.order_status,
        o.created_at
    FROM orders o
    INNER JOIN customers c
        ON o.customer_id = c.customer_id
    ORDER BY o.created_at DESC
    LIMIT 10
");

$recentOrders = $stmt->fetchAll();

// Escape output
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | Zest Restaurant</title>

    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>

<div class="admin-layout">

    <!-- Sidebar -->
    <aside class="sidebar">

        <div class="brand">
            ZEST RESTAURANT
        </div>

        <p>Main Menu</p>

        <a href="dashboard.php" class="active">
            Dashboard
        </a>

        <a href="foods/index.php">
            Food Management
        </a>

        <a href="categories/index.php">
            Category Management
        </a>

        <a href="orders/index.php">
            Order Management
        </a>

        <a href="payments/index.php">
            Payment Verification
        </a>

        <p>Business Management</p>

        <a href="reports/index.php">
            Business Reports
        </a>

        <a href="settings.php">
            Restaurant Settings
        </a>

    </aside>


    <!-- Main Content -->
    <main class="main-content">

        <!-- Topbar -->
        <header class="topbar">

            <div>
                <h1>Dashboard</h1>

                <p class="admin-user">
                    Restaurant management overview
                </p>
            </div>

            <div>

                <span class="admin-user">
                    <?= e($_SESSION['full_name'] ?? 'Administrator') ?>
                </span>

                <form
                    action="logout.php"
                    method="POST"
                    style="display:inline-block; margin-left:12px;"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrfToken()) ?>"
                    >

                    <button type="submit" class="logout-button">
                        Logout
                    </button>

                </form>

            </div>

        </header>


        <!-- Welcome Section -->
        <section class="welcome">

            <h2>
                Welcome back, <?= e($_SESSION['full_name'] ?? 'Administrator') ?>!
            </h2>

            <p>
                Here is an overview of your restaurant operations.
            </p>

        </section>


        <!-- Summary Cards -->
        <section class="stats-grid">

            <div class="stat-card">

                <h3>Total Orders</h3>

                <div class="number">
                    <?= number_format($totalOrders) ?>
                </div>

                <div class="note">
                    All recorded orders
                </div>

            </div>


            <div class="stat-card">

                <h3>Today's Orders</h3>

                <div class="number">
                    <?= number_format($todayOrders) ?>
                </div>

                <div class="note">
                    Orders received today
                </div>

            </div>


            <div class="stat-card">

                <h3>Verified Revenue</h3>

                <div class="number">
                    <?= number_format($totalRevenue, 2) ?> ETB
                </div>

                <div class="note">
                    Payments verified by admin
                </div>

            </div>


            <div class="stat-card">

                <h3>Available Foods</h3>

                <div class="number">
                    <?= number_format($availableFoods) ?>
                </div>

                <div class="note">
                    Foods available for ordering
                </div>

            </div>

        </section>


        <!-- Payment Attention -->
        <section class="dashboard-section">

            <div class="section-heading">
                <h2>Payment Attention</h2>

                <a href="payments/index.php">
                    View Payments
                </a>
            </div>

            <p>
                Payments awaiting verification:

                <strong>
                    <?= number_format($pendingPayments) ?>
                </strong>
            </p>

        </section>


        <!-- Recent Orders -->
        <section class="dashboard-section">

            <div class="section-heading">

                <h2>Recent Orders</h2>

                <a href="orders/index.php">
                    View All Orders
                </a>

            </div>


            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>
                            <th>Order Number</th>
                            <th>Customer</th>
                            <th>Order Type</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>

                    </thead>


                    <tbody>

                        <?php if (!empty($recentOrders)): ?>

                            <?php foreach ($recentOrders as $order): ?>

                                <tr>

                                    <td>
                                        <?= e($order['order_number']) ?>
                                    </td>

                                    <td>
                                        <?= e($order['customer_name']) ?>
                                    </td>

                                    <td>
                                        <?= e(ucfirst($order['order_type'])) ?>
                                    </td>

                                    <td>
                                        <?= number_format(
                                            (float) $order['total_amount'],
                                            2
                                        ) ?> ETB
                                    </td>

                                    <td>

                                        <span class="status">
                                            <?= e(
                                                ucwords(
                                                    str_replace(
                                                        '_',
                                                        ' ',
                                                        $order['order_status']
                                                    )
                                                )
                                            ) ?>
                                        </span>

                                    </td>

                                    <td>
                                        <?= e(
                                            date(
                                                'd M Y, H:i',
                                                strtotime($order['created_at'])
                                            )
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="6">
                                    No orders have been received yet.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <!-- Management Modules -->
        <section class="dashboard-section">

            <div class="section-heading">
                <h2>Management Modules</h2>
            </div>

            <div class="quick-links">

                <a href="foods/index.php">
                    Food Management
                </a>

                <a href="categories/index.php">
                    Category Management
                </a>

                <a href="orders/index.php">
                    Order Management
                </a>

                <a href="payments/index.php">
                    Payment Verification
                </a>

                <a href="reports/index.php">
                    Business Reports
                </a>

                <a href="settings.php">
                    Restaurant Settings
                </a>

            </div>

        </section>

    </main>

</div>

</body>
</html>
