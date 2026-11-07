
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$error = '';
$success = false;

$username = '';
$fullName = '';

$setupKeyFile = __DIR__ . '/../config/admin-setup.local.php';
$setupKey = null;
$adminExists = false;
$setupAvailable = false;

// Load the private setup key.
if (is_file($setupKeyFile)) {
    $loadedKey = require $setupKeyFile;

    if (is_string($loadedKey) && strlen($loadedKey) >= 32) {
        $setupKey = $loadedKey;
        $setupAvailable = true;
    }
}

// Redirect an authenticated administrator.
if (
    !empty($_SESSION['user_id']) &&
    ($_SESSION['role'] ?? '') === 'admin'
) {
    header('Location: dashboard.php');
    exit;
}

// Check whether an administrator already exists.
try {
    $adminStmt = $pdo->query("
        SELECT user_id
        FROM users
        WHERE role = 'admin'
        LIMIT 1
    ");

    $adminExists = (bool) $adminStmt->fetch();

} catch (Throwable $e) {
    error_log('[ZEST ADMIN SETUP ERROR] ' . $e->getMessage());

    $error = 'Unable to check administrator setup. Please try again later.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$adminExists) {

    if (!$setupAvailable) {

        $error = 'Administrator setup is not configured.';

    } else {

        // Verify CSRF token.
        $csrfInput = $_POST['csrf_token'] ?? '';
        $csrfInput = is_string($csrfInput) ? $csrfInput : '';

        if (!verifyCsrfToken($csrfInput)) {

            $error = 'Invalid request. Please refresh the page.';

        } else {

            // Read submitted values safely.
            $usernameInput = $_POST['username'] ?? '';
            $fullNameInput = $_POST['full_name'] ?? '';
            $passwordInput = $_POST['password'] ?? '';
            $confirmInput = $_POST['confirm_password'] ?? '';
            $keyInput = $_POST['setup_key'] ?? '';

            $username = is_string($usernameInput)
                ? trim($usernameInput)
                : '';

            $fullName = is_string($fullNameInput)
                ? trim($fullNameInput)
                : '';

            $password = is_string($passwordInput)
                ? $passwordInput
                : '';

            $confirmPassword = is_string($confirmInput)
                ? $confirmInput
                : '';

            $submittedKey = is_string($keyInput)
                ? $keyInput
                : '';

            // Validate the private setup key.
            if (
                $submittedKey === '' ||
                !hash_equals($setupKey, $submittedKey)
            ) {

                $error = 'Invalid setup key.';

            } elseif (
                !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)
            ) {

                $error = 'Username must contain 3â€“50 letters, numbers, dots, underscores, or hyphens.';

            } elseif (
                $fullName === '' ||
                strlen($fullName) > 100
            ) {

                $error = 'Please enter a valid full name.';

            } elseif (
                strlen($password) < 12 ||
                strlen($password) > 4096
            ) {

                $error = 'Password must be at least 12 characters long.';

            } elseif ($password !== $confirmPassword) {

                $error = 'The passwords do not match.';

            } else {

                $lockAcquired = false;

                try {

                    // Prevent simultaneous first-admin creation.
                    $lockStmt = $pdo->prepare("
                        SELECT GET_LOCK(?, 10)
                    ");

                    $lockStmt->execute([
                        'zest_first_admin_setup'
                    ]);

                    $lockAcquired = ((int) $lockStmt->fetchColumn() === 1);

                    if (!$lockAcquired) {
                        throw new RuntimeException(
                            'Unable to acquire administrator setup lock.'
                        );
                    }

                    // Recheck administrator existence while holding the lock.
                    $checkAdmin = $pdo->query("
                        SELECT user_id
                        FROM users
                        WHERE role = 'admin'
                        LIMIT 1
                    ");

                    if ($checkAdmin->fetch()) {

                        $adminExists = true;

                        $error = 'An administrator account already exists. Setup is closed.';

                    } else {

                        // Check username availability.
                        $checkUsername = $pdo->prepare("
                            SELECT user_id
                            FROM users
                            WHERE username = ?
                            LIMIT 1
                        ");

                        $checkUsername->execute([$username]);

                        if ($checkUsername->fetch()) {

                            $error = 'This username is already in use.';

                        } else {

                            // Hash the password securely.
                            $passwordHash = password_hash(
                                $password,
                                PASSWORD_DEFAULT
                            );

                            if ($passwordHash === false) {
                                throw new RuntimeException(
                                    'Password hashing failed.'
                                );
                            }

                            $pdo->beginTransaction();

                            $insertAdmin = $pdo->prepare("
                                INSERT INTO users (
                                    username,
                                    password,
                                    full_name,
                                    role
                                )
                                VALUES (?, ?, ?, 'admin')
                            ");

                            $insertAdmin->execute([
                                $username,
                                $passwordHash,
                                $fullName
                            ]);

                            $pdo->commit();

                            $adminExists = true;
                            $success = true;
                        }
                    }

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log(
                        '[ZEST ADMIN CREATION ERROR] ' . $e->getMessage()
                    );

                    $error = 'Unable to create the administrator account. Please try again later.';

                } finally {

                    if ($lockAcquired) {
                        try {
                            $releaseLock = $pdo->prepare("
                                SELECT RELEASE_LOCK(?)
                            ");

                            $releaseLock->execute([
                                'zest_first_admin_setup'
                            ]);

                        } catch (Throwable $e) {
                            error_log(
                                '[ZEST ADMIN LOCK ERROR] ' . $e->getMessage()
                            );
                        }
                    }
                }
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

    <title>Administrator Setup | Zest Restaurant</title>

    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body class="login-page">

    <main class="login-container">

        <div class="login-card">

            <div class="login-logo">
                <h1>ZEST</h1>
                <p>RESTAURANT</p>
            </div>

            <h2 class="login-title">
                First Administrator Setup
            </h2>

            <p class="login-subtitle">
                Create the administrator account for your restaurant.
            </p>

            <?php if ($success): ?>

                <div class="login-success" role="status">
                    Administrator account created successfully.
                    You can now sign in.
                </div>

                <p>
                    <a href="login.php">Go to Administrator Login</a>
                </p>

            <?php elseif ($adminExists): ?>

                <div class="login-error" role="alert">
                    An administrator account already exists.
                    Administrator setup is closed.
                </div>

                <p>
                    <a href="login.php">Go to Administrator Login</a>
                </p>

            <?php elseif (!$setupAvailable): ?>

                <div class="login-error" role="alert">
                    Administrator setup is not configured.
                    Please configure the private setup key first.
                </div>

            <?php else: ?>

                <?php if ($error !== ''): ?>

                    <div class="login-error" role="alert">
                        <?= htmlspecialchars(
                            $error,
                            ENT_QUOTES | ENT_SUBSTITUTE,
                            'UTF-8'
                        ) ?>
                    </div>

                <?php endif; ?>

                <form method="POST" class="login-form">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            csrfToken(),
                            ENT_QUOTES | ENT_SUBSTITUTE,
                            'UTF-8'
                        ) ?>"
                    >

                    <div>
                        <label for="full_name">Full Name</label>

                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            class="login-input"
                            value="<?= htmlspecialchars(
                                $fullName,
                                ENT_QUOTES | ENT_SUBSTITUTE,
                                'UTF-8'
                            ) ?>"
                            maxlength="100"
                            autocomplete="name"
                            required
                        >
                    </div>

                    <div>
                        <label for="username">Username</label>

                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="login-input"
                            value="<?= htmlspecialchars(
                                $username,
                                ENT_QUOTES | ENT_SUBSTITUTE,
                                'UTF-8'
                            ) ?>"
                            minlength="3"
                            maxlength="50"
                            autocomplete="username"
                            required
                        >
                    </div>

                    <div>
                        <label for="password">Password</label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="login-input"
                            minlength="12"
                            maxlength="4096"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div>
                        <label for="confirm_password">
                            Confirm Password
                        </label>

                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            class="login-input"
                            minlength="12"
                            maxlength="4096"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div>
                        <label for="setup_key">Private Setup Key</label>

                        <input
                            type="password"
                            id="setup_key"
                            name="setup_key"
                            class="login-input"
                            maxlength="128"
                            autocomplete="off"
                            required
                        >
                    </div>

                    <button type="submit" class="login-button">
                        Create Administrator
                    </button>

                </form>

            <?php endif; ?>

            <p class="login-footer">
                &copy; <?= date('Y') ?> Zest Restaurant.
                All rights reserved.
            </p>

        </div>

    </main>

</body>
</html>
