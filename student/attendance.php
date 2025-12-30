<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in as student
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit();
}

$student_user_id = $_SESSION['student_id'];
$current_month = date('Y-m');
$selected_month = isset($_GET['month']) ? $_GET['month'] : $current_month;

// Parse selected month for calendar display
list($year, $month) = explode('-', $selected_month);
$days_in_month = date('t', strtotime($selected_month . '-01'));
$first_day = date('N', strtotime($selected_month . '-01'));
$today = date('Y-m-d');

// Get student information
$student_sql = "SELECT s.*, c.class_name 
                FROM students s 
                JOIN classes c ON s.class_id = c.id 
                WHERE s.id = :id";
$student_stmt = $pdo->prepare($student_sql);
$student_stmt->execute([':id' => $student_user_id]);
$student = $student_stmt->fetch();

if (!$student) {
    die("Student information not found.");
}

// Fetch attendance for selected month
$attendance_sql = "SELECT date, status, notes 
                   FROM attendance 
                   WHERE student_id = :id 
                   AND DATE_FORMAT(date, '%Y-%m') = :selected_month 
                   ORDER BY date DESC";
$attendance_stmt = $pdo->prepare($attendance_sql);
$attendance_stmt->execute([
    ':id' => $student['id'],
    ':selected_month' => $selected_month
]);
$attendance_records = $attendance_stmt->fetchAll();

// Create attendance map for calendar
$attendance_map = [];
foreach ($attendance_records as $record) {
    $attendance_map[$record['date']] = $record;
}

// Calculate attendance statistics
$stats_sql = "SELECT 
    COUNT(*) as total_days,
    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
    SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days
    FROM attendance 
    WHERE student_id = :id 
    AND date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([':id' => $student['id']]);
$stats = $stats_stmt->fetch();

// Get recent months for dropdown
$months_sql = "SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as month 
              FROM attendance 
              WHERE student_id = :id 
              ORDER BY month DESC";
