
<?php
require_once __DIR__ . '/includes/auth.php';

requireRole(['manager', 'storekeeper', 'engineer']);

$fullName = htmlspecialchars(
    $_SESSION['full_name'],
    ENT_QUOTES,
    'UTF-8'
);

$role = $_SESSION['role'];

$roleNames = [
    'manager' => 'Manager',
    'storekeeper' => 'Storekeeper',
    'engineer' => 'Site Engineer'
];

$roleName = $roleNames[$role] ?? 'User';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Construction Material System</title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

<header class="navbar">
    <div class="container">
        <div class="brand">
            <h2>Construction Site A</h2>
            <p>Material Allocation and Wastage Tracking System</p>
        </div>

        <div class="nav-links">
            <span>
                Welcome, <?= $fullName ?>
                (<?= htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') ?>)
            </span>

            <a href="logout.php" class="btn btn-danger">Log Out</a>
        </div>
    </div>
</header>

<main class="container">

    <section class="page-header">
        <h1>Dashboard</h1>
        <p>Welcome to the Construction Site Material Management System.</p>
    </section>

    <section class="dashboard-grid">

        <?php if (in_array($role, ['manager', 'storekeeper'], true)): ?>

            <a href="materials.php" class="dashboard-card">
                <h3>Manage Materials</h3>
                <p>Add and manage construction materials.</p>
            </a>

            <a href="suppliers.php" class="dashboard-card">
                <h3>Manage Suppliers</h3>
                <p>Register and manage material suppliers.</p>
            </a>

            <a href="deliveries.php" class="dashboard-card">
                <h3>Record Deliveries</h3>
                <p>Record materials received at the construction site.</p>
            </a>

            <a href="allocations.php" class="dashboard-card">
                <h3>Material Allocation</h3>
                <p>Allocate available materials to work areas.</p>
            </a>

        <?php endif; ?>

        <a href="stock.php" class="dashboard-card">
            <h3>View Stock</h3>
            <p>Check available materials and stock levels.</p>
        </a>

        <?php if (in_array($role, ['manager', 'engineer'], true)): ?>

            <a href="usage.php" class="dashboard-card">
                <h3>Material Usage</h3>
                <p>Record and monitor materials used.</p>
            </a>

            <a href="reports.php" class="dashboard-card">
                <h3>View Reports</h3>
                <p>Review material, usage and wastage reports.</p>
            </a>

        <?php endif; ?>

        <a href="wastage.php" class="dashboard-card">
            <h3>Wastage Tracking</h3>
            <p>Submit, monitor and review material wastage.</p>
        </a>

    </section>

    <?php if ($role === 'manager'): ?>

        <section class="card">
            <h2>Manager Functions</h2>
            <p>Oversee stock, allocations, wastage approvals and reports.</p>
            <p>User management will be added in a later step.</p>
        </section>

    <?php elseif ($role === 'storekeeper'): ?>

        <section class="card">
            <h2>Storekeeper Functions</h2>
            <p>Manage materials, suppliers, deliveries and allocations.</p>
        </section>

    <?php elseif ($role === 'engineer'): ?>

        <section class="card">
            <h2>Site Engineer Functions</h2>
            <p>Record material usage and submit wastage for approval.</p>
        </section>

    <?php endif; ?>

    <footer class="footer">
        Construction Site Material Allocation and Wastage Tracking System
    </footer>

</main>

</body>
</html>
