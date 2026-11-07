<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

requireAdmin();

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Allowed order statuses.
$allowedStatuses = [
    'pending',
    'confirmed',
    'preparing',
    'ready',
    'out_for_delivery',
    'delivered',
    'picked_up',
    'rejected',
    'cancelled'
];

// Get search and filter values.
$searchInput = $_GET['search'] ?? '';
$statusInput = $_GET['status'] ?? '';

$search = is_string($searchInput)
    ? trim($searchInput)
    : '';

$status = is_string($statusInput)
    ? trim($statusInput)
    : '';

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

// Build the query safely.
$sql = "
    SELECT
        o.order_id,
        o.order_number,
        o.order_type,
        o.total_amount,
        o.order_status,
        o.created_at,
        c.full_name,
        c.phone
    FROM orders o
    INNER JOIN customers c
        ON o.customer_id = c.customer_id
    WHERE 1 = 1
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            o.order_number LIKE ?
            OR c.full_name LIKE ?
            OR c.phone LIKE ?
        )
    ";

    $searchTerm = '%' . $search . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($status !== '') {
    $sql .= " AND o.order_status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY o.created_at DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Get order counts for dashboard cards.
$countStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_orders,
        SUM(order_status = 'pending') AS pending_orders,
        SUM(order_status = 'confirmed') AS confirmed_orders,
        SUM(order_status = 'preparing') AS preparing_orders
    FROM orders
");

$orderCounts = $countStmt->fetch();

$adminName = $_SESSION['full_name'] ?? 'Administrator';

function statusClass(string $status): string {
    $classes = [
        'pending' => 'status-pending',
        'confirmed' => 'status-confirmed',
        'preparing' => 'status-preparing',
        'ready' => 'status-ready',
        'out_for_delivery' => 'status-delivery',
        'delivered' => 'status-completed',
        'picked_up' => 'status-completed',
        'rejected' => 'status-rejected',
        'cancelled' => 'status-rejected'
    ];

    return $classes[$status] ?? '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Order Management - Zest Admin</title>

    <link rel="stylesheet" href="../../assets/css/admin.css">

    <style>
        .orders-content {
            padding: 25px;
        }

        .orders-heading {
            margin-bottom: 25px;
        }

        .orders-heading h1 {
            margin-bottom: 8px;
        }

        .order-summary-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }

        .order-summary-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
        }

        .order-summary-card h3 {
            font-size: 14px;
            color: #777;
            margin-bottom: 10px;
        }

        .order-summary-card p {
            font-size: 25px;
            font-weight: bold;
            margin: 0;
        }

        .order-filters {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            background: white;
            padding: 18px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .order-filters input,
        .order-filters select {
            padding: 11px;
            border: 1px solid #ddd;
            border-radius: 6px;
            min-width: 180px;
        }

        .order-filters button,
        .clear-filter {
            padding: 11px 18px;
            border: none;
            border-radius: 6px;
            background: #8b4513;
            color: white;
            cursor: pointer;
            text-decoration: none;
        }

        .clear-filter {
            background: #666;
        }

        .orders-table-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            overflow-x: auto;
        }

        .orders-table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        .orders-table th,
        .orders-table td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .orders-table th {
            background: #f7f7f7;
        }

        .order-status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .status-pending {
            background: #fff0c2;
            color: #805600;
        }

        .status-confirmed {
            background: #dcecff;
            color: #14518a;
        }

        .status-preparing {
            background: #eee1ff;
            color: #6532a0;
        }

        .status-ready {
            background: #d9f3ef;
            color: #176b5d;
        }

        .status-delivery {
            background: #e0e8ff;
            color: #354d9b;
        }

        .status-completed {
            background: #d8f5df;
            color: #17652c;
        }

        .status-rejected {
            background: #ffe0e0;
            color: #a00000;
        }

        .view-order-link {
            color: #8b4513;
            text-decoration: none;
            font-weight: bold;
        }

        .empty-orders {
            text-align: center;
            padding: 35px;
            color: #777;
        }

        @media (max-width: 900px) {
            .order-summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 550px) {
            .order-summary-cards {
                grid-template-columns: 1fr;
            }

            .orders-content {
                padding: 15px;
            }

            .order-filters {
                flex-direction: column;
            }

            .order-filters input,
            .order-filters select {
                width: 100%;
                box-sizing: border-box;
            }
        }
    </style>
