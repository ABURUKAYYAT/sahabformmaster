<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in as student
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit();
}

$student_user_id = $_SESSION['student_id'];
$current_school_id = get_current_school_id();
$current_month = date('Y-m');
$selected_month = isset($_GET['month']) ? $_GET['month'] : $current_month;
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = $current_month;
}

// Parse selected month for calendar display
$parts = explode('-', $selected_month);
$year = (int)($parts[0] ?? date('Y'));
$month = (int)($parts[1] ?? date('m'));
$year = ($year >= 2000 && $year <= 2100) ? $year : (int)date('Y');
$month = ($month >= 1 && $month <= 12) ? $month : (int)date('m');
$days_in_month = date('t', strtotime($selected_month . '-01'));
$first_day = date('N', strtotime($selected_month . '-01'));
$today = date('Y-m-d');

// Get student information
$student_sql = "SELECT s.*, c.class_name
                FROM students s
                JOIN classes c ON s.class_id = c.id
                WHERE s.id = :id AND s.school_id = :school_id";
$student_stmt = $pdo->prepare($student_sql);
$student_stmt->execute([':id' => $student_user_id, ':school_id' => $current_school_id]);
$student = $student_stmt->fetch();

if (!$student) {
    die("Student information not found.");
}

// Fetch attendance for selected month
$attendance_sql = "SELECT a.date, a.status, a.notes
                   FROM attendance a
                   JOIN students s ON a.student_id = s.id
                   WHERE a.student_id = :id AND s.school_id = :school_id
                   AND DATE_FORMAT(a.date, '%Y-%m') = :selected_month
                   ORDER BY a.date DESC";
$attendance_stmt = $pdo->prepare($attendance_sql);
$attendance_stmt->execute([
    ':id' => $student['id'],
    ':school_id' => $current_school_id,
    ':selected_month' => $selected_month
]);
$attendance_records = $attendance_stmt->fetchAll();

$monthly_counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0];
foreach ($attendance_records as $record) {
    if (isset($monthly_counts[$record['status']])) {
        $monthly_counts[$record['status']]++;
    }
}
$monthly_total_records = count($attendance_records);
$monthly_attendance_rate = $monthly_total_records > 0
    ? round((($monthly_counts['present'] + $monthly_counts['late']) / $monthly_total_records) * 100, 1)
    : 0;

// Create attendance map for calendar
$attendance_map = [];
foreach ($attendance_records as $record) {
    $attendance_map[$record['date']] = $record;
}

// Calculate attendance statistics
$stats_sql = "SELECT
    COUNT(*) as total_days,
    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_days,
    SUM(CASE WHEN a.status = 'leave' THEN 1 ELSE 0 END) as leave_days
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE a.student_id = :id AND s.school_id = :school_id
    AND a.date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([':id' => $student['id'], ':school_id' => $current_school_id]);
$stats = $stats_stmt->fetch();

// Get recent months for dropdown
$months_sql = "SELECT DISTINCT DATE_FORMAT(a.date, '%Y-%m') as month
              FROM attendance a
              JOIN students s ON a.student_id = s.id
              WHERE a.student_id = :id AND s.school_id = :school_id
              ORDER BY month DESC";
$months_stmt = $pdo->prepare($months_sql);
$months_stmt->execute([':id' => $student['id'], ':school_id' => $current_school_id]);
$months = $months_stmt->fetchAll();

// Current attendance streak (consecutive present/late records)
$streak_sql = "SELECT a.date, a.status
               FROM attendance a
               JOIN students s ON a.student_id = s.id
               WHERE a.student_id = :id AND s.school_id = :school_id
               ORDER BY a.date DESC
               LIMIT 120";
$streak_stmt = $pdo->prepare($streak_sql);
$streak_stmt->execute([':id' => $student['id'], ':school_id' => $current_school_id]);
$streak_records = $streak_stmt->fetchAll();

