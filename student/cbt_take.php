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
$test_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

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
    SELECT s.class_id, s.full_name, c.class_name
    FROM students s
    LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id
    WHERE s.id = ? AND s.school_id = ?
    LIMIT 1
");
$student_stmt->execute([$student_id, $current_school_id]);
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    $_SESSION['cbt_error'] = 'Student profile not found for CBT.';
    header('Location: cbt_tests.php');
    exit;
}

$class_id = (int) $student['class_id'];
$class_name = trim((string) ($student['class_name'] ?? ''));
if ($class_name === '') {
    $class_name = 'Class Not Set';
}

$test_stmt = $pdo->prepare("
    SELECT t.*, s.subject_name
    FROM cbt_tests t
    JOIN subjects s ON t.subject_id = s.id
    WHERE t.id = ?
      AND t.school_id = ?
      AND t.class_id = ?
      AND t.status = 'published'
    LIMIT 1
");
$test_stmt->execute([$test_id, $current_school_id, $class_id]);
$test = $test_stmt->fetch(PDO::FETCH_ASSOC);

if (!$test) {
    $_SESSION['cbt_error'] = 'Test not found or not available for your class.';
    header('Location: cbt_tests.php');
    exit;
}

$now_ts = time();
$start_ts = !empty($test['starts_at']) ? strtotime((string) $test['starts_at']) : null;
$end_ts = !empty($test['ends_at']) ? strtotime((string) $test['ends_at']) : null;

if ($start_ts !== null && $now_ts < $start_ts) {
    $_SESSION['cbt_error'] = 'This test has not started yet.';
    header('Location: cbt_tests.php');
    exit;
}

if ($end_ts !== null && $now_ts > $end_ts) {
    $_SESSION['cbt_error'] = 'This test is closed.';
    header('Location: cbt_tests.php');
    exit;
}

$questions_stmt = $pdo->prepare('SELECT * FROM cbt_questions WHERE test_id = ? ORDER BY question_order ASC, id ASC');
$questions_stmt->execute([$test_id]);
$questions = $questions_stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($questions)) {
    $_SESSION['cbt_error'] = 'No questions available for this test yet.';
    header('Location: cbt_tests.php');
    exit;
}

$attempt_stmt = $pdo->prepare('SELECT * FROM cbt_attempts WHERE test_id = ? AND student_id = ? LIMIT 1');
$attempt_stmt->execute([$test_id, $student_id]);
$attempt = $attempt_stmt->fetch(PDO::FETCH_ASSOC);

