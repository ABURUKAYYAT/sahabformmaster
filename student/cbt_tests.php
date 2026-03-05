<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/cbt_helpers.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: index.php');
    exit;
}

ensure_cbt_schema($pdo);

$current_school_id = get_current_school_id();
$student_id = (int) $_SESSION['student_id'];
$message = '';
$error = '';

if (!empty($_SESSION['cbt_message'])) {
    $message = (string) $_SESSION['cbt_message'];
    unset($_SESSION['cbt_message']);
}
if (!empty($_SESSION['cbt_error'])) {
    $error = (string) $_SESSION['cbt_error'];
    unset($_SESSION['cbt_error']);
}

if ($current_school_id === false) {
    $school_stmt = $pdo->prepare('SELECT school_id FROM students WHERE id = ? LIMIT 1');
    $school_stmt->execute([$student_id]);
    $resolved_school_id = $school_stmt->fetchColumn();
    if ($resolved_school_id !== false) {
        $_SESSION['school_id'] = $resolved_school_id;
        $current_school_id = $resolved_school_id;
    }
}

$student_stmt = $pdo->prepare("
    SELECT s.class_id, s.full_name, s.school_id, c.class_name
    FROM students s
    LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id
    WHERE s.id = ? AND s.school_id = ?
    LIMIT 1
");
$student_stmt->execute([$student_id, $current_school_id]);
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    $student_stmt = $pdo->prepare("
        SELECT s.class_id, s.full_name, s.school_id, c.class_name
        FROM students s
        LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id
        WHERE s.id = ?
        LIMIT 1
    ");
    $student_stmt->execute([$student_id]);
    $student = $student_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        header('Location: index.php');
        exit;
    }
    if ($current_school_id === false && !empty($student['school_id'])) {
        $_SESSION['school_id'] = $student['school_id'];
        $current_school_id = $student['school_id'];
    }
}

$class_id = (int) ($student['class_id'] ?? 0);
$class_name = trim((string) ($student['class_name'] ?? ''));
if ($class_name === '') {
    $class_name = 'Class Not Set';
}

