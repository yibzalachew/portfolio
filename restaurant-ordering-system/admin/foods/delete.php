
<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

requireAdmin();
requirePost();
verifyAdminCsrf();

// Safely validate submitted food ID.
$idInput = $_POST['id'] ?? '';

$id = is_string($idInput)
    ? filter_var($idInput, FILTER_VALIDATE_INT)
    : false;

if ($id === false || $id === null || $id <= 0) {
    redirectTo('index.php');
}

$imageName = null;

try {

    $pdo->beginTransaction();

    // Lock food record while checking and deleting.
    $stmt = $pdo->prepare("
        SELECT food_id, image
        FROM foods
        WHERE food_id = ?
        FOR UPDATE
    ");

    $stmt->execute([$id]);

    $food = $stmt->fetch();

    if (!$food) {

        $pdo->rollBack();

        $_SESSION['food_message'] = 'Food item not found.';

        redirectTo('index.php');
    }

    // Check whether this food appears in previous orders.
    $check = $pdo->prepare("
        SELECT COUNT(*)
        FROM order_items
        WHERE food_id = ?
    ");

    $check->execute([$id]);

    if ((int) $check->fetchColumn() > 0) {

        $pdo->rollBack();

        $_SESSION['food_message'] =
            'This food appears in previous orders. Mark it unavailable instead of deleting it.';

        redirectTo('index.php');
    }

    // Remember image filename for cleanup after database deletion.
    $imageName = !empty($food['image'])
        ? basename($food['image'])
        : null;

    // Delete food record.
    $delete = $pdo->prepare("
        DELETE FROM foods
        WHERE food_id = ?
    ");

    $delete->execute([$id]);

    $pdo->commit();

    // Delete image only after database deletion succeeds.
    if ($imageName !== null) {

        $uploadDirectory = __DIR__ . '/../../uploads/foods/';

        $imagePath = $uploadDirectory . $imageName;

        if (is_file($imagePath)) {

            if (!unlink($imagePath)) {

                error_log(
                    '[ZEST FOOD IMAGE WARNING] Could not delete image: '
                    . $imageName
                );
            }
        }
    }

    $_SESSION['food_message'] =
        'Food item deleted successfully.';

} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[ZEST FOOD DELETE ERROR] ' . $e->getMessage());

    $_SESSION['food_message'] =
        'Could not delete this food item.';

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[ZEST FOOD DELETE ERROR] ' . $e->getMessage());

    $_SESSION['food_message'] =
        'An unexpected error occurred while deleting the food item.';
}

redirectTo('index.php');
