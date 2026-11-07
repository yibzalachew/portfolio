<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

requireAdmin();

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$message = '';

$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$orderId) {
    exit('Invalid order ID.');
}

// Allowed next statuses.
function getNextStatuses(string $currentStatus, string $orderType): array
{
    $transitions = [
        'confirmed' => ['preparing'],
        'preparing' => ['ready'],
        'ready' => [
            'delivery' => ['out_for_delivery'],
            'pickup' => ['picked_up']
        ],
        'out_for_delivery' => ['delivered']
    ];

    if ($currentStatus === 'ready') {
        return $transitions['ready'][$orderType] ?? [];
    }

    return $transitions[$currentStatus] ?? [];
}

// Process status update.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token. Please refresh the page and try again.');
}

    $newStatus = $_POST['new_status'] ?? '';

    try {
        $pdo->beginTransaction();

        // Lock the order while changing its status.
        $stmt = $pdo->prepare("
            SELECT order_id, order_status, order_type
            FROM orders
            WHERE order_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$orderId]);
        $lockedOrder = $stmt->fetch();

        if (!$lockedOrder) {
            throw new Exception('Order not found.');
        }

        $oldStatus = $lockedOrder['order_status'];
        $orderType = $lockedOrder['order_type'];

        $allowedNextStatuses = getNextStatuses($oldStatus, $orderType);

        if (!in_array($newStatus, $allowedNextStatuses, true)) {
            throw new Exception('Invalid order status transition.');
        }

        // Confirm that payment is verified before preparation.
        if ($newStatus === 'preparing') {
            $stmt = $pdo->prepare("
                SELECT payment_id
                FROM payments
                WHERE order_id = ?
                  AND payment_status = 'verified'
                LIMIT 1
            ");
            $stmt->execute([$orderId]);

            if (!$stmt->fetch()) {
                throw new Exception(
                    'This order cannot enter preparation because its payment is not verified.'
                );
            }
        }

        // Update the order status.
        $stmt = $pdo->prepare("
            UPDATE orders
            SET order_status = ?,
                updated_at = NOW()
            WHERE order_id = ?
        ");
        $stmt->execute([$newStatus, $orderId]);

        // Record the status change.
        $adminId = (int)($_SESSION['user_id'] ?? 0);

        $stmt = $pdo->prepare("
            INSERT INTO order_status_history
            (order_id, user_id, old_status, new_status)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $orderId,
            $adminId,
            $oldStatus,
            $newStatus
        ]);

        $pdo->commit();

        $message = 'Order status updated successfully.';

        } catch (PDOException $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[ZEST ORDER UPDATE DATABASE ERROR] ' . $e->getMessage());

        $error = 'A database error occurred while updating the order.';

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[ZEST ORDER UPDATE ERROR] ' . $e->getMessage());

        $error = 'An unexpected error occurred. Please try again.';
    }
}

// Load order and customer information.
$stmt = $pdo->prepare("
    SELECT
        o.*,
        c.full_name,
        c.phone,
        da.city,
        da.sub_city,
        da.specific_address,
        da.instructions
    FROM orders o
    INNER JOIN customers c
        ON o.customer_id = c.customer_id
    LEFT JOIN delivery_addresses da
        ON o.delivery_address_id = da.address_id
    WHERE o.order_id = ?
    LIMIT 1
");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    exit('Order not found.');
}

