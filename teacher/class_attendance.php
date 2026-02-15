<?php
// teacher/class_attendance.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'principal'])) {
    header('Location: ../index.php');
    exit();
}


// Get current school context
$current_school_id = require_school_auth();
$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'];
$current_date = date('Y-m-d');
$selected_date = $_GET['date'] ?? $current_date;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = $current_date;
}

// Get teacher's assigned classes - school-filtered
$assigned_classes_sql = "SELECT c.id, c.class_name
                        FROM class_teachers ct
                        JOIN classes c ON ct.class_id = c.id
                        WHERE ct.teacher_id = :teacher_id AND c.school_id = :school_id";
$assigned_stmt = $pdo->prepare($assigned_classes_sql);
$assigned_stmt->execute([':teacher_id' => $teacher_id, ':school_id' => $current_school_id]);
$assigned_classes = $assigned_stmt->fetchAll();

$assigned_class_ids = array_column($assigned_classes, 'id');
$assigned_classes_map = array_column($assigned_classes, 'class_name', 'id');

if (empty($assigned_class_ids)) {
    die("No classes assigned to you.");
}

$selected_class = isset($_GET['class_id']) && in_array((int)$_GET['class_id'], array_map('intval', $assigned_class_ids), true)
    ? (int)$_GET['class_id']
    : $assigned_class_ids[0];

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $attendance_date = $_POST['attendance_date'] ?? '';
    $class_id = (int)($_POST['class_id'] ?? 0);
    $allowed_statuses = ['present', 'absent', 'late', 'leave'];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendance_date)) {
        $_SESSION['error'] = "Invalid attendance date format.";
        header("Location: class_attendance.php");
        exit();
    }

    if (!in_array($class_id, array_map('intval', $assigned_class_ids), true)) {
        $_SESSION['error'] = "You are not authorized to submit attendance for this class.";
        header("Location: class_attendance.php");
        exit();
    }
    
    try {
        $pdo->beginTransaction();

        // Limit updates strictly to students in selected class and school
        $student_ids_stmt = $pdo->prepare("SELECT id FROM students WHERE class_id = :class_id AND school_id = :school_id");
        $student_ids_stmt->execute([
            ':class_id' => $class_id,
            ':school_id' => $current_school_id
        ]);
        $valid_student_ids = array_map('intval', array_column($student_ids_stmt->fetchAll(), 'id'));
        $valid_student_lookup = array_flip($valid_student_ids);
        
        $insert_count = 0;
        $update_count = 0;

        $posted_attendance = $_POST['attendance'] ?? [];
        foreach ($posted_attendance as $student_id => $status) {
            $student_id = (int)$student_id;
            if (!isset($valid_student_lookup[$student_id])) {
                continue;
            }
            if (!in_array($status, $allowed_statuses, true)) {
                $status = 'absent';
            }
            $remarks = trim((string)($_POST['remarks'][$student_id] ?? ''));

            // Check if record exists for this student and date
            $check_sql = "SELECT id FROM attendance WHERE student_id = :student_id AND date = :date AND school_id = :school_id";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([
                ':student_id' => $student_id,
                ':date' => $attendance_date,
                ':school_id' => $current_school_id
            ]);
            $existing_record = $check_stmt->fetch();
            
            if ($existing_record) {
                // Update existing record
                $sql = "UPDATE attendance SET status = :status, recorded_by = :recorded_by, notes = :notes, recorded_at = NOW() 
                        WHERE student_id = :student_id AND date = :date AND school_id = :school_id";
                $update_count++;
            } else {
                // Insert new record
                $sql = "INSERT INTO attendance (student_id, class_id, date, status, recorded_by, notes, school_id, recorded_at) 
                        VALUES (:student_id, :class_id, :date, :status, :recorded_by, :notes, :school_id, NOW())";
                $insert_count++;
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':student_id' => $student_id,
                ':class_id' => $class_id,
                ':date' => $attendance_date,
                ':status' => $status,
                ':recorded_by' => $teacher_id,
                ':notes' => $remarks,
                ':school_id' => $current_school_id
            ]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Attendance submitted successfully! ($insert_count new, $update_count updated)";
        header("Location: class_attendance.php?date=$attendance_date&class_id=$class_id");
        exit();
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        error_log("ATTENDANCE ERROR: " . $e->getMessage());
        error_log("ATTENDANCE ERROR: SQL State - " . $e->getCode());
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    } catch(Exception $e) {
        $pdo->rollBack();
        error_log("ATTENDANCE ERROR: " . $e->getMessage());
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
}

// Fetch students for selected class
$students_sql = "SELECT s.id, s.full_name, s.admission_no, a.status, a.notes 
                FROM students s 
                LEFT JOIN attendance a ON s.id = a.student_id AND a.date = :selected_date AND a.school_id = :attendance_school_id
                WHERE s.class_id = :class_id AND s.school_id = :student_school_id
                ORDER BY s.full_name";
$students_stmt = $pdo->prepare($students_sql);
$students_stmt->execute([
    ':selected_date' => $selected_date,
    ':class_id' => $selected_class,
    ':attendance_school_id' => $current_school_id,
    ':student_school_id' => $current_school_id
]);
$students = $students_stmt->fetchAll();

// Fetch class attendance statistics
$stats_sql = "SELECT 
    COUNT(DISTINCT s.id) as total_students,
    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
    SUM(CASE WHEN a.status = 'leave' THEN 1 ELSE 0 END) as leave_count
    FROM students s 
    LEFT JOIN attendance a ON s.id = a.student_id AND a.date = :selected_date AND a.school_id = :attendance_school_id
    WHERE s.class_id = :class_id AND s.school_id = :student_school_id";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([
    ':selected_date' => $selected_date,
    ':class_id' => $selected_class,
    ':attendance_school_id' => $current_school_id,
    ':student_school_id' => $current_school_id
]);
$stats = $stats_stmt->fetch();

