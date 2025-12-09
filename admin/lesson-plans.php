<?php
// filepath: c:\xampp\htdocs\sahabformmaster\admin\lesson-plan.php
session_start();
require_once '../config/db.php';

// Only allow principal (admin) and teachers to access
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['principal', 'teacher'])) {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['full_name'] ?? 'User';
$is_principal = ($user_role === 'principal');

$errors = [];
$success = '';

// Handle Create / Update via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $subject_id = intval($_POST['subject_id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);
    $teacher_id = intval($_POST['teacher_id'] ?? 0);
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
    $principal_remarks = $is_principal ? trim($_POST['principal_remarks'] ?? '') : '';

    // Validate inputs
    $date_planned = trim($_POST['date_planned'] ?? '');
    $status = $_POST['status'] ?? 'draft';
    $principal_remarks = $is_principal ? trim($_POST['principal_remarks'] ?? '') : '';

    // Validate inputs
    if ($subject_id <= 0) {
        $errors[] = 'Subject is required.';
    }
    if ($class_id <= 0) {
        $errors[] = 'Class is required.';
    }
    if ($topic === '') {
        $errors[] = 'Topic is required.';
    }
    if ($duration <= 0) {
        $errors[] = 'Duration must be a valid number.';
    }
    if ($learning_objectives === '') {
        $errors[] = 'Learning objectives are required.';
    }
    if ($assessment_method === '') {
        $errors[] = 'Assessment method is required.';
    }
    if ($date_planned === '' || !strtotime($date_planned)) {
        $errors[] = 'Valid planned date is required.';
    }

    // For teachers: set teacher_id to current user
    if ($user_role === 'teacher') {
        $teacher_id = $user_id;
    }

    // Validate teacher_id (must exist and be a teacher)
    if ($teacher_id <= 0) {
        $errors[] = 'Teacher is required.';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND role = 'teacher'");
        $stmt->execute(['id' => $teacher_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = 'Selected teacher does not exist or is not a teacher.';
        }
    }

    if (empty($errors)) {
        if ($action === 'add') {
            // Check if teacher already has lesson plan for this topic/class on same date
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lesson_plans 
                                  WHERE teacher_id = :teacher_id AND class_id = :class_id 
                                  AND topic = :topic AND DATE(date_planned) = :date_planned");
            $stmt->execute([
                'teacher_id' => $teacher_id,
                'class_id' => $class_id,
                'topic' => $topic,
                'date_planned' => $date_planned
            ]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'A lesson plan for this topic already exists for this class on this date.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO lesson_plans 
                                      (subject_id, class_id, teacher_id, topic, duration, learning_objectives, 
                                       teaching_methods, resources, lesson_content, assessment_method, assessment_tasks, 
                                       differentiation, homework, date_planned, status, principal_remarks) 
                                      VALUES (:subject_id, :class_id, :teacher_id, :topic, :duration, :learning_objectives, 
                                              :teaching_methods, :resources, :lesson_content, :assessment_method, :assessment_tasks, 
                                              :differentiation, :homework, :date_planned, :status, :principal_remarks)");
                $stmt->execute([
                    'subject_id' => $subject_id,
                    'class_id' => $class_id,
                    'teacher_id' => $teacher_id,
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
                    'principal_remarks' => $principal_remarks
                ]);
                $success = 'Lesson plan created successfully.';
                header("Location: lesson-plans.php");
                exit;
            }
        }

        if ($action === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid lesson plan ID.';
            } else {
                // Check permissions: teacher can only edit own drafts, principal can edit all
                $stmt = $pdo->prepare("SELECT teacher_id, status FROM lesson_plans WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $plan = $stmt->fetch();

                if (!$plan) {
                    $errors[] = 'Lesson plan not found.';
                } elseif ($user_role === 'teacher' && ($plan['teacher_id'] != $user_id || $plan['status'] !== 'draft')) {
                    $errors[] = 'You can only edit your own draft lesson plans.';
                } else {
                    $stmt = $pdo->prepare("UPDATE lesson_plans SET 
                                          subject_id = :subject_id, class_id = :class_id, teacher_id = :teacher_id,
                                          topic = :topic, duration = :duration, learning_objectives = :learning_objectives,
                                          teaching_methods = :teaching_methods, resources = :resources, lesson_content = :lesson_content,
                                          assessment_method = :assessment_method, assessment_tasks = :assessment_tasks,
                                          differentiation = :differentiation, homework = :homework, date_planned = :date_planned,
                                          status = :status, principal_remarks = :principal_remarks WHERE id = :id");
                    $stmt->execute([
                        'subject_id' => $subject_id,
                        'class_id' => $class_id,
                        'teacher_id' => $teacher_id,
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
                        'principal_remarks' => $principal_remarks,
                        'id' => $id
                    ]);
                    $success = 'Lesson plan updated successfully.';
                    header("Location: lesson-plans.php");
                    exit;
                }
            }
        }

        if ($action === 'approve' && $is_principal) {
            $id = intval($_POST['id'] ?? 0);
            $approval_status = $_POST['approval_status'] ?? 'approved';
            if ($id <= 0) {
                $errors[] = 'Invalid lesson plan ID.';
            } else {
                $stmt = $pdo->prepare("UPDATE lesson_plans SET approval_status = :approval_status, approved_by = :approved_by, status = :status WHERE id = :id");
                $new_status = ($approval_status === 'approved') ? 'scheduled' : 'on_hold';
                $stmt->execute([
                    'approval_status' => $approval_status,
                    'approved_by' => $user_id,
                    'status' => $new_status,
                    'id' => $id
                ]);
                $success = 'Lesson plan ' . $approval_status . ' successfully.';
                header("Location: lesson-plans.php");
                exit;
            }
        }

        if ($action === 'complete' && $is_principal) {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid lesson plan ID.';
            } else {
                $stmt = $pdo->prepare("UPDATE lesson_plans SET status = :status WHERE id = :id");
                $stmt->execute(['status' => 'completed', 'id' => $id]);
                $success = 'Lesson plan marked as completed.';
                header("Location: lesson-plans.php");
                exit;
            }
        }

        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid lesson plan ID.';
            } else {
                $stmt = $pdo->prepare("SELECT teacher_id, status FROM lesson_plans WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $plan = $stmt->fetch();

                if (!$plan) {
                    $errors[] = 'Lesson plan not found.';
                // } elseif ($user_role === 'teacher' && ($plan['teacher_id'] != $user_id || $plan['status'] !== 'draft')) {
                //     $errors[] = 'You can only delete your own draft lesson plans.';
                // } elseif ($user_role === 'teacher' && $plan['teacher_id'] != $user_id) {
                //     // Teacher can delete only their own plans (allow regardless of status)
                //     $errors[] = 'You can only delete your own lesson plans.';
                } else {
                    $pdo->prepare("DELETE FROM lesson_plan_feedback WHERE lesson_plan_id = :id")->execute(['id' => $id]);
                    $pdo->prepare("DELETE FROM lesson_plan_attachments WHERE lesson_plan_id = :id")->execute(['id' => $id]);
                    $pdo->prepare("DELETE FROM lesson_plans WHERE id = :id")->execute(['id' => $id]);
                    $success = 'Lesson plan deleted successfully.';
                    header("Location: lesson-plans.php");
                    exit;
                }
            }
        }
    }
}

