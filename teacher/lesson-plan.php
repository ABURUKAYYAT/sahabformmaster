<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../index.php');
    exit();
}

// Get current school context
$current_school_id = require_school_auth();
$user_id = intval($_SESSION['user_id']);
$user_name = $_SESSION['full_name'] ?? 'Teacher';
$errors = [];
$success = '';

// Handle teacher actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);
    $topic = trim($_POST['topic'] ?? '');
    $duration = intval($_POST['duration'] ?? 0);
    $learning_objectives = trim($_POST['learning_objectives'] ?? '');
    $teaching_methods = trim($_POST['teaching_methods'] ?? '');
    $resources = trim($_POST['resources'] ?? '');
    $lesson_content = trim($_POST['lesson_content'] ?? '');
    $assessment_method = trim($_POST['assessment_method'] ?? '');
    $assessment_tasks = trim($_POST['assessment_tasks'] ?? '');
    $differentiation = trim($_POST['differentiation'] ?? '');
    $homework = trim($_POST['homework'] ?? '');
    $date_planned = trim($_POST['date_planned'] ?? '');
    $status = $_POST['status'] ?? 'draft';

    // Basic validations
    if ($subject_id <= 0) $errors[] = 'Subject is required.';
    if ($class_id <= 0) $errors[] = 'Class is required.';
    if ($topic === '') $errors[] = 'Topic is required.';
    if ($duration <= 0) $errors[] = 'Duration must be a positive number.';
    if ($learning_objectives === '') $errors[] = 'Learning objectives are required.';
    if ($assessment_method === '') $errors[] = 'Assessment method is required.';
    if ($date_planned === '' || !strtotime($date_planned)) $errors[] = 'Valid planned date is required.';

    if (empty($errors)) {
        if ($action === 'add') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lesson_plans WHERE teacher_id = :teacher_id AND class_id = :class_id AND topic = :topic AND DATE(date_planned) = :date_planned AND school_id = :school_id");
            $stmt->execute([
                'teacher_id' => $user_id,
                'class_id' => $class_id,
                'topic' => $topic,
                'date_planned' => $date_planned,
                'school_id' => $current_school_id
            ]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'You already created a lesson plan for this topic on the selected date.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO lesson_plans 
                    (school_id, subject_id, class_id, teacher_id, topic, duration, learning_objectives, teaching_methods, resources, lesson_content, assessment_method, assessment_tasks, differentiation, homework, date_planned, status, approval_status, created_at)
                    VALUES (:school_id, :subject_id, :class_id, :teacher_id, :topic, :duration, :learning_objectives, :teaching_methods, :resources, :lesson_content, :assessment_method, :assessment_tasks, :differentiation, :homework, :date_planned, 'draft', 'pending', NOW())");
                $stmt->execute([
                    'school_id' => $current_school_id,
                    'subject_id' => $subject_id,
                    'class_id' => $class_id,
                    'teacher_id' => $user_id,
                    'topic' => $topic,
                    'duration' => $duration,
                    'learning_objectives' => $learning_objectives,
                    'teaching_methods' => $teaching_methods,
                    'resources' => $resources,
                    'lesson_content' => $lesson_content,
                    'assessment_method' => $assessment_method,
                    'assessment_tasks' => $assessment_tasks,
                    'differentiation' => $differentiation,
                    'homework' => $homework,
                    'date_planned' => $date_planned,
                'school_id' => $current_school_id
                ]);
                $success = 'Lesson plan created successfully!';
                header("Location: lesson-plan.php");
                exit;
            }
        }

        if ($action === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid lesson plan ID.';
            } else {
                $stmt = $pdo->prepare("SELECT teacher_id, status FROM lesson_plans WHERE id = :id AND school_id = :school_id");
                $stmt->execute(['id' => $id, 'school_id' => $current_school_id]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan || $plan['teacher_id'] != $user_id) {
                    $errors[] = 'Lesson plan not found or access denied.';
                } elseif (!in_array($plan['status'], ['draft', 'on_hold'], true)) {
                    $errors[] = 'Only draft or on-hold lesson plans can be edited.';
                } else {
                    $stmt = $pdo->prepare("UPDATE lesson_plans SET subject_id = :subject_id, class_id = :class_id, topic = :topic, duration = :duration, learning_objectives = :learning_objectives, teaching_methods = :teaching_methods, resources = :resources, lesson_content = :lesson_content, assessment_method = :assessment_method, assessment_tasks = :assessment_tasks, differentiation = :differentiation, homework = :homework, date_planned = :date_planned, status = :status WHERE id = :id AND school_id = :school_id");
                    $stmt->execute([
                        'subject_id' => $subject_id,
                        'class_id' => $class_id,
                        'topic' => $topic,
                        'duration' => $duration,
                        'learning_objectives' => $learning_objectives,
                        'teaching_methods' => $teaching_methods,
                        'resources' => $resources,
                        'lesson_content' => $lesson_content,
                        'assessment_method' => $assessment_method,
                        'assessment_tasks' => $assessment_tasks,
                        'differentiation' => $differentiation,
                        'homework' => $homework,
                        'date_planned' => $date_planned,
                        'status' => $status,
                        'id' => $id,
                        'school_id' => $current_school_id
                    ]);
                    $success = 'Lesson plan updated successfully!';
                    header("Location: lesson-plan.php");
                    exit;
                }
            }
        }

        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid lesson plan ID.';
            } else {
                $stmt = $pdo->prepare("SELECT teacher_id, status FROM lesson_plans WHERE id = :id AND school_id = :school_id");
                $stmt->execute(['id' => $id, 'school_id' => $current_school_id]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan || $plan['teacher_id'] != $user_id) {
                    $errors[] = 'Not found or access denied.';
                } elseif (!in_array($plan['status'], ['draft', 'on_hold'], true)) {
                    $errors[] = 'Only draft or on-hold lesson plans can be deleted.';
                } else {
                    $pdo->prepare("DELETE FROM lesson_plan_feedback WHERE lesson_plan_id = :id")->execute(['id' => $id]);
                    $pdo->prepare("DELETE FROM lesson_plan_attachments WHERE lesson_plan_id = :id")->execute(['id' => $id]);
                    $pdo->prepare("DELETE FROM lesson_plans WHERE id = :id AND school_id = :school_id")
                        ->execute(['id' => $id, 'school_id' => $current_school_id]);
                    $success = 'Lesson plan deleted successfully!';
                    header("Location: lesson-plan.php");
                    exit;
                }
            }
        }

        if ($action === 'submit_for_approval') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid lesson plan ID.';
            } else {
                $stmt = $pdo->prepare("SELECT teacher_id, status FROM lesson_plans WHERE id = :id AND school_id = :school_id");
                $stmt->execute(['id' => $id, 'school_id' => $current_school_id]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan || $plan['teacher_id'] != $user_id) {
                    $errors[] = 'Not found or access denied.';
                } elseif (!in_array($plan['status'], ['draft', 'on_hold'], true)) {
                    $errors[] = 'Only draft or on-hold plans can be submitted for approval.';
                } else {
                    $stmt = $pdo->prepare("UPDATE lesson_plans SET approval_status = 'pending', status = 'submitted' WHERE id = :id AND school_id = :school_id");
                    $stmt->execute(['id' => $id, 'school_id' => $current_school_id]);
                    $success = 'Lesson plan submitted for principal review!';
                    header("Location: lesson-plan.php");
                    exit;
                }
            }
        }
    }
}

