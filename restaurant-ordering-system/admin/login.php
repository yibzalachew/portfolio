
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$error = '';
$username = '';

$maxAttempts = 5;
$windowSeconds = 900; // 15 minutes

// Redirect already authenticated administrators.
if (
    !empty($_SESSION['user_id']) &&
    ($_SESSION['role'] ?? '') === 'admin'
) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF token.
    $csrfInput = $_POST['csrf_token'] ?? '';
    $csrfInput = is_string($csrfInput) ? $csrfInput : '';

    if (!verifyCsrfToken($csrfInput)) {

        $error = 'Invalid request. Please refresh the page.';

    } else {

        // Safely read submitted values.
        $usernameInput = $_POST['username'] ?? '';
        $passwordInput = $_POST['password'] ?? '';

        $username = is_string($usernameInput)
            ? trim($usernameInput)
            : '';

        $password = is_string($passwordInput)
            ? $passwordInput
            : '';

        if ($username === '' || $password === '') {

            $error = 'Please enter your username and password.';

        } elseif (
            strlen($username) > 100 ||
            strlen($password) > 4096
        ) {

            $error = 'Invalid username or password.';

        } else {

            // Create a tracking key from username and client IP.
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            $attemptKey = hash(
                'sha256',
                strtolower($username) . '|' . $ipAddress
            );

            try {

                $pdo->beginTransaction();

                // Create an attempt record if one does not exist.
                $insertAttempt = $pdo->prepare("
                    INSERT IGNORE INTO login_attempts (
                        attempt_key,
                        failed_attempts,
                        window_started_at
                    )
                    VALUES (?, 0, UTC_TIMESTAMP())
                ");

                $insertAttempt->execute([$attemptKey]);

                // Lock the attempt record while processing login.
                $attemptStmt = $pdo->prepare("
                    SELECT
                        failed_attempts,
                        window_started_at,
                        blocked_until
                    FROM login_attempts
                    WHERE attempt_key = ?
                    FOR UPDATE
                ");

                $attemptStmt->execute([$attemptKey]);

                $attempt = $attemptStmt->fetch();

                if (!$attempt) {
                    throw new RuntimeException(
                        'Unable to retrieve login attempt record.'
                    );
                }

                $now = time();

                $windowStarted = strtotime(
                    $attempt['window_started_at'] . ' UTC'
                );

                $blockedUntil = !empty($attempt['blocked_until'])
                    ? strtotime($attempt['blocked_until'] . ' UTC')
                    : false;

                $failedAttempts = (int) $attempt['failed_attempts'];

                // Reset the counter if the 15-minute window expired.
                if (
                    $windowStarted === false ||
                    ($now - $windowStarted) >= $windowSeconds
                ) {

                    $failedAttempts = 0;
                    $blockedUntil = false;

                    $resetStmt = $pdo->prepare("
                        UPDATE login_attempts
                        SET
                            failed_attempts = 0,
                            window_started_at = UTC_TIMESTAMP(),
                            blocked_until = NULL
                        WHERE attempt_key = ?
                    ");

                    $resetStmt->execute([$attemptKey]);
                }

                // Check whether this username/IP is currently blocked.
                if (
                    $blockedUntil !== false &&
                    $blockedUntil > $now
                ) {

                    $pdo->commit();

                    $error = 'Too many failed login attempts. Please try again in 15 minutes.';

                } else {

                    // Look up the administrator.
                    $stmt = $pdo->prepare("
                        SELECT
                            user_id,
                            username,
                            password,
                            full_name,
                            role
                        FROM users
                        WHERE username = ?
                        LIMIT 1
                    ");

                    $stmt->execute([$username]);

                    $user = $stmt->fetch();

                    // Verify administrator credentials.
                    if (
                        $user &&
                        $user['role'] === 'admin' &&
                        password_verify($password, $user['password'])
                    ) {

                        // Remove failed attempts after successful login.
                        $deleteAttempts = $pdo->prepare("
                            DELETE FROM login_attempts
                            WHERE attempt_key = ?
                        ");

                        $deleteAttempts->execute([$attemptKey]);

                        $pdo->commit();

                        // Prevent session fixation.
                        session_regenerate_id(true);

                        // Generate a new CSRF token.
                        $_SESSION['csrf_token'] = bin2hex(
                            random_bytes(32)
                        );

                        // Store administrator information.
                        $_SESSION['user_id'] = (int) $user['user_id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['role'] = $user['role'];

                        // Initialize session inactivity timeout.
                        $_SESSION['last_activity'] = time();

                        header('Location: dashboard.php');
                        exit;

                    } else {

                        // Increase failed attempt counter.
                        $failedAttempts++;

                        $newBlockedUntil = null;

                        if ($failedAttempts >= $maxAttempts) {
                            $newBlockedUntil = gmdate(
                                'Y-m-d H:i:s',
                                time() + $windowSeconds
                            );
                        }

                        $updateAttempts = $pdo->prepare("
                            UPDATE login_attempts
                            SET
                                failed_attempts = ?,
                                blocked_until = ?
                            WHERE attempt_key = ?
                        ");

                        $updateAttempts->execute([
                            $failedAttempts,
                            $newBlockedUntil,
                            $attemptKey
                        ]);

                        $pdo->commit();

                        if ($failedAttempts >= $maxAttempts) {

                            $error = 'Too many failed login attempts. Please try again in 15 minutes.';

                        } else {

                            $remaining = $maxAttempts - $failedAttempts;

                            $error = 'Invalid username or password. Attempts remaining: '
                                . $remaining;
                        }
                    }
                }

            } catch (PDOException $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('[ZEST LOGIN DATABASE ERROR] ' . $e->getMessage());

                $error = 'A database error occurred. Please try again later.';

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('[ZEST LOGIN ERROR] ' . $e->getMessage());

                $error = 'Unable to process your login. Please try again later.';
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

    <title>Admin Login | Zest Restaurant</title>

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
                Administrator Login
            </h2>

            <p class="login-subtitle">
                Sign in to manage your restaurant
            </p>

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

                    <label for="username">Username</label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="login-input"
                        placeholder="Enter your username"
                        value="<?= htmlspecialchars(
                            $username,
                            ENT_QUOTES | ENT_SUBSTITUTE,
                            'UTF-8'
                        ) ?>"
                        autocomplete="username"
                        maxlength="100"
                        required
                        autofocus
                    >

                </div>

                <div>

                    <label for="password">Password</label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="login-input"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        maxlength="4096"
                        required
                    >

                </div>

                <button type="submit" class="login-button">
                    Sign In
                </button>

            </form>

            <p class="login-footer">
                &copy; <?= date('Y') ?> Zest Restaurant.
                All rights reserved.
            </p>

        </div>

    </main>

</body>

</html>
