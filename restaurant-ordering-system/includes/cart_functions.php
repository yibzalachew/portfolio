
<?php

require_once __DIR__ . '/auth.php';

/**
 * Get the current shopping cart.
 */
function getCart(): array
{
    $cart = $_SESSION['cart'] ?? [];

    if (!is_array($cart)) {
        return [];
    }

    $cleanCart = [];

    foreach ($cart as $foodId => $quantity) {

        $foodId = filter_var($foodId, FILTER_VALIDATE_INT);
        $quantity = filter_var($quantity, FILTER_VALIDATE_INT);

        if (
            $foodId !== false &&
            $foodId > 0 &&
            $quantity !== false &&
            $quantity >= 1 &&
            $quantity <= 99
        ) {
            $cleanCart[$foodId] = $quantity;
        }
    }

    return $cleanCart;
}

/**
 * Add food to cart.
 */
function addToCart(int $foodId, int $quantity = 1): bool
{
    if ($foodId <= 0 || $quantity < 1 || $quantity > 99) {
        return false;
    }

    global $pdo;

    $stmt = $pdo->prepare("
        SELECT food_id
        FROM foods
        WHERE food_id = ?
          AND is_available = 1
        LIMIT 1
    ");

    $stmt->execute([$foodId]);

    if (!$stmt->fetch()) {
        return false;
    }

    $cart = getCart();

    $currentQuantity = (int) ($cart[$foodId] ?? 0);

    if ($currentQuantity + $quantity > 99) {
        return false;
    }

    $_SESSION['cart'] = $cart;

    $_SESSION['cart'][$foodId] = $currentQuantity + $quantity;

    return true;
}

/**
 * Update item quantity.
 */
function updateCartQuantity(int $foodId, int $quantity): bool
{
    if ($foodId <= 0 || $quantity < 1 || $quantity > 99) {
        return false;
    }

    $cart = getCart();

    if (!isset($cart[$foodId])) {
        return false;
    }

    global $pdo;

    // Confirm that the food still exists and is available.
    $stmt = $pdo->prepare("
        SELECT food_id
        FROM foods
        WHERE food_id = ?
          AND is_available = 1
        LIMIT 1
    ");

    $stmt->execute([$foodId]);

    if (!$stmt->fetch()) {
        return false;
    }

    $_SESSION['cart'] = $cart;

    $_SESSION['cart'][$foodId] = $quantity;

    return true;
}

/**
 * Remove an item.
 */
function removeFromCart(int $foodId): void
{
    $cart = getCart();

    unset($cart[$foodId]);

    $_SESSION['cart'] = $cart;
}

/**
 * Count all items in cart.
 */
function getCartItemCount(): int
{
    return array_sum(getCart());
}

/**
 * Get cart foods from database.
 */
function getCartItems(PDO $pdo): array
{
    $cart = getCart();

    if (empty($cart)) {
        return [];
    }

    // Only validated integer IDs are used to create placeholders.
    $foodIds = array_keys($cart);

    $placeholders = implode(
        ',',
        array_fill(0, count($foodIds), '?')
    );

    $stmt = $pdo->prepare("
        SELECT
            food_id,
            food_name,
            price,
            image,
            is_available
        FROM foods
        WHERE food_id IN ($placeholders)
    ");

    $stmt->execute($foodIds);

    $foods = $stmt->fetchAll();

    $items = [];

    foreach ($foods as $food) {

        $foodId = (int) $food['food_id'];

        $quantity = (int) $cart[$foodId];

        $price = (float) $food['price'];

        $lineTotal = $price * $quantity;

        $items[] = [
            'food_id' => $foodId,
            'food_name' => $food['food_name'],
            'price' => $price,
            'image' => $food['image'],
            'quantity' => $quantity,
            'line_total' => $lineTotal,
            'is_available' => (int) $food['is_available']
        ];
    }

    return $items;
}

/**
 * Calculate subtotal.
 */
function calculateCartSubtotal(array $items): float
{
    $subtotal = 0.0;

    foreach ($items as $item) {

        if (
            !isset($item['line_total']) ||
            !is_numeric($item['line_total'])
        ) {
            continue;
        }

        $subtotal += (float) $item['line_total'];
    }

    return $subtotal;
}
