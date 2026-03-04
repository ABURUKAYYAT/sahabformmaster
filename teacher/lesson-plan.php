<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: ../index.php');
    exit();
}

$current_school_id = require_school_auth();
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['full_name'] ?? 'Teacher';
$errors = [];
$action = '';

function lesson_plan_status_meta($status)
{
    $map = [
        'draft' => ['label' => 'Draft', 'icon' => 'fa-pen', 'class' => 'status-draft'],
        'submitted' => ['label' => 'Submitted', 'icon' => 'fa-paper-plane', 'class' => 'status-submitted'],
        'scheduled' => ['label' => 'Scheduled', 'icon' => 'fa-calendar-check', 'class' => 'status-scheduled'],
        'completed' => ['label' => 'Completed', 'icon' => 'fa-circle-check', 'class' => 'status-completed'],
        'on_hold' => ['label' => 'On Hold', 'icon' => 'fa-pause-circle', 'class' => 'status-on_hold'],
        'cancelled' => ['label' => 'Cancelled', 'icon' => 'fa-ban', 'class' => 'status-cancelled'],
    ];

    return $map[$status] ?? ['label' => ucfirst((string) $status), 'icon' => 'fa-circle', 'class' => 'status-draft'];
}

function lesson_plan_approval_meta($status)
{
    $normalized = $status ?: 'pending';
    $map = [
        'approved' => ['label' => 'Approved', 'icon' => 'fa-circle-check', 'class' => 'approval-approved'],
        'rejected' => ['label' => 'Rejected', 'icon' => 'fa-circle-xmark', 'class' => 'approval-rejected'],
        'pending' => ['label' => 'Pending Review', 'icon' => 'fa-clock', 'class' => 'approval-pending'],
    ];

    return $map[$normalized] ?? ['label' => ucfirst($normalized), 'icon' => 'fa-clock', 'class' => 'approval-pending'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $class_id = (int) ($_POST['class_id'] ?? 0);
    $topic = trim($_POST['topic'] ?? '');
    $duration = (int) ($_POST['duration'] ?? 0);
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

    if (!in_array($status, ['draft', 'scheduled', 'on_hold'], true)) $status = 'draft';
    if ($subject_id <= 0) $errors[] = 'Subject is required.';
    if ($class_id <= 0) $errors[] = 'Class is required.';
    if ($topic === '') $errors[] = 'Topic is required.';
    if ($duration <= 0) $errors[] = 'Duration must be a positive number.';
    if ($learning_objectives === '') $errors[] = 'Learning objectives are required.';
    if ($assessment_method === '') $errors[] = 'Assessment method is required.';
    if ($date_planned === '' || !strtotime($date_planned)) $errors[] = 'Valid planned date is required.';

    if (!$errors && in_array($action, ['add', 'edit'], true)) {
        $params = [
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
            'school_id' => $current_school_id,
        ];

        if ($action === 'add') {
            $check = $pdo->prepare('SELECT COUNT(*) FROM lesson_plans WHERE teacher_id = :teacher_id AND class_id = :class_id AND topic = :topic AND DATE(date_planned) = :date_planned AND school_id = :school_id');
            $check->execute([
                'teacher_id' => $user_id,
                'class_id' => $class_id,
                'topic' => $topic,
                'date_planned' => $date_planned,
                'school_id' => $current_school_id,
            ]);

            if ((int) $check->fetchColumn() > 0) {
                $errors[] = 'You already created a lesson plan for this topic on the selected date.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO lesson_plans (school_id, subject_id, class_id, teacher_id, topic, duration, learning_objectives, teaching_methods, resources, lesson_content, assessment_method, assessment_tasks, differentiation, homework, date_planned, status, approval_status, created_at) VALUES (:school_id, :subject_id, :class_id, :teacher_id, :topic, :duration, :learning_objectives, :teaching_methods, :resources, :lesson_content, :assessment_method, :assessment_tasks, :differentiation, :homework, :date_planned, :status, :approval_status, NOW())');
                $stmt->execute($params + ['teacher_id' => $user_id, 'approval_status' => 'pending']);
                $_SESSION['success'] = 'Lesson plan created successfully.';
                header('Location: lesson-plan.php');
                exit;
            }
        }

        if ($action === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);
            $check = $pdo->prepare('SELECT teacher_id, status FROM lesson_plans WHERE id = :id AND school_id = :school_id');
            $check->execute(['id' => $id, 'school_id' => $current_school_id]);
            $plan = $check->fetch(PDO::FETCH_ASSOC);

            if ($id <= 0 || !$plan || (int) $plan['teacher_id'] !== $user_id) {
                $errors[] = 'Lesson plan not found or access denied.';
            } elseif (!in_array($plan['status'], ['draft', 'on_hold'], true)) {
                $errors[] = 'Only draft or on-hold lesson plans can be edited.';
            } else {
                $stmt = $pdo->prepare('UPDATE lesson_plans SET subject_id = :subject_id, class_id = :class_id, topic = :topic, duration = :duration, learning_objectives = :learning_objectives, teaching_methods = :teaching_methods, resources = :resources, lesson_content = :lesson_content, assessment_method = :assessment_method, assessment_tasks = :assessment_tasks, differentiation = :differentiation, homework = :homework, date_planned = :date_planned, status = :status WHERE id = :id AND school_id = :school_id');
                $stmt->execute($params + ['id' => $id]);
                $_SESSION['success'] = 'Lesson plan updated successfully.';
                header('Location: lesson-plan.php');
                exit;
            }
        }
    }

    if (!$errors && in_array($action, ['delete', 'submit_for_approval'], true)) {
        $id = (int) ($_POST['id'] ?? 0);
        $check = $pdo->prepare('SELECT teacher_id, status FROM lesson_plans WHERE id = :id AND school_id = :school_id');
        $check->execute(['id' => $id, 'school_id' => $current_school_id]);
        $plan = $check->fetch(PDO::FETCH_ASSOC);

        if ($id <= 0 || !$plan || (int) $plan['teacher_id'] !== $user_id) {
            $errors[] = 'Lesson plan not found or access denied.';
        } elseif (!in_array($plan['status'], ['draft', 'on_hold'], true)) {
            $errors[] = $action === 'delete' ? 'Only draft or on-hold lesson plans can be deleted.' : 'Only draft or on-hold plans can be submitted for approval.';
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM lesson_plan_feedback WHERE lesson_plan_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM lesson_plan_attachments WHERE lesson_plan_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM lesson_plans WHERE id = :id AND school_id = :school_id')->execute(['id' => $id, 'school_id' => $current_school_id]);
            $_SESSION['success'] = 'Lesson plan deleted successfully.';
            header('Location: lesson-plan.php');
            exit;
        } else {
            $pdo->prepare("UPDATE lesson_plans SET approval_status = 'pending', status = 'submitted' WHERE id = :id AND school_id = :school_id")->execute(['id' => $id, 'school_id' => $current_school_id]);
            $_SESSION['success'] = 'Lesson plan submitted for principal review.';
            header('Location: lesson-plan.php');
            exit;
        }
    }
}
$subjects = get_school_subjects($pdo, $current_school_id);
$classes = get_school_classes($pdo, $current_school_id);

