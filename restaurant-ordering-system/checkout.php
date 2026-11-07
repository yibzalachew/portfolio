
<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cart_functions.php';

/**
 * Safely escape output for HTML.
 */
function checkoutEscape($value): string
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
 * Read a POST value safely as a string.
 */
function checkoutPostString(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

/**
 * Count characters safely, including UTF-8 text.
 */
function checkoutLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

/**
 * Read a numeric restaurant setting safely.
 */
function checkoutSetting(
    array $settings,
    string $key,
    float $default = 0
): float {

    $value = $settings[$key] ?? $default;

    if (!is_numeric($value)) {
        return $default;
    }

    $number = (float) $value;

    if (!is_finite($number) || $number < 0 || $number > 99999999.99) {
        return $default;
    }

    return $number;
}


// --------------------------------------------------
// 1. LOAD AND VALIDATE SHOPPING CART
// --------------------------------------------------

$cart = getCart();

if (empty($cart)) {
    header('Location: cart.php');
    exit;
}

$items = getCartItems($pdo);

// Detect deleted food records.
$foundFoodIds = array_map(
    'intval',
    array_column($items, 'food_id')
);

$missingFoodIds = array_diff(
    array_keys($cart),
    $foundFoodIds
);

// Return to cart so deleted items can be removed.
if (!empty($missingFoodIds)) {
    $_SESSION['cart_message'] =
        'Some food items have been removed from the menu. Please update your cart.';

    header('Location: cart.php');
    exit;
}

if (empty($items)) {
    header('Location: cart.php');
    exit;
}

// Validate cart IDs and quantities.
$cartIsValid = true;
$foodIds = [];

foreach ($cart as $foodId => $quantity) {

    if (
        !is_int($foodId) ||
        $foodId <= 0 ||
        !is_int($quantity) ||
        $quantity < 1 ||
        $quantity > 99
    ) {
        $cartIsValid = false;
        break;
    }

    $foodIds[] = $foodId;
}

$foodIds = array_values(array_unique($foodIds));

if (!$cartIsValid || empty($foodIds)) {
    $_SESSION['cart_message'] =
        'Your shopping cart contains invalid items. Please update your cart.';

    header('Location: cart.php');
    exit;
}


// --------------------------------------------------
// 2. LOAD RESTAURANT SETTINGS
// --------------------------------------------------

$settingsStmt = $pdo->query("
    SELECT setting_key, setting_value
    FROM restaurant_settings
");

$settings = [];

foreach ($settingsStmt->fetchAll() as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

$deliveryFee = checkoutSetting(
    $settings,
    'delivery_fee'
);

$minimumOrder = checkoutSetting(
    $settings,
    'minimum_order_amount'
);


// --------------------------------------------------
// 3. CHECK ITEM AVAILABILITY
// --------------------------------------------------

$availableItems = array_filter(
    $items,
    static function ($item) {
        return isset($item['is_available'])
            && (int) $item['is_available'] === 1;
    }
);

$hasUnavailableItems = count($availableItems) !== count($items);

$subtotal = calculateCartSubtotal($items);


// --------------------------------------------------
// 4. INITIALIZE CHECKOUT FORM
// --------------------------------------------------

$error = '';

$old = [
    'full_name' => '',
    'phone' => '',
    'order_type' => 'delivery',
    'city' => 'Hawassa',
    'sub_city' => '',
    'specific_address' => '',
    'instructions' => '',
    'customer_note' => ''
];

// Generate a checkout submission token.
// It is separate from the CSRF token.
if (
    !isset($_SESSION['checkout_nonce']) ||
    !is_string($_SESSION['checkout_nonce'])
) {
    $_SESSION['checkout_nonce'] = bin2hex(random_bytes(32));
}


// --------------------------------------------------
// 5. PROCESS CHECKOUT FORM
// --------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    // Read submitted values safely.
    $old['full_name'] = checkoutPostString('full_name');
    $old['phone'] = checkoutPostString('phone');
    $old['order_type'] = checkoutPostString('order_type');

    $old['city'] = checkoutPostString('city', 'Hawassa');
    $old['sub_city'] = checkoutPostString('sub_city');

    $old['specific_address'] =
        checkoutPostString('specific_address');

    $old['instructions'] =
        checkoutPostString('instructions');

    $old['customer_note'] =
        checkoutPostString('customer_note');


    // Verify CSRF token.
    $csrfInput = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfInput) ||
        !verifyCsrfToken($csrfInput)
    ) {

        $error = 'Invalid request. Please refresh the page and try again.';

    }

    // Verify checkout submission token.
    elseif (
        !isset($_SESSION['checkout_nonce']) ||
        !is_string($_SESSION['checkout_nonce'])
    ) {

        $error = 'Your checkout session has expired. Please refresh the page.';

    } else {

        $nonceInput = $_POST['checkout_nonce'] ?? '';

        if (
            !is_string($nonceInput) ||
            !hash_equals($_SESSION['checkout_nonce'], $nonceInput)
        ) {

            $error = 'This checkout form has already been submitted or has expired. Please refresh the page.';

        }
    }


    // Validate cart and customer information.
    if ($error === '' && $cartIsValid === false) {

        $error = 'Your shopping cart contains invalid items. Please update your cart.';

    } elseif ($error === '' && $hasUnavailableItems) {

        $error = 'Some food items are unavailable. Please remove them from your cart.';

    } elseif (
        $error === '' &&
        (
            $old['full_name'] === '' ||
            $old['phone'] === '' ||
            !in_array($old['order_type'], ['delivery', 'pickup'], true)
        )
    ) {

        $error = 'Please complete all required fields.';

    } elseif (
        $error === '' &&
        (
            checkoutLength($old['full_name']) > 100 ||
            checkoutLength($old['phone']) > 20
        )
    ) {

        $error = 'Your name or phone number exceeds the allowed length.';

    } elseif (
        $error === '' &&
        $old['order_type'] === 'delivery' &&
        (
            $old['city'] === '' ||
            $old['specific_address'] === ''
        )
    ) {

        $error = 'Please provide your delivery city and specific address.';

    } elseif (
        $error === '' &&
        $old['order_type'] === 'delivery' &&
        (
            checkoutLength($old['city']) > 100 ||
            checkoutLength($old['sub_city']) > 100 ||
            checkoutLength($old['specific_address']) > 255
        )
    ) {

        $error = 'One or more delivery address fields are too long.';

    } elseif (
        $error === '' &&
        (
            checkoutLength($old['instructions']) > 5000 ||
            checkoutLength($old['customer_note']) > 5000
        )
    ) {

        $error = 'Your instructions or order note is too long.';

    } elseif ($error === '' && $subtotal < $minimumOrder) {

        $error = 'Your order does not meet the minimum order amount.';
    }


    // --------------------------------------------------
    // 6. CREATE ORDER USING DATABASE TRANSACTION
    // --------------------------------------------------

    if ($error === '') {

        try {

            $pdo->beginTransaction();

            // Lock food records while processing checkout.
            $placeholders = implode(
                ',',
                array_fill(0, count($foodIds), '?')
            );

            $foodStmt = $pdo->prepare("
                SELECT
                    food_id,
                    food_name,
                    price,
                    is_available
                FROM foods
                WHERE food_id IN ($placeholders)
                FOR UPDATE
            ");

            $foodStmt->execute($foodIds);

            $currentFoods = $foodStmt->fetchAll();

            if (count($currentFoods) !== count($foodIds)) {

                throw new RuntimeException(
                    'Some food items could not be found. Please update your cart.'
                );
            }


            // Build food lookup map.
            $foodMap = [];

            foreach ($currentFoods as $food) {
                $foodMap[(int) $food['food_id']] = $food;
            }


            // Recalculate subtotal using current database prices.
            $currentSubtotal = 0.0;

            foreach ($cart as $foodId => $quantity) {

                if (!isset($foodMap[$foodId])) {

                    throw new RuntimeException(
                        'Some food items could not be found. Please update your cart.'
                    );
                }

                $food = $foodMap[$foodId];

                if ((int) $food['is_available'] !== 1) {

                    throw new RuntimeException(
                        'One or more food items are unavailable. Please update your cart.'
                    );
                }

                $price = (float) $food['price'];

                if (
                    !is_finite($price) ||
                    $price <= 0 ||
                    $price > 99999999.99
                ) {

                    throw new RuntimeException(
                        'A food item has an invalid price. Please contact the restaurant.'
                    );
                }

                $currentSubtotal += $price * $quantity;
            }

            $currentSubtotal = round($currentSubtotal, 2);

            if (
                !is_finite($currentSubtotal) ||
                $currentSubtotal <= 0 ||
                $currentSubtotal > 99999999.99
            ) {

                throw new RuntimeException(
                    'The order total is invalid. Please contact the restaurant.'
                );
            }


            // Check minimum order again.
            if ($currentSubtotal < $minimumOrder) {

                throw new RuntimeException(
                    'Your order does not meet the minimum order amount.'
                );
            }


            // Calculate delivery fee.
            $finalDeliveryFee = (
                $old['order_type'] === 'delivery'
            ) ? $deliveryFee : 0.0;

            $totalAmount = round(
                $currentSubtotal + $finalDeliveryFee,
                2
            );

            if (
                !is_finite($totalAmount) ||
                $totalAmount > 99999999.99
            ) {

                throw new RuntimeException(
                    'The order total exceeds the allowed amount.'
                );
            }


            // --------------------------------------------------
            // 7. CREATE CUSTOMER
            // --------------------------------------------------

            $customerStmt = $pdo->prepare("
                INSERT INTO customers (
                    full_name,
                    phone
                )
                VALUES (
                    :full_name,
                    :phone
                )
            ");

            $customerStmt->execute([
                'full_name' => $old['full_name'],
                'phone' => $old['phone']
            ]);

            $customerId = (int) $pdo->lastInsertId();


            // --------------------------------------------------
            // 8. CREATE DELIVERY ADDRESS
            // --------------------------------------------------

            $addressId = null;

            if ($old['order_type'] === 'delivery') {

                $addressStmt = $pdo->prepare("
                    INSERT INTO delivery_addresses (
                        customer_id,
                        city,
                        sub_city,
                        specific_address,
                        instructions
                    )
                    VALUES (
                        :customer_id,
                        :city,
                        :sub_city,
                        :specific_address,
                        :instructions
                    )
                ");

                $addressStmt->execute([
                    'customer_id' => $customerId,
                    'city' => $old['city'],
                    'sub_city' => $old['sub_city'] !== ''
                        ? $old['sub_city']
                        : null,
                    'specific_address' => $old['specific_address'],
                    'instructions' => $old['instructions'] !== ''
                        ? $old['instructions']
                        : null
                ]);

                $addressId = (int) $pdo->lastInsertId();
            }


            // --------------------------------------------------
            // 9. GENERATE ORDER NUMBER
            // --------------------------------------------------

            $orderNumber = 'ZEST-' .
                date('Ymd') .
                '-' .
                strtoupper(bin2hex(random_bytes(6)));


            // --------------------------------------------------
            // 10. CREATE ORDER
            // --------------------------------------------------

            $orderStmt = $pdo->prepare("
                INSERT INTO orders (
                    customer_id,
                    delivery_address_id,
                    order_number,
                    order_type,
                    subtotal,
                    delivery_fee,
                    total_amount,
                    order_status,
                    customer_note
                )
                VALUES (
                    :customer_id,
                    :delivery_address_id,
                    :order_number,
                    :order_type,
                    :subtotal,
                    :delivery_fee,
                    :total_amount,
                    'pending',
                    :customer_note
                )
            ");

            $orderStmt->execute([
                'customer_id' => $customerId,
                'delivery_address_id' => $addressId,
                'order_number' => $orderNumber,
                'order_type' => $old['order_type'],
                'subtotal' => $currentSubtotal,
                'delivery_fee' => $finalDeliveryFee,
                'total_amount' => $totalAmount,
                'customer_note' => $old['customer_note'] !== ''
                    ? $old['customer_note']
                    : null
            ]);

            $orderId = (int) $pdo->lastInsertId();


            // --------------------------------------------------
            // 11. SAVE ORDER ITEMS
            // --------------------------------------------------

            $itemStmt = $pdo->prepare("
                INSERT INTO order_items (
                    order_id,
                    food_id,
                    food_name,
                    quantity,
                    unit_price,
                    line_total
                )
                VALUES (
                    :order_id,
                    :food_id,
                    :food_name,
                    :quantity,
                    :unit_price,
                    :line_total
                )
            ");

            foreach ($cart as $foodId => $quantity) {

                $food = $foodMap[$foodId];

                $unitPrice = (float) $food['price'];

                $lineTotal = round(
                    $unitPrice * $quantity,
                    2
                );

                $itemStmt->execute([
                    'order_id' => $orderId,
                    'food_id' => $foodId,
                    'food_name' => $food['food_name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal
                ]);
            }


            // --------------------------------------------------
            // 12. COMMIT TRANSACTION
            // --------------------------------------------------

            $pdo->commit();

            // Invalidate the checkout form token.
            unset($_SESSION['checkout_nonce']);

            // Clear cart only after successful order creation.
            unset($_SESSION['cart']);

            // Redirect to payment page.
            header(
                'Location: payment.php?order=' .
                urlencode($orderNumber)
            );

            exit;


        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                '[ZEST CHECKOUT DATABASE ERROR] ' .
                $e->getMessage()
            );

            $error = 'We could not process your order. Please try again later.';


        } catch (RuntimeException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();


        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                '[ZEST CHECKOUT ERROR] ' .
                $e->getMessage()
            );

            $error = 'An unexpected error occurred. Please try again.';
        }
    }
}


// --------------------------------------------------
// 13. LOAD PUBLIC HEADER
// --------------------------------------------------

$pageTitle = 'Checkout - Zest Restaurant';

require_once __DIR__ . '/includes/header.php';

?>

<section class="checkout-section">

    <div class="container">

        <h1>Customer Checkout</h1>

        <p>Enter your details to place your order.</p>


        <?php if ($error !== ''): ?>

            <div class="checkout-error">
                <?= checkoutEscape($error) ?>
            </div>

        <?php endif; ?>


        <?php if ($hasUnavailableItems): ?>

            <div class="checkout-error">

                Some food items are currently unavailable.

                <a href="cart.php">Return to your cart</a>
                to remove them before checkout.

            </div>

        <?php else: ?>

            <div class="checkout-layout">


                <!-- CUSTOMER CHECKOUT FORM -->

                <form
                    action="checkout.php"
                    method="POST"
                    class="checkout-form"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= checkoutEscape(csrfToken()) ?>"
                    >

                    <input
                        type="hidden"
                        name="checkout_nonce"
                        value="<?= checkoutEscape($_SESSION['checkout_nonce']) ?>"
                    >


                    <h2>Customer Information</h2>


                    <label for="full_name">
                        Full Name *
                    </label>

                    <input
                        type="text"
                        name="full_name"
                        id="full_name"
                        maxlength="100"
                        value="<?= checkoutEscape($old['full_name']) ?>"
                        required
                    >


                    <label for="phone">
                        Phone Number *
                    </label>

                    <input
                        type="tel"
                        name="phone"
                        id="phone"
                        maxlength="20"
                        value="<?= checkoutEscape($old['phone']) ?>"
                        required
                    >


                    <h2>Order Type</h2>


                    <label class="radio-option">

                        <input
                            type="radio"
                            name="order_type"
                            value="delivery"
                            <?= $old['order_type'] === 'delivery' ? 'checked' : '' ?>
                            required
                        >

                        Home Delivery

                    </label>


                    <label class="radio-option">

                        <input
                            type="radio"
                            name="order_type"
                            value="pickup"
                            <?= $old['order_type'] === 'pickup' ? 'checked' : '' ?>
                        >

                        Restaurant Pickup

                    </label>


                    <!-- DELIVERY ADDRESS -->

                    <div id="delivery-fields">

                        <h2>Delivery Address</h2>


                        <label for="city">
                            City *
                        </label>

                        <input
                            type="text"
                            name="city"
                            id="city"
                            maxlength="100"
                            value="<?= checkoutEscape($old['city']) ?>"
                        >


                        <label for="sub_city">
                            Sub-city / Area
                        </label>

                        <input
                            type="text"
                            name="sub_city"
                            id="sub_city"
                            maxlength="100"
                            value="<?= checkoutEscape($old['sub_city']) ?>"
                        >


                        <label for="specific_address">
                            Specific Address *
                        </label>

                        <textarea
                            name="specific_address"
                            id="specific_address"
                            rows="3"
                            maxlength="255"
                        ><?= checkoutEscape($old['specific_address']) ?></textarea>


                        <label for="instructions">
                            Delivery Instructions
                        </label>

                        <textarea
                            name="instructions"
                            id="instructions"
                            rows="2"
                            maxlength="5000"
                        ><?= checkoutEscape($old['instructions']) ?></textarea>

                    </div>


                    <!-- ADDITIONAL INFORMATION -->

                    <h2>Additional Information</h2>


                    <label for="customer_note">
                        Order Note
                    </label>

                    <textarea
                        name="customer_note"
                        id="customer_note"
                        rows="3"
                        maxlength="5000"
                        placeholder="Any special requests?"
                    ><?= checkoutEscape($old['customer_note']) ?></textarea>


                    <button
                        type="submit"
                        class="btn-primary"
                    >
                        Place Order
                    </button>

                </form>


                <!-- ORDER SUMMARY -->

                <div class="checkout-summary">

                    <h2>Order Summary</h2>


                    <?php foreach ($items as $item): ?>

                        <div class="summary-row">

                            <span>

                                <?= checkoutEscape($item['food_name']) ?>

                                Ã— <?= (int) $item['quantity'] ?>

                            </span>


                            <strong>

                                <?= number_format(
                                    (float) $item['line_total'],
                                    2
                                ) ?>

                                ETB

                            </strong>

                        </div>

                    <?php endforeach; ?>


                    <hr>


                    <div class="summary-row">

                        <span>Subtotal</span>

                        <strong>
                            <?= number_format($subtotal, 2) ?> ETB
                        </strong>

                    </div>


                    <div class="summary-row">

                        <span>Delivery Fee</span>

                        <strong id="delivery-fee">
                            <?= number_format($deliveryFee, 2) ?> ETB
                        </strong>

                    </div>


                    <div class="summary-row total">

                        <span>Estimated Total</span>

                        <strong id="estimated-total">
                            <?= number_format(
                                $subtotal + $deliveryFee,
                                2
                            ) ?> ETB
                        </strong>

                    </div>


                    <p class="checkout-note">

                        The delivery fee applies only to delivery orders.

                        Pickup orders have no delivery fee.

                    </p>

                </div>

            </div>

        <?php endif; ?>

    </div>

</section>


<!-- CHECKOUT JAVASCRIPT -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    const deliveryFields =
        document.getElementById('delivery-fields');

    const deliveryFeeDisplay =
        document.getElementById('delivery-fee');

    const totalDisplay =
        document.getElementById('estimated-total');

    const radios =
        document.querySelectorAll('input[name="order_type"]');

    const deliveryFee = <?= json_encode(
        $deliveryFee,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    const subtotal = <?= json_encode(
        $subtotal,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;


    function updateOrderType() {

        const selected = document.querySelector(
            'input[name="order_type"]:checked'
        );

        const isDelivery =
            selected && selected.value === 'delivery';


        // Show or hide delivery fields.
        deliveryFields.style.display =
            isDelivery ? 'block' : 'none';


        // Apply required validation to delivery fields.
        deliveryFields
            .querySelectorAll('input, textarea')
            .forEach(function (field) {

                field.required = isDelivery && (
                    field.name === 'city' ||
                    field.name === 'specific_address'
                );

            });


        // Calculate estimated total.
        const fee = isDelivery ? deliveryFee : 0;

        deliveryFeeDisplay.textContent =
            fee.toFixed(2) + ' ETB';

        totalDisplay.textContent =
            (subtotal + fee).toFixed(2) + ' ETB';

    }


    // Listen for order type changes.
    radios.forEach(function (radio) {

        radio.addEventListener(
            'change',
            updateOrderType
        );

    });


    // Set initial form state.
    updateOrderType();

});

</script>


<?php require_once __DIR__ . '/includes/footer.php'; ?>
