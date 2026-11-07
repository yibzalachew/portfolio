
<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

requireAdmin();

$error = '';

$name = '';
$description = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verifyAdminCsrf();

    // Safely read form values.
    $nameInput = $_POST['category_name'] ?? '';
    $descriptionInput = $_POST['description'] ?? '';

    $name = is_string($nameInput)
        ? trim($nameInput)
        : '';

    $description = is_string($descriptionInput)
        ? trim($descriptionInput)
        : '';

    // Validate category name.
    if ($name === '' || strlen($name) > 100) {

        $error = 'Enter a category name of up to 100 characters.';

    } elseif (strlen($description) > 2000) {

        $error = 'Description must not exceed 2000 characters.';

    } else {

        try {

            // Insert category using a prepared statement.
            $stmt = $pdo->prepare("
                INSERT INTO categories (
                    category_name,
                    description
                )
                VALUES (?, ?)
            ");

            $stmt->execute([
                $name,
                $description
            ]);

            redirectTo('index.php');

        } catch (PDOException $e) {

            error_log('[ZEST CATEGORY CREATE ERROR] ' . $e->getMessage());

            if ($e->getCode() === '23000') {

                $error = 'This category name already exists.';

            } else {

                $error = 'Could not create category. Please try again.';

            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Category - Zest Restaurant</title>

    <link rel="stylesheet" href="../../assets/css/admin.css">

</head>

<body>

<main class="main-content">

    <h1>Add Category</h1>

    <p>
        <a href="index.php">Back to Categories</a>
    </p>

    <?php if ($error !== ''): ?>

        <p style="color:red;" role="alert">
            <?= escapeHtml($error) ?>
        </p>

    <?php endif; ?>

    <section class="dashboard-section">

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapeHtml(csrfToken()) ?>"
            >

            <p>

                <label for="category_name">Category Name</label><br>

                <input
                    type="text"
                    id="category_name"
                    name="category_name"
                    maxlength="100"
                    value="<?= escapeHtml($name) ?>"
                    required
                >

            </p>

            <br>

            <p>

                <label for="description">Description</label><br>

                <textarea
                    id="description"
                    name="description"
                    rows="4"
                    maxlength="2000"
                ><?= escapeHtml($description) ?></textarea>

            </p>

            <br>

            <button type="submit">
                Save Category
            </button>

        </form>

    </section>

</main>

</body>

</html>
