<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

/**
 * Safely escape output.
 */
function paymentEscape($value): string
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
 * Safely read a string from an input array.
 */
function paymentString($value): string
{
    return is_string($value) ? trim($value) : '';
}

/**
 * Get string length safely.
 */
function paymentLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

// --------------------------------------------------
// 1. Validate request method
// --------------------------------------------------

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

// --------------------------------------------------
// 2. Get and validate order number
// --------------------------------------------------

$orderInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['order_number'] ?? '')
    : ($_GET['order'] ?? '');

$orderNumber = paymentString($orderInput);

if (
    $orderNumber === '' ||
    strlen($orderNumber) > 30 ||
    !preg_match('/^[A-Za-z0-9_-]+$/', $orderNumber)
) {
    http_response_code(400);
    exit('A valid order reference is required.');
}

// --------------------------------------------------
// 3. Find the order and customer
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        o.order_id,
        o.order_number,
        o.total_amount,
        o.order_status,
        o.order_type,
        c.full_name,
        c.phone
    FROM orders o
    INNER JOIN customers c
        ON o.customer_id = c.customer_id
    WHERE o.order_number = ?
    LIMIT 1
");

$stmt->execute([$orderNumber]);

$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$error = '';

// --------------------------------------------------
// 4. Process payment submission
// --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF token.
    $csrfInput = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfInput) ||
        !verifyCsrfToken($csrfInput)
    ) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }

    // Validate payment method ID.
    $methodInput = $_POST['payment_method_id'] ?? '';

    $methodId = is_string($methodInput)
        ? filter_var($methodInput, FILTER_VALIDATE_INT)
        : false;

    // Validate transaction reference.
    $reference = paymentString(
        $_POST['transaction_reference'] ?? ''
    );

    if (!$methodId || $methodId <= 0) {

        $error = 'Please select a valid payment method.';

    } elseif ($reference === '') {

        $error = 'Please enter your transaction reference.';

    } elseif (paymentLength($reference) > 100) {

        $error = 'The transaction reference must not exceed 100 characters.';

    } elseif (preg_match('/[\x00-\x1F\x7F]/', $reference)) {

        $error = 'The transaction reference contains invalid characters.';

    } else {

        try {

            // Start database transaction.
            $pdo->beginTransaction();

            // Lock the order to prevent simultaneous submissions.
            $stmt = $pdo->prepare("
                SELECT
                    order_id,
                    order_number,
                    total_amount,
                    order_status
                FROM orders
                WHERE order_number = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([$orderNumber]);

            $lockedOrder = $stmt->fetch();

            if (!$lockedOrder) {
                throw new RuntimeException('Order not found.');
            }

            // Only pending orders can accept payment.
            if ($lockedOrder['order_status'] !== 'pending') {
                throw new RuntimeException(
                    'This order is not currently accepting payments.'
                );
            }

            // Check whether payment has already been submitted.
            $stmt = $pdo->prepare("
                SELECT
                    payment_status
                FROM payments
                WHERE order_id = ?
                ORDER BY payment_id DESC
                LIMIT 1
            ");

            $stmt->execute([
                $lockedOrder['order_id']
            ]);

            $latestPayment = $stmt->fetch();

            if (
                $latestPayment &&
                in_array(
                    $latestPayment['payment_status'],
                    ['submitted', 'verified'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'A payment has already been submitted for this order.'
                );
            }

            // Confirm the payment method is active.
            $stmt = $pdo->prepare("
                SELECT payment_method_id
                FROM payment_methods
                WHERE payment_method_id = ?
                  AND is_active = 1
                LIMIT 1
            ");

            $stmt->execute([$methodId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException(
                    'This payment method is unavailable.'
                );
            }

            // Check whether the reference has already been used.
            $stmt = $pdo->prepare("
                SELECT payment_id
                FROM payments
                WHERE payment_method_id = ?
                  AND transaction_reference = ?
                LIMIT 1
            ");

            $stmt->execute([
                $methodId,
                $reference
            ]);

            if ($stmt->fetch()) {
                throw new RuntimeException(
                    'This transaction reference has already been submitted.'
                );
            }

            // Validate the order amount.
            $amount = (float) $lockedOrder['total_amount'];

            if (!is_finite($amount) || $amount <= 0) {
                throw new RuntimeException(
                    'The order amount is invalid. Please contact the restaurant.'
                );
            }

            // Save payment reference.
            // The amount comes from the database, not the submitted form.
            $stmt = $pdo->prepare("
                INSERT INTO payments (
                    order_id,
                    payment_method_id,
                    amount,
                    transaction_reference,
                    payment_status
                ) VALUES (?, ?, ?, ?, 'submitted')
            ");

            $stmt->execute([
                $lockedOrder['order_id'],
                $methodId,
                $amount,
                $reference
            ]);

            // Commit transaction.
            $pdo->commit();

            // Redirect after successful submission.
            header(
                'Location: order_success.php?order=' .
                rawurlencode($orderNumber)
            );

            exit;

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                '[ZEST PAYMENT DATABASE ERROR] ' . $e->getMessage()
            );

            // The database unique index also protects against
            // simultaneous duplicate transaction references.
            if ($e->getCode() === '23000') {

                $error = 'This transaction reference may already have been used. Please check your reference and try again.';

            } else {

                $error = 'A database error occurred while submitting your payment. Please try again.';

            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {

                $error = $e->getMessage();

            } else {

                error_log(
                    '[ZEST PAYMENT ERROR] ' . $e->getMessage()
                );

                $error = 'An unexpected error occurred. Please try again later.';

            }
        }
    }
}

// --------------------------------------------------
// 5. Reload latest payment status
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        p.payment_id,
        p.payment_status,
        p.transaction_reference,
        pm.method_name
    FROM payments p
    LEFT JOIN payment_methods pm
        ON p.payment_method_id = pm.payment_method_id
    WHERE p.order_id = ?
    ORDER BY p.payment_id DESC
    LIMIT 1
");

$stmt->execute([
    $order['order_id']
]);

$latestPayment = $stmt->fetch();

$paymentAlreadySubmitted = $latestPayment &&
    in_array(
        $latestPayment['payment_status'],
        ['submitted', 'verified'],
        true
    );

// --------------------------------------------------
// 6. Load active payment methods
// --------------------------------------------------

$stmt = $pdo->query("
    SELECT
        payment_method_id,
        method_name,
        account_name,
        account_number,
        payment_instructions
    FROM payment_methods
    WHERE is_active = 1
    ORDER BY payment_method_id
");

$paymentMethods = $stmt->fetchAll();

// --------------------------------------------------
// 7. Preserve form values after validation errors
// --------------------------------------------------

$selectedMethodId = paymentString(
    $_POST['payment_method_id'] ?? ''
);

$referenceValue = paymentString(
    $_POST['transaction_reference'] ?? ''
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Payment - Zest Restaurant</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>

        .payment-page {
            max-width: 850px;
            margin: 50px auto;
            padding: 20px;
        }

        .payment-card {
            background: #fff;
            padding: 25px;
            margin: 20px 0;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0,0,0,.08);
        }

        .payment-method {
            border: 1px solid #ddd;
            padding: 18px;
            margin: 15px 0;
            border-radius: 10px;
        }

        .payment-method label {
            display: block;
            cursor: pointer;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .payment-method p {
            margin: 7px 0;
        }

        .payment-page input[type="text"],
        .payment-page select {
            width: 100%;
            padding: 12px;
            margin: 8px 0 18px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        .payment-page button {
            padding: 13px 22px;
            border: 0;
            border-radius: 6px;
            background: #8b4513;
            color: white;
            cursor: pointer;
        }

        .payment-error {
            background: #ffe5e5;
            color: #a40000;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .payment-notice {
            background: #fff3cd;
            padding: 15px;
            border-radius: 8px;
        }

    </style>

</head>

<body>

<main class="payment-page">

    <h1>Complete Your Payment</h1>

    <div class="payment-card">

        <h2>Order Summary</h2>

        <p>
            <strong>Order number:</strong>
            <?= paymentEscape($order['order_number']) ?>
        </p>

        <p>
            <strong>Customer:</strong>
            <?= paymentEscape($order['full_name']) ?>
        </p>

        <p>
            <strong>Total amount:</strong>
            <?= number_format((float) $order['total_amount'], 2) ?> ETB
        </p>

    </div>

    <?php if ($error !== ''): ?>

        <div class="payment-error">
            <?= paymentEscape($error) ?>
        </div>

    <?php endif; ?>

    <?php if ($paymentAlreadySubmitted): ?>

        <div class="payment-card">

            <h2>Payment submitted</h2>

            <p>
                Your payment reference has already been submitted.
            </p>

            <p>
                <strong>Payment method:</strong>
                <?= paymentEscape(
                    $latestPayment['method_name'] ?? 'Not available'
                ) ?>
            </p>

            <p>
                <strong>Payment status:</strong>
                <?= paymentEscape(
                    ucfirst($latestPayment['payment_status'])
                ) ?>
            </p>

            <p>
                Please wait for the restaurant administrator to verify your payment.
            </p>

        </div>

    <?php elseif ($order['order_status'] !== 'pending'): ?>

        <div class="payment-card">

            <p>
                This order is not currently accepting payments.
            </p>

        </div>

    <?php elseif (empty($paymentMethods)): ?>

        <div class="payment-card">

            <p>
                No payment methods are currently available.
                Please contact the restaurant.
            </p>

        </div>

    <?php else: ?>

        <div class="payment-card">

            <h2>1. Choose a payment method</h2>

            <?php foreach ($paymentMethods as $method): ?>

                <div class="payment-method">

                    <h3>
                        <?= paymentEscape($method['method_name']) ?>
                    </h3>

                    <p>
                        <strong>Account name:</strong>
                        <?= paymentEscape(
                            $method['account_name'] ?: 'Not provided'
                        ) ?>
                    </p>

                    <p>
                        <strong>Account number:</strong>
                        <?= paymentEscape(
                            $method['account_number'] ?: 'Not provided'
                        ) ?>
                    </p>

                    <p>
                        <strong>Instructions:</strong>
                        <?= nl2br(paymentEscape(
                            $method['payment_instructions']
                            ?: 'Contact the restaurant for instructions.'
                        )) ?>
                    </p>

                </div>

            <?php endforeach; ?>

        </div>

        <div class="payment-card">

            <h2>2. Submit your transaction reference</h2>

            <form method="POST" id="paymentForm">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= paymentEscape(csrfToken()) ?>"
                >

                <input
                    type="hidden"
                    name="order_number"
                    value="<?= paymentEscape($orderNumber) ?>"
                >

                <label for="payment_method_id">
                    Payment method
                </label>

                <select
                    name="payment_method_id"
                    id="payment_method_id"
                    required
                >

                    <option value="">
                        Select payment method
                    </option>

                    <?php foreach ($paymentMethods as $method): ?>

                        <option
                            value="<?= (int) $method['payment_method_id'] ?>"
                            <?= $selectedMethodId ===
                                (string) $method['payment_method_id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= paymentEscape($method['method_name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <label for="transaction_reference">
                    Transaction reference
                </label>

                <input
                    type="text"
                    name="transaction_reference"
                    id="transaction_reference"
                    maxlength="100"
                    value="<?= paymentEscape($referenceValue) ?>"
                    placeholder="Enter your transaction reference"
                    required
                >

                <p class="payment-notice">

                    Enter only the transaction reference from your payment receipt.

                    Never enter your PIN or OTP.

                    Your payment will remain pending until an administrator verifies it.

                </p>

                <button type="submit">
                    Submit Payment Reference
                </button>

            </form>

        </div>

    <?php endif; ?>

</main>

</body>
</html>