// Fetch subjects
$stmt = $pdo->query("SELECT id, subject_name FROM subjects ORDER BY subject_name ASC");
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch classes
$stmt = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch teachers
$stmt = $pdo->query("SELECT id, full_name FROM users WHERE role = 'teacher' ORDER BY full_name ASC");
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Search and filter
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['filter_status'] ?? '';
$filter_teacher = $_GET['filter_teacher'] ?? '';
$filter_class = $_GET['filter_class'] ?? '';

$query = "SELECT lp.*, s.subject_name as subject_name, c.class_name, u.full_name as teacher_name 
          FROM lesson_plans lp 
          JOIN subjects s ON lp.subject_id = s.id 
          JOIN classes c ON lp.class_id = c.id 
          JOIN users u ON lp.teacher_id = u.id 
          WHERE 1=1";
$params = [];

// Teachers see only their own plans
if ($user_role === 'teacher') {
    $query .= " AND lp.teacher_id = :teacher_id";
    $params['teacher_id'] = $user_id;
}

if ($search !== '') {
    $query .= " AND (lp.topic LIKE :search OR s.subject_name LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($filter_status !== '') {
    $query .= " AND lp.status = :status";
    $params['status'] = $filter_status;
}

if ($filter_teacher !== '' && $is_principal) {
    $query .= " AND lp.teacher_id = :teacher_id_filter";
    $params['teacher_id_filter'] = intval($filter_teacher);
}

if ($filter_class !== '') {
    $query .= " AND lp.class_id = :class_id_filter";
    $params['class_id_filter'] = intval($filter_class);
}

