<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$current_school_id = get_current_school_id();

if (!isset($_SESSION['student_id'])) {
    header('Location: ../student_login.php');
    exit();
}

$student_id = (int) $_SESSION['student_id'];
$allowed_actions = ['dashboard', 'submitted', 'graded', 'view', 'submit', 'feedback'];
$action = isset($_GET['action']) ? trim((string) $_GET['action']) : 'dashboard';
if (!in_array($action, $allowed_actions, true)) {
    $action = 'dashboard';
}
$activity_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$activity_type_labels = [
    'classwork' => 'Classwork',
    'assignment' => 'Assignment',
    'quiz' => 'Quiz',
    'project' => 'Project',
    'homework' => 'Homework',
];

$get_activity_meta = static function (?string $activity_type) use ($activity_type_labels): array {
    $type = strtolower(trim((string) $activity_type));
    $meta = [
        'classwork' => ['label' => $activity_type_labels['classwork'], 'icon' => 'fa-clipboard-list', 'pill' => 'pill-sky'],
        'assignment' => ['label' => $activity_type_labels['assignment'], 'icon' => 'fa-list-check', 'pill' => 'pill-indigo'],
        'quiz' => ['label' => $activity_type_labels['quiz'], 'icon' => 'fa-brain', 'pill' => 'pill-amber'],
        'project' => ['label' => $activity_type_labels['project'], 'icon' => 'fa-diagram-project', 'pill' => 'pill-violet'],
        'homework' => ['label' => $activity_type_labels['homework'], 'icon' => 'fa-house-laptop', 'pill' => 'pill-emerald'],
    ];

    return $meta[$type] ?? [
        'label' => ucfirst(str_replace('_', ' ', $type !== '' ? $type : 'activity')),
        'icon' => 'fa-tasks',
        'pill' => 'pill-slate',
    ];
};

$get_submission_meta = static function (?string $status): array {
    $status_key = strtolower(trim((string) $status));
    $meta = [
        'graded' => ['label' => 'Graded', 'pill' => 'pill-emerald', 'icon' => 'fa-check-circle'],
        'submitted' => ['label' => 'Submitted', 'pill' => 'pill-sky', 'icon' => 'fa-paper-plane'],
        'late' => ['label' => 'Late', 'pill' => 'pill-amber', 'icon' => 'fa-clock'],
        'pending' => ['label' => 'Pending', 'pill' => 'pill-amber', 'icon' => 'fa-hourglass-half'],
    ];

    return $meta[$status_key] ?? ['label' => 'Not Submitted', 'pill' => 'pill-slate', 'icon' => 'fa-circle'];
};

$format_datetime = static function (?string $value, string $format = 'M d, Y h:i A'): string {
    if (empty($value)) {
        return 'Not set';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date($format, $timestamp) : 'Not set';
};

$is_overdue = static function (?string $value): bool {
    if (empty($value)) {
        return false;
    }

    $timestamp = strtotime($value);
    return $timestamp !== false && $timestamp < time();
};

$truncate_text = static function (?string $value, int $length = 120): string {
    $text = trim((string) $value);
    if ($text === '') {
        return 'No description provided.';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '...' : $text;
    }

    return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'submit' && $activity_id > 0) {
        $submission_text = trim((string) ($_POST['submission_text'] ?? ''));
        $attachment = $_FILES['attachment'] ?? null;

        $activity_check = "
            SELECT ca.* FROM class_activities ca
            WHERE ca.id = ?
              AND ca.class_id = (
                  SELECT class_id FROM students WHERE id = ? AND school_id = ?
              )
              AND ca.status = 'published'
              AND ca.school_id = ?
        ";
        $check_stmt = $pdo->prepare($activity_check);
        $check_stmt->execute([$activity_id, $student_id, $current_school_id, $current_school_id]);
        $activity_record = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$activity_record) {
            $_SESSION['error'] = 'Activity not found or closed for submission.';
            header("Location: student_class_activities.php?action=view&id=$activity_id");
            exit();
        }

        if ($submission_text === '') {
            $_SESSION['error'] = 'Submission text is required.';
            header("Location: student_class_activities.php?action=submit&id=$activity_id");
            exit();
        }

        $submission_check = "SELECT id FROM student_submissions WHERE activity_id = ? AND student_id = ? AND school_id = ?";
        $sub_check_stmt = $pdo->prepare($submission_check);
        $sub_check_stmt->execute([$activity_id, $student_id, $current_school_id]);

        if ($sub_check_stmt->rowCount() > 0) {
            $_SESSION['error'] = 'You have already submitted this activity.';
            header("Location: student_class_activities.php?action=view&id=$activity_id");
            exit();
        }

        $attachment_path = null;
        if ($attachment && (int) ($attachment['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip', 'txt'];
            $file_ext = strtolower(pathinfo((string) $attachment['name'], PATHINFO_EXTENSION));

            if (in_array($file_ext, $allowed_types, true)) {
                $upload_dir = '../uploads/student_submissions/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) basename((string) $attachment['name']));
                $file_name = 'sub_' . $student_id . '_' . time() . '_' . $safe_name;
                $target_path = $upload_dir . $file_name;

                if (move_uploaded_file((string) $attachment['tmp_name'], $target_path)) {
                    $attachment_path = $target_path;
                }
            }
        }

        $status = 'submitted';
        if (!empty($activity_record['due_date']) && strtotime((string) $activity_record['due_date']) < time()) {
            $status = 'late';
        }

        $insert_sql = "
            INSERT INTO student_submissions
            (school_id, activity_id, student_id, submission_text, attachment_path, status, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ";
        $insert_stmt = $pdo->prepare($insert_sql);
        $insert_stmt->execute([
            $current_school_id,
            $activity_id,
            $student_id,
            $submission_text,
            $attachment_path,
            $status,
        ]);

        $_SESSION['success'] = 'Submission successful.';
        header("Location: student_class_activities.php?action=view&id=$activity_id");
        exit();
    }
}

$student_query = "
    SELECT s.*, c.class_name
    FROM students s
    JOIN classes c ON s.class_id = c.id AND c.school_id = ?
    WHERE s.id = ? AND s.school_id = ?
";
$student_stmt = $pdo->prepare($student_query);
$student_stmt->execute([$current_school_id, $student_id, $current_school_id]);
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die('Student not found');
}

