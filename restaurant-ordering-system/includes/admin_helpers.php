
<?php

require_once __DIR__ . '/auth.php';

/**
 * Allow POST requests only.
 */
function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {

        header('Allow: POST');

        http_response_code(405);

        exit('Method not allowed.');
    }
}

/**
 * Verify the administrator CSRF token.
 */
function verifyAdminCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !verifyCsrfToken($token)) {

        http_response_code(403);

        exit('Invalid security token. Please refresh the page and try again.');
    }
}

/**
 * Escape output before displaying it in HTML.
 */
function escapeHtml($value): string
{
    if (!is_scalar($value) && $value !== null) {
        return '';
    }

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Redirect to a local application path.
 */
function redirectTo(string $location): never
{
    // Prevent empty or external redirect destinations.
    if (
        $location === '' ||
        preg_match('/[\r\n]/', $location) ||
        preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $location)
    ) {
        http_response_code(400);
        exit('Invalid redirect destination.');
    }

    header('Location: ' . $location);

    exit;
}
