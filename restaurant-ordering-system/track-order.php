<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

/**
 * Safely escape HTML output.
 */
function h($value): string
{
    if (!is_scalar($value) && $value !== null) {
        return '';
    }

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Generate a hashed identifier from the visitor's IP address.
 */
function getTrackingAttemptKey(): string
{
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    return hash('sha256', (string) $ipAddress);
}

/**
 * Check whether the visitor is blocked.
 * Uses database time to avoid PHP/database timezone differences.
 */
function isTrackingBlocked(PDO $pdo, string $attemptKey): bool
{
    $pdo->beginTransaction();

    try {

        // Create the tracking record if it does not exist.
        $insert = $pdo->prepare("
            INSERT IGNORE INTO tracking_attempts (
                attempt_key,
                failed_attempts,
                window_started_at,
                blocked_until
            )
            VALUES (?, 0, NOW(), NULL)
        ");

        $insert->execute([$attemptKey]);

        // Lock the visitor's record.
        $stmt = $pdo->prepare("
            SELECT
                failed_attempts,
                window_started_at,
                blocked_until,
                (
                    blocked_until IS NOT NULL
                    AND blocked_until > NOW()
                ) AS currently_blocked,
                (
                    window_started_at <=
                    DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                ) AS window_expired
            FROM tracking_attempts
            WHERE attempt_key = ?
            FOR UPDATE
        ");

        $stmt->execute([$attemptKey]);

        $attempt = $stmt->fetch();

        if (!$attempt) {
            throw new RuntimeException(
                'Unable to check tracking attempts.'
            );
        }

        // Respect an active block.
        if ((int) $attempt['currently_blocked'] === 1) {

            $pdo->commit();

            return true;
        }

        // Reset expired attempts and expired blocks.
        $windowExpired =
            (int) $attempt['window_expired'] === 1;

        $blockExpired =
            !empty($attempt['blocked_until']) &&
            strtotime($attempt['blocked_until']) <= time();

        if ($windowExpired || $blockExpired) {

            $reset = $pdo->prepare("
                UPDATE tracking_attempts
                SET
                    failed_attempts = 0,
                    window_started_at = NOW(),
                    blocked_until = NULL
                WHERE attempt_key = ?
            ");

            $reset->execute([$attemptKey]);
        }

        $pdo->commit();

        return false;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Record a failed tracking attempt.
 * Block the visitor after five failed attempts.
 */
function recordTrackingFailure(
    PDO $pdo,
    string $attemptKey
): void {

    $pdo->beginTransaction();

    try {

        $stmt = $pdo->prepare("
            SELECT
                failed_attempts,
                (
                    window_started_at <=
                    DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                ) AS window_expired
            FROM tracking_attempts
            WHERE attempt_key = ?
            FOR UPDATE
        ");

        $stmt->execute([$attemptKey]);

        $attempt = $stmt->fetch();

        if (!$attempt) {
            throw new RuntimeException(
                'Tracking attempt record not found.'
            );
        }

        // Start a new window if the old one has expired.
        if ((int) $attempt['window_expired'] === 1) {
            $failedAttempts = 1;
        } else {
            $failedAttempts =
                (int) $attempt['failed_attempts'] + 1;
        }

        $update = $pdo->prepare("
            UPDATE tracking_attempts
            SET
                failed_attempts = ?,
                window_started_at = IF(
                    ? = 1,
                    NOW(),
                    window_started_at
                ),
                blocked_until = IF(
                    ? >= 5,
                    DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                    NULL
                )
            WHERE attempt_key = ?
        ");

        $windowExpired =
            (int) $attempt['window_expired'];

        $update->execute([
            $failedAttempts,
            $windowExpired,
            $failedAttempts,
            $attemptKey
        ]);

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

// --------------------------------------------------
// 1. Initialize page data
// --------------------------------------------------

$order = null;
$orderItems = [];
$payments = [];
$statusHistory = [];

$error = '';

$orderNumber = '';
$phone = '';

$attemptKey = getTrackingAttemptKey();

$isBlocked = false;

// --------------------------------------------------
// 2. Process tracking request
// --------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    // Validate CSRF token safely.
    $csrfInput = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfInput) ||
        !verifyCsrfToken($csrfInput)
    ) {

        http_response_code(403);

        exit(
            'Invalid security token. Please refresh the page and try again.'
        );
    }

    try {

        // Check rate limit before processing.
        $isBlocked = isTrackingBlocked(
            $pdo,
            $attemptKey
        );

        if ($isBlocked) {

            $error = 'Too many failed attempts. Please try again after 15 minutes.';

        } else {

            // Safely read submitted values.
            $orderNumberInput = $_POST['order_number'] ?? '';
            $phoneInput = $_POST['phone'] ?? '';

            $orderNumber = is_string($orderNumberInput)
                ? strtoupper(trim($orderNumberInput))
                : '';

            $phone = is_string($phoneInput)
                ? trim($phoneInput)
                : '';

            // Validate order number format.
            $validOrderNumber = preg_match(
                '/^ZEST-\d{8}-[A-F0-9]{12}$/',
                $orderNumber
            );

            if ($orderNumber === '' || $phone === '') {

                $error = 'Please enter your order number and phone number.';

            } elseif (
                !$validOrderNumber ||
                strlen($orderNumber) > 30 ||
                strlen($phone) > 20
            ) {

                $error = 'Please check your order number and phone number.';

            } else {

                // Find order using order number and phone number.
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
                    WHERE o.order_number = ?
                      AND c.phone = ?
                    LIMIT 1
                ");

                $stmt->execute([
                    $orderNumber,
                    $phone
                ]);

                $order = $stmt->fetch();

                if (!$order) {

                    // Record unsuccessful lookup.
                    recordTrackingFailure(
                        $pdo,
                        $attemptKey
                    );

                    $error = 'Order not found. Please check your order number and phone number.';

                    // Check whether this attempt caused a block.
                    $check = $pdo->prepare("
                        SELECT
                            (
                                blocked_until IS NOT NULL
                                AND blocked_until > NOW()
                            ) AS currently_blocked
                        FROM tracking_attempts
                        WHERE attempt_key = ?
                        LIMIT 1
                    ");

                    $check->execute([$attemptKey]);

                    $isBlocked =
                        (int) $check->fetchColumn() === 1;

                    if ($isBlocked) {

                        $error = 'Too many failed attempts. Please try again after 15 minutes.';

                    }

                } else {

                    // Successful lookup: clear failed attempts.
                    $deleteAttempts = $pdo->prepare("
                        DELETE FROM tracking_attempts
                        WHERE attempt_key = ?
                    ");

                    $deleteAttempts->execute([$attemptKey]);

                    // ------------------------------------------
                    // 3. Retrieve ordered food items
                    // ------------------------------------------

                    $stmt = $pdo->prepare("
                        SELECT
                            food_name,
                            quantity,
                            unit_price,
                            line_total
                        FROM order_items
                        WHERE order_id = ?
                        ORDER BY order_item_id ASC
                    ");

                    $stmt->execute([
                        $order['order_id']
                    ]);

                    $orderItems = $stmt->fetchAll();

                    // ------------------------------------------
                    // 4. Retrieve payment information
                    // ------------------------------------------
                    // Never display transaction references.

                    $stmt = $pdo->prepare("
                        SELECT
                            pm.method_name,
                            p.amount,
                            p.payment_status,
                            p.created_at
                        FROM payments p
                        LEFT JOIN payment_methods pm
                            ON p.payment_method_id = pm.payment_method_id
                        WHERE p.order_id = ?
                        ORDER BY p.payment_id DESC
                    ");

                    $stmt->execute([
                        $order['order_id']
                    ]);

                    $payments = $stmt->fetchAll();

                    // ------------------------------------------
                    // 5. Retrieve order status history
                    // ------------------------------------------

                    $stmt = $pdo->prepare("
                        SELECT
                            old_status,
                            new_status,
                            changed_at
                        FROM order_status_history
                        WHERE order_id = ?
                        ORDER BY history_id ASC
                    ");

                    $stmt->execute([
                        $order['order_id']
                    ]);

                    $statusHistory = $stmt->fetchAll();
                }
            }
        }

    } catch (Throwable $e) {

        error_log(
            '[ZEST TRACKING ERROR] ' . $e->getMessage()
        );

        $error = 'Unable to retrieve your order right now. Please try again later.';
    }
}

// --------------------------------------------------
// 6. Define delivery or pickup progress
// --------------------------------------------------

$progressSteps = [];

if ($order) {

    if ($order['order_type'] === 'delivery') {

        $progressSteps = [
            'pending' => 'Order Placed',
            'confirmed' => 'Confirmed',
            'preparing' => 'Preparing',
            'ready' => 'Ready',
            'out_for_delivery' => 'Out for Delivery',
            'delivered' => 'Delivered'
        ];

    } else {

        $progressSteps = [
            'pending' => 'Order Placed',
            'confirmed' => 'Confirmed',
            'preparing' => 'Preparing',
            'ready' => 'Ready',
            'picked_up' => 'Picked Up'
        ];
    }
}

$currentStatus = $order['order_status'] ?? '';

$terminalStatus = in_array(
    $currentStatus,
    ['rejected', 'cancelled'],
    true
);

$currentStepIndex = array_search(
    $currentStatus,
    array_keys($progressSteps),
    true
);

if ($currentStepIndex === false) {
    $currentStepIndex = -1;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Track Your Order - Zest Restaurant</title>

    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body>

    <?php require_once __DIR__ . '/includes/header.php'; ?>

    <main class="tracking-container">

        <div class="tracking-heading">

            <h1>Track Your Order</h1>

            <p>
                Enter your order number and phone number to check your order.
            </p>

        </div>

        <div class="tracking-card">

            <?php if ($error !== ''): ?>

                <div class="tracking-error">
                    <?= h($error) ?>
                </div>

            <?php endif; ?>

            <form method="POST" class="tracking-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h(csrfToken()) ?>"
                >

                <label for="order_number">
                    Order Number
                </label>

                <input
                    type="text"
                    name="order_number"
                    id="order_number"
                    placeholder="Example: ZEST-20261103-123456789ABC"
                    value="<?= h($orderNumber) ?>"
                    maxlength="30"
                    required
                    <?= $isBlocked ? 'disabled' : '' ?>
                >

                <label for="phone">
                    Phone Number
                </label>

                <input
                    type="tel"
                    name="phone"
                    id="phone"
                    placeholder="Enter your checkout phone number"
                    value="<?= h($phone) ?>"
                    maxlength="20"
                    required
                    <?= $isBlocked ? 'disabled' : '' ?>
                >

                <button
                    type="submit"
                    <?= $isBlocked ? 'disabled' : '' ?>
                >
                    Track My Order
                </button>

            </form>

        </div>

        <?php if ($order): ?>

            <div class="tracking-card">

                <h2>Order Information</h2>

                <div class="tracking-grid">

                    <div>

                        <p>
                            <strong>Order Number:</strong>
                            <?= h($order['order_number']) ?>
                        </p>

                        <p>
                            <strong>Customer:</strong>
                            <?= h($order['full_name']) ?>
                        </p>

                        <p>
                            <strong>Order Type:</strong>
                            <?= h(ucfirst($order['order_type'])) ?>
                        </p>

                    </div>

                    <div>

                        <p>
                            <strong>Order Date:</strong>

                            <?= h(date(
                                'd M Y, h:i A',
                                strtotime($order['created_at'])
                            )) ?>
                        </p>

                        <p>
                            <strong>Order Status:</strong>

                            <span class="tracking-status <?= (
                                in_array(
                                    $currentStatus,
                                    ['delivered', 'picked_up'],
                                    true
                                )
                                    ? 'success'
                                    : ($terminalStatus ? 'failed' : '')
                            ) ?>">

                                <?= h(str_replace('_', ' ', $currentStatus)) ?>

                            </span>
                        </p>

                    </div>

                </div>

                <?php if ($terminalStatus): ?>

                    <div class="terminal-message terminal-<?= h($currentStatus) ?>">

                        Your order has been
                        <?= h($currentStatus) ?>.

                        Please contact the restaurant if you need assistance.

                    </div>

                <?php else: ?>

                    <h3>Order Progress</h3>

                    <ul class="progress-list">

                        <?php $stepIndex = 0; ?>

                        <?php foreach ($progressSteps as $stepStatus => $stepLabel): ?>

                            <?php

                            $stepClass = '';

                            if ($stepIndex < $currentStepIndex) {
                                $stepClass = 'completed';
                            } elseif ($stepIndex === $currentStepIndex) {
                                $stepClass = 'current';
                            }

                            ?>

                            <li class="progress-step <?= h($stepClass) ?>">

                                <span class="progress-dot"></span>

                                <span>
                                    <?= h($stepLabel) ?>
                                </span>

                            </li>

                            <?php $stepIndex++; ?>

                        <?php endforeach; ?>

                    </ul>

                <?php endif; ?>

            </div>

            <div class="tracking-card">

                <h2>Ordered Food</h2>

                <?php if (empty($orderItems)): ?>

                    <p>No food items found.</p>

                <?php else: ?>

                    <div class="tracking-table-wrapper">

                        <table class="tracking-table">

                            <thead>
                                <tr>
                                    <th>Food</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($orderItems as $item): ?>

                                    <tr>

                                        <td>
                                            <?= h($item['food_name']) ?>
                                        </td>

                                        <td>
                                            <?= (int) $item['quantity'] ?>
                                        </td>

                                        <td>
                                            <?= number_format(
                                                (float) $item['unit_price'],
                                                2
                                            ) ?> ETB
                                        </td>

                                        <td>
                                            <?= number_format(
                                                (float) $item['line_total'],
                                                2
                                            ) ?> ETB
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

                <div class="tracking-total">

                    Subtotal:
                    <?= number_format((float) $order['subtotal'], 2) ?> ETB

                    <br>

                    Delivery:
                    <?= number_format((float) $order['delivery_fee'], 2) ?> ETB

                    <br>

                    Total:
                    <?= number_format((float) $order['total_amount'], 2) ?> ETB

                </div>

            </div>

            <div class="tracking-card">

                <h2>Payment Information</h2>

                <?php if (empty($payments)): ?>

                    <p>No payment has been submitted yet.</p>

                <?php else: ?>

                    <?php foreach ($payments as $payment): ?>

                        <div class="payment-row">

                            <p>
                                <strong>Method:</strong>
                                <?= h($payment['method_name'] ?? 'Not available') ?>
                            </p>

                            <p>
                                <strong>Amount:</strong>

                                <?= number_format(
                                    (float) $payment['amount'],
                                    2
                                ) ?> ETB
                            </p>

                            <p>
                                <strong>Payment Status:</strong>
                                <?= h(ucfirst($payment['payment_status'])) ?>
                            </p>

                            <small>

                                Submitted:

                                <?= h(date(
                                    'd M Y, h:i A',
                                    strtotime($payment['created_at'])
                                )) ?>

                            </small>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

            <?php if ($order['order_type'] === 'delivery'): ?>

                <div class="tracking-card">

                    <h2>Delivery Information</h2>

                    <p>
                        <strong>City:</strong>
                        <?= h($order['city'] ?? '') ?>
                    </p>

                    <p>
                        <strong>Sub-city:</strong>
                        <?= h($order['sub_city'] ?? '') ?>
                    </p>

                    <p>
                        <strong>Address:</strong>
                        <?= h($order['specific_address'] ?? '') ?>
                    </p>

                    <p>
                        <strong>Instructions:</strong>
                        <?= h($order['instructions'] ?? '') ?>
                    </p>

                </div>

            <?php else: ?>

                <div class="tracking-card">

                    <h2>Pickup Information</h2>

                    <p>
                        Your order is for pickup at Zest Restaurant.
                    </p>

                    <p>
                        Please wait until the order status becomes
                        <strong>Ready</strong> before collecting it.
                    </p>

                </div>

            <?php endif; ?>

            <?php if (!empty($statusHistory)): ?>

                <div class="tracking-card">

                    <h2>Order Status History</h2>

                    <div class="tracking-table-wrapper">

                        <table class="tracking-table">

                            <thead>
                                <tr>
                                    <th>Previous Status</th>
                                    <th>New Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($statusHistory as $history): ?>

                                    <tr>

                                        <td>
                                            <?= h(
                                                $history['old_status'] !== null
                                                    ? ucfirst(str_replace(
                                                        '_',
                                                        ' ',
                                                        $history['old_status']
                                                    ))
                                                    : 'New Order'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= h(ucfirst(str_replace(
                                                '_',
                                                ' ',
                                                $history['new_status']
                                            ))) ?>
                                        </td>

                                        <td>
                                            <?= h(date(
                                                'd M Y, h:i A',
                                                strtotime($history['changed_at'])
                                            )) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            <?php endif; ?>

        <?php endif; ?>

    </main>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>

</body>

</html>
