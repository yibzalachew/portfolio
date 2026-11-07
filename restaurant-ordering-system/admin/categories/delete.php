
<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

requireAdmin();
requirePost();
verifyAdminCsrf();

// Safely validate category ID.
$idInput = $_POST['id'] ?? '';

$id = is_string($idInput)
    ? filter_var($idInput, FILTER_VALIDATE_INT)
    : false;

if ($id === false || $id === null || $id <= 0) {
    redirectTo('index.php');
}

try {

    $pdo->beginTransaction();

    // Lock category before checking its food items.
    $categoryStmt = $pdo->prepare("
        SELECT category_id
        FROM categories
        WHERE category_id = ?
        FOR UPDATE
    ");

    $categoryStmt->execute([$id]);

    $category = $categoryStmt->fetch();

    if (!$category) {

        $pdo->rollBack();

        $_SESSION['category_message'] = 'Category not found.';

        redirectTo('index.php');
    }

    // Prevent deletion if foods still belong to this category.
    $check = $pdo->prepare("
        SELECT COUNT(*)
        FROM foods
        WHERE category_id = ?
    ");

    $check->execute([$id]);

    if ((int) $check->fetchColumn() > 0) {

        $pdo->rollBack();

        $_SESSION['category_message'] =
            'This category contains food items. Reassign or remove those foods first.';

        redirectTo('index.php');
    }

    // Delete category.
    $delete = $pdo->prepare("
        DELETE FROM categories
        WHERE category_id = ?
    ");

    $delete->execute([$id]);

    $pdo->commit();

    $_SESSION['category_message'] =
        'Category deleted successfully.';

} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[ZEST CATEGORY DELETE ERROR] ' . $e->getMessage());

    $_SESSION['category_message'] =
        'Could not delete this category.';

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[ZEST CATEGORY DELETE ERROR] ' . $e->getMessage());

    $_SESSION['category_message'] =
        'An unexpected error occurred while deleting the category.';
}

redirectTo('index.php');
