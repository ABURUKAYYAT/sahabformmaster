<?php
require_once 'auth_check.php';
require_once '../config/db.php';

// Get current super admin info
$super_admin = get_current_super_admin();

// Log analytics access
log_super_action('view_analytics', 'system', null, 'Accessed analytics dashboard');

// Get analytics data
$analytics = [];

// 1. User growth trends (last 12 months)
$user_growth = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
               COUNT(*) as count,
               role
        FROM users
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m'), role
        ORDER BY month
    ");
    $stmt->execute();
    $user_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process data for chart
    $months = [];
    $role_data = ['super_admin' => [], 'principal' => [], 'vice_principal' => [], 'teacher' => [], 'admin_staff' => [], 'student' => []];

    foreach ($user_trends as $trend) {
        if (!in_array($trend['month'], $months)) {
            $months[] = $trend['month'];
        }
        $role_data[$trend['role']][] = (int)$trend['count'];
    }

    $analytics['user_growth'] = [
        'months' => $months,
        'data' => $role_data
    ];
} catch (Exception $e) {
    $analytics['user_growth'] = ['months' => [], 'data' => []];
}

// 2. Student enrollment trends
$student_growth = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
               COUNT(*) as count
        FROM students
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month
    ");
    $stmt->execute();
    $enrollment_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $student_months = [];
    $student_counts = [];
    foreach ($enrollment_data as $data) {
        $student_months[] = $data['month'];
        $student_counts[] = (int)$data['count'];
    }

    $analytics['student_growth'] = [
        'months' => $student_months,
        'counts' => $student_counts
    ];
} catch (Exception $e) {
    $analytics['student_growth'] = ['months' => [], 'counts' => []];
}

// 3. Attendance analytics (last 30 days)
$attendance_stats = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
            COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent,
            COUNT(CASE WHEN status = 'late' THEN 1 END) as late,
            COUNT(*) as total
        FROM attendance
        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $attendance_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $attendance_stats = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0];
}

// 4. School performance metrics
$school_metrics = [];
try {
    $stmt = $pdo->query("
        SELECT s.school_name,
               COUNT(DISTINCT st.id) as student_count,
               COUNT(DISTINCT u.id) as teacher_count,
               AVG(r.score) as avg_score,
               COUNT(DISTINCT lp.id) as lesson_plans_count
        FROM schools s
        LEFT JOIN students st ON s.id = st.school_id
        LEFT JOIN users u ON s.id = u.school_id AND u.role IN ('teacher', 'principal', 'vice_principal')
        LEFT JOIN results r ON s.id = r.school_id
        LEFT JOIN lesson_plans lp ON s.id = lp.school_id
        WHERE s.status = 'active'
        GROUP BY s.id, s.school_name
        ORDER BY student_count DESC
        LIMIT 10
    ");
    $school_metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $school_metrics = [];
}

// 5. Access logs analytics (last 7 days)
$access_trends = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) as date,
               COUNT(*) as total_access,
               COUNT(DISTINCT user_id) as unique_users
        FROM access_logs
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date
    ");
    $stmt->execute();
    $access_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $access_dates = [];
    $access_counts = [];
    $unique_users = [];
    foreach ($access_data as $data) {
        $access_dates[] = date('M j', strtotime($data['date']));
        $access_counts[] = (int)$data['total_access'];
        $unique_users[] = (int)$data['unique_users'];
    }

    $analytics['access_trends'] = [
        'dates' => $access_dates,
        'total_access' => $access_counts,
        'unique_users' => $unique_users
    ];
} catch (Exception $e) {
    $analytics['access_trends'] = ['dates' => [], 'total_access' => [], 'unique_users' => []];
}

