<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

requireAdmin();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    redirectTo('index.php');
}

$stmt = $pdo->prepare("
    SELECT * FROM categories WHERE category_id = ?
");
$stmt->execute([$id]);

$category = $stmt->fetch();

if (!$category) {
    http_response_code(404);
    exit('Category not found.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyAdminCsrf();

    $name = trim($_POST['category_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '' || strlen($name) > 100) {
        $error = 'Enter a valid category name.';
    } else {
        try {
            $update = $pdo->prepare("
                UPDATE categories
                SET category_name = ?,
                    description = ?,
                    is_active = ?
                WHERE category_id = ?
            ");

            $update->execute([
                $name,
                $description,
                $isActive,
                $id
            ]);

            redirectTo('index.php');

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'Could not update category. The name may already exist.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Category - Zest Restaurant</title>
    <link rel="stylesheet" href="../../assets/css/admin.css">
</head>
<body>

<main class="main-content">

    <h1>Edit Category</h1>
    <p><a href="index.php">Back to Categories</a></p>

    <?php if ($error): ?>
        <p style="color:red;"><?= escapeHtml($error) ?></p>
    <?php endif; ?>

    <section class="dashboard-section">

        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?= escapeHtml(csrfToken()) ?>">

            <p>
                <label>Category Name</label><br>
                <input type="text" name="category_name"
                       maxlength="100"
                       value="<?= escapeHtml($category['category_name']) ?>"
                       required>
            </p>

            <br>

            <p>
                <label>Description</label><br>
                <textarea name="description" rows="4"><?= escapeHtml($category['description']) ?></textarea>
            </p>

            <br>

            <p>
                <label>
                    <input type="checkbox" name="is_active"
                        <?= $category['is_active'] ? 'checked' : '' ?>>
                    Active
                </label>
            </p>

            <br>

            <button type="submit">Update Category</button>

        </form>

    </section>

</main>

</body>
</html>
