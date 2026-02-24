<?php
require_once 'auth_check.php';
require_once '../config/db.php';

// Get current super admin info
$super_admin = get_current_super_admin();

// Check if user ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage_users.php?error=invalid_user");
    exit;
}

$user_id = (int)$_GET['id'];

// Fetch user details
try {
    $stmt = $pdo->prepare("
        SELECT u.*,
               s.school_name,
               0 as recent_activity
        FROM users u
        LEFT JOIN schools s ON u.school_id = s.id
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header("Location: manage_users.php?error=user_not_found");
        exit;
    }
} catch (Exception $e) {
    header("Location: manage_users.php?error=database_error");
    exit;
}

// Get schools for dropdown
$schools = $pdo->query("SELECT id, school_name, school_code FROM schools WHERE status = 'active' ORDER BY school_name")->fetchAll(PDO::FETCH_ASSOC);

// Log access
log_super_action('view_edit_user', 'user', $user_id, 'Accessed edit user page for user: ' . $user['username']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User | SahabFormMaster</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #334155;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e293b 0%, #334155 100%);
            color: white;
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 24px;
            border-bottom: 1px solid #475569;
            background: rgba(255, 255, 255, 0.05);
        }

        .sidebar-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: #f8fafc;
        }

        .sidebar-header p {
            font-size: 14px;
            color: #cbd5e1;
            margin-top: 4px;
        }

        .sidebar-nav {
            padding: 16px 0;
        }

        .nav-item {
            margin: 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 14px 24px;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            border-left-color: #3b82f6;
        }

        .nav-link.active {
            background: rgba(59, 130, 246, 0.2);
            color: #f8fafc;
            border-left-color: #3b82f6;
        }

        .nav-icon {
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 32px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .header h1 {
            font-size: 32px;
            font-weight: 800;
            color: #1e293b;
        }

        .header-actions {
            display: flex;
            gap: 16px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #475569;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .form-header {
            padding: 24px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .form-header h2 {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .form-header p {
            color: #64748b;
            font-size: 14px;
        }

        .form-body {
            padding: 24px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-full {
            grid-column: 1 / -1;
        }

        .form-row {
            display: flex;
            gap: 16px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin: 0;
        }

        .form-actions {
            padding: 24px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #f8fafc;
        }

        /* User Info Display */
        .user-info-card {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }

        .user-details h3 {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .user-meta {
            font-size: 14px;
            color: #64748b;
        }

        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        /* Loading */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid #e2e8f0;
            border-radius: 50%;
            border-top-color: #3b82f6;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-row {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-crown"></i> Super Admin</h2>
                <p>System Control Panel</p>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_schools.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-school"></i></span>
                            <span>Manage Schools</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_users.php" class="nav-link active">
                            <span class="nav-icon"><i class="fas fa-users"></i></span>
                            <span>Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="system_settings.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-cogs"></i></span>
                            <span>System Settings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="audit_logs.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-history"></i></span>
                            <span>Audit Logs</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="analytics.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                            <span>Analytics</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                            <span>Reports</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1>👤 Edit User</h1>
                <div class="header-actions">
                    <a href="manage_users.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Users
                    </a>
                </div>
            </div>

            <!-- User Info Card -->
            <div class="user-info-card">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                </div>
                <div class="user-details">
                    <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
                    <div class="user-meta">
                        @<?php echo htmlspecialchars($user['username']); ?> • Staff ID: <?php echo htmlspecialchars($user['staff_id']); ?> • Created: <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                    </div>
                </div>
            </div>

            <!-- Edit Form -->
            <div class="form-container">
                <div class="form-header">
                    <h2>Edit User Information</h2>
                    <p>Update user details and permissions</p>
                </div>

                <form id="editUserForm" method="POST" action="manage_users.php">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">

                    <div class="form-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="username">Username *</label>
                                <input type="text" id="username" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="full_name">Full Name *</label>
                                <input type="text" id="full_name" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="email">Email Address *</label>
                            <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="role">Role *</label>
                                <select id="role" name="role" class="form-control" required>
                                    <option value="teacher" <?php echo $user['role'] === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                                    <option value="principal" <?php echo $user['role'] === 'principal' ? 'selected' : ''; ?>>Principal</option>
                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Administrative Staff</option>
                                    <option value="support" <?php echo $user['role'] === 'support' ? 'selected' : ''; ?>>Support Staff</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="school_id">School *</label>
                                <select id="school_id" name="school_id" class="form-control" required>
                                    <option value="">Select School</option>
                                    <?php foreach ($schools as $school): ?>
                                        <option value="<?php echo $school['id']; ?>" <?php echo $user['school_id'] == $school['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($school['school_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="phone">Phone</label>
                                <input type="text" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="designation">Designation</label>
                                <input type="text" id="designation" name="designation" class="form-control" value="<?php echo htmlspecialchars($user['designation'] ?? ''); ?>" placeholder="e.g., Math Teacher">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="department">Department</label>
                            <input type="text" id="department" name="department" class="form-control" value="<?php echo htmlspecialchars($user['department'] ?? ''); ?>" placeholder="e.g., Science, Administration">
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="is_active" name="is_active" value="1" <?php echo $user['is_active'] ? 'checked' : ''; ?>>
                                <label for="is_active" style="margin: 0; font-weight: 500;">Active User</label>
                            </div>
                            <small style="color: #64748b; font-size: 12px; margin-left: 24px;">Inactive users cannot log in to the system</small>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="manage_users.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i>
                            <span id="submitText">Update User</span>
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        // Form validation and submission
        document.getElementById('editUserForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');

            submitBtn.disabled = true;
            submitText.textContent = 'Updating...';
            submitBtn.innerHTML = '<span class="spinner"></span> ' + submitText.textContent;
        });

        // Mobile menu toggle
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
        }
    </script>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