// 6. Subject popularity
$subject_stats = [];
try {
    $stmt = $pdo->query("
        SELECT sub.subject_name,
               COUNT(DISTINCT sa.student_id) as enrolled_students,
               COUNT(DISTINCT sa.teacher_id) as assigned_teachers,
               AVG(r.score) as avg_performance
        FROM subjects sub
        LEFT JOIN subject_assignments sa ON sub.id = sa.subject_id
        LEFT JOIN results r ON sub.id = r.subject_id
        GROUP BY sub.id, sub.subject_name
        ORDER BY enrolled_students DESC
        LIMIT 10
    ");
    $subject_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $subject_stats = [];
}

// 7. System health metrics
$system_health = [];
try {
    // Total active users today
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM access_logs WHERE DATE(created_at) = CURDATE() AND user_id IS NOT NULL");
    $stmt->execute();
    $system_health['active_today'] = $stmt->fetchColumn();

    // Failed logins today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM access_logs WHERE DATE(created_at) = CURDATE() AND action = 'login' AND status = 'denied'");
    $stmt->execute();
    $system_health['failed_logins'] = $stmt->fetchColumn();

    // Schools added this month
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM schools WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stmt->execute();
    $system_health['schools_this_month'] = $stmt->fetchColumn();

    // Average session duration (simplified)
    $system_health['avg_session_duration'] = 'N/A'; // Would need more complex tracking

} catch (Exception $e) {
    $system_health = [
        'active_today' => 0,
        'failed_logins' => 0,
        'schools_this_month' => 0,
        'avg_session_duration' => 'N/A'
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics | SahabFormMaster</title>
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
        .stat-users .stat-icon { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; }
        .stat-students .stat-icon { background: linear-gradient(135deg, #10b981, #059669); color: white; }
        .stat-attendance .stat-icon { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
        .stat-schools .stat-icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; }
        .stat-access .stat-icon { background: linear-gradient(135deg, #06b6d4, #0891b2); color: white; }
        .stat-subjects .stat-icon { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        .stat-health .stat-icon { background: linear-gradient(135deg, #84cc16, #65a30d); color: white; }
        .stat-performance .stat-icon { background: linear-gradient(135deg, #f97316, #ea580c); color: white; }
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
                        <a href="analytics.php" class="nav-link active">
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
                <h1><i class="fas fa-chart-line"></i> System Analytics</h1>
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

            <!-- System Health Stats -->
            <div class="stats-grid">
                <div class="stat-card stat-health">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($system_health['active_today']); ?></div>
                    <div class="stat-label">Active Users Today</div>
                </div>

                <div class="stat-card stat-users">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-trend trend-negative">
                            <?php echo $system_health['failed_logins']; ?> attempts
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($system_health['failed_logins']); ?></div>
                    <div class="stat-label">Failed Logins Today</div>
                </div>

                <div class="stat-card stat-schools">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($system_health['schools_this_month']); ?></div>
                    <div class="stat-label">Schools Added This Month</div>
                </div>

                <div class="stat-card stat-attendance">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                    </div>
                    <div class="stat-value">
                        <?php
                        $attendance_rate = $attendance_stats['total'] > 0 ?
                            round(($attendance_stats['present'] / $attendance_stats['total']) * 100, 1) : 0;
                        echo $attendance_rate . '%';
                        ?>
                    </div>
                    <div class="stat-label">Overall Attendance Rate (30d)</div>
                </div>
            </div>

            <!-- Growth Charts -->
            <div class="charts-section">
                <div class="chart-card">
                    <h3><i class="fas fa-users"></i> User Growth Trends</h3>
                    <canvas id="userGrowthChart"></canvas>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-user-graduate"></i> Student Enrollment Trends</h3>
                    <canvas id="studentGrowthChart"></canvas>
                </div>
            </div>

            <!-- Activity and Performance Charts -->
            <div class="charts-section">
                <div class="chart-card">
                    <h3><i class="fas fa-activity"></i> System Activity (Last 7 Days)</h3>
                    <canvas id="activityChart"></canvas>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie"></i> Attendance Distribution (30 Days)</h3>
                    <canvas id="attendanceChart"></canvas>
                </div>
            </div>

            <!-- School Performance Table -->
            <div class="table-card">
                <h3><i class="fas fa-trophy"></i> Top Performing Schools</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>School Name</th>
                            <th>Students</th>
                            <th>Teachers</th>
                            <th>Avg Score</th>
                            <th>Lesson Plans</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($school_metrics)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #64748b;">
                                    No school data available
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($school_metrics as $school): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($school['school_name']); ?></td>
                                    <td><?php echo number_format($school['student_count']); ?></td>
                                    <td><?php echo number_format($school['teacher_count']); ?></td>
                                    <td><?php echo $school['avg_score'] ? number_format($school['avg_score'], 1) : 'N/A'; ?></td>
                                    <td><?php echo number_format($school['lesson_plans_count']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Subject Analytics Table -->
            <div class="table-card">
                <h3><i class="fas fa-book"></i> Popular Subjects</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Subject Name</th>
                            <th>Enrolled Students</th>
                            <th>Assigned Teachers</th>
                            <th>Average Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($subject_stats)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: #64748b;">
                                    No subject data available
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($subject_stats as $subject): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                    <td><?php echo number_format($subject['enrolled_students']); ?></td>
                                    <td><?php echo number_format($subject['assigned_teachers']); ?></td>
                                    <td><?php echo $subject['avg_performance'] ? number_format($subject['avg_performance'], 1) : 'N/A'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // User Growth Chart
            const userGrowthCtx = document.getElementById('userGrowthChart').getContext('2d');
            const userGrowthData = <?php echo json_encode($analytics['user_growth']); ?>;
            new Chart(userGrowthCtx, {
                type: 'line',
                data: {
                    labels: userGrowthData.months,
                    datasets: [
                        {
                            label: 'Teachers',
                            data: userGrowthData.data.teacher,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4,
                            fill: false
                        },
                        {
                            label: 'Students',
                            data: userGrowthData.data.student,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4,
                            fill: false
                        },
                        {
                            label: 'Principals',
                            data: userGrowthData.data.principal,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            tension: 0.4,
                            fill: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    aspectRatio: 2,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return Math.round(value);
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    }
                }
            });

            // Student Growth Chart
            const studentGrowthCtx = document.getElementById('studentGrowthChart').getContext('2d');
            const studentGrowthData = <?php echo json_encode($analytics['student_growth']); ?>;
            new Chart(studentGrowthCtx, {
                type: 'bar',
                data: {
                    labels: studentGrowthData.months,
                    datasets: [{
                        label: 'New Students',
                        data: studentGrowthData.counts,
                        backgroundColor: '#10b981',
                        borderColor: '#059669',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    aspectRatio: 2,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return Math.round(value);
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });

            // Activity Chart
            const activityCtx = document.getElementById('activityChart').getContext('2d');
            const activityData = <?php echo json_encode($analytics['access_trends']); ?>;
            new Chart(activityCtx, {
                type: 'line',
                data: {
                    labels: activityData.dates,
                    datasets: [
                        {
                            label: 'Total Access',
                            data: activityData.total_access,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4,
                            fill: true,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Unique Users',
                            data: activityData.unique_users,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            tension: 0.4,
                            fill: true,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    aspectRatio: 2,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Total Access'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Unique Users'
                            },
                            grid: {
                                drawOnChartArea: false,
                            }
                        }
                    }
                }
            });

            // Attendance Distribution Chart
            const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
            const attendanceStats = <?php echo json_encode($attendance_stats); ?>;
            new Chart(attendanceCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Present', 'Absent', 'Late'],
                    datasets: [{
                        data: [
                            attendanceStats.present || 0,
                            attendanceStats.absent || 0,
                            attendanceStats.late || 0
                        ],
                        backgroundColor: [
                            '#10b981',
                            '#ef4444',
                            '#f59e0b'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    aspectRatio: 1.5,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        });

        // Mobile menu toggle
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }
    </script>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