$student_class_id = (int) ($student['class_id'] ?? 0);

$pending_query = "
    SELECT COUNT(DISTINCT ca.id) AS count
    FROM class_activities ca
    LEFT JOIN student_submissions ss
        ON ca.id = ss.activity_id
       AND ss.student_id = ?
       AND ss.school_id = ?
    WHERE ca.class_id = ?
      AND ca.school_id = ?
      AND ca.status = 'published'
      AND (ss.id IS NULL OR ss.status IN ('pending', 'late'))
      AND (ca.due_date IS NULL OR ca.due_date > NOW())
";
$pending_stmt = $pdo->prepare($pending_query);
$pending_stmt->execute([$student_id, $current_school_id, $student_class_id, $current_school_id]);
$pending_count = (int) ($pending_stmt->fetchColumn() ?: 0);

$due_week_query = "
    SELECT COUNT(*) AS count
    FROM class_activities
    WHERE class_id = ?
      AND school_id = ?
      AND status = 'published'
      AND due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
";
$due_week_stmt = $pdo->prepare($due_week_query);
$due_week_stmt->execute([$student_class_id, $current_school_id]);
$due_week_count = (int) ($due_week_stmt->fetchColumn() ?: 0);

$submitted_query = "
    SELECT COUNT(*) AS count
    FROM student_submissions
    WHERE student_id = ?
      AND school_id = ?
      AND status IN ('submitted', 'graded', 'late')
";
$submitted_stmt = $pdo->prepare($submitted_query);
$submitted_stmt->execute([$student_id, $current_school_id]);
$submitted_count = (int) ($submitted_stmt->fetchColumn() ?: 0);

$avg_query = "
    SELECT AVG(marks_obtained) AS avg_score
    FROM student_submissions
    WHERE student_id = ?
      AND school_id = ?
      AND status = 'graded'
      AND marks_obtained IS NOT NULL
";
$avg_stmt = $pdo->prepare($avg_query);
$avg_stmt->execute([$student_id, $current_school_id]);
$avg_score_value = (float) ($avg_stmt->fetchColumn() ?: 0);
$avg_score = number_format($avg_score_value, 1);

$feedback = null;
$feedback_not_found = false;
$selected_activity = null;
$activity_not_found = false;
$submit_activity = null;
$submit_activity_not_found = false;
$already_submitted = false;
$activities = [];

