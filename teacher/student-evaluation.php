<?php
// teacher/student-evaluation.php
session_start();
require_once '../config/db.php';

// Check authorization and get school context
require_once '../includes/functions.php';
require_once '../includes/auth-check.php';
$current_school_id = require_school_auth();

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'];

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_evaluation'])) {
        // Add new evaluation
        $student_id = $_POST['student_id'];
        $class_id = $_POST['class_id'];
        $term = $_POST['term'];
        $year = $_POST['year'];
        $academic = $_POST['academic'];
        $non_academic = $_POST['non_academic'];
        $cognitive = $_POST['cognitive'];
        $psychomotor = $_POST['psychomotor'];
        $affective = $_POST['affective'];
        $comments = $_POST['comments'];

        $stmt = $pdo->prepare("INSERT INTO evaluations (student_id, class_id, term, academic_year, academic, non_academic, cognitive, psychomotor, affective, comments, teacher_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$student_id, $class_id, $term, $year, $academic, $non_academic, $cognitive, $psychomotor, $affective, $comments, $teacher_id]);

        $evaluation_id = $pdo->lastInsertId();

        // Log evaluation creation
        log_teacher_action('create_evaluation', 'evaluation', $evaluation_id, "Created evaluation for student ID: {$student_id}, Term: {$term}, Year: {$year}");

        $_SESSION['success'] = "Evaluation added successfully!";
    }

    if (isset($_POST['update_evaluation'])) {
        // Update evaluation
        $evaluation_id = $_POST['evaluation_id'];
        $academic = $_POST['academic'];
        $non_academic = $_POST['non_academic'];
        $cognitive = $_POST['cognitive'];
        $psychomotor = $_POST['psychomotor'];
        $affective = $_POST['affective'];
        $comments = $_POST['comments'];

        $stmt = $pdo->prepare("UPDATE evaluations SET academic = ?, non_academic = ?, cognitive = ?, psychomotor = ?, affective = ?, comments = ?, updated_at = NOW() WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$academic, $non_academic, $cognitive, $psychomotor, $affective, $comments, $evaluation_id, $teacher_id]);

        // Log evaluation update
        log_teacher_action('update_evaluation', 'evaluation', $evaluation_id, "Updated evaluation ratings and comments");

        $_SESSION['success'] = "Evaluation updated successfully!";
    }

    if (isset($_POST['delete_evaluation'])) {
        // Delete evaluation
        $evaluation_id = $_POST['evaluation_id'];
        $stmt = $pdo->prepare("DELETE FROM evaluations WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$evaluation_id, $teacher_id]);

        // Log evaluation deletion
        log_teacher_action('delete_evaluation', 'evaluation', $evaluation_id, "Deleted evaluation record");

        $_SESSION['success'] = "Evaluation deleted successfully!";
        header("Location: student-evaluation.php");
        exit();
    }
}

// Fetch evaluations created by this teacher - school-filtered
$stmt = $pdo->prepare("
    SELECT e.*, s.full_name, s.class_id, s.admission_no, c.class_name
    FROM evaluations e
    JOIN students s ON e.student_id = s.id
    JOIN classes c ON e.class_id = c.id
    WHERE e.teacher_id = ? AND s.school_id = ?
    ORDER BY e.created_at DESC
");
$stmt->execute([$teacher_id, $current_school_id]);
$evaluations = $stmt->fetchAll();

// Fetch students that this teacher can evaluate (students in classes they teach)
$students_query = "
    SELECT DISTINCT s.id, s.full_name, s.class_id, s.admission_no, c.class_name
    FROM students s
    JOIN classes c ON s.class_id = c.id
    WHERE EXISTS (
        SELECT 1 FROM subject_assignments sa WHERE sa.class_id = s.class_id AND sa.teacher_id = ?
    ) OR EXISTS (
        SELECT 1 FROM class_teachers ct WHERE ct.class_id = s.class_id AND ct.teacher_id = ?
    )
    ORDER BY c.class_name, s.full_name
";
$stmt = $pdo->prepare($students_query);
$stmt->execute([$teacher_id, $teacher_id]);
$students = $stmt->fetchAll();

// Calculate statistics
$total_evaluations = count($evaluations);
$excellent_count = 0;
$very_good_count = 0;
$good_count = 0;
$needs_improvement_count = 0;

foreach ($evaluations as $eval) {
    if (in_array('excellent', [$eval['academic'], $eval['non_academic'], $eval['cognitive'], $eval['psychomotor'], $eval['affective']])) {
        $excellent_count++;
    }
    if (in_array('very-good', [$eval['academic'], $eval['non_academic'], $eval['cognitive'], $eval['psychomotor'], $eval['affective']])) {
        $very_good_count++;
    }
    if (in_array('good', [$eval['academic'], $eval['non_academic'], $eval['cognitive'], $eval['psychomotor'], $eval['affective']])) {
        $good_count++;
    }
    if (in_array('needs-improvement', [$eval['academic'], $eval['non_academic'], $eval['cognitive'], $eval['psychomotor'], $eval['affective']])) {
        $needs_improvement_count++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Evaluations | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
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
        .dashboard-container .main-content {
            width: 100%;
        }
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }
        body {
            background: #f5f7fb;
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
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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

        .stat-excellent .stat-icon-modern {
            background: var(--gradient-success);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-very-good .stat-icon-modern {
            background: var(--warning-100);
            color: var(--warning-600);
            box-shadow: var(--shadow-medium);
        }

        .stat-good .stat-icon-modern {
            background: var(--accent-100);
            color: var(--accent-600);
            box-shadow: var(--shadow-medium);
        }

        .stat-needs-improvement .stat-icon-modern {
            background: var(--error-100);
            color: var(--error-600);
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        /* Evaluations Table */
        .evaluations-table-container {
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

        .table-subtitle-modern {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .table-wrapper-modern {
            overflow-x: auto;
        }

        .evaluations-table-modern {
            width: 100%;
            border-collapse: collapse;
        }

        .evaluations-table-modern th {
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

        .evaluations-table-modern td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.2s ease;
        }

        .evaluations-table-modern tr:nth-child(even) {
            background: var(--gray-50);
        }

        .evaluations-table-modern tr:hover {
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

        .rating-badge-modern {
            padding: 0.375rem 0.875rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .rating-excellent { background: var(--success-100); color: var(--success-700); }
        .rating-very-good { background: var(--warning-100); color: var(--warning-700); }
        .rating-good { background: var(--accent-100); color: var(--accent-700); }
        .rating-needs-improvement { background: var(--error-100); color: var(--error-700); }

        .action-buttons-modern {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn-modern {
            padding: 0.5rem 0.875rem;
            border: 2px solid transparent;
            border-radius: 8px;
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

        .action-view-modern {
            background: var(--primary-100);
            color: var(--primary-700);
            border-color: var(--primary-200);
        }

        .action-view-modern:hover {
            background: var(--primary-500);
            color: white;
            border-color: var(--primary-500);
        }

        .action-edit-modern {
            background: var(--warning-100);
            color: var(--warning-700);
            border-color: var(--warning-200);
        }

        .action-edit-modern:hover {
            background: var(--warning-500);
            color: white;
            border-color: var(--warning-500);
        }

        .action-delete-modern {
            background: var(--error-100);
            color: var(--error-700);
            border-color: var(--error-200);
        }

        .action-delete-modern:hover {
            background: var(--error-500);
            color: white;
            border-color: var(--error-500);
        }

        /* Modals */
        .modal-modern {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            z-index: 1050;
            backdrop-filter: blur(8px);
        }

        .modal-content-modern {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: var(--shadow-strong);
        }

        .modal-header-modern {
            padding: 2rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title-modern {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin: 0;
        }

        .modal-close-modern {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray-400);
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .modal-close-modern:hover {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .modal-body-modern {
            padding: 2rem;
        }

        .modal-footer-modern {
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
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

            .evaluations-table-modern th,
            .evaluations-table-modern td {
                padding: 0.75rem;
                font-size: 0.8rem;
            }

            .action-buttons-modern {
                flex-direction: column;
                gap: 0.25rem;
            }

            .modal-content-modern {
                width: 95%;
                margin: 2rem auto;
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
    <?php include '../includes/mobile_navigation.php'; ?>

    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                        <p class="school-tagline">Student Evaluations</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($teacher_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <?php include '../includes/teacher_sidebar.php'; ?>
        <main class="main-content">
            <div class="main-container">
        <!-- Welcome Section -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern">
                <h2 class="card-title-modern">
                    <i class="fas fa-clipboard-check"></i>
                    Student Evaluations Management
                </h2>
                <p class="card-subtitle-modern">
                    Comprehensive evaluation system for tracking student performance across multiple domains
                </p>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-modern">
            <div class="stat-card-modern stat-total animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-value-modern"><?php echo $total_evaluations; ?></div>
                <div class="stat-label-modern">Total Evaluations</div>
            </div>

            <div class="stat-card-modern stat-excellent animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-star"></i>
                </div>
                <div class="stat-value-modern"><?php echo $excellent_count; ?></div>
                <div class="stat-label-modern">Excellent Ratings</div>
            </div>

            <div class="stat-card-modern stat-very-good animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-thumbs-up"></i>
                </div>
                <div class="stat-value-modern"><?php echo $very_good_count; ?></div>
                <div class="stat-label-modern">Very Good Ratings</div>
            </div>

            <div class="stat-card-modern stat-good animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value-modern"><?php echo $good_count; ?></div>
                <div class="stat-label-modern">Good Ratings</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="actions-modern animate-fade-in-up">
            <div class="actions-grid-modern">
                <button class="action-btn-modern" onclick="openAddModal()">
                    <i class="fas fa-plus-circle action-icon-modern"></i>
                    <span class="action-text-modern">Add Evaluation</span>
                </button>
                <button class="action-btn-modern" onclick="printEvaluations()">
                    <i class="fas fa-print action-icon-modern"></i>
                    <span class="action-text-modern">Print Report</span>
                </button>
                <button class="action-btn-modern" onclick="exportEvaluations()">
                    <i class="fas fa-download action-icon-modern"></i>
                    <span class="action-text-modern">Export Data</span>
                </button>
                <button class="action-btn-modern" onclick="showAnalytics()">
                    <i class="fas fa-chart-bar action-icon-modern"></i>
                    <span class="action-text-modern">View Analytics</span>
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert-modern alert-success-modern animate-fade-in-up">
                <i class="fas fa-check-circle"></i>
                <span><?php safe_echo($_SESSION['success']); unset($_SESSION['success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert-modern alert-error-modern animate-fade-in-up">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php safe_echo($_SESSION['error']); unset($_SESSION['error']); ?></span>
            </div>
        <?php endif; ?>

        <!-- Evaluations Table -->
        <div class="evaluations-table-container animate-fade-in-up">
            <div class="table-header-modern">
                <div class="table-title-modern">
                    <i class="fas fa-table"></i>
                    Student Evaluations Overview
                </div>
                <div class="table-subtitle-modern">
                    <?php echo $total_evaluations; ?> Total Records
                </div>
            </div>

            <div class="table-wrapper-modern">
                <table class="evaluations-table-modern">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Term/Year</th>
                            <th>Academic</th>
                            <th>Non-Academic</th>
                            <th>Cognitive</th>
                            <th>Psychomotor</th>
                            <th>Affective</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; ?>
                        <?php foreach ($evaluations as $eval): ?>
                            <tr>
                                <td>
                                    <span class="student-number-modern"><?php echo $counter++; ?></span>
                                </td>
                                <td>
                                    <div class="student-name-modern"><?php echo htmlspecialchars($eval['full_name']); ?></div>
                                    <div class="admission-number-modern">Roll: <?php echo $eval['admission_no']; ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($eval['class_name']); ?></td>
                                <td>
                                    <span class="badge bg-info">Term <?php echo $eval['term']; ?></span>
                                    <br><small><?php echo $eval['academic_year']; ?></small>
                                </td>
                                <td><span class="rating-badge-modern rating-<?php echo $eval['academic']; ?>"><?php echo ucfirst(str_replace('-', ' ', $eval['academic'])); ?></span></td>
                                <td><span class="rating-badge-modern rating-<?php echo $eval['non_academic']; ?>"><?php echo ucfirst(str_replace('-', ' ', $eval['non_academic'])); ?></span></td>
                                <td><span class="rating-badge-modern rating-<?php echo $eval['cognitive']; ?>"><?php echo ucfirst(str_replace('-', ' ', $eval['cognitive'])); ?></span></td>
                                <td><span class="rating-badge-modern rating-<?php echo $eval['psychomotor']; ?>"><?php echo ucfirst(str_replace('-', ' ', $eval['psychomotor'])); ?></span></td>
                                <td><span class="rating-badge-modern rating-<?php echo $eval['affective']; ?>"><?php echo ucfirst(str_replace('-', ' ', $eval['affective'])); ?></span></td>
                                <td>
                                    <div class="action-buttons-modern">
                                        <button class="action-btn-modern action-view-modern" onclick="viewEvaluation(<?php echo $eval['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                            <span>View</span>
                                        </button>
                                        <button class="action-btn-modern action-edit-modern" onclick="editEvaluation(<?php echo $eval['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                            <span>Edit</span>
                                        </button>
                                        <button class="action-btn-modern action-delete-modern" onclick="deleteEvaluation(<?php echo $eval['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                            <span>Delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

            </div>
        </main>
    </div>

    <!-- Add Evaluation Modal -->
    <div class="modal-modern" id="addEvaluationModal">
        <div class="modal-content-modern">
            <form method="post">
                <div class="modal-header-modern">
                    <h3 class="modal-title-modern">Add New Evaluation</h3>
                    <button type="button" class="modal-close-modern" onclick="closeModal('addEvaluationModal')">×</button>
                </div>
                <div class="modal-body-modern">
                    <input type="hidden" name="add_evaluation" value="1">

                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label class="form-label-modern">Student</label>
                            <select class="form-input-modern" name="student_id" required>
                                <option value="">Select Student</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['full_name']); ?> - <?php echo htmlspecialchars($student['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group-modern">
                            <label class="form-label-modern">Term</label>
                            <select class="form-input-modern" name="term" required>
                                <option value="1">Term 1</option>
                                <option value="2">Term 2</option>
                                <option value="3">Term 3</option>
                            </select>
                        </div>
                        <div class="form-group-modern">
                            <label class="form-label-modern">Year</label>
                            <input type="number" class="form-input-modern" name="year" value="<?php echo date('Y'); ?>" required>
                        </div>
                    </div>

                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label class="form-label-modern">Class</label>
                            <select class="form-input-modern" name="class_id" required>
                                <option value="">Select Class</option>
                                <?php
                                $classes = array_unique(array_column($students, 'class_id'));
                                foreach ($classes as $class_id) {
                                    $class_name = '';
                                    foreach ($students as $s) {
                                        if ($s['class_id'] == $class_id) {
                                            $class_name = $s['class_name'];
                                            break;
                                        }
                                    }
                                    echo "<option value=\"$class_id\">" . htmlspecialchars($class_name) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <hr style="margin: 2rem 0; border: none; border-top: 1px solid var(--gray-200);">

                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label class="form-label-modern">Academic Performance</label>
                            <select class="form-input-modern" name="academic" required>
                                <option value="excellent">Excellent</option>
                                <option value="very-good">Very Good</option>
                                <option value="good">Good</option>
                                <option value="needs-improvement">Needs Improvement</option>
                            </select>
                        </div>
                        <div class="form-group-modern">
                            <label class="form-label-modern">Non-Academic Activities</label>
                            <select class="form-input-modern" name="non_academic" required>
                                <option value="excellent">Excellent</option>
                                <option value="very-good">Very Good</option>
                                <option value="good">Good</option>
                                <option value="needs-improvement">Needs Improvement</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label class="form-label-modern">Cognitive Domain</label>
                            <select class="form-input-modern" name="cognitive" required>
                                <option value="excellent">Excellent</option>
                                <option value="very-good">Very Good</option>
                                <option value="good">Good</option>
                                <option value="needs-improvement">Needs Improvement</option>
                            </select>
                        </div>
                        <div class="form-group-modern">
                            <label class="form-label-modern">Psychomotor Domain</label>
                            <select class="form-input-modern" name="psychomotor" required>
                                <option value="excellent">Excellent</option>
                                <option value="very-good">Very Good</option>
                                <option value="good">Good</option>
                                <option value="needs-improvement">Needs Improvement</option>
                            </select>
                        </div>
                        <div class="form-group-modern">
                            <label class="form-label-modern">Affective Domain</label>
                            <select class="form-input-modern" name="affective" required>
                                <option value="excellent">Excellent</option>
                                <option value="very-good">Very Good</option>
                                <option value="good">Good</option>
                                <option value="needs-improvement">Needs Improvement</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern">Comments & Recommendations</label>
                        <textarea class="form-input-modern" name="comments" rows="4" placeholder="Enter additional comments, strengths, and areas for improvement..."></textarea>
                    </div>
                </div>
                <div class="modal-footer-modern">
                    <button type="button" class="btn-modern-primary" style="background: var(--gray-200); color: var(--gray-700);" onclick="closeModal('addEvaluationModal')">Cancel</button>
                    <button type="submit" class="btn-modern-primary">Save Evaluation</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Evaluation Modal -->
    <div class="modal-modern" id="viewModal">
        <div class="modal-content-modern">
            <div class="modal-header-modern">
                <h3 class="modal-title-modern">Evaluation Details</h3>
                <button type="button" class="modal-close-modern" onclick="closeModal('viewModal')">×</button>
            </div>
            <div class="modal-body-modern" id="viewModalBody">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Edit Evaluation Modal -->
    <div class="modal-modern" id="editModal">
        <div class="modal-content-modern">
            <form method="post" id="editForm">
                <div class="modal-header-modern">
                    <h3 class="modal-title-modern">Edit Evaluation</h3>
                    <button type="button" class="modal-close-modern" onclick="closeModal('editModal')">×</button>
                </div>
                <div class="modal-body-modern" id="editModalBody">
                    <!-- Content will be populated by JavaScript -->
                </div>
                <div class="modal-footer-modern">
                    <button type="button" class="btn-modern-primary" style="background: var(--gray-200); color: var(--gray-700);" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn-modern-primary">Update Evaluation</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Evaluation data for JavaScript
        const evaluations = <?php echo json_encode($evaluations); ?>;

        // Modal functions
        function openAddModal() {
            document.getElementById('addEvaluationModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal when clicking outside
        document.querySelectorAll('.modal-modern').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
        });

        // View evaluation function
        function viewEvaluation(evaluationId) {
            const evaluation = evaluations.find(e => e.id == evaluationId);
            if (!evaluation) return;

            const viewModalBody = document.getElementById('viewModalBody');
            viewModalBody.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                    <div>
                        <h4 style="color: var(--gray-900); margin-bottom: 1rem;">Student Information</h4>
                        <p><strong>Name:</strong> ${evaluation.full_name}</p>
                        <p><strong>Class:</strong> ${evaluation.class_name}</p>
                        <p><strong>Admission No:</strong> ${evaluation.admission_no}</p>
                        <p><strong>Term:</strong> ${evaluation.term} | <strong>Year:</strong> ${evaluation.academic_year}</p>
                    </div>
                    <div>
                        <h4 style="color: var(--gray-900); margin-bottom: 1rem;">Performance Ratings</h4>
                        <p><strong>Academic:</strong> <span class="rating-badge-modern rating-${evaluation.academic}">${evaluation.academic.charAt(0).toUpperCase() + evaluation.academic.slice(1).replace('-', ' ')}</span></p>
                        <p><strong>Non-Academic:</strong> <span class="rating-badge-modern rating-${evaluation.non_academic}">${evaluation.non_academic.charAt(0).toUpperCase() + evaluation.non_academic.slice(1).replace('-', ' ')}</span></p>
                        <p><strong>Cognitive:</strong> <span class="rating-badge-modern rating-${evaluation.cognitive}">${evaluation.cognitive.charAt(0).toUpperCase() + evaluation.cognitive.slice(1).replace('-', ' ')}</span></p>
                        <p><strong>Psychomotor:</strong> <span class="rating-badge-modern rating-${evaluation.psychomotor}">${evaluation.psychomotor.charAt(0).toUpperCase() + evaluation.psychomotor.slice(1).replace('-', ' ')}</span></p>
                        <p><strong>Affective:</strong> <span class="rating-badge-modern rating-${evaluation.affective}">${evaluation.affective.charAt(0).toUpperCase() + evaluation.affective.slice(1).replace('-', ' ')}</span></p>
                    </div>
                </div>
                <hr style="border: none; border-top: 1px solid var(--gray-200); margin: 2rem 0;">
                <h4 style="color: var(--gray-900); margin-bottom: 1rem;">Comments & Recommendations</h4>
                <p style="line-height: 1.6;">${evaluation.comments ? evaluation.comments.replace(/\n/g, '<br>') : 'No comments provided.'}</p>
            `;

            document.getElementById('viewModal').style.display = 'block';
        }

        // Edit evaluation function
        function editEvaluation(evaluationId) {
            const evaluation = evaluations.find(e => e.id == evaluationId);
            if (!evaluation) return;

            const editModalBody = document.getElementById('editModalBody');
            editModalBody.innerHTML = `
                <input type="hidden" name="evaluation_id" value="${evaluation.id}">
                <input type="hidden" name="update_evaluation" value="1">

                <div class="form-row-modern">
                    <div class="form-group-modern">
                        <label class="form-label-modern">Academic Performance</label>
                        <select class="form-input-modern" name="academic" required>
                            <option value="excellent" ${evaluation.academic === 'excellent' ? 'selected' : ''}>Excellent</option>
                            <option value="very-good" ${evaluation.academic === 'very-good' ? 'selected' : ''}>Very Good</option>
                            <option value="good" ${evaluation.academic === 'good' ? 'selected' : ''}>Good</option>
                            <option value="needs-improvement" ${evaluation.non_academic === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Non-Academic</label>
                        <select class="form-input-modern" name="non_academic" required>
                            <option value="excellent" ${evaluation.non_academic === 'excellent' ? 'selected' : ''}>Excellent</option>
                            <option value="very-good" ${evaluation.non_academic === 'very-good' ? 'selected' : ''}>Very Good</option>
                            <option value="good" ${evaluation.non_academic === 'good' ? 'selected' : ''}>Good</option>
                            <option value="needs-improvement" ${evaluation.non_academic === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                        </select>
                    </div>
                </div>

                <div class="form-row-modern">
                    <div class="form-group-modern">
                        <label class="form-label-modern">Cognitive Domain</label>
                        <select class="form-input-modern" name="cognitive" required>
                            <option value="excellent" ${evaluation.cognitive === 'excellent' ? 'selected' : ''}>Excellent</option>
                            <option value="very-good" ${evaluation.cognitive === 'very-good' ? 'selected' : ''}>Very Good</option>
                            <option value="good" ${evaluation.cognitive === 'good' ? 'selected' : ''}>Good</option>
                            <option value="needs-improvement" ${evaluation.cognitive === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Psychomotor Domain</label>
                        <select class="form-input-modern" name="psychomotor" required>
                            <option value="excellent" ${evaluation.psychomotor === 'excellent' ? 'selected' : ''}>Excellent</option>
                            <option value="very-good" ${evaluation.psychomotor === 'very-good' ? 'selected' : ''}>Very Good</option>
                            <option value="good" ${evaluation.psychomotor === 'good' ? 'selected' : ''}>Good</option>
                            <option value="needs-improvement" ${evaluation.psychomotor === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Affective Domain</label>
                        <select class="form-input-modern" name="affective" required>
                            <option value="excellent" ${evaluation.affective === 'excellent' ? 'selected' : ''}>Excellent</option>
                            <option value="very-good" ${evaluation.affective === 'very-good' ? 'selected' : ''}>Very Good</option>
                            <option value="good" ${evaluation.affective === 'good' ? 'selected' : ''}>Good</option>
                            <option value="needs-improvement" ${evaluation.affective === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                        </select>
                    </div>
                </div>

                <div class="form-group-modern">
                    <label class="form-label-modern">Comments & Recommendations</label>
                    <textarea class="form-input-modern" name="comments" rows="4">${evaluation.comments || ''}</textarea>
                </div>
            `;

            document.getElementById('editModal').style.display = 'block';
        }

        // Delete evaluation function
        function deleteEvaluation(evaluationId) {
            if (confirm('Are you sure you want to delete this evaluation? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = `
                    <input type="hidden" name="delete_evaluation" value="1">
                    <input type="hidden" name="evaluation_id" value="${evaluationId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Print evaluations function - Now uses TCPDF for professional PDF generation
        function printEvaluations() {
            // Show loading indicator
            const originalText = event.target.innerHTML;
            event.target.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF...';
            event.target.disabled = true;

            // Redirect to PDF generation script
            window.location.href = 'generate-evaluation-pdf.php';
        }

        // Export evaluations function - Now uses TCPDF for professional data export
        function exportEvaluations() {
            // Show export options modal
            showExportOptions();
        }

        // Show export options modal
        function showExportOptions() {
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.6); z-index: 1050; backdrop-filter: blur(8px);
                display: flex; align-items: center; justify-content: center;
            `;

            modal.innerHTML = `
                <div style="
                    background: white; border-radius: 20px; width: 90%; max-width: 500px;
                    box-shadow: var(--shadow-strong); position: relative;
                ">
                    <div style="
                        padding: 2rem; border-bottom: 1px solid var(--gray-200);
                        display: flex; justify-content: space-between; align-items: center;
                    ">
                        <h3 style="margin: 0; font-size: 1.5rem; font-weight: 700; color: var(--gray-900);">
                            Export Evaluation Data
                        </h3>
                        <button onclick="this.closest('div').parentElement.remove()" style="
                            background: none; border: none; font-size: 1.5rem; cursor: pointer;
                            color: var(--gray-400); padding: 0.5rem; border-radius: 8px;
                        ">×</button>
                    </div>
                    <div style="padding: 2rem;">
                        <p style="margin-bottom: 1.5rem; color: var(--gray-600);">
                            Choose the type of data export you need:
                        </p>

                        <div style="display: grid; gap: 1rem;">
                            <button onclick="startExport('full')" style="
                                padding: 1rem 1.5rem; background: var(--gradient-primary);
                                color: white; border: none; border-radius: 12px; cursor: pointer;
                                font-weight: 600; display: flex; align-items: center; gap: 0.75rem;
                                transition: transform 0.2s;
                            " onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                                <i class="fas fa-file-pdf"></i>
                                <div style="text-align: left;">
                                    <div style="font-weight: 700;">Full Data Export</div>
                                    <div style="font-size: 0.875rem; opacity: 0.9;">Complete evaluation records with statistics</div>
                                </div>
                            </button>

                            <button onclick="startExport('summary')" style="
                                padding: 1rem 1.5rem; background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                                color: white; border: none; border-radius: 12px; cursor: pointer;
                                font-weight: 600; display: flex; align-items: center; gap: 0.75rem;
                                transition: transform 0.2s;
                            " onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                                <i class="fas fa-chart-pie"></i>
                                <div style="text-align: left;">
                                    <div style="font-weight: 700;">Summary Report</div>
                                    <div style="font-size: 0.875rem; opacity: 0.9;">Key statistics and class summaries</div>
                                </div>
                            </button>

                            <button onclick="startExport('analytics')" style="
                                padding: 1rem 1.5rem; background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
                                color: white; border: none; border-radius: 12px; cursor: pointer;
                                font-weight: 600; display: flex; align-items: center; gap: 0.75rem;
                                transition: transform 0.2s;
                            " onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                                <i class="fas fa-chart-bar"></i>
                                <div style="text-align: left;">
                                    <div style="font-weight: 700;">Analytics & Insights</div>
                                    <div style="font-size: 0.875rem; opacity: 0.9;">Trends, charts, and recommendations</div>
                                </div>
                            </button>
                        </div>

                        <div style="margin-top: 1.5rem; padding: 1rem; background: var(--gray-50); border-radius: 8px;">
                            <p style="margin: 0; font-size: 0.875rem; color: var(--gray-600);">
                                <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                                Export files will be generated as PDF documents with professional formatting and school branding.
                            </p>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
        }

        // Start export with loading indicator
        function startExport(type) {
            // Remove modal
            document.querySelector('[style*="position: fixed"][style*="backdrop-filter"]').remove();

            // Show loading on export button
            const exportBtn = document.querySelector('button[onclick="exportEvaluations()"]');
            const originalHTML = exportBtn.innerHTML;
            exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF...';
            exportBtn.disabled = true;

            // Redirect to export script
            window.location.href = `export-evaluations-pdf.php?type=${type}`;
        }

        function showAnalytics() {
            alert('Analytics view will be implemented soon.');
        }

        // Auto-fill class when student is selected
        document.addEventListener('DOMContentLoaded', function() {
            const studentSelect = document.querySelector('select[name="student_id"]');
            if (studentSelect) {
                studentSelect.addEventListener('change', function() {
                    const studentId = this.value;
                    const students = <?php echo json_encode($students); ?>;
                    const selectedStudent = students.find(s => s.id == studentId);
                    if (selectedStudent) {
                        const classSelect = document.querySelector('select[name="class_id"]');
                        if (classSelect) {
                            classSelect.value = selectedStudent.class_id;
                        }
                    }
                });
            }
        });

        // Header scroll effect
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (!header) return;
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