// Fetch monthly attendance summary
$monthly_sql = "SELECT 
    DATE_FORMAT(date, '%Y-%m') as month,
    COUNT(*) as total_days,
    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
    AVG(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100 as attendance_rate
    FROM attendance 
    WHERE school_id = :attendance_school_id
    AND student_id IN (SELECT id FROM students WHERE class_id = :class_id AND school_id = :student_school_id)
    AND date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
    GROUP BY DATE_FORMAT(date, '%Y-%m')
    ORDER BY month DESC";
$monthly_stmt = $pdo->prepare($monthly_sql);
$monthly_stmt->execute([
    ':class_id' => $selected_class,
    ':attendance_school_id' => $current_school_id,
    ':student_school_id' => $current_school_id
]);
$monthly_data = $monthly_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Attendance | SahabFormMaster</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-50: #eff6ff;
            --primary-100: #dbeafe;
            --primary-200: #bfdbfe;
            --primary-300: #93c5fd;
            --primary-400: #60a5fa;
            --primary-500: #3b82f6;
            --primary-600: #2563eb;
            --primary-700: #1d4ed8;
            --primary-800: #1e40af;
            --primary-900: #1e3a8a;

            --accent-50: #fdf4ff;
            --accent-100: #fae8ff;
            --accent-200: #f5d0fe;
            --accent-300: #f0abfc;
            --accent-400: #e879f9;
            --accent-500: #d946ef;
            --accent-600: #c026d3;
            --accent-700: #a21caf;
            --accent-800: #86198f;
            --accent-900: #701a75;

            --success-50: #f0fdf4;
            --success-100: #dcfce7;
            --success-500: #22c55e;
            --success-600: #16a34a;
            --success-700: #15803d;

            --error-50: #fef2f2;
            --error-100: #fee2e2;
            --error-500: #ef4444;
            --error-600: #dc2626;

            --warning-50: #fffbeb;
            --warning-100: #fef3c7;
            --warning-500: #f59e0b;
            --warning-600: #d97706;

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

            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 32px rgba(0, 0, 0, 0.12);
            --shadow-strong: 0 16px 48px rgba(0, 0, 0, 0.15);

            --gradient-primary: linear-gradient(135deg, var(--primary-500) 0%, var(--primary-700) 100%);
            --gradient-accent: linear-gradient(135deg, var(--accent-500) 0%, var(--accent-700) 100%);
            --gradient-bg: linear-gradient(135deg, var(--primary-50) 0%, var(--accent-50) 50%, var(--primary-100) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--gradient-bg);
            color: var(--gray-800);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* Modern Header */
        .modern-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-soft);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-container {
            width: 56px;
            height: 56px;
            background: var(--gradient-primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: var(--shadow-medium);
        }

        .brand-text h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.125rem;
        }

        .brand-text p {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 500;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 12px;
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .back-btn:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--gradient-accent);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .user-details p {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.125rem;
        }

        .user-details span {
            font-weight: 600;
            color: var(--gray-900);
        }

        .logout-btn {
            padding: 0.75rem 1.25rem;
            background: var(--error-500);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: var(--error-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Main Container */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Modern Cards */
        .modern-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            transition: all 0.3s ease;
            margin-bottom: 2rem;
        }

        .modern-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-strong);
        }

        .card-header-modern {
            padding: 2rem;
            background: var(--gradient-primary);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .card-header-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.05)"/><circle cx="10" cy="90" r="0.5" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .card-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .card-subtitle-modern {
            font-size: 1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .card-body-modern {
            padding: 2rem;
        }

        /* Statistics Grid */
        .stats-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-soft);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }

        .stat-card-modern:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-strong);
        }

        .stat-icon-modern {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        .stat-total .stat-icon-modern {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-present .stat-icon-modern {
            background: var(--gradient-success);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-absent .stat-icon-modern {
            background: var(--gradient-error);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-late .stat-icon-modern {
            background: var(--gradient-warning);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-value-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-label-modern {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Form Controls */
        .controls-modern {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .form-row-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group-modern {
            position: relative;
        }

        .form-label-modern {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            letter-spacing: 0.025em;
        }

        .form-input-modern {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-input-modern:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .form-input-modern::placeholder {
            color: var(--gray-400);
        }

        .btn-modern-primary {
            padding: 1rem 2rem;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-medium);
        }

        .btn-modern-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-strong);
        }

        /* Quick Actions */
        .actions-modern {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .actions-grid-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .action-btn-modern {
            padding: 1.25rem 1.5rem;
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--gray-700);
            box-shadow: var(--shadow-soft);
            position: relative;
            overflow: hidden;
        }

        .action-btn-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.1), transparent);
            transition: left 0.5s;
        }

        .action-btn-modern:hover::before {
            left: 100%;
        }

        .action-btn-modern:hover {
            transform: translateY(-4px);
            border-color: var(--primary-300);
            box-shadow: var(--shadow-strong);
        }

        .action-icon-modern {
            font-size: 1.5rem;
            color: var(--primary-600);
            transition: transform 0.3s ease;
        }

        .action-btn-modern:hover .action-icon-modern {
            transform: scale(1.1);
        }

        .action-text-modern {
            font-weight: 600;
            font-size: 0.875rem;
            text-align: center;
        }

        /* Attendance Table */
        .attendance-table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-soft);
        }

        .table-header-modern {
            background: var(--gradient-primary);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .table-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .attendance-rate-modern {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .table-wrapper-modern {
            overflow-x: auto;
        }

        .attendance-table-modern {
            width: 100%;
            border-collapse: collapse;
        }

        .attendance-table-modern th {
            background: var(--gray-50);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 1.25rem 1.5rem;
            text-align: left;
            border-bottom: 2px solid var(--gray-200);
        }

        .attendance-table-modern td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.2s ease;
        }

        .attendance-table-modern tr:nth-child(even) {
            background: var(--gray-50);
        }

        .attendance-table-modern tr:hover {
            background: var(--primary-50);
        }

        .student-number-modern {
            font-weight: 600;
            color: var(--gray-900);
        }

        .admission-number-modern {
            font-weight: 500;
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        .student-name-modern {
            font-weight: 600;
            color: var(--gray-900);
            font-size: 1.125rem;
        }

        .status-buttons-modern {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .status-btn-modern {
            padding: 0.5rem 0.875rem;
            border: 2px solid transparent;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .status-present-modern {
            background: var(--success-100);
            color: var(--success-700);
            border-color: var(--success-200);
        }

        .status-present-modern.active {
            background: var(--success-500);
            color: white;
            border-color: var(--success-500);
        }

        .status-absent-modern {
            background: var(--error-100);
            color: var(--error-700);
            border-color: var(--error-200);
        }

        .status-absent-modern.active {
            background: var(--error-500);
            color: white;
            border-color: var(--error-500);
        }

        .status-late-modern {
            background: var(--warning-100);
            color: var(--warning-700);
            border-color: var(--warning-200);
        }

        .status-late-modern.active {
            background: var(--warning-500);
            color: white;
            border-color: var(--warning-500);
        }

        .status-leave-modern {
            background: var(--accent-100);
            color: var(--accent-700);
            border-color: var(--accent-200);
        }

        .status-leave-modern.active {
            background: var(--accent-500);
            color: white;
            border-color: var(--accent-500);
        }

        .remarks-input-modern {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            font-size: 0.875rem;
            background: white;
            transition: all 0.3s ease;
        }

        .remarks-input-modern:focus {
            outline: none;
            border-color: var(--primary-400);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .history-btn-modern {
            padding: 0.75rem 1.25rem;
            background: var(--primary-500);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .history-btn-modern:hover {
            background: var(--primary-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Submit Section */
        .submit-section-modern {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin-top: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .submit-btn-modern {
            padding: 1.25rem 3rem;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 1.125rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: var(--shadow-medium);
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .submit-btn-modern:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-strong);
        }

        /* Alerts */
        .alert-modern {
            padding: 1.25rem 1.5rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            font-weight: 500;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .alert-success-modern {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success-700);
            border-left: 4px solid var(--success-500);
        }

        .alert-error-modern {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-700);
            border-left: 4px solid var(--error-500);
        }

        /* Footer */
        .footer-modern {
            background: var(--gray-900);
            color: var(--gray-300);
            padding: 3rem 2rem 2rem;
            margin-top: 4rem;
            position: relative;
        }

        .footer-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gray-700), transparent);
        }

        .footer-content-modern {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .footer-section-modern h4 {
            color: white;
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 1rem;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .footer-section-modern p {
            margin-bottom: 0.75rem;
            line-height: 1.6;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header-content {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
            }

            .main-container {
                padding: 1rem;
            }

            .stats-modern {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .form-row-modern {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .actions-grid-modern {
                grid-template-columns: repeat(2, 1fr);
            }

            .table-header-modern {
                padding: 1rem;
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }

            .attendance-table-modern th,
            .attendance-table-modern td {
                padding: 0.75rem;
                font-size: 0.8rem;
            }

            .status-buttons-modern {
                flex-direction: column;
                gap: 0.25rem;
            }

            .status-btn-modern {
                padding: 0.375rem 0.5rem;
                font-size: 0.7rem;
            }
        }

        @media (max-width: 480px) {
            .stats-modern {
                grid-template-columns: 1fr;
            }

            .actions-grid-modern {
                grid-template-columns: 1fr;
            }

            .modern-card {
                margin-bottom: 1rem;
            }

            .card-header-modern,
            .card-body-modern {
                padding: 1.5rem;
            }

            .stat-card-modern {
                padding: 1.5rem;
            }

            .stat-icon-modern {
                width: 48px;
                height: 48px;
                font-size: 1.25rem;
            }

            .stat-value-modern {
                font-size: 2rem;
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

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .animate-slide-in-left {
            animation: slideInLeft 0.6s ease-out;
        }

        .animate-slide-in-right {
            animation: slideInRight 0.6s ease-out;
        }

        /* Utility Classes */
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }
        .font-semibold { font-weight: 600; }
        .font-medium { font-weight: 500; }

        .gradient-success { background: linear-gradient(135deg, var(--success-500) 0%, var(--success-600) 100%); }
        .gradient-error { background: linear-gradient(135deg, var(--error-500) 0%, var(--error-600) 100%); }
        .gradient-warning { background: linear-gradient(135deg, var(--warning-500) 0%, var(--warning-600) 100%); }
    </style>
</head>
<body>
    <!-- Modern Header -->
    <header class="modern-header">
        <div class="header-content">
            <div class="header-brand">
                <a href="index.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
                <div class="logo-container">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="brand-text">
                    <h1>SahabFormMaster</h1>
                    <p>Class Attendance</p>
                </div>
            </div>
            <div class="header-actions">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($teacher_name, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <p>Teacher</p>
                        <span><?php echo htmlspecialchars($teacher_name); ?></span>
                    </div>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Welcome Section -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern">
                <h2 class="card-title-modern">
                    <i class="fas fa-calendar-check"></i>
                    Class Attendance Management
                </h2>
                <p class="card-subtitle-modern">
                    Efficiently track and manage student attendance for <?php echo htmlspecialchars($assigned_classes_map[$selected_class]); ?>
                </p>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-modern">
            <?php
            $attendance_rate = ($stats['total_students'] > 0)
                ? round(($stats['present_count'] / $stats['total_students']) * 100, 1)
                : 0;
            ?>
            <div class="stat-card-modern stat-total animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value-modern"><?php echo $stats['total_students']; ?></div>
                <div class="stat-label-modern">Total Students</div>
            </div>

            <div class="stat-card-modern stat-present animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value-modern"><?php echo $stats['present_count']; ?></div>
                <div class="stat-label-modern">Present Today</div>
            </div>

            <div class="stat-card-modern stat-absent animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-value-modern"><?php echo $stats['absent_count']; ?></div>
                <div class="stat-label-modern">Absent Today</div>
            </div>

            <div class="stat-card-modern stat-late animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value-modern"><?php echo $stats['late_count']; ?></div>
                <div class="stat-label-modern">Late Today</div>
            </div>

            <div class="stat-card-modern stat-total animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="stat-value-modern"><?php echo $stats['leave_count']; ?></div>
                <div class="stat-label-modern">On Leave</div>
            </div>
        </div>

        <!-- Controls Section -->
        <div class="controls-modern animate-fade-in-up">
            <form method="GET" action="" id="filterForm">
                <div class="form-row-modern">
                    <div class="form-group-modern">
                        <label class="form-label-modern">Select Date</label>
                        <input type="date" class="form-input-modern" name="date" value="<?php echo $selected_date; ?>">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Select Class</label>
                        <select class="form-input-modern" name="class_id">
                            <?php foreach($assigned_classes_map as $id => $name): ?>
                                <option value="<?php echo $id; ?>" <?php echo ($selected_class == $id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">&nbsp;</label>
                        <button type="submit" class="btn-modern-primary">
                            <i class="fas fa-search"></i>
                            <span>Load Attendance</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Quick Actions -->
        <div class="actions-modern animate-fade-in-up">
            <div class="actions-grid-modern">
                <button class="action-btn-modern" onclick="markAllStatus('present')">
                    <i class="fas fa-check-circle action-icon-modern"></i>
                    <span class="action-text-modern">Mark All Present</span>
                </button>
                <button class="action-btn-modern" onclick="markAllStatus('absent')" style="border-color: var(--error-500);">
                    <i class="fas fa-times-circle action-icon-modern" style="color: var(--error-500);"></i>
                    <span class="action-text-modern">Mark All Absent</span>
                </button>
                <button class="action-btn-modern" onclick="markAllStatus('late')" style="border-color: var(--warning-500);">
                    <i class="fas fa-clock action-icon-modern" style="color: var(--warning-500);"></i>
                    <span class="action-text-modern">Mark All Late</span>
                </button>
                <button class="action-btn-modern" onclick="markAllStatus('leave')" style="border-color: var(--accent-500);">
                    <i class="fas fa-envelope action-icon-modern" style="color: var(--accent-500);"></i>
                    <span class="action-text-modern">Mark All Leave</span>
                </button>
                <button class="action-btn-modern" onclick="submitAttendance()">
                    <i class="fas fa-save action-icon-modern"></i>
                    <span class="action-text-modern">Save Attendance</span>
                </button>
                <button class="action-btn-modern" onclick="printAttendance()">
                    <i class="fas fa-print action-icon-modern"></i>
                    <span class="action-text-modern">Print Report</span>
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert-modern alert-success-modern animate-fade-in-up">
                <i class="fas fa-check-circle"></i>
                <span><?php echo Security::secureOutput($_SESSION['success']); unset($_SESSION['success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert-modern alert-error-modern animate-fade-in-up">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
            </div>
        <?php endif; ?>

        <!-- Attendance Table -->
        <div class="attendance-table-container animate-fade-in-up">
            <div class="table-header-modern">
                <div class="table-title-modern">
                    <i class="fas fa-clipboard-list"></i>
                    <?php echo htmlspecialchars($assigned_classes_map[$selected_class]); ?> - <?php echo date('F j, Y', strtotime($selected_date)); ?>
                </div>
                <div class="attendance-rate-modern">
                    Attendance Rate: <?php echo $attendance_rate; ?>%
                </div>
            </div>

            <div class="table-wrapper-modern">
                <form method="POST" action="" id="attendanceForm">
                    <input type="hidden" name="attendance_date" value="<?php echo $selected_date; ?>">
                    <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                    <input type="hidden" name="submit_attendance" value="1">

                    <table class="attendance-table-modern">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Admission No</th>
                                <th>Student Name</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach($students as $student): ?>
                                <?php $current_status = $student['status'] ?: 'absent'; ?>
                                <tr>
                                    <td>
                                        <span class="student-number-modern"><?php echo $counter++; ?></span>
                                    </td>
                                    <td>
                                        <span class="admission-number-modern"><?php echo htmlspecialchars($student['admission_no']); ?></span>
                                    </td>
                                    <td>
                                        <span class="student-name-modern"><?php echo htmlspecialchars($student['full_name']); ?></span>
                                    </td>
                                    <td>
                                        <div class="status-buttons-modern">
                                            <button type="button" class="status-btn-modern status-present-modern <?php echo ($current_status == 'present') ? 'active' : ''; ?>"
                                                    data-student="<?php echo $student['id']; ?>"
                                                    data-status="present">
                                                <i class="fas fa-check"></i>
                                                <span>P</span>
                                            </button>
                                            <button type="button" class="status-btn-modern status-absent-modern <?php echo ($current_status == 'absent') ? 'active' : ''; ?>"
                                                    data-student="<?php echo $student['id']; ?>"
                                                    data-status="absent">
                                                <i class="fas fa-times"></i>
                                                <span>A</span>
                                            </button>
                                            <button type="button" class="status-btn-modern status-late-modern <?php echo ($current_status == 'late') ? 'active' : ''; ?>"
                                                    data-student="<?php echo $student['id']; ?>"
                                                    data-status="late">
                                                <i class="fas fa-clock"></i>
                                                <span>L</span>
                                            </button>
                                            <button type="button" class="status-btn-modern status-leave-modern <?php echo ($current_status == 'leave') ? 'active' : ''; ?>"
                                                    data-student="<?php echo $student['id']; ?>"
                                                    data-status="leave">
                                                <i class="fas fa-envelope"></i>
                                                <span>LV</span>
                                            </button>
                                        </div>
                                        <input type="hidden" name="attendance[<?php echo $student['id']; ?>]"
                                               id="status_<?php echo $student['id']; ?>"
                                               value="<?php echo $current_status; ?>">
                                    </td>
                                    <td>
                                        <input type="text" class="remarks-input-modern"
                                               name="remarks[<?php echo $student['id']; ?>]"
                                               value="<?php echo htmlspecialchars($student['notes'] ?? ''); ?>"
                                               placeholder="Optional remarks">
                                    </td>
                                    <td>
                                        <button type="button" class="history-btn-modern"
                                                onclick="showStudentHistory(<?php echo $student['id']; ?>)">
                                            <i class="fas fa-history"></i>
                                            <span>History</span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
            </div>
        </div>

        <!-- Submit Section -->
        <div class="submit-section-modern animate-fade-in-up">
            <button type="submit" class="submit-btn-modern" form="attendanceForm">
                <i class="fas fa-save"></i>
                <span>Submit Attendance</span>
            </button>
        </div>

        
    </div>

    <!-- Student History Modal -->
    <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: none; z-index: 1050; backdrop-filter: blur(8px);" id="historyModal">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 20px; width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto; box-shadow: var(--shadow-strong);">
            <div style="padding: 2rem; border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 1.5rem; font-weight: 700; color: var(--gray-900);">Student Attendance History</h3>
                <button style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--gray-400); padding: 0.5rem; border-radius: 8px; transition: all 0.2s;" onclick="closeHistoryModal()">×</button>
            </div>
            <div style="padding: 2rem;" id="historyContent">
                <div style="text-align: center; padding: 2rem;">
                    <div style="display: inline-block; width: 48px; height: 48px; border: 4px solid var(--primary-200); border-top: 4px solid var(--primary-500); border-radius: 50%; animation: spin 1s linear infinite;"></div>
                    <p style="margin-top: 1rem; color: var(--gray-600);">Loading history...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Status button click handler for modern buttons
        document.querySelectorAll('.status-btn-modern').forEach(btn => {
            btn.addEventListener('click', function() {
                const studentId = this.dataset.student;
                const status = this.dataset.status;

                // Update hidden input
                document.getElementById('status_' + studentId).value = status;

                // Update button active state - find all buttons in the same container
                const container = this.closest('.status-buttons-modern');
                container.querySelectorAll('.status-btn-modern').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
            });
        });

        function markAllStatus(status) {
            document.querySelectorAll(`.status-btn-modern[data-status="${status}"]`).forEach(btn => btn.click());
        }

        function submitAttendance() {
            if (confirm('Are you sure you want to submit attendance for <?php echo date('F j, Y', strtotime($selected_date)); ?>?')) {
                document.getElementById('attendanceForm').submit();
            }
        }

        function printAttendance() {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Attendance Report - <?php echo htmlspecialchars($assigned_classes_map[$selected_class]); ?></title>
                    <style>
                        body { font-family: 'Inter', sans-serif; margin: 20px; background: #f9fafb; }
                        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #1e40af; padding-bottom: 20px; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                        .header h2 { margin: 0; color: #1e40af; font-weight: 700; }
                        .header h3 { margin: 5px 0; color: #374151; }
                        .header p { margin: 5px 0; color: #6b7280; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                        th, td { border: 1px solid #e5e7eb; padding: 12px; text-align: left; }
                        th { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white; font-weight: 600; }
                        .footer { margin-top: 40px; font-size: 12px; color: #6b7280; text-align: center; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
                        .status-present { background: #dcfce7 !important; color: #166534; }
                        .status-absent { background: #fee2e2 !important; color: #991b1b; }
                        .status-late { background: #fef3c7 !important; color: #92400e; }
                        .status-leave { background: #e9d5ff !important; color: #6b21a8; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>Sahab Academy</h2>
                        <h3>Attendance Report</h3>
                        <p>Class: <?php echo htmlspecialchars($assigned_classes_map[$selected_class]); ?></p>
                        <p>Date: <?php echo date('F j, Y', strtotime($selected_date)); ?></p>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Admission No</th>
                                <th>Student Name</th>
                                <th>Status</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach($students as $student): ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><?php echo htmlspecialchars($student['admission_no']); ?></td>
                                <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                <td class="status-<?php echo $student['status'] ?: 'absent'; ?>">
                                    <?php echo ucfirst($student['status'] ?: 'absent'); ?>
                                </td>
                                <td><?php echo htmlspecialchars($student['notes'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="footer">
                        <p>Generated on: ${new Date().toLocaleString()}</p>
                        <p>Teacher: <?php echo htmlspecialchars($teacher_name); ?></p>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        function showStudentHistory(studentId) {
            document.getElementById('historyContent').innerHTML = '<div style="text-align: center; padding: 2rem;"><div style="display: inline-block; width: 48px; height: 48px; border: 4px solid var(--gray-200); border-top: 4px solid var(--primary-500); border-radius: 50%; animation: spin 1s linear infinite;"></div><p style="margin-top: 1rem; color: var(--gray-600);">Loading history...</p></div>';
            document.getElementById('historyModal').style.display = 'block';

            fetch('ajax/get_student_history.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'student_id=' + studentId
            })
            .then(response => response.text())
            .then(data => {
                document.getElementById('historyContent').innerHTML = data;
            })
            .catch(error => {
                document.getElementById('historyContent').innerHTML = '<p style="color: var(--error-600); text-align: center; padding: 2rem;">Error loading history. Please try again.</p>';
            });
        }

        function closeHistoryModal() {
            document.getElementById('historyModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('historyModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeHistoryModal();
            }
        });

        // Add CSS animation for spinner
        const style = document.createElement('style');
        style.textContent = '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
        document.head.appendChild(style);

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

        // Header scroll effect
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.modern-header');
            if (window.scrollY > 50) {
                header.style.background = 'rgba(255, 255, 255, 0.98)';
                header.style.backdropFilter = 'blur(20px)';
            } else {
                header.style.background = 'rgba(255, 255, 255, 0.95)';
            }
        });

        // Add entrance animations on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe all animated elements
        document.querySelectorAll('.animate-fade-in-up, .animate-slide-in-left, .animate-slide-in-right').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
            observer.observe(el);
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