if ($action === 'feedback') {
    $feedback_id = isset($_GET['feedback_id']) ? (int) $_GET['feedback_id'] : 0;
    if ($feedback_id > 0) {
        $feedback_query = "
            SELECT ss.*, ca.title AS activity_title, ca.total_marks, ca.activity_type,
                   ca.due_date, s.subject_name, u.full_name AS teacher_name
            FROM student_submissions ss
            JOIN class_activities ca
                ON ss.activity_id = ca.id
               AND ca.school_id = ?
            JOIN subjects s
                ON ca.subject_id = s.id
               AND s.school_id = ?
            JOIN users u
                ON ca.teacher_id = u.id
            WHERE ss.id = ?
              AND ss.student_id = ?
              AND ss.school_id = ?
        ";
        $feedback_stmt = $pdo->prepare($feedback_query);
        $feedback_stmt->execute([$current_school_id, $current_school_id, $feedback_id, $student_id, $current_school_id]);
        $feedback = $feedback_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$feedback) {
        $feedback_not_found = true;
    }
} elseif ($action === 'view' && $activity_id > 0) {
    $activity_query = "
        SELECT ca.*, s.subject_name, u.full_name AS teacher_name,
               ss.id AS submission_id,
               ss.submission_text,
               ss.attachment_path AS student_attachment,
               ss.marks_obtained,
               ss.feedback,
               ss.status AS submission_status,
               ss.submitted_at,
               ss.graded_at
        FROM class_activities ca
        JOIN subjects s ON ca.subject_id = s.id AND s.school_id = ?
        JOIN users u ON ca.teacher_id = u.id
        LEFT JOIN student_submissions ss
            ON ca.id = ss.activity_id
           AND ss.student_id = ?
           AND ss.school_id = ?
        WHERE ca.id = ?
          AND ca.class_id = ?
          AND ca.school_id = ?
    ";
    $activity_stmt = $pdo->prepare($activity_query);
    $activity_stmt->execute([$current_school_id, $student_id, $current_school_id, $activity_id, $student_class_id, $current_school_id]);
    $selected_activity = $activity_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$selected_activity) {
        $activity_not_found = true;
    }
} elseif ($action === 'submit' && $activity_id > 0) {
    $submit_query = "
        SELECT ca.*, s.subject_name, u.full_name AS teacher_name
        FROM class_activities ca
        JOIN subjects s ON ca.subject_id = s.id AND s.school_id = ?
        JOIN users u ON ca.teacher_id = u.id
        WHERE ca.id = ?
          AND ca.class_id = ?
          AND ca.status = 'published'
          AND ca.school_id = ?
    ";
    $submit_stmt = $pdo->prepare($submit_query);
    $submit_stmt->execute([$current_school_id, $activity_id, $student_class_id, $current_school_id]);
    $submit_activity = $submit_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$submit_activity) {
        $submit_activity_not_found = true;
    } else {
        $check_submission = "SELECT id FROM student_submissions WHERE activity_id = ? AND student_id = ? AND school_id = ?";
        $check_stmt = $pdo->prepare($check_submission);
        $check_stmt->execute([$activity_id, $student_id, $current_school_id]);
        $already_submitted = $check_stmt->rowCount() > 0;
    }
} else {
    $activities_query = "
        SELECT ca.*, s.subject_name, u.full_name AS teacher_name,
               ss.id AS submission_id,
               ss.status AS submission_status,
               ss.marks_obtained,
               ss.feedback,
               ss.submitted_at,
               ss.attachment_path AS student_attachment,
               ss.id AS submission_db_id
        FROM class_activities ca
        JOIN subjects s ON ca.subject_id = s.id AND s.school_id = ?
        JOIN users u ON ca.teacher_id = u.id
        LEFT JOIN student_submissions ss
            ON ca.id = ss.activity_id
           AND ss.student_id = ?
           AND ss.school_id = ?
        WHERE ca.class_id = ?
          AND ca.status = 'published'
          AND ca.school_id = ?
    ";

    if ($action === 'submitted') {
        $activities_query .= " AND ss.id IS NOT NULL AND ss.status IN ('submitted', 'graded', 'late')";
    } elseif ($action === 'graded') {
        $activities_query .= " AND ss.status = 'graded'";
    } else {
        $activities_query .= " AND (ss.id IS NULL OR ss.status IN ('pending', 'late'))";
    }

    $activities_query .= ' ORDER BY ca.due_date ASC, ca.created_at DESC';
    $activities_stmt = $pdo->prepare($activities_query);
    $activities_stmt->execute([$current_school_id, $student_id, $current_school_id, $student_class_id, $current_school_id]);
    $activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$flash_success = trim((string) ($_SESSION['success'] ?? ''));
$flash_error = trim((string) ($_SESSION['error'] ?? ''));
unset($_SESSION['success'], $_SESSION['error']);

$action_titles = [
    'dashboard' => 'Active Activities',
    'submitted' => 'Submitted Activities',
    'graded' => 'Graded Activities',
    'view' => 'Activity Details',
    'submit' => 'Submit Activity',
    'feedback' => 'Feedback Review',
];

$action_subtitles = [
    'dashboard' => 'Review open work and submit before deadlines.',
    'submitted' => 'Track work you have already submitted.',
    'graded' => 'See scored activities and teacher feedback.',
    'view' => 'Detailed activity brief, submission status, and feedback.',
    'submit' => 'Upload your response and keep your activity record complete.',
    'feedback' => 'Understand your score and next improvement steps.',
];

$current_title = $action_titles[$action] ?? 'Class Activities';
$current_subtitle = $action_subtitles[$action] ?? 'Manage your class activities.';

$pageTitle = 'Class Activities | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool');
$extraHead = <<<'HTML'
<link rel="stylesheet" href="../assets/css/student-class-activities.css">
HTML;

