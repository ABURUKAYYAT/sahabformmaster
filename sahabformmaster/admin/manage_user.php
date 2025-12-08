<?php
// admin/manage_user.php
session_start();
require_once '../config/db.php';

// Only allow principal (admin) to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$admin_name = $_SESSION['full_name'] ?? 'Administrator';
$errors = [];
$success = '';

// Handle Create / Update via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // common fields
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'teacher';

    if ($action === 'add') {
        if ($username === '' || $full_name === '' || $password === '') {
            $errors[] = 'Please fill all required fields (username, full name, password).';
        } else {
            // check username uniqueness
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
            $stmt->execute(['username' => $username]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Username already exists.';
            } else {
                // NOTE: passwords stored as plain text in your app — ideally use password_hash()
                $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (:username, :password, :full_name, :role)");
                $stmt->execute([
                    'username' => $username,
                    'password' => $password,
                    'full_name' => $full_name,
                    'role' => $role
                ]);
                $success = 'User created successfully.';
                header("Location: manage_user.php");
                exit;
            }
        }
    }

    if ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0 || $username === '' || $full_name === '') {
            $errors[] = 'Invalid input for update.';
        } else {
            // ensure username uniqueness (exclude current user)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username AND id <> :id");
            $stmt->execute(['username' => $username, 'id' => $id]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Username already used by another account.';
            } else {
                if ($password !== '') {
                    $stmt = $pdo->prepare("UPDATE users SET username = :username, password = :password, full_name = :full_name, role = :role WHERE id = :id");
                    $stmt->execute([
                        'username' => $username,
                        'password' => $password,
                        'full_name' => $full_name,
                        'role' => $role,
                        'id' => $id
                    ]);
                } else {
                    // don't change password if left blank
                    $stmt = $pdo->prepare("UPDATE users SET username = :username, full_name = :full_name, role = :role WHERE id = :id");
                    $stmt->execute([
                        'username' => $username,
                        'full_name' => $full_name,
                        'role' => $role,
                        'id' => $id
                    ]);
                }
                $success = 'User updated successfully.';
                header("Location: manage_user.php");
                exit;
            }
        }
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errors[] = 'Invalid user id.';
        } elseif ($id === ($_SESSION['user_id'] ?? 0)) {
            $errors[] = 'You cannot delete your own account while logged in.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $success = 'User deleted successfully.';
            header("Location: manage_user.php");
            exit;
        }
    }
}

// Handle delete via GET (graceful fallback; still checks permission)
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    if ($del_id > 0 && $del_id !== ($_SESSION['user_id'] ?? 0)) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute(['id' => $del_id]);
        header("Location: manage_user.php");
        exit;
    } else {
        $errors[] = 'Cannot delete this user.';
    }
}