</head>

<body>

<div class="admin-layout">

    <aside class="sidebar">
        <h2>ZEST</h2>
        <p>Restaurant Admin</p>

        <nav>
            <a href="../dashboard.php">Dashboard</a>
            <a href="../foods/index.php">Food Management</a>
            <a href="../categories/index.php">Categories</a>
            <a href="../payments/index.php">Payments</a>
            <a href="index.php" class="active">Orders</a>
            <a href="../settings.php">Settings</a>
        </nav>
    </aside>

    <main class="main-content">

        <header class="topbar">
            <h2>Order Management</h2>
            <span><?= h($adminName) ?></span>
        </header>

        <section class="orders-content">

            <div class="orders-heading">
                <h1>Customer Orders</h1>
                <p>View and manage restaurant customer orders.</p>
            </div>

            <div class="order-summary-cards">

                <div class="order-summary-card">
                    <h3>Total Orders</h3>
                    <p><?= (int)($orderCounts['total_orders'] ?? 0) ?></p>
                </div>

                <div class="order-summary-card">
                    <h3>Pending Orders</h3>
                    <p><?= (int)($orderCounts['pending_orders'] ?? 0) ?></p>
                </div>

                <div class="order-summary-card">
                    <h3>Confirmed Orders</h3>
                    <p><?= (int)($orderCounts['confirmed_orders'] ?? 0) ?></p>
                </div>

                <div class="order-summary-card">
                    <h3>Preparing Orders</h3>
                    <p><?= (int)($orderCounts['preparing_orders'] ?? 0) ?></p>
                </div>

            </div>

            <form method="GET" class="order-filters">

                <input
                    type="text"
                    name="search"
                    placeholder="Search order number, name or phone"
                    value="<?= h($search) ?>"
                >

                <select name="status">
                    <option value="">All Order Statuses</option>

                    <?php foreach ($allowedStatuses as $orderStatus): ?>
                        <option
                            value="<?= h($orderStatus) ?>"
                            <?= $status === $orderStatus ? 'selected' : '' ?>
                        >
                            <?= h(ucwords(str_replace('_', ' ', $orderStatus))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit">Search / Filter</button>

                <a href="index.php" class="clear-filter">Clear</a>

            </form>

            <div class="orders-table-container">

                <h2>Orders List</h2>

                <?php if (empty($orders)): ?>

                    <div class="empty-orders">
                        <h3>No orders found</h3>
                        <p>Try another search or status filter.</p>
                    </div>

                <?php else: ?>

                    <table class="orders-table">

                        <thead>
                            <tr>
                                <th>Order Number</th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Order Type</th>
                                <th>Total Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($orders as $order): ?>

                                <tr>
                                    <td>
                                        <strong><?= h($order['order_number']) ?></strong>
                                    </td>

                                    <td><?= h($order['full_name']) ?></td>

                                    <td><?= h($order['phone']) ?></td>

                                    <td>
                                        <?= h(ucfirst($order['order_type'])) ?>
                                    </td>

                                    <td>
                                        <?= number_format((float)$order['total_amount'], 2) ?>
                                        ETB
                                    </td>

                                    <td>
                                        <span class="order-status <?= h(statusClass($order['order_status'])) ?>">
                                            <?= h(str_replace('_', ' ', $order['order_status'])) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= h(date('d M Y, h:i A', strtotime($order['created_at']))) ?>
                                    </td>

                                    <td>
                                        <a
                                            class="view-order-link"
                                            href="view.php?id=<?= (int)$order['order_id'] ?>"
                                        >
                                            View Details
                                        </a>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

</body>
</html>