require_once __DIR__ . '/../includes/student_header.php';
?>

<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="student-activities-page space-y-8">
    <section class="workspace-card workspace-hero p-6 sm:p-8" data-reveal>
        <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-3xl">
                <p class="hero-kicker text-xs uppercase tracking-wide">Student Workflow</p>
                <h1 class="hero-title mt-3 text-3xl sm:text-4xl"><?php echo htmlspecialchars($current_title); ?></h1>
                <p class="hero-meta mt-3 text-sm sm:text-base">
                    <?php echo htmlspecialchars($current_subtitle); ?>
                    <?php if (!empty($student['class_name'])): ?>
                        <span class="hidden sm:inline">&middot;</span> <?php echo htmlspecialchars((string) $student['class_name']); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="hero-actions grid gap-3 sm:grid-cols-2">
                <a href="student_class_activities.php?action=dashboard" class="btn btn-outline">
                    <i class="fas fa-list"></i>
                    <span>Activity List</span>
                </a>
                <a href="student_class_activities.php?action=graded" class="btn btn-outline">
                    <i class="fas fa-star"></i>
                    <span>View Graded</span>
                </a>
            </div>
        </div>
    </section>

    <section class="workspace-card p-6" data-reveal data-reveal-delay="60">
        <div class="workspace-metrics-grid">
            <article class="metric-tile">
                <div class="metric-icon bg-amber-500/10 text-amber-700"><i class="fas fa-hourglass-half"></i></div>
                <p class="metric-label">Pending</p>
                <h2 class="metric-value"><?php echo number_format($pending_count); ?></h2>
                <p class="metric-note">Activities still awaiting your response</p>
            </article>
            <article class="metric-tile">
                <div class="metric-icon bg-sky-100 text-sky-700"><i class="fas fa-calendar-week"></i></div>
                <p class="metric-label">Due In 7 Days</p>
                <h2 class="metric-value"><?php echo number_format($due_week_count); ?></h2>
                <p class="metric-note">Deadlines approaching this week</p>
            </article>
            <article class="metric-tile">
                <div class="metric-icon bg-emerald-100 text-emerald-700"><i class="fas fa-paper-plane"></i></div>
                <p class="metric-label">Submitted</p>
                <h2 class="metric-value"><?php echo number_format($submitted_count); ?></h2>
                <p class="metric-note">All activity submissions recorded</p>
            </article>
            <article class="metric-tile">
                <div class="metric-icon bg-violet-600/10 text-violet-700"><i class="fas fa-chart-line"></i></div>
                <p class="metric-label">Average Score</p>
                <h2 class="metric-value"><?php echo htmlspecialchars($avg_score); ?>%</h2>
                <p class="metric-note">Mean score from graded submissions</p>
            </article>
        </div>
    </section>

    <?php if ($flash_success !== ''): ?>
        <div class="alert-banner alert-success" data-reveal data-reveal-delay="80">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($flash_success); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($flash_error !== ''): ?>
        <div class="alert-banner alert-error" data-reveal data-reveal-delay="80">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($flash_error); ?></span>
        </div>
    <?php endif; ?>

    <?php
    $tab_action = in_array($action, ['dashboard', 'submitted', 'graded'], true) ? $action : 'dashboard';
    $tabs = [
        'dashboard' => ['label' => 'Active', 'icon' => 'fa-tasks'],
        'submitted' => ['label' => 'Submitted', 'icon' => 'fa-check-circle'],
        'graded' => ['label' => 'Graded', 'icon' => 'fa-star'],
    ];
    ?>

    <section class="workspace-card p-5 sm:p-6" data-reveal data-reveal-delay="100">
        <div class="activity-tabs">
            <?php foreach ($tabs as $tab_key => $tab_meta): ?>
                <a href="student_class_activities.php?action=<?php echo htmlspecialchars($tab_key); ?>" class="activity-tab <?php echo $tab_action === $tab_key ? 'is-active' : ''; ?>">
                    <i class="fas <?php echo htmlspecialchars($tab_meta['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($tab_meta['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($action === 'feedback'): ?>
        <section class="workspace-card p-6" data-reveal data-reveal-delay="120">
            <?php if ($feedback_not_found): ?>
                <div class="empty-state">
                    <i class="fas fa-comments"></i>
                    <p class="empty-title">Feedback not found</p>
                    <p class="empty-text">The selected feedback record is unavailable or outside your account scope.</p>
                    <a class="btn btn-primary" href="student_class_activities.php?action=graded">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Graded Activities</span>
                    </a>
                </div>
            <?php else: ?>
                <?php $feedback_meta = $get_activity_meta($feedback['activity_type'] ?? ''); ?>
                <div class="detail-grid">
                    <article class="detail-card">
                        <div class="detail-heading">
                            <span class="pill <?php echo htmlspecialchars($feedback_meta['pill']); ?>">
                                <i class="fas <?php echo htmlspecialchars($feedback_meta['icon']); ?>"></i>
                                <span><?php echo htmlspecialchars($feedback_meta['label']); ?></span>
                            </span>
                            <h2 class="text-2xl font-semibold text-ink-900 mt-3"><?php echo htmlspecialchars((string) ($feedback['activity_title'] ?? 'Activity')); ?></h2>
                            <p class="mt-2 text-sm text-slate-600">
                                <?php echo htmlspecialchars((string) ($feedback['subject_name'] ?? 'Subject')); ?>
                                <span>&middot;</span>
                                <?php echo htmlspecialchars((string) ($feedback['teacher_name'] ?? 'Teacher')); ?>
                            </p>
                        </div>
                        <div class="detail-list mt-5">
                            <div class="info-tile">
                                <p class="tile-label">Due Date</p>
                                <p class="tile-value"><?php echo htmlspecialchars($format_datetime($feedback['due_date'] ?? null)); ?></p>
                            </div>
                            <div class="info-tile">
                                <p class="tile-label">Submitted</p>
                                <p class="tile-value"><?php echo htmlspecialchars($format_datetime($feedback['submitted_at'] ?? null)); ?></p>
                            </div>
                            <div class="info-tile">
                                <p class="tile-label">Score</p>
                                <p class="tile-value">
                                    <?php echo htmlspecialchars((string) ($feedback['marks_obtained'] ?? '0')); ?>/<?php echo htmlspecialchars((string) ($feedback['total_marks'] ?? '0')); ?>
                                </p>
                            </div>
                        </div>
                    </article>

                    <article class="detail-card">
                        <h3 class="text-lg font-semibold text-ink-900">Teacher Feedback</h3>
                        <div class="read-only-surface mt-4">
                            <?php if (!empty($feedback['feedback'])): ?>
                                <?php echo nl2br(htmlspecialchars((string) $feedback['feedback'])); ?>
                            <?php else: ?>
                                <span class="text-slate-500">No written feedback has been added for this submission yet.</span>
                            <?php endif; ?>
                        </div>
                        <div class="mt-5">
                            <a class="btn btn-outline" href="student_class_activities.php?action=graded">
                                <i class="fas fa-arrow-left"></i>
                                <span>Back to Graded Activities</span>
                            </a>
                        </div>
                    </article>
                </div>
            <?php endif; ?>
        </section>

    <?php elseif ($action === 'view' && $activity_id > 0): ?>
        <section class="workspace-card p-6" data-reveal data-reveal-delay="120">
            <?php if ($activity_not_found): ?>
                <div class="empty-state">
                    <i class="fas fa-eye-slash"></i>
                    <p class="empty-title">Activity not found</p>
                    <p class="empty-text">This activity does not exist in your class, or you no longer have access.</p>
                    <a class="btn btn-primary" href="student_class_activities.php?action=dashboard">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Activities</span>
                    </a>
                </div>
            <?php else: ?>
                <?php
                $activity_meta = $get_activity_meta($selected_activity['activity_type'] ?? '');
                $submission_meta = $get_submission_meta($selected_activity['submission_status'] ?? '');
                $activity_overdue = $is_overdue($selected_activity['due_date'] ?? null);
                $has_score = isset($selected_activity['marks_obtained']) && $selected_activity['marks_obtained'] !== null && $selected_activity['marks_obtained'] !== '';
                ?>
                <div class="detail-grid detail-grid-wide">
                    <article class="detail-card">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="pill <?php echo htmlspecialchars($activity_meta['pill']); ?>">
                                <i class="fas <?php echo htmlspecialchars($activity_meta['icon']); ?>"></i>
                                <span><?php echo htmlspecialchars($activity_meta['label']); ?></span>
                            </span>
                            <span class="pill <?php echo htmlspecialchars($submission_meta['pill']); ?>">
                                <i class="fas <?php echo htmlspecialchars($submission_meta['icon']); ?>"></i>
                                <span><?php echo htmlspecialchars($submission_meta['label']); ?></span>
                            </span>
                            <?php if ($activity_overdue): ?>
                                <span class="pill pill-rose"><i class="fas fa-fire"></i><span>Overdue</span></span>
                            <?php endif; ?>
                        </div>

                        <h2 class="mt-4 text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars((string) ($selected_activity['title'] ?? 'Activity')); ?></h2>
                        <p class="mt-2 text-sm text-slate-600">
                            <?php echo htmlspecialchars((string) ($selected_activity['subject_name'] ?? 'Subject')); ?>
                            <span>&middot;</span>
                            <?php echo htmlspecialchars((string) ($selected_activity['teacher_name'] ?? 'Teacher')); ?>
                        </p>

                        <div class="detail-section mt-6">
                            <h3 class="detail-section-title">Description</h3>
                            <div class="read-only-surface"><?php echo nl2br(htmlspecialchars((string) ($selected_activity['description'] ?? 'No description provided.'))); ?></div>
                        </div>

                        <div class="detail-section mt-5">
                            <h3 class="detail-section-title">Instructions</h3>
                            <div class="read-only-surface"><?php echo nl2br(htmlspecialchars((string) ($selected_activity['instructions'] ?? 'No instructions provided.'))); ?></div>
                        </div>

                        <?php if (!empty($selected_activity['attachment_path'])): ?>
                            <div class="mt-5">
                                <a href="<?php echo htmlspecialchars((string) $selected_activity['attachment_path']); ?>" class="btn btn-outline" target="_blank" rel="noopener">
                                    <i class="fas fa-download"></i>
                                    <span>Download Activity Attachment</span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </article>

                    <aside class="detail-card">
                        <h3 class="text-lg font-semibold text-ink-900">Activity Snapshot</h3>
                        <div class="detail-list mt-4">
                            <div class="info-tile">
                                <p class="tile-label">Due Date</p>
                                <p class="tile-value"><?php echo htmlspecialchars($format_datetime($selected_activity['due_date'] ?? null)); ?></p>
                            </div>
                            <div class="info-tile">
                                <p class="tile-label">Total Marks</p>
                                <p class="tile-value"><?php echo htmlspecialchars((string) ($selected_activity['total_marks'] ?? '0')); ?></p>
                            </div>
                            <?php if ($has_score): ?>
                                <div class="info-tile">
                                    <p class="tile-label">Your Score</p>
                                    <p class="tile-value"><?php echo htmlspecialchars((string) $selected_activity['marks_obtained']); ?>/<?php echo htmlspecialchars((string) ($selected_activity['total_marks'] ?? '0')); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-6">
                            <?php if (!empty($selected_activity['submission_id'])): ?>
                                <a class="btn btn-outline" href="student_class_activities.php?action=<?php echo htmlspecialchars(($selected_activity['submission_status'] ?? '') === 'graded' ? 'graded' : 'submitted'); ?>">
                                    <i class="fas fa-arrow-left"></i>
                                    <span>Back to List</span>
                                </a>
                            <?php else: ?>
                                <a class="btn btn-primary" href="student_class_activities.php?action=submit&id=<?php echo (int) $activity_id; ?>">
                                    <i class="fas fa-upload"></i>
                                    <span>Submit Work</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </aside>
                </div>

                <?php if (!empty($selected_activity['submission_id'])): ?>
                    <article class="detail-card mt-6">
                        <h3 class="text-lg font-semibold text-ink-900">Your Submission</h3>
                        <div class="detail-list mt-4">
                            <div class="info-tile">
                                <p class="tile-label">Submitted At</p>
                                <p class="tile-value"><?php echo htmlspecialchars($format_datetime($selected_activity['submitted_at'] ?? null)); ?></p>
                            </div>
                            <?php if (!empty($selected_activity['graded_at'])): ?>
                                <div class="info-tile">
                                    <p class="tile-label">Graded At</p>
                                    <p class="tile-value"><?php echo htmlspecialchars($format_datetime($selected_activity['graded_at'] ?? null)); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="detail-section mt-5">
                            <h4 class="detail-section-title">Submission Text</h4>
                            <div class="read-only-surface">
                                <?php if (!empty($selected_activity['submission_text'])): ?>
                                    <?php echo nl2br(htmlspecialchars((string) $selected_activity['submission_text'])); ?>
                                <?php else: ?>
                                    <span class="text-slate-500">No text submitted.</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($selected_activity['student_attachment'])): ?>
                            <div class="mt-5">
                                <a href="<?php echo htmlspecialchars((string) $selected_activity['student_attachment']); ?>" class="btn btn-outline" target="_blank" rel="noopener">
                                    <i class="fas fa-download"></i>
                                    <span>Download Your Attachment</span>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($selected_activity['feedback'])): ?>
                            <div class="detail-section mt-5">
                                <h4 class="detail-section-title">Teacher Feedback</h4>
                                <div class="read-only-surface feedback-surface"><?php echo nl2br(htmlspecialchars((string) $selected_activity['feedback'])); ?></div>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endif; ?>
            <?php endif; ?>
        </section>

    <?php elseif ($action === 'submit' && $activity_id > 0): ?>
        <section class="workspace-card p-6" data-reveal data-reveal-delay="120">
            <?php if ($submit_activity_not_found): ?>
                <div class="empty-state">
                    <i class="fas fa-upload"></i>
                    <p class="empty-title">Activity not available</p>
                    <p class="empty-text">This activity is not currently open for your class.</p>
                    <a class="btn btn-primary" href="student_class_activities.php?action=dashboard">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Activities</span>
                    </a>
                </div>
            <?php elseif ($already_submitted): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <p class="empty-title">Submission already received</p>
                    <p class="empty-text">You already submitted this activity. Open details to review your record.</p>
                    <a class="btn btn-primary" href="student_class_activities.php?action=view&id=<?php echo (int) $activity_id; ?>">
                        <i class="fas fa-eye"></i>
                        <span>View Submission</span>
                    </a>
                </div>
            <?php else: ?>
                <?php
                $submit_meta = $get_activity_meta($submit_activity['activity_type'] ?? '');
                $submit_overdue = $is_overdue($submit_activity['due_date'] ?? null);
                ?>
                <div class="detail-grid detail-grid-wide">
                    <article class="detail-card">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="pill <?php echo htmlspecialchars($submit_meta['pill']); ?>">
                                <i class="fas <?php echo htmlspecialchars($submit_meta['icon']); ?>"></i>
                                <span><?php echo htmlspecialchars($submit_meta['label']); ?></span>
                            </span>
                            <?php if ($submit_overdue): ?>
                                <span class="pill pill-amber"><i class="fas fa-clock"></i><span>Late Submission</span></span>
                            <?php endif; ?>
                        </div>

                        <h2 class="mt-4 text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars((string) ($submit_activity['title'] ?? 'Activity')); ?></h2>
                        <p class="mt-2 text-sm text-slate-600">
                            <?php echo htmlspecialchars((string) ($submit_activity['subject_name'] ?? 'Subject')); ?>
                            <span>&middot;</span>
                            <?php echo htmlspecialchars((string) ($submit_activity['teacher_name'] ?? 'Teacher')); ?>
                        </p>

                        <form method="POST" enctype="multipart/form-data" class="submit-form mt-6">
                            <div class="field-wrap">
                                <label for="submission_text" class="field-label">Submission Text</label>
                                <textarea id="submission_text" name="submission_text" rows="8" class="control-input" placeholder="Type your answer or summary here..." required></textarea>
                            </div>

                            <div class="field-wrap">
                                <label for="attachment" class="field-label">Attachment (Optional)</label>
                                <input id="attachment" type="file" name="attachment" class="control-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.zip,.txt">
                                <p class="field-note">Allowed formats: PDF, DOC, DOCX, JPG, PNG, ZIP, TXT.</p>
                            </div>

                            <div class="alert-note">
                                <i class="fas fa-circle-info"></i>
                                <span>After submission, edits are restricted unless your teacher reopens the activity.</span>
                            </div>

                            <div class="mt-6 flex flex-wrap gap-3">
                                <a href="student_class_activities.php?action=dashboard" class="btn btn-outline">
                                    <i class="fas fa-arrow-left"></i>
                                    <span>Cancel</span>
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i>
                                    <span>Submit Work</span>
                                </button>
                            </div>
                        </form>
                    </article>

                    <aside class="detail-card">
                        <h3 class="text-lg font-semibold text-ink-900">Activity Details</h3>
                        <div class="detail-list mt-4">
                            <div class="info-tile">
                                <p class="tile-label">Type</p>
                                <p class="tile-value"><?php echo htmlspecialchars($submit_meta['label']); ?></p>
                            </div>
                            <div class="info-tile">
                                <p class="tile-label">Due Date</p>
                                <p class="tile-value"><?php echo htmlspecialchars($format_datetime($submit_activity['due_date'] ?? null)); ?></p>
                            </div>
                            <div class="info-tile">
                                <p class="tile-label">Total Marks</p>
                                <p class="tile-value"><?php echo htmlspecialchars((string) ($submit_activity['total_marks'] ?? '0')); ?></p>
                            </div>
                        </div>

                        <?php if (!empty($submit_activity['instructions'])): ?>
                            <div class="detail-section mt-5">
                                <h4 class="detail-section-title">Instructions</h4>
                                <div class="read-only-surface"><?php echo nl2br(htmlspecialchars((string) $submit_activity['instructions'])); ?></div>
                            </div>
                        <?php endif; ?>
                    </aside>
                </div>
            <?php endif; ?>
        </section>

    <?php else: ?>
        <section class="workspace-card p-6" data-reveal data-reveal-delay="120">
            <div class="section-head">
                <h2 class="text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars($action_titles[$tab_action] ?? 'Activities'); ?></h2>
                <p class="text-sm text-slate-600">
                    <?php if ($tab_action === 'dashboard'): ?>
                        Prioritized by deadline so you can submit on time.
                    <?php elseif ($tab_action === 'submitted'): ?>
                        Review work you already sent and open each record for full details.
                    <?php else: ?>
                        Check scores and open detailed teacher feedback.
                    <?php endif; ?>
                </p>
            </div>

            <?php if (empty($activities)): ?>
                <div class="empty-state mt-4">
                    <i class="fas fa-clipboard-list"></i>
                    <p class="empty-title">No activities found</p>
                    <p class="empty-text">
                        <?php if ($tab_action === 'dashboard'): ?>
                            There are no pending activities at the moment.
                        <?php elseif ($tab_action === 'submitted'): ?>
                            You have not submitted any activities yet.
                        <?php else: ?>
                            No graded activities available yet.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="activity-list mt-4">
                    <?php foreach ($activities as $activity): ?>
                        <?php
                        $type_meta = $get_activity_meta($activity['activity_type'] ?? '');
                        $status_meta = $get_submission_meta($activity['submission_status'] ?? '');
                        $activity_due_soon = !empty($activity['due_date'])
                            && strtotime((string) $activity['due_date']) > time()
                            && strtotime((string) $activity['due_date']) < strtotime('+3 days');
                        $activity_overdue = $is_overdue($activity['due_date'] ?? null);
                        $has_student_file = !empty($activity['student_attachment']);
                        ?>
                        <article class="activity-item">
                            <div class="activity-main">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="pill <?php echo htmlspecialchars($type_meta['pill']); ?>">
                                        <i class="fas <?php echo htmlspecialchars($type_meta['icon']); ?>"></i>
                                        <span><?php echo htmlspecialchars($type_meta['label']); ?></span>
                                    </span>
                                    <span class="pill <?php echo htmlspecialchars($status_meta['pill']); ?>">
                                        <i class="fas <?php echo htmlspecialchars($status_meta['icon']); ?>"></i>
                                        <span><?php echo htmlspecialchars($status_meta['label']); ?></span>
                                    </span>
                                    <?php if ($activity_overdue): ?>
                                        <span class="pill pill-rose"><i class="fas fa-fire"></i><span>Overdue</span></span>
                                    <?php elseif ($activity_due_soon): ?>
                                        <span class="pill pill-amber"><i class="fas fa-clock"></i><span>Due Soon</span></span>
                                    <?php endif; ?>
                                </div>

                                <h3 class="activity-title"><?php echo htmlspecialchars((string) ($activity['title'] ?? 'Untitled Activity')); ?></h3>
                                <p class="activity-meta">
                                    <i class="fas fa-book"></i> <?php echo htmlspecialchars((string) ($activity['subject_name'] ?? 'Subject')); ?>
                                    <span>&middot;</span>
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars((string) ($activity['teacher_name'] ?? 'Teacher')); ?>
                                    <?php if (!empty($activity['due_date'])): ?>
                                        <span>&middot;</span>
                                        <i class="fas fa-calendar"></i> <?php echo htmlspecialchars($format_datetime($activity['due_date'])); ?>
                                    <?php endif; ?>
                                </p>
                                <p class="activity-description"><?php echo htmlspecialchars($truncate_text($activity['description'] ?? '', 140)); ?></p>
                            </div>

                            <div class="activity-side">
                                <div class="score-chip">
                                    <span class="text-xs uppercase tracking-wide text-slate-500">Marks</span>
                                    <strong><?php echo htmlspecialchars((string) ($activity['total_marks'] ?? '0')); ?></strong>
                                </div>

                                <div class="activity-actions">
                                    <?php if ($has_student_file): ?>
                                        <a href="<?php echo htmlspecialchars((string) $activity['student_attachment']); ?>" class="btn btn-outline" target="_blank" rel="noopener">
                                            <i class="fas fa-download"></i>
                                            <span>Your File</span>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($tab_action === 'graded' && !empty($activity['submission_db_id'])): ?>
                                        <a href="student_class_activities.php?action=feedback&feedback_id=<?php echo (int) $activity['submission_db_id']; ?>" class="btn btn-primary">
                                            <i class="fas fa-comments"></i>
                                            <span>View Feedback</span>
                                        </a>
                                    <?php elseif (!empty($activity['submission_id'])): ?>
                                        <a href="student_class_activities.php?action=view&id=<?php echo (int) $activity['id']; ?>" class="btn btn-outline">
                                            <i class="fas fa-eye"></i>
                                            <span>View Submission</span>
                                        </a>
                                    <?php else: ?>
                                        <a href="student_class_activities.php?action=submit&id=<?php echo (int) $activity['id']; ?>" class="btn btn-primary">
                                            <i class="fas fa-upload"></i>
                                            <span>Submit Work</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<script>
(function () {
    const sidebarOverlay = document.getElementById('studentSidebarOverlay');
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function () {
            document.body.classList.remove('sidebar-open');
        });
    }

    if (window.matchMedia('(min-width: 768px)').matches) {
        document.body.classList.remove('sidebar-open');
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>