$months_stmt = $pdo->prepare($months_sql);
$months_stmt->execute([':id' => $student['id']]);
$months = $months_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance - Sahab Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.10.0/dist/css/bootstrap-datepicker.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #a5b4fc;
            --secondary-color: #06b6d4;
            --secondary-dark: #0891b2;
            --accent-color: #f59e0b;
            --success-color: #10b981;
            --success-dark: #059669;
            --warning-color: #f59e0b;
            --warning-dark: #d97706;
            --danger-color: #ef4444;
            --danger-dark: #dc2626;
            --info-color: #06b6d4;
            --info-dark: #0891b2;
            --light-bg: #f8fafc;
            --white: #ffffff;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-2xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --border-radius-xl: 16px;
            --border-radius-2xl: 20px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--gray-800);
            line-height: 1.6;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Modern Header */
        .header {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius-2xl);
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(255, 255, 255, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-content h1 {
            font-size: 2rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header-content h1 i {
            color: var(--primary-color);
            font-size: 1.75rem;
        }

        .header-subtitle {
            color: var(--gray-600);
            font-size: 1rem;
            font-weight: 500;
        }

        .nav-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .nav-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            text-decoration: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow);
        }

        .nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
        }

        /* Modern Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Hero Section */
        .hero-section {
            background: linear-gradient(135deg, var(--white), var(--gray-50));
            border-radius: var(--border-radius-2xl);
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color), var(--accent-color));
        }

        .hero-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .hero-title i {
            color: var(--primary-color);
            font-size: 2rem;
        }

        .hero-subtitle {
            color: var(--gray-600);
            font-size: 1.125rem;
            font-weight: 500;
            margin-bottom: 2rem;
        }

        .student-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: linear-gradient(135deg, var(--white), var(--gray-50));
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius-xl);
            padding: 1.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .info-card:hover {
            border-color: var(--primary-color);
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .info-card .icon {
            width: 3rem;
            height: 3rem;
            border-radius: var(--border-radius-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }

        .info-card .icon.bg-primary { background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); color: white; }
        .info-card .icon.bg-secondary { background: linear-gradient(135deg, var(--secondary-color), var(--secondary-dark)); color: white; }
        .info-card .icon.bg-accent { background: linear-gradient(135deg, var(--accent-color), var(--warning-dark)); color: white; }

        .info-card h5 {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .info-card p {
            color: var(--gray-600);
            font-weight: 500;
            margin: 0;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            border: 2px solid transparent;
            background: linear-gradient(145deg, var(--white), #fafbfc);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-2xl);
            border-color: rgba(99, 102, 241, 0.15);
        }

        .stat-card.present::before { background: linear-gradient(90deg, var(--success-color), var(--success-dark)); }
        .stat-card.absent::before { background: linear-gradient(90deg, var(--danger-color), var(--danger-dark)); }
        .stat-card.late::before { background: linear-gradient(90deg, var(--warning-color), var(--warning-dark)); }
        .stat-card.rate::before { background: linear-gradient(90deg, var(--info-color), var(--info-dark)); }

        .stat-icon {
            width: 4rem;
            height: 4rem;
            border-radius: var(--border-radius-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1.5rem;
            position: relative;
        }

        .stat-card.present .stat-icon {
            background: linear-gradient(135deg, var(--success-color), var(--success-dark));
            color: white;
        }

        .stat-card.absent .stat-icon {
            background: linear-gradient(135deg, var(--danger-color), var(--danger-dark));
            color: white;
        }

        .stat-card.late .stat-icon {
            background: linear-gradient(135deg, var(--warning-color), var(--warning-dark));
            color: white;
        }

        .stat-card.rate .stat-icon {
            background: linear-gradient(135deg, var(--info-color), var(--info-dark));
            color: white;
        }

        .stat-value {
            font-size: 3rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-label {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
        }

        .stat-subtitle {
            color: var(--gray-500);
            font-size: 0.875rem;
            font-weight: 500;
        }

        .stat-progress {
            margin-top: 1.5rem;
        }

        .progress {
            height: 8px;
            border-radius: 4px;
            background: var(--gray-200);
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: progressShimmer 2s infinite;
        }

        @keyframes progressShimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* Main Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        @media (min-width: 1024px) {
            .content-grid {
                grid-template-columns: 2fr 1fr;
            }
        }

        /* Calendar Card */
        .calendar-card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            padding: 2.5rem;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--gray-100);
        }

        .calendar-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .calendar-title i {
            color: var(--primary-color);
        }

        .month-selector {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .month-picker {
            background: var(--white);
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius-lg);
            padding: 0.75rem 1rem;
            font-weight: 600;
            color: var(--gray-900);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .month-picker:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            outline: none;
        }

        .calendar-month {
            text-align: center;
            margin-bottom: 2rem;
        }

        .calendar-month h3 {
            font-size: 1.875rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .calendar-month p {
            color: var(--gray-500);
            font-weight: 500;
        }

        .calendar-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0.25rem;
            margin-bottom: 2rem;
        }

        .calendar-header th {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            padding: 1rem;
            font-weight: 700;
            font-size: 0.875rem;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-radius: var(--border-radius);
        }

        .calendar-day {
            width: 3rem;
            height: 3rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--border-radius-lg);
            margin: 0.125rem;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            border: 2px solid transparent;
        }

        .calendar-day:hover {
            transform: scale(1.1);
            box-shadow: var(--shadow-md);
        }

        .calendar-day.present {
            background: linear-gradient(135deg, var(--success-color), var(--success-dark));
            color: white;
            border-color: rgba(16, 185, 129, 0.3);
        }

        .calendar-day.absent {
            background: linear-gradient(135deg, var(--danger-color), var(--danger-dark));
            color: white;
            border-color: rgba(239, 68, 68, 0.3);
        }

        .calendar-day.late {
            background: linear-gradient(135deg, var(--warning-color), var(--warning-dark));
            color: white;
            border-color: rgba(245, 158, 11, 0.3);
        }

        .calendar-day.leave {
            background: linear-gradient(135deg, var(--info-color), var(--info-dark));
            color: white;
            border-color: rgba(6, 182, 212, 0.3);
        }

        .calendar-day.holiday {
            background: linear-gradient(135deg, var(--gray-400), var(--gray-500));
            color: white;
        }

        .calendar-day.weekend {
            background: var(--gray-100);
            color: var(--gray-500);
            opacity: 0.6;
        }

        .calendar-day.today {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
            font-weight: 800;
        }

        .calendar-day.empty {
            background: transparent;
            cursor: default;
        }

        /* Legend */
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--gray-50), var(--white));
            border-radius: var(--border-radius-xl);
            border: 1px solid var(--gray-200);
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
        }

        .legend-dot {
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* Sidebar */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .sidebar-card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .sidebar-card .card-header {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-100);
        }

        .sidebar-card .card-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0;
        }

        .sidebar-card .card-title i {
            color: var(--primary-color);
        }

        /* Monthly Summary */
        .summary-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: linear-gradient(135deg, var(--gray-50), var(--white));
            border-radius: var(--border-radius-lg);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }

        .summary-item:hover {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            transform: translateX(4px);
            box-shadow: var(--shadow-md);
        }

        .summary-label {
            font-weight: 600;
        }

        .summary-badge {
            padding: 0.375rem 0.875rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .summary-badge.present {
            background: linear-gradient(135deg, var(--success-color), var(--success-dark));
            color: white;
        }

        .summary-badge.absent {
            background: linear-gradient(135deg, var(--danger-color), var(--danger-dark));
            color: white;
        }

        .summary-badge.late {
            background: linear-gradient(135deg, var(--warning-color), var(--warning-dark));
            color: white;
        }

        .summary-badge.leave {
            background: linear-gradient(135deg, var(--info-color), var(--info-dark));
            color: white;
        }

        /* Monthly Progress */
        .monthly-progress {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            color: white;
            margin-top: 1rem;
        }

        .progress-title {
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .progress-value {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 1rem;
        }

        .progress-bar-container {
            background: rgba(255, 255, 255, 0.2);
            height: 12px;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Month History */
        .month-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .month-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: var(--gray-50);
            border-radius: var(--border-radius-lg);
            text-decoration: none;
            color: var(--gray-700);
            transition: all 0.3s ease;
            border: 1px solid var(--gray-200);
        }

        .month-item:hover {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            transform: translateX(4px);
            box-shadow: var(--shadow-md);
        }

        .month-item.active {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            box-shadow: var(--shadow-md);
        }

        .month-name {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .month-name i {
            font-size: 0.875rem;
        }

        /* Download Button */
        .download-section {
            text-align: center;
            margin-top: 2rem;
        }

        .download-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: var(--border-radius-xl);
            font-weight: 700;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            box-shadow: var(--shadow-lg);
        }

        .download-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-2xl);
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
        }

        .download-btn i {
            font-size: 1.125rem;
        }

        /* Modal */
        .modal-content {
            border-radius: var(--border-radius-xl);
            border: none;
            box-shadow: var(--shadow-2xl);
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border-radius: var(--border-radius-xl) var(--border-radius-xl) 0 0;
            padding: 1.5rem 2rem;
            border: none;
        }

        .modal-title {
            font-weight: 700;
            font-size: 1.25rem;
        }

        .modal-body {
            padding: 2rem;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 0.5rem;
            }

            .header {
                padding: 1rem;
                margin-bottom: 1rem;
            }

            .hero-section {
                padding: 1.5rem;
            }

            .hero-title {
                font-size: 2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .stat-card {
                padding: 1.5rem;
            }

            .stat-value {
                font-size: 2.5rem;
            }

            .calendar-card {
                padding: 1.5rem;
            }

            .calendar-day {
                width: 2.5rem;
                height: 2.5rem;
                font-size: 0.75rem;
            }

            .sidebar-card {
                padding: 1.5rem;
            }

            .content-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .hero-title {
                font-size: 1.75rem;
                flex-direction: column;
                gap: 0.5rem;
            }

            .student-info {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .calendar-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .month-selector {
                justify-content: center;
            }

            .legend {
                flex-direction: column;
                align-items: center;
            }

            .summary-item {
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }
        }

        /* Animations */
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

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .animate-fade-in {
            animation: fadeInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .animate-scale-in {
            animation: scaleIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Loading States */
        .loading {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .loading::after {
            content: '';
            width: 1rem;
            height: 1rem;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>
<body>
    <!-- Modern Header -->
    <div class="header">
        <div class="header-content">
            <h1 class="animate-fade-in">
                <?php echo htmlspecialchars($student['full_name']); ?>
            </h1>
            <p class="header-subtitle">
                <i class="bi bi-mortarboard-fill"></i>
                <?php echo htmlspecialchars($student['class_name']); ?> |
                <i class="bi bi-person-badge-fill"></i>
                Admission: <?php echo htmlspecialchars($student['admission_no']); ?>
            </p>
        </div>
        <div class="nav-buttons">
            <a href="dashboard.php" class="nav-btn">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="logout.php" class="nav-btn nav-btn-secondary">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>

    <div class="container">
        <!-- Hero Section -->
        <div class="hero-section animate-scale-in">
            <div class="hero-title">
                <i class="bi bi-calendar-check"></i>
                Attendance Overview
            </div>
            <p class="hero-subtitle">Track your attendance records and stay on top of your academic performance</p>

            <div class="student-info">
                <div class="info-card">
                    <div class="icon bg-primary">
                        <i class="bi bi-person-circle"></i>
                    </div>
                    <div>
                        <h5><?php echo htmlspecialchars($student['full_name']); ?></h5>
                        <p>Student Profile</p>
                    </div>
                </div>

                <div class="info-card">
                    <div class="icon bg-secondary">
                        <i class="bi bi-mortarboard"></i>
                    </div>
                    <div>
                        <h5><?php echo htmlspecialchars($student['class_name']); ?></h5>
                        <p>Current Class</p>
                    </div>
                </div>

                <div class="info-card">
                    <div class="icon bg-accent">
                        <i class="bi bi-calendar-event"></i>
                    </div>
                    <div>
                        <h5><?php echo date('F Y', strtotime($selected_month . '-01')); ?></h5>
                        <p>Viewing Month</p>
                    </div>
                </div>
            </div>

            <!-- Month Selector -->
            <div class="month-selector">
                <form method="GET" action="" class="d-inline-flex align-items-center gap-3">
                    <label class="fw-bold text-white">Select Month:</label>
                    <input type="text" class="monthpicker" name="month" value="<?php echo $selected_month; ?>">
                    <button type="submit" class="nav-btn">
                        <i class="bi bi-search"></i> View
                    </button>
                </form>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <?php
            $attendance_rate = ($stats['total_days'] > 0)
                ? round(($stats['present_days'] / $stats['total_days']) * 100, 1)
                : 0;
            ?>

            <div class="stat-card present animate-fade-in">
                <div class="stat-icon">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $stats['present_days']; ?></div>
                    <div class="stat-label">Present Days</div>
                    <div class="stat-subtitle">Last 3 months</div>
                    <div class="stat-progress">
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo $stats['total_days'] > 0 ? ($stats['present_days'] / $stats['total_days'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="stat-card absent animate-fade-in">
                <div class="stat-icon">
                    <i class="bi bi-x-circle-fill"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $stats['absent_days']; ?></div>
                    <div class="stat-label">Absent Days</div>
                    <div class="stat-subtitle">Last 3 months</div>
                    <div class="stat-progress">
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo $stats['total_days'] > 0 ? ($stats['absent_days'] / $stats['total_days'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="stat-card late animate-fade-in">
                <div class="stat-icon">
                    <i class="bi bi-clock-fill"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $stats['late_days']; ?></div>
                    <div class="stat-label">Late Days</div>
                    <div class="stat-subtitle">Last 3 months</div>
                    <div class="stat-progress">
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo $stats['total_days'] > 0 ? ($stats['late_days'] / $stats['total_days'] * 100) : 0; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="stat-card rate animate-fade-in">
                <div class="stat-icon">
                    <i class="bi bi-graph-up"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $attendance_rate; ?><span class="stat-unit">%</span></div>
                    <div class="stat-label">Attendance Rate</div>
                    <div class="stat-subtitle">Overall Performance</div>
                    <div class="stat-progress">
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo $attendance_rate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Calendar Section -->
            <div class="calendar-card animate-fade-in">
                <div class="calendar-header">
                    <div class="calendar-title">
                        <i class="bi bi-calendar3"></i>
                        Monthly Calendar
                    </div>
                </div>

                <div class="calendar-month">
                    <h3><?php echo date('F Y', strtotime("$year-$month-01")); ?></h3>
                    <p><?php echo date('l, F j, Y', strtotime("$year-$month-01")); ?> - <?php echo date('l, F j, Y', strtotime("$year-$month-$days_in_month")); ?></p>
                </div>

                <div class="calendar-container">
                    <table class="calendar-table">
                        <thead>
                            <tr>
                                <th class="calendar-header">Mon</th>
                                <th class="calendar-header">Tue</th>
                                <th class="calendar-header">Wed</th>
                                <th class="calendar-header">Thu</th>
                                <th class="calendar-header">Fri</th>
                                <th class="calendar-header">Sat</th>
                                <th class="calendar-header">Sun</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $day_counter = 1;
                            for ($i = 0; $i < 6; $i++) {
                                echo '<tr>';
                                for ($j = 1; $j <= 7; $j++) {
                                    if (($i == 0 && $j < $first_day) || $day_counter > $days_in_month) {
                                        echo '<td><div class="calendar-day empty"></div></td>';
                                    } else {
                                        $current_date = sprintf("%04d-%02d-%02d", $year, $month, $day_counter);
                                        $date_str = date('Y-m-d', strtotime($current_date));
                                        $day_of_week = date('N', strtotime($current_date));

                                        $class = '';
                                        $title = date('l, F j, Y', strtotime($current_date));

                                        // Check if today
                                        if ($date_str == $today) {
                                            $class .= ' today';
                                            $title .= ' (Today)';
                                        }

                                        // Check weekend
                                        if ($day_of_week >= 6) {
                                            $class .= ' weekend';
                                            $title .= ' (Weekend)';
                                        }

                                        // Check attendance
                                        if (isset($attendance_map[$date_str])) {
                                            $status = $attendance_map[$date_str]['status'];
                                            $class .= ' ' . $status;
                                            $title .= ' - ' . ucfirst($status);
                                            if ($attendance_map[$date_str]['notes']) {
                                                $title .= ' - ' . htmlspecialchars($attendance_map[$date_str]['notes']);
                                            }
                                        }

                                        echo '<td>';
                                        echo '<div class="calendar-day' . $class . '" title="' . $title . '" onclick="showDayDetails(\'' . $date_str . '\')">';
                                        echo '<span class="day-number">' . date('j', strtotime($current_date)) . '</span>';
                                        echo '</div>';
                                        echo '</td>';

                                        $day_counter++;
                                    }
                                }
                                echo '</tr>';
                                if ($day_counter > $days_in_month) break;
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- Legend -->
                <div class="legend">
                    <div class="legend-item">
                        <div class="legend-dot" style="background: linear-gradient(135deg, var(--success-color), var(--success-dark));"></div>
                        <span>Present</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot" style="background: linear-gradient(135deg, var(--danger-color), var(--danger-dark));"></div>
                        <span>Absent</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot" style="background: linear-gradient(135deg, var(--warning-color), var(--warning-dark));"></div>
                        <span>Late</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot" style="background: linear-gradient(135deg, var(--info-color), var(--info-dark));"></div>
                        <span>Leave</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot" style="background: var(--gray-100); border: 1px solid var(--gray-300);"></div>
                        <span>Weekend</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot" style="border: 3px solid var(--primary-color); background: transparent;"></div>
                        <span>Today</span>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="sidebar">
                <!-- Monthly Summary -->
                <div class="sidebar-card animate-fade-in">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="bi bi-bar-chart"></i>
                            Monthly Summary
                        </h3>
                    </div>

                    <div class="summary-list">
                        <?php
                        $monthly_counts = [
                            'present' => 0,
                            'absent' => 0,
                            'late' => 0,
                            'leave' => 0
                        ];

                        foreach ($attendance_records as $record) {
                            $monthly_counts[$record['status']]++;
                        }

                        foreach($monthly_counts as $status => $count):
                            if($count > 0):
                        ?>
                        <div class="summary-item">
                            <span class="summary-label"><?php echo ucfirst($status); ?> Days</span>
                            <span class="summary-badge <?php echo $status; ?>"><?php echo $count; ?></span>
                        </div>
                        <?php
                            endif;
                        endforeach;

                        $total_monthly_days = array_sum($monthly_counts);
                        $monthly_rate = ($total_monthly_days > 0)
                            ? round(($monthly_counts['present'] / $total_monthly_days) * 100, 1)
                            : 0;
                        ?>

                        <div class="monthly-progress">
                            <div class="progress-title">Monthly Attendance Rate</div>
                            <div class="progress-value"><?php echo $monthly_rate; ?>%</div>
                            <div class="progress-bar-container">
                                <div class="progress-fill" style="width: <?php echo $monthly_rate; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Previous Months -->
                <div class="sidebar-card animate-fade-in">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="bi bi-clock-history"></i>
                            Previous Months
                        </h3>
                    </div>

                    <div class="month-list">
                        <?php foreach($months as $month_row): ?>
                        <a href="?month=<?php echo $month_row['month']; ?>"
                           class="month-item <?php echo ($selected_month == $month_row['month']) ? 'active' : ''; ?>">
                            <div class="month-name">
                                <i class="bi bi-calendar-month"></i>
                                <?php echo date('F Y', strtotime($month_row['month'] . '-01')); ?>
                            </div>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Download Section -->
                <div class="sidebar-card animate-fade-in">
                    <div class="download-section">
                        <a href="generate_attendance_pdf.php?month=<?php echo $selected_month; ?>" class="download-btn" target="_blank">
                            <i class="bi bi-download"></i>
                            Download Report
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Day Details Modal -->
    <div class="modal fade" id="dayModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Attendance Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="dayDetails">
                    Loading...
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/js/bootstrap-datepicker.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.monthpicker').datepicker({
                format: 'yyyy-mm',
                startView: 'months',
                minViewMode: 'months',
                autoclose: true
            });
        });

        function showDayDetails(dateStr) {
            $('#dayDetails').html('<div class="text-center"><div class="spinner-border"></div></div>');
            
            $.ajax({
                url: 'ajax/get_day_details.php',
                method: 'POST',
                data: { 
                    date: dateStr,
                    user_id: <?php echo $student['id']; ?>
                },
                success: function(response) {
                    $('#dayDetails').html(response);
                    $('#dayModal').modal('show');
                }
            });
        }


    </script>
</body>
</html>
