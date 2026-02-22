<?php
session_start();
require_once '../config/db.php';
require_once 'helpers.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'principal', 'vice_principal'])) {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$principal_name = $_SESSION['full_name'] ?? 'Admin';

$classes_sql = "SELECT id, class_name FROM classes WHERE school_id = :school_id ORDER BY class_name";
$classes_stmt = $pdo->prepare($classes_sql);
$classes_stmt->execute([':school_id' => $current_school_id]);
$classes = $classes_stmt->fetchAll();

$teachers_sql = "SELECT id, full_name FROM users WHERE school_id = :school_id AND role IN ('teacher', 'principal') ORDER BY full_name";
$teachers_stmt = $pdo->prepare($teachers_sql);
$teachers_stmt->execute([':school_id' => $current_school_id]);
$teachers = $teachers_stmt->fetchAll();

$selected_class = $_GET['class_id'] ?? 'all';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$selected_status = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$selected_teacher = $_GET['teacher_id'] ?? 'all';
$allowed_statuses = ['all', 'present', 'absent', 'late', 'leave'];

if (!in_array($selected_status, $allowed_statuses, true)) {
    $selected_status = 'all';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = date('Y-m-t');
}
if ($start_date > $end_date) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

$today = date('Y-m-d');
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

$today_stats_sql = "SELECT COUNT(*) as total_students, SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count, SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count, SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count, SUM(CASE WHEN a.status = 'leave' THEN 1 ELSE 0 END) as leave_count FROM students s LEFT JOIN attendance a ON s.id = a.student_id AND a.date = :today AND a.school_id = :attendance_school_id WHERE s.school_id = :student_school_id";
$today_stmt = $pdo->prepare($today_stats_sql);
$today_stmt->execute([':today' => $today, ':attendance_school_id' => $current_school_id, ':student_school_id' => $current_school_id]);
$today_stats = $today_stmt->fetch();

$at_risk_sql = "SELECT s.id, s.full_name, s.admission_no, c.class_name, COUNT(a.date) as total_days, SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_days, ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.date)) * 100, 1) as attendance_rate FROM students s JOIN classes c ON s.class_id = c.id LEFT JOIN attendance a ON s.id = a.student_id AND a.date BETWEEN :month_start AND :month_end AND a.school_id = :attendance_school_id WHERE s.school_id = :student_school_id GROUP BY s.id HAVING total_days > 0 AND attendance_rate < 80 ORDER BY attendance_rate ASC LIMIT 10";
$at_risk_stmt = $pdo->prepare($at_risk_sql);
$at_risk_stmt->execute([':month_start' => $month_start, ':month_end' => $month_end, ':attendance_school_id' => $current_school_id, ':student_school_id' => $current_school_id]);
$at_risk_students = $at_risk_stmt->fetchAll();

$perfect_sql = "SELECT s.id, s.full_name, s.admission_no, c.class_name, COUNT(a.date) as total_days, SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_days FROM students s JOIN classes c ON s.class_id = c.id LEFT JOIN attendance a ON s.id = a.student_id AND a.date BETWEEN :month_start AND :month_end AND a.school_id = :attendance_school_id WHERE s.school_id = :student_school_id GROUP BY s.id HAVING total_days > 0 AND present_days = total_days ORDER BY total_days DESC LIMIT 10";
$perfect_stmt = $pdo->prepare($perfect_sql);
$perfect_stmt->execute([':month_start' => $month_start, ':month_end' => $month_end, ':attendance_school_id' => $current_school_id, ':student_school_id' => $current_school_id]);
$perfect_students = $perfect_stmt->fetchAll();

$records_sql = "SELECT a.id, a.date, a.status, a.notes, a.recorded_by, s.id as student_id, s.full_name, s.admission_no, c.id as class_id, c.class_name, u.full_name as recorded_by_name FROM attendance a JOIN students s ON a.student_id = s.id JOIN classes c ON s.class_id = c.id LEFT JOIN users u ON a.recorded_by = u.id WHERE s.school_id = :student_school_id AND a.school_id = :attendance_school_id";
$records_params = [':student_school_id' => $current_school_id, ':attendance_school_id' => $current_school_id];

