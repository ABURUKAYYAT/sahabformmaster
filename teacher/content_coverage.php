<?php
// teacher/content_coverage.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow teachers to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

// Get current school context
$current_school_id = require_school_auth();

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';
$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_coverage') {
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $class_id = intval($_POST['class_id'] ?? 0);
        $term = trim($_POST['term'] ?? '');
        $week = intval($_POST['week'] ?? 0);
        $date_covered = trim($_POST['date_covered'] ?? '');
        $time_start = trim($_POST['time_start'] ?? '');
        $time_end = trim($_POST['time_end'] ?? '');
        $period = trim($_POST['period'] ?? '');
        $topics_covered = trim($_POST['topics_covered'] ?? '');
        $objectives_achieved = trim($_POST['objectives_achieved'] ?? '');
        $resources_used = trim($_POST['resources_used'] ?? '');
        $assessment_done = trim($_POST['assessment_done'] ?? '');
        $challenges = trim($_POST['challenges'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Validation
        if ($subject_id <= 0) $errors[] = 'Subject is required.';
        if ($class_id <= 0) $errors[] = 'Class is required.';
        if (empty($term)) $errors[] = 'Term is required.';
        if (empty($date_covered)) $errors[] = 'Date covered is required.';
        if (empty($topics_covered)) $errors[] = 'Topics covered is required.';

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO content_coverage
                    (school_id, teacher_id, subject_id, class_id, term, week, date_covered, time_start, time_end, period,
                     topics_covered, objectives_achieved, resources_used, assessment_done, challenges, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $stmt->execute([
                    $current_school_id, $teacher_id, $subject_id, $class_id, $term, $week, $date_covered,
                    $time_start ?: null, $time_end ?: null, $period ?: null,
                    $topics_covered, $objectives_achieved ?: null, $resources_used ?: null,
                    $assessment_done ?: null, $challenges ?: null, $notes ?: null
                ]);

                $success = 'Content coverage submitted successfully and is pending principal approval.';

                // Reset form
                $_POST = [];
            } catch (Exception $e) {
                $errors[] = 'Failed to submit coverage: ' . $e->getMessage();
            }
        }
    }
}

// Get teacher's assigned subjects and classes (school-filtered)
$stmt = $pdo->prepare("
    SELECT DISTINCT
        s.id as subject_id, s.subject_name,
        c.id as class_id, c.class_name,
        sa.assigned_at
    FROM subject_assignments sa
    JOIN subjects s ON sa.subject_id = s.id AND s.school_id = ?
    JOIN classes c ON sa.class_id = c.id AND c.school_id = ?
    WHERE sa.teacher_id = ?
    ORDER BY s.subject_name, c.class_name
");
$stmt->execute([$current_school_id, $current_school_id, $teacher_id]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get existing coverage entries for this teacher (school-filtered)
$stmt = $pdo->prepare("
    SELECT cc.*,
           s.subject_name, cl.class_name,
           u.full_name as principal_name
    FROM content_coverage cc
    JOIN subjects s ON cc.subject_id = s.id
    JOIN classes cl ON cc.class_id = cl.id
    LEFT JOIN users u ON cc.principal_id = u.id
    WHERE cc.teacher_id = ? AND cc.school_id = ?
    ORDER BY cc.date_covered DESC, cc.submitted_at DESC
");
$stmt->execute([$teacher_id, $current_school_id]);
$coverage_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current academic year
$current_year = date('Y') . '/' . (date('Y') + 1);
$unique_subjects = [];
$unique_classes = [];

foreach ($assignments as $assignment) {
    if (!isset($unique_subjects[$assignment['subject_id']])) {
        $unique_subjects[$assignment['subject_id']] = [
            'subject_id' => $assignment['subject_id'],
            'subject_name' => $assignment['subject_name'],
        ];
    }

    if (!isset($unique_classes[$assignment['class_id']])) {
        $unique_classes[$assignment['class_id']] = [
            'class_id' => $assignment['class_id'],
            'class_name' => $assignment['class_name'],
        ];
    }
}

$coverage_total = count($coverage_entries);
$pending_total = 0;
$approved_total = 0;
$recent_total = 0;
$thirty_days_ago = strtotime('-30 days');

foreach ($coverage_entries as $entry) {
    $entry_status = strtolower($entry['status'] ?? 'pending');
    if ($entry_status === 'pending') {
        $pending_total += 1;
    }
    if ($entry_status === 'approved') {
        $approved_total += 1;
    }
    if (!empty($entry['date_covered']) && strtotime($entry['date_covered']) >= $thirty_days_ago) {
        $recent_total += 1;
    }
}

$status_labels = [
    'pending' => 'Pending Review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];

$status_badges = [
    'pending' => 'bg-amber-500/10 text-amber-700 border border-amber-100',
    'approved' => 'bg-emerald-50 text-emerald-700 border border-emerald-100',
    'rejected' => 'bg-rose-50 text-rose-700 border border-rose-100',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Coverage | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/css/teacher-workspace.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="landing bg-slate-50 workspace-page">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="h-10 w-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Teacher Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="workspace-header-actions flex items-center gap-3">
                <span class="hidden text-sm text-slate-600 md:block">Welcome, <?php echo htmlspecialchars($teacher_name); ?></span>
                <a class="btn btn-outline" href="curricullum.php">Curriculum</a>
                <a class="btn btn-primary" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 py-8 lg:grid-cols-[280px_1fr]">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 bg-white shadow-lift border-r border-ink-900/10 transform -translate-x-full transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell h-full overflow-y-auto">
                <?php include '../includes/teacher_sidebar.php'; ?>
            </div>
        </aside>

        <main class="space-y-6">
            <section class="overflow-hidden rounded-3xl border border-ink-900/5 shadow-lift">
                <div class="workspace-hero p-6 text-white sm:p-8">
                    <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                        <div class="max-w-3xl">
                            <p class="text-xs uppercase tracking-[0.32em] text-white/75">Coverage Workspace</p>
                            <h1 class="mt-3 text-3xl font-display font-semibold leading-tight sm:text-4xl">Weekly coverage tracking now follows the same structured teacher workspace pattern as the question bank.</h1>
                            <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base">Submit taught content, record delivery details, and review submission history in a consistent shell that matches curriculum and assessment workflows.</p>
                        </div>
                        <div class="workspace-hero-actions grid gap-3 sm:grid-cols-2">
                            <a href="#coverage-form-card" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                                <i class="fas fa-plus-circle"></i>
                                <span>Submit Coverage</span>
                            </a>
                            <a href="#coverage-history" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-history"></i>
                                <span>Review History</span>
                            </a>
                            <a href="curricullum.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-book-open"></i>
                                <span>Open Curriculum</span>
                            </a>
                            <a href="questions.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-question-circle"></i>
                                <span>Question Bank</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="workspace-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-teal-600/10 text-teal-700"><i class="fas fa-calendar-alt"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Academic Year</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars($current_year); ?></h2>
                        <p class="text-sm text-slate-500">Current reporting cycle</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-sky-600/10 text-sky-700"><i class="fas fa-folder-open"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Entries</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $coverage_total; ?></h2>
                        <p class="text-sm text-slate-500">Coverage submissions on record</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-amber-500/10 text-amber-700"><i class="fas fa-hourglass-half"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Pending</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $pending_total; ?></h2>
                        <p class="text-sm text-slate-500">Awaiting principal review</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-emerald-600/10 text-emerald-700"><i class="fas fa-circle-check"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Approved</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $approved_total; ?></h2>
                        <p class="text-sm text-slate-500">Accepted coverage reports</p>
                    </article>
                    <article class="workspace-metric-card">
                        <div class="workspace-metric-icon bg-violet-600/10 text-violet-700"><i class="fas fa-clock-rotate-left"></i></div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Last 30 Days</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $recent_total; ?></h2>
                        <p class="text-sm text-slate-500">Recent teaching updates</p>
                    </article>
                </div>
            </section>

            <?php if ($errors): ?>
                <section class="rounded-3xl border border-red-200 bg-red-50 p-5 text-red-800 shadow-soft">
                    <h2 class="text-lg font-semibold">Review the highlighted issues</h2>
                    <ul class="mt-2 space-y-1 text-sm">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if ($success): ?>
                <section class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900 shadow-soft">
                    <p class="font-semibold">Coverage submitted</p>
                    <p class="mt-1 text-sm"><?php echo htmlspecialchars($success); ?></p>
                </section>
            <?php endif; ?>

            <section class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_340px]" id="coverage-form-card">
                <div class="rounded-3xl bg-white shadow-lift border border-ink-900/5 overflow-hidden">
                    <div class="flex flex-col gap-4 border-b border-ink-900/5 p-6 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Coverage Form</p>
                            <h2 class="mt-2 text-2xl font-display text-ink-900">Submit New Coverage</h2>
                        </div>
                        <button type="button" class="btn btn-outline" id="toggleCoverageForm" aria-expanded="true" aria-controls="coverageFormBody">
                            <i class="fas fa-eye-slash"></i>
                            <span>Hide Form</span>
                        </button>
                    </div>
                    <div id="coverageFormBody" class="p-6 space-y-5">
                        <form method="POST" class="space-y-5">
                            <input type="hidden" name="action" value="submit_coverage">

                            <div class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4 space-y-4">
                                <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Session Details</h3>
                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Subject *</label>
                                        <select name="subject_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" required>
                                            <option value="">Select Subject</option>
                                            <?php foreach ($unique_subjects as $subject): ?>
                                                <option value="<?php echo (int) $subject['subject_id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Class *</label>
                                        <select name="class_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" required>
                                            <option value="">Select Class</option>
                                            <?php foreach ($unique_classes as $class): ?>
                                                <option value="<?php echo (int) $class['class_id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Term *</label>
                                        <select name="term" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" required>
                                            <option value="">Select Term</option>
                                            <option value="1st Term">1st Term</option>
                                            <option value="2nd Term">2nd Term</option>
                                            <option value="3rd Term">3rd Term</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Week</label>
                                        <input type="number" name="week" min="1" max="52" placeholder="Enter week number" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                    </div>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Date Covered *</label>
                                        <input type="date" name="date_covered" value="<?php echo date('Y-m-d'); ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" required>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Time Start</label>
                                        <input type="time" name="time_start" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Time End</label>
                                        <input type="time" name="time_end" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Period</label>
                                        <input type="text" name="period" placeholder="e.g. Period 1, Morning Session" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-ink-900/10 bg-slate-50/70 p-4 space-y-4">
                                <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Instructional Notes</h3>
                                <div>
                                    <label class="mb-2 block text-sm font-semibold text-slate-700">Topics Covered *</label>
                                    <textarea name="topics_covered" rows="4" class="w-full rounded-3xl border border-slate-200 bg-white px-4 py-4 text-sm text-slate-700" placeholder="Enter the actual topics you covered in this session..." required></textarea>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Objectives Achieved</label>
                                        <textarea name="objectives_achieved" rows="3" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" placeholder="What learning objectives were achieved..."></textarea>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Resources Used</label>
                                        <textarea name="resources_used" rows="3" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" placeholder="Textbooks, materials, equipment used..."></textarea>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Assessment Done</label>
                                        <textarea name="assessment_done" rows="3" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" placeholder="Tests, quizzes, assignments given..."></textarea>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-slate-700">Challenges Faced</label>
                                        <textarea name="challenges" rows="3" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" placeholder="Any difficulties encountered..."></textarea>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-semibold text-slate-700">Additional Notes</label>
                                    <textarea name="notes" rows="2" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" placeholder="Any additional comments or observations..."></textarea>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i>
                                    <span>Submit for Approval</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="space-y-6">
                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Workflow Shortcuts</h2>
                        <div class="mt-4 grid gap-3 text-sm font-semibold">
                            <a href="curricullum.php" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                <span><i class="fas fa-book-open mr-3 text-teal-700"></i>Review curriculum</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                            <a href="questions.php" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                <span><i class="fas fa-question-circle mr-3 text-teal-700"></i>Open question bank</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                            <a href="#coverage-history" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                <span><i class="fas fa-table-list mr-3 text-teal-700"></i>Jump to history</span>
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </section>

                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <h2 class="text-xl font-semibold text-ink-900">Coverage Standards</h2>
                        <div class="mt-4 space-y-4 text-sm text-slate-600">
                            <p><span class="font-semibold text-ink-900">Record what was actually taught:</span> keep the report tied to delivered content rather than the intended plan alone.</p>
                            <p><span class="font-semibold text-ink-900">Make the timing precise:</span> use period and time fields consistently so classroom activity can be audited later.</p>
                            <p><span class="font-semibold text-ink-900">Note teaching friction clearly:</span> challenges and resource gaps are useful only when they are specific enough to support follow-up action.</p>
                        </div>
                    </section>
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5" id="coverage-history">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">History</p>
                        <h2 class="mt-2 text-2xl font-display text-ink-900">Coverage History</h2>
                        <p class="mt-2 text-sm text-slate-600">Review recent submissions, statuses, timing, and taught topics in one scrollable report.</p>
                    </div>
                    <div class="rounded-2xl bg-mist-50 px-4 py-3 border border-ink-900/5">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Assignments</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo count($assignments); ?></p>
                    </div>
                </div>

                <?php if (empty($coverage_entries)): ?>
                    <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500">
                        <i class="fas fa-clipboard-list text-3xl text-teal-700"></i>
                        <p class="mt-4 text-lg font-semibold text-ink-900">No coverage entries yet</p>
                        <p class="mt-2 text-sm">Use the form above to submit your first content coverage report.</p>
                    </div>
                <?php else: ?>
                    <div class="workspace-table-wrapper mt-6 overflow-x-auto rounded-3xl border border-ink-900/10">
                        <table class="workspace-table min-w-[980px] w-full bg-white text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                <tr>
                                    <th class="px-4 py-4">Date</th>
                                    <th class="px-4 py-4">Subject / Class</th>
                                    <th class="px-4 py-4">Topics Covered</th>
                                    <th class="px-4 py-4">Time</th>
                                    <th class="px-4 py-4">Period</th>
                                    <th class="px-4 py-4">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($coverage_entries as $entry): ?>
                                    <?php
                                    $entry_status = strtolower($entry['status'] ?? 'pending');
                                    $entry_status_label = $status_labels[$entry_status] ?? ucfirst(str_replace('_', ' ', $entry_status));
                                    $entry_status_badge = $status_badges[$entry_status] ?? 'bg-slate-100 text-slate-700 border border-slate-200';
                                    $time_start = $entry['time_start'] ?? '';
                                    $time_end = $entry['time_end'] ?? '';
                                    $time_range = trim($time_start) !== '' || trim($time_end) !== '' ? trim($time_start . ' - ' . $time_end) : '--';
                                    ?>
                                    <tr class="border-t border-slate-100 align-top">
                                        <td class="px-4 py-4 font-semibold text-ink-900"><?php echo htmlspecialchars(date('d M Y', strtotime($entry['date_covered']))); ?></td>
                                        <td class="px-4 py-4">
                                            <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($entry['subject_name']); ?></p>
                                            <p class="mt-1 text-slate-500"><?php echo htmlspecialchars($entry['class_name']); ?></p>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="max-w-md">
                                                <p class="leading-6 text-slate-700"><?php echo htmlspecialchars(mb_strimwidth((string) $entry['topics_covered'], 0, 160, '...')); ?></p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-slate-600"><?php echo htmlspecialchars($time_range); ?></td>
                                        <td class="px-4 py-4 text-slate-600"><?php echo htmlspecialchars($entry['period'] ?? '--'); ?></td>
                                        <td class="px-4 py-4">
                                            <div class="space-y-2">
                                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?php echo $entry_status_badge; ?>"><?php echo htmlspecialchars($entry_status_label); ?></span>
                                                <?php if (!empty($entry['principal_name'])): ?><p class="text-xs text-slate-500">Reviewed by <?php echo htmlspecialchars($entry['principal_name']); ?></p><?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="table-scroll-hint"><i class="fas fa-arrows-left-right"></i><span>Swipe sideways and scroll down to inspect the full history table on mobile.</span></p>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <?php include '../includes/floating-button.php'; ?>
    <script>
        const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
        const sidebar = document.querySelector('[data-sidebar]');
        const overlay = document.querySelector('[data-sidebar-overlay]');
        const body = document.body;

        const openSidebar = () => {
            if (!sidebar || !overlay) return;
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('opacity-0', 'pointer-events-none');
            overlay.classList.add('opacity-100');
            body.classList.add('nav-open');
        };

        const closeSidebarShell = () => {
            if (!sidebar || !overlay) return;
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('opacity-0', 'pointer-events-none');
            overlay.classList.remove('opacity-100');
            body.classList.remove('nav-open');
        };

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                if (sidebar.classList.contains('-translate-x-full')) {
                    openSidebar();
                } else {
                    closeSidebarShell();
                }
            });
        }

        if (overlay) {
            overlay.addEventListener('click', closeSidebarShell);
        }

        if (sidebar) {
            sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeSidebarShell));
        }

        (function () {
            const toggleButton = document.getElementById('toggleCoverageForm');
            const formBody = document.getElementById('coverageFormBody');
            if (!toggleButton || !formBody) return;

            toggleButton.addEventListener('click', function () {
                const isHidden = formBody.classList.toggle('hidden');
                toggleButton.setAttribute('aria-expanded', isHidden ? 'false' : 'true');
                toggleButton.innerHTML = isHidden
                    ? '<i class="fas fa-eye"></i><span>Show Form</span>'
                    : '<i class="fas fa-eye-slash"></i><span>Hide Form</span>';
            });
        })();
    </script>
</body>
</html>
