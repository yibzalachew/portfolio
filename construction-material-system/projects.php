
<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['manager', 'storekeeper', 'engineer']);
require_once __DIR__ . '/includes/db.php';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$editing = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please refresh the page.';
    } else {

        $action = $_POST['action'] ?? '';

        // ADD OR UPDATE PROJECT
        if ($action === 'save') {

            $projectId = (int)($_POST['project_id'] ?? 0);
            $name = trim($_POST['project_name'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if ($name === '' || strlen($name) > 150) {
                $error = 'Project name is required and must not exceed 150 characters.';
            } elseif (strlen($location) > 200) {
                $error = 'Location must not exceed 200 characters.';
            } elseif (!in_array($status, ['active', 'completed', 'on_hold'], true)) {
                $error = 'Invalid project status.';
            } else {
                try {
                    // Prevent duplicate project names
                    $check = $pdo->prepare(
                        "SELECT project_id FROM projects
                         WHERE project_name = ? AND project_id != ?"
                    );
                    $check->execute([$name, $projectId]);

                    if ($check->fetch()) {
                        $error = 'A project with this name already exists.';
                    } else {
                        if ($projectId > 0) {
                            $stmt = $pdo->prepare(
                                "UPDATE projects
                                 SET project_name = ?, location = ?, status = ?
                                 WHERE project_id = ?"
                            );

                            $stmt->execute([
                                $name,
                                $location !== '' ? $location : null,
                                $status,
                                $projectId
                            ]);

                            header('Location: projects.php?updated=1');
                            exit;
                        } else {
                            $stmt = $pdo->prepare(
                                "INSERT INTO projects
                                 (project_name, location, status)
                                 VALUES (?, ?, ?)"
                            );

                            $stmt->execute([
                                $name,
                                $location !== '' ? $location : null,
                                $status
                            ]);

                            header('Location: projects.php?added=1');
                            exit;
                        }
                    }
                } catch (PDOException $ex) {
                    $error = 'Database error: ' . $ex->getMessage();
                }
            }
        }

        // DELETE PROJECT
        if ($action === 'delete') {

            $projectId = (int)($_POST['project_id'] ?? 0);

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "SELECT project_id FROM projects
                     WHERE project_id = ? FOR UPDATE"
                );
                $stmt->execute([$projectId]);

                if (!$stmt->fetch()) {
                    throw new RuntimeException('Project not found.');
                }

                // Check dependent records
                $dependencies = [
                    'material deliveries' => 'material_receipts',
                    'material allocations' => 'allocations',
                    'work areas' => 'work_areas'
                ];

                $usedIn = [];

                foreach ($dependencies as $description => $table) {
                    $check = $pdo->prepare(
                        "SELECT COUNT(*) FROM `$table` WHERE project_id = ?"
                    );
                    $check->execute([$projectId]);

                    if ((int)$check->fetchColumn() > 0) {
                        $usedIn[] = $description;
                    }
                }

                if (!empty($usedIn)) {
                    throw new RuntimeException(
                        'Cannot delete this project because it has existing ' .
                        implode(', ', $usedIn) . '.'
                    );
                }

                $delete = $pdo->prepare(
                    "DELETE FROM projects WHERE project_id = ?"
                );
                $delete->execute([$projectId]);

                $pdo->commit();

                header('Location: projects.php?deleted=1');
                exit;

            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $ex->getMessage();
            }
        }
    }
}

// Success messages
if (isset($_GET['added'])) {
    $message = 'Project added successfully.';
} elseif (isset($_GET['updated'])) {
    $message = 'Project updated successfully.';
} elseif (isset($_GET['deleted'])) {
    $message = 'Project deleted successfully.';
}

// Load project for editing
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    $stmt = $pdo->prepare(
        "SELECT * FROM projects WHERE project_id = ?"
    );
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();

    if (!$editing) {
        $error = 'Project not found.';
    }
}

// Get all projects
$stmt = $pdo->query(
    "SELECT * FROM projects ORDER BY project_id DESC"
);
$projects = $stmt->fetchAll();

// Project totals
$totalProjects = count($projects);
$activeProjects = 0;
$completedProjects = 0;
$onHoldProjects = 0;

