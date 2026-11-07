
<?php
session_start();

// Redirect logged-in users to the dashboard.
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Get login error message.
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Construction Material System - Login</title>

    <!-- Shared CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

    <main class="login-container">

        <h1>Construction Material System</h1>

        <p>
            Sign in to manage construction materials,
            allocations and wastage.
        </p>

        <?php if ($error === 'invalid'): ?>

            <div class="alert alert-error">
                Invalid username or password.
            </div>

        <?php elseif ($error === 'required'): ?>

            <div class="alert alert-error">
                Please enter your username and password.
            </div>

        <?php endif; ?>

        <form action="login.php" method="POST">

            <div class="form-group">
                <label for="username">Username</label>

                <input
                    id="username"
                    name="username"
                    type="text"
                    required
                    maxlength="50"
                    autocomplete="username"
                    placeholder="Enter your username"
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>

                <input
                    id="password"
                    name="password"
                    type="password"
                    required
                    autocomplete="current-password"
                    placeholder="Enter your password"
                >
            </div>

            <button type="submit" class="btn">
                Sign In
            </button>

        </form>

        <div class="footer">
            Construction Site Material Allocation
            and Wastage Tracking System
        </div>

    </main>

</body>
</html>