if ($selected_class !== 'all') {
    $records_sql .= " AND c.id = :class_id";
    $records_params[':class_id'] = $selected_class;
}

if (!empty($search_term)) {
    $records_sql .= " AND (s.full_name LIKE :search OR s.admission_no LIKE :search2)";
    $records_params[':search'] = "%$search_term%";
    $records_params[':search2'] = "%$search_term%";
}

if ($selected_status !== 'all') {
    $records_sql .= " AND a.status = :status";
    $records_params[':status'] = $selected_status;
}

if ($selected_teacher !== 'all') {
    $records_sql .= " AND a.recorded_by = :teacher_id";
    $records_params[':teacher_id'] = $selected_teacher;
}

$records_sql .= " AND a.date BETWEEN :start_date AND :end_date";
$records_params[':start_date'] = $start_date;
$records_params[':end_date'] = $end_date;

$records_sql .= " ORDER BY a.date DESC, c.class_name, s.full_name LIMIT 500";

$records_stmt = $pdo->prepare($records_sql);
$records_stmt->execute($records_params);
$attendance_records = $records_stmt->fetchAll();
$records_count = count($attendance_records);

$recent_sql = "SELECT a.id, a.date, a.status, a.notes, s.full_name, c.class_name FROM attendance a JOIN students s ON a.student_id = s.id JOIN classes c ON s.class_id = c.id WHERE s.school_id = :student_school_id AND a.school_id = :attendance_school_id ORDER BY a.id DESC LIMIT 10";
$recent_stmt = $pdo->prepare($recent_sql);
$recent_stmt->execute([':student_school_id' => $current_school_id, ':attendance_school_id' => $current_school_id]);
$recent_activity = $recent_stmt->fetchAll();