$tests_stmt = $pdo->prepare("
    SELECT
        t.id,
        t.title,
        t.duration_minutes,
        t.starts_at,
        t.ends_at,
        t.status,
        t.created_at,
        s.subject_name,
        COALESCE(qc.question_count, 0) AS question_count,
        a.id AS attempt_id,
        a.status AS attempt_status,
        a.score,
        a.total_questions,
        a.submitted_at,
        a.started_at
    FROM cbt_tests t
    JOIN subjects s ON t.subject_id = s.id
    LEFT JOIN (
        SELECT test_id, COUNT(*) AS question_count
        FROM cbt_questions
        GROUP BY test_id
    ) qc ON qc.test_id = t.id
    LEFT JOIN cbt_attempts a ON a.test_id = t.id AND a.student_id = ?
    WHERE t.school_id = ?
      AND t.class_id = ?
      AND t.status = 'published'
    ORDER BY COALESCE(t.starts_at, t.created_at) DESC, t.id DESC
");
$tests_stmt->execute([$student_id, $current_school_id, $class_id]);
$tests = $tests_stmt->fetchAll(PDO::FETCH_ASSOC);

$now_ts = time();
$total_tests = count($tests);
$available_count = 0;
$in_progress_count = 0;
$completed_count = 0;
$scheduled_count = 0;
$closed_count = 0;
$not_ready_count = 0;
$total_questions = 0;

$decorated_tests = [];
foreach ($tests as $test_row) {
    $start_ts = !empty($test_row['starts_at']) ? strtotime((string) $test_row['starts_at']) : null;
    $end_ts = !empty($test_row['ends_at']) ? strtotime((string) $test_row['ends_at']) : null;
    $has_questions = ((int) ($test_row['question_count'] ?? 0)) > 0;
    $attempt_status = trim((string) ($test_row['attempt_status'] ?? ''));
    $is_submitted = ($attempt_status === 'submitted');
    $is_in_progress = ($attempt_status === 'in_progress');
    $is_scheduled = ($start_ts !== null && $start_ts > $now_ts);
    $is_closed_by_time = ($end_ts !== null && $end_ts < $now_ts);
    $is_available = !$is_submitted && !$is_scheduled && !$is_closed_by_time && $has_questions;

    $status_label = 'Available';
    $status_class = 'is-available';
    $action_type = 'start';
    $action_label = $is_in_progress ? 'Resume Test' : 'Start Test';
    $action_note = '';

    if ($is_submitted) {
        $status_label = 'Completed';
        $status_class = 'is-completed';
        $action_type = 'pdf';
        $action_label = 'Result PDF';
        $completed_count++;
    } elseif (!$has_questions) {
        $status_label = 'Not Ready';
        $status_class = 'is-not-ready';
        $action_type = 'note';
        $action_note = 'Teacher is preparing questions.';
        $not_ready_count++;
    } elseif ($is_scheduled) {
        $status_label = 'Scheduled';
        $status_class = 'is-scheduled';
        $action_type = 'note';
        $action_note = 'Wait for start time.';
        $scheduled_count++;
    } elseif ($is_closed_by_time) {
        $status_label = 'Closed';
        $status_class = 'is-closed';
        $action_type = 'note';
        $action_note = 'Submission window ended.';
        $closed_count++;
    } elseif ($is_in_progress) {
        $status_label = 'In Progress';
        $status_class = 'is-in-progress';
        $in_progress_count++;
        $available_count++;
    } else {
        $available_count++;
    }

    $total_questions += (int) ($test_row['question_count'] ?? 0);

    $decorated_tests[] = [
        'id' => (int) $test_row['id'],
        'title' => (string) $test_row['title'],
        'subject_name' => (string) $test_row['subject_name'],
        'duration_minutes' => (int) ($test_row['duration_minutes'] ?? 0),
        'question_count' => (int) ($test_row['question_count'] ?? 0),
        'start_text' => $start_ts ? date('d M Y, h:i A', $start_ts) : 'Immediate',
        'end_text' => $end_ts ? date('d M Y, h:i A', $end_ts) : 'No fixed end',
        'status_label' => $status_label,
        'status_class' => $status_class,
        'action_type' => $action_type,
        'action_label' => $action_label,
        'action_note' => $action_note,
        'attempt_status' => $attempt_status,
        'score' => (int) ($test_row['score'] ?? 0),
        'total_questions' => (int) ($test_row['total_questions'] ?? 0),
        'submitted_at' => !empty($test_row['submitted_at']) ? date('d M Y, h:i A', strtotime((string) $test_row['submitted_at'])) : '',
    ];
}

$pageTitle = 'Student CBT Workspace | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool');
$extraHead = <<<'HTML'
<link rel="stylesheet" href="../assets/css/teacher-workspace.css">
<link rel="stylesheet" href="../assets/css/teacher-cbt.css">
<link rel="stylesheet" href="../assets/css/student-cbt.css">
HTML;

require_once __DIR__ . '/../includes/student_header.php';
?>

