
<?php

require_once __DIR__ . '/../includes/auth.php';

// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

// Verify CSRF token.
$csrfInput = $_POST['csrf_token'] ?? '';

$csrfInput = is_string($csrfInput) ? $csrfInput : '';

if (!verifyCsrfToken($csrfInput)) {
    http_response_code(403);
    exit('Invalid request.');
}

// Clear all session data.
$_SESSION = [];

// Delete the session cookie.
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

// Destroy the session.
session_destroy();

// Redirect to login.
header('Location: login.php');
exit;
