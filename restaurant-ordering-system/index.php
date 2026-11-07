<?php

require_once __DIR__ . '/config/database.php';

$pageTitle = 'Zest Restaurant | Delicious Food in Hawassa';

// Search input
$search = trim($_GET['search'] ?? '');

// Selected category
$categoryId = filter_input(
    INPUT_GET,
    'category',
    FILTER_VALIDATE_INT
);

// Get active categories
$categoryStmt = $pdo->query("
    SELECT category_id, category_name
    FROM categories
    WHERE is_active = 1
    ORDER BY category_name
");

$categories = $categoryStmt->fetchAll();

// Build food query
$sql = "
    SELECT
        f.food_id,
        f.food_name,
        f.description,
        f.price,
        f.image,
        c.category_id,
        c.category_name
    FROM foods f
    INNER JOIN categories c
        ON f.category_id = c.category_id
    WHERE f.is_available = 1
      AND c.is_active = 1
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            f.food_name LIKE ?
            OR f.description LIKE ?
            OR c.category_name LIKE ?
        )
    ";

    $keyword = '%' . $search . '%';

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($categoryId !== false && $categoryId !== null) {
    $sql .= " AND c.category_id = ?";
    $params[] = $categoryId;
}

$sql .= " ORDER BY f.food_id DESC";

$foodStmt = $pdo->prepare($sql);
$foodStmt->execute($params);

$foods = $foodStmt->fetchAll();

// Restaurant information
$settingsStmt = $pdo->query("
    SELECT setting_key, setting_value
    FROM restaurant_settings
");

$settings = [];

foreach ($settingsStmt->fetchAll() as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

$restaurantName = $settings['restaurant_name'] ?? 'Zest Restaurant';
$restaurantLocation = $settings['restaurant_location'] ?? 'Hawassa';

function publicEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

require_once __DIR__ . '/includes/header.php';

?>

<!-- Hero Section -->

<section class="hero">

    <div class="container hero-content">

        <div class="hero-text">

            <span class="eyebrow">WELCOME TO <?= publicEscape(strtoupper($restaurantName)) ?></span>

            <h1>
                Good Food.<br>
                Great <span>Moments.</span>
            </h1>

            <p>
                Discover delicious meals made with fresh ingredients.
                Order your favorites and enjoy them your way.
            </p>

            <div class="hero-buttons">

                <a href="#menu" class="btn btn-primary">
                    Explore Menu
                </a>

                <a href="#about" class="btn btn-outline">
                    Learn More
                </a>

            </div>

            <div class="hero-location">
                <span>ðŸ“</span>
                <?= publicEscape($restaurantLocation) ?>, Ethiopia
            </div>

        </div>

        <div class="hero-image">
            <img
                src="assets/images/hero-food.jpg"
                alt="Delicious food from Zest Restaurant"
                onerror="this.style.display='none';"
            >
        </div>

    </div>

</section>


<!-- Features -->

<section class="features">

    <div class="container features-grid">

        <div class="feature">
            <span>ðŸ”</span>
            <div>
                <h3>Fresh Food</h3>
                <p>Prepared with care.</p>
            </div>
        </div>

        <div class="feature">
            <span>ðŸšš</span>
            <div>
                <h3>Fast Delivery</h3>
                <p>Enjoy food at home.</p>
            </div>
        </div>

        <div class="feature">
            <span>ðŸ›ï¸</span>
            <div>
                <h3>Easy Pickup</h3>
                <p>Order ahead and collect.</p>
            </div>
        </div>

    </div>

</section>


<!-- Food Menu -->

<section class="menu-section" id="menu">

    <div class="container">

        <div class="section-title">

            <span class="eyebrow">TASTE THE DIFFERENCE</span>

            <h2>Explore Our Menu</h2>

            <p>
                Find your favorite meal from our delicious selection.
            </p>

        </div>


        <!-- Search -->

        <form action="index.php#menu" method="GET" class="search-form">

            <input
                type="search"
                name="search"
                placeholder="Search burgers, pizza, chicken..."
                value="<?= publicEscape($search) ?>"
            >

            <?php if ($categoryId): ?>
                <input type="hidden" name="category"
                       value="<?= (int) $categoryId ?>">
            <?php endif; ?>

            <button type="submit">Search</button>

            <?php if ($search !== '' || $categoryId): ?>
                <a href="index.php#menu" class="clear-search">
                    Clear
                </a>
            <?php endif; ?>

        </form>


        <!-- Category Filters -->

        <div class="category-filters">

            <a
                href="index.php#menu"
                class="<?= !$categoryId ? 'selected' : '' ?>"
            >
                All Foods
            </a>

            <?php foreach ($categories as $category): ?>

                <a
                    href="index.php?category=<?= (int) $category['category_id'] ?>#menu"
                    class="<?= (int) $categoryId === (int) $category['category_id'] ? 'selected' : '' ?>"
                >
                    <?= publicEscape($category['category_name']) ?>
                </a>

            <?php endforeach; ?>

        </div>


        <!-- Food Cards -->

        <div class="food-grid">

            <?php if ($foods): ?>

                <?php foreach ($foods as $food): ?>

                    <article class="food-card">

                        <div class="food-image">

                            <?php if (!empty($food['image'])): ?>

                                <img
                                    src="uploads/foods/<?= rawurlencode(basename($food['image'])) ?>"
                                    alt="<?= publicEscape($food['food_name']) ?>"
                                    loading="lazy"
                                >

                            <?php else: ?>

                                <div class="no-image">
                                    <span>ðŸ½ï¸</span>
                                    <p>Food Image</p>
                                </div>

                            <?php endif; ?>

                        </div>

                        <div class="food-details">

                            <span class="food-category">
                                <?= publicEscape($food['category_name']) ?>
                            </span>

                            <h3>
                                <?= publicEscape($food['food_name']) ?>
                            </h3>

                            <p>
                                <?= publicEscape($food['description']) ?>
                            </p>

                            <div class="food-bottom">

                                <strong>
                                    <?= number_format((float) $food['price'], 2) ?>
                                    ETB
                                </strong>

                               <form action="cart_action.php" method="POST">

    <input type="hidden" name="csrf_token"
           value="<?= htmlspecialchars(csrfToken()) ?>">

    <input type="hidden" name="action" value="add">

    <input type="hidden" name="food_id"
           value="<?= (int) $food['food_id'] ?>">

    <input type="hidden" name="quantity" value="1">

    <button type="submit" class="btn-primary">
        Add to Cart
    </button>

</form>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty-menu">

                    <span>ðŸ½ï¸</span>

                    <h3>No food found</h3>

                    <p>
                        Try another search or select a different category.
                    </p>

                    <a href="index.php#menu" class="btn btn-primary">
                        View All Foods
                    </a>

                </div>

            <?php endif; ?>

        </div>

    </div>

</section>


<!-- About -->

<section class="about-section" id="about">

    <div class="container about-content">

        <div>
            <span class="eyebrow">ABOUT US</span>

            <h2>Food That Brings People Together</h2>

            <p>
                At <?= publicEscape($restaurantName) ?>, we believe
                great food creates great memories. We are here to make
                your dining experience convenient and enjoyable.
            </p>

            <p>
                Visit us in <?= publicEscape($restaurantLocation) ?>,
                or order online for delivery and pickup.
            </p>

            <a href="#menu" class="btn btn-primary">
                Order Your Favorite Food
            </a>
        </div>

    </div>

</section>


<?php require_once __DIR__ . '/includes/footer.php'; ?>
