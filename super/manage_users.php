<?php
require_once 'auth_check.php';
require_once '../config/db.php';

// Get current super admin info
$super_admin = get_current_super_admin();

// Log access
log_super_action('view_manage_users', 'system', null, 'Accessed user management interface');

// Function to generate unique staff ID
function generateStaffId($pdo) {
    $year = date('Y');
    $prefix = 'STF' . $year;

    // Find the highest existing staff_id for this year
    $stmt = $pdo->prepare("SELECT staff_id FROM users WHERE staff_id LIKE ? ORDER BY CAST(SUBSTRING(staff_id, 8) AS UNSIGNED) DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);

    $lastStaffId = $stmt->fetchColumn();

    if ($lastStaffId) {
        // Extract the sequential number and increment
        $sequential = (int)substr($lastStaffId, 7) + 1;
    } else {
        // Start with 001
        $sequential = 1;
    }

    // Format as 3-digit number with leading zeros
    return $prefix . str_pad($sequential, 3, '0', STR_PAD_LEFT);
}

// Handle form submissions
$errors = [];
$success = '';
$edit_user = null;

// Handle different actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        // Add new user
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'teacher';
        $school_id = (int)($_POST['school_id'] ?? 0);
        $phone = trim($_POST['phone'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $department = trim($_POST['department'] ?? '');

        // Validation
        if (empty($username) || empty($full_name) || empty($password)) {
            $errors[] = 'Username, full name, and password are required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email address is required.';
        }
        if ($school_id <= 0) {
            $errors[] = 'Please select a school.';
        }

        // Check uniqueness
        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Username already exists.';
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Email already exists.';
            }
        }

        if (empty($errors)) {
            // Generate unique staff ID
            $staff_id = generateStaffId($pdo);

            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, school_id, staff_id, phone, designation, department, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
                $stmt->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $full_name,
                    $email,
                    $role,
                    $school_id,
                    $staff_id,
                    $phone,
                    $designation,
                    $department
                ]);

                $new_user_id = $pdo->lastInsertId();
                log_super_action('add_user', 'user', $new_user_id, "Added new user: $username ($full_name) with staff ID $staff_id to school $school_id");
                $success = 'User created successfully with Staff ID: ' . $staff_id;

                header("Location: manage_users.php?success=added");
                exit;
            } catch (Exception $e) {
                $errors[] = 'Failed to create user: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'edit_user') {
        // Edit existing user
        $user_id = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'teacher';
        $school_id = (int)($_POST['school_id'] ?? 0);
        $phone = trim($_POST['phone'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Validation
        if (empty($username) || empty($full_name)) {
            $errors[] = 'Username and full name are required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email address is required.';
        }
        if ($school_id <= 0) {
            $errors[] = 'Please select a school.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, school_id = ?, phone = ?, designation = ?, department = ?, is_active = ? WHERE id = ?");
                $stmt->execute([
                    $username,
                    $full_name,
                    $email,
                    $role,
                    $school_id,
                    $phone,
                    $designation,
                    $department,
                    $is_active,
                    $user_id
                ]);

                log_super_action('edit_user', 'user', $user_id, "Updated user: $username ($full_name)");
                $success = 'User updated successfully.';

                header("Location: manage_users.php?success=updated");
                exit;
            } catch (Exception $e) {
                $errors[] = 'Failed to update user: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'bulk_status_change') {
        // Bulk status change
        $user_ids = $_POST['user_ids'] ?? [];
        $new_status = (int)($_POST['new_status'] ?? 1);

        if (empty($user_ids) || !is_array($user_ids)) {
            $errors[] = 'No users selected.';
        } else {
            try {
                $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
                $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id IN ($placeholders)");
                $params = array_merge([$new_status], $user_ids);
                $stmt->execute($params);

                log_super_action('bulk_status_change', 'system', null, "Bulk updated " . count($user_ids) . " users to status $new_status");
                $success = count($user_ids) . ' users updated successfully.';
            } catch (Exception $e) {
                $errors[] = 'Failed to update users: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'reset_password') {
        // Reset user password
        $user_id = (int)($_POST['user_id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';

        if (empty($new_password) || strlen($new_password) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([
                    password_hash($new_password, PASSWORD_DEFAULT),
                    $user_id
                ]);

                log_super_action('reset_password', 'user', $user_id, "Password reset for user ID $user_id");
                $success = 'Password reset successfully.';
            } catch (Exception $e) {
                $errors[] = 'Failed to reset password: ' . $e->getMessage();
            }
        }
    }
}

// Handle GET parameters for edit mode
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT u.*, s.school_name FROM users u LEFT JOIN schools s ON u.school_id = s.id WHERE u.id = ?");
    $stmt->execute([$edit_id]);
    $edit_user = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle success messages from redirects
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'added') {
        $success = 'User added successfully.';
    } elseif ($_GET['success'] === 'updated') {
        $success = 'User updated successfully.';
    }
}

// Get user statistics
$stats = [];
try {
    // Total users
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
    $stats['total_active'] = $stmt->fetchColumn();

    // Total inactive
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE is_active = 0");
    $stats['total_inactive'] = $stmt->fetchColumn();

    // Users by role
    $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users WHERE is_active = 1 GROUP BY role");
    $stats['role_counts'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Users by school
    $stmt = $pdo->query("SELECT s.school_name, COUNT(u.id) as count FROM schools s LEFT JOIN users u ON s.id = u.school_id AND u.is_active = 1 GROUP BY s.id ORDER BY count DESC LIMIT 10");
    $stats['school_counts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $stats = array_fill_keys(['total_active', 'total_inactive', 'role_counts', 'school_counts'], 0);
}

// Get users with filters
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$school_filter = $_GET['school'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$per_page = 15;
$offset = ($page - 1) * $per_page;

$query = "
    SELECT u.*, s.school_name, s.school_code,
           0 as recent_activity
    FROM users u
    LEFT JOIN schools s ON u.school_id = s.id
    WHERE 1=1
";

$params = [];
if ($search) {
    $query .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($role_filter) {
    $query .= " AND u.role = ?";
    $params[] = $role_filter;
}
if ($school_filter) {
    $query .= " AND u.school_id = ?";
    $params[] = $school_filter;
}
if ($status_filter !== '') {
    $query .= " AND u.is_active = ?";
    $params[] = (int)$status_filter;
}

$query .= " ORDER BY u.created_at DESC LIMIT " . (int)$offset . ", " . (int)$per_page;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_query = "SELECT COUNT(*) FROM users u WHERE 1=1";
$count_params = [];
if ($search) {
    $count_query .= " AND (username LIKE ? OR full_name LIKE ? OR email LIKE ?)";
    $count_params = array_merge($count_params, ["%$search%", "%$search%", "%$search%"]);
}
if ($role_filter) {
    $count_query .= " AND role = ?";
    $count_params[] = $role_filter;
}
if ($school_filter) {
    $count_query .= " AND school_id = ?";
    $count_params[] = $school_filter;
}
if ($status_filter !== '') {
    $count_query .= " AND is_active = ?";
    $count_params[] = (int)$status_filter;
}

$stmt = $pdo->prepare($count_query);
$stmt->execute($count_params);
$total_users = $stmt->fetchColumn();
$total_pages = ceil($total_users / $per_page);

// Get schools for dropdown
$schools = $pdo->query("SELECT id, school_name, school_code FROM schools WHERE status = 'active' ORDER BY school_name")->fetchAll(PDO::FETCH_ASSOC);

// Get roles for filter
$roles = $pdo->query("SELECT DISTINCT role FROM users ORDER BY role")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users | SahabFormMaster</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 14px;
            color: #64748b;
        }

        /* Color schemes */
        .stat-users .stat-icon { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; }
        .stat-active .stat-icon { background: linear-gradient(135deg, #10b981, #059669); color: white; }
        .stat-inactive .stat-icon { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        .stat-schools .stat-icon { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }

        /* Filters */
        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
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
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* Users Table */
        .users-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .table-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .bulk-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 14px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .data-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            font-size: 14px;
        }

        .data-table tbody tr:hover {
            background: #f8fafc;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .user-details h4 {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 2px;
            font-size: 14px;
        }

        .user-meta {
            font-size: 12px;
            color: #64748b;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .role-badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .role-principal { background: #fef3c7; color: #92400e; }
        .role-teacher { background: #dbeafe; color: #1e40af; }
        .role-admin { background: #f3e8ff; color: #6b21a8; }
        .role-support { background: #ecfdf5; color: #065f46; }

        .action-buttons {
            display: flex;
            gap: 6px;
        }

        .action-btn {
            padding: 6px 10px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .action-btn-edit {
            background: #3b82f6;
            color: white;
        }

        .action-btn-edit:hover {
            background: #2563eb;
        }

        .action-btn-view {
            background: #6b7280;
            color: white;
        }

        .action-btn-view:hover {
            background: #4b5563;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 6px;
            margin-top: 20px;
            padding: 20px;
        }

        .page-link {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            text-decoration: none;
            color: #64748b;
            transition: all 0.3s ease;
        }

        .page-link:hover,
        .page-link.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #64748b;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
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
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            .filters-grid {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .table-container {
                font-size: 12px;
            }
            .data-table th,
            .data-table td {
                padding: 8px;
            }
            .action-buttons {
                flex-direction: column;
                gap: 4px;
            }
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
                <h1>👥 Manage Users</h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-user-plus"></i>
                        Add New User
                    </button>
                    <button class="btn btn-success" onclick="exportUsers()">
                        <i class="fas fa-download"></i>
                        Export CSV
                    </button>
                    <button class="btn btn-secondary" onclick="openBulkImportModal()">
                        <i class="fas fa-upload"></i>
                        Bulk Import
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Dashboard
                    </a>
                </div>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <?php foreach ($errors as $e): ?>
                            <div><?php echo htmlspecialchars($e); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card stat-users">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_active'] + $stats['total_inactive']); ?></div>
                    <div class="stat-label">Total Users</div>
                </div>

                <div class="stat-card stat-active">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_active']); ?></div>
                    <div class="stat-label">Active Users</div>
                </div>

                <div class="stat-card stat-inactive">
                    <div class="stat-icon">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_inactive']); ?></div>
                    <div class="stat-label">Inactive Users</div>
                </div>

                <div class="stat-card stat-schools">
                    <div class="stat-icon">
                        <i class="fas fa-school"></i>
                    </div>
                    <div class="stat-value"><?php echo count($stats['school_counts']); ?></div>
                    <div class="stat-label">Schools</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" class="filters-grid">
                    <div class="form-group">
                        <label class="form-label">Search Users</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, username, or email..." class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-control">
                            <option value="">All Roles</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role); ?>" <?php echo $role_filter === $role ? 'selected' : ''; ?>>
                                    <?php echo ucfirst(htmlspecialchars($role)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">School</label>
                        <select name="school" class="form-control">
                            <option value="">All Schools</option>
                            <?php foreach ($schools as $school): ?>
                                <option value="<?php echo $school['id']; ?>" <?php echo $school_filter == $school['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($school['school_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="form-group" style="display: flex; gap: 8px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-search"></i>
                            Filter
                        </button>
                        <?php if ($search || $role_filter || $school_filter || $status_filter): ?>
                            <a href="manage_users.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i>
                                Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Users Table -->
            <div class="users-table">
                <div class="table-header">
                    <div style="font-size: 16px; font-weight: 600; color: #1e293b;">
                        Users (<?php echo number_format($total_users); ?> total)
                    </div>
                    <?php if (!empty($users)): ?>
                        <div class="bulk-actions">
                            <select id="bulk-status" class="form-control" style="width: auto; font-size: 12px;">
                                <option value="">Bulk Actions</option>
                                <option value="1">Activate Selected</option>
                                <option value="0">Deactivate Selected</option>
                            </select>
                            <button class="btn btn-success" onclick="applyBulkAction()" id="bulk-apply-btn" disabled style="font-size: 12px; padding: 8px 16px;">
                                <i class="fas fa-check"></i>
                                Apply
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="select-all" onchange="toggleAllCheckboxes()">
                                </th>
                                <th>User</th>
                                <th>Role</th>
                                <th>School</th>
                                <th>Status</th>
                                <th>Activity</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 48px; color: #64748b;">
                                        <i class="fas fa-users" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                                        No users found.
                                        <?php if ($search || $role_filter || $school_filter || $status_filter): ?>
                                            Try adjusting your search criteria.
                                        <?php else: ?>
                                            <br><button class="btn btn-primary" onclick="openAddModal()">Add your first user</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="user-checkbox" value="<?php echo $user['id']; ?>" onchange="updateBulkButton()">
                                        </td>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-avatar">
                                                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                                </div>
                                                <div class="user-details">
                                                    <h4><?php echo htmlspecialchars($user['full_name']); ?></h4>
                                                    <div class="user-meta">
                                                        @<?php echo htmlspecialchars($user['username']); ?> • <?php echo htmlspecialchars($user['email']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="role-badge role-<?php echo $user['role']; ?>">
                                                <?php echo ucfirst(htmlspecialchars($user['role'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['school_name'] ?? 'No School'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo number_format($user['recent_activity']); ?> actions</td>
                                        <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="action-btn action-btn-edit">
                                                    <i class="fas fa-edit"></i>
                                                    Edit
                                                </a>
                                                <button class="action-btn action-btn-view" onclick="resetPassword(<?php echo $user['id']; ?>)">
                                                    <i class="fas fa-key"></i>
                                                    Reset
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Add/Edit User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Add New User</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="userForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="add_user">
                <input type="hidden" name="user_id" id="userId" value="">
                <div class="modal-body">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="form-group">
                            <label class="form-label" for="username">Username *</label>
                            <input type="text" id="username" name="username" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="full_name">Full Name *</label>
                            <input type="text" id="full_name" name="full_name" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="email">Email Address *</label>
                        <input type="email" id="email" name="email" class="form-control" required>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="form-group">
                            <label class="form-label" for="role">Role *</label>
                            <select id="role" name="role" class="form-control" required>
                                <option value="teacher">Teacher</option>
                                <option value="principal">Principal</option>
                                <option value="admin">Administrative Staff</option>
                                <option value="support">Support Staff</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="school_id">School *</label>
                            <select id="school_id" name="school_id" class="form-control" required>
                                <option value="">Select School</option>
                                <?php foreach ($schools as $school): ?>
                                    <option value="<?php echo $school['id']; ?>">
                                        <?php echo htmlspecialchars($school['school_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="form-group">
                            <label class="form-label" for="phone">Phone</label>
                            <input type="text" id="phone" name="phone" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="designation">Designation</label>
                            <input type="text" id="designation" name="designation" class="form-control" placeholder="e.g., Math Teacher">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="department">Department</label>
                        <input type="text" id="department" name="department" class="form-control" placeholder="e.g., Science, Administration">
                    </div>

                    <div class="form-group" id="passwordGroup">
                        <label class="form-label" for="password">Password *</label>
                        <input type="password" id="password" name="password" class="form-control" required minlength="6">
                        <small style="color: #64748b; font-size: 12px;">Minimum 6 characters</small>
                    </div>

                    <div class="form-group" id="activeGroup" style="display: none;">
                        <label class="form-label">
                            <input type="checkbox" id="is_active" name="is_active" checked> Active User
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-save"></i>
                        <span id="submitText">Add User</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Password Reset Modal -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Reset User Password</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="passwordForm" method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="new_password">New Password *</label>
                        <input type="password" id="new_password" name="new_password" class="form-control" required minlength="6">
                        <small style="color: #64748b; font-size: 12px;">Minimum 6 characters. User will be notified to change it on next login.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-key"></i>
                        Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New User';
            document.getElementById('formAction').value = 'add_user';
            document.getElementById('userId').value = '';
            document.getElementById('submitText').textContent = 'Add User';
            document.getElementById('passwordGroup').style.display = 'block';
            document.getElementById('activeGroup').style.display = 'none';
            document.getElementById('userForm').reset();
            document.getElementById('userModal').classList.add('show');
        }



        function resetPassword(id) {
            document.getElementById('resetUserId').value = id;
            document.getElementById('passwordForm').reset();
            document.getElementById('passwordModal').classList.add('show');
        }

        function closeModal() {
            document.querySelectorAll('.modal').forEach(modal => modal.classList.remove('show'));
        }

        // Bulk actions
        function toggleAllCheckboxes() {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
            updateBulkButton();
        }

        function updateBulkButton() {
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            const bulkBtn = document.getElementById('bulk-apply-btn');
            bulkBtn.disabled = checkboxes.length === 0;
        }

        function applyBulkAction() {
            const status = document.getElementById('bulk-status').value;
            if (!status) {
                alert('Please select an action.');
                return;
            }

            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Please select users to update.');
                return;
            }

            if (confirm(`Are you sure you want to ${status === '1' ? 'activate' : 'deactivate'} ${checkboxes.length} user(s)?`)) {
                const userIds = Array.from(checkboxes).map(cb => cb.value);

                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const actionInput = document.createElement('input');
                actionInput.name = 'action';
                actionInput.value = 'bulk_status_change';
                form.appendChild(actionInput);

                userIds.forEach(id => {
                    const input = document.createElement('input');
                    input.name = 'user_ids[]';
                    input.value = id;
                    form.appendChild(input);
                });

                const statusInput = document.createElement('input');
                statusInput.name = 'new_status';
                statusInput.value = status;
                form.appendChild(statusInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Form validation
        document.getElementById('userForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');

            submitBtn.disabled = true;
            submitText.textContent = 'Processing...';
            submitBtn.innerHTML = '<span class="spinner"></span> ' + submitText.textContent;
        });

        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            const submitBtn = e.target.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Resetting...';
        });

        // Mobile menu toggle
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
        }

        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                closeModal();
            }
        });

        // Export users to CSV
        function exportUsers() {
            const search = '<?php echo addslashes($search); ?>';
            const role = '<?php echo addslashes($role_filter); ?>';
            const school = '<?php echo addslashes($school_filter); ?>';
            const status = '<?php echo addslashes($status_filter); ?>';

            let url = 'ajax/export_users.php?format=csv';
            if (search) url += '&search=' + encodeURIComponent(search);
            if (role) url += '&role=' + encodeURIComponent(role);
            if (school) url += '&school=' + encodeURIComponent(school);
            if (status !== '') url += '&status=' + encodeURIComponent(status);

            // Create a temporary link and click it to trigger download without opening a new window
            const link = document.createElement('a');
            link.href = url;
            link.download = 'users_export_' + new Date().toISOString().split('T')[0] + '.csv';
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Bulk import modal
        function openBulkImportModal() {
            alert('Bulk import functionality coming soon! This will allow CSV upload for batch user creation.');
        }
    </script>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