$today_rate = ($today_stats['total_students'] > 0) ? round(($today_stats['present_count'] / $today_stats['total_students']) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Attendance | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --primary-color: #4361ee; --success-color: #06d6a0; --warning-color: #ffd166; --danger-color: #ef476f; }
        .stat-card { position: relative; overflow: hidden; }
        .stat-card i { position: absolute; top: 15px; right: 15px; font-size: 2.5rem; opacity: 0.15; }
        .stat-card h3 { font-size: 0.9rem; font-weight: 500; color: #64748b; margin-bottom: 5px; }
        .stat-card .count { font-size: 2.5rem; font-weight: 700; color: #1e293b; line-height: 1; }
        .stat-card .stat-description { font-size: 0.8rem; color: #94a3b8; margin-top: 5px; }
        .present-card { background: linear-gradient(135deg, #06d6a0 0%, #05b88c 100%); }
        .present-card i, .present-card h3, .present-card .count, .present-card .stat-description { color: white; }
        .present-card i { opacity: 0.3; }
        .absent-card { background: linear-gradient(135deg, #ef476f 0%, #dc3661 100%); }
        .absent-card i, .absent-card h3, .absent-card .count, .absent-card .stat-description { color: white; }
        .absent-card i { opacity: 0.3; }
        .late-card { background: linear-gradient(135deg, #ffd166 0%, #f0c040 100%); }
        .late-card i, .late-card h3, .late-card .count, .late-card .stat-description { color: #1e293b; }
        .late-card i { opacity: 0.3; }
        .leave-card { background: linear-gradient(135deg, #4361ee 0%, #3651d4 100%); }
        .leave-card i, .leave-card h3, .leave-card .count, .leave-card .stat-description { color: white; }
        .leave-card i { opacity: 0.3; }
        .attendance-status { display: inline-flex; align-items: center; padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 500; }
        .status-present { background: rgba(6, 214, 160, 0.1); color: var(--success-color); border: 1px solid var(--success-color); }
        .status-absent { background: rgba(239, 71, 111, 0.1); color: var(--danger-color); border: 1px solid var(--danger-color); }
        .status-late { background: rgba(255, 209, 102, 0.1); color: #b8860b; border: 1px solid #ffd166; }
        .status-leave { background: rgba(67, 97, 238, 0.1); color: var(--primary-color); border: 1px solid var(--primary-color); }
        .panel { background: white; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); margin-bottom: 24px; overflow: hidden; }
        .panel-header { padding: 20px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .panel-header h2 { font-size: 1.1rem; font-weight: 600; color: #1e293b; margin: 0; }
        .panel-header h2 i { margin-right: 10px; color: var(--primary-color); }
        .panel-body { padding: 24px; }
        .form-inline { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .form-inline input, .form-inline select { flex: 1; min-width: 150px; padding: 10px 15px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; transition: border-color 0.2s, box-shadow 0.2s; }
        .form-inline input:focus, .form-inline select:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1); }
        .quick-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .quick-action-card { background: white; border: 2px dashed #e2e8f0; border-radius: 12px; padding: 24px; text-align: center; cursor: pointer; transition: all 0.3s; text-decoration: none; color: #64748b; }
        .quick-action-card:hover { border-color: var(--primary-color); color: var(--primary-color); background: rgba(67, 97, 238, 0.02); transform: translateY(-2px); }
        .quick-action-card i { font-size: 2rem; margin-bottom: 10px; display: block; }
        .quick-action-card h4 { font-size: 0.95rem; font-weight: 600; margin-bottom: 5px; }
        .quick-action-card p { font-size: 0.8rem; color: #94a3b8; margin: 0; }
        .student-row { display: flex; align-items: center; }
        .student-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color), #3651d4); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.9rem; margin-right: 12px; }
        .badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
        .badge-warning { background: rgba(239, 71, 111, 0.1); color: var(--danger-color); }
        .badge-success { background: rgba(6, 214, 160, 0.1); color: var(--success-color); }
        .activity-item { display: flex; align-items: center; padding: 15px 0; border-bottom: 1px solid #f1f5f9; }
        .activity-item:last-child { border-bottom: none; }
        .activity-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 15px; }
        .activity-icon.present { background: rgba(6, 214, 160, 0.1); color: var(--success-color); }
        .activity-icon.absent { background: rgba(239, 71, 111, 0.1); color: var(--danger-color); }
        .activity-icon.late { background: rgba(255, 209, 102, 0.1); color: #b8860b; }
        .activity-icon.leave { background: rgba(67, 97, 238, 0.1); color: var(--primary-color); }
        .activity-content { flex: 1; }
        .activity-content h4 { font-size: 0.95rem; font-weight: 600; color: #1e293b; margin: 0 0 4px 0; }
        .activity-content p { font-size: 0.85rem; color: #64748b; margin: 0; }
        .activity-time { font-size: 0.8rem; color: #94a3b8; white-space: nowrap; }
    </style>
</head>
<body>
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu"><i class="fas fa-bars"></i></button>
    
    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Admin Portal</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Admin</p>
                    <span class="principal-name"><?php echo htmlspecialchars($principal_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout"><span class="logout-icon">🚪</span><span>Logout</span></a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>Navigation</h3>
                <button class="sidebar-close" id="sidebarClose">✕</button>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link">
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
                        <a href="manage-school.php" class="nav-link">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                                                            <li class="nav-item">
                        <a href="support.php" class="nav-link">
                            <span class="nav-icon">🛟</span>
                            <span class="nav-text">Support</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="subscription.php" class="nav-link">
                            <span class="nav-icon">💳</span>
                            <span class="nav-text">Subscription</span>
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
                        <a href="manage_attendance.php" class="nav-link active">
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

        <main class="main-content">
            <div class="content-header">
                <div class="welcome-section">
                    <h2>📋 Attendance Management</h2>
                    <p>Track and manage student attendance records</p>
                </div>
            </div>

            <div class="stats-container">
                <div class="stat-card present-card">
                    <i class="fas fa-check-circle"></i>
                    <h3>Present Today</h3>
                    <div class="count"><?php echo $today_stats['present_count'] ?? 0; ?></div>
                    <p class="stat-description">of <?php echo $today_stats['total_students'] ?? 0; ?> students</p>
                </div>
                <div class="stat-card absent-card">
                    <i class="fas fa-times-circle"></i>
                    <h3>Absent Today</h3>
                    <div class="count"><?php echo $today_stats['absent_count'] ?? 0; ?></div>
                    <p class="stat-description">students absent</p>
                </div>
                <div class="stat-card late-card">
                    <i class="fas fa-clock"></i>
                    <h3>Late Arrivals</h3>
                    <div class="count"><?php echo $today_stats['late_count'] ?? 0; ?></div>
                    <p class="stat-description">students late</p>
                </div>
                <div class="stat-card leave-card">
                    <i class="fas fa-calendar-minus"></i>
                    <h3>On Leave</h3>
                    <div class="count"><?php echo $today_stats['leave_count'] ?? 0; ?></div>
                    <p class="stat-description">approved leaves</p>
                </div>
            </div>

            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-filter"></i>Advanced Filters</h2>
                </div>
                <div class="panel-body">
                    <form method="GET" action="" class="form-inline">
                        <select name="class_id">
                            <option value="all">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status">
                            <option value="all">All Status</option>
                            <option value="present" <?php echo $selected_status == 'present' ? 'selected' : ''; ?>>Present</option>
                            <option value="absent" <?php echo $selected_status == 'absent' ? 'selected' : ''; ?>>Absent</option>
                            <option value="late" <?php echo $selected_status == 'late' ? 'selected' : ''; ?>>Late</option>
                            <option value="leave" <?php echo $selected_status == 'leave' ? 'selected' : ''; ?>>Leave</option>
                        </select>
                        <select name="teacher_id">
                            <option value="all">All Teachers</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo $teacher['id']; ?>" <?php echo $selected_teacher == $teacher['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($teacher['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="search" placeholder="Search student..." value="<?php echo htmlspecialchars($search_term); ?>">
                        <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                        <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                        <button type="submit" class="btn primary"><i class="fas fa-search"></i>Apply Filters</button>
                        <a href="manage_attendance.php" class="btn secondary"><i class="fas fa-redo"></i>Reset</a>
                    </form>
                    <p style="margin-top:10px; color:#64748b; font-size:0.9rem;">
                        Showing <strong><?php echo $records_count; ?></strong> record(s) for
                        <strong><?php echo htmlspecialchars($start_date); ?></strong> to
                        <strong><?php echo htmlspecialchars($end_date); ?></strong>.
                    </p>
                </div>
            </section>

            <div class="quick-actions">
                <a href="attendance_reports.php" class="quick-action-card">
                    <i class="fas fa-chart-line"></i>
                    <h4>Monthly Report</h4>
                    <p>View monthly trends</p>
                </a>
                <div class="quick-action-card" onclick="exportAttendance()">
                    <i class="fas fa-file-export"></i>
                    <h4>Export Data</h4>
                    <p>Download records</p>
                </div>
                <div class="quick-action-card" onclick="window.print()">
                    <i class="fas fa-print"></i>
                    <h4>Print Report</h4>
                    <p>Generate PDF</p>
                </div>
            </div>

            <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; margin-top: 24px;">
                <section class="panel">
                    <div class="panel-header">
                        <h2><i class="fas fa-exclamation-triangle"></i>At-Risk Students (< 80%)</h2>
                    </div>
                    <div class="panel-body">
                        <?php if (!empty($at_risk_students)): ?>
                            <?php foreach ($at_risk_students as $student): ?>
                                <div class="activity-item">
                                    <div class="student-avatar"><?php echo strtoupper(substr($student['full_name'], 0, 1)); ?></div>
                                    <div class="activity-content">
                                        <h4><?php echo htmlspecialchars($student['full_name']); ?></h4>
                                        <p><?php echo htmlspecialchars($student['class_name']); ?> • <?php echo htmlspecialchars($student['admission_no']); ?></p>
                                    </div>
                                    <span class="badge badge-warning"><?php echo $student['attendance_rate']; ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: #64748b; padding: 20px;"><i class="fas fa-check-circle" style="color: var(--success-color);"></i> No at-risk students this month!</p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <h2><i class="fas fa-trophy"></i>Perfect Attendance (100%)</h2>
                    </div>
                    <div class="panel-body">
                        <?php if (!empty($perfect_students)): ?>
                            <?php foreach ($perfect_students as $student): ?>
                                <div class="activity-item">
                                    <div class="student-avatar"><?php echo strtoupper(substr($student['full_name'], 0, 1)); ?></div>
                                    <div class="activity-content">
                                        <h4><?php echo htmlspecialchars($student['full_name']); ?></h4>
                                        <p><?php echo htmlspecialchars($student['class_name']); ?> • <?php echo $student['total_days']; ?> days</p>
                                    </div>
                                    <span class="badge badge-success"><i class="fas fa-star"></i> 100%</span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: #64748b; padding: 20px;">No perfect attendance records yet.</p>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-list"></i>Attendance Records</h2>
                    <span class="badge" style="background: #f1f5f9; color: #64748b;"><?php echo count($attendance_records); ?> records</span>
                </div>
                <div class="panel-body">
                    <?php if (!empty($attendance_records)): ?>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Student</th>
                                        <th>Class</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                        <th>Recorded By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attendance_records as $record): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($record['date'])); ?></td>
                                            <td>
                                                <div class="student-row">
                                                    <div class="student-avatar"><?php echo strtoupper(substr($record['full_name'], 0, 1)); ?></div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($record['full_name']); ?></strong>
                                                        <small style="color: #64748b; display: block;"><?php echo htmlspecialchars($record['admission_no']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                                            <td><span class="attendance-status status-<?php echo $record['status']; ?>"><?php echo ucfirst($record['status']); ?></span></td>
                                            <td><?php echo htmlspecialchars($record['notes'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($record['recorded_by_name'] ?? 'System'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #64748b;">
                            <i class="fas fa-clipboard-list" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 15px;"></i>
                            <h4>No Attendance Records Found</h4>
                            <p>Try adjusting your filters or select a different date range.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-history"></i>Recent Activity</h2>
                </div>
                <div class="panel-body">
                    <?php if (!empty($recent_activity)): ?>
                        <?php foreach ($recent_activity as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo $activity['status']; ?>">
                                    <i class="fas <?php echo $activity['status'] == 'present' ? 'fa-check' : ($activity['status'] == 'absent' ? 'fa-times' : 'fa-clock'); ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <h4><?php echo htmlspecialchars($activity['full_name']); ?></h4>
                                    <p>Marked as <?php echo $activity['status']; ?> - <?php echo htmlspecialchars($activity['class_name']); ?></p>
                                </div>
                                <span class="activity-time"><?php echo date('M j, Y', strtotime($activity['date'])); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; color: #64748b;">No recent activity</p>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <script>
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });

        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
        });

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

        function exportAttendance() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'excel');
            window.location.href = 'export_attendance.php?' + params.toString();
        }

        document.querySelectorAll('.panel, .stat-card, .quick-action-card, .activity-item').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'all 0.5s ease';
            setTimeout(() => {
                el.style.opacity = '1';
                el.style.transform = 'translateY(0)';
            }, 100);
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>
</body>
</html>



