
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_helpers.php';

requireAdmin();

$pageTitle = 'Restaurant Settings';

$success = '';
$error = '';

$allowedSettings = [
    'restaurant_name',
    'restaurant_location',
    'restaurant_phone',
    'restaurant_email',
    'delivery_fee',
    'minimum_order_amount',
    'opening_hours'
];

// Load existing restaurant settings.
$settingsStmt = $pdo->query("
    SELECT setting_key, setting_value
    FROM restaurant_settings
");

$settings = [];

foreach ($settingsStmt->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Load payment methods.
$paymentStmt = $pdo->query("
    SELECT *
    FROM payment_methods
    ORDER BY payment_method_id ASC
");

$paymentMethods = $paymentStmt->fetchAll();

// Default form values.
$formSettings = $settings;
$formPaymentMethods = $paymentMethods;

// Default values for missing settings.
$defaults = [
    'restaurant_name' => 'Zest Restaurant',
    'restaurant_location' => 'Hawassa',
    'restaurant_phone' => '',
    'restaurant_email' => '',
    'delivery_fee' => '0.00',
    'minimum_order_amount' => '0.00',
    'opening_hours' => ''
];

foreach ($defaults as $key => $value) {
    if (!isset($formSettings[$key])) {
        $formSettings[$key] = $value;
    }
}

// Process form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrfInput = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfInput) ||
        !verifyCsrfToken($csrfInput)
    ) {

        $error = 'Invalid request. Please refresh the page.';

    } else {

        $restaurantSettings = [];

        // Safely read restaurant settings.
        foreach ($allowedSettings as $key) {

            $value = $_POST[$key] ?? '';

            if (!is_string($value)) {

                $error = 'Invalid restaurant settings submitted.';
                break;

            }

            $restaurantSettings[$key] = trim($value);
            $formSettings[$key] = trim($value);
        }

        // Validate text fields.
        if ($error === '') {

            if (
                $restaurantSettings['restaurant_name'] === '' ||
                strlen($restaurantSettings['restaurant_name']) > 150
            ) {

                $error = 'Restaurant name is required and must not exceed 150 characters.';

            } elseif (
                $restaurantSettings['restaurant_location'] === '' ||
                strlen($restaurantSettings['restaurant_location']) > 150
            ) {

                $error = 'Restaurant location is required and must not exceed 150 characters.';

            } elseif (
                strlen($restaurantSettings['restaurant_phone']) > 30
            ) {

                $error = 'Restaurant phone number is too long.';

            } elseif (
                strlen($restaurantSettings['restaurant_email']) > 254
            ) {

                $error = 'Restaurant email address is too long.';

            } elseif (
                strlen($restaurantSettings['opening_hours']) > 255
            ) {

                $error = 'Opening hours must not exceed 255 characters.';

            } elseif (
                $restaurantSettings['restaurant_email'] !== '' &&
                !filter_var(
                    $restaurantSettings['restaurant_email'],
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                $error = 'Please enter a valid restaurant email address.';

            }
        }

        // Validate monetary values.
        $moneyPattern = '/^\d{1,8}(?:\.\d{1,2})?$/';

        if ($error === '') {

            foreach ([
                'delivery_fee',
                'minimum_order_amount'
            ] as $moneyField) {

                if ($restaurantSettings[$moneyField] === '') {
                    $restaurantSettings[$moneyField] = '0';
                    $formSettings[$moneyField] = '0';
                }

                if (
                    !preg_match(
                        $moneyPattern,
                        $restaurantSettings[$moneyField]
                    )
                ) {

                    $error = 'Delivery fee and minimum order must be valid non-negative amounts with up to two decimal places.';

                    break;
                }
            }
        }

        // Read and validate payment method information.
        $paymentUpdates = [];

        if ($error === '') {

            $accountNames = $_POST['account_name'] ?? [];
            $accountNumbers = $_POST['account_number'] ?? [];
            $instructionsList = $_POST['payment_instructions'] ?? [];
            $activeMethods = $_POST['is_active'] ?? [];

            if (
                !is_array($accountNames) ||
                !is_array($accountNumbers) ||
                !is_array($instructionsList) ||
                !is_array($activeMethods)
            ) {

                $error = 'Invalid payment method information submitted.';

            } else {

                foreach ($paymentMethods as $method) {

                    $id = (int) $method['payment_method_id'];

                    $accountName = $accountNames[$id] ?? '';
                    $accountNumber = $accountNumbers[$id] ?? '';
                    $instructions = $instructionsList[$id] ?? '';

                    if (
                        !is_string($accountName) ||
                        !is_string($accountNumber) ||
                        !is_string($instructions)
                    ) {

                        $error = 'Invalid payment account information.';
                        break;
                    }

                    $accountName = trim($accountName);
                    $accountNumber = trim($accountNumber);
                    $instructions = trim($instructions);

                    if (
                        strlen($accountName) > 150 ||
                        strlen($accountNumber) > 100 ||
                        strlen($instructions) > 2000
                    ) {

                        $error = 'Payment account information exceeds the allowed length.';
                        break;
                    }

                    $activeValue = $activeMethods[$id] ?? null;

                    $isActive = (
                        is_string($activeValue) &&
                        $activeValue === '1'
                    ) ? 1 : 0;

                    $paymentUpdates[] = [
                        'id' => $id,
                        'account_name' => $accountName,
                        'account_number' => $accountNumber,
                        'payment_instructions' => $instructions,
                        'is_active' => $isActive
                    ];

                    // Preserve submitted values if validation fails.
                    $formPaymentMethods[
                        array_search(
                            $id,
                            array_column(
                                $formPaymentMethods,
                                'payment_method_id'
                            )
                        )
                    ]['account_name'] = $accountName;

                    $formPaymentMethods[
                        array_search(
                            $id,
                            array_column(
                                $formPaymentMethods,
                                'payment_method_id'
                            )
                        )
                    ]['account_number'] = $accountNumber;

                    $formPaymentMethods[
                        array_search(
                            $id,
                            array_column(
                                $formPaymentMethods,
                                'payment_method_id'
                            )
                        )
                    ]['payment_instructions'] = $instructions;

                    $formPaymentMethods[
                        array_search(
                            $id,
                            array_column(
                                $formPaymentMethods,
                                'payment_method_id'
                            )
                        )
                    ]['is_active'] = $isActive;
                }
            }
        }

        // Save everything in one transaction.
        if ($error === '') {

            try {

                $pdo->beginTransaction();

                // Save restaurant settings.
                $settingStmt = $pdo->prepare("
                    INSERT INTO restaurant_settings (
                        setting_key,
                        setting_value
                    )
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE
                        setting_value = VALUES(setting_value)
                ");

                foreach ($restaurantSettings as $key => $value) {

                    $settingStmt->execute([
                        $key,
                        $value
                    ]);
                }

                // Update payment methods.
                $paymentUpdate = $pdo->prepare("
                    UPDATE payment_methods
                    SET
                        account_name = ?,
                        account_number = ?,
                        payment_instructions = ?,
                        is_active = ?
                    WHERE payment_method_id = ?
                ");

                foreach ($paymentUpdates as $payment) {

                    $paymentUpdate->execute([
                        $payment['account_name'] ?: null,
                        $payment['account_number'] ?: null,
                        $payment['payment_instructions'] ?: null,
                        $payment['is_active'],
                        $payment['id']
                    ]);
                }

                $pdo->commit();

                $success = 'Restaurant settings and payment details saved successfully.';

                // Refresh restaurant settings.
                $settings = array_merge(
                    $settings,
                    $restaurantSettings
                );

                $formSettings = array_merge(
                    $defaults,
                    $settings
                );

                // Refresh payment methods.
                $paymentStmt = $pdo->query("
                    SELECT *
                    FROM payment_methods
                    ORDER BY payment_method_id ASC
                ");

                $paymentMethods = $paymentStmt->fetchAll();
                $formPaymentMethods = $paymentMethods;

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    '[ZEST SETTINGS ERROR] ' . $e->getMessage()
                );

                $error = 'Unable to save settings. Please try again.';
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

    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> - Zest Restaurant</title>

    <link rel="stylesheet" href="../assets/css/admin.css">

</head>

<body>

<div class="admin-layout">

    <aside class="sidebar">

        <div class="brand">
            <h2>ZEST</h2>
            <span>Restaurant Admin</span>
        </div>

        <nav class="sidebar-menu">

            <a href="dashboard.php">
                <span>âŒ‚</span> Dashboard
            </a>

            <a href="foods/index.php">
                <span>ðŸ”</span> Food Management
            </a>

            <a href="categories/index.php">
                <span>â–¦</span> Categories
            </a>

            <a href="orders/index.php">
                <span>ðŸ“¦</span> Orders
            </a>

            <a href="payments/index.php">
                <span>ðŸ’³</span> Payments
            </a>

            <a href="reports/index.php">
                <span>ðŸ“Š</span> Reports
            </a>

            <a href="settings.php" class="active">
                <span>âš™</span> Settings
            </a>

            <form method="POST" action="logout.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"
                >

                <button type="submit" class="logout-button">
                    <span>â†ª</span> Logout
                </button>

            </form>

        </nav>

    </aside>

    <main class="main-content">

        <header class="topbar">

            <div>
                <h2>Restaurant Settings</h2>
                <p>Manage your restaurant configuration</p>
            </div>

            <div class="admin-user">
                <span>
                    <?= htmlspecialchars(
                        $_SESSION['full_name'] ?? 'Administrator',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </span>
            </div>

        </header>

        <div class="settings-content">

            <div class="settings-heading">

                <div>
                    <h1>Settings</h1>

                    <p>
                        Manage restaurant information, delivery charges
                        and customer payment methods.
                    </p>
                </div>

            </div>

            <?php if ($success !== ''): ?>

                <div class="settings-alert success-alert">

                    <strong>âœ“ Success</strong>

                    <?= htmlspecialchars(
                        $success,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

            <?php endif; ?>

            <?php if ($error !== ''): ?>

                <div class="settings-alert error-alert" role="alert">

                    <strong>âœ• Error</strong>

                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

            <?php endif; ?>

            <form method="POST" action="settings.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"
                >

                <!-- Restaurant Information -->

                <section class="settings-card">

                    <div class="settings-card-heading">

                        <div class="settings-icon">ðŸª</div>

                        <div>
                            <h2>Restaurant Information</h2>
                            <p>Basic information about your restaurant.</p>
                        </div>

                    </div>

                    <div class="settings-form-grid">

                        <div class="settings-field">

                            <label for="restaurant_name">
                                Restaurant Name *
                            </label>

                            <input
                                type="text"
                                id="restaurant_name"
                                name="restaurant_name"
                                maxlength="150"
                                value="<?= htmlspecialchars($formSettings['restaurant_name'], ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >

                        </div>

                        <div class="settings-field">

                            <label for="restaurant_location">
                                Location *
                            </label>

                            <input
                                type="text"
                                id="restaurant_location"
                                name="restaurant_location"
                                maxlength="150"
                                value="<?= htmlspecialchars($formSettings['restaurant_location'], ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >

                        </div>

                        <div class="settings-field">

                            <label for="restaurant_phone">
                                Phone Number
                            </label>

                            <input
                                type="text"
                                id="restaurant_phone"
                                name="restaurant_phone"
                                maxlength="30"
                                value="<?= htmlspecialchars($formSettings['restaurant_phone'], ENT_QUOTES, 'UTF-8') ?>"
                            >

                        </div>

                        <div class="settings-field">

                            <label for="restaurant_email">
                                Email Address
                            </label>

                            <input
                                type="email"
                                id="restaurant_email"
                                name="restaurant_email"
                                maxlength="254"
                                value="<?= htmlspecialchars($formSettings['restaurant_email'], ENT_QUOTES, 'UTF-8') ?>"
                            >

                        </div>

                        <div class="settings-field full-width">

                            <label for="opening_hours">
                                Opening Hours
                            </label>

                            <input
                                type="text"
                                id="opening_hours"
                                name="opening_hours"
                                maxlength="255"
                                value="<?= htmlspecialchars($formSettings['opening_hours'], ENT_QUOTES, 'UTF-8') ?>"
                                placeholder="Monday - Sunday, 8:00 AM - 10:00 PM"
                            >

                        </div>

                    </div>

                </section>

                <!-- Delivery Settings -->

                <section class="settings-card">

                    <div class="settings-card-heading">

                        <div class="settings-icon">ðŸšš</div>

                        <div>
                            <h2>Order and Delivery</h2>
                            <p>Configure delivery charges and minimum orders.</p>
                        </div>

                    </div>

                    <div class="settings-form-grid">

                        <div class="settings-field">

                            <label for="delivery_fee">
                                Delivery Fee (ETB)
                            </label>

                            <input
                                type="number"
                                id="delivery_fee"
                                name="delivery_fee"
                                min="0"
                                max="99999999.99"
                                step="0.01"
                                value="<?= htmlspecialchars($formSettings['delivery_fee'], ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >

                            <small>Applied to delivery orders only.</small>

                        </div>

                        <div class="settings-field">

                            <label for="minimum_order_amount">
                                Minimum Order Amount (ETB)
                            </label>

                            <input
                                type="number"
                                id="minimum_order_amount"
                                name="minimum_order_amount"
                                min="0"
                                max="99999999.99"
                                step="0.01"
                                value="<?= htmlspecialchars($formSettings['minimum_order_amount'], ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >

                            <small>Minimum subtotal accepted for an order.</small>

                        </div>

                    </div>

                </section>

                <!-- Payment Methods -->

                <section class="settings-card">

                    <div class="settings-card-heading">

                        <div class="settings-icon">ðŸ’³</div>

                        <div>
                            <h2>Payment Methods</h2>
                            <p>Configure the accounts customers use to pay.</p>
                        </div>

                    </div>

                    <div class="payment-settings-grid">

                        <?php foreach ($formPaymentMethods as $method): ?>

                            <?php
                            $methodId = (int) $method['payment_method_id'];
                            ?>

                            <div class="payment-settings-card">

                                <div class="payment-card-heading">

                                    <div>

                                        <h3>
                                            <?= htmlspecialchars(
                                                $method['method_name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </h3>

                                        <span class="payment-description">
                                            Customer payment account
                                        </span>

                                    </div>

                                    <label class="payment-toggle">

                                        <input
                                            type="checkbox"
                                            name="is_active[<?= $methodId ?>]"
                                            value="1"
                                            <?= (int) $method['is_active'] === 1 ? 'checked' : '' ?>
                                        >

                                        <span>Enabled</span>

                                    </label>

                                </div>

                                <div class="settings-field">

                                    <label>
                                        Account Name
                                    </label>

                                    <input
                                        type="text"
                                        name="account_name[<?= $methodId ?>]"
                                        maxlength="150"
                                        value="<?= htmlspecialchars($method['account_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        placeholder="Account holder name"
                                    >

                                </div>

                                <div class="settings-field">

                                    <label>
                                        Account Number / Phone Number
                                    </label>

                                    <input
                                        type="text"
                                        name="account_number[<?= $methodId ?>]"
                                        maxlength="100"
                                        value="<?= htmlspecialchars($method['account_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        placeholder="Receiving account number"
                                    >

                                </div>

                                <div class="settings-field">

                                    <label>
                                        Payment Instructions
                                    </label>

                                    <textarea
                                        name="payment_instructions[<?= $methodId ?>]"
                                        rows="4"
                                        maxlength="2000"
                                        placeholder="Explain how customers should make payment."
                                    ><?= htmlspecialchars(
                                        $method['payment_instructions'] ?? '',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?></textarea>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <div class="payment-security-note">

                        <strong>Security reminder</strong>

                        Only enter receiving account details. Never enter
                        your banking PIN, password or OTP.

                    </div>

                </section>

                <!-- Save Settings -->

                <div class="settings-save-area">

                    <button
                        type="submit"
                        class="settings-save-button"
                    >
                        Save All Settings
                    </button>

                </div>

            </form>

        </div>

    </main>

</div>

</body>
</html>
