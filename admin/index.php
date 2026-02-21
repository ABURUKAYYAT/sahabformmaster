<?php

session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if principal is logged in and get school_id
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_name = $_SESSION['full_name'];
$current_school_id = require_school_auth();

// Fetch dynamic statistics (each query isolated so one failure doesn't zero everything)
$total_students = $new_students = $total_teachers = $total_classes = $total_results = $pending_complaints = $unpaid_fees = 0;
$attendance_stats = ['total_records' => 0, 'attendance_rate' => 0];
$fee_stats = ['total_collected' => 0, 'total_outstanding' => 0];
$recent_activities = [];

try {
    $query = "SELECT COUNT(*) as total_students FROM students WHERE is_active = 1";
    $params = [];
    $query = add_school_filter($query, $params);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $total_students = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Admin dashboard total_students error: " . $e->getMessage());
}

try {
    $query = "SELECT COUNT(*) as new_students FROM students WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $params = [];
    $query = add_school_filter($query, $params);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $new_students = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Admin dashboard new_students error: " . $e->getMessage());
}

try {
    $query = "SELECT COUNT(*) as total_teachers FROM users WHERE role IN ('teacher', 'principal') AND is_active = 1";
    $params = [];
    $query = add_school_filter($query, $params);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $total_teachers = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Admin dashboard total_teachers error: " . $e->getMessage());
}

try {
    $query = "SELECT COUNT(*) as total_classes FROM classes";
    $params = [];
    $query = add_school_filter($query, $params);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $total_classes = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Admin dashboard total_classes error: " . $e->getMessage());
}

try {
    $query = "
        SELECT
            COUNT(*) as total_records,
            ROUND(AVG(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100, 1) as attendance_rate
        FROM attendance
        WHERE date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ";
    $params = [];
    $query = add_school_filter($query, $params);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $attendance_stats = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Admin dashboard attendance_stats error: " . $e->getMessage());
}

try {
    $query = "SELECT COUNT(*) as total_results FROM results";
    $params = [];
    $query = add_school_filter($query, $params);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $total_results = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Admin dashboard total_results error: " . $e->getMessage());
}

try {
    $query = "
        SELECT
            SUM(amount_paid) as total_collected,
            SUM(total_amount - amount_paid) as total_outstanding
        FROM student_payments
        WHERE academic_year = YEAR(CURDATE())
    ";
    $params = [];
    $query = add_school_filter($query, $params);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $fee_stats = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Admin dashboard fee_stats error: " . $e->getMessage());
}

try {
    $query1 = "SELECT 'student' as type, full_name, admission_no, created_at FROM students WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $params1 = [];
    $query1 = add_school_filter($query1, $params1);

    $query2 = "
        SELECT 'result' as type, CONCAT(s.full_name, ' - ', sub.subject_name) as full_name, s.admission_no, r.created_at
        FROM results r
        JOIN students s ON r.student_id = s.id
        JOIN subjects sub ON r.subject_id = sub.id
        WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ";
    $params2 = [];
    $query2 = add_school_filter($query2, $params2);

    $stmt = $pdo->prepare("{$query1} UNION ALL {$query2} ORDER BY created_at DESC LIMIT 10");
    $stmt->execute(array_merge($params1, $params2));
    $recent_activities = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Admin dashboard recent_activities error: " . $e->getMessage());
}

try {
    $query = "SELECT COUNT(*) as pending_complaints FROM results_complaints WHERE status = 'pending'";
    $params = [];
    $query = add_school_filter($query, $params);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $pending_complaints = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Admin dashboard pending_complaints error: " . $e->getMessage());
}

try {
    $query = "SELECT COUNT(*) as unpaid_fees FROM student_payments WHERE balance > 0";
    $params = [];
    $query = add_school_filter($query, $params);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $unpaid_fees = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Admin dashboard unpaid_fees error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Principal Dashboard | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1e3a8a;
            --primary-light: #dbeafe;
            --accent-blue: #38bdf8;
        }

        .content-header {
            background: #ffffff;
            border: 1px solid rgba(37, 99, 235, 0.12);
            box-shadow: 0 12px 30px rgba(30, 58, 138, 0.08);
        }

        .card {
            border: 1px solid rgba(37, 99, 235, 0.08);
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.08);
        }

        .chart-card,
        .stat-box,
        .quick-action-card,
        .activity-item {
            border: 1px solid rgba(37, 99, 235, 0.1);
            box-shadow: 0 10px 22px rgba(30, 58, 138, 0.06);
        }

        .progress-bar {
            background: linear-gradient(90deg, var(--primary-color), var(--accent-blue));
        }

        .notification-badge {
            background: var(--primary-color);
        }
    </style>