if (!$attempt) {
    $create_attempt = $pdo->prepare("
        INSERT INTO cbt_attempts (test_id, student_id, total_questions, started_at, status)
        VALUES (?, ?, ?, NOW(), 'in_progress')
    ");
    $create_attempt->execute([$test_id, $student_id, count($questions)]);
    $attempt_id = (int) $pdo->lastInsertId();

    $attempt_stmt->execute([$test_id, $student_id]);
    $attempt = $attempt_stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $attempt_id = (int) $attempt['id'];
    if (($attempt['status'] ?? '') === 'submitted') {
        $_SESSION['cbt_message'] = 'You have already submitted this test.';
        header('Location: cbt_tests.php');
        exit;
    }

    $update_attempt = $pdo->prepare("UPDATE cbt_attempts SET total_questions = ? WHERE id = ? AND status = 'in_progress'");
    $update_attempt->execute([count($questions), $attempt_id]);
}

$saved_answers = [];
$ans_stmt = $pdo->prepare('SELECT question_id, selected_option FROM cbt_answers WHERE attempt_id = ?');
$ans_stmt->execute([$attempt_id]);
foreach ($ans_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $saved_answers[(int) $row['question_id']] = (string) $row['selected_option'];
}

$duration_seconds = max(60, ((int) $test['duration_minutes']) * 60);
$started_at_ts = !empty($attempt['started_at']) ? strtotime((string) $attempt['started_at']) : $now_ts;
if ($started_at_ts === false) {
    $started_at_ts = $now_ts;
}
$elapsed_seconds = max(0, $now_ts - $started_at_ts);
$remaining_seconds = $duration_seconds - $elapsed_seconds;

if ($remaining_seconds <= 0) {
    $_SESSION['cbt_error'] = 'Your CBT time has elapsed. Please submit from the test list.';
    header('Location: cbt_tests.php');
    exit;
}

$question_total = count($questions);
$saved_count = count($saved_answers);
$duration_minutes = (int) ($test['duration_minutes'] ?? 0);
$ends_display = $end_ts ? date('d M Y, h:i A', $end_ts) : 'No fixed end time';

$pageTitle = 'Take CBT | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool');
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
                    <p class="text-xs uppercase tracking-[0.32em] text-white/75">Active CBT Session</p>
                    <h1 class="mt-3 font-display text-3xl font-semibold leading-tight sm:text-4xl"><?php echo htmlspecialchars((string) ($test['title'] ?? 'CBT Test')); ?></h1>
                    <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base">
                        <?php echo htmlspecialchars((string) ($test['subject_name'] ?? 'Subject')); ?> | <?php echo htmlspecialchars($class_name); ?>
                    </p>
                </div>
                <div class="grid gap-3">
                    <span class="cbt-timer-pill">
                        <i class="fas fa-clock"></i>
                        <span>Time Left: <strong id="timer">--:--</strong></span>
                    </span>
                    <a href="cbt_tests.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Tests</span>
                    </a>
                </div>
            </div>
        </div>
        <div class="workspace-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
            <article class="workspace-metric-card">
                <div class="workspace-metric-icon bg-teal-600/10 text-teal-700"><i class="fas fa-list-check"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Questions</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $question_total; ?></h2>
                <p class="text-sm text-slate-500">Total items in this test</p>
            </article>
            <article class="workspace-metric-card">
                <div class="workspace-metric-icon bg-sky-100 text-sky-700"><i class="fas fa-pen"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Answered</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900" id="answered-count"><?php echo $saved_count; ?></h2>
                <p class="text-sm text-slate-500">Saved selections so far</p>
            </article>
            <article class="workspace-metric-card">
                <div class="workspace-metric-icon bg-amber-100 text-amber-700"><i class="fas fa-stopwatch"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Duration</p>
                <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $duration_minutes; ?> mins</h2>
                <p class="text-sm text-slate-500">Fixed test duration</p>
            </article>
            <article class="workspace-metric-card">
                <div class="workspace-metric-icon bg-emerald-100 text-emerald-700"><i class="fas fa-calendar-check"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Started</p>
                <h2 class="mt-1 text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(date('d M Y, h:i A', $started_at_ts)); ?></h2>
                <p class="text-sm text-slate-500">Attempt start time</p>
            </article>
            <article class="workspace-metric-card">
                <div class="workspace-metric-icon bg-slate-100 text-slate-700"><i class="fas fa-hourglass-end"></i></div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Ends</p>
                <h2 class="mt-1 text-lg font-semibold text-ink-900"><?php echo htmlspecialchars($ends_display); ?></h2>
                <p class="text-sm text-slate-500">Schedule close window</p>
            </article>
        </div>
    </section>

    <div id="cbt-offline-status" style="display:none;" data-reveal></div>

    <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]" data-reveal>
        <div class="cbt-attempt-shell">
            <div class="cbt-attempt-header">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Question Navigator</p>
                        <h2 class="mt-1 text-xl font-semibold text-ink-900" id="question-progress">Question 1 of <?php echo $question_total; ?></h2>
                    </div>
                    <p class="text-sm text-slate-600">Answer each question before moving to the next one.</p>
                </div>
                <div class="cbt-progress-rail">
                    <div class="cbt-progress-fill" id="cbtProgressFill"></div>
                </div>
            </div>

            <div class="cbt-panel-body">
                <form id="cbt-form" method="POST" action="cbt_submit.php" data-offline-sync="true" data-offline-message="You are offline. Your CBT submission was queued and will auto-sync when internet returns.">
                    <input type="hidden" name="test_id" value="<?php echo (int) $test_id; ?>">
                    <input type="hidden" name="attempt_id" value="<?php echo (int) $attempt_id; ?>">

                    <?php foreach ($questions as $index => $question): ?>
                        <?php
                        $question_id = (int) $question['id'];
                        $question_number = $index + 1;
                        ?>
                        <article class="cbt-attempt-question" data-index="<?php echo $index; ?>">
                            <p class="cbt-question-title">Q<?php echo $question_number; ?>. <?php echo htmlspecialchars((string) ($question['question_text'] ?? '')); ?></p>

                            <div class="cbt-choice-grid">
                                <?php foreach (['A', 'B', 'C', 'D'] as $option_letter): ?>
                                    <?php
                                    $option_key = 'option_' . strtolower($option_letter);
                                    $is_selected = (($saved_answers[$question_id] ?? '') === $option_letter);
                                    ?>
                                    <div class="cbt-choice <?php echo $is_selected ? 'is-selected' : ''; ?>">
                                        <label>
                                            <input
                                                type="radio"
                                                name="answers[<?php echo $question_id; ?>]"
                                                value="<?php echo $option_letter; ?>"
                                                <?php echo $is_selected ? 'checked' : ''; ?>
                                            >
                                            <span class="cbt-choice-letter"><?php echo $option_letter; ?></span>
                                            <span><?php echo htmlspecialchars((string) ($question[$option_key] ?? '')); ?></span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>

                    <div class="cbt-nav">
                        <button type="button" id="prev-btn" class="btn btn-outline" onclick="goPrev()">Previous</button>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" id="next-btn" class="btn btn-primary" onclick="goNext()">Next</button>
                            <button type="submit" id="submit-btn" class="btn btn-primary" style="display:none;">Submit CBT</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="space-y-6">
            <section class="student-status-card">
                <h2 class="text-xl font-semibold text-ink-900">Session Notes</h2>
                <div class="student-note-list mt-4">
                    <p><strong>Auto submit:</strong> The test submits automatically once your timer reaches zero.</p>
                    <p><strong>Offline support:</strong> If internet drops, your queued submission will sync when connection returns.</p>
                    <p><strong>Final step:</strong> On the last question, click Submit CBT to complete your attempt.</p>
                </div>
            </section>

            <section class="student-status-card">
                <h2 class="text-xl font-semibold text-ink-900">Quick Links</h2>
                <div class="mt-4 grid gap-3 text-sm font-semibold">
                    <a href="cbt_tests.php" class="cbt-anchor-card"><span><i class="fas fa-laptop-file mr-3 text-teal-700"></i>Back to CBT list</span><i class="fas fa-arrow-right"></i></a>
                    <a href="dashboard.php" class="cbt-anchor-card"><span><i class="fas fa-house mr-3 text-teal-700"></i>Return to dashboard</span><i class="fas fa-arrow-right"></i></a>
                </div>
            </section>
        </div>
    </section>