foreach ($projects as $project) {
    if ($project['status'] === 'active') {
        $activeProjects++;
    } elseif ($project['status'] === 'completed') {
        $completedProjects++;
    } elseif ($project['status'] === 'on_hold') {
        $onHoldProjects++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projects Management</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

<div class="navbar">
    <div class="logo">Construction Material System</div>

    <div class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="projects.php" class="active">Projects</a>
        <a href="materials.php">Materials</a>
        <a href="suppliers.php">Suppliers</a>
        <a href="deliveries.php">Deliveries</a>
        <a href="allocations.php">Allocations</a>
        <a href="usage.php">Usage</a>
        <a href="wastage.php">Wastage</a>
        <a href="stock.php">Stock</a>
        <a href="reports.php">Reports</a>
        <a href="logout.php">Logout</a>
    </div>
</div>

<div class="container projects-page">

    <div class="page-header">
        <h1>Projects Management</h1>
        <p>Manage construction projects and their status.</p>
    </div>

    <?php if ($message): ?>
        <div class="alert success">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="project-summary">

        <div class="card">
            <h3>Total Projects</h3>
            <h2><?= $totalProjects ?></h2>
        </div>

        <div class="card">
            <h3>Active Projects</h3>
            <h2><?= $activeProjects ?></h2>
        </div>

        <div class="card">
            <h3>Completed Projects</h3>
            <h2><?= $completedProjects ?></h2>
        </div>

        <div class="card">
            <h3>On Hold</h3>
            <h2><?= $onHoldProjects ?></h2>
        </div>

    </div>

    <div class="panel project-form">

        <h2>
            <?= $editing ? 'Edit Project' : 'Add New Project' ?>
        </h2>

        <form method="POST" action="projects.php">

            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action" value="save">

            <input type="hidden" name="project_id"
                   value="<?= e($editing['project_id'] ?? '') ?>">

            <div class="project-form-grid">

                <div class="form-group">
                    <label>Project Name</label>
                    <input
                        type="text"
                        name="project_name"
                        maxlength="150"
                        required
                        value="<?= e($editing['project_name'] ?? '') ?>"
                        placeholder="Enter project name">
                </div>

                <div class="form-group">
                    <label>Location</label>
                    <input
                        type="text"
                        name="location"
                        maxlength="200"
                        value="<?= e($editing['location'] ?? '') ?>"
                        placeholder="Enter project location">
                </div>

                <div class="form-group">
                    <label>Project Status</label>
                    <select name="status" required>
                        <option value="active"
                            <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>
                            Active
                        </option>

                        <option value="completed"
                            <?= ($editing['status'] ?? '') === 'completed' ? 'selected' : '' ?>>
                            Completed
                        </option>

                        <option value="on_hold"
                            <?= ($editing['status'] ?? '') === 'on_hold' ? 'selected' : '' ?>>
                            On Hold
                        </option>
                    </select>
                </div>

            </div>

            <div class="project-form-actions">

                <button type="submit" class="btn-primary">
                    <?= $editing ? 'Update Project' : 'Add Project' ?>
                </button>

                <?php if ($editing): ?>
                    <a href="projects.php" class="btn-cancel">
                        Cancel Editing
                    </a>
                <?php endif; ?>

            </div>

        </form>
    </div>

    <div class="panel">

        <h2>Project History</h2>

        <div class="table-container">

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Project Name</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($projects)): ?>
                    <tr>
                        <td colspan="6">No projects registered yet.</td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($projects as $project): ?>

                        <tr>
                            <td><?= e($project['project_id']) ?></td>

                            <td><?= e($project['project_name']) ?></td>

                            <td><?= e($project['location'] ?? 'N/A') ?></td>

                            <td>
                                <span class="project-status <?= e($project['status']) ?>">
                                    <?= e(ucwords(str_replace('_', ' ', $project['status']))) ?>
                                </span>
                            </td>

                            <td><?= e($project['created_at']) ?></td>

                            <td class="project-actions">

                                <a
                                    href="projects.php?edit=<?= (int)$project['project_id'] ?>"
                                    class="project-edit">
                                    Edit
                                </a>

                                <form method="POST" action="projects.php"
                                      onsubmit="return confirm('Are you sure you want to delete this project?');">

                                    <input type="hidden" name="csrf_token"
                                           value="<?= e($_SESSION['csrf_token']) ?>">

                                    <input type="hidden" name="action"
                                           value="delete">

                                    <input type="hidden" name="project_id"
                                           value="<?= (int)$project['project_id'] ?>">

                                    <button type="submit" class="project-delete">
                                        Delete
                                    </button>

                                </form>

                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>

        </div>
    </div>

</div>

</body>
</html>
