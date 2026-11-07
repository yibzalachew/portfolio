
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin(): void
{
    if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
        header('Location: index.php');
        exit;
    }
}

function requireRole(array $allowedRoles): void
{
    requireLogin();

    if (!in_array($_SESSION['role'], $allowedRoles, true)) {
        http_response_code(403);
        exit(
            '<h2>Access Denied</h2>' .
            '<p>You do not have permission to access this page.</p>' .
            '<p><a href="dashboard.php">Return to Dashboard</a></p>'
        );
    }
}
