
<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/cart_functions.php';

/**
 * Safely escape output before displaying it in HTML.
 */
function cartEscape($value): string
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

// Retrieve the sanitized cart and existing food records.
$cart = getCart();
$items = getCartItems($pdo);

// Calculate the subtotal of food records still in the database.
$subtotal = calculateCartSubtotal($items);

// Identify cart IDs that no longer exist in the foods table.
$foundFoodIds = array_map(
    'intval',
    array_column($items, 'food_id')
);

$missingFoodIds = array_diff(
    array_keys($cart),
    $foundFoodIds
);

// Check whether any item is unavailable or missing.
$hasUnavailableItems = !empty($missingFoodIds);

foreach ($items as $item) {
    if ((int) $item['is_available'] !== 1) {
        $hasUnavailableItems = true;
        break;
    }
}

// Set the page title.
$pageTitle = 'Shopping Cart - Zest Restaurant';

// Load the shared header.
require_once __DIR__ . '/includes/header.php';

// Retrieve and clear the cart message.
$message = $_SESSION['cart_message'] ?? '';

if (!is_string($message)) {
    $message = '';
}

unset($_SESSION['cart_message']);

?>

<section class="cart-section">

    <div class="container">

        <h1>Your Shopping Cart</h1>

        <p>Review your selected food before checkout.</p>

        <?php if ($message !== ''): ?>

            <div class="cart-message">
                <?= cartEscape($message) ?>
            </div>

        <?php endif; ?>

        <?php if (empty($cart)): ?>

            <div class="empty-cart">

                <h2>Your cart is empty</h2>

                <p>
                    Explore our delicious menu and add your favorite food.
                </p>

                <a href="index.php" class="btn-primary">
                    Explore Menu
                </a>

            </div>

        <?php else: ?>

            <?php if ($hasUnavailableItems): ?>

                <div class="cart-message">

                    Some items in your cart are unavailable or no longer
                    exist in our menu.

                    Please remove those items before proceeding to checkout.

                </div>

            <?php endif; ?>

            <div class="cart-layout">

                <div class="cart-items">

                    <?php foreach ($items as $item): ?>

                        <?php
                        $foodAvailable = (int) $item['is_available'] === 1;
                        ?>

                        <div class="cart-item">

                            <?php if (!empty($item['image'])): ?>

                                <img
                                    src="uploads/foods/<?= rawurlencode(basename((string) $item['image'])) ?>"
                                    alt="<?= cartEscape($item['food_name']) ?>"
                                >

                            <?php endif; ?>

                            <div class="cart-item-details">

                                <h3>
                                    <?= cartEscape($item['food_name']) ?>
                                </h3>

                                <p>
                                    <?= number_format((float) $item['price'], 2) ?>
                                    ETB
                                </p>

                                <?php if (!$foodAvailable): ?>

                                    <p class="unavailable">
                                        Currently unavailable
                                    </p>

                                <?php endif; ?>

                                <?php if ($foodAvailable): ?>

                                    <form
                                        action="cart_action.php"
                                        method="POST"
                                        class="cart-update-form"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= cartEscape(csrfToken()) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="update"
                                        >

                                        <input
                                            type="hidden"
                                            name="food_id"
                                            value="<?= (int) $item['food_id'] ?>"
                                        >

                                        <input
                                            type="number"
                                            name="quantity"
                                            value="<?= (int) $item['quantity'] ?>"
                                            min="1"
                                            max="99"
                                            required
                                            aria-label="Quantity"
                                        >

                                        <button
                                            type="submit"
                                            class="btn-secondary"
                                        >
                                            Update
                                        </button>

                                    </form>

                                <?php endif; ?>

                                <form
                                    action="cart_action.php"
                                    method="POST"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= cartEscape(csrfToken()) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="remove"
                                    >

                                    <input
                                        type="hidden"
                                        name="food_id"
                                        value="<?= (int) $item['food_id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="remove-btn"
                                    >
                                        Remove
                                    </button>

                                </form>

                            </div>

                            <div class="cart-item-total">

                                <?= number_format(
                                    (float) $item['line_total'],
                                    2
                                ) ?> ETB

                            </div>

                        </div>

                    <?php endforeach; ?>

                    <?php foreach ($missingFoodIds as $missingFoodId): ?>

                        <div class="cart-item">

                            <div class="cart-item-details">

                                <h3>Food item no longer available</h3>

                                <p class="unavailable">
                                    This item has been removed from the menu.
                                    Please remove it from your cart.
                                </p>

                                <form
                                    action="cart_action.php"
                                    method="POST"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= cartEscape(csrfToken()) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="remove"
                                    >

                                    <input
                                        type="hidden"
                                        name="food_id"
                                        value="<?= (int) $missingFoodId ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="remove-btn"
                                    >
                                        Remove
                                    </button>

                                </form>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

                <div class="cart-summary">

                    <h2>Order Summary</h2>

                    <div class="summary-row">

                        <span>Subtotal</span>

                        <strong>
                            <?= number_format($subtotal, 2) ?> ETB
                        </strong>

                    </div>

                    <div class="summary-row">

                        <span>Delivery Fee</span>

                        <strong>Calculated at checkout</strong>

                    </div>

                    <hr>

                    <?php if (!$hasUnavailableItems): ?>

                        <a
                            href="checkout.php"
                            class="btn-primary checkout-button"
                        >
                            Proceed to Checkout
                        </a>

                    <?php else: ?>

                        <p class="unavailable">
                            Remove unavailable items to continue.
                        </p>

                        <button
                            type="button"
                            class="btn-primary checkout-button"
                            disabled
                        >
                            Proceed to Checkout
                        </button>

                    <?php endif; ?>

                    <a
                        href="index.php"
                        class="continue-shopping"
                    >
                        Continue Shopping
                    </a>

                </div>

            </div>

        <?php endif; ?>

    </div>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
