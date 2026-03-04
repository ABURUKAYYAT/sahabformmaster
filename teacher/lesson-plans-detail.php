<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['principal', 'teacher'], true)) {
    header('Location: ../index.php');
    exit;
}

$current_school_id = require_school_auth();
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'teacher';
$user_name = $_SESSION['full_name'] ?? 'User';
$is_teacher = $user_role === 'teacher';
$is_principal = $user_role === 'principal';
$return_path = $is_teacher ? 'lesson-plan.php' : '../admin/lesson-plans.php';
$errors = [];

function detail_status_meta($status)
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

function detail_approval_meta($status)
{
    $normalized = $status ?: 'pending';
    $map = [
        'approved' => ['label' => 'Approved', 'icon' => 'fa-circle-check', 'class' => 'approval-approved'],
        'rejected' => ['label' => 'Rejected', 'icon' => 'fa-circle-xmark', 'class' => 'approval-rejected'],
        'pending' => ['label' => 'Pending Review', 'icon' => 'fa-clock', 'class' => 'approval-pending'],
    ];

    return $map[$normalized] ?? ['label' => ucfirst($normalized), 'icon' => 'fa-clock', 'class' => 'approval-pending'];
}

$plan_id = (int) ($_GET['id'] ?? 0);
if ($plan_id <= 0) {
    header('Location: ' . $return_path);
    exit;
}

$stmt = $pdo->prepare('SELECT lp.*, s.subject_name, c.class_name, u.full_name AS teacher_name, u2.full_name AS approved_by_name FROM lesson_plans lp JOIN subjects s ON lp.subject_id = s.id JOIN classes c ON lp.class_id = c.id JOIN users u ON lp.teacher_id = u.id LEFT JOIN users u2 ON lp.approved_by = u2.id WHERE lp.id = :id AND s.school_id = :school_id AND c.school_id = :school_id2');
$stmt->execute(['id' => $plan_id, 'school_id' => $current_school_id, 'school_id2' => $current_school_id]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan || ($is_teacher && (int) $plan['teacher_id'] !== $user_id)) {
    header('Location: ' . $return_path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_comment') {
        $comment = trim($_POST['comment'] ?? '');
        if ($comment === '') {
            $errors[] = 'Comment cannot be empty.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO lesson_plan_feedback (lesson_plan_id, user_id, comment) VALUES (:lesson_plan_id, :user_id, :comment)');
            $stmt->execute(['lesson_plan_id' => $plan_id, 'user_id' => $user_id, 'comment' => $comment]);
            $_SESSION['success'] = 'Comment added successfully.';
            header('Location: lesson-plans-detail.php?id=' . $plan_id);
            exit;
        }
    }

    if ($action === 'delete_comment' && $is_principal) {
        $comment_id = (int) ($_POST['comment_id'] ?? 0);
        if ($comment_id > 0) {
            $pdo->prepare('DELETE FROM lesson_plan_feedback WHERE id = :id AND lesson_plan_id = :plan_id')->execute(['id' => $comment_id, 'plan_id' => $plan_id]);
            $_SESSION['success'] = 'Comment deleted.';
            header('Location: lesson-plans-detail.php?id=' . $plan_id);
            exit;
        }
    }
}

$feedback_stmt = $pdo->prepare('SELECT lpf.*, u.full_name FROM lesson_plan_feedback lpf JOIN users u ON lpf.user_id = u.id WHERE lpf.lesson_plan_id = :plan_id ORDER BY lpf.created_at DESC');
$feedback_stmt->execute(['plan_id' => $plan_id]);
$feedback = $feedback_stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = null;
if (isset($_SESSION['success'])) {
    $flash = ['type' => 'success', 'message' => $_SESSION['success']];
    unset($_SESSION['success']);
}