if (isset($_GET['seed_samples']) && $_GET['seed_samples'] === '1') {
    if (!empty($subjects) && !empty($classes)) {
        $insert = $pdo->prepare('INSERT INTO lesson_plans (school_id, subject_id, class_id, teacher_id, topic, duration, learning_objectives, teaching_methods, resources, lesson_content, assessment_method, assessment_tasks, differentiation, homework, date_planned, status, approval_status, created_at) VALUES (:school_id, :subject_id, :class_id, :teacher_id, :topic, :duration, :learning_objectives, :teaching_methods, :resources, :lesson_content, :assessment_method, :assessment_tasks, :differentiation, :homework, :date_planned, :status, :approval_status, NOW())');
        foreach (['Introduction to the Topic', 'Key Concepts and Terms', 'Guided Practice Session', 'Real-world Applications', 'Summary and Review'] as $index => $seed_topic) {
            $insert->execute([
                'school_id' => $current_school_id,
                'subject_id' => (int) $subjects[0]['id'],
                'class_id' => (int) $classes[0]['id'],
                'teacher_id' => $user_id,
                'topic' => $seed_topic,
                'duration' => 45,
                'learning_objectives' => 'Understand the lesson objectives and key takeaways.',
                'teaching_methods' => 'Discussion, demonstration, and guided practice.',
                'resources' => 'Whiteboard, textbook, and slides.',
                'lesson_content' => 'Introduce the topic, explain key ideas, and provide examples.',
                'assessment_method' => 'Quiz',
                'assessment_tasks' => 'Complete classwork and answer review questions.',
                'differentiation' => 'Provide support materials and extension tasks.',
                'homework' => 'Revise notes and complete assigned exercises.',
                'date_planned' => date('Y-m-d', strtotime('+' . $index . ' day')),
                'status' => 'draft',
                'approval_status' => 'pending',
            ]);
        }
        $_SESSION['success'] = 'Sample lesson plans inserted successfully.';
    } else {
        $_SESSION['error'] = 'No subjects or classes found for your school.';
    }
    header('Location: lesson-plan.php');
    exit;
}