</main>

<script>
let duration = <?php echo (int) $remaining_seconds; ?>;
let currentQuestionIndex = 0;
let totalQuestions = <?php echo (int) count($questions); ?>;
const attemptId = <?php echo (int) $attempt_id; ?>;
const localAnswersKey = `cbt_local_answers_${attemptId}`;

function updateAnsweredCount() {
    const countEl = document.getElementById('answered-count');
    if (!countEl) return;
    const answered = document.querySelectorAll('.cbt-attempt-question input[type="radio"]:checked').length;
    countEl.textContent = answered.toString();
}

function updateChoiceVisualState() {
    document.querySelectorAll('.cbt-choice').forEach((choice) => {
        const input = choice.querySelector('input[type="radio"]');
        choice.classList.toggle('is-selected', Boolean(input && input.checked));
    });
}

function updateProgressBar(index, total) {
    const fill = document.getElementById('cbtProgressFill');
    if (!fill || total <= 0) return;
    const width = ((index + 1) / total) * 100;
    fill.style.width = `${width}%`;
}

function startTimer() {
    const timerEl = document.getElementById('timer');
    if (!timerEl) return;

    const tick = () => {
        if (duration <= 0) {
            document.getElementById('cbt-form').submit();
            return;
        }
        const minutes = Math.floor(duration / 60);
        const seconds = duration % 60;
        timerEl.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
        duration -= 1;
    };

    setInterval(tick, 1000);
    tick();
}

