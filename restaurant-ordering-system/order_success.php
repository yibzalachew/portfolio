<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/cart_functions.php';

// --------------------------------------------------
// 1. Safely read the order number
// --------------------------------------------------

$orderInput = $_GET['order'] ?? '';

$orderNumber = is_string($orderInput)
    ? strtoupper(trim($orderInput))
    : '';

// Validate order number format.
if (!preg_match('/^ZEST-\d{8}-[A-F0-9]{12}$/', $orderNumber)) {
    header('Location: index.php');
    exit;
}

// --------------------------------------------------
// 2. Find the order and its latest payment
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT
        o.order_id,
        o.order_number,
        o.order_status,
        o.total_amount,
        p.payment_status,
        pm.method_name
    FROM orders o
    LEFT JOIN payments p
        ON p.payment_id = (
            SELECT p2.payment_id
            FROM payments p2
            WHERE p2.order_id = o.order_id
            ORDER BY p2.payment_id DESC
            LIMIT 1
        )
    LEFT JOIN payment_methods pm
        ON p.payment_method_id = pm.payment_method_id
    WHERE o.order_number = ?
    LIMIT 1
");

$stmt->execute([$orderNumber]);

$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

// --------------------------------------------------
// 3. Determine the payment message
// --------------------------------------------------

$paymentStatus = $order['payment_status'] ?? '';

if ($paymentStatus === 'verified') {

    $paymentMessage = 'Your payment has been verified successfully.';

} elseif ($paymentStatus === 'submitted') {

    $paymentMessage = 'Your payment reference has been submitted and is awaiting administrator verification.';

} elseif ($paymentStatus === 'rejected') {

    $paymentMessage = 'Your payment was not approved. Please contact the restaurant.';

} else {

    $paymentMessage = 'Your order has been received. Please follow the payment instructions or contact the restaurant if you have not submitted payment.';
}

$pageTitle = 'Order Received - Zest Restaurant';

require_once __DIR__ . '/includes/header.php';

?>

<section class="order-success-section">

    <div class="container">

        <div class="order-success-card">

            <div class="success-icon">âœ“</div>

            <h1>Thank You for Your Order!</h1>

            <p>Your order has been received successfully.</p>

            <div class="order-number">

                <span>Order Number</span>

                <strong id="orderNumber">
                    <?= htmlspecialchars(
                        $order['order_number'],
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) ?>
                </strong>

                <button
                    type="button"
                    onclick="copyOrderNumber()"
                >
                    Copy Order Number
                </button>

            </div>

            <div class="payment-status-message">

                <h3>Payment Status</h3>

                <p>
                    <?= htmlspecialchars(
                        $paymentMessage,
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) ?>
                </p>

                <?php if (!empty($order['method_name'])): ?>

                    <p>
                        <strong>Payment method:</strong>
                        <?= htmlspecialchars(
                            $order['method_name'],
                            ENT_QUOTES | ENT_SUBSTITUTE,
                            'UTF-8'
                        ) ?>
                    </p>

                <?php endif; ?>

            </div>

            <p class="pending-message">

                Your order status is:

                <strong>
                    <?= htmlspecialchars(
                        ucwords(str_replace(
                            '_',
                            ' ',
                            $order['order_status']
                        )),
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) ?>
                </strong>

            </p>

            <p>

                Please keep your order number for reference.

                You can track your order using your order number
                and the phone number you provided during checkout.

            </p>

            <a href="track-order.php" class="btn-primary">
                Track Your Order
            </a>

            <a href="index.php">
                Return to Homepage
            </a>

        </div>

    </div>

</section>

<script>

function copyOrderNumber() {

    const orderElement = document.getElementById('orderNumber');

    if (!orderElement) {
        return;
    }

    const orderNumber = orderElement.textContent.trim();

    if (!navigator.clipboard) {
        alert('Clipboard access is unavailable. Please copy the order number manually.');
        return;
    }

    navigator.clipboard.writeText(orderNumber)
        .then(() => {
            alert('Order number copied successfully!');
        })
        .catch(() => {
            alert('Unable to copy. Please copy it manually.');
        });

}

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
