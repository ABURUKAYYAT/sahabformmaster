<?php
require_once 'auth_check.php';
require_once '../config/db.php';

// Get current super admin info
$super_admin = get_current_super_admin();

// Log dashboard access
log_super_action('view_dashboard', 'system', null, 'Accessed main dashboard');

// Fetch system-wide statistics
$stats = [];
try {
    // Total schools
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM schools WHERE status = 'active'");
    $stats['total_schools'] = $stmt->fetchColumn();

    // Total users across all schools
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
    $stats['total_users'] = $stmt->fetchColumn();

    // Total students across all schools
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM students WHERE is_active = 1");
    $stats['total_students'] = $stmt->fetchColumn();

    // Total teachers across all schools
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role IN ('teacher', 'principal', 'vice_principal') AND is_active = 1");
    $stats['total_teachers'] = $stmt->fetchColumn();

    // System health - recent activity
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM access_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->execute();
    $stats['recent_activity'] = $stmt->fetchColumn();

    // Failed login attempts in last 24 hours
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM access_logs WHERE action = 'login' AND status = 'denied' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->execute();
    $stats['failed_logins'] = $stmt->fetchColumn();

    // Database size estimate
    $stmt = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb FROM information_schema.tables WHERE table_schema = DATABASE()");
    $stats['db_size'] = $stmt->fetchColumn();

    // Recent schools added
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM schools WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt->execute();
    $stats['new_schools_week'] = $stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $stats = array_fill_keys(['total_schools', 'total_users', 'total_students', 'total_teachers', 'recent_activity', 'failed_logins', 'db_size', 'new_schools_week'], 0);
}

