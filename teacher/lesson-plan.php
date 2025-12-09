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

// Handle teacher actions (add, edit, delete, submit_for_approval)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Common inputs
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
            // prevent duplicate for same teacher/class/topic/date
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
                $success = 'Lesson plan created.';
                header("Location: lesson-plan.php");
                exit;
            }
        }

        if ($action === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'Invalid lesson plan ID.';
            } else {
                // ensure teacher owns it and is editable (only draft allowed to edit)
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
                    $success = 'Lesson plan updated.';
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
                // Only teacher owner can delete; disallow deleting completed plans
                $stmt = $pdo->prepare("SELECT teacher_id, status FROM lesson_plans WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan || $plan['teacher_id'] != $user_id) {
                    $errors[] = 'Not found or access denied.';
                } elseif ($plan['status'] === 'completed') {
                    $errors[] = 'Completed lesson plans cannot be deleted.';
                } else {
                    // remove associated attachments/feedback (if any)
                    $pdo->prepare("DELETE FROM lesson_plan_feedback WHERE lesson_plan_id = :id")->execute(['id' => $id]);
                    $pdo->prepare("DELETE FROM lesson_plan_attachments WHERE lesson_plan_id = :id")->execute(['id' => $id]);
                    $pdo->prepare("DELETE FROM lesson_plans WHERE id = :id")->execute(['id' => $id]);
                    $success = 'Lesson plan deleted.';
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
                // ensure owner and status is draft
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
                    $success = 'Lesson plan submitted for principal review.';
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

// Fetch teacher's lesson plans only
$stmt = $pdo->prepare("SELECT lp.*, s.subject_name, c.class_name FROM lesson_plans lp JOIN subjects s ON lp.subject_id = s.id JOIN classes c ON lp.class_id = c.id WHERE lp.teacher_id = :teacher_id ORDER BY lp.date_planned DESC, lp.created_at DESC");
$stmt->execute(['teacher_id' => $user_id]);
$lesson_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If editing, fetch single plan ensuring ownership
$edit_plan = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM lesson_plans WHERE id = :id AND teacher_id = :teacher_id");
        $stmt->execute(['id' => $edit_id, 'teacher_id' => $user_id]);
        $edit_plan = $stmt->fetch(PDO::FETCH_ASSOC);
        // Only allow editing drafts
        if ($edit_plan && $edit_plan['status'] !== 'draft') $edit_plan = null;
    }
}

// Helper for status badges (keeps UI consistent with admin)
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
    <link rel="stylesheet" href="../assets/css/lesson-plan.css">
