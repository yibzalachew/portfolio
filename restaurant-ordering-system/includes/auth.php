
<?php

// Configure sessions before starting them.
if (session_status() === PHP_SESSION_NONE) {

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    $isSecure = isset($_SERVER['HTTPS'])
        && strtolower((string) $_SERVER['HTTPS']) !== 'off'
        && $_SERVER['HTTPS'] !== '';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

/**
 * Require an authenticated administrator.
 */
function requireAdmin(): void
{
    $userId = $_SESSION['user_id'] ?? null;
    $role = $_SESSION['role'] ?? null;

    // Check administrator authentication.
    if (
        !is_numeric($userId) ||
        (int) $userId <= 0 ||
        !is_string($role) ||
        $role !== 'admin'
    ) {
        header('Location: /restaurant-ordering-system/admin/login.php');
        exit;
    }

    // Session inactivity timeout: 30 minutes.
    $timeout = 1800;

    $lastActivity = $_SESSION['last_activity'] ?? null;

    if (
        !is_numeric($lastActivity) ||
        (int) $lastActivity <= 0 ||
        (time() - (int) $lastActivity) > $timeout
    ) {
        destroyAuthSession();

        header(
            'Location: /restaurant-ordering-system/admin/login.php?expired=1'
        );

        exit;
    }

    // Update session activity.
    $_SESSION['last_activity'] = time();
}

/**
 * Destroy the current authentication session.
 */
function destroyAuthSession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax'
            ]
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Generate a CSRF token.
 */
function csrfToken(): string
{
    if (
        !isset($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token'])
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted CSRF token.
 *
 * Accept mixed input so malformed requests cannot cause a TypeError.
 */
function verifyCsrfToken($token): bool
{
    if (
        !is_string($token) ||
        !isset($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token'])
    ) {
        return false;
    }

    return hash_equals(
        $_SESSION['csrf_token'],
        $token
    );
}