$query .= " ORDER BY lp.date_planned DESC, lp.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$lesson_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If editing, fetch lesson plan data
$edit_plan = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM lesson_plans WHERE id = :id");
        $stmt->execute(['id' => $edit_id]);
        $edit_plan = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check permissions
        if ($edit_plan && $user_role === 'teacher' && ($edit_plan['teacher_id'] != $user_id || $edit_plan['status'] !== 'draft')) {
            $edit_plan = null;
        }
    }
}

// Get approval status color
function getApprovalBadge($status) {
    $classes = [
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'pending' => 'badge-warning'
    ];
    return $classes[$status] ?? 'badge-default';
}

// Get status color
function getStatusBadge($status) {
    $classes = [
        'draft' => 'badge-secondary',
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
    <title>Lesson Plans | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/lesson-plan.css">
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
                <span class="teacher-role"><?php echo ucfirst($user_role); ?></span>
            </div>
            <a href="../index.php" class="btn-logout">Logout</a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <aside class="sidebar">
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
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">School News</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Manage Students</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="results.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Manage Results</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="lesson-plans.php" class="nav-link">
                            <span class="nav-icon">📝</span>
                            <span class="nav-text">Manage Lesson Plans</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_curriculum.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Manage Curriculum</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="school.php" class="nav-link">
                            <span class="nav-icon">🏫</span>
                            <span class="nav-text">Manage School</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="manage_user.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="visitors.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Visitors</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="timebook.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Teachers Time Book</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="travelling.php" class="nav-link">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Manage Travelling</span>
                        </a>
                    </li>
                                        
                    <li class="nav-item">
                        <a href="classwork.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Class Work</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a href="assignment.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Assignment</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a href="attendance.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">Attendance Register</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a href="schoolfees.php" class="nav-link">
                            <span class="nav-icon">📚</span>
                            <span class="nav-text">School Fees Payments</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

    <main class="main-content">
        <div class="content-header">
            <h2>Lesson Plans</h2>
            <p class="small-muted"><?php echo $is_principal ? 'Review and manage all lesson plans' : 'Create and manage your lesson plans'; ?></p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Create / Edit form -->
        <section class="lesson-section">
            <div class="lesson-card">
                <h3><?php echo $edit_plan ? 'Edit Lesson Plan' : 'Create New Lesson Plan'; ?></h3>

                <form method="POST" class="lesson-form" action="">
                    <input type="hidden" name="action" value="<?php echo $edit_plan ? 'edit' : 'add'; ?>">
                    <?php if ($edit_plan): ?>
                        <input type="hidden" name="id" value="<?php echo intval($edit_plan['id']); ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="subject_id">Subject *</label>
                                <select id="subject_id" name="subject_id" class="form-control" required>
                                    <option value="">Select Subject</option>
                                    <?php $sel_subject = $edit_plan['subject_id'] ?? 0; ?>
                                    <?php foreach ($subjects as $s): ?>
                                        <option value="<?php echo intval($s['id']); ?>" <?php echo intval($s['id']) === $sel_subject ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($s['subject_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="class_id">Class *</label>
                                <select id="class_id" name="class_id" class="form-control" required>
                                    <option value="">Select Class</option>
                                    <?php $sel_class = $edit_plan['class_id'] ?? 0; ?>
                                    <?php foreach ($classes as $cl): ?>
                                        <option value="<?php echo intval($cl['id']); ?>" <?php echo intval($cl['id']) === $sel_class ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cl['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                       <div class="form-col">
                           <div class="form-group">
                               <label for="teacher_id">Teacher *</label>
                               <select id="teacher_id" name="teacher_id" class="form-control" required <?php echo $user_role === 'teacher' ? 'disabled' : ''; ?>>
                                   <option value="">Select Teacher</option>
                                   <?php $sel_teacher = $edit_plan['teacher_id'] ?? ($user_role === 'teacher' ? $user_id : 0); ?>
                                   <?php foreach ($teachers as $t): ?>
                                       <option value="<?php echo intval($t['id']); ?>" <?php echo intval($t['id']) === intval($sel_teacher) ? 'selected' : ''; ?>>
                                           <?php echo htmlspecialchars($t['full_name']); ?>
                                       </option>
                                   <?php endforeach; ?>
                               </select>
                               <?php if ($user_role === 'teacher'): ?>
                                   <input type="hidden" name="teacher_id" value="<?php echo intval($user_id); ?>">
                               <?php endif; ?>
                           </div>
                       </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="topic">Topic/Unit *</label>
                                <input type="text" id="topic" name="topic" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_plan['topic'] ?? ''); ?>" 
                                       placeholder="e.g. Fractions, The Human Body" required>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="duration">Duration (minutes) *</label>
                                <input type="number" id="duration" name="duration" class="form-control" 
                                       value="<?php echo intval($edit_plan['duration'] ?? 0); ?>" 
                                       min="1" placeholder="45" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="date_planned">Planned Date *</label>
                                <input type="date" id="date_planned" name="date_planned" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_plan['date_planned'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="assessment_method">Assessment Method *</label>
                                <select id="assessment_method" name="assessment_method" class="form-control" required>
                                    <option value="">Select Assessment Method</option>
                                    <?php $sel_assess = $edit_plan['assessment_method'] ?? ''; ?>
                                    <option value="Quiz" <?php echo $sel_assess === 'Quiz' ? 'selected' : ''; ?>>Quiz</option>
                                    <option value="Assignment" <?php echo $sel_assess === 'Assignment' ? 'selected' : ''; ?>>Assignment</option>
                                    <option value="Practical" <?php echo $sel_assess === 'Practical' ? 'selected' : ''; ?>>Practical</option>
                                    <option value="Observation" <?php echo $sel_assess === 'Observation' ? 'selected' : ''; ?>>Observation</option>
                                    <option value="Project" <?php echo $sel_assess === 'Project' ? 'selected' : ''; ?>>Project</option>
                                    <option value="Presentation" <?php echo $sel_assess === 'Presentation' ? 'selected' : ''; ?>>Presentation</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status" class="form-control">
                                    <?php $sel_status = $edit_plan['status'] ?? 'draft'; ?>
                                    <option value="draft" <?php echo $sel_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="scheduled" <?php echo $sel_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="completed" <?php echo $sel_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="learning_objectives">Learning Objectives *</label>
                        <textarea id="learning_objectives" name="learning_objectives" class="form-control" rows="3" 
                                  placeholder="What will students be able to do after this lesson?" required><?php echo htmlspecialchars($edit_plan['learning_objectives'] ?? ''); ?></textarea>
                        <small class="small-muted">Be specific and use measurable outcomes (SMART objectives)</small>
                    </div>

                    <div class="form-group">
                        <label for="teaching_methods">Teaching Methods</label>
                        <textarea id="teaching_methods" name="teaching_methods" class="form-control" rows="2" 
                                  placeholder="e.g. Lecture, Discussion, Practical, Group Work, Role Play"><?php echo htmlspecialchars($edit_plan['teaching_methods'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="resources">Learning Resources/Materials</label>
                        <textarea id="resources" name="resources" class="form-control" rows="2" 
                                  placeholder="Textbooks, charts, videos, lab equipment, etc."><?php echo htmlspecialchars($edit_plan['resources'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="lesson_content">Detailed Lesson Content</label>
                        <textarea id="lesson_content" name="lesson_content" class="form-control" rows="5" 
                                  placeholder="Lesson outline: Introduction, Main content, Activities, Conclusion"><?php echo htmlspecialchars($edit_plan['lesson_content'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="assessment_tasks">Assessment Tasks</label>
                        <textarea id="assessment_tasks" name="assessment_tasks" class="form-control" rows="3" 
                                  placeholder="Specific questions, tasks or criteria for assessment"><?php echo htmlspecialchars($edit_plan['assessment_tasks'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="differentiation">Differentiation Strategies</label>
                        <textarea id="differentiation" name="differentiation" class="form-control" rows="3" 
                                  placeholder="Support for struggling learners, extension for advanced learners"><?php echo htmlspecialchars($edit_plan['differentiation'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="homework">Homework/Assignment</label>
                        <textarea id="homework" name="homework" class="form-control" rows="2" 
                                  placeholder="Homework tasks and deadline"><?php echo htmlspecialchars($edit_plan['homework'] ?? ''); ?></textarea>
                    </div>

                    <?php if ($is_principal && $edit_plan): ?>
                    <div class="form-group">
                        <label for="principal_remarks">Principal's Remarks/Feedback</label>
                        <textarea id="principal_remarks" name="principal_remarks" class="form-control" rows="2" 
                                  placeholder="Your feedback or notes"><?php echo htmlspecialchars($edit_plan['principal_remarks'] ?? ''); ?></textarea>
                    </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <?php if ($edit_plan): ?>
                            <button type="submit" class="btn-gold">Update Lesson Plan</button>
                            <a href="lesson-plans.php" class="btn-secondary">Cancel</a>
                        <?php else: ?>
                            <button type="submit" class="btn-gold">Create Lesson Plan</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <!-- Search and Filter -->
        <section class="lesson-section">
            <div class="search-filter">
                <form method="GET" class="search-form">
                    <div class="form-group">
                        <input type="text" name="search" class="form-control" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search topic or subject...">
                    </div>

                    <div class="form-group">
                        <select name="filter_status" class="form-control">
                            <option value="">All Status</option>
                            <option value="draft" <?php echo $filter_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="scheduled" <?php echo $filter_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="on_hold" <?php echo $filter_status === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                        </select>
                    </div>

                    <?php if ($is_principal): ?>
                    <div class="form-group">
                        <select name="filter_teacher" class="form-control">
                            <option value="">All Teachers</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?php echo intval($t['id']); ?>" <?php echo $filter_teacher == $t['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <select name="filter_class" class="form-control">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $cl): ?>
                                <option value="<?php echo intval($cl['id']); ?>" <?php echo $filter_class == $cl['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cl['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn-search">Search</button>
                    <a href="lesson-plans.php" class="btn-reset">Reset</a>
                </form>
            </div>
        </section>

        <!-- Lesson Plans Table -->
        <section class="lesson-section">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Subject</th>
                            <th>Topic</th>
                            <th>Class</th>
                            <th>Teacher</th>
                            <th>Planned Date</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <?php if ($is_principal): ?>
                                <th>Approval</th>
                            <?php endif; ?>
                            <th style="width:200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($lesson_plans) === 0): ?>
                            <tr><td colspan="<?php echo $is_principal ? 10 : 9; ?>" class="text-center small-muted">No lesson plans found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($lesson_plans as $lp): ?>
                                <tr>
                                    <td><?php echo intval($lp['id']); ?></td>
                                    <td><?php echo htmlspecialchars($lp['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($lp['topic']); ?></td>
                                    <td><?php echo htmlspecialchars($lp['class_name']); ?></td>
                                    <td class="small-muted"><?php echo htmlspecialchars($lp['teacher_name']); ?></td>
                                    <td><?php echo htmlspecialchars($lp['date_planned']); ?></td>
                                    <td><?php echo intval($lp['duration']); ?> min</td>
                                    <td>
                                        <span class="badge <?php echo getStatusBadge($lp['status']); ?>">
                                            <?php echo ucfirst($lp['status']); ?>
                                        </span>
                                    </td>
                                    <?php if ($is_principal): ?>
                                        <td>
                                            <span class="badge <?php echo getApprovalBadge($lp['approval_status']); ?>">
                                                <?php echo ucfirst($lp['approval_status']); ?>
                                            </span>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <div class="manage-actions">
                                            <a class="btn-small btn-view" href="lesson-plans-detail.php?id=<?php echo intval($lp['id']); ?>" title="View Details">👁</a>

                                            <?php if ($user_role === 'teacher' && $lp['teacher_id'] == $user_id && $lp['status'] === 'draft'): ?>
                                                <a class="btn-small btn-edit" href="lesson-plans.phps?edit=<?php echo intval($lp['id']); ?>">Edit</a>
                                            <?php elseif ($is_principal): ?>
                                                <a class="btn-small btn-edit" href="lesson-plans.php?edit=<?php echo intval($lp['id']); ?>">Edit</a>
                                            <?php endif; ?>

                                            <?php if ($is_principal && $lp['approval_status'] === 'pending'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                    <input type="hidden" name="approval_status" value="approved">
                                                    <button type="submit" class="btn-small btn-approve" title="Approve">✓</button>
                                                </form>

                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                    <input type="hidden" name="approval_status" value="rejected">
                                                    <button type="submit" class="btn-small btn-reject" title="Reject" onclick="return confirm('Reject this lesson plan?');">✗</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($is_principal && $lp['status'] === 'scheduled'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="complete">
                                                    <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                    <button type="submit" class="btn-small btn-complete" title="Mark Completed">✔</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (($user_role === 'teacher' && $lp['teacher_id'] == $user_id && $lp['status'] === 'draft') || $is_principal): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this lesson plan?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                    <button type="submit" class="btn-small btn-delete">Delete</button>
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
        </section>
    </main>
</div>

<footer class="dashboard-footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>About SahabFormMaster</h4>
                <p>Professional lesson planning and management system.</p>
            </div>
            <div class="footer-section">
                <h4>Quick Links</h4>
                <div class="footer-links">
                    <a href="index.php">Dashboard</a>
                    <a href="lesson-plan.php">Lesson Plans</a>
                    <a href="manage_curriculum.php">Curriculum</a>
                </div>
            </div>
            <div class="footer-section">
                <h4>Support</h4>
                <p>Email: <a href="mailto:support@sahabformmaster.com">support@sahabformmaster.com</a></p>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copyright">&copy; 2025 SahabFormMaster. All rights reserved.</p>
            <p class="footer-version">Version 1.0</p>
        </div>
    </div>
</footer>

</body>
</html>