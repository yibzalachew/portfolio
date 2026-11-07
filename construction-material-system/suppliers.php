
<?php

require_once __DIR__ . '/includes/auth.php';
requireRole(['manager', 'storekeeper', 'engineer']);

require_once __DIR__ . '/includes/db.php';

// Safely escape output
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'success';
$editingSupplierId = 0;

// Default form values
$formData = [
    'supplier_name' => '',
    'phone' => '',
    'address' => ''
];

// Load supplier for editing
if (isset($_GET['edit']) && is_scalar($_GET['edit'])) {

    $editingSupplierId = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT
    ) ?: 0;

    if ($editingSupplierId > 0) {

        $editStmt = $pdo->prepare(
            "SELECT supplier_id, supplier_name, phone, address
             FROM suppliers
             WHERE supplier_id = ?"
        );

        $editStmt->execute([$editingSupplierId]);
        $editingSupplier = $editStmt->fetch();

        if ($editingSupplier) {

            $formData = [
                'supplier_name' => $editingSupplier['supplier_name'],
                'phone' => $editingSupplier['phone'] ?? '',
                'address' => $editingSupplier['address'] ?? ''
            ];

        } else {

            $editingSupplierId = 0;
            $message = 'The selected supplier could not be found.';
            $messageType = 'error';
        }
    }
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid request token. Refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    /*
     * DELETE SUPPLIER
     */
    if ($action === 'delete') {

        $idValue = $_POST['supplier_id'] ?? '';

        $deleteId = (
            is_scalar($idValue) &&
            filter_var($idValue, FILTER_VALIDATE_INT)
        ) ? (int) $idValue : 0;

        if ($deleteId <= 0) {

            $message = 'Invalid supplier selected.';
            $messageType = 'error';

        } else {

            try {

                $pdo->beginTransaction();

                // Lock supplier record
                $lockStmt = $pdo->prepare(
                    "SELECT supplier_id
                     FROM suppliers
                     WHERE supplier_id = ?
                     FOR UPDATE"
                );

                $lockStmt->execute([$deleteId]);

                if (!$lockStmt->fetch()) {
                    throw new RuntimeException('Supplier not found.');
                }

                // Check existing material delivery records
                $receiptStmt = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM material_receipts
                     WHERE supplier_id = ?"
                );

                $receiptStmt->execute([$deleteId]);

                $receiptCount = (int) $receiptStmt->fetchColumn();

                if ($receiptCount > 0) {

                    $pdo->rollBack();

                    $message = 'Cannot delete this supplier because it is connected to existing material delivery records.';
                    $messageType = 'error';

                } else {

                    $deleteStmt = $pdo->prepare(
                        "DELETE FROM suppliers
                         WHERE supplier_id = ?"
                    );

                    $deleteStmt->execute([$deleteId]);

                    if ($deleteStmt->rowCount() !== 1) {
                        throw new RuntimeException('Supplier could not be deleted.');
                    }

                    $pdo->commit();

                    header('Location: suppliers.php?deleted=1');
                    exit;
                }

            } catch (PDOException $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($exception->getCode() === '23000') {
                    $message = 'Cannot delete this supplier because it is connected to existing records.';
                } else {
                    error_log($exception->getMessage());
                    $message = 'Could not delete the supplier. Please try again.';
                }

                $messageType = 'error';

            } catch (RuntimeException $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = $exception->getMessage();
                $messageType = 'error';
            }
        }

    /*
     * ADD OR UPDATE SUPPLIER
     */
    } elseif (in_array($action, ['add', 'update'], true)) {

        $idValue = $_POST['supplier_id'] ?? '';

        $editingSupplierId = (
            is_scalar($idValue) &&
            filter_var($idValue, FILTER_VALIDATE_INT)
        ) ? (int) $idValue : 0;

        $nameValue = $_POST['supplier_name'] ?? '';
        $phoneValue = $_POST['phone'] ?? '';
        $addressValue = $_POST['address'] ?? '';

        $name = is_string($nameValue) ? trim($nameValue) : '';
        $phone = is_string($phoneValue) ? trim($phoneValue) : '';
        $address = is_string($addressValue) ? trim($addressValue) : '';

        $formData = [
            'supplier_name' => $name,
            'phone' => $phone,
            'address' => $address
        ];

        if (
            ($action === 'update' && $editingSupplierId <= 0) ||
            $name === '' ||
            strlen($name) > 150 ||
            strlen($phone) > 30 ||
            strlen($address) > 255
        ) {

            $message = 'Enter a valid supplier name, phone number and address.';
            $messageType = 'error';

        } else {

            try {

                if ($action === 'add') {

                    $stmt = $pdo->prepare(
                        "INSERT INTO suppliers
                        (supplier_name, phone, address)
                        VALUES (?, ?, ?)"
                    );

                    $stmt->execute([
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null
                    ]);

                    header('Location: suppliers.php?added=1');
                    exit;

                } else {

                    // Confirm supplier exists
                    $checkStmt = $pdo->prepare(
                        "SELECT supplier_id
                         FROM suppliers
                         WHERE supplier_id = ?"
                    );

                    $checkStmt->execute([$editingSupplierId]);

                    if (!$checkStmt->fetch()) {
                        throw new RuntimeException('Supplier not found.');
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE suppliers
                         SET supplier_name = ?,
                             phone = ?,
                             address = ?
                         WHERE supplier_id = ?"
                    );

                    $stmt->execute([
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                        $editingSupplierId
                    ]);

                    header('Location: suppliers.php?updated=1');
                    exit;
                }

            } catch (PDOException $exception) {

                error_log($exception->getMessage());

                $message = 'Could not save the supplier. Please try again.';
                $messageType = 'error';

            } catch (RuntimeException $exception) {

                $message = $exception->getMessage();
                $messageType = 'error';
                $editingSupplierId = 0;
            }
        }

    } else {

        $message = 'Invalid request.';
        $messageType = 'error';
    }
}