// Fetch dropdowns - school-filtered
$subjects = get_school_subjects($pdo, $current_school_id);
$classes = get_school_classes($pdo, $current_school_id);

// Seed sample lesson plans (one-time trigger via ?seed_samples=1)
if (isset($_GET['seed_samples']) && $_GET['seed_samples'] === '1') {
    if (!empty($subjects) && !empty($classes)) {
        $subject_id = intval($subjects[0]['id']);
        $class_id = intval($classes[0]['id']);
        $sample_topics = [
            'Introduction to the Topic',
            'Key Concepts and Terms',
            'Guided Practice Session',
            'Real-world Applications',
            'Summary and Review'
        ];

        $stmt = $pdo->prepare("INSERT INTO lesson_plans
            (school_id, subject_id, class_id, teacher_id, topic, duration, learning_objectives, teaching_methods, resources, lesson_content, assessment_method, assessment_tasks, differentiation, homework, date_planned, status, approval_status, created_at)
            VALUES (:school_id, :subject_id, :class_id, :teacher_id, :topic, :duration, :learning_objectives, :teaching_methods, :resources, :lesson_content, :assessment_method, :assessment_tasks, :differentiation, :homework, :date_planned, 'draft', 'pending', NOW())");

        $base_date = date('Y-m-d');
        foreach ($sample_topics as $index => $topic) {
            $date_planned = date('Y-m-d', strtotime($base_date . " +$index day"));
            $stmt->execute([
                'school_id' => $current_school_id,
                'subject_id' => $subject_id,
                'class_id' => $class_id,
                'teacher_id' => $user_id,
                'topic' => $topic,
                'duration' => 45,
                'learning_objectives' => 'Understand the lesson objectives and key takeaways.',
                'teaching_methods' => 'Discussion, demonstration, and guided practice.',
                'resources' => 'Whiteboard, textbook, and slides.',
                'lesson_content' => 'Introduce the topic, explain key ideas, and provide examples.',
                'assessment_method' => 'Short quiz and oral questions.',
                'assessment_tasks' => 'Complete classwork and answer review questions.',
                'differentiation' => 'Provide support materials and extension tasks.',
                'homework' => 'Revise notes and complete assigned exercises.',
                'date_planned' => $date_planned
            ]);
        }
        $_SESSION['success'] = 'Sample lesson plans inserted successfully.';
    } else {
        $_SESSION['error'] = 'No subjects or classes found for your school.';
    }
    header("Location: lesson-plan.php");
    exit;
}

// Fetch teacher's lesson plans - school-filtered
$stmt = $pdo->prepare("SELECT lp.*, s.subject_name, c.class_name, u.full_name AS approved_by_name FROM lesson_plans lp JOIN subjects s ON lp.subject_id = s.id JOIN classes c ON lp.class_id = c.id LEFT JOIN users u ON lp.approved_by = u.id WHERE lp.teacher_id = :teacher_id AND lp.school_id = :school_id_lp AND s.school_id = :school_id_s AND c.school_id = :school_id_c ORDER BY lp.date_planned DESC, lp.created_at DESC");
$stmt->execute([
    'teacher_id' => $user_id,
    'school_id_lp' => $current_school_id,
    'school_id_s' => $current_school_id,
    'school_id_c' => $current_school_id
]);
$lesson_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If editing, fetch single plan
$edit_plan = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM lesson_plans WHERE id = :id AND teacher_id = :teacher_id AND school_id = :school_id");
        $stmt->execute(['id' => $edit_id, 'teacher_id' => $user_id, 'school_id' => $current_school_id]);
        $edit_plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($edit_plan && $edit_plan['status'] !== 'draft') $edit_plan = null;
    }
}

