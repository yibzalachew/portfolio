<?php
require_once __DIR__ . '/cart_functions.php';
?>
<?php
$pageTitle = $pageTitle ?? 'Zest Restaurant | Delicious Food in Hawassa';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="description"
          content="Enjoy delicious fast food at Zest Restaurant in Hawassa, Ethiopia. Order online for delivery or pickup.">

    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

<header class="site-header">

    <div class="container navbar">

        <a href="index.php" class="logo">
            ZEST<span>.</span>
        </a>

        <nav class="nav-links">
            <a href="index.php">Home</a>
            <a href="#menu">Our Menu</a>
            <a href="#about">About Us</a>
            <a href="#contact">Contact</a>
            <a href="track-order.php">Track Order</a>
        </nav>

       <a href="cart.php" class="cart-link">
    Cart (<?= function_exists('getCartItemCount') ? getCartItemCount() : 0 ?>)
</a>

    </div>

</header>