// Success messages
if (isset($_GET['added'])) {
    $message = 'Supplier added successfully.';
    $messageType = 'success';
}

if (isset($_GET['updated'])) {
    $message = 'Supplier updated successfully.';
    $messageType = 'success';
}

if (isset($_GET['deleted'])) {
    $message = 'Supplier deleted successfully.';
    $messageType = 'success';
}

// Retrieve suppliers
$stmt = $pdo->query(
    "SELECT supplier_id, supplier_name, phone, address
     FROM suppliers
     ORDER BY supplier_name"
);

$suppliers = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Suppliers | Construction Material Management System</title>

    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body class="suppliers-page">

<nav class="navbar">

    <div class="brand">
        <h2>Construction Material Management System</h2>
        <p>Material Allocation and Wastage Tracking</p>
    </div>

    <div class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="stock.php">Stock</a>
        <a href="materials.php">Materials</a>
        <a href="suppliers.php" class="active">Suppliers</a>
        <a href="deliveries.php">Deliveries</a>
        <a href="allocations.php">Allocations</a>
        <a href="usage.php">Usage</a>
        <a href="wastage.php">Wastage</a>
        <a href="reports.php">Reports</a>
        <a href="logout.php">Logout</a>
    </div>

</nav>

<main class="container suppliers-page-content">

    <section class="page-header">

        <h1>Supplier Management</h1>

        <p>
            Register, view, update, and delete construction material suppliers.
        </p>

    </section>

    <?php if ($message !== ''): ?>

        <div class="alert <?= $messageType === 'error' ? 'alert-error' : 'alert-success' ?>">
            <?= e($message) ?>
        </div>

    <?php endif; ?>

    <section class="card suppliers-form-card">

        <h2>
            <?= $editingSupplierId > 0 ? 'Edit Supplier' : 'Register New Supplier' ?>
        </h2>

        <p class="suppliers-description">
            <?= $editingSupplierId > 0
                ? 'Update the selected supplier information below.'
                : 'Enter the details of the supplier you want to register.' ?>
        </p>

        <form method="POST" action="suppliers.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="<?= $editingSupplierId > 0 ? 'update' : 'add' ?>"
            >

            <?php if ($editingSupplierId > 0): ?>

                <input
                    type="hidden"
                    name="supplier_id"
                    value="<?= e($editingSupplierId) ?>"
                >

            <?php endif; ?>

            <div class="form-grid">

                <div class="form-group">

                    <label for="supplier_name">
                        Supplier Name *
                    </label>

                    <input
                        type="text"
                        id="supplier_name"
                        name="supplier_name"
                        maxlength="150"
                        placeholder="Enter supplier name"
                        value="<?= e($formData['supplier_name']) ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label for="phone">
                        Phone Number
                    </label>

                    <input
                        type="text"
                        id="phone"
                        name="phone"
                        maxlength="30"
                        placeholder="Enter phone number"
                        value="<?= e($formData['phone']) ?>"
                    >

                </div>

                <div class="form-group">

                    <label for="address">
                        Address
                    </label>

                    <input
                        type="text"
                        id="address"
                        name="address"
                        maxlength="255"
                        placeholder="Enter supplier address"
                        value="<?= e($formData['address']) ?>"
                    >

                </div>

            </div>

            <div class="suppliers-form-actions">

                <button type="submit" class="btn">
                    <?= $editingSupplierId > 0 ? 'Update Supplier' : '+ Save Supplier' ?>
                </button>

                <?php if ($editingSupplierId > 0): ?>

                    <a href="suppliers.php" class="btn btn-secondary">
                        Cancel
                    </a>

                <?php endif; ?>

            </div>

        </form>

    </section>

    <section class="card suppliers-table-card">

        <div class="suppliers-table-heading">

            <div>
                <h2>Registered Suppliers</h2>

                <p>
                    Total registered suppliers:
                    <strong><?= count($suppliers) ?></strong>
                </p>
            </div>

        </div>

        <div class="table-container suppliers-table-container">

            <table class="suppliers-table">

                <thead>

                    <tr>
                        <th>ID</th>
                        <th>Supplier Name</th>
                        <th>Phone Number</th>
                        <th>Address</th>
                        <th>Action</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if ($suppliers): ?>

                        <?php foreach ($suppliers as $supplier): ?>

                            <tr>

                                <td>
                                    <?= e($supplier['supplier_id']) ?>
                                </td>

                                <td class="suppliers-name">
                                    <?= e($supplier['supplier_name']) ?>
                                </td>

                                <td>
                                    <?= e($supplier['phone'] ?? '-') ?>
                                </td>

                                <td>
                                    <?= e($supplier['address'] ?? '-') ?>
                                </td>

                                <td class="suppliers-action-cell">

                                    <a
                                        href="suppliers.php?edit=<?= e($supplier['supplier_id']) ?>"
                                        class="suppliers-edit-btn"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="suppliers.php"
                                        class="suppliers-delete-form"
                                        onsubmit="return confirm('Are you sure you want to delete this supplier? This action cannot be undone.');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($_SESSION['csrf_token']) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete"
                                        >

                                        <input
                                            type="hidden"
                                            name="supplier_id"
                                            value="<?= e($supplier['supplier_id']) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="suppliers-delete-btn"
                                        >
                                            Delete
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="5" class="suppliers-empty">
                                No suppliers registered yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <footer class="footer">
        Construction Site Material Allocation and Wastage Tracking System
    </footer>

</main>

</body>
</html>