// Load ordered food items.
$stmt = $pdo->prepare("
    SELECT
        order_item_id,
        food_name,
        quantity,
        unit_price,
        line_total
    FROM order_items
    WHERE order_id = ?
    ORDER BY order_item_id
");
$stmt->execute([$orderId]);
$orderItems = $stmt->fetchAll();

// Load payment attempts.
$stmt = $pdo->prepare("
    SELECT
        p.*,
        pm.method_name,
        u.full_name AS verified_by_name
    FROM payments p
    INNER JOIN payment_methods pm
        ON p.payment_method_id = pm.payment_method_id
    LEFT JOIN users u
        ON p.verified_by = u.user_id
    WHERE p.order_id = ?
    ORDER BY p.payment_id DESC
");
$stmt->execute([$orderId]);
$payments = $stmt->fetchAll();

// Load order status history.
$stmt = $pdo->prepare("
    SELECT
        h.old_status,
        h.new_status,
        h.changed_at,
        u.full_name AS admin_name
    FROM order_status_history h
    LEFT JOIN users u
        ON h.user_id = u.user_id
    WHERE h.order_id = ?
    ORDER BY h.history_id DESC
");
$stmt->execute([$orderId]);
$statusHistory = $stmt->fetchAll();

$nextStatuses = getNextStatuses(
    $order['order_status'],
    $order['order_type']
);

$adminName = $_SESSION['full_name'] ?? 'Administrator';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Order Details - Zest Admin</title>

    <link rel="stylesheet" href="../../assets/css/admin.css">

    <style>
        .order-details-content {
            padding: 25px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 22px;
        }

        .details-card {
            background: white;
            padding: 22px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
            margin-bottom: 20px;
        }

        .details-card h2 {
            margin-top: 0;
            margin-bottom: 18px;
        }

        .details-card p {
            margin: 10px 0;
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
        }

        .details-table th,
        .details-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .details-table th {
            background: #f7f7f7;
        }

        .order-total {
            text-align: right;
            margin-top: 20px;
        }

        .order-total p {
            margin: 10px 0;
        }

        .order-total .grand-total {
            font-size: 21px;
            font-weight: bold;
        }

        .detail-status {
            display: inline-block;
            padding: 6px 12px;
            background: #eee;
            border-radius: 20px;
            text-transform: capitalize;
            font-weight: bold;
        }

        .status-update-form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }

        .status-update-form select {
            padding: 11px;
            min-width: 200px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .status-update-form button {
            padding: 11px 20px;
            background: #8b4513;
            border: none;
            color: white;
            border-radius: 6px;
            cursor: pointer;
        }

        .details-alert {
            padding: 13px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .details-success {
            background: #d8f5df;
            color: #17652c;
        }

        .details-error {
            background: #ffe0e0;
            color: #a00000;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #8b4513;
            text-decoration: none;
        }

        .payment-status {
            text-transform: capitalize;
            font-weight: bold;
        }

        .history-item {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .history-item:last-child {
            border-bottom: none;
        }

        @media (max-width: 700px) {
            .details-grid {
                grid-template-columns: 1fr;
            }

            .order-details-content {
                padding: 15px;
            }

            .details-table {
                min-width: 500px;
            }

            .details-card {
                overflow-x: auto;
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
            <h2>Order Details</h2>
            <span><?= h($adminName) ?></span>
        </header>

        <section class="order-details-content">

            <a href="index.php" class="back-link">
                &larr; Back to Orders
            </a>

            <?php if ($message): ?>
                <div class="details-alert details-success">
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="details-alert details-error">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <h1>Order <?= h($order['order_number']) ?></h1>

            <div class="details-grid">

                <div class="details-card">
                    <h2>Customer Information</h2>

                    <p>
                        <strong>Name:</strong>
                        <?= h($order['full_name']) ?>
                    </p>

                    <p>
                        <strong>Phone:</strong>
                        <?= h($order['phone']) ?>
                    </p>

                    <p>
                        <strong>Order type:</strong>
                        <?= h(ucfirst($order['order_type'])) ?>
                    </p>

                    <p>
                        <strong>Order date:</strong>
                        <?= h(date('d M Y, h:i A', strtotime($order['created_at']))) ?>
                    </p>
                </div>

                <div class="details-card">
                    <h2>Order Status</h2>

                    <p>
                        <strong>Current status:</strong>
                        <span class="detail-status">
                            <?= h(str_replace('_', ' ', $order['order_status'])) ?>
                        </span>
                    </p>

                    <p>
                        <strong>Order number:</strong>
                        <?= h($order['order_number']) ?>
                    </p>

                    <?php if ($order['order_type'] === 'delivery'): ?>

                        <p>
                            <strong>City:</strong>
                            <?= h($order['city']) ?>
                        </p>

                        <p>
                            <strong>Sub-city:</strong>
                            <?= h($order['sub_city']) ?>
                        </p>

                        <p>
                            <strong>Address:</strong>
                            <?= h($order['specific_address']) ?>
                        </p>

                        <p>
                            <strong>Delivery instructions:</strong>
                            <?= h($order['instructions'] ?? '') ?>
                        </p>

                    <?php else: ?>

                        <p>This customer selected restaurant pickup.</p>

                    <?php endif; ?>
                </div>

            </div>

            <div class="details-card">
                <h2>Ordered Food Items</h2>

                <?php if (empty($orderItems)): ?>

                    <p>No food items found for this order.</p>

                <?php else: ?>

                    <table class="details-table">
                        <thead>
                            <tr>
                                <th>Food</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($orderItems as $item): ?>
                                <tr>
                                    <td><?= h($item['food_name']) ?></td>
                                    <td><?= (int)$item['quantity'] ?></td>
                                    <td>
                                        <?= number_format((float)$item['unit_price'], 2) ?> ETB
                                    </td>
                                    <td>
                                        <?= number_format((float)$item['line_total'], 2) ?> ETB
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php endif; ?>

                <div class="order-total">
                    <p>
                        Subtotal:
                        <strong>
                            <?= number_format((float)$order['subtotal'], 2) ?> ETB
                        </strong>
                    </p>

                    <p>
                        Delivery fee:
                        <strong>
                            <?= number_format((float)$order['delivery_fee'], 2) ?> ETB
                        </strong>
                    </p>

                    <p class="grand-total">
                        Total:
                        <?= number_format((float)$order['total_amount'], 2) ?> ETB
                    </p>
                </div>
            </div>

            <div class="details-card">
                <h2>Payment Information</h2>

                <?php if (empty($payments)): ?>

                    <p>No payment has been submitted for this order.</p>

                <?php else: ?>

                    <table class="details-table">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Reference</th>
                                <th>Status</th>
                                <th>Verified By</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= h($payment['method_name']) ?></td>

                                    <td>
                                        <?= number_format((float)$payment['amount'], 2) ?> ETB
                                    </td>

                                    <td><?= h($payment['transaction_reference']) ?></td>

                                    <td class="payment-status">
                                        <?= h($payment['payment_status']) ?>
                                    </td>

                                    <td>
                                        <?= h($payment['verified_by_name'] ?? 'Not verified') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php endif; ?>
            </div>

            <div class="details-card">
                <h2>Update Order Status</h2>

                <?php if (!empty($nextStatuses)): ?>

                    <form method="POST" class="status-update-form">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= h(csrfToken()) ?>"
                        >

                        <label for="new_status">Next status:</label>

                        <select name="new_status" id="new_status" required>
                            <option value="">Select next status</option>

                            <?php foreach ($nextStatuses as $nextStatus): ?>
                                <option value="<?= h($nextStatus) ?>">
                                    <?= h(ucwords(str_replace('_', ' ', $nextStatus))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit"
                            onclick="return confirm('Are you sure you want to update this order status?');">
                            Update Status
                        </button>

                    </form>

                <?php else: ?>

                    <p>
                        No further status update is available for this order.
                    </p>

                    <?php if ($order['order_status'] === 'pending'): ?>
                        <p>
                            This order must first have its payment verified
                            on the Payment Verification page.
                        </p>
                    <?php endif; ?>

                <?php endif; ?>
            </div>

            <div class="details-card">
                <h2>Order Status History</h2>

                <?php if (empty($statusHistory)): ?>

                    <p>No status history available.</p>

                <?php else: ?>

                    <?php foreach ($statusHistory as $history): ?>

                        <div class="history-item">
                            <strong>
                                <?= h(str_replace('_', ' ', $history['old_status'])) ?>
                                &rarr;
                                <?= h(str_replace('_', ' ', $history['new_status'])) ?>
                            </strong>

                            <p>
                                By:
                                <?= h($history['admin_name'] ?? 'System') ?>
                            </p>

                            <small>
                                <?= h(date(
                                    'd M Y, h:i A',
                                    strtotime($history['changed_at'])
                                )) ?>
                            </small>
                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>
            </div>

        </section>

    </main>

</div>

</body>
</html>