<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="student-cbt-page space-y-6">
    <section class="overflow-hidden rounded-3xl border border-ink-900/5 shadow-lift" data-reveal>
        <div class="workspace-hero p-6 text-white sm:p-8">
            <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs uppercase tracking-[0.32em] text-white/75">Student Assessment Workspace</p>
                    <h1 class="mt-3 font-display text-3xl font-semibold leading-tight sm:text-4xl">CBT Tests</h1>
                    <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base">
                        Review your published tests, start available sessions, and track completed scores for <?php echo htmlspecialchars($class_name); ?>.
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <a href="dashboard.php" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                        <i class="fas fa-house"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="myresults.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                        <i class="fas fa-chart-line"></i>
                        <span>My Results</span>
                    </a>
                </div>
            </div>
        </div>
        <div class="workspace-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
            <article class="workspace-metric-card">
                <div class="workspace-metric-icon bg-teal-600/10 text-teal-700"><i class="fas fa-laptop-file"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Published Tests</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $total_tests; ?></h2>
                <p class="text-sm text-slate-500">Visible for your class</p>
            </article>
            <article class="workspace-metric-card">
                <div class="workspace-metric-icon bg-emerald-100 text-emerald-700"><i class="fas fa-play"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Available</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $available_count; ?></h2>
                <p class="text-sm text-slate-500">Ready to start or resume</p>
            </article>
            <article class="workspace-metric-card">
                <div class="workspace-metric-icon bg-sky-100 text-sky-700"><i class="fas fa-hourglass-half"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">In Progress</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $in_progress_count; ?></h2>
                <p class="text-sm text-slate-500">Attempts not yet submitted</p>
            </article>
            <article class="workspace-metric-card">
                <div class="workspace-metric-icon bg-amber-100 text-amber-700"><i class="fas fa-square-check"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Completed</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $completed_count; ?></h2>
                <p class="text-sm text-slate-500">Submitted and scored</p>
            </article>
            <article class="workspace-metric-card">
                <div class="workspace-metric-icon bg-slate-100 text-slate-700"><i class="fas fa-list-check"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Question Load</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $total_questions; ?></h2>
                <p class="text-sm text-slate-500">Across all listed tests</p>
            </article>
        </div>
    </section>

    <?php if ($message): ?>
        <section class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900 shadow-soft" data-reveal>
            <p class="font-semibold">Action successful</p>
            <p class="mt-1 text-sm"><?php echo htmlspecialchars($message); ?></p>
        </section>
    <?php endif; ?>

    <?php if ($error): ?>
        <section class="rounded-3xl border border-red-200 bg-red-50 p-5 text-red-800 shadow-soft" data-reveal>
            <p class="font-semibold">Action needs attention</p>
            <p class="mt-1 text-sm"><?php echo htmlspecialchars($error); ?></p>
        </section>
    <?php endif; ?>

    <div id="cbt-offline-status" style="display:none;" data-reveal></div>

    <section class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_340px]" data-reveal>
        <div class="rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Registry</p>
                    <h2 class="mt-2 font-display text-2xl text-ink-900">My CBT Schedule</h2>
                    <p class="mt-2 text-sm text-slate-600">Start active tests, resume in-progress attempts, and download completed result slips.</p>
                </div>
            </div>

            <?php if (empty($decorated_tests)): ?>
                <div class="cbt-empty-state mt-6">
                    <i class="fas fa-laptop-file text-3xl text-teal-700"></i>
                    <p class="mt-4 text-lg font-semibold text-ink-900">No CBT tests available yet</p>
                    <p class="mt-2 text-sm text-slate-500">Your class does not have published CBT tests at the moment. Check back later.</p>
                </div>
            <?php else: ?>
                <div class="workspace-table-wrapper mt-6 overflow-x-auto rounded-3xl border border-ink-900/10" id="student-cbt-registry">
                    <table class="workspace-table min-w-[1080px] w-full bg-white text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                            <tr>
                                <th class="px-4 py-4">Title</th>
                                <th class="px-4 py-4">Subject</th>
                                <th class="px-4 py-4">Schedule</th>
                                <th class="px-4 py-4">Duration</th>
                                <th class="px-4 py-4">Questions</th>
                                <th class="px-4 py-4">Status</th>
                                <th class="px-4 py-4">Score</th>
                                <th class="px-4 py-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($decorated_tests as $test_item): ?>
                                <tr class="border-t border-slate-100 align-top">
                                    <td class="px-4 py-4">
                                        <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($test_item['title']); ?></p>
                                        <?php if ($test_item['submitted_at'] !== ''): ?>
                                            <p class="mt-1 text-xs text-slate-500">Submitted <?php echo htmlspecialchars($test_item['submitted_at']); ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-slate-600"><?php echo htmlspecialchars($test_item['subject_name']); ?></td>
                                    <td class="px-4 py-4 text-slate-600">
                                        <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($test_item['start_text']); ?></p>
                                        <p class="mt-1 text-xs text-slate-500">Ends <?php echo htmlspecialchars($test_item['end_text']); ?></p>
                                    </td>
                                    <td class="px-4 py-4 font-semibold text-ink-900"><?php echo (int) $test_item['duration_minutes']; ?> mins</td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700"><?php echo (int) $test_item['question_count']; ?> items</span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="cbt-status-badge <?php echo htmlspecialchars($test_item['status_class']); ?>">
                                            <i class="fas fa-circle text-[0.55rem]"></i>
                                            <span><?php echo htmlspecialchars($test_item['status_label']); ?></span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <?php if ($test_item['attempt_status'] === 'submitted'): ?>
                                            <span class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                                                <?php echo (int) $test_item['score']; ?> / <?php echo (int) $test_item['total_questions']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-slate-400">Not submitted</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4">
                                        <?php if ($test_item['action_type'] === 'start'): ?>
                                            <a href="cbt_take.php?id=<?php echo (int) $test_item['id']; ?>" class="btn btn-primary !px-3 !py-2">
                                                <i class="fas fa-play"></i>
                                                <span><?php echo htmlspecialchars($test_item['action_label']); ?></span>
                                            </a>
                                        <?php elseif ($test_item['action_type'] === 'pdf'): ?>
                                            <a href="generate_cbt_result_pdf.php?test_id=<?php echo (int) $test_item['id']; ?>" class="btn btn-outline !px-3 !py-2">
                                                <i class="fas fa-file-pdf"></i>
                                                <span><?php echo htmlspecialchars($test_item['action_label']); ?></span>
                                            </a>
                                        <?php else: ?>
                                            <span class="cbt-action-note"><?php echo htmlspecialchars($test_item['action_note']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways and scroll down to inspect all CBT details on mobile.</span></p>
            <?php endif; ?>
        </div>

        <div class="space-y-6">
            <section class="student-status-card">
                <h2 class="text-xl font-semibold text-ink-900">Status Summary</h2>
                <div class="student-status-list mt-4">
                    <div class="student-status-row"><span class="text-slate-500">Available tests</span><strong class="text-ink-900"><?php echo $available_count; ?></strong></div>
                    <div class="student-status-row"><span class="text-slate-500">In progress</span><strong class="text-ink-900"><?php echo $in_progress_count; ?></strong></div>
                    <div class="student-status-row"><span class="text-slate-500">Scheduled</span><strong class="text-ink-900"><?php echo $scheduled_count; ?></strong></div>
                    <div class="student-status-row"><span class="text-slate-500">Closed</span><strong class="text-ink-900"><?php echo $closed_count; ?></strong></div>
                    <div class="student-status-row"><span class="text-slate-500">Not ready</span><strong class="text-ink-900"><?php echo $not_ready_count; ?></strong></div>
                </div>
            </section>

            <section class="student-status-card">
                <h2 class="text-xl font-semibold text-ink-900">Attempt Guidance</h2>
                <div class="student-note-list mt-4">
                    <p><strong>Check timing:</strong> Start tests only within the scheduled window shown in the table.</p>
                    <p><strong>Resume quickly:</strong> If an attempt is in progress, use Resume Test to continue where you stopped.</p>
                    <p><strong>Keep record:</strong> After submission, generate your result PDF immediately for reference.</p>
                </div>
            </section>

            <section class="student-status-card">
                <h2 class="text-xl font-semibold text-ink-900">Shortcuts</h2>
                <div class="mt-4 grid gap-3 text-sm font-semibold">
                    <a href="myresults.php" class="cbt-anchor-card"><span><i class="fas fa-chart-line mr-3 text-teal-700"></i>Open results dashboard</span><i class="fas fa-arrow-right"></i></a>
                    <a href="student_class_activities.php" class="cbt-anchor-card"><span><i class="fas fa-tasks mr-3 text-teal-700"></i>View class activities</span><i class="fas fa-arrow-right"></i></a>
                </div>
            </section>
        </div>
    </section>
</main>

<script src="../assets/js/cbt-offline-sync.js"></script>
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

    CBTOfflineSync.init({
        queueKey: 'cbt_student_offline_queue_v1',
        formSelector: 'form[data-offline-sync="true"]',
        statusElementId: 'cbt-offline-status',
        statusPrefix: 'Student CBT Sync:',
        swPath: '../cbt-sw.js'
    });
})();
</script>

<?php include '../includes/floating-button.php'; ?>
<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>