</head>
<body>

    <!-- Mobile Navigation Component -->
    <?php include '../includes/mobile_navigation.php'; ?>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Principal Portal</p>
                    </div>
                </div>
            </div>

            <!-- Principal Info and Logout -->
            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Principal</p>
                    <span class="principal-name"><?php echo htmlspecialchars($principal_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <span class="logout-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Navigation</h3>
                <button class="sidebar-close" id="sidebarClose">✕</button>
            </div>
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
                            <span class="nav-icon">📰</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_diary.php" class="nav-link">
                            <span class="nav-icon">📔</span>
                            <span class="nav-text">School Diary</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Students Registration</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students-evaluations.php" class="nav-link">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Students Evaluations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_class.php" class="nav-link">
                            <span class="nav-icon">🎓</span>
                            <span class="nav-text">Manage Classes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_results.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Manage Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="content_coverage.php" class="nav-link">
                            <span class="nav-icon">✅</span>
                            <span class="nav-text">Content Coverage</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage-school.php" class="nav-link">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subjects.php" class="nav-link">
                            <span class="nav-icon">📖</span>
                            <span class="nav-text">Subjects</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_user.php" class="nav-link">
                            <span class="nav-icon">👤</span>
                            <span class="nav-text">Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="visitors.php" class="nav-link">
                            <span class="nav-icon">🚶</span>
                            <span class="nav-text">Visitors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_timebook.php" class="nav-link">
                            <span class="nav-icon">⏰</span>
                            <span class="nav-text">Teachers Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permissions.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Permissions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_attendance.php" class="nav-link">
                            <span class="nav-icon">📋</span>
                            <span class="nav-text">Attendance Register</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="payments_dashboard.php" class="nav-link">
                            <span class="nav-icon">💰</span>
                            <span class="nav-text">School Fees</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="sessions.php" class="nav-link">
                            <span class="nav-icon">📅</span>
                            <span class="nav-text">School Sessions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school_calendar.php" class="nav-link">
                            <span class="nav-icon">🗓️</span>
                            <span class="nav-text">School Calendar</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="applicants.php" class="nav-link">
                            <span class="nav-icon">📄</span>
                            <span class="nav-text">Applicants</span>
                        </a>
                    </li>
                </ul>
            </nav> 
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>Welcome back, <?php echo htmlspecialchars($principal_name); ?>! ??</h2>
                    <p>Here's what's happening with your school today</p>
                </div>
                <div class="header-stats">
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo number_format($total_students); ?></span>
                        <span class="quick-stat-label">Students</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo number_format($total_teachers); ?></span>
                        <span class="quick-stat-label">Teachers</span>
                    </div>
                    <div class="quick-stat">
                        <span class="quick-stat-value"><?php echo number_format($total_classes); ?></span>
                        <span class="quick-stat-label">Classes</span>
                    </div>
                </div>
            </div>

            <!-- Dashboard Cards -->
            <div class="dashboard-cards">
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">👥</div>
                    </div>
                    <div class="card-content">
                        <h3>Total Students</h3>
                        <p class="card-value"><?php echo number_format($total_students); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">+<?php echo number_format($new_students); ?> this month</span>
                            <a href="students.php" class="card-link">View All →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">👨‍🏫</div>
                    </div>
                    <div class="card-content">
                        <h3>Total Teachers</h3>
                        <p class="card-value"><?php echo number_format($total_teachers); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Active Staff</span>
                            <a href="manage_user.php" class="card-link">Manage →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📊</div>
                    </div>
                    <div class="card-content">
                        <h3>Attendance Rate</h3>
                        <p class="card-value"><?php echo number_format($attendance_stats['attendance_rate'] ?? 0, 1); ?>%</p>
                        <div class="card-footer">
                            <span class="card-badge">Last 30 days</span>
                            <a href="manage_attendance.php" class="card-link">Monitor →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📈</div>
                    </div>
                    <div class="card-content">
                        <h3>Results Processed</h3>
                        <p class="card-value"><?php echo number_format($total_results); ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Academic Records</span>
                            <a href="manage_results.php" class="card-link">View →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-5">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">💰</div>
                    </div>
                    <div class="card-content">
                        <h3>Fees Collected</h3>
                        <p class="card-value">₦<?php echo number_format(($fee_stats['total_collected'] ?? 0) / 1000000, 1); ?>M</p>
                        <div class="card-footer">
                            <span class="card-badge">₦<?php echo number_format(($fee_stats['total_outstanding'] ?? 0) / 1000, 0); ?>K outstanding</span>
                            <a href="payments_dashboard.php" class="card-link">Monitor →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-6">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">⚠️</div>
                    </div>
                    <div class="card-content">
                        <h3>System Alerts</h3>
                        <p class="card-value"><?php echo number_format($pending_complaints + $unpaid_fees); ?></p>
                        <div class="card-footer">
                            <span class="card-badge"><?php echo $pending_complaints; ?> complaints</span>
                            <a href="manage_results.php?tab=complaints" class="card-link">Review →</a>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-7">
                    <div class="card-icon-wrapper">
                        <div class="card-icon">📱</div>
                    </div>
                    <div class="card-content">
                        <h3>VTU Services</h3>
                        <p class="card-value">Available</p>
                        <div class="card-footer">
                            <span class="card-badge">Buy Now</span>
                            <a href="https://www.sahabdata.com.ng" target="_blank" class="card-link">Shop VTU →</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Section -->
            <div class="stats-section">
                <div class="section-header">
                    <h3>📊 School Analytics</h3>
                    <span class="section-badge">Real-time</span>
                </div>
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-icon">🎓</div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo number_format($total_classes); ?></span>
                            <span class="stat-label">Classes</span>
                        </div>
                        <div class="stat-progress">
                            <div class="progress-bar" style="width: <?php echo min(100, ($total_classes / 20) * 100); ?>%;"></div>
                        </div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-icon">📊</div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo number_format($attendance_stats['attendance_rate'] ?? 0, 1); ?>%</span>
                            <span class="stat-label">Attendance Rate</span>
                        </div>
                        <div class="stat-progress">
                            <div class="progress-bar" style="width: <?php echo $attendance_stats['attendance_rate'] ?? 0; ?>%;"></div>
                        </div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-icon">📈</div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo number_format($total_results); ?></span>
                            <span class="stat-label">Results</span>
                        </div>
                        <div class="stat-progress">
                            <div class="progress-bar" style="width: <?php echo min(100, ($total_results / 1000) * 100); ?>%;"></div>
                        </div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-icon">⚠️</div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo number_format($pending_complaints + $unpaid_fees); ?></span>
                            <span class="stat-label">Alerts</span>
                        </div>
                        <div class="stat-progress">
                            <div class="progress-bar progress-warning" style="width: <?php
                                $total_alerts = $pending_complaints + $unpaid_fees;
                                echo $total_alerts > 0 ? min(100, ($total_alerts / 50) * 100) : 0;
                            ?>%;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-section">
                <div class="section-header">
                    <h3>📈 Performance Analytics</h3>
                    <span class="section-badge">Interactive</span>
                </div>
                <div class="charts-grid">
                    <div class="chart-card">
                        <h4>Student Enrollment Trend</h4>
                        <canvas id="enrollmentChart" role="img" aria-label="Student enrollment trend chart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h4>Attendance Overview</h4>
                        <canvas id="attendanceChart" role="img" aria-label="Attendance overview chart"></canvas>
                    </div>
                    <div class="chart-card">
                        <h4>Fee Collection Status</h4>
                        <canvas id="feesChart" role="img" aria-label="Fee collection status chart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Section -->
            <div class="quick-actions-section">
                <div class="section-header">
                    <h3>⚡ Quick Actions</h3>
                    <span class="section-badge">Most Used</span>
                </div>
                <div class="quick-actions-grid">
                    <a href="../helpers/generate-excel-export.php?export_type=students" class="quick-action-card">
                        <i class="fas fa-file-excel"></i>
                        <span>Export Students</span>
                    </a>
                    <a href="../helpers/generate-excel-export.php?export_type=results" class="quick-action-card">
                        <i class="fas fa-chart-bar"></i>
                        <span>Export Results</span>
                    </a>
                    <a href="../helpers/generate-excel-export.php?export_type=payments" class="quick-action-card">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Export Payments</span>
                    </a>
                    <a href="manage_results.php?tab=complaints" class="quick-action-card">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Review Complaints</span>
                        <?php if ($pending_complaints > 0): ?>
                            <span class="notification-badge"><?php echo $pending_complaints; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="schoolnews.php" class="quick-action-card">
                        <i class="fas fa-newspaper"></i>
                        <span>Publish News</span>
                    </a>
                    <a href="manage_attendance.php" class="quick-action-card">
                        <i class="fas fa-calendar-check"></i>
                        <span>Mark Attendance</span>
                    </a>
                    <a href="https://www.sahabdata.com.ng" target="_blank" class="quick-action-card">
                        <i class="fas fa-mobile-alt"></i>
                        <span>Buy VTU Services</span>
                    </a>
                </div>
            </div>

            <!-- Recent Activity Section -->
            <div class="activity-section">
                <div class="section-header">
                    <h3>⚡ Recent Activity</h3>
                    <a href="#" class="view-all-link">View All</a>
                </div>
                <div class="activity-list">
                    <?php if (!empty($recent_activities)): ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo $activity['type'] === 'student' ? 'activity-icon-success' : 'activity-icon-info'; ?>">
                                    <?php echo $activity['type'] === 'student' ? '👤' : '📊'; ?>
                                </div>
                                <div class="activity-content">
                                    <span class="activity-text">
                                        <?php if ($activity['type'] === 'student'): ?>
                                            New student registered - <strong><?php echo htmlspecialchars($activity['full_name']); ?></strong> (<?php echo htmlspecialchars($activity['admission_no']); ?>)
                                        <?php else: ?>
                                            Results uploaded - <strong><?php echo htmlspecialchars($activity['full_name']); ?></strong>
                                        <?php endif; ?>
                                    </span>
                                    <span class="activity-date"><?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="activity-item">
                            <div class="activity-icon activity-icon-info">📅</div>
                            <div class="activity-content">
                                <span class="activity-text">No recent activities in the last 7 days</span>
                                <span class="activity-date"><?php echo date('M j, Y'); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Smooth scroll for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Add active class on scroll
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Enrollment Chart
            const enrollmentCtx = document.getElementById('enrollmentChart').getContext('2d');
            new Chart(enrollmentCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [{
                        label: 'New Students',
                        data: [12, 19, 15, 25, 22, <?php echo $new_students; ?>],
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.12)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });

            // Attendance Chart
            const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
            new Chart(attendanceCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Present', 'Absent', 'Late'],
                    datasets: [{
                        data: [
                            <?php echo number_format($attendance_stats['attendance_rate'] ?? 0, 0); ?>,
                            <?php echo number_format(100 - ($attendance_stats['attendance_rate'] ?? 0), 0); ?>,
                            5
                        ],
                        backgroundColor: [
                            '#1d4ed8',
                            '#93c5fd',
                            '#38bdf8'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Fees Chart
            const feesCtx = document.getElementById('feesChart').getContext('2d');
            new Chart(feesCtx, {
                type: 'bar',
                data: {
                    labels: ['Collected', 'Outstanding'],
                    datasets: [{
                        label: 'Amount (₦)',
                        data: [
                            <?php echo ($fee_stats['total_collected'] ?? 0) / 1000; ?>,
                            <?php echo ($fee_stats['total_outstanding'] ?? 0) / 1000; ?>
                        ],
                        backgroundColor: [
                            '#2563eb',
                            '#93c5fd'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₦' + value + 'K';
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
        });

        // Quick Actions Functionality
        document.querySelectorAll('.quick-action-card').forEach(card => {
            card.addEventListener('click', function(e) {
                e.preventDefault();
                // Add loading state
                this.style.opacity = '0.7';
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Loading...</span>';

                // Reset after a short delay (simulating navigation)
                setTimeout(() => {
                    window.location.href = this.href;
                }, 500);
            });
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