function getStatusBadge($status) {
    $classes = [
        'draft' => 'badge-secondary',
        'submitted' => 'badge-warning',
        'scheduled' => 'badge-primary',
        'completed' => 'badge-success',
        'on_hold' => 'badge-warning',
        'cancelled' => 'badge-danger'
    ];
    return $classes[$status] ?? 'badge-default';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Lesson Plans | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
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

        .stat-draft .stat-icon-modern {
            background: var(--gradient-accent);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-submitted .stat-icon-modern {
            background: var(--warning-500);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-approved .stat-icon-modern {
            background: var(--success-500);
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

        .form-textarea-modern {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            font-family: inherit;
            min-height: 100px;
            resize: vertical;
        }

        .form-textarea-modern:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .form-select-modern {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-select-modern:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
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

        .btn-modern-secondary {
            padding: 1rem 2rem;
            background: var(--gray-100);
            color: var(--gray-700);
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-modern-secondary:hover {
            background: var(--gray-200);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
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

        /* Lesson Plans Table */
        .lesson-plans-table-container {
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
            font-size: 0.875rem;
            opacity: 0.9;
        }

        .table-wrapper-modern {
            overflow-x: auto;
        }

        .lesson-plans-table-modern {
            width: 100%;
            border-collapse: collapse;
        }

        .lesson-plans-table-modern th {
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

        .lesson-plans-table-modern td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.2s ease;
        }

        .lesson-plans-table-modern tr:nth-child(even) {
            background: var(--gray-50);
        }

        .lesson-plans-table-modern tr:hover {
            background: var(--primary-50);
        }

        .plan-id-modern {
            font-weight: 600;
            color: var(--gray-900);
        }

        .plan-title-modern {
            font-weight: 600;
            color: var(--gray-900);
            font-size: 1.125rem;
        }

        .plan-subject-modern {
            font-weight: 500;
            color: var(--gray-600);
        }

        .plan-class-modern {
            font-weight: 500;
            color: var(--gray-600);
        }

        .plan-date-modern {
            font-weight: 500;
            color: var(--gray-600);
        }

        .status-badge-modern {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .status-draft-modern {
            background: var(--accent-100);
            color: var(--accent-700);
        }

        .status-submitted-modern {
            background: var(--warning-100);
            color: var(--warning-700);
        }

        .status-scheduled-modern {
            background: var(--primary-100);
            color: var(--primary-700);
        }

        .status-completed-modern {
            background: var(--success-100);
            color: var(--success-700);
        }

        .status-on-hold-modern {
            background: var(--warning-100);
            color: var(--warning-700);
        }

        .status-cancelled-modern {
            background: var(--error-100);
            color: var(--error-700);
        }

        .approval-badge-modern {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .approval-pending-modern {
            background: var(--warning-100);
            color: var(--warning-700);
        }

        .approval-approved-modern {
            background: var(--success-100);
            color: var(--success-700);
        }

        .approval-rejected-modern {
            background: var(--error-100);
            color: var(--error-700);
        }

        .manage-actions-modern {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-small-modern {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .btn-view-modern {
            background: var(--primary-500);
            color: white;
        }

        .btn-view-modern:hover {
            background: var(--primary-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .btn-edit-modern {
            background: var(--warning-500);
            color: white;
        }

        .btn-edit-modern:hover {
            background: var(--warning-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .btn-submit-modern {
            background: var(--primary-500);
            color: white;
        }

        .btn-submit-modern:hover {
            background: var(--primary-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .btn-delete-modern {
            background: var(--error-500);
            color: white;
        }

        .btn-delete-modern:hover:not(:disabled) {
            background: var(--error-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .btn-small-modern:disabled,
        .btn-small-modern[disabled] {
            opacity: 0.5 !important;
            cursor: not-allowed !important;
            transform: none !important;
            box-shadow: none !important;
        }

        .btn-small-modern:disabled:hover,
        .btn-small-modern[disabled]:hover {
            transform: none !important;
            box-shadow: none !important;
            background: inherit !important;
        }
        .toggle-form-btn {
            background: #f1f5f9;
            color: #1f2937;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.5rem 1rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }
        .toggle-form-btn:hover {
            background: #e2e8f0;
        }
        .lesson-form-collapsible {
            display: none;
        }
        .lesson-form-collapsible.active {
            display: block;
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

            .lesson-plans-table-modern th,
            .lesson-plans-table-modern td {
                padding: 0.75rem;
                font-size: 0.8rem;
            }

            .manage-actions-modern {
                flex-direction: column;
            }

            .status-badge-modern,
            .approval-badge-modern {
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
    </style>
</head>
<body>
    <!-- Mobile Navigation Component -->
    <?php include '../includes/mobile_navigation.php'; ?>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                        <p class="school-tagline">Teacher Portal</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($user_name); ?></span>
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
                    <i class="fas fa-book-open"></i>
                    Lesson Plan Management
                </h2>
                <p class="card-subtitle-modern">
                    Create, edit, and submit lesson plans for approval
                </p>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-modern">
            <?php
            $draft_count = 0;
            $submitted_count = 0;
            $approved_count = 0;
            $total_count = count($lesson_plans);

            foreach ($lesson_plans as $lp) {
                if ($lp['status'] === 'draft') $draft_count++;
                if ($lp['status'] === 'submitted') $submitted_count++;
                if ($lp['approval_status'] === 'approved') $approved_count++;
            }
            ?>
            <div class="stat-card-modern stat-total animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-value-modern"><?php echo $total_count; ?></div>
                <div class="stat-label-modern">Total Plans</div>
            </div>

            <div class="stat-card-modern stat-draft animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-edit"></i>
                </div>
                <div class="stat-value-modern"><?php echo $draft_count; ?></div>
                <div class="stat-label-modern">Drafts</div>
            </div>

            <div class="stat-card-modern stat-submitted animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <div class="stat-value-modern"><?php echo $submitted_count; ?></div>
                <div class="stat-label-modern">Submitted</div>
            </div>

            <div class="stat-card-modern stat-approved animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value-modern"><?php echo $approved_count; ?></div>
                <div class="stat-label-modern">Approved</div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($errors): ?>
            <div class="alert-modern alert-error-modern animate-fade-in-up">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars(implode(' ', $errors)); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-modern alert-success-modern animate-fade-in-up">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <!-- Form Section -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern" style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
                <div>
                <h2 class="card-title-modern">
                    <i class="fas <?php echo $edit_plan ? 'fa-edit' : 'fa-plus-circle'; ?>"></i>
                    <?php echo $edit_plan ? 'Edit Lesson Plan' : 'Create New Lesson Plan'; ?>
                </h2>
                <p class="card-subtitle-modern">
                    <?php echo $edit_plan ? 'Modify existing lesson plan details' : 'Fill in the details to create a new lesson plan'; ?>
                </p>
                </div>
                <button type="button" class="toggle-form-btn" id="toggleLessonForm">
                    <i class="fas fa-eye"></i>
                    <span>Show Form</span>
                </button>
            </div>
            <div class="card-body-modern">
                <div id="lessonFormBody" class="lesson-form-collapsible">
                <form method="POST" action="lesson-plan.php">
                    <input type="hidden" name="action" value="<?php echo $edit_plan ? 'edit' : 'add'; ?>">
                    <?php if ($edit_plan): ?>
                        <input type="hidden" name="id" value="<?php echo intval($edit_plan['id']); ?>">
                    <?php endif; ?>

                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label class="form-label-modern" for="subject_id">
                                <i class="fas fa-book"></i> Subject *
                            </label>
                            <select id="subject_id" name="subject_id" class="form-select-modern" required>
                                <option value="">Select subject</option>
                                <?php $sel_subject = $edit_plan['subject_id'] ?? 0; ?>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?php echo intval($s['id']); ?>" <?php echo intval($s['id']) === intval($sel_subject) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern" for="class_id">
                                <i class="fas fa-users"></i> Class *
                            </label>
                            <select id="class_id" name="class_id" class="form-select-modern" required>
                                <option value="">Select class</option>
                                <?php $sel_class = $edit_plan['class_id'] ?? 0; ?>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo intval($c['id']); ?>" <?php echo intval($c['id']) === intval($sel_class) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern" for="topic">
                                <i class="fas fa-tag"></i> Topic *
                            </label>
                            <input id="topic" name="topic" class="form-input-modern"
                                   value="<?php echo htmlspecialchars($edit_plan['topic'] ?? ''); ?>"
                                   placeholder="Enter lesson topic" required>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern" for="duration">
                                <i class="fas fa-clock"></i> Duration (minutes) *
                            </label>
                            <input type="number" id="duration" name="duration" class="form-input-modern"
                                   min="1" value="<?php echo intval($edit_plan['duration'] ?? 45); ?>" required>
                        </div>
                    </div>

                    <div class="form-row-modern">
                        <div class="form-group-modern">
                            <label class="form-label-modern" for="date_planned">
                                <i class="fas fa-calendar-alt"></i> Planned Date *
                            </label>
                            <input type="date" id="date_planned" name="date_planned" class="form-input-modern"
                                   value="<?php echo htmlspecialchars($edit_plan['date_planned'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern" for="assessment_method">
                                <i class="fas fa-clipboard-check"></i> Assessment Method *
                            </label>
                            <select id="assessment_method" name="assessment_method" class="form-select-modern" required>
                                <?php $sel_assess = $edit_plan['assessment_method'] ?? ''; ?>
                                <option value="">Select method</option>
                                <option value="Quiz" <?php echo $sel_assess === 'Quiz' ? 'selected' : ''; ?>>Quiz</option>
                                <option value="Assignment" <?php echo $sel_assess === 'Assignment' ? 'selected' : ''; ?>>Assignment</option>
                                <option value="Practical" <?php echo $sel_assess === 'Practical' ? 'selected' : ''; ?>>Practical</option>
                                <option value="Observation" <?php echo $sel_assess === 'Observation' ? 'selected' : ''; ?>>Observation</option>
                                <option value="Project" <?php echo $sel_assess === 'Project' ? 'selected' : ''; ?>>Project</option>
                            </select>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern" for="status">
                                <i class="fas fa-info-circle"></i> Status
                            </label>
                            <select id="status" name="status" class="form-select-modern">
                                <?php $sel_status = $edit_plan['status'] ?? 'draft'; ?>
                                <option value="draft" <?php echo $sel_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="scheduled" <?php echo $sel_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern" for="learning_objectives">
                            <i class="fas fa-bullseye"></i> Learning Objectives *
                        </label>
                        <textarea id="learning_objectives" name="learning_objectives" class="form-textarea-modern"
                                  placeholder="What will students learn from this lesson?" required><?php echo htmlspecialchars($edit_plan['learning_objectives'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern" for="teaching_methods">
                            <i class="fas fa-chalkboard-teacher"></i> Teaching Methods
                        </label>
                        <textarea id="teaching_methods" name="teaching_methods" class="form-textarea-modern"
                                  placeholder="Describe your teaching approach"><?php echo htmlspecialchars($edit_plan['teaching_methods'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern" for="resources">
                            <i class="fas fa-tools"></i> Resources & Materials
                        </label>
                        <textarea id="resources" name="resources" class="form-textarea-modern"
                                  placeholder="List required teaching resources"><?php echo htmlspecialchars($edit_plan['resources'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern" for="lesson_content">
                            <i class="fas fa-file-alt"></i> Lesson Content
                        </label>
                        <textarea id="lesson_content" name="lesson_content" class="form-textarea-modern"
                                  placeholder="Detailed lesson structure and content"><?php echo htmlspecialchars($edit_plan['lesson_content'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern" for="assessment_tasks">
                            <i class="fas fa-tasks"></i> Assessment Tasks
                        </label>
                        <textarea id="assessment_tasks" name="assessment_tasks" class="form-textarea-modern"
                                  placeholder="Specific assessment criteria and tasks"><?php echo htmlspecialchars($edit_plan['assessment_tasks'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern" for="differentiation">
                            <i class="fas fa-users-cog"></i> Differentiation Strategies
                        </label>
                        <textarea id="differentiation" name="differentiation" class="form-textarea-modern"
                                  placeholder="Accommodations for diverse learners"><?php echo htmlspecialchars($edit_plan['differentiation'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern" for="homework">
                            <i class="fas fa-home"></i> Homework/Assignment
                        </label>
                        <textarea id="homework" name="homework" class="form-textarea-modern"
                                  placeholder="Homework details and deadlines"><?php echo htmlspecialchars($edit_plan['homework'] ?? ''); ?></textarea>
                    </div>

                    <div style="display: flex; gap: 1rem; padding-top: 1.5rem; border-top: 1px solid var(--gray-200);">
                        <?php if ($edit_plan): ?>
                            <button type="submit" class="btn-modern-primary">
                                <i class="fas fa-save"></i>
                                <span>Save Changes</span>
                            </button>
                            <a href="lesson-plan.php" class="btn-modern-secondary">
                                <i class="fas fa-times"></i>
                                <span>Cancel</span>
                            </a>
                        <?php else: ?>
                            <button type="submit" class="btn-modern-primary">
                                <i class="fas fa-plus-circle"></i>
                                <span>Create Plan</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
                </div>
            </div>
        </div>
        </div>

        <!-- Lesson Plans Table -->
        <div class="lesson-plans-table-container animate-fade-in-up">
            <div class="table-header-modern">
                <div class="table-title-modern">
                    <i class="fas fa-list"></i>
                    My Lesson Plans
                </div>
                <div class="table-subtitle-modern">
                    <?php echo count($lesson_plans); ?> total plans
                </div>
            </div>

            <div class="table-wrapper-modern">
                <table class="lesson-plans-table-modern">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Subject</th>
                            <th>Topic</th>
                            <th>Class</th>
                            <th>Date</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Approval</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($lesson_plans) === 0): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 3rem; color: var(--gray-500);">
                                    <i class="fas fa-inbox" style="font-size: 3rem; color: var(--gray-300); margin-bottom: 1rem; display: block;"></i>
                                    <p style="margin: 0; font-size: 1.125rem;">No lesson plans created yet</p>
                                    <p style="margin: 0.5rem 0 0 0; color: var(--gray-400);">Start by creating your first lesson plan above</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($lesson_plans as $lp): ?>
                                <tr>
                                    <td>
                                        <span class="plan-id-modern">#<?php echo intval($lp['id']); ?></span>
                                    </td>
                                    <td>
                                        <span class="plan-subject-modern"><?php echo htmlspecialchars($lp['subject_name']); ?></span>
                                    </td>
                                    <td>
                                        <span class="plan-title-modern"><?php echo htmlspecialchars($lp['topic']); ?></span>
                                    </td>
                                    <td>
                                        <span class="plan-class-modern"><?php echo htmlspecialchars($lp['class_name']); ?></span>
                                    </td>
                                    <td>
                                        <span class="plan-date-modern"><?php echo date('M d, Y', strtotime($lp['date_planned'])); ?></span>
                                    </td>
                                    <td>
                                        <span class="plan-duration-modern"><?php echo intval($lp['duration']); ?> min</span>
                                    </td>
                                    <td>
                                        <span class="status-badge-modern status-<?php echo $lp['status']; ?>-modern">
                                            <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                            <?php echo htmlspecialchars(ucfirst($lp['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="approval-badge-modern approval-<?php echo $lp['approval_status'] ?: 'pending'; ?>-modern">
                                            <?php if ($lp['approval_status'] === 'approved'): ?>
                                                <i class="fas fa-check"></i>
                                            <?php elseif ($lp['approval_status'] === 'rejected'): ?>
                                                <i class="fas fa-times"></i>
                                            <?php else: ?>
                                                <i class="fas fa-clock"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars(ucfirst($lp['approval_status'] ?? 'pending')); ?>
                                        </span>
                                        <?php if (!empty($lp['approved_by_name'])): ?>
                                            <div style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.35rem;">
                                                By <?php echo htmlspecialchars($lp['approved_by_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="manage-actions-modern">
                                            <a class="btn-small-modern btn-view-modern" href="lesson-plans-detail.php?id=<?php echo intval($lp['id']); ?>" title="View Details">
                                                <i class="fas fa-eye"></i>
                                                <span>View</span>
                                            </a>

                                            <?php if (in_array($lp['status'], ['draft', 'on_hold'], true)): ?>
                                                <a class="btn-small-modern btn-edit-modern" href="lesson-plan.php?edit=<?php echo intval($lp['id']); ?>">
                                                    <i class="fas fa-edit"></i>
                                                    <span>Edit</span>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn-small-modern btn-edit-modern" disabled style="opacity: 0.5; cursor: not-allowed;" title="Only draft or on-hold plans can be edited">
                                                    <i class="fas fa-edit"></i>
                                                    <span>Edit</span>
                                                </button>
                                            <?php endif; ?>

                                            <?php if (in_array($lp['status'], ['draft', 'on_hold'], true)): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="submit_for_approval">
                                                    <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                    <button type="submit" class="btn-small-modern btn-submit-modern">
                                                        <i class="fas fa-paper-plane"></i>
                                                        <span>Submit</span>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn-small-modern btn-submit-modern" disabled style="opacity: 0.5; cursor: not-allowed;" title="Only draft or on-hold plans can be submitted">
                                                    <i class="fas fa-paper-plane"></i>
                                                    <span>Submit</span>
                                                </button>
                                            <?php endif; ?>

                                            <?php if (in_array($lp['status'], ['draft', 'on_hold'], true)): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this lesson plan?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                    <button type="submit" class="btn-small-modern btn-delete-modern">
                                                        <i class="fas fa-trash"></i>
                                                        <span>Delete</span>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn-small-modern btn-delete-modern" disabled style="opacity: 0.5; cursor: not-allowed;" title="Only draft or on-hold plans can be deleted">
                                                    <i class="fas fa-trash"></i>
                                                    <span>Delete</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

    

<script>
    // Mobile navigation is handled by includes/mobile_navigation.php

// Auto-focus first input in form
    document.addEventListener('DOMContentLoaded', function() {
        const firstInput = document.querySelector('.lesson-form input, .lesson-form select, .lesson-form textarea');
        if (firstInput) firstInput.focus();

        // Toggle create/edit form
        const toggleBtn = document.getElementById('toggleLessonForm');
        const formBody = document.getElementById('lessonFormBody');
        if (toggleBtn && formBody) {
            const show = true;
            formBody.classList.toggle('active', show);
            toggleBtn.innerHTML = show
                ? '<i class="fas fa-eye-slash"></i><span>Hide Form</span>'
                : '<i class="fas fa-eye"></i><span>Show Form</span>';
            toggleBtn.addEventListener('click', () => {
                const isOpen = formBody.classList.toggle('active');
                toggleBtn.innerHTML = isOpen
                    ? '<i class="fas fa-eye-slash"></i><span>Hide Form</span>'
                    : '<i class="fas fa-eye"></i><span>Show Form</span>';
            });
        }

        // Form validation feedback
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const requiredFields = form.querySelectorAll('[required]');
                let valid = true;

                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        valid = false;
                        field.style.borderColor = '#f72585';
                        field.style.boxShadow = '0 0 0 3px rgba(247, 37, 133, 0.1)';
                    }
                });

                if (!valid) {
                    e.preventDefault();
                    alert('Please fill in all required fields marked with *');
                }
            });
        });
    });
</script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