$stmt = $pdo->prepare('SELECT lp.*, s.subject_name, c.class_name, u.full_name AS approved_by_name FROM lesson_plans lp JOIN subjects s ON lp.subject_id = s.id JOIN classes c ON lp.class_id = c.id LEFT JOIN users u ON lp.approved_by = u.id WHERE lp.teacher_id = :teacher_id AND lp.school_id = :school_id_lp AND s.school_id = :school_id_s AND c.school_id = :school_id_c ORDER BY lp.date_planned DESC, lp.created_at DESC');
$stmt->execute([
    'teacher_id' => $user_id,
    'school_id_lp' => $current_school_id,
    'school_id_s' => $current_school_id,
    'school_id_c' => $current_school_id,
]);
$lesson_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$edit_plan = null;
if (isset($_GET['edit'])) {
    $edit_id = (int) ($_GET['edit'] ?? 0);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM lesson_plans WHERE id = :id AND teacher_id = :teacher_id AND school_id = :school_id');
        $stmt->execute(['id' => $edit_id, 'teacher_id' => $user_id, 'school_id' => $current_school_id]);
        $edit_plan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($edit_plan && !in_array($edit_plan['status'], ['draft', 'on_hold'], true)) {
            $errors[] = 'Only draft or on-hold lesson plans can be edited.';
            $edit_plan = null;
        }
    }
}

$flash = null;
if (isset($_SESSION['success'])) {
    $flash = ['type' => 'success', 'message' => $_SESSION['success']];
    unset($_SESSION['success']);
} elseif (isset($_SESSION['error'])) {
    $flash = ['type' => 'error', 'message' => $_SESSION['error']];
    unset($_SESSION['error']);
}