// Fetch users list
$stmt = $pdo->query("SELECT id, username, full_name, role, created_at FROM users ORDER BY id DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If editing, fetch user data
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT id, username, full_name, role FROM users WHERE id = :id");
        $stmt->execute(['id' => $edit_id]);
        $edit_user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Manage Users | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/manage_user.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        /* small helpers specific to this page */
        .manage-actions { display:flex; gap:8px; align-items:center; }
        .table { width:100%; border-collapse:collapse; background:var(--white); border-radius:8px; overflow:hidden; }
        .table th, .table td { padding:12px 10px; border-bottom:1px solid #eee; text-align:left; font-size:14px; }
        .table th { background: #faf7ef; color:var(--dark-gold); font-weight:700; }
        .small-muted { color:#777; font-size:13px; }
        .form-inline { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .form-inline .form-group { flex:1; min-width:160px; }
        .btn-small { padding:6px 10px; border-radius:6px; font-size:13px; text-decoration:none; display:inline-block; }
        .btn-edit { background:#f0f0f0; color:#444; border:1px solid #ddd; }
        .btn-delete { background:#ffefef; color:#c00; border:1px solid #f5c6c6; }
    </style>
</head>
<body>

<header class="dashboard-header">
    <div class="header-container">
        <div class="header-right">
            <div class="school-logo-container">
                <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                <h1 class="school-name">SahabFormMaster</h1>
            </div>
        </div>

        <div class="header-left">
            <div class="teacher-info">
                <span class="teacher-name"><?php echo htmlspecialchars($admin_name); ?></span>
            </div>
            <a href="../index.php" class="btn-logout">Logout</a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <aside class="sidebar">
            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a href="schoolnews.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Manage Students</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="results.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Manage Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Manage Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Manage Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school.php" class="nav-link">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_user.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="visitors.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Visitors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="timebook.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Teachers Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="travelling.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Travelling</span>
                        </a>
                    </li>
                                        
                    <li class="nav-item">
                        <a href="classwork.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Class Work</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a href="assignment.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Assignment</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a href="attendance.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Attendance Register</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a href="schoolfees.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">School Fees Payments</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

    <main class="main-content">
        <div class="content-header">
            <h2>Manage Users</h2>
            <p class="small-muted">Create, update or remove staff accounts</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert" role="alert">
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert" style="background:rgba(200,255,200,0.8); color:#064;">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Create / Edit form -->
        <section style="margin-bottom:20px;">
            <div style="background:var(--white); padding:18px; border-radius:10px; box-shadow:0 6px 18px rgba(0,0,0,0.05);">
                <h3 style="margin-top:0;"><?php echo $edit_user ? 'Edit User' : 'Create New User'; ?></h3>

                <form method="POST" class="form-inline" style="margin-top:10px;">
                    <input type="hidden" name="action" value="<?php echo $edit_user ? 'edit' : 'add'; ?>">
                    <?php if ($edit_user): ?>
                        <input type="hidden" name="id" value="<?php echo intval($edit_user['id']); ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($edit_user['username'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($edit_user['full_name'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Password <?php echo $edit_user ? '(leave blank to keep)' : ''; ?></label>
                        <input type="text" name="password" class="form-control" value="">
                    </div>

                    <div class="form-group" style="min-width:160px;">
                        <label>Role</label>
                        <select name="role" class="form-control">
                            <?php $selRole = $edit_user['role'] ?? 'teacher'; ?>
                            <option value="principal" <?php echo $selRole === 'principal' ? 'selected' : ''; ?>>Principal</option>
                            <option value="teacher" <?php echo $selRole === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                        </select>
                    </div>

                    <div style="display:flex; gap:10px; align-items:flex-end; margin-left:auto;">
                        <?php if ($edit_user): ?>
                            <button type="submit" class="btn-gold">Update User</button>
                            <a href="manage_user.php" class="btn-gold" style="background:#f0f0f0; color:#444; border:1px solid #ddd;">Cancel</a>
                        <?php else: ?>
                            <button type="submit" class="btn-gold">Create User</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <!-- Users table -->
        <section>
            <div style="overflow:auto;">
                <table class="table" aria-describedby="users-list">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th style="width:150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($users) === 0): ?>
                            <tr><td colspan="6" class="small-muted">No users found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><?php echo intval($u['id']); ?></td>
                                    <td><?php echo htmlspecialchars($u['username']); ?></td>
                                    <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($u['role'])); ?></td>
                                    <td class="small-muted"><?php echo htmlspecialchars($u['created_at'] ?? ''); ?></td>
                                    <td>
                                        <div class="manage-actions">
                                            <a class="btn-small btn-edit" href="manage_user.php?edit=<?php echo intval($u['id']); ?>">Edit</a>

                                            <?php if (intval($u['id']) !== ($_SESSION['user_id'] ?? 0)): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this user?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo intval($u['id']); ?>">
                                                    <button type="submit" class="btn-small btn-delete">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="small-muted">Current</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>About SahabFormMaster</h4>
                <p>Admin tools to manage users, students and results.</p>
            </div>
            <div class="footer-section">
                <h4>Quick Links</h4>
                <div class="footer-links">
                    <a href="index.php">Dashboard</a>
                    <a href="manage_user.php">Manage Users</a>
                    <a href="students.php">Students</a>
                </div>
            </div>
            <div class="footer-section">
                <h4>Support</h4>
                <p>Email: <a href="mailto:support@sahabformmaster.com">support@sahabformmaster.com</a></p>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
            <p class="footer-version">Version 1.0</p>
        </div>
    </div>
</footer>

</body>
</html>