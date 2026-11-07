
<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/cart_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cart.php');
    exit;
}

// Validate CSRF token.
$csrfInput = $_POST['csrf_token'] ?? '';

if (!is_string($csrfInput) || !verifyCsrfToken($csrfInput)) {
    $_SESSION['cart_message'] = 'Invalid request. Please try again.';
    header('Location: cart.php');
    exit;
}

// Read action safely.
$actionInput = $_POST['action'] ?? '';
$action = is_string($actionInput) ? $actionInput : '';

// Validate food ID.
$foodIdInput = $_POST['food_id'] ?? '';
$foodId = is_string($foodIdInput)
    ? filter_var($foodIdInput, FILTER_VALIDATE_INT)
    : false;

if (!$foodId || $foodId <= 0) {
    $_SESSION['cart_message'] = 'Invalid food selection.';
    header('Location: cart.php');
    exit;
}

// Read quantity safely.
$quantityInput = $_POST['quantity'] ?? '';
$quantity = is_string($quantityInput)
    ? filter_var($quantityInput, FILTER_VALIDATE_INT)
    : false;

// ADD ITEM
if ($action === 'add') {

    if ($quantity === false || $quantity === null || $quantity === '') {
        $quantity = 1;
    }

    if ($quantity < 1 || $quantity > 99) {

        $_SESSION['cart_message'] =
            'Quantity must be between 1 and 99.';

    } elseif (addToCart($foodId, $quantity)) {

        $_SESSION['cart_message'] =
            'Food added to your cart successfully.';

    } else {

        $_SESSION['cart_message'] =
            'Unable to add this food. It may be unavailable or the quantity is invalid.';
    }
}

// UPDATE ITEM
elseif ($action === 'update') {

    if (
        $quantity !== false &&
        $quantity !== null &&
        $quantity >= 1 &&
        $quantity <= 99 &&
        updateCartQuantity($foodId, $quantity)
    ) {

        $_SESSION['cart_message'] =
            'Cart updated successfully.';

    } else {

        $_SESSION['cart_message'] =
            'Invalid quantity. Enter a number between 1 and 99.';
    }
}

// REMOVE ITEM
elseif ($action === 'remove') {

    removeFromCart($foodId);

    $_SESSION['cart_message'] =
        'Item removed from your cart.';

} else {

    $_SESSION['cart_message'] =
        'Invalid cart action.';
}

header('Location: cart.php');
exit;