$form_values = [
    'subject_id' => (int) ($edit_plan['subject_id'] ?? 0),
    'class_id' => (int) ($edit_plan['class_id'] ?? 0),
    'topic' => $edit_plan['topic'] ?? '',
    'duration' => (int) ($edit_plan['duration'] ?? 40),
    'date_planned' => $edit_plan['date_planned'] ?? date('Y-m-d'),
    'assessment_method' => $edit_plan['assessment_method'] ?? '',
    'status' => $edit_plan['status'] ?? 'draft',
    'learning_objectives' => $edit_plan['learning_objectives'] ?? '',
    'teaching_methods' => $edit_plan['teaching_methods'] ?? '',
    'resources' => $edit_plan['resources'] ?? '',
    'lesson_content' => $edit_plan['lesson_content'] ?? '',
    'assessment_tasks' => $edit_plan['assessment_tasks'] ?? '',
    'differentiation' => $edit_plan['differentiation'] ?? '',
    'homework' => $edit_plan['homework'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'], true)) {
    foreach (array_keys($form_values) as $key) {
        if (isset($_POST[$key])) {
            $form_values[$key] = $_POST[$key];
        }
    }
}

$total_count = count($lesson_plans);
$draft_count = 0;
$pending_count = 0;
$approved_count = 0;
$upcoming_plan = null;
$today = strtotime(date('Y-m-d'));
foreach ($lesson_plans as $lesson_plan) {
    if (($lesson_plan['status'] ?? '') === 'draft') $draft_count++;
    if (($lesson_plan['approval_status'] ?? 'pending') === 'pending') $pending_count++;
    if (($lesson_plan['approval_status'] ?? 'pending') === 'approved') $approved_count++;
    $planned_at = strtotime((string) ($lesson_plan['date_planned'] ?? ''));
    if ($planned_at && $planned_at >= $today && ($upcoming_plan === null || $planned_at < strtotime((string) $upcoming_plan['date_planned']))) {
        $upcoming_plan = $lesson_plan;
    }
}

$plans_per_page = 10;
$current_page = max(1, (int) ($_GET['page'] ?? 1));
$total_pages = max(1, (int) ceil($total_count / $plans_per_page));
if ($current_page > $total_pages) {
    $current_page = $total_pages;
}
$display_lesson_plans = array_slice($lesson_plans, ($current_page - 1) * $plans_per_page, $plans_per_page);
$display_start = $total_count > 0 ? (($current_page - 1) * $plans_per_page) + 1 : 0;
$display_end = min($total_count, $current_page * $plans_per_page);

$can_create = !empty($subjects) && !empty($classes);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Lesson Plans | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/css/teacher-lesson-plans.css?v=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="landing teacher-shell-page lesson-plans-page">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="w-10 h-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Teacher Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="hidden md:block text-sm text-slate-600">Welcome, <?php echo htmlspecialchars($user_name); ?></span>
                <a class="btn btn-outline" href="index.php">Dashboard</a>
                <a class="btn btn-primary" href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 py-8 lg:grid-cols-[280px_1fr]">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 -translate-x-full transform border-r border-ink-900/10 bg-white shadow-lift transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell">
                <?php include '../includes/teacher_sidebar.php'; ?>
            </div>
        </aside>

        <main class="space-y-6">
            <section class="lesson-hero">
                <div class="lesson-hero-grid">
                    <div>
                        <p class="lesson-eyebrow">Instruction Planning</p>
                        <h1 class="lesson-title"><?php echo $edit_plan ? 'Refine a lesson plan with stronger structure and classroom-ready detail.' : 'Plan instruction with a polished workflow built for daily teaching.'; ?></h1>
                        <p class="lesson-subtitle"><?php echo $edit_plan ? 'Update objectives, pacing, resources, and assessment notes before the lesson returns to your active schedule.' : 'Create well-structured lesson plans that align objectives, methods, assessment, and differentiation in one professional workspace.'; ?></p>
                    </div>
                    <div class="lesson-hero-actions">
                        <a href="#plan-form" class="lesson-hero-action lesson-hero-action-primary"><i class="fas <?php echo $edit_plan ? 'fa-edit' : 'fa-plus-circle'; ?>"></i><span><?php echo $edit_plan ? 'Continue Editing' : 'Create Lesson Plan'; ?></span></a>
                        <a href="#lesson-plan-library" class="lesson-hero-action lesson-hero-action-secondary"><i class="fas fa-layer-group"></i><span>Review Plan Library</span></a>
                    </div>
                </div>

                <div class="lesson-metrics">
                    <article class="lesson-metric-card"><span class="lesson-metric-icon bg-teal-600/10 text-teal-700"><i class="fas fa-file-alt"></i></span><div><p class="lesson-metric-label">Total Plans</p><p class="lesson-metric-value"><?php echo $total_count; ?></p><p class="lesson-metric-note">Your planning archive</p></div></article>
                    <article class="lesson-metric-card"><span class="lesson-metric-icon bg-slate-500/10 text-slate-600"><i class="fas fa-pen-ruler"></i></span><div><p class="lesson-metric-label">Drafts</p><p class="lesson-metric-value"><?php echo $draft_count; ?></p><p class="lesson-metric-note">Still being refined</p></div></article>
                    <article class="lesson-metric-card"><span class="lesson-metric-icon bg-amber-500/10 text-amber-600"><i class="fas fa-hourglass-half"></i></span><div><p class="lesson-metric-label">Pending Review</p><p class="lesson-metric-value"><?php echo $pending_count; ?></p><p class="lesson-metric-note">Awaiting approval</p></div></article>
                    <article class="lesson-metric-card"><span class="lesson-metric-icon bg-emerald-600/10 text-emerald-700"><i class="fas fa-circle-check"></i></span><div><p class="lesson-metric-label">Approved</p><p class="lesson-metric-value"><?php echo $approved_count; ?></p><p class="lesson-metric-note">Ready for classroom use</p></div></article>
                </div>
            </section>
            <?php if ($flash): ?>
                <div class="lesson-alert <?php echo $flash['type'] === 'success' ? 'lesson-alert-success' : 'lesson-alert-error'; ?>">
                    <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <span><?php echo htmlspecialchars($flash['message']); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="lesson-alert lesson-alert-error">
                    <i class="fas fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars(implode(' ', $errors)); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$can_create): ?>
                <div class="lesson-alert lesson-alert-warning">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>You need at least one subject and one class in your school workspace before a lesson plan can be created.</span>
                </div>
            <?php endif; ?>
            <section class="lesson-content-grid">
                <article class="lesson-panel" id="plan-form">
                    <div class="lesson-panel-heading">
                        <div class="lesson-panel-heading-bar">
                            <div class="lesson-panel-title-row">
                                <span class="lesson-panel-icon"><i class="fas <?php echo $edit_plan ? 'fa-edit' : 'fa-plus-circle'; ?>"></i></span>
                                <div>
                                    <p class="lesson-panel-kicker"><?php echo $edit_plan ? 'Update Plan' : 'New Plan'; ?></p>
                                    <h2 class="lesson-panel-title"><?php echo $edit_plan ? 'Edit your lesson structure' : 'Build a classroom-ready lesson plan'; ?></h2>
                                    <p class="lesson-panel-copy">Capture objectives, delivery method, assessment, and learner support in a format suitable for school review.</p>
                                </div>
                            </div>
                            <button type="button" class="lesson-button-secondary lesson-form-toggle" id="toggleLessonPlanForm" aria-expanded="<?php echo $edit_plan ? 'true' : 'false'; ?>" aria-controls="lessonPlanFormShell">
                                <i class="fas <?php echo $edit_plan ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                <span><?php echo $edit_plan ? 'Hide Form' : 'Show Form'; ?></span>
                            </button>
                        </div>
                    </div>
                    <div class="lesson-panel-body lesson-form-shell <?php echo $edit_plan ? '' : 'is-collapsed'; ?>" id="lessonPlanFormShell">
                        <form method="POST" action="lesson-plan.php" class="lesson-form-grid">
                            <input type="hidden" name="action" value="<?php echo $edit_plan ? 'edit' : 'add'; ?>">
                            <?php if ($edit_plan): ?><input type="hidden" name="id" value="<?php echo (int) $edit_plan['id']; ?>"><?php endif; ?>

                            <label class="lesson-field"><span><i class="fas fa-book-open"></i>Subject</span><select id="subject_id" name="subject_id" class="lesson-select" required><option value="">Select subject</option><?php foreach ($subjects as $subject): ?><option value="<?php echo (int) $subject['id']; ?>" <?php echo (int) $subject['id'] === (int) $form_values['subject_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($subject['subject_name']); ?></option><?php endforeach; ?></select></label>
                            <label class="lesson-field"><span><i class="fas fa-users"></i>Class</span><select name="class_id" class="lesson-select" required><option value="">Select class</option><?php foreach ($classes as $class): ?><option value="<?php echo (int) $class['id']; ?>" <?php echo (int) $class['id'] === (int) $form_values['class_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option><?php endforeach; ?></select></label>
                            <label class="lesson-field"><span><i class="fas fa-lightbulb"></i>Topic or Unit</span><input id="topic" name="topic" class="lesson-input" value="<?php echo htmlspecialchars((string) $form_values['topic']); ?>" placeholder="e.g. Fractions as parts of a whole" required></label>
                            <label class="lesson-field"><span><i class="fas fa-clock"></i>Duration</span><input type="number" name="duration" class="lesson-input" min="1" value="<?php echo (int) $form_values['duration']; ?>" required></label>

                            <div class="lesson-form-section lesson-form-grid-four" style="grid-column: 1 / -1;">
                                <div>
                                    <h3 class="lesson-form-section-title">Scheduling and Review</h3>
                                    <p class="lesson-form-section-copy">Set timing, assessment mode, and plan state.</p>
                                </div>
                                <label class="lesson-field"><span><i class="fas fa-calendar-day"></i>Planned Date</span><input type="date" name="date_planned" class="lesson-input" value="<?php echo htmlspecialchars((string) $form_values['date_planned']); ?>" required></label>
                                <label class="lesson-field"><span><i class="fas fa-clipboard-check"></i>Assessment Method</span><select name="assessment_method" class="lesson-select" required><option value="">Select method</option><?php foreach (['Quiz', 'Assignment', 'Practical', 'Observation', 'Project'] as $method): ?><option value="<?php echo htmlspecialchars($method); ?>" <?php echo (string) $form_values['assessment_method'] === $method ? 'selected' : ''; ?>><?php echo htmlspecialchars($method); ?></option><?php endforeach; ?></select></label>
                                <label class="lesson-field"><span><i class="fas fa-layer-group"></i>Status</span><select name="status" class="lesson-select"><?php foreach (['draft' => 'Draft', 'scheduled' => 'Scheduled', 'on_hold' => 'On Hold'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo (string) $form_values['status'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></label>
                            </div>

                            <div class="lesson-form-section" style="grid-column: 1 / -1;">
                                <div>
                                    <h3 class="lesson-form-section-title">Learning Design</h3>
                                    <p class="lesson-form-section-copy">Write the lesson in the same professional structure used during supervision and review.</p>
                                </div>
                                <label class="lesson-field"><span><i class="fas fa-bullseye"></i>Learning Objectives</span><textarea name="learning_objectives" class="lesson-textarea" required><?php echo htmlspecialchars((string) $form_values['learning_objectives']); ?></textarea></label>
                                <label class="lesson-field"><span><i class="fas fa-chalkboard-teacher"></i>Teaching Methods</span><textarea name="teaching_methods" class="lesson-textarea"><?php echo htmlspecialchars((string) $form_values['teaching_methods']); ?></textarea></label>
                                <label class="lesson-field"><span><i class="fas fa-toolbox"></i>Resources and Materials</span><textarea name="resources" class="lesson-textarea"><?php echo htmlspecialchars((string) $form_values['resources']); ?></textarea></label>
                                <label class="lesson-field"><span><i class="fas fa-file-alt"></i>Lesson Content</span><textarea name="lesson_content" class="lesson-textarea"><?php echo htmlspecialchars((string) $form_values['lesson_content']); ?></textarea></label>
                                <label class="lesson-field"><span><i class="fas fa-list-check"></i>Assessment Tasks</span><textarea name="assessment_tasks" class="lesson-textarea"><?php echo htmlspecialchars((string) $form_values['assessment_tasks']); ?></textarea></label>
                                <label class="lesson-field"><span><i class="fas fa-users"></i>Differentiation Strategies</span><textarea name="differentiation" class="lesson-textarea"><?php echo htmlspecialchars((string) $form_values['differentiation']); ?></textarea></label>
                                <label class="lesson-field"><span><i class="fas fa-house"></i>Homework or Follow-up</span><textarea name="homework" class="lesson-textarea"><?php echo htmlspecialchars((string) $form_values['homework']); ?></textarea></label>
                            </div>

                            <div class="lesson-action-row" style="grid-column: 1 / -1;">
                                <button type="submit" class="lesson-button" <?php echo $can_create ? '' : 'disabled'; ?>><i class="fas <?php echo $edit_plan ? 'fa-floppy-disk' : 'fa-plus-circle'; ?>"></i><span><?php echo $edit_plan ? 'Save Changes' : 'Create Lesson Plan'; ?></span></button>
                                <?php if ($edit_plan): ?><a href="lesson-plan.php" class="lesson-button-secondary"><i class="fas fa-xmark"></i><span>Cancel Editing</span></a><?php endif; ?>
                                <a href="#lesson-plan-library" class="lesson-button-ghost"><i class="fas fa-layer-group"></i><span>View Existing Plans</span></a>
                            </div>
                        </form>
                    </div>
                </article>

                <aside class="lesson-panel">
                    <div class="lesson-panel-heading">
                        <div class="lesson-panel-title-row">
                            <span class="lesson-panel-icon"><i class="fas fa-graduation-cap"></i></span>
                            <div>
                                <p class="lesson-panel-kicker">Planning Standard</p>
                                <h2 class="lesson-panel-title">Keep every lesson review-ready</h2>
                                <p class="lesson-panel-copy">Use one consistent structure from planning to approval so your records remain clear and defensible.</p>
                            </div>
                        </div>
                    </div>
                    <div class="lesson-panel-body lesson-guidance-card">
                        <ul class="lesson-checklist">
                            <li><i class="fas fa-circle-check"></i><span>Write objectives as measurable learner outcomes.</span></li>
                            <li><i class="fas fa-circle-check"></i><span>Sequence the lesson from introduction to guided and independent practice.</span></li>
                            <li><i class="fas fa-circle-check"></i><span>Match resources and assessment to the exact learner level.</span></li>
                            <li><i class="fas fa-circle-check"></i><span>Include differentiation so support and extension are visible.</span></li>
                        </ul>

                        <div class="lesson-summary-grid">
                            <article class="lesson-summary-card"><p class="lesson-summary-label">Next Planned Lesson</p><p class="lesson-summary-value"><?php echo $upcoming_plan ? htmlspecialchars($upcoming_plan['topic']) : 'No upcoming lesson'; ?></p><p class="lesson-summary-note"><?php echo $upcoming_plan ? htmlspecialchars(date('M d, Y', strtotime($upcoming_plan['date_planned']))) : 'Add a future date to keep your schedule visible.'; ?></p></article>
                            <article class="lesson-summary-card"><p class="lesson-summary-label">Approved Plans</p><p class="lesson-summary-value"><?php echo $approved_count; ?></p><p class="lesson-summary-note">Plans already cleared through review.</p></article>
                        </div>

                        <?php if ($total_count === 0): ?>
                            <a href="lesson-plan.php?seed_samples=1" class="lesson-button-secondary"><i class="fas fa-magic"></i><span>Insert Sample Plans</span></a>
                        <?php endif; ?>
                    </div>
                </aside>
            </section>
            <section class="lesson-panel" id="lesson-plan-library">
                <div class="lesson-panel-heading">
                    <div class="lesson-panel-title-row">
                        <span class="lesson-panel-icon"><i class="fas fa-folder-open"></i></span>
                        <div>
                            <p class="lesson-panel-kicker">Plan Library</p>
                            <h2 class="lesson-panel-title">Manage your lesson plan records</h2>
                            <p class="lesson-panel-copy"><?php echo $total_count; ?> plan<?php echo $total_count === 1 ? '' : 's'; ?> currently linked to your account. Showing <?php echo $display_start; ?>-<?php echo $display_end; ?> on this page.</p>
                        </div>
                    </div>
                </div>

                <div class="lesson-panel-body">
                    <?php if ($total_count === 0): ?>
                        <div class="lesson-empty-state"><i class="fas fa-inbox"></i><div><h3>No lesson plans created yet</h3><p>Use the planning form above to create your first lesson plan.</p></div><a href="#plan-form" class="lesson-button">Create Your First Plan</a></div>
                    <?php else: ?>
                        <div class="lesson-table-toolbar">
                            <p class="lesson-inline-copy">Responsive table with internal horizontal and vertical scrolling for smaller screens.</p>
                            <p class="lesson-inline-copy">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></p>
                        </div>

                        <div class="lesson-table-shell lesson-table-scroll">
                            <table class="lesson-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Topic</th>
                                        <th>Subject</th>
                                        <th>Class</th>
                                        <th>Planned Date</th>
                                        <th>Duration</th>
                                        <th>Status</th>
                                        <th>Approval</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($display_lesson_plans as $lesson_plan): ?>
                                        <?php $status_meta = lesson_plan_status_meta($lesson_plan['status'] ?? 'draft'); $approval_meta = lesson_plan_approval_meta($lesson_plan['approval_status'] ?? 'pending'); $can_manage = in_array($lesson_plan['status'], ['draft', 'on_hold'], true); ?>
                                        <tr>
                                            <td><span class="lesson-table-id">#<?php echo (int) $lesson_plan['id']; ?></span></td>
                                            <td>
                                                <span class="lesson-table-topic"><?php echo htmlspecialchars($lesson_plan['topic']); ?></span>
                                                <span class="lesson-table-subcopy"><?php $objective_preview = (string) ($lesson_plan['learning_objectives'] ?? ''); echo htmlspecialchars(strlen($objective_preview) > 80 ? substr($objective_preview, 0, 77) . '...' : $objective_preview); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($lesson_plan['subject_name']); ?></td>
                                            <td><?php echo htmlspecialchars($lesson_plan['class_name']); ?></td>
                                            <td><?php echo htmlspecialchars(date('M d, Y', strtotime($lesson_plan['date_planned']))); ?></td>
                                            <td><?php echo (int) $lesson_plan['duration']; ?> min</td>
                                            <td><span class="lesson-pill <?php echo $status_meta['class']; ?>"><i class="fas <?php echo $status_meta['icon']; ?>"></i><?php echo htmlspecialchars($status_meta['label']); ?></span></td>
                                            <td><span class="lesson-pill <?php echo $approval_meta['class']; ?>"><i class="fas <?php echo $approval_meta['icon']; ?>"></i><?php echo htmlspecialchars($approval_meta['label']); ?></span></td>
                                            <td>
                                                <div class="lesson-table-actions">
                                                    <a class="lesson-button-secondary" href="lesson-plans-detail.php?id=<?php echo (int) $lesson_plan['id']; ?>"><i class="fas fa-eye"></i><span>View</span></a>
                                                    <?php if ($can_manage): ?>
                                                        <a class="lesson-button-ghost" href="lesson-plan.php?edit=<?php echo (int) $lesson_plan['id']; ?>&page=<?php echo $current_page; ?>"><i class="fas fa-edit"></i><span>Edit</span></a>
                                                        <form method="POST">
                                                            <input type="hidden" name="action" value="submit_for_approval">
                                                            <input type="hidden" name="id" value="<?php echo (int) $lesson_plan['id']; ?>">
                                                            <button type="submit" class="lesson-button"><i class="fas fa-paper-plane"></i><span>Submit</span></button>
                                                        </form>
                                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this lesson plan?');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo (int) $lesson_plan['id']; ?>">
                                                            <button type="submit" class="lesson-button-danger"><i class="fas fa-trash"></i><span>Delete</span></button>
                                                        </form>
                                                    <?php else: ?>
                                                        <button type="button" class="lesson-button-ghost" disabled><i class="fas fa-lock"></i><span>Locked</span></button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <nav class="lesson-pagination" aria-label="Lesson plan pagination">
                                <a class="lesson-pagination-link <?php echo $current_page <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo $current_page <= 1 ? '#' : 'lesson-plan.php?page=' . ($current_page - 1) . '#lesson-plan-library'; ?>" <?php echo $current_page <= 1 ? 'aria-disabled="true"' : ''; ?>>Previous</a>
                                <?php for ($page = 1; $page <= $total_pages; $page++): ?>
                                    <a class="lesson-pagination-link <?php echo $page === $current_page ? 'is-active' : ''; ?>" href="lesson-plan.php?page=<?php echo $page; ?>#lesson-plan-library"><?php echo $page; ?></a>
                                <?php endfor; ?>
                                <a class="lesson-pagination-link <?php echo $current_page >= $total_pages ? 'is-disabled' : ''; ?>" href="<?php echo $current_page >= $total_pages ? '#' : 'lesson-plan.php?page=' . ($current_page + 1) . '#lesson-plan-library'; ?>" <?php echo $current_page >= $total_pages ? 'aria-disabled="true"' : ''; ?>>Next</a>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <?php include '../includes/floating-button.php'; ?>

    <script>
        const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
        const sidebar = document.querySelector('[data-sidebar]');
        const overlay = document.querySelector('[data-sidebar-overlay]');
        const body = document.body;
        const formToggle = document.getElementById('toggleLessonPlanForm');
        const formShell = document.getElementById('lessonPlanFormShell');
        const closeSidebar = () => {
            if (!sidebar || !overlay) return;
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('opacity-0', 'pointer-events-none');
            overlay.classList.remove('opacity-100');
            body.classList.remove('nav-open');
        };
        if (sidebarToggle && sidebar && overlay) {
            sidebarToggle.addEventListener('click', () => {
                const closed = sidebar.classList.contains('-translate-x-full');
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('opacity-0', !closed);
                overlay.classList.toggle('pointer-events-none', !closed);
                overlay.classList.toggle('opacity-100', closed);
                body.classList.toggle('nav-open', closed);
            });
            overlay.addEventListener('click', closeSidebar);
            sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeSidebar));
        }

        if (formToggle && formShell) {
            const syncFormToggle = () => {
                const expanded = !formShell.classList.contains('is-collapsed');
                formToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                formToggle.querySelector('i').className = expanded ? 'fas fa-eye-slash' : 'fas fa-eye';
                formToggle.querySelector('span').textContent = expanded ? 'Hide Form' : 'Show Form';
            };

            formToggle.addEventListener('click', () => {
                formShell.classList.toggle('is-collapsed');
                syncFormToggle();
            });

            syncFormToggle();
        }
    </script>
</body>
</html>
