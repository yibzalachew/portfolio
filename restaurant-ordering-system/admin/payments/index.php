<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

requireAdmin();

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token. Please refresh the page and try again.');
}

    $paymentId = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
    $actionInput = $_POST['action'] ?? '';

$action = is_string($actionInput)
    ? $actionInput
    : '';
    $noteInput = $_POST['verification_note'] ?? '';

$note = is_string($noteInput)
    ? trim($noteInput)
    : '';
    $adminId = (int)($_SESSION['user_id'] ?? 0);

    if (!$paymentId || !in_array($action, ['verify', 'reject'], true)) {
        $error = 'Invalid payment request.';
    } elseif (strlen($note) > 1000) {
        $error = 'The verification note is too long.';
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the payment and its order while processing.
            $stmt = $pdo->prepare("
                SELECT p.payment_id, p.order_id, p.payment_status,
                       o.order_status
                FROM payments p
                INNER JOIN orders o ON p.order_id = o.order_id
                WHERE p.payment_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();

            if (!$payment) {
                throw new Exception('Payment record not found.');
            }

            if ($payment['payment_status'] !== 'submitted') {
                throw new Exception('This payment has already been processed.');
            }

            if ($action === 'verify') {
                if ($payment['order_status'] !== 'pending') {
                    throw new Exception(
                        'This order is not pending. Check its current status before verifying payment.'
                    );
                }

                // Do not allow multiple verified payments for one order.
                $stmt = $pdo->prepare("
                    SELECT payment_id
                    FROM payments
                    WHERE order_id = ?
                      AND payment_status = 'verified'
                    LIMIT 1
                ");
                $stmt->execute([$payment['order_id']]);

                if ($stmt->fetch()) {
                    throw new Exception(
                        'This order already has a verified payment.'
                    );
                }

                // Mark payment as verified.
                $stmt = $pdo->prepare("
                    UPDATE payments
                    SET payment_status = 'verified',
                        verified_by = ?,
                        verification_note = ?,
                        paid_at = NOW(),
                        updated_at = NOW()
                    WHERE payment_id = ?
                      AND payment_status = 'submitted'
                ");
                $stmt->execute([
                    $adminId,
                    $note,
                    $paymentId
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new Exception('Payment verification could not be completed.');
                }

                // Confirm the order.
                $stmt = $pdo->prepare("
                    UPDATE orders
                    SET order_status = 'confirmed',
                        updated_at = NOW()
                    WHERE order_id = ?
                      AND order_status = 'pending'
                ");
                $stmt->execute([$payment['order_id']]);

                if ($stmt->rowCount() !== 1) {
                    throw new Exception('The order status could not be updated.');
                }

                // Record the order status change.
                $stmt = $pdo->prepare("
                    INSERT INTO order_status_history
                    (order_id, user_id, old_status, new_status)
                    VALUES (?, ?, 'pending', 'confirmed')
                ");
                $stmt->execute([
                    $payment['order_id'],
                    $adminId
                ]);

                $message = 'Payment verified successfully. The order is now confirmed.';

            } else {
                // Reject the payment. The order remains pending.
                $stmt = $pdo->prepare("
                    UPDATE payments
                    SET payment_status = 'rejected',
                        verified_by = ?,
                        verification_note = ?,
                        updated_at = NOW()
                    WHERE payment_id = ?
                      AND payment_status = 'submitted'
                ");
                $stmt->execute([
                    $adminId,
                    $note,
                    $paymentId
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new Exception('Payment rejection could not be completed.');
                }

                $message = 'Payment rejected. The order remains pending.';
            }

            $pdo->commit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            $error = 'A database error occurred while processing the payment.';
        }
    }
}

// Get recent payment records.
$stmt = $pdo->query("
    SELECT
        p.payment_id,
        p.amount,
        p.transaction_reference,
        p.payment_status,
        p.verification_note,
        p.paid_at,
        p.created_at,
        pm.method_name,
        o.order_number,
        o.order_status,
        c.full_name,
        c.phone,
        u.full_name AS verified_by_name
    FROM payments p
    INNER JOIN payment_methods pm
        ON p.payment_method_id = pm.payment_method_id
    INNER JOIN orders o
        ON p.order_id = o.order_id
    INNER JOIN customers c
        ON o.customer_id = c.customer_id
    LEFT JOIN users u
        ON p.verified_by = u.user_id
    ORDER BY p.payment_id DESC
    LIMIT 100
");

$payments = $stmt->fetchAll();

$adminName = $_SESSION['full_name'] ?? 'Administrator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Verification - Zest Admin</title>
    <link rel="stylesheet" href="../../assets/css/admin.css">

    <style>
        .payments-content {
            padding: 25px;
            overflow-x: auto;
        }

        .payments-content table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
            background: white;
        }

        .payments-content th,
        .payments-content td {
            padding: 13px;
            border-bottom: 1px solid #eee;
            text-align: left;
            vertical-align: top;
        }

        .payments-content th {
            background: #f5f5f5;
        }

        .status-label {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-submitted {
            background: #fff0c2;
            color: #805600;
        }

        .status-verified {
            background: #d8f5df;
            color: #17652c;
        }

        .status-rejected {
            background: #ffe0e0;
            color: #a00000;
        }

        .payment-action-form {
            min-width: 180px;
        }

        .payment-action-form textarea {
            width: 100%;
            min-height: 55px;
            padding: 8px;
            margin-bottom: 7px;
            box-sizing: border-box;
        }

        .payment-action-form button {
            padding: 8px 12px;
            margin: 3px 2px;
            border: none;
            border-radius: 5px;
            color: white;
            cursor: pointer;
        }

        .verify-button {
            background: #238443;
        }

        .reject-button {
            background: #bd3030;
        }

        .payment-alert {
            padding: 13px;
            margin-bottom: 18px;
            border-radius: 6px;
        }

        .payment-success {
            background: #d8f5df;
            color: #17652c;
        }

        .payment-danger {
            background: #ffe0e0;
            color: #a00000;
        }

        .payment-page-heading {
            margin-bottom: 20px;
        }

        .payment-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #8b4513;
            text-decoration: none;
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
            <a href="../settings.php">Settings</a>
            <a href="index.php" class="active">Payments</a>
        </nav>
    </aside>

    <main class="main-content">

        <header class="topbar">
            <h2>Payment Verification</h2>
            <span><?= h($adminName) ?></span>
        </header>

        <section class="payments-content">

            <div class="payment-page-heading">
                <h1>Customer Payments</h1>
                <p>Review transaction references and verify or reject customer payments.</p>
                <a class="payment-link" href="../dashboard.php">
                    &larr; Back to Dashboard
                </a>
            </div>

            <?php if ($message): ?>
                <div class="payment-alert payment-success">
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="payment-alert payment-danger">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <?php if (empty($payments)): ?>

                <p>No payment records found.</p>

            <?php else: ?>

                <table>
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Transaction Reference</th>
                            <th>Payment Status</th>
                            <th>Verification</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td>
                                    <strong><?= h($payment['order_number']) ?></strong>
                                    <br>
                                    <small>Order: <?= h($payment['order_status']) ?></small>
                                </td>

                                <td>
                                    <?= h($payment['full_name']) ?>
                                    <br>
                                    <small><?= h($payment['phone']) ?></small>
                                </td>

                                <td><?= h($payment['method_name']) ?></td>

                                <td>
                                    <?= number_format((float)$payment['amount'], 2) ?> ETB
                                </td>

                                <td>
                                    <strong><?= h($payment['transaction_reference']) ?></strong>
                                </td>

                                <td>
                                    <span class="status-label status-<?= h($payment['payment_status']) ?>">
                                        <?= h(ucfirst($payment['payment_status'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($payment['payment_status'] === 'submitted'): ?>

                                        <form method="POST" class="payment-action-form">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="payment_id"
                                                   value="<?= (int)$payment['payment_id'] ?>">

                                            <textarea name="verification_note"
                                                placeholder="Optional verification note"></textarea>

                                            <button type="submit" name="action"
                                                    value="verify" class="verify-button"
                                                    onclick="return confirm('Have you confirmed this payment?');">
                                                Verify
                                            </button>

                                            <button type="submit" name="action"
                                                    value="reject" class="reject-button"
                                                    onclick="return confirm('Are you sure you want to reject this payment?');">
                                                Reject
                                            </button>
                                        </form>

                                    <?php else: ?>

                                        <strong><?= h(ucfirst($payment['payment_status'])) ?></strong>
                                        <br>

                                        <?php if ($payment['verified_by_name']): ?>
                                            <small>By: <?= h($payment['verified_by_name']) ?></small>
                                        <?php endif; ?>

                                        <?php if ($payment['verification_note']): ?>
                                            <br>
                                            <small>Note: <?= h($payment['verification_note']) ?></small>
                                        <?php endif; ?>

                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php endif; ?>

        </section>
    </main>
</div>

</body>
</html>