$current_streak = 0;
foreach ($streak_records as $row) {
    if (in_array($row['status'], ['present', 'late'], true)) {
        $current_streak++;
    } else {
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/css/bootstrap-datepicker.min.css">
    <style>
        /* Custom attendance page styles */
        .attendance-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            text-align: center;
        }

        .attendance-hero h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .attendance-hero p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* Dashboard-style cards for stats */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition-normal);
            border: 1px solid var(--gray-200);
        }

        .card:hover {
            box-shadow: var(--shadow-xl);
            transform: translateY(-5px);
        }

        .card.card-gradient-1 { background: var(--gradient-1); color: white; }
        .card.card-gradient-2 { background: var(--gradient-2); color: white; }
        .card.card-gradient-3 { background: var(--gradient-3); color: white; }
        .card.card-gradient-4 { background: var(--gradient-4); color: white; }

        .card-icon-wrapper {
            padding: 2rem 1.5rem 1rem;
            display: flex;
            justify-content: center;
        }

        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            backdrop-filter: blur(10px);
        }

        .card-content {
            padding: 0 1.5rem 1.5rem;
        }

        .card-content h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .card-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            line-height: 1;
        }

        .card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-badge {
            background: rgba(255, 255, 255, 0.2);
            color: inherit;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .card-link {
            color: inherit;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            opacity: 0.9;
            transition: var(--transition-fast);
        }

        .card-link:hover {
            opacity: 1;
            text-decoration: underline;
        }

        /* Modern Form Controls */
        .controls-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1), 0 2px 8px rgba(0, 0, 0, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .controls-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.05), transparent);
            transition: left 0.6s ease;
        }

        .controls-modern:hover::before {
            left: 100%;
        }

        .form-row-modern {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            align-items: end;
        }

        .form-group-modern {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-label-modern {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-label-modern i {
            color: var(--primary-500);
            font-size: 0.75rem;
        }

        .form-input-modern,
        .monthpicker {
            width: 100%;
            padding: 1rem 1.25rem;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(0, 0, 0, 0.1);
            border-radius: 16px;
            font-size: 1rem;
            font-weight: 500;
            color: var(--gray-900);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.1);
        }

        .form-input-modern:focus,
        .monthpicker:focus {
            outline: none;
            border-color: var(--primary-500);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1), 0 4px 16px rgba(59, 130, 246, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .form-input-modern::placeholder,
        .monthpicker::placeholder {
            color: var(--gray-500);
            font-weight: 400;
            opacity: 0.8;
        }

        .form-input-modern:hover,
        .monthpicker:hover {
            border-color: rgba(59, 130, 246, 0.3);
            background: rgba(255, 255, 255, 0.95);
        }

        .btn-modern-primary,
        .nav-btn {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 16px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(59, 130, 246, 0.3), 0 2px 8px rgba(59, 130, 246, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            min-height: 48px;
        }

        .btn-modern-primary::before,
        .nav-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .btn-modern-primary:hover,
        .nav-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.4), 0 4px 12px rgba(59, 130, 246, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
        }

        .btn-modern-primary:hover::before,
        .nav-btn:hover::before {
            left: 100%;
        }

        .btn-modern-primary:active,
        .nav-btn:active {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1);
        }

        .btn-modern-primary i,
        .nav-btn i {
            font-size: 1rem;
            transition: transform 0.3s ease;
        }

        .btn-modern-primary:hover i,
        .nav-btn:hover i {
            transform: scale(1.1);
        }

        /* Calendar and sidebar styles */
        .calendar-section {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e5e7eb;
        }

        .calendar-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #374151;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.5rem;
        }

        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .calendar-day.present { background: #dcfce7; color: #166534; }
        .calendar-day.absent { background: #fef2f2; color: #dc2626; }
        .calendar-day.late { background: #fef3c7; color: #d97706; }
        .calendar-day.today { border: 3px solid #6366f1; }
        .calendar-day.empty { background: transparent; cursor: default; }

        .attendance-sidebar {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .sidebar-section {
            margin-bottom: 2rem;
        }

        .sidebar-section:last-child {
            margin-bottom: 0;
        }

        .sidebar-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .month-links {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .month-link {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            color: #6b7280;
            transition: all 0.2s ease;
            border: 1px solid #e5e7eb;
        }

        .month-link:hover,
        .month-link.active {
            background: #6366f1;
            color: white;
            border-color: #6366f1;
        }

        .download-report {
            display: inline-block;
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            text-align: center;
            font-weight: 600;
            transition: transform 0.2s ease;
        }

        .download-report:hover {
            transform: translateY(-2px);
        }

        /* Legend styles */
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%);
            border-radius: 12px;
            border: 1px solid #e5e7eb;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
        }

        .legend-dot {
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .legend-dot.present { background: #dcfce7; border: 2px solid #166534; }
        .legend-dot.absent { background: #fef2f2; border: 2px solid #dc2626; }
        .legend-dot.late { background: #fef3c7; border: 2px solid #d97706; }
        .legend-dot.today { border: 3px solid #6366f1; background: transparent; }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .form-row-modern {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .dashboard-cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Student Portal</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="student-info">
                    <p class="student-label">Student</p>
                    <span class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <?php include '../includes/student_sidebar.php'; ?>

        <main class="main-content">
            <!-- Hero Section -->
            <div class="attendance-hero animate-fade-in">
                <h1><i class="fas fa-calendar-check"></i> My Attendance</h1>
                <p>Track your attendance records and stay on top of your academic performance</p>
            </div>

            <!-- Statistics Overview - Dashboard Style Cards -->
            <div class="dashboard-cards">
                <div class="card card-gradient-1">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-calendar-alt"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Total Days</h3>
                        <p class="card-value"><?php echo $stats['total_days']; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">Last 3 Months</span>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-2">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-check-circle"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Present Days</h3>
                        <p class="card-value"><?php echo $stats['present_days']; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">
                                <?php echo $stats['total_days'] > 0 ? round(($stats['present_days'] / $stats['total_days']) * 100, 1) : 0; ?>%
                                Rate
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-3">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-times-circle"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Absent Days</h3>
                        <p class="card-value"><?php echo $stats['absent_days']; ?></p>
                        <div class="card-footer">
                            <span class="card-badge">
                                <?php echo $stats['total_days'] > 0 ? round(($stats['absent_days'] / $stats['total_days']) * 100, 1) : 0; ?>%
                                of Total
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card card-gradient-4">
                    <div class="card-icon-wrapper">
                        <div class="card-icon"><i class="fas fa-chart-line"></i></div>
                    </div>
                    <div class="card-content">
                        <h3>Current Streak</h3>
                        <p class="card-value">
                            <?php echo $current_streak; ?>
                        </p>
                        <div class="card-footer">
                            <span class="card-badge">Present/Late in a row</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Month Selector -->
            <div class="controls-modern">
                <div class="form-row-modern">
                    <div class="form-group-modern">
                        <label class="form-label-modern">Select Month</label>
                        <input type="text" class="form-input-modern monthpicker" name="month" value="<?php echo $selected_month; ?>" form="monthForm">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">&nbsp;</label>
                        <button type="submit" class="btn-modern-primary" form="monthForm">
                            <i class="fas fa-search"></i>
                            <span>Load Month</span>
                        </button>
                    </div>
                </div>
            </div>

            <form method="GET" action="" id="monthForm" style="display: none;"></form>

            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Calendar Section -->
                <div class="calendar-section">
                    <div class="calendar-header">
                        <h2 class="calendar-title">
                            <i class="fas fa-calendar-alt"></i>
                            <?php echo date('F Y', strtotime("$year-$month-01")); ?>
                        </h2>
                    </div>

                    <div class="calendar-grid">
                        <!-- Calendar Days -->
                        <?php
                        $day_counter = 1;
                        for ($i = 0; $i < 42; $i++) { // 6 weeks * 7 days
                            if (($i < $first_day - 1) || $day_counter > $days_in_month) {
                                echo '<div class="calendar-day empty"></div>';
                            } else {
                                $current_date = sprintf("%04d-%02d-%02d", $year, $month, $day_counter);
                                $date_str = date('Y-m-d', strtotime($current_date));

                                $classes = ['calendar-day'];
                                $title = date('l, F j, Y', strtotime($current_date));

                                if ($date_str == $today) {
                                    $classes[] = 'today';
                                    $title .= ' (Today)';
                                }

                                if (isset($attendance_map[$date_str])) {
                                    $classes[] = $attendance_map[$date_str]['status'];
                                    $title .= ' - ' . ucfirst($attendance_map[$date_str]['status']);
                                }

                                echo '<div class="' . implode(' ', $classes) . '" title="' . $title . '">';
                                echo '<span class="day-number">' . $day_counter . '</span>';
                                echo '</div>';

                                $day_counter++;
                            }
                        }
                        ?>
                    </div>

                    <!-- Legend -->
                    <div class="legend">
                        <div class="legend-item">
                            <div class="legend-dot present"></div>
                            <span>Present</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-dot absent"></div>
                            <span>Absent</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-dot late"></div>
                            <span>Late</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-dot" style="background:#e9d5ff;"></div>
                            <span>Leave</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-dot today"></div>
                            <span>Today</span>
                        </div>
                    </div>
                </div>

                <!-- Sidebar Content -->
                <div class="attendance-sidebar">
                    <!-- Monthly Summary -->
                    <div class="sidebar-section">
                        <h3 class="sidebar-title">
                            <i class="fas fa-chart-bar"></i>
                            Monthly Summary (<?php echo $monthly_attendance_rate; ?>% attendance)
                        </h3>
                        <div class="summary-list">
                            <?php foreach ($monthly_counts as $status => $count):
                                if ($count > 0):
                            ?>
                            <div class="summary-item">
                                <span class="summary-label"><?php echo ucfirst($status); ?> Days</span>
                                <span class="summary-badge <?php echo $status; ?>"><?php echo $count; ?></span>
                            </div>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>

                    <!-- Previous Months -->
                    <div class="sidebar-section">
                        <h3 class="sidebar-title">
                            <i class="fas fa-history"></i>
                            Previous Months
                        </h3>
                        <div class="month-links">
                            <?php foreach($months as $month_row): ?>
                            <a href="?month=<?php echo $month_row['month']; ?>" class="month-link <?php echo ($selected_month == $month_row['month']) ? 'active' : ''; ?>">
                                <i class="fas fa-calendar-month"></i>
                                <?php echo date('F Y', strtotime($month_row['month'] . '-01')); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Download Report -->
                    <div class="sidebar-section">
                        <a href="generate_attendance_pdf.php?month=<?php echo $selected_month; ?>" class="download-report" target="_blank">
                            <i class="fas fa-download"></i>
                            Download Report
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/js/bootstrap-datepicker.min.js"></script>
    <script>
        // Mobile menu functionality
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        mobileMenuToggle?.addEventListener('click', () => {
            sidebar?.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });

        sidebarClose?.addEventListener('click', () => {
            sidebar?.classList.remove('active');
            mobileMenuToggle?.classList.remove('active');
        });

        // Month picker
        $(document).ready(function() {
            $('.monthpicker').datepicker({
                format: 'yyyy-mm',
                startView: 'months',
                minViewMode: 'months',
                autoclose: true
            });
        });
    </script>
    <?php include '../includes/floating-button.php'; ?>
</body>
</html>
