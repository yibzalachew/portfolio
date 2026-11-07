
<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

requireAdmin();

$error = '';

$foodName = '';
$description = '';
$categoryId = '';
$price = '';
$isAvailable = true;

$uploadDirectory = __DIR__ . '/../../uploads/foods/';

// Load active categories.
$categories = $pdo->query("
    SELECT category_id, category_name
    FROM categories
    WHERE is_active = 1
    ORDER BY category_name
")->fetchAll();

// Process form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verifyAdminCsrf();

    // Safely read text inputs.
    $foodNameInput = $_POST['food_name'] ?? '';
    $descriptionInput = $_POST['description'] ?? '';
    $categoryInput = $_POST['category_id'] ?? '';
    $priceInput = $_POST['price'] ?? '';

    $foodName = is_string($foodNameInput)
        ? trim($foodNameInput)
        : '';

    $description = is_string($descriptionInput)
        ? trim($descriptionInput)
        : '';

    $categoryId = is_string($categoryInput)
        ? $categoryInput
        : '';

    $price = is_string($priceInput)
        ? trim($priceInput)
        : '';

    $isAvailable = isset($_POST['is_available'])
        && $_POST['is_available'] === 'on';

    // Validate food name.
    if (
        $foodName === '' ||
        strlen($foodName) > 150
    ) {

        $error = 'Food name is required and must not exceed 150 characters.';

    } elseif (strlen($description) > 2000) {

        $error = 'Description must not exceed 2000 characters.';

    } elseif (
        $categoryId === '' ||
        filter_var($categoryId, FILTER_VALIDATE_INT) === false ||
        (int) $categoryId <= 0
    ) {

        $error = 'Please select a valid category.';

    } elseif (
        $price === '' ||
        !is_numeric($price) ||
        !is_finite((float) $price) ||
        (float) $price <= 0 ||
        (float) $price > 99999999.99
    ) {

        $error = 'Please enter a valid food price.';

    } elseif (empty($categories)) {

        $error = 'Please create an active category first.';

    }

    // Confirm the selected category is still active.
    if ($error === '') {

        $categoryCheck = $pdo->prepare("
            SELECT COUNT(*)
            FROM categories
            WHERE category_id = ?
              AND is_active = 1
        ");

        $categoryCheck->execute([(int) $categoryId]);

        if ((int) $categoryCheck->fetchColumn() === 0) {
            $error = 'Please select a valid active category.';
        }
    }

    $imageName = null;
    $imageUploaded = false;

    // Process optional image upload.
    if (
        $error === '' &&
        isset($_FILES['image'])
    ) {

        $image = $_FILES['image'];

        if (!is_array($image) || !isset($image['error'])) {

            $error = 'Invalid image upload.';

        } elseif ($image['error'] !== UPLOAD_ERR_NO_FILE) {

            if ($image['error'] !== UPLOAD_ERR_OK) {

                $error = 'Image upload failed. Please try again.';

            } elseif (
                !isset($image['tmp_name'], $image['size']) ||
                !is_string($image['tmp_name']) ||
                !is_uploaded_file($image['tmp_name'])
            ) {

                $error = 'Invalid uploaded image.';

            } elseif (
                !is_numeric($image['size']) ||
                (int) $image['size'] <= 0 ||
                (int) $image['size'] > 2 * 1024 * 1024
            ) {

                $error = 'Image must not exceed 2 MB.';

            } else {

                // Verify actual file MIME type.
                $finfo = new finfo(FILEINFO_MIME_TYPE);

                $mime = $finfo->file($image['tmp_name']);

                $allowedTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp'
                ];

                // Verify image structure and dimensions.
                $imageInfo = @getimagesize($image['tmp_name']);

                if (
                    !isset($allowedTypes[$mime]) ||
                    $imageInfo === false ||
                    $imageInfo[0] <= 0 ||
                    $imageInfo[1] <= 0 ||
                    $imageInfo[0] > 6000 ||
                    $imageInfo[1] > 6000
                ) {

                    $error = 'Only valid JPG, PNG or WEBP images up to 6000 x 6000 pixels are allowed.';

                } elseif (!is_dir($uploadDirectory) || !is_writable($uploadDirectory)) {

                    $error = 'The food image upload directory is unavailable.';

                } else {

                    // Generate an unpredictable filename.
                    $imageName = bin2hex(random_bytes(16))
                        . '.' . $allowedTypes[$mime];

                    $uploadPath = $uploadDirectory . $imageName;

                    if (
                        move_uploaded_file(
                            $image['tmp_name'],
                            $uploadPath
                        )
                    ) {

                        $imageUploaded = true;

                    } else {

                        $imageName = null;

                        $error = 'Could not save the uploaded image.';
                    }
                }
            }
        }
    }

    // Save food item.
    if ($error === '') {

        try {

            $stmt = $pdo->prepare("
                INSERT INTO foods (
                    category_id,
                    food_name,
                    description,
                    price,
                    image,
                    is_available
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                (int) $categoryId,
                $foodName,
                $description,
                (float) $price,
                $imageName,
                $isAvailable ? 1 : 0
            ]);

            redirectTo('index.php');

        } catch (PDOException $e) {

            // Remove uploaded image if database insertion fails.
            if ($imageUploaded && $imageName !== null) {

                $file = $uploadDirectory . $imageName;

                if (is_file($file)) {
                    unlink($file);
                }
            }

            error_log('[ZEST FOOD CREATE ERROR] ' . $e->getMessage());

            $error = 'Could not save the food item. Please try again.';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Food - Zest Restaurant</title>

    <link rel="stylesheet" href="../../assets/css/admin.css">

</head>

<body>

<main class="main-content">

    <h1>Add New Food</h1>

    <p>
        <a href="index.php">Back to Food List</a>
    </p>

    <?php if ($error !== ''): ?>

        <p style="color:red;" role="alert">
            <?= escapeHtml($error) ?>
        </p>

    <?php endif; ?>

    <section class="dashboard-section">

        <form method="POST" enctype="multipart/form-data">

            <input type="hidden" name="csrf_token"
                   value="<?= escapeHtml(csrfToken()) ?>">

            <input type="hidden" name="MAX_FILE_SIZE"
                   value="2097152">

            <p>

                <label for="food_name">Food Name</label><br>

                <input
                    type="text"
                    id="food_name"
                    name="food_name"
                    maxlength="150"
                    value="<?= escapeHtml($foodName) ?>"
                    required
                >

            </p>

            <br>

            <p>

                <label for="category_id">Category</label><br>

                <select id="category_id" name="category_id" required>

                    <option value="">Select Category</option>

                    <?php foreach ($categories as $category): ?>

                        <option
                            value="<?= (int) $category['category_id'] ?>"
                            <?= (string) $categoryId ===
                                (string) $category['category_id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= escapeHtml($category['category_name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

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

            <p>

                <label for="price">Price (ETB)</label><br>

                <input
                    type="number"
                    id="price"
                    name="price"
                    min="0.01"
                    max="99999999.99"
                    step="0.01"
                    value="<?= escapeHtml($price) ?>"
                    required
                >

            </p>

            <br>

            <p>

                <label for="image">
                    Food Image (JPG, PNG or WEBP; max 2 MB)
                </label><br>

                <input
                    type="file"
                    id="image"
                    name="image"
                    accept=".jpg,.jpeg,.png,.webp"
                >

            </p>

            <br>

            <p>

                <label>

                    <input
                        type="checkbox"
                        name="is_available"
                        <?= $isAvailable ? 'checked' : '' ?>
                    >

                    Available for ordering

                </label>

            </p>

            <br>

            <button type="submit">
                Save Food
            </button>

        </form>

    </section>

</main>

</body>

</html>