function showQuestion(index) {
    const questionEls = document.querySelectorAll('.cbt-attempt-question');
    if (!questionEls.length) return;

    if (index < 0) index = 0;
    if (index >= questionEls.length) index = questionEls.length - 1;
    currentQuestionIndex = index;

    questionEls.forEach((questionEl, i) => {
        questionEl.classList.toggle('active', i === index);
    });

    const progressEl = document.getElementById('question-progress');
    if (progressEl) {
        progressEl.textContent = `Question ${index + 1} of ${questionEls.length}`;
    }

    updateProgressBar(index, questionEls.length);

    const prevBtn = document.getElementById('prev-btn');
    const nextBtn = document.getElementById('next-btn');
    const submitBtn = document.getElementById('submit-btn');

    if (prevBtn) prevBtn.style.display = index === 0 ? 'none' : 'inline-flex';
    if (nextBtn) nextBtn.style.display = index === questionEls.length - 1 ? 'none' : 'inline-flex';
    if (submitBtn) submitBtn.style.display = index === questionEls.length - 1 ? 'inline-flex' : 'none';
}

function goNext() {
    const questionEls = document.querySelectorAll('.cbt-attempt-question');
    if (!questionEls.length) return;

    const currentQuestionEl = questionEls[currentQuestionIndex];
    const selected = currentQuestionEl.querySelector('input[type="radio"]:checked');
    if (!selected) {
        alert('Please select an answer before moving to the next question.');
        return;
    }

    showQuestion(currentQuestionIndex + 1);
}

function goPrev() {
    showQuestion(currentQuestionIndex - 1);
}

function saveAnswersToLocal() {
    const answers = {};
    document.querySelectorAll('.cbt-attempt-question input[type="radio"]:checked').forEach((input) => {
        const match = input.name.match(/^answers\[(\d+)\]$/);
        if (match) {
            answers[match[1]] = input.value;
        }
    });
    localStorage.setItem(localAnswersKey, JSON.stringify(answers));
}

function restoreAnswersFromLocal() {
    let stored = {};
    try {
        stored = JSON.parse(localStorage.getItem(localAnswersKey) || '{}');
    } catch (error) {
        stored = {};
    }

    Object.keys(stored).forEach((questionId) => {
        const value = stored[questionId];
        const selector = `input[name="answers[${questionId}]"][value="${value}"]`;
        const input = document.querySelector(selector);
        if (input && !input.checked) {
            input.checked = true;
        }
    });
}

window.onload = function () {
    startTimer();
    restoreAnswersFromLocal();
    updateChoiceVisualState();
    updateAnsweredCount();

    document.querySelectorAll('.cbt-attempt-question input[type="radio"]').forEach((input) => {
        input.addEventListener('change', function () {
            saveAnswersToLocal();
            updateChoiceVisualState();
            updateAnsweredCount();
        });
    });

    const form = document.getElementById('cbt-form');
    if (form) {
        form.addEventListener('submit', function () {
            if (navigator.onLine) {
                localStorage.removeItem(localAnswersKey);
            } else {
                saveAnswersToLocal();
            }
        });
    }

    showQuestion(0);
};
</script>

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
