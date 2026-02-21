<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if student is logged in
if (!isset($_SESSION['student_id']) && !isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$student_id = isset($_SESSION['student_id']) ? $_SESSION['student_id'] : $_SESSION['user_id'];
$student_name = $_SESSION['student_name'] ?? '';
$admission_number = $_SESSION['admission_no'] ?? '';
$current_school_id = get_current_school_id();

// If school_id is missing, try to resolve it from the student record
if ($current_school_id === false && $student_id) {
    $school_stmt = $pdo->prepare("SELECT school_id FROM students WHERE id = ? OR user_id = ? LIMIT 1");
    $school_stmt->execute([$student_id, $student_id]);
    $resolved_school_id = $school_stmt->fetchColumn();
    if ($resolved_school_id !== false) {
        $_SESSION['school_id'] = $resolved_school_id;
        $current_school_id = $resolved_school_id;
    }
}

// Get student details including class
try {
    $student_stmt = $pdo->prepare("
        SELECT s.*, c.class_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id AND c.school_id = ?
        WHERE (s.id = ? OR s.user_id = ? OR s.admission_no = ?) AND s.school_id = ?
    ");
    $student_stmt->execute([$current_school_id, $student_id, $student_id, $admission_number, $current_school_id]);
    $student = $student_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        throw new Exception("Student not found");
    }

} catch (Exception $e) {
    $_SESSION['error'] = "Error loading student data: " . $e->getMessage();
    header("Location: index.php");
    exit;
}

// Get current date for filtering
$current_date = date('Y-m-d');

// Build query - students see activities for their class or all-school activities
$query = "SELECT sd.*, ac.category_name, ac.color, ac.icon, u.full_name as coordinator_name
          FROM school_diary sd
          LEFT JOIN activity_categories ac ON sd.category_id = ac.id
          LEFT JOIN users u ON sd.coordinator_id = u.id
          WHERE sd.school_id = ?
          AND (sd.target_audience = 'All'
               OR sd.target_audience = 'Secondary Only'
               OR (sd.target_audience = 'Specific Classes' AND FIND_IN_SET(?, REPLACE(sd.target_classes, ', ', ','))))
          AND sd.status != 'Cancelled'
          ORDER BY
            CASE
                WHEN sd.status = 'Ongoing' THEN 1
                WHEN sd.activity_date >= ? THEN 2
                ELSE 3
            END,
            sd.activity_date ASC,
            sd.start_time ASC";

// If student has class, search for it in target_classes
$class_param = $student['class_name'] ?: '';
$stmt = $pdo->prepare($query);
$stmt->execute([$current_school_id, $class_param, $current_date]);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get today's activities
$today_query = "SELECT COUNT(*) as count FROM school_diary
                WHERE activity_date = ?
                AND status != 'Cancelled'
                AND school_id = ?";
$today_stmt = $pdo->prepare($today_query);
$today_stmt->execute([$current_date, $current_school_id]);
$today_count = $today_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get upcoming activities (next 7 days)
$next_week = date('Y-m-d', strtotime('+7 days'));
$upcoming_query = "SELECT COUNT(*) as count FROM school_diary
                   WHERE activity_date BETWEEN ? AND ?
                   AND status != 'Cancelled'
                   AND status = 'Upcoming'
                   AND school_id = ?";
$upcoming_stmt = $pdo->prepare($upcoming_query);
$upcoming_stmt->execute([$current_date, $next_week, $current_school_id]);
$upcoming_count = $upcoming_stmt->fetch(PDO::FETCH_ASSOC)['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Events Calendar | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        /* ===================================================
           School Diary - Modern Internal Styles
           =================================================== */

        :root {
            /* Inherit dashboard color palette */
            --primary-color: #6366f1;
            --primary-dark: #4f46e5;
            --secondary-color: #06b6d4;
            --accent-color: #8b5cf6;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --info-color: #3b82f6;

            /* Modern gradients */
            --gradient-primary: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            --gradient-secondary: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            --gradient-success: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --gradient-warning: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --gradient-error: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);

            /* Enhanced shadows */
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);

            /* Modern border radius */
            --border-radius-sm: 0.375rem;
            --border-radius-md: 0.5rem;
            --border-radius-lg: 0.75rem;
            --border-radius-xl: 1rem;

            /* Smooth transitions */
            --transition-fast: 0.15s ease-in-out;
            --transition-normal: 0.3s ease-in-out;
            --transition-slow: 0.5s ease-in-out;
        }

        /* ===================================================
           Layout Fixes for Sidebar Integration
           =================================================== */

        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 80px);
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }

        .main-content {
            flex: 1;
            margin-left: 0;
            padding: 2rem;
            max-width: 100%;
            background: transparent;
        }

        /* Desktop sidebar layout */
        @media (min-width: 1024px) {
            .main-content {
                margin-left: 280px;
                max-width: calc(100vw - 280px);
            }
        }

        /* ===================================================
           Modern Welcome Section
           =================================================== */

        .welcome-card {
            background: var(--gradient-primary);
            color: white;
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            margin-bottom: 2rem;
            border: none;
            position: relative;
        }

        .welcome-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="50" r="1" fill="white" opacity="0.05"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.1;
        }

        .welcome-card-body {
            padding: 2.5rem;
            position: relative;
            z-index: 1;
        }

        .welcome-card h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .welcome-card h2 i {
            font-size: 2rem;
            opacity: 0.9;
        }

        .welcome-card p {
            font-size: 1.1rem;
            opacity: 0.9;
            margin: 0;
            max-width: 600px;
        }

        /* ===================================================
           Statistics Cards Grid
           =================================================== */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            transition: var(--transition-normal);
            border: 1px solid rgba(255, 255, 255, 0.8);
            position: relative;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card-primary { border-left: 4px solid var(--primary-color); }
        .stat-card-success { border-left: 4px solid var(--success-color); }
        .stat-card-warning { border-left: 4px solid var(--warning-color); }

        .stat-card-body {
            padding: 2rem;
            text-align: center;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 1rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            backdrop-filter: blur(10px);
        }

        .stat-card-primary .stat-icon {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary-color);
        }

        .stat-card-success .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .stat-card-warning .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .stat-value {
            font-size: 3rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-label {
            font-size: 1rem;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* ===================================================
           Filter Section
           =================================================== */

        .filter-card {
            background: white;
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
            border: 1px solid #e5e7eb;
        }

        .filter-card-body {
            padding: 2rem;
        }

        .filter-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            border-radius: var(--border-radius-lg);
            font-weight: 500;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition-fast);
            border: 2px solid #e5e7eb;
            background: white;
            color: #6b7280;
            text-decoration: none;
        }

        .filter-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-1px);
        }

        .filter-btn.active {
            background: var(--gradient-primary);
            color: white;
            border-color: transparent;
            box-shadow: var(--shadow-md);
        }

        /* ===================================================
           Activities Grid
           =================================================== */

        .activities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .activity-card {
            background: white;
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            transition: var(--transition-normal);
            border: 1px solid #e5e7eb;
            opacity: 0;
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .activity-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .activity-card:nth-child(1) { animation-delay: 0.1s; }
        .activity-card:nth-child(2) { animation-delay: 0.2s; }
        .activity-card:nth-child(3) { animation-delay: 0.3s; }
        .activity-card:nth-child(4) { animation-delay: 0.4s; }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .activity-header {
            padding: 1.5rem;
            background: var(--gradient-primary);
            color: white;
            position: relative;
        }

        .activity-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
        }

        .activity-header-content {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .activity-icon-wrapper {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
        }

        .activity-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
        }

        .activity-category {
            font-size: 0.9rem;
            opacity: 0.9;
            margin: 0;
        }

        .activity-body {
            padding: 1.5rem;
        }

        .activity-status {
            display: inline-block;
            padding: 0.375rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1rem;
        }

        .status-ongoing {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .status-upcoming {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .status-completed {
            background: rgba(107, 114, 128, 0.1);
            color: #6b7280;
        }

        .activity-details {
            margin-bottom: 1rem;
        }

        .activity-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: #4b5563;
        }

        .activity-detail i {
            color: var(--primary-color);
            width: 16px;
        }

        .activity-description {
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        .activity-actions {
            display: flex;
            gap: 0.75rem;
        }

        .activity-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1rem;
            border-radius: var(--border-radius-md);
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #6b7280;
            transition: var(--transition-fast);
            cursor: pointer;
        }

        .activity-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* ===================================================
           Empty State
           =================================================== */

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-md);
        }

        .empty-icon {
            font-size: 4rem;
            color: #d1d5db;
            margin-bottom: 1.5rem;
        }

        .empty-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.5rem;
            color: #6b7280;
            margin-bottom: 0.5rem;
        }

        .empty-text {
            color: #9ca3af;
            font-size: 1rem;
        }

        /* ===================================================
           Responsive Design
           =================================================== */

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1.5rem;
                max-width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 1rem;
            }

            .activities-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 1rem;
            }

            .welcome-card h2 {
                font-size: 2rem;
            }

            .welcome-card-body {
                padding: 2rem;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .welcome-card h2 {
                font-size: 1.75rem;
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }

            .welcome-card p {
                font-size: 1rem;
                text-align: center;
            }

            .welcome-card-body {
                padding: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .stat-card-body {
                padding: 1.5rem;
            }

            .stat-value {
                font-size: 2.5rem;
            }

            .activities-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .filter-buttons {
                justify-content: center;
            }

            .filter-btn {
                flex: 1;
                justify-content: center;
                min-width: 120px;
            }
        }

        @media (max-width: 480px) {
            .welcome-card h2 {
                font-size: 1.5rem;
            }

            .stat-card-body {
                padding: 1.25rem;
            }

            .stat-value {
                font-size: 2rem;
            }

            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 1.25rem;
            }

            .activity-header-content {
                flex-direction: column;
                text-align: center;
                gap: 0.75rem;
            }

            .activity-icon-wrapper {
                width: 40px;
                height: 40px;
            }

            .activity-title {
                font-size: 1.1rem;
            }
        }

        /* ===================================================
           Utility Classes
           =================================================== */

        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }

        .mb-0 { margin-bottom: 0; }
        .mb-1 { margin-bottom: 0.25rem; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-3 { margin-bottom: 1rem; }
        .mb-4 { margin-bottom: 1.5rem; }

        /* ===================================================
           Override Bootstrap Classes
           =================================================== */

        .row {
            display: contents;
        }

        .col-md-4,
        .col-md-6 {
            display: contents;
        }

        .card {
            background: white;
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-lg);
            border: 1px solid #e5e7eb;
            margin-bottom: 1.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .card-header {
            padding: 1.5rem;
            background: var(--gradient-primary);
            color: white;
            border-bottom: none;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }

        .badge {
            display: inline-block;
            padding: 0.375rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info-color);
        }

        .badge-secondary {
            background: rgba(107, 114, 128, 0.1);
            color: #6b7280;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1rem;
            border-radius: var(--border-radius-md);
            font-weight: 500;
            text-decoration: none;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #6b7280;
            transition: var(--transition-fast);
            cursor: pointer;
            font-size: 0.85rem;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            border-color: transparent;
        }

        .btn-outline-primary {
            border-color: var(--primary-color);
            color: var(--primary-color);
            background: white;
        }

        .btn-outline-primary:hover {
            background: var(--primary-color);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 0.875rem;
            font-size: 0.8rem;
        }

        /* ===================================================
           Modal Z-Index Fixes
           =================================================== */

        .modal-backdrop {
            z-index: 9998 !important;
        }

        .modal {
            z-index: 9999 !important;
        }

        .modal-dialog {
            z-index: 10000 !important;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Student Portal</p>
                    </div>
                </div>
            </div>

            <!-- Student Info and Logout -->
            <div class="header-right">
                <div class="student-info">
                    <p class="student-label">Student</p>
                    <span class="student-name"><?php echo htmlspecialchars($student_name); ?></span>
                    <span class="admission-number"><?php echo htmlspecialchars($admission_number); ?></span>
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

        <!-- Main Content -->
        <main class="main-content">
            <!-- Welcome Section -->
            <div class="welcome-card">
                <div class="welcome-card-body">
                    <h2><i class="fas fa-calendar-alt"></i> School Events & Activities</h2>
                    <p>Stay updated with all school events, competitions, and activities for <?php echo htmlspecialchars($student['class_name'] ?? 'your class'); ?> students</p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card stat-card-primary">
                    <div class="stat-card-body">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-value"><?php echo count($activities); ?></div>
                        <div class="stat-label">Total Events</div>
                    </div>
                </div>
                <div class="stat-card stat-card-success">
                    <div class="stat-card-body">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $today_count; ?></div>
                        <div class="stat-label">Today's Events</div>
                    </div>
                </div>
                <div class="stat-card stat-card-warning">
                    <div class="stat-card-body">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-plus"></i>
                        </div>
                        <div class="stat-value"><?php echo $upcoming_count; ?></div>
                        <div class="stat-label">Upcoming (7 days)</div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <div class="filter-card-body">
                    <div class="filter-buttons">
                        <button class="filter-btn active" data-filter="all">
                            <i class="fas fa-list"></i> All Events
                        </button>
                        <button class="filter-btn" data-filter="upcoming">
                            <i class="fas fa-clock"></i> Upcoming
                        </button>
                        <button class="filter-btn" data-filter="ongoing">
                            <i class="fas fa-play-circle"></i> Ongoing
                        </button>
                        <button class="filter-btn" data-filter="today">
                            <i class="fas fa-calendar-day"></i> Today
                        </button>
                        <button class="filter-btn" data-filter="completed">
                            <i class="fas fa-check-circle"></i> Past Events
                        </button>
                    </div>
                </div>
            </div>

            <!-- Activities Grid -->
            <div id="activities-container">
                <?php if (empty($activities)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-calendar-times"></i>
                        </div>
                        <h3 class="empty-title">No Events Scheduled</h3>
                        <p class="empty-text">There are no upcoming events scheduled at the moment.</p>
                    </div>
                <?php else: ?>
                    <div class="activities-grid">
                        <?php foreach ($activities as $activity): ?>
                            <?php
                            // Determine status and styling
                            $activity_date = strtotime($activity['activity_date']);
                            $today = strtotime(date('Y-m-d'));
                            $status_class = '';

                            if ($activity['status'] == 'Ongoing') {
                                $status_class = 'status-ongoing';
                                $status_text = 'Ongoing';
                            } elseif ($activity_date == $today && $activity['status'] == 'Upcoming') {
                                $status_class = 'status-ongoing';
                                $status_text = 'Today';
                            } elseif ($activity_date > $today) {
                                $status_class = 'status-upcoming';
                                $status_text = 'Upcoming';
                            } else {
                                $status_class = 'status-completed';
                                $status_text = 'Completed';
                            }

                            // Get category color
                            $category_color = $activity['color'] ?: '#6366f1';
                            $category_icon = $activity['icon'] ?: 'fas fa-calendar-alt';

                            // Format date
                            $formatted_date = date('M d, Y', $activity_date);
                            $formatted_time = $activity['start_time'] ? date('h:i A', strtotime($activity['start_time'])) : 'All Day';
                            ?>
                            <div class="activity-card" data-status="<?php echo strtolower($status_text); ?>" data-date="<?php echo $activity['activity_date']; ?>">
                                <div class="activity-header">
                                    <div class="activity-header-content">
                                        <div class="activity-icon-wrapper">
                                            <i class="<?php echo $category_icon; ?>"></i>
                                        </div>
                                        <div>
                                            <h3 class="activity-title"><?php echo htmlspecialchars($activity['activity_title']); ?></h3>
                                            <p class="activity-category"><?php echo htmlspecialchars($activity['category_name'] ?: $activity['activity_type']); ?></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="activity-body">
                                    <span class="activity-status <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>

                                    <div class="activity-details">
                                        <div class="activity-detail">
                                            <i class="fas fa-calendar"></i>
                                            <span><strong><?php echo $formatted_date; ?></strong></span>
                                        </div>
                                        <?php if ($activity['start_time']): ?>
                                            <div class="activity-detail">
                                                <i class="fas fa-clock"></i>
                                                <span><?php echo $formatted_time; ?><?php if ($activity['end_time']): ?> - <?php echo date('h:i A', strtotime($activity['end_time'])); ?><?php endif; ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($activity['venue']): ?>
                                            <div class="activity-detail">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <span><?php echo htmlspecialchars($activity['venue']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <p class="activity-description">
                                        <?php
                                        $description = strip_tags($activity['description'] ?: 'No description available');
                                        echo strlen($description) > 150 ? substr($description, 0, 150) . '...' : $description;
                                        ?>
                                    </p>

                                    <div class="activity-actions">
                                        <button class="activity-btn" onclick="viewDetails(<?php echo $activity['id']; ?>)">
                                            <i class="fas fa-eye"></i> View Details
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
        // Filter functionality
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const filter = this.getAttribute('data-filter');

                // Update button states
                document.querySelectorAll('.filter-btn').forEach(b => {
                    b.classList.remove('active');
                });
                this.classList.add('active');

                // Filter activities
                const activities = document.querySelectorAll('#activities-container .activity-card');
                activities.forEach(activity => {
                    const status = activity.getAttribute('data-status');
                    const date = activity.getAttribute('data-date');
                    const today = new Date().toISOString().split('T')[0];

                    if (filter === 'all') {
                        activity.style.display = 'block';
                    } else if (filter === 'today') {
                        activity.style.display = date === today ? 'block' : 'none';
                    } else {
                        activity.style.display = status === filter ? 'block' : 'none';
                    }
                });
            });
        });

        // View Activity Details - Redirect to separate page
        function viewDetails(activityId) {
            window.location.href = `activity_details.php?id=${activityId}`;
        }
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