</head>
<body>
<header class="dashboard-header">
    <div class="header-container">
        <div class="header-right">
            <div class="school-logo-container">
                <img src="../assets/images/logo.png" alt="School Logo" class="school-logo">
                <h1 class="school-name">SahabFormMaster</h1>
            </div>
        </div>
        <div class="header-left">
            <div class="teacher-info">
                <span class="teacher-name"><?php echo htmlspecialchars($user_name); ?></span>
                <span class="teacher-role">Teacher</span>
            </div>
            <a href="../index.php" class="btn-logout">Logout</a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <main class="main-content">
        <div class="content-header">
            <h2>My Lesson Plans</h2>
            <p class="small-muted">Create, edit (drafts) and submit lesson plans for approval.</p>
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

        <!-- Form -->
        <section class="lesson-section">
            <div class="lesson-card">
                <h3><?php echo $edit_plan ? 'Edit Lesson Plan' : 'Create Lesson Plan'; ?></h3>

                <form method="POST" class="lesson-form" action="lesson-plan.php">
                    <input type="hidden" name="action" value="<?php echo $edit_plan ? 'edit' : 'add'; ?>">
                    <?php if ($edit_plan): ?>
                        <input type="hidden" name="id" value="<?php echo intval($edit_plan['id']); ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-col">
                            <label for="subject_id">Subject *</label>
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

                        <div class="form-col">
                            <label for="class_id">Class *</label>
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

                        <div class="form-col">
                            <label for="topic">Topic *</label>
                            <input id="topic" name="topic" class="form-control" value="<?php echo htmlspecialchars($edit_plan['topic'] ?? ''); ?>" required>
                        </div>

                        <div class="form-col">
                            <label for="duration">Duration (minutes) *</label>
                            <input type="number" id="duration" name="duration" class="form-control" min="1" value="<?php echo intval($edit_plan['duration'] ?? 45); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <label for="date_planned">Planned Date *</label>
                            <input type="date" id="date_planned" name="date_planned" class="form-control" value="<?php echo htmlspecialchars($edit_plan['date_planned'] ?? ''); ?>" required>
                        </div>

                        <div class="form-col">
                            <label for="assessment_method">Assessment Method *</label>
                            <select id="assessment_method" name="assessment_method" class="form-control" required>
                                <?php $sel_assess = $edit_plan['assessment_method'] ?? ''; ?>
                                <option value="">Select</option>
                                <option value="Quiz" <?php echo $sel_assess === 'Quiz' ? 'selected' : ''; ?>>Quiz</option>
                                <option value="Assignment" <?php echo $sel_assess === 'Assignment' ? 'selected' : ''; ?>>Assignment</option>
                                <option value="Practical" <?php echo $sel_assess === 'Practical' ? 'selected' : ''; ?>>Practical</option>
                                <option value="Observation" <?php echo $sel_assess === 'Observation' ? 'selected' : ''; ?>>Observation</option>
                                <option value="Project" <?php echo $sel_assess === 'Project' ? 'selected' : ''; ?>>Project</option>
                            </select>
                        </div>

                        <div class="form-col">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="form-control">
                                <?php $sel_status = $edit_plan['status'] ?? 'draft'; ?>
                                <option value="draft" <?php echo $sel_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="scheduled" <?php echo $sel_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="learning_objectives">Learning Objectives *</label>
                        <textarea id="learning_objectives" name="learning_objectives" class="form-control" rows="3" required><?php echo htmlspecialchars($edit_plan['learning_objectives'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="teaching_methods">Teaching Methods</label>
                        <textarea id="teaching_methods" name="teaching_methods" class="form-control" rows="2"><?php echo htmlspecialchars($edit_plan['teaching_methods'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="resources">Resources</label>
                        <textarea id="resources" name="resources" class="form-control" rows="2"><?php echo htmlspecialchars($edit_plan['resources'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="lesson_content">Lesson Content</label>
                        <textarea id="lesson_content" name="lesson_content" class="form-control" rows="4"><?php echo htmlspecialchars($edit_plan['lesson_content'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-actions">
                        <?php if ($edit_plan): ?>
                            <button type="submit" class="btn-gold">Save Changes</button>
                            <a href="lesson-plan.php" class="btn-secondary">Cancel</a>
                        <?php else: ?>
                            <button type="submit" class="btn-gold">Create Plan</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <!-- My Plans Table -->
        <section class="lesson-section">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Subject</th>
                            <th>Topic</th>
                            <th>Class</th>
                            <th>Planned Date</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Approval</th>
                            <th style="width:200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($lesson_plans) === 0): ?>
                            <tr><td colspan="9" class="text-center small-muted">No lesson plans yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($lesson_plans as $lp): ?>
                                <tr>
                                    <td><?php echo intval($lp['id']); ?></td>
                                    <td><?php echo htmlspecialchars($lp['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($lp['topic']); ?></td>
                                    <td><?php echo htmlspecialchars($lp['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($lp['date_planned']); ?></td>
                                    <td><?php echo intval($lp['duration']); ?> min</td>
                                    <td><span class="badge <?php echo getStatusBadge($lp['status']); ?>"><?php echo htmlspecialchars(ucfirst($lp['status'])); ?></span></td>
                                    <td><span class="badge <?php echo $lp['approval_status'] === 'approved' ? 'badge-success' : ($lp['approval_status'] === 'rejected' ? 'badge-danger' : 'badge-warning'); ?>"><?php echo htmlspecialchars(ucfirst($lp['approval_status'] ?? 'pending')); ?></span></td>
                                    <td>
                                        <div class="manage-actions">
                                            <a class="btn-small btn-view" href="../admin/lesson-plans-detail.php?id=<?php echo intval($lp['id']); ?>" title="View">👁</a>

                                            <?php if ($lp['status'] === 'draft'): ?>
                                                <a class="btn-small btn-edit" href="lesson-plan.php?edit=<?php echo intval($lp['id']); ?>">Edit</a>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="submit_for_approval">
                                                    <input type="hidden" name="id" value="<?php echo intval($lp['id']); ?>">
                                                    <button type="submit" class="btn-small btn-submit">Submit</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($lp['status'] !== 'completed'): ?>
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
                <p>Lesson planning for teachers.</p>
            </div>
        </div>
    </div>
</footer>
</body>
</html>