$status_meta = detail_status_meta($plan['status'] ?? 'draft');
$approval_meta = detail_approval_meta($plan['approval_status'] ?? 'pending');
$can_edit = $is_teacher && (int) $plan['teacher_id'] === $user_id && in_array($plan['status'], ['draft', 'on_hold'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lesson Plan Details | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/css/teacher-lesson-plans.css?v=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="landing teacher-shell-page lesson-plan-detail-page">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <?php if ($is_teacher): ?>
                    <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu">
                        <span></span><span></span><span></span>
                    </button>
                <?php endif; ?>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="w-10 h-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500"><?php echo $is_teacher ? 'Teacher Portal' : 'School Review'; ?></p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="hidden md:block text-sm text-slate-600">Welcome, <?php echo htmlspecialchars($user_name); ?></span>
                <a class="btn btn-outline" href="<?php echo htmlspecialchars($return_path); ?>">Back</a>
                <a class="btn btn-primary" href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>
    </header>

    <?php if ($is_teacher): ?>
        <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>
    <?php endif; ?>

    <div class="container <?php echo $is_teacher ? 'grid gap-6 py-8 lg:grid-cols-[280px_1fr]' : 'py-8'; ?>">
        <?php if ($is_teacher): ?>
            <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 -translate-x-full transform border-r border-ink-900/10 bg-white shadow-lift transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
                <div class="sidebar-scroll-shell"><?php include '../includes/teacher_sidebar.php'; ?></div>
            </aside>
        <?php endif; ?>

        <main class="space-y-6">
            <section class="lesson-hero">
                <div class="lesson-hero-grid">
                    <div>
                        <p class="lesson-eyebrow">Lesson Plan Detail</p>
                        <h1 class="lesson-title"><?php echo htmlspecialchars($plan['topic']); ?></h1>
                        <p class="lesson-subtitle"><?php echo htmlspecialchars($plan['subject_name']); ?> for <?php echo htmlspecialchars($plan['class_name']); ?>. Review lesson structure, classroom intent, comments, and approval state in one place.</p>
                    </div>
                    <div class="lesson-hero-actions">
                        <a href="<?php echo htmlspecialchars($return_path); ?>" class="lesson-hero-action lesson-hero-action-primary"><i class="fas fa-arrow-left"></i><span>Back to Library</span></a>
                        <?php if ($can_edit): ?>
                            <a href="lesson-plan.php?edit=<?php echo (int) $plan['id']; ?>" class="lesson-hero-action lesson-hero-action-secondary"><i class="fas fa-edit"></i><span>Edit This Plan</span></a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
            <?php if ($flash): ?>
                <div class="lesson-alert lesson-alert-success"><i class="fas fa-circle-check"></i><span><?php echo htmlspecialchars($flash['message']); ?></span></div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="lesson-alert lesson-alert-error"><i class="fas fa-circle-exclamation"></i><span><?php echo htmlspecialchars(implode(' ', $errors)); ?></span></div>
            <?php endif; ?>

            <?php if (($plan['approval_status'] ?? 'pending') === 'rejected'): ?>
                <div class="lesson-alert lesson-alert-warning"><i class="fas fa-triangle-exclamation"></i><span>This lesson plan was rejected. Review the remarks and comments below before editing.</span></div>
            <?php endif; ?>
            <section class="lesson-detail-layout">
                <div class="lesson-detail-stack">
                    <article class="lesson-detail-card">
                        <div class="lesson-detail-card-header">
                            <p class="lesson-detail-kicker">Overview</p>
                            <h2>Lesson summary and status</h2>
                            <p>Core instructional details for this plan, including timing, ownership, and approval position.</p>
                        </div>
                        <div class="lesson-detail-card-body">
                            <div class="lesson-pill-row" style="margin-bottom: 1rem;">
                                <span class="lesson-pill <?php echo $status_meta['class']; ?>"><i class="fas <?php echo $status_meta['icon']; ?>"></i><?php echo htmlspecialchars($status_meta['label']); ?></span>
                                <span class="lesson-pill <?php echo $approval_meta['class']; ?>"><i class="fas <?php echo $approval_meta['icon']; ?>"></i><?php echo htmlspecialchars($approval_meta['label']); ?></span>
                            </div>
                            <div class="lesson-overview-grid">
                                <div class="lesson-overview-item"><strong>Teacher</strong><span><?php echo htmlspecialchars($plan['teacher_name']); ?></span></div>
                                <div class="lesson-overview-item"><strong>Planned Date</strong><span><?php echo htmlspecialchars(date('M d, Y', strtotime($plan['date_planned']))); ?></span></div>
                                <div class="lesson-overview-item"><strong>Duration</strong><span><?php echo (int) $plan['duration']; ?> minutes</span></div>
                                <div class="lesson-overview-item"><strong>Assessment Method</strong><span><?php echo htmlspecialchars($plan['assessment_method']); ?></span></div>
                                <div class="lesson-overview-item"><strong>Created</strong><span><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($plan['created_at']))); ?></span></div>
                                <?php if (!empty($plan['approved_by_name'])): ?><div class="lesson-overview-item"><strong>Reviewed By</strong><span><?php echo htmlspecialchars($plan['approved_by_name']); ?></span></div><?php endif; ?>
                            </div>
                        </div>
                    </article>

                    <article class="lesson-detail-card">
                        <div class="lesson-detail-card-header"><p class="lesson-detail-kicker">Learning Objectives</p><h3>What students should achieve</h3></div>
                        <div class="lesson-detail-card-body"><div class="lesson-copy-block"><?php echo nl2br(htmlspecialchars($plan['learning_objectives'])); ?></div></div>
                    </article>

                    <?php if (!empty($plan['lesson_content'])): ?>
                        <article class="lesson-detail-card">
                            <div class="lesson-detail-card-header"><p class="lesson-detail-kicker">Lesson Content</p><h3>Instruction flow and classroom delivery</h3></div>
                            <div class="lesson-detail-card-body"><div class="lesson-copy-block"><?php echo nl2br(htmlspecialchars($plan['lesson_content'])); ?></div></div>
                        </article>
                    <?php endif; ?>

                    <article class="lesson-detail-card">
                        <div class="lesson-detail-card-header"><p class="lesson-detail-kicker">Assessment</p><h3>Checks for understanding</h3></div>
                        <div class="lesson-detail-card-body">
                            <div class="lesson-overview-item"><strong>Assessment Method</strong><span><?php echo htmlspecialchars($plan['assessment_method']); ?></span></div>
                            <?php if (!empty($plan['assessment_tasks'])): ?><div class="lesson-copy-block" style="margin-top: 1rem;"><?php echo nl2br(htmlspecialchars($plan['assessment_tasks'])); ?></div><?php endif; ?>
                        </div>
                    </article>

                    <?php if (!empty($plan['principal_remarks'])): ?>
                        <article class="lesson-detail-card">
                            <div class="lesson-detail-card-header"><p class="lesson-detail-kicker">Principal Remarks</p><h3>Reviewer notes</h3></div>
                            <div class="lesson-detail-card-body"><div class="lesson-copy-block"><?php echo nl2br(htmlspecialchars($plan['principal_remarks'])); ?></div></div>
                        </article>
                    <?php endif; ?>
                </div>

                <div class="lesson-detail-stack">
                    <?php if (!empty($plan['teaching_methods'])): ?><article class="lesson-detail-card"><div class="lesson-detail-card-header"><p class="lesson-detail-kicker">Teaching Methods</p><h3>Instructional approach</h3></div><div class="lesson-detail-card-body"><div class="lesson-copy-block"><?php echo nl2br(htmlspecialchars($plan['teaching_methods'])); ?></div></div></article><?php endif; ?>
                    <?php if (!empty($plan['resources'])): ?><article class="lesson-detail-card"><div class="lesson-detail-card-header"><p class="lesson-detail-kicker">Resources</p><h3>Materials and tools</h3></div><div class="lesson-detail-card-body"><div class="lesson-copy-block"><?php echo nl2br(htmlspecialchars($plan['resources'])); ?></div></div></article><?php endif; ?>
                    <?php if (!empty($plan['differentiation'])): ?><article class="lesson-detail-card"><div class="lesson-detail-card-header"><p class="lesson-detail-kicker">Differentiation</p><h3>Support and extension</h3></div><div class="lesson-detail-card-body"><div class="lesson-copy-block"><?php echo nl2br(htmlspecialchars($plan['differentiation'])); ?></div></div></article><?php endif; ?>
                    <?php if (!empty($plan['homework'])): ?><article class="lesson-detail-card"><div class="lesson-detail-card-header"><p class="lesson-detail-kicker">Homework</p><h3>Follow-up task</h3></div><div class="lesson-detail-card-body"><div class="lesson-copy-block"><?php echo nl2br(htmlspecialchars($plan['homework'])); ?></div></div></article><?php endif; ?>

                    <article class="lesson-detail-card">
                        <div class="lesson-detail-card-header"><p class="lesson-detail-kicker">Feedback</p><h3>Comments and review trail</h3></div>
                        <div class="lesson-detail-card-body">
                            <form method="POST" class="lesson-comment-form">
                                <input type="hidden" name="action" value="add_comment">
                                <label class="lesson-field"><span><i class="fas fa-comment"></i>Add a Comment</span><textarea name="comment" class="lesson-textarea" rows="4" placeholder="Share review notes, teaching reflections, or follow-up actions." required></textarea></label>
                                <button type="submit" class="lesson-button"><i class="fas fa-paper-plane"></i><span>Post Comment</span></button>
                            </form>

                            <div class="lesson-feedback-list" style="margin-top: 1rem;">
                                <?php if (!$feedback): ?>
                                    <div class="lesson-empty-state"><i class="fas fa-comments"></i><div><h3>No comments yet</h3><p>Use the form above to start the review conversation.</p></div></div>
                                <?php else: ?>
                                    <?php foreach ($feedback as $item): ?>
                                        <article class="lesson-comment-item">
                                            <div class="lesson-comment-header">
                                                <div>
                                                    <div class="lesson-comment-author"><?php echo htmlspecialchars($item['full_name']); ?></div>
                                                    <div class="lesson-comment-meta"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($item['created_at']))); ?></div>
                                                </div>
                                                <?php if ($is_principal): ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="action" value="delete_comment">
                                                        <input type="hidden" name="comment_id" value="<?php echo (int) $item['id']; ?>">
                                                        <button type="submit" class="lesson-button-danger" onclick="return confirm('Delete this comment?');"><i class="fas fa-trash"></i><span>Delete</span></button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                            <div class="lesson-comment-body"><?php echo nl2br(htmlspecialchars($item['comment'])); ?></div>
                                        </article>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
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
    </script>
</body>
</html>
