<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

requireAdmin();

$stmt = $pdo->query("
    SELECT
        f.food_id,
        f.food_name,
        f.description,
        f.price,
        f.image,
        f.is_available,
        c.category_name
    FROM foods f
    INNER JOIN categories c
        ON f.category_id = c.category_id
    ORDER BY f.food_id DESC
");

$foods = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Food Management - Zest Restaurant</title>
    <link rel="stylesheet" href="../../assets/css/admin.css">
</head>
<body>

<main class="main-content">
    <?php if (!empty($_SESSION['food_message'])): ?>
        <p>
            <?= escapeHtml($_SESSION['food_message']) ?>
        </p>
        <?php unset($_SESSION['food_message']); ?>
    <?php endif; ?>
    <div class="topbar">
        <h1>Food Management</h1>
        <a href="../dashboard.php">Back to Dashboard</a>
    </div>

    <p>
        <a href="create.php">+ Add New Food</a>
        |
        <a href="../categories/index.php">Manage Categories</a>
    </p>

    <section class="dashboard-section">

        <div class="table-wrapper">

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Image</th>
                        <th>Food Name</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Availability</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($foods): ?>

                        <?php foreach ($foods as $food): ?>

                            <tr>
                                <td><?= escapeHtml($food['food_id']) ?></td>

                                <td>
                                   <?php if (!empty($food['image'])): ?>
                                        <img
                                            src="../../uploads/foods/<?= rawurlencode(basename($food['image'])) ?>"
                                            alt="<?= escapeHtml($food['food_name']) ?>"
                                            width="65"
                                            height="50"
                                            style="object-fit:cover;"
                                        >
                                    <?php else: ?>
                                        No image
                                    <?php endif; ?>
                                </td>

                                <td><?= escapeHtml($food['food_name']) ?></td>

                                <td><?= escapeHtml($food['category_name']) ?></td>

                                <td>
                                    <?= number_format((float) $food['price'], 2) ?> ETB
                                </td>

                                <td>
                                    <?= $food['is_available'] ? 'Available' : 'Unavailable' ?>
                                </td>

                                <td>
                                    <a href="edit.php?id=<?= (int) $food['food_id'] ?>">
                                        Edit
                                    </a>

                                    <form method="POST"
                                          action="delete.php"
                                          style="display:inline;">

                                        <input type="hidden" name="csrf_token"
                                               value="<?= escapeHtml(csrfToken()) ?>">

                                        <input type="hidden" name="id"
                                               value="<?= (int) $food['food_id'] ?>">

                                        <button type="submit"
                                                onclick="return confirm('Delete this food item?')">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7">No food items found.</td>
                        </tr>

                    <?php endif; ?>

                </tbody>
            </table>

        </div>

    </section>

</main>

</body>
</html>