// Get recent system activities
$recent_activities = [];
try {
    $stmt = $pdo->prepare("
        SELECT al.*, u.full_name, s.school_name
        FROM access_logs al
        LEFT JOIN users u ON al.user_id = u.id
        LEFT JOIN schools s ON al.school_id = s.id
        WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_activities = [];
}

// Get schools overview
$schools_overview = [];
try {
    $stmt = $pdo->query("
        SELECT s.*,
               (SELECT COUNT(*) FROM users WHERE school_id = s.id AND is_active = 1) as user_count,
               (SELECT COUNT(*) FROM students WHERE school_id = s.id AND is_active = 1) as student_count,
               (SELECT COUNT(*) FROM classes WHERE school_id = s.id) as class_count
        FROM schools s
        WHERE s.status = 'active'
        ORDER BY s.created_at DESC
        LIMIT 5
    ");
    $schools_overview = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $schools_overview = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard | SahabFormMaster</title>
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
            justify-content: between;
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

        .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .admin-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
        }

        .logout-btn {
            padding: 8px 16px;
            background: #dc2626;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s ease;
        }

        .logout-btn:hover {
            background: #b91c1c;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
        }

        .stat-trend {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 6px;
            font-weight: 600;
        }

        .trend-positive {
            background: #dcfce7;
            color: #166534;
        }

        .trend-negative {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Charts Section */
        .charts-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .chart-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            max-width: 100%;
            overflow: hidden;
        }

        .chart-card h3 {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 20px;
        }

        .chart-card canvas {
            max-width: 100%;
            max-height: 300px;
            width: 100% !important;
            height: auto !important;
        }

        /* Tables */
        .table-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
        }

        .table-card h3 {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 20px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .data-table th {
            font-weight: 600;
            color: #475569;
            font-size: 14px;
        }

        .data-table td {
            color: #334155;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 240px;
            }
            .main-content {
                margin-left: 240px;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.active {
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
            .charts-section {
                grid-template-columns: 1fr;
            }
        }

        /* Color schemes for stats */
        .stat-schools .stat-icon { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; }
        .stat-users .stat-icon { background: linear-gradient(135deg, #10b981, #059669); color: white; }
        .stat-students .stat-icon { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
        .stat-teachers .stat-icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; }
        .stat-activity .stat-icon { background: linear-gradient(135deg, #06b6d4, #0891b2); color: white; }
        .stat-security .stat-icon { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        .stat-database .stat-icon { background: linear-gradient(135deg, #6b7280, #4b5563); color: white; }
        .stat-growth .stat-icon { background: linear-gradient(135deg, #84cc16, #65a30d); color: white; }

        /* CSS Data Visualizations */
        .system-overview {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .progress-ring-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 20px;
            justify-items: center;
        }

        .progress-ring {
            position: relative;
            width: 100px;
            height: 100px;
        }

        .progress-ring-circle {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: conic-gradient(
                var(--ring-color, #3b82f6) 0deg,
                var(--ring-color, #3b82f6) calc(var(--percentage) * 3.6deg),
                #e2e8f0 calc(var(--percentage) * 3.6deg),
                #e2e8f0 360deg
            );
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .progress-ring-fill {
            width: 80%;
            height: 80%;
            background: white;
            border-radius: 50%;
            position: absolute;
            top: 10%;
            left: 10%;
        }

        .progress-ring-text {
            position: absolute;
            text-align: center;
            font-weight: 700;
            color: #1e293b;
        }

        .progress-ring-text .percentage {
            font-size: 18px;
            line-height: 1;
        }

        .progress-ring-text .label {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }

        /* Progress ring colors */
        .progress-ring.schools { --ring-color: #3b82f6; }
        .progress-ring.users { --ring-color: #10b981; }
        .progress-ring.students { --ring-color: #f59e0b; }
        .progress-ring.teachers { --ring-color: #8b5cf6; }

        .system-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 16px;
            text-align: center;
        }

        .stat-item {
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .stat-item .stat-number {
            display: block;
            font-size: 20px;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .stat-item .stat-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .growth-trends {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .progress-bars {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .progress-bar-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .progress-bar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
        }

        .progress-bar-header .month {
            color: #1e293b;
        }

        .progress-bar-header .value {
            color: #3b82f6;
            font-weight: 700;
        }

        .progress-bar-container {
            width: 100%;
            height: 12px;
            background: #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #1d4ed8);
            border-radius: 6px;
            transition: width 1s ease-out;
            position: relative;
        }

        .progress-bar-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, rgba(255,255,255,0.3), rgba(255,255,255,0));
            border-radius: 6px;
        }

        .progress-percentage {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-align: right;
        }

        .growth-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .summary-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .summary-item .summary-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-item .summary-value {
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
        }

        /* Mobile adjustments for data visualizations */
        @media (max-width: 768px) {
            .progress-ring-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }

            .progress-ring {
                width: 80px;
                height: 80px;
            }

            .progress-ring-text .percentage {
                font-size: 14px;
            }

            .progress-ring-text .label {
                font-size: 10px;
            }

            .system-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .stat-item {
                padding: 12px;
            }

            .stat-item .stat-number {
                font-size: 16px;
            }

            .stat-item .stat-label {
                font-size: 11px;
            }

            .growth-summary {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }

        @media (max-width: 480px) {
            .progress-ring-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .progress-ring {
                width: 70px;
                height: 70px;
            }

            .progress-ring-text .percentage {
                font-size: 12px;
            }

            .progress-ring-text .label {
                font-size: 9px;
            }

            .system-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            .stat-item {
                padding: 8px;
            }

            .stat-item .stat-number {
                font-size: 14px;
            }

            .stat-item .stat-label {
                font-size: 10px;
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
                        <a href="dashboard.php" class="nav-link active">
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
                        <a href="support_tickets.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-life-ring"></i></span>
                            <span>Support Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subscription_plans.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-tags"></i></span>
                            <span>Subscription Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subscription_requests.php" class="nav-link">
                            <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                            <span>Subscription Requests</span>
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
                        <a href="audit_logs.php" class="nav-link">
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
                <h1>System Dashboard</h1>
                <div class="header-actions">
                    <div class="admin-info">
                        <div class="admin-avatar">
                            <?php echo strtoupper(substr($super_admin['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($super_admin['full_name']); ?></div>
                            <div style="font-size: 12px; color: #64748b;">Super Administrator</div>
                        </div>
                    </div>
                    <a href="logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card stat-schools">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-school"></i>
                        </div>
                        <div class="stat-trend trend-positive">
                            +<?php echo $stats['new_schools_week']; ?> this week
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_schools']); ?></div>
                    <div class="stat-label">Active Schools</div>
                </div>

                <div class="stat-card stat-users">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                    <div class="stat-label">Total Users</div>
                </div>

                <div class="stat-card stat-students">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_students']); ?></div>
                    <div class="stat-label">Total Students</div>
                </div>

                <div class="stat-card stat-teachers">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_teachers']); ?></div>
                    <div class="stat-label">Total Teachers</div>
                </div>

                <div class="stat-card stat-activity">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-activity"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['recent_activity']); ?></div>
                    <div class="stat-label">Activities (24h)</div>
                </div>

                <div class="stat-card stat-security">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="stat-trend trend-negative">
                            <?php echo $stats['failed_logins']; ?> attempts
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['failed_logins']; ?></div>
                    <div class="stat-label">Failed Logins (24h)</div>
                </div>

                <div class="stat-card stat-database">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-database"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['db_size'], 1); ?>MB</div>
                    <div class="stat-label">Database Size</div>
                </div>

                <div class="stat-card stat-growth">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_schools'] * 100); ?>%</div>
                    <div class="stat-label">System Health</div>
                </div>
            </div>

            <!-- Data Visualization Section -->
            <div class="charts-section">
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> System Overview</h3>
                    <div class="system-overview">
                        <?php
                        $total_entities = $stats['total_schools'] + $stats['total_users'] + $stats['total_students'] + $stats['total_teachers'];
                        if ($total_entities > 0) {
                            $schools_percent = round(($stats['total_schools'] / $total_entities) * 100);
                            $users_percent = round(($stats['total_users'] / $total_entities) * 100);
                            $students_percent = round(($stats['total_students'] / $total_entities) * 100);
                            $teachers_percent = round(($stats['total_teachers'] / $total_entities) * 100);
                        } else {
                            $schools_percent = $users_percent = $students_percent = $teachers_percent = 0;
                        }
                        ?>
                        <div class="progress-ring-container">
                            <div class="progress-ring schools" style="--percentage: <?php echo $schools_percent; ?>%">
                                <div class="progress-ring-circle">
                                    <div class="progress-ring-fill"></div>
                                </div>
                                <div class="progress-ring-text">
                                    <div class="percentage"><?php echo $schools_percent; ?>%</div>
                                    <div class="label">Schools</div>
                                </div>
                            </div>
                            <div class="progress-ring users" style="--percentage: <?php echo $users_percent; ?>%">
                                <div class="progress-ring-circle">
                                    <div class="progress-ring-fill"></div>
                                </div>
                                <div class="progress-ring-text">
                                    <div class="percentage"><?php echo $users_percent; ?>%</div>
                                    <div class="label">Users</div>
                                </div>
                            </div>
                            <div class="progress-ring students" style="--percentage: <?php echo $students_percent; ?>%">
                                <div class="progress-ring-circle">
                                    <div class="progress-ring-fill"></div>
                                </div>
                                <div class="progress-ring-text">
                                    <div class="percentage"><?php echo $students_percent; ?>%</div>
                                    <div class="label">Students</div>
                                </div>
                            </div>
                            <div class="progress-ring teachers" style="--percentage: <?php echo $teachers_percent; ?>%">
                                <div class="progress-ring-circle">
                                    <div class="progress-ring-fill"></div>
                                </div>
                                <div class="progress-ring-text">
                                    <div class="percentage"><?php echo $teachers_percent; ?>%</div>
                                    <div class="label">Teachers</div>
                                </div>
                            </div>
                        </div>
                        <div class="system-stats">
                            <div class="stat-item">
                                <span class="stat-number"><?php echo number_format($stats['total_schools']); ?></span>
                                <span class="stat-label">Schools</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo number_format($stats['total_users']); ?></span>
                                <span class="stat-label">Users</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo number_format($stats['total_students']); ?></span>
                                <span class="stat-label">Students</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo number_format($stats['total_teachers']); ?></span>
                                <span class="stat-label">Teachers</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="chart-card">
                    <h3><i class="fas fa-chart-bar"></i> Growth Trends</h3>
                    <div class="growth-trends">
                        <?php
                        $maxSchools = max(10, $stats['total_schools']);
                        $growthData = [
                            ['month' => 'Jan', 'value' => min(1, $maxSchools * 0.1), 'percentage' => min(10, ($maxSchools * 0.1) / $maxSchools * 100)],
                            ['month' => 'Feb', 'value' => min(2, $maxSchools * 0.2), 'percentage' => min(20, ($maxSchools * 0.2) / $maxSchools * 100)],
                            ['month' => 'Mar', 'value' => min($maxSchools * 0.6, $maxSchools - 2), 'percentage' => min(60, (($maxSchools * 0.6) / $maxSchools) * 100)],
                            ['month' => 'Apr', 'value' => min($maxSchools * 0.8, $maxSchools), 'percentage' => min(80, (($maxSchools * 0.8) / $maxSchools) * 100)],
                            ['month' => 'May', 'value' => min($maxSchools * 0.9, $maxSchools), 'percentage' => min(90, (($maxSchools * 0.9) / $maxSchools) * 100)],
                            ['month' => 'Jun', 'value' => $maxSchools, 'percentage' => 100]
                        ];
                        ?>
                        <div class="progress-bars">
                            <?php foreach ($growthData as $data): ?>
                                <div class="progress-bar-item">
                                    <div class="progress-bar-header">
                                        <span class="month"><?php echo $data['month']; ?></span>
                                        <span class="value"><?php echo number_format($data['value']); ?></span>
                                    </div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar-fill" style="width: <?php echo $data['percentage']; ?>%"></div>
                                    </div>
                                    <div class="progress-percentage"><?php echo round($data['percentage']); ?>%</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="growth-summary">
                            <div class="summary-item">
                                <span class="summary-label">Current Total:</span>
                                <span class="summary-value"><?php echo number_format($stats['total_schools']); ?> Schools</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Growth Rate:</span>
                                <span class="summary-value"><?php echo $stats['new_schools_week'] > 0 ? '+' . $stats['new_schools_week'] . ' this week' : 'Stable'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Schools -->
            <div class="table-card">
                <h3><i class="fas fa-school"></i> Recent Schools</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>School Name</th>
                            <th>Code</th>
                            <th>Users</th>
                            <th>Students</th>
                            <th>Classes</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($schools_overview)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: #64748b;">
                                    No schools found. <a href="manage_schools.php">Create your first school</a>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($schools_overview as $school): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($school['school_name']); ?></td>
                                    <td><code><?php echo htmlspecialchars($school['school_code']); ?></code></td>
                                    <td><?php echo number_format($school['user_count']); ?></td>
                                    <td><?php echo number_format($school['student_count']); ?></td>
                                    <td><?php echo number_format($school['class_count']); ?></td>
                                    <td>
                                        <span style="padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600;
                                            background: <?php echo $school['status'] === 'active' ? '#dcfce7' : '#fee2e2'; ?>;
                                            color: <?php echo $school['status'] === 'active' ? '#166534' : '#991b1b'; ?>;">
                                            <?php echo ucfirst($school['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($school['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Activity -->
            <div class="table-card">
                <h3><i class="fas fa-history"></i> Recent System Activity</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Resource</th>
                            <th>Status</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_activities)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #64748b;">
                                    No recent activity found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_activities as $activity): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($activity['full_name'] ?? 'System'); ?></td>
                                    <td><?php echo htmlspecialchars($activity['action']); ?></td>
                                    <td><?php echo htmlspecialchars($activity['resource_type'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span style="padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600;
                                            background: <?php echo $activity['status'] === 'success' ? '#dcfce7' : '#fee2e2'; ?>;
                                            color: <?php echo $activity['status'] === 'success' ? '#166534' : '#991b1b'; ?>;">
                                            <?php echo ucfirst($activity['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, H:i', strtotime($activity['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
        // Mobile menu toggle
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }

        // Animate progress bars on scroll
        document.addEventListener('DOMContentLoaded', function() {
            const progressBars = document.querySelectorAll('.progress-bar-fill');

            const animateProgressBars = () => {
                progressBars.forEach((bar, index) => {
                    setTimeout(() => {
                        bar.style.width = bar.style.width; // Trigger animation
                    }, index * 100);
                });
            };

            // Animate on load
            setTimeout(animateProgressBars, 500);
        });
    </script>
</body>
</html>
