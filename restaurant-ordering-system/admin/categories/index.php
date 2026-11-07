<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

requireAdmin();

$categories = $pdo->query("
    SELECT
        c.category_id,
        c.category_name,
        c.description,
        c.is_active,
        COUNT(f.food_id) AS food_count
    FROM categories c
    LEFT JOIN foods f ON c.category_id = f.category_id
    GROUP BY
        c.category_id,
        c.category_name,
        c.description,
        c.is_active
    ORDER BY c.category_name
")->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - Zest Restaurant</title>
    <link rel="stylesheet" href="../../assets/css/admin.css">
</head>
<body>

<main class="main-content">

    <div class="topbar">
        <h1>Category Management</h1>
        <a href="../dashboard.php">Back to Dashboard</a>
    </div>

    <p>
        <a href="create.php">+ Add Category</a> |
        <a href="../foods/index.php">Food Management</a>
    </p>

    <?php if (!empty($_SESSION['category_message'])): ?>
        <p><?= escapeHtml($_SESSION['category_message']) ?></p>
        <?php unset($_SESSION['category_message']); ?>
    <?php endif; ?>

    <section class="dashboard-section">

        <div class="table-wrapper">

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Category Name</th>
                        <th>Description</th>
                        <th>Foods</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($categories as $category): ?>
                        <tr>
                            <td><?= (int) $category['category_id'] ?></td>
                            <td><?= escapeHtml($category['category_name']) ?></td>
                            <td><?= escapeHtml($category['description']) ?></td>
                            <td><?= (int) $category['food_count'] ?></td>
                            <td>
                                <?= ((int) $category['is_active'] === 1) ? 'Active' : 'Inactive' ?>
                            </td>
                            <td>
                                <a href="edit.php?id=<?= (int) $category['category_id'] ?>">
                                    Edit
                                </a>

                                <form method="POST" action="delete.php"
                                      style="display:inline">

                                    <input type="hidden" name="csrf_token"
                                           value="<?= escapeHtml(csrfToken()) ?>">

                                    <input type="hidden" name="id"
                                           value="<?= (int) $category['category_id'] ?>">

                                    <button type="submit"
                                            onclick="return confirm('Delete this category?')">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$categories): ?>
                        <tr>
                            <td colspan="6">No categories found.</td>
                        </tr>
                    <?php endif; ?>

                </tbody>
            </table>

        </div>

    </section>

</main>

</body>
</html>
