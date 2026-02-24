<?php
require_once 'auth_check.php';
require_once '../config/db.php';

// Get current super admin info
$super_admin = get_current_super_admin();

// Log access
log_super_action('view_audit_logs', 'system', null, 'Accessed audit logs page');

// Handle filters and search
$search = $_GET['search'] ?? '';
$action_filter = $_GET['action'] ?? '';
$status_filter = $_GET['status'] ?? '';
$user_filter = $_GET['user'] ?? '';
$school_filter = $_GET['school'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;

// Build query conditions
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(al.action LIKE ? OR al.resource_type LIKE ? OR al.details LIKE ? OR u.full_name LIKE ? OR s.school_name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
}

if (!empty($action_filter)) {
    $conditions[] = "al.action = ?";
    $params[] = $action_filter;
}

if (!empty($status_filter)) {
    $conditions[] = "al.status = ?";
    $params[] = $status_filter;
}

if (!empty($user_filter)) {
    $conditions[] = "al.user_id = ?";
    $params[] = $user_filter;
}

if (!empty($school_filter)) {
    $conditions[] = "al.school_id = ?";
    $params[] = $school_filter;
}

if (!empty($date_from)) {
    $conditions[] = "al.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if (!empty($date_to)) {
    $conditions[] = "al.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total count for pagination
$count_query = "SELECT COUNT(*) FROM access_logs al
                LEFT JOIN users u ON al.user_id = u.id
                LEFT JOIN schools s ON al.school_id = s.id
                $where_clause";

$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get audit logs with pagination
$offset = ($page - 1) * $per_page;
$query = "SELECT al.*, u.full_name, u.role, s.school_name, s.school_code
          FROM access_logs al
          LEFT JOIN users u ON al.user_id = u.id
          LEFT JOIN schools s ON al.school_id = s.id
          $where_clause
          ORDER BY al.created_at DESC
          LIMIT $per_page OFFSET $offset";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter options
$actions = $pdo->query("SELECT DISTINCT action FROM access_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$statuses = $pdo->query("SELECT DISTINCT status FROM access_logs ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);

// Get users for filter dropdown
$users_query = "SELECT DISTINCT u.id, u.full_name, u.role FROM users u
                INNER JOIN access_logs al ON u.id = al.user_id
                ORDER BY u.full_name";
$users = $pdo->query($users_query)->fetchAll(PDO::FETCH_ASSOC);

// Get schools for filter dropdown
$schools_query = "SELECT DISTINCT s.id, s.school_name, s.school_code FROM schools s
                  INNER JOIN access_logs al ON s.id = al.school_id
                  ORDER BY s.school_name";
$schools = $pdo->query($schools_query)->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [];
$stats['total_logs'] = $pdo->query("SELECT COUNT(*) FROM access_logs")->fetchColumn();
$stats['today_logs'] = $pdo->query("SELECT COUNT(*) FROM access_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$stats['failed_logins'] = $pdo->query("SELECT COUNT(*) FROM access_logs WHERE action = 'login' AND status = 'denied' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
$stats['active_users'] = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM access_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND user_id IS NOT NULL")->fetchColumn();

// Comprehensive user type statistics
$stats['admin_logs'] = $pdo->query("SELECT COUNT(*) FROM access_logs al JOIN users u ON al.user_id = u.id WHERE u.role IN ('admin', 'principal')")->fetchColumn();
$stats['teacher_logs'] = $pdo->query("SELECT COUNT(*) FROM access_logs al JOIN users u ON al.user_id = u.id WHERE u.role = 'teacher'")->fetchColumn();
$stats['student_logs'] = $pdo->query("SELECT COUNT(*) FROM access_logs al JOIN students s ON al.user_id = s.user_id")->fetchColumn();
$stats['super_admin_logs'] = $pdo->query("SELECT COUNT(*) FROM access_logs al JOIN users u ON al.user_id = u.id WHERE u.role = 'super_admin'")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs | SahabFormMaster</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
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
            padding-bottom: 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .header h1 {
            font-size: 32px;
            font-weight: 800;
            color: #1e293b;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
        }

        .stat-card h3 {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 12px;
            color: #64748b;
        }

        /* Filters */
        .filters-section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .filter-buttons {
            display: flex;
            gap: 12px;
            align-items: end;
        }

        /* Table */
        .logs-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logs-table th {
            background: #f8fafc;
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e2e8f0;
            font-size: 14px;
        }

        .logs-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }

        .logs-table tbody tr:hover {
            background: #f8fafc;
        }

        /* Status badges */
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-success {
            background: #dcfce7;
            color: #166534;
        }

        .status-denied {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-info {
            background: #dbeafe;
            color: #1e40af;
        }

        /* User role badges */
        .role-badge {
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-super_admin {
            background: #fef3c7;
            color: #92400e;
        }

        .role-admin {
            background: #dbeafe;
            color: #1e40af;
        }

        .role-teacher {
            background: #dcfce7;
            color: #166534;
        }

        .role-student {
            background: #f3e8ff;
            color: #6b21a8;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: between;
            align-items: center;
            padding: 20px;
            background: white;
            border-top: 1px solid #e2e8f0;
        }

        .pagination-info {
            color: #64748b;
            font-size: 14px;
        }

        .pagination-controls {
            display: flex;
            gap: 8px;
        }

        .page-btn {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            background: white;
            color: #374151;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
        }

        .page-btn:hover,
        .page-btn.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        .page-btn.disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 240px;
            }
            .main-content {
                margin-left: 240px;
            }
            .filters-form {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
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
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .logs-table {
                font-size: 12px;
            }
            .logs-table th,
            .logs-table td {
                padding: 8px 12px;
            }
        }

        /* Details modal styles would go here if needed */
        .details-link {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
        }

        .details-link:hover {
            text-decoration: underline;
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
                        <a href="manage_users.php" class="nav-link">
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
                        <a href="audit_logs.php" class="nav-link active">
                            <span class="nav-icon"><i class="fas fa-history"></i></span>
                            <span>Audit Logs</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="database_tools.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-database"></i></span>
                            <span>Database Tools</span>
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

        <!-- Mobile Navigation -->
        <?php include '../includes/mobile_navigation.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1><i class="fas fa-history"></i> Audit Logs</h1>
                <div class="header-actions">
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <a href="logout.php" class="btn" style="background: #dc2626; color: white;">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Logs</h3>
                    <div class="stat-value"><?php echo number_format($stats['total_logs']); ?></div>
                    <div class="stat-label">All time</div>
                </div>
                <div class="stat-card">
                    <h3>Today's Activity</h3>
                    <div class="stat-value"><?php echo number_format($stats['today_logs']); ?></div>
                    <div class="stat-label">Logs today</div>
                </div>
                <div class="stat-card">
                    <h3>Super Admin Logs</h3>
                    <div class="stat-value"><?php echo number_format($stats['super_admin_logs']); ?></div>
                    <div class="stat-label">System administration</div>
                </div>
                <div class="stat-card">
                    <h3>Admin/Principal Logs</h3>
                    <div class="stat-value"><?php echo number_format($stats['admin_logs']); ?></div>
                    <div class="stat-label">School management</div>
                </div>
                <div class="stat-card">
                    <h3>Teacher Logs</h3>
                    <div class="stat-value"><?php echo number_format($stats['teacher_logs']); ?></div>
                    <div class="stat-label">Academic activities</div>
                </div>
                <div class="stat-card">
                    <h3>Student Logs</h3>
                    <div class="stat-value"><?php echo number_format($stats['student_logs']); ?></div>
                    <div class="stat-label">Student activities</div>
                </div>
                <div class="stat-card">
                    <h3>Failed Logins</h3>
                    <div class="stat-value"><?php echo number_format($stats['failed_logins']); ?></div>
                    <div class="stat-label">Last 24 hours</div>
                </div>
                <div class="stat-card">
                    <h3>Active Users</h3>
                    <div class="stat-value"><?php echo number_format($stats['active_users']); ?></div>
                    <div class="stat-label">Last 24 hours</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" class="filters-form">
                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search logs...">
                    </div>

                    <div class="filter-group">
                        <label for="action">Action</label>
                        <select id="action" name="action">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $action): ?>
                                <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $action_filter === $action ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($action); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="">All Statuses</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $status_filter === $status ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="user">User</label>
                        <select id="user" name="user">
                            <option value="">All Users</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $user_filter == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name'] . ' (' . $user['role'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="school">School</label>
                        <select id="school" name="school">
                            <option value="">All Schools</option>
                            <?php foreach ($schools as $school): ?>
                                <option value="<?php echo $school['id']; ?>" <?php echo $school_filter == $school['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($school['school_name'] . ' (' . $school['school_code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="date_from">From Date</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>

                    <div class="filter-group">
                        <label for="date_to">To Date</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>

                    <div class="filter-buttons">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="audit_logs.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Audit Logs Table -->
            <div class="logs-table-container">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Resource</th>
                            <th>School</th>
                            <th>Status</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($audit_logs)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px; color: #64748b;">
                                    <i class="fas fa-inbox fa-2x" style="margin-bottom: 16px; display: block;"></i>
                                    No audit logs found matching your criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($audit_logs as $log): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; color: #1e293b;">
                                            <?php echo date('M j, Y', strtotime($log['created_at'])); ?>
                                        </div>
                                        <div style="font-size: 12px; color: #64748b;">
                                            <?php echo date('H:i:s', strtotime($log['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($log['full_name']): ?>
                                            <div style="font-weight: 500; color: #1e293b;">
                                                <?php echo htmlspecialchars($log['full_name']); ?>
                                            </div>
                                            <div class="role-badge role-<?php echo $log['role']; ?>">
                                                <?php echo htmlspecialchars($log['role']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #64748b;">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-weight: 500; color: #374151;">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight: 500; color: #374151;">
                                            <?php echo htmlspecialchars($log['resource_type'] ?: 'N/A'); ?>
                                        </span>
                                        <?php if ($log['resource_id']): ?>
                                            <br>
                                            <span style="font-size: 12px; color: #64748b;">
                                                ID: <?php echo htmlspecialchars($log['resource_id']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['school_name']): ?>
                                            <div style="font-weight: 500; color: #1e293b;">
                                                <?php echo htmlspecialchars($log['school_name']); ?>
                                            </div>
                                            <div style="font-size: 12px; color: #64748b;">
                                                <?php echo htmlspecialchars($log['school_code']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #64748b;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $log['status']; ?>">
                                            <?php echo htmlspecialchars($log['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($log['details'])): ?>
                                            <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?php echo htmlspecialchars(substr($log['details'], 0, 50)); ?>
                                                <?php if (strlen($log['details']) > 50): ?>
                                                    ...
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #64748b;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Showing <?php echo (($page - 1) * $per_page) + 1; ?> to <?php echo min($page * $per_page, $total_records); ?> of <?php echo number_format($total_records); ?> entries
                        </div>
                        <div class="pagination-controls">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-btn">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            <?php else: ?>
                                <span class="page-btn disabled">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </span>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                   class="page-btn <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-btn">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-btn disabled">
                                    Next <i class="fas fa-chevron-right"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Auto-submit form on filter change (optional enhancement)
        document.querySelectorAll('select').forEach(select => {
            select.addEventListener('change', function() {
                // Uncomment below to auto-submit on filter change
                // this.closest('form').submit();
            });
        });

        // Clear date inputs
        document.getElementById('date_from').addEventListener('change', function() {
            if (!this.value) {
                this.value = '';
            }
        });

        document.getElementById('date_to').addEventListener('change', function() {
            if (!this.value) {
                this.value = '';
            }
        });

        // Mobile sidebar toggle
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
        }
    </script>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
