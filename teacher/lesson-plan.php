<?php
session_start();
require_once '../config/db.php';

// Only allow teachers
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
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
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lesson_plans WHERE teacher_id = :teacher_id AND class_id = :class_id AND topic = :topic AND DATE(date_planned) = :date_planned");
            $stmt->execute([
                'teacher_id' => $user_id,
                'class_id' => $class_id,
                'topic' => $topic,
                'date_planned' => $date_planned
            ]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'You already created a lesson plan for this topic on the selected date.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO lesson_plans 
                    (subject_id, class_id, teacher_id, topic, duration, learning_objectives, teaching_methods, resources, lesson_content, assessment_method, assessment_tasks, differentiation, homework, date_planned, status, approval_status, created_at)
                    VALUES (:subject_id, :class_id, :teacher_id, :topic, :duration, :learning_objectives, :teaching_methods, :resources, :lesson_content, :assessment_method, :assessment_tasks, :differentiation, :homework, :date_planned, 'draft', 'pending', NOW())");
                $stmt->execute([
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
                    'date_planned' => $date_planned
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
                $stmt = $pdo->prepare("SELECT teacher_id, status FROM lesson_plans WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan || $plan['teacher_id'] != $user_id) {
                    $errors[] = 'Lesson plan not found or access denied.';
                } elseif ($plan['status'] !== 'draft') {
                    $errors[] = 'Only draft lesson plans can be edited.';
                } else {
                    $stmt = $pdo->prepare("UPDATE lesson_plans SET subject_id = :subject_id, class_id = :class_id, topic = :topic, duration = :duration, learning_objectives = :learning_objectives, teaching_methods = :teaching_methods, resources = :resources, lesson_content = :lesson_content, assessment_method = :assessment_method, assessment_tasks = :assessment_tasks, differentiation = :differentiation, homework = :homework, date_planned = :date_planned, status = :status WHERE id = :id");
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
                        'id' => $id
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
                $stmt = $pdo->prepare("SELECT teacher_id, status FROM lesson_plans WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan || $plan['teacher_id'] != $user_id) {
                    $errors[] = 'Not found or access denied.';
                } elseif ($plan['status'] === 'completed') {
                    $errors[] = 'Completed lesson plans cannot be deleted.';
                } else {
                    $pdo->prepare("DELETE FROM lesson_plan_feedback WHERE lesson_plan_id = :id")->execute(['id' => $id]);
                    $pdo->prepare("DELETE FROM lesson_plan_attachments WHERE lesson_plan_id = :id")->execute(['id' => $id]);
                    $pdo->prepare("DELETE FROM lesson_plans WHERE id = :id")->execute(['id' => $id]);
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
                $stmt = $pdo->prepare("SELECT teacher_id, status FROM lesson_plans WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan || $plan['teacher_id'] != $user_id) {
                    $errors[] = 'Not found or access denied.';
                } elseif ($plan['status'] !== 'draft') {
                    $errors[] = 'Only draft plans can be submitted for approval.';
                } else {
                    $stmt = $pdo->prepare("UPDATE lesson_plans SET approval_status = 'pending', status = 'submitted' WHERE id = :id");
                    $stmt->execute(['id' => $id]);
                    $success = 'Lesson plan submitted for principal review!';
                    header("Location: lesson-plan.php");
                    exit;
                }
            }
        }
    }
}

// Fetch dropdowns
$subjects = $pdo->query("SELECT id, subject_name FROM subjects ORDER BY subject_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch teacher's lesson plans
$stmt = $pdo->prepare("SELECT lp.*, s.subject_name, c.class_name FROM lesson_plans lp JOIN subjects s ON lp.subject_id = s.id JOIN classes c ON lp.class_id = c.id WHERE lp.teacher_id = :teacher_id ORDER BY lp.date_planned DESC, lp.created_at DESC");
$stmt->execute(['teacher_id' => $user_id]);
$lesson_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If editing, fetch single plan
$edit_plan = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM lesson_plans WHERE id = :id AND teacher_id = :teacher_id");
        $stmt->execute(['id' => $edit_id, 'teacher_id' => $user_id]);
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
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>My Lesson Plans | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #4cc9f0;
            --warning-color: #f8961e;
            --danger-color: #f72585;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --gray-color: #6c757d;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.08);
            --hover-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
        
        .lesson-section {
            margin-bottom: 2rem;
            background: white;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        
        .lesson-section:hover {
            transform: translateY(-2px);
            box-shadow: var(--hover-shadow);
        }
        
        .lesson-card {
            padding: 1.5rem;
        }
        
        .lesson-card h3 {
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .lesson-card h3 i {
            color: var(--primary-color);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .form-col {
            display: flex;
            flex-direction: column;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .form-group label i {
            color: var(--primary-color);
            font-size: 0.9em;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .form-control[required] {
            border-left: 4px solid var(--primary-color);
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e9ecef;
        }
        
        .btn-gold {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: white;
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 165, 0, 0.3);
        }
        
        .btn-secondary {
            background: var(--gray-color);
            color: white;
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            background: white;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        .table th {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        
        .table td {
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .table tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .table tr:last-child td {
            border-bottom: none;
        }
        
        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .badge-secondary { background: #6c757d; color: white; }
        .badge-warning { background: #ffc107; color: #212529; }
        .badge-primary { background: var(--primary-color); color: white; }
        .badge-success { background: #28a745; color: white; }
        .badge-danger { background: var(--danger-color); color: white; }
        .badge-default { background: #e9ecef; color: #495057; }
        
        .manage-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .btn-small {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        
        .btn-view { background: #17a2b8; color: white; }
        .btn-edit { background: #ffc107; color: #212529; }
        .btn-submit { background: var(--primary-color); color: white; }
        .btn-delete { background: var(--danger-color); color: white; }
        
        .btn-small:hover {
            transform: translateY(-1px);
            opacity: 0.9;
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #ffeaea, #ffcccc);
            border-left: 4px solid var(--danger-color);
            color: #721c24;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border-left: 4px solid #28a745;
            color: #155724;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            text-align: center;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--hover-shadow);
        }
        
        .stat-card i {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin: 0.5rem 0;
        }
        
        .stat-label {
            color: var(--gray-color);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .lesson-card {
                padding: 1rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn-gold, .btn-secondary {
                width: 100%;
                justify-content: center;
            }
            
            .manage-actions {
                flex-direction: column;
            }
            
            .table th, .table td {
                padding: 0.75rem;
            }
        }
        
        @media (max-width: 480px) {
            .lesson-section {
                margin: 0 -1rem;
                border-radius: 0;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-number {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
<header class="dashboard-header">
    <div class="header-container">
        <div class="header-right">
            <div class="school-logo-container">
                <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                <h1 class="school-name">SahabFormMaster</h1>
            </div>
        </div>
        <div class="header-left">
            <div class="teacher-info">
                <span class="teacher-name"><?php echo htmlspecialchars($user_name); ?></span>
                <span class="teacher-role">Teacher</span>
            </div>
            <a href="../index.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</header>

<div class="dashboard-container">
             <aside class="sidebar">
        <!-- Sidebar content remains the same -->
             <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link active">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    
                                    
                </ul>
            </nav>
    </aside>
    <main class="main-content">

        

        <div class="content-header">
            <h2><i class="fas fa-book-open"></i> My Lesson Plans</h2>
            <p class="small-muted">Create, edit, and submit lesson plans for approval</p>
        </div>
        
        <!-- Stats Overview -->
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
        
        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-file-alt"></i>
                <div class="stat-number"><?php echo $total_count; ?></div>
                <div class="stat-label">Total Plans</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-edit"></i>
                <div class="stat-number"><?php echo $draft_count; ?></div>
                <div class="stat-label">Drafts</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-paper-plane"></i>
                <div class="stat-number"><?php echo $submitted_count; ?></div>
                <div class="stat-label">Submitted</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <div class="stat-number"><?php echo $approved_count; ?></div>
                <div class="stat-label">Approved</div>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>

        <!-- Form Section -->
        <section class="lesson-section">
            <div class="lesson-card">
                <h3>
                    <i class="fas <?php echo $edit_plan ? 'fa-edit' : 'fa-plus-circle'; ?>"></i>
                    <?php echo $edit_plan ? 'Edit Lesson Plan' : 'Create New Lesson Plan'; ?>
                </h3>

                <form method="POST" class="lesson-form" action="lesson-plan.php">
                    <input type="hidden" name="action" value="<?php echo $edit_plan ? 'edit' : 'add'; ?>">
                    <?php if ($edit_plan): ?>
                        <input type="hidden" name="id" value="<?php echo intval($edit_plan['id']); ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="subject_id"><i class="fas fa-book"></i> Subject *</label>
                                <select id="subject_id" name="subject_id" class="form-control" required>
                                    <option value="">Select subject</option>
                                    <?php $sel_subject = $edit_plan['subject_id'] ?? 0; ?>
                                    <?php foreach ($subjects as $s): ?>
                                        <option value="<?php echo intval($s['id']); ?>" <?php echo intval($s['id']) === intval($sel_subject) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($s['subject_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="class_id"><i class="fas fa-users"></i> Class *</label>
                                <select id="class_id" name="class_id" class="form-control" required>
                                    <option value="">Select class</option>
                                    <?php $sel_class = $edit_plan['class_id'] ?? 0; ?>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?php echo intval($c['id']); ?>" <?php echo intval($c['id']) === intval($sel_class) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="topic"><i class="fas fa-tag"></i> Topic *</label>
                                <input id="topic" name="topic" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_plan['topic'] ?? ''); ?>" 
                                       placeholder="Enter lesson topic" required>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="duration"><i class="fas fa-clock"></i> Duration (minutes) *</label>
                                <input type="number" id="duration" name="duration" class="form-control" 
                                       min="1" value="<?php echo intval($edit_plan['duration'] ?? 45); ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="date_planned"><i class="fas fa-calendar-alt"></i> Planned Date *</label>
                                <input type="date" id="date_planned" name="date_planned" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_plan['date_planned'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="assessment_method"><i class="fas fa-clipboard-check"></i> Assessment Method *</label>
                                <select id="assessment_method" name="assessment_method" class="form-control" required>
                                    <?php $sel_assess = $edit_plan['assessment_method'] ?? ''; ?>
                                    <option value="">Select method</option>
                                    <option value="Quiz" <?php echo $sel_assess === 'Quiz' ? 'selected' : ''; ?>>Quiz</option>
                                    <option value="Assignment" <?php echo $sel_assess === 'Assignment' ? 'selected' : ''; ?>>Assignment</option>
                                    <option value="Practical" <?php echo $sel_assess === 'Practical' ? 'selected' : ''; ?>>Practical</option>
                                    <option value="Observation" <?php echo $sel_assess === 'Observation' ? 'selected' : ''; ?>>Observation</option>
                                    <option value="Project" <?php echo $sel_assess === 'Project' ? 'selected' : ''; ?>>Project</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="status"><i class="fas fa-info-circle"></i> Status</label>
                                <select id="status" name="status" class="form-control">
                                    <?php $sel_status = $edit_plan['status'] ?? 'draft'; ?>
                                    <option value="draft" <?php echo $sel_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="scheduled" <?php echo $sel_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="learning_objectives"><i class="fas fa-bullseye"></i> Learning Objectives *</label>
                        <textarea id="learning_objectives" name="learning_objectives" class="form-control" rows="3" 
                                  placeholder="What will students learn from this lesson?" required><?php echo htmlspecialchars($edit_plan['learning_objectives'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="teaching_methods"><i class="fas fa-chalkboard-teacher"></i> Teaching Methods</label>
                        <textarea id="teaching_methods" name="teaching_methods" class="form-control" rows="2" 
                                  placeholder="Describe your teaching approach"><?php echo htmlspecialchars($edit_plan['teaching_methods'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="resources"><i class="fas fa-tools"></i> Resources & Materials</label>
                        <textarea id="resources" name="resources" class="form-control" rows="2" 
                                  placeholder="List required teaching resources"><?php echo htmlspecialchars($edit_plan['resources'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="lesson_content"><i class="fas fa-file-alt"></i> Lesson Content</label>
                        <textarea id="lesson_content" name="lesson_content" class="form-control" rows="4" 
                                  placeholder="Detailed lesson structure and content"><?php echo htmlspecialchars($edit_plan['lesson_content'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="assessment_tasks"><i class="fas fa-tasks"></i> Assessment Tasks</label>
                        <textarea id="assessment_tasks" name="assessment_tasks" class="form-control" rows="3" 
                                  placeholder="Specific assessment criteria and tasks"><?php echo htmlspecialchars($edit_plan['assessment_tasks'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="differentiation"><i class="fas fa-users-cog"></i> Differentiation Strategies</label>
                        <textarea id="differentiation" name="differentiation" class="form-control" rows="3" 
                                  placeholder="Accommodations for diverse learners"><?php echo htmlspecialchars($edit_plan['differentiation'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="homework"><i class="fas fa-home"></i> Homework/Assignment</label>
                        <textarea id="homework" name="homework" class="form-control" rows="2" 
                                  placeholder="Homework details and deadlines"><?php echo htmlspecialchars($edit_plan['homework'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-actions">
                        <?php if ($edit_plan): ?>
                            <button type="submit" class="btn-gold">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <a href="lesson-plan.php" class="btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        <?php else: ?>
                            <button type="submit" class="btn-gold">
                                <i class="fas fa-plus-circle"></i> Create Plan
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <!-- Lesson Plans Table -->
        <section class="lesson-section">
            <div class="lesson-card">
                <h3><i class="fas fa-list"></i> My Lesson Plans</h3>
                
                <div class="table-wrapper">
                    <table class="table">
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
                                    <td colspan="9" class="text-center small-muted" style="padding: 3rem;">
                                        <i class="fas fa-inbox" style="font-size: 3rem; color: #dee2e6; margin-bottom: 1rem;"></i>
                                        <p>No lesson plans created yet</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lesson_plans as $lp): ?>
                                    <tr>
                                        <td><strong>#<?php echo intval($lp['id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($lp['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($lp['topic']); ?></td>
                                        <td><?php echo htmlspecialchars($lp['class_name']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($lp['date_planned'])); ?></td>
                                        <td><?php echo intval($lp['duration']); ?> min</td>
                                        <td>
                                            <span class="badge <?php echo getStatusBadge($lp['status']); ?>">
                                                <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                                <?php echo htmlspecialchars(ucfirst($lp['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $lp['approval_status'] === 'approved' ? 'badge-success' : ($lp['approval_status'] === 'rejected' ? 'badge-danger' : 'badge-warning'); ?>">
                                                <?php if ($lp['approval_status'] === 'approved'): ?>
                                                    <i class="fas fa-check"></i>
                                                <?php elseif ($lp['approval_status'] === 'rejected'): ?>
                                                    <i class="fas fa-times"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-clock"></i>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars(ucfirst($lp['approval_status'] ?? 'pending')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="manage-actions">
                                                <a class="btn-small btn-view" href="lesson-plans-detail.php?id=<?php echo intval($lp['id']); ?>" title="View Details">
                                                    <i class="fas fa-eye"></i> View
                                                </a>

                                                <?php if ($lp['status'] === 'draft'): ?>
                                                    <a class="btn-small btn-edit" href="lesson-plan.php?edit=<?php echo intval($lp['id']); ?>">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="submit_for_approval">
                                                        <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                        <button type="submit" class="btn-small btn-submit">
                                                            <i class="fas fa-paper-plane"></i> Submit
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($lp['status'] !== 'completed'): ?>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this lesson plan?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                        <button type="submit" class="btn-small btn-delete">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
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
        </section>
    </main>
</div>

<footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4><i class="fas fa-graduation-cap"></i> SahabFormMaster</h4>
                <p>Empowering teachers through effective lesson planning</p>
            </div>
        </div>
    </div>
</footer>

<script>
    // Auto-focus first input in form
    document.addEventListener('DOMContentLoaded', function() {
        const firstInput = document.querySelector('.lesson-form input, .lesson-form select, .lesson-form textarea');
        if (firstInput) firstInput.focus();
        
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
</body>
</html>