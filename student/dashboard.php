<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: index.php');
    exit;
}

$student_id = (int) $_SESSION['student_id'];
$school_id = get_current_school_id();

$student_stmt = $pdo->prepare("
    SELECT s.*, c.class_name
    FROM students s
    LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id
    WHERE s.id = ? AND s.school_id = ?
    LIMIT 1
");
$student_stmt->execute([$student_id, $school_id]);
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    session_destroy();
    header('Location: index.php?error=access_denied');
    exit;
}

$student_name = $_SESSION['student_name'] ?? ($student['full_name'] ?? 'Student');
$admission_no = $_SESSION['admission_no'] ?? ($student['admission_no'] ?? '');
$class_id = (int) ($student['class_id'] ?? 0);
$class_name = trim((string) ($student['class_name'] ?? 'N/A'));

$attendance_stmt = $pdo->prepare("
    SELECT COUNT(*) total_days,
           SUM(CASE WHEN LOWER(TRIM(status))='present' THEN 1 ELSE 0 END) present_days,
           SUM(CASE WHEN LOWER(TRIM(status))='late' THEN 1 ELSE 0 END) late_days,
           SUM(CASE WHEN LOWER(TRIM(status))='absent' THEN 1 ELSE 0 END) absent_days,
           SUM(CASE WHEN LOWER(TRIM(status))='leave' THEN 1 ELSE 0 END) leave_days
    FROM attendance
    WHERE student_id=? AND school_id=?
");
$attendance_stmt->execute([$student_id, $school_id]);
$attendance = $attendance_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$attendance_total = (int) ($attendance['total_days'] ?? 0);
$attendance_present = (int) ($attendance['present_days'] ?? 0);
$attendance_late = (int) ($attendance['late_days'] ?? 0);
$attendance_absent = (int) ($attendance['absent_days'] ?? 0);
$attendance_leave = (int) ($attendance['leave_days'] ?? 0);
$attendance_rate = $attendance_total > 0 ? round(($attendance_present / $attendance_total) * 100, 1) : 0.0;

$attendance_trend_stmt = $pdo->prepare("
    SELECT DATE_FORMAT(date,'%Y-%m') month_key,
           SUM(CASE WHEN LOWER(TRIM(status))='present' THEN 1 ELSE 0 END) present_count,
           SUM(CASE WHEN LOWER(TRIM(status))='late' THEN 1 ELSE 0 END) late_count,
           SUM(CASE WHEN LOWER(TRIM(status))='absent' THEN 1 ELSE 0 END) absent_count
    FROM attendance
    WHERE student_id=? AND school_id=? AND date>=DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY month_key
    ORDER BY month_key ASC
");
$attendance_trend_stmt->execute([$student_id, $school_id]);
$attendance_rows = $attendance_trend_stmt->fetchAll(PDO::FETCH_ASSOC);
$attendance_index = [];
foreach ($attendance_rows as $row) {
    $attendance_index[(string) $row['month_key']] = [
        'present' => (int) ($row['present_count'] ?? 0),
        'late' => (int) ($row['late_count'] ?? 0),
        'absent' => (int) ($row['absent_count'] ?? 0),
    ];
}
$attendance_labels = [];
$attendance_present_series = [];
$attendance_late_series = [];
$attendance_absent_series = [];
$cursor = new DateTime('first day of this month');
$cursor->modify('-5 months');
for ($i = 0; $i < 6; $i++) {
    $key = $cursor->format('Y-m');
    $attendance_labels[] = $cursor->format('M Y');
    $attendance_present_series[] = $attendance_index[$key]['present'] ?? 0;
    $attendance_late_series[] = $attendance_index[$key]['late'] ?? 0;
    $attendance_absent_series[] = $attendance_index[$key]['absent'] ?? 0;
    $cursor->modify('+1 month');
}

$result_summary_stmt = $pdo->prepare("
    SELECT COUNT(*) results_count,
           AVG(COALESCE(first_ca,0)+COALESCE(second_ca,0)+COALESCE(exam,0)) avg_score,
           MAX(COALESCE(first_ca,0)+COALESCE(second_ca,0)+COALESCE(exam,0)) best_score
    FROM results
    WHERE student_id=? AND school_id=?
");
$result_summary_stmt->execute([$student_id, $school_id]);
$result_summary = $result_summary_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$results_count = (int) ($result_summary['results_count'] ?? 0);
$avg_score = round((float) ($result_summary['avg_score'] ?? 0), 1);
$best_score = round((float) ($result_summary['best_score'] ?? 0), 1);

$result_term_stmt = $pdo->prepare("
    SELECT term,
           AVG(COALESCE(first_ca,0)+COALESCE(second_ca,0)+COALESCE(exam,0)) avg_score,
           COUNT(*) entry_count
    FROM results
    WHERE student_id=? AND school_id=?
    GROUP BY term
");
$result_term_stmt->execute([$student_id, $school_id]);
$result_rows = $result_term_stmt->fetchAll(PDO::FETCH_ASSOC);
$result_labels = ['First Term', 'Second Term', 'Third Term'];
$result_avg_series = [0, 0, 0];
$result_entry_series = [0, 0, 0];
foreach ($result_rows as $row) {
    $term = strtolower(trim((string) ($row['term'] ?? '')));
    $idx = null;
    if (str_contains($term, 'first') || str_contains($term, '1st')) {
        $idx = 0;
    } elseif (str_contains($term, 'second') || str_contains($term, '2nd')) {
        $idx = 1;
    } elseif (str_contains($term, 'third') || str_contains($term, '3rd')) {
        $idx = 2;
    }
    if ($idx !== null) {
        $result_avg_series[$idx] = round((float) ($row['avg_score'] ?? 0), 1);
        $result_entry_series[$idx] = (int) ($row['entry_count'] ?? 0);
    }
}

$subject_count = 0;
if ($class_id > 0) {
    $subject_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sa.subject_id)
        FROM subject_assignments sa
        JOIN subjects sub ON sub.id = sa.subject_id AND sub.school_id = ?
        WHERE sa.class_id = ?
    ");
    $subject_stmt->execute([$school_id, $class_id]);
    $subject_count = (int) ($subject_stmt->fetchColumn() ?: 0);
}

$published_activities = 0;
$pending_activities = 0;
$upcoming_activities = [];
if ($class_id > 0) {
    $published_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM class_activities
        WHERE class_id=? AND school_id=? AND LOWER(TRIM(status))='published'
    ");
    $published_stmt->execute([$class_id, $school_id]);
    $published_activities = (int) ($published_stmt->fetchColumn() ?: 0);

    $pending_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM class_activities ca
        WHERE ca.class_id=? AND ca.school_id=? AND LOWER(TRIM(ca.status))='published'
          AND NOT EXISTS (
              SELECT 1 FROM student_submissions ss
              WHERE ss.activity_id=ca.id AND ss.student_id=? AND ss.school_id=?
          )
    ");
    $pending_stmt->execute([$class_id, $school_id, $student_id, $school_id]);
    $pending_activities = (int) ($pending_stmt->fetchColumn() ?: 0);

    $upcoming_stmt = $pdo->prepare("
        SELECT ca.id, ca.title, ca.activity_type, ca.due_date, COALESCE(ss.status,'pending') submission_status
        FROM class_activities ca
        LEFT JOIN student_submissions ss ON ss.activity_id=ca.id AND ss.student_id=? AND ss.school_id=?
        WHERE ca.class_id=? AND ca.school_id=? AND LOWER(TRIM(ca.status))='published'
        ORDER BY (ca.due_date IS NULL) ASC, ca.due_date ASC, ca.id DESC
        LIMIT 6
    ");
    $upcoming_stmt->execute([$student_id, $school_id, $class_id, $school_id]);
    $upcoming_activities = $upcoming_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$completed_activities = max(0, $published_activities - $pending_activities);
$completion_rate = $published_activities > 0 ? round(($completed_activities / $published_activities) * 100, 1) : 0.0;

$submission_counts = ['submitted' => 0, 'late' => 0, 'graded' => 0, 'pending' => 0, 'other' => 0];
$sub_status_stmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(LOWER(TRIM(status)),''),'pending') status_key, COUNT(*) total
    FROM student_submissions
    WHERE student_id=? AND school_id=?
    GROUP BY status_key
");
$sub_status_stmt->execute([$student_id, $school_id]);
foreach ($sub_status_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = strtolower((string) ($row['status_key'] ?? 'pending'));
    $val = (int) ($row['total'] ?? 0);
    if (array_key_exists($key, $submission_counts)) {
        $submission_counts[$key] += $val;
    } else {
        $submission_counts['other'] += $val;
    }
}
$submission_counts['pending'] += $pending_activities;

$events = [];
$submission_table_columns_stmt = $pdo->query("SHOW COLUMNS FROM student_submissions");
$submission_table_columns = [];
if ($submission_table_columns_stmt !== false) {
    foreach ($submission_table_columns_stmt->fetchAll(PDO::FETCH_ASSOC) as $column_info) {
        $field_name = strtolower((string) ($column_info['Field'] ?? ''));
        if ($field_name !== '') {
            $submission_table_columns[$field_name] = true;
        }
    }
}

$event_time_candidates = [];
if (isset($submission_table_columns['graded_at'])) {
    $event_time_candidates[] = 'ss.graded_at';
}
if (isset($submission_table_columns['updated_at'])) {
    $event_time_candidates[] = 'ss.updated_at';
}
if (isset($submission_table_columns['submitted_at'])) {
    $event_time_candidates[] = 'ss.submitted_at';
}

if (count($event_time_candidates) === 0) {
    $event_time_sql = 'NOW()';
} elseif (count($event_time_candidates) === 1) {
    $event_time_sql = $event_time_candidates[0];
} else {
    $event_time_sql = 'COALESCE(' . implode(', ', $event_time_candidates) . ')';
}

$recent_sub_stmt = $pdo->prepare("
    SELECT ca.title, ss.status, {$event_time_sql} AS event_time
    FROM student_submissions ss
    JOIN class_activities ca ON ca.id=ss.activity_id AND ca.school_id=?
    WHERE ss.student_id=? AND ss.school_id=?
    ORDER BY event_time DESC
    LIMIT 4
");
$recent_sub_stmt->execute([$school_id, $student_id, $school_id]);
foreach ($recent_sub_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $status = strtolower(trim((string) ($row['status'] ?? 'pending')));
    $tone = 'slate';
    $icon = 'fa-upload';
    $label = 'Submission updated';
    if ($status === 'graded') {
        $tone = 'emerald';
        $icon = 'fa-check-circle';
        $label = 'Work graded';
    } elseif ($status === 'late') {
        $tone = 'amber';
        $icon = 'fa-clock';
        $label = 'Late submission';
    } elseif ($status === 'submitted') {
        $tone = 'teal';
        $icon = 'fa-paper-plane';
        $label = 'Submission received';
    }
    $ts = strtotime((string) ($row['event_time'] ?? 'now')) ?: time();
    $events[] = [
        'time' => $ts,
        'tone' => $tone,
        'icon' => $icon,
        'title' => $label . ': ' . (string) ($row['title'] ?? 'Class activity'),
        'meta' => date('M d, Y g:i A', $ts),
    ];
}

$recent_att_stmt = $pdo->prepare("
    SELECT date, status
    FROM attendance
    WHERE student_id=? AND school_id=?
    ORDER BY date DESC
    LIMIT 4
");
$recent_att_stmt->execute([$student_id, $school_id]);
foreach ($recent_att_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $status = strtolower(trim((string) ($row['status'] ?? 'absent')));
    $tone = 'slate';
    $label = 'Attendance recorded';
    if ($status === 'present') {
        $tone = 'teal';
        $label = 'Marked present';
    } elseif ($status === 'late') {
        $tone = 'amber';
        $label = 'Marked late';
    } elseif ($status === 'absent') {
        $tone = 'rose';
        $label = 'Marked absent';
    }
    $ts = strtotime((string) ($row['date'] ?? 'now') . ' 18:00:00') ?: time();
    $events[] = ['time' => $ts, 'tone' => $tone, 'icon' => 'fa-calendar-check', 'title' => $label, 'meta' => date('M d, Y', $ts)];
}
usort($events, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);
$events = array_slice($events, 0, 6);

$format_due = static function (?string $due): string {
    if ($due === null || trim($due) === '') return 'No deadline set';
    $ts = strtotime($due);
    return $ts === false ? 'No deadline set' : date('M d, Y g:i A', $ts);
};

$analytics_payload = [
    'attendance' => ['labels' => $attendance_labels, 'present' => $attendance_present_series, 'late' => $attendance_late_series, 'absent' => $attendance_absent_series],
    'results' => ['labels' => $result_labels, 'average' => $result_avg_series, 'entries' => $result_entry_series],
    'submissions' => ['labels' => ['Submitted', 'Late', 'Graded', 'Pending', 'Other'], 'values' => [$submission_counts['submitted'], $submission_counts['late'], $submission_counts['graded'], $submission_counts['pending'], $submission_counts['other']]],
];

$pageTitle = 'Student Dashboard | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool');
$extraHead = <<<'HTML'
<style>
    .student-layout{overflow-x:hidden}
    .dashboard-card{border-radius:1.5rem;border:1px solid rgba(15,31,45,.06);background:#fff;box-shadow:0 10px 24px rgba(15,31,51,.08)}
    .student-analytics-chart-wrap{position:relative;height:18rem;padding:.4rem .35rem .65rem}
    .student-analytics-chart-wrap canvas{max-width:100% !important}
    .student-sidebar-overlay{position:fixed;inset:0;background:rgba(2,6,23,.45);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:30}
    .sidebar{position:fixed;top:73px;left:0;width:16rem;height:calc(100vh - 73px);background:#fff;border-right:1px solid rgba(15,31,45,.1);box-shadow:0 18px 40px rgba(15,31,51,.12);transform:translateX(-106%);transition:transform .22s ease;z-index:40;overflow-y:auto}
    body.sidebar-open .sidebar{transform:translateX(0)} body.sidebar-open .student-sidebar-overlay{opacity:1;pointer-events:auto}
    .sidebar-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid rgba(15,31,45,.08)}
    .sidebar-header h3{margin:0;font-size:1rem;font-weight:700;color:#0f1f2d}
    .sidebar-close{border:0;border-radius:.55rem;padding:.35rem .55rem;background:rgba(15,31,45,.08);color:#334155;font-size:.8rem;line-height:1;cursor:pointer}
    .sidebar-nav{padding:.8rem}.nav-list{list-style:none;margin:0;padding:0;display:grid;gap:.2rem}
    .nav-link{display:flex;align-items:center;gap:.65rem;border-radius:.75rem;padding:.62rem .72rem;color:#475569;font-size:.88rem;font-weight:600;text-decoration:none;transition:background-color .15s ease,color .15s ease}
    .nav-link:hover{background:rgba(22,133,117,.1);color:#0f6a5c}.nav-link.active{background:rgba(22,133,117,.14);color:#0f6a5c}.nav-icon{width:1rem;text-align:center}
    #studentMain{min-width:0}
    @media (min-width:768px){
        #studentMain{padding-left:16rem !important}
        .sidebar{transform:translateX(0);top:73px;height:calc(100vh - 73px);padding-top:0}
        .sidebar-close{display:none}
        .student-sidebar-overlay{display:none}
    }
    @media (max-width:767.98px){
        #studentMain{padding-left:0 !important}
    }
    @media (max-width:640px){
        .student-analytics-chart-wrap{height:19.5rem;padding:.55rem .5rem .9rem}
        .student-dashboard-page .dashboard-card{padding:1.05rem !important}
        .student-dashboard-page article.rounded-2xl{padding:1.05rem !important}
    }
</style>
HTML;

require_once __DIR__ . '/../includes/student_header.php';
?>
<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="student-dashboard-page space-y-6">
    <section class="dashboard-card p-6 sm:p-8" data-reveal>
        <div class="flex flex-col gap-5 xl:grid xl:grid-cols-[1.6fr_1fr_1fr_1fr_auto] xl:items-end">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Student Workspace</p>
                <h1 class="mt-2 text-3xl font-display text-ink-900">Welcome back, <?php echo htmlspecialchars((string) $student_name); ?></h1>
                <p class="mt-2 text-sm text-slate-600">Class <?php echo htmlspecialchars($class_name); ?>, admission <?php echo htmlspecialchars((string) $admission_no); ?>. Here is your academic pulse for <?php echo htmlspecialchars(get_school_display_name()); ?>.</p>
            </div>
            <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Attendance</p><p class="text-2xl font-semibold text-ink-900"><?php echo number_format($attendance_rate, 1); ?>%</p><p class="text-xs text-slate-500 mt-1"><?php echo $attendance_present; ?> present of <?php echo $attendance_total; ?></p></div>
            <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Average Score</p><p class="text-2xl font-semibold text-ink-900"><?php echo number_format($avg_score, 1); ?></p><p class="text-xs text-slate-500 mt-1"><?php echo number_format($best_score, 1); ?> highest score</p></div>
            <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Pending Work</p><p class="text-2xl font-semibold text-ink-900"><?php echo number_format($pending_activities); ?></p><p class="text-xs text-slate-500 mt-1"><?php echo number_format($completed_activities); ?> completed activities</p></div>
            <div class="flex flex-wrap items-center gap-3"><a class="btn btn-primary" href="myresults.php"><i class="fas fa-chart-line"></i><span>View Results</span></a><a class="btn btn-outline" href="student_class_activities.php"><i class="fas fa-tasks"></i><span>Open Activities</span></a></div>
        </div>
    </section>

    <section class="dashboard-card p-6" data-reveal data-reveal-delay="70">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between mb-5">
            <div><h2 class="text-2xl font-display text-ink-900">Performance Analytics</h2><p class="text-sm text-slate-600">Trend view of your attendance, term scores, and activity workflow.</p></div>
            <a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="attendance.php">Open attendance details</a>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-6">
            <div class="rounded-2xl bg-mist-50 px-4 py-4 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Total Subjects</p><p class="text-2xl font-semibold text-ink-900"><?php echo number_format($subject_count); ?></p><p class="text-xs text-slate-500 mt-1">Subjects assigned to your class</p></div>
            <div class="rounded-2xl bg-mist-50 px-4 py-4 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Result Entries</p><p class="text-2xl font-semibold text-ink-900"><?php echo number_format($results_count); ?></p><p class="text-xs text-slate-500 mt-1">Published records across terms</p></div>
            <div class="rounded-2xl bg-mist-50 px-4 py-4 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Activity Completion</p><p class="text-2xl font-semibold text-ink-900"><?php echo number_format($completion_rate, 1); ?>%</p><p class="text-xs text-slate-500 mt-1"><?php echo $completed_activities; ?> of <?php echo $published_activities; ?> activities completed</p></div>
            <div class="rounded-2xl bg-mist-50 px-4 py-4 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Attendance Records</p><p class="text-2xl font-semibold text-ink-900"><?php echo number_format($attendance_total); ?></p><p class="text-xs text-slate-500 mt-1"><?php echo number_format($attendance_late); ?> late, <?php echo number_format($attendance_absent); ?> absent, <?php echo number_format($attendance_leave); ?> leave</p></div>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-2xl border border-ink-900/10 bg-white p-5 sm:p-6">
                <h3 class="text-base font-semibold text-ink-900">Attendance Trend (6 Months)</h3>
                <p class="text-xs text-slate-500 mt-1 mb-3">Monthly records of present, late, and absent status.</p>
                <div class="student-analytics-chart-wrap"><canvas id="attendanceTrendChart" class="h-full w-full"></canvas><p id="attendanceTrendChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No attendance data available for this period.</p></div>
            </article>
            <article class="rounded-2xl border border-ink-900/10 bg-white p-5 sm:p-6">
                <h3 class="text-base font-semibold text-ink-900">Term Result Performance</h3>
                <p class="text-xs text-slate-500 mt-1 mb-3">Average term score with total subject entries.</p>
                <div class="student-analytics-chart-wrap"><canvas id="resultPerformanceChart" class="h-full w-full"></canvas><p id="resultPerformanceChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No result entries available yet.</p></div>
            </article>
            <article class="rounded-2xl border border-ink-900/10 bg-white p-5 sm:p-6 xl:col-span-2">
                <h3 class="text-base font-semibold text-ink-900">Submission Workflow</h3>
                <p class="text-xs text-slate-500 mt-1 mb-3">Distribution of submitted, late, graded, and pending work.</p>
                <div class="student-analytics-chart-wrap"><canvas id="submissionStatusChart" class="h-full w-full"></canvas><p id="submissionStatusChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No submission data available yet.</p></div>
            </article>
        </div>
    </section>

    <section class="grid gap-4 xl:grid-cols-[1.55fr_1fr]" data-reveal data-reveal-delay="120">
        <article class="dashboard-card p-6">
            <div class="flex items-center justify-between mb-4"><h2 class="text-xl font-semibold text-ink-900">Academic Workspace</h2><a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="mysubjects.php">View all tools</a></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <a href="myresults.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10"><i class="fas fa-chart-line text-teal-700"></i>My results</a>
                <a href="student_class_activities.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10"><i class="fas fa-tasks text-teal-700"></i>Class activities</a>
                <a href="attendance.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10"><i class="fas fa-calendar-check text-teal-700"></i>Attendance</a>
                <a href="mysubjects.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10"><i class="fas fa-book-open text-teal-700"></i>My subjects</a>
                <a href="my-evaluations.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10"><i class="fas fa-star text-teal-700"></i>Evaluations</a>
                <a href="payment.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10"><i class="fas fa-money-bill-wave text-teal-700"></i>School fees</a>
            </div>
        </article>

        <article class="dashboard-card p-6">
            <div class="flex items-center justify-between mb-4"><h2 class="text-lg font-semibold text-ink-900">Upcoming Activities</h2><a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="student_class_activities.php">Open list</a></div>
            <?php if (empty($upcoming_activities)): ?>
                <div class="rounded-xl border border-dashed border-ink-900/15 bg-mist-50 px-4 py-6 text-sm text-slate-500 text-center">No published activities yet. Check back later.</div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($upcoming_activities as $activity): ?>
                        <?php
                        $st = strtolower(trim((string) ($activity['submission_status'] ?? 'pending')));
                        $pill = 'bg-slate-100 text-slate-700';
                        if ($st === 'graded') $pill = 'bg-emerald-100 text-emerald-700';
                        elseif ($st === 'submitted') $pill = 'bg-teal-50 text-teal-700';
                        elseif ($st === 'late') $pill = 'bg-amber-100 text-amber-700';
                        elseif ($st === 'pending') $pill = 'bg-rose-100 text-rose-700';
                        ?>
                        <div class="rounded-xl border border-ink-900/10 px-4 py-3">
                            <div class="flex items-start justify-between gap-2">
                                <div><p class="text-sm font-semibold text-ink-900"><?php echo htmlspecialchars((string) ($activity['title'] ?? 'Class activity')); ?></p><p class="text-xs text-slate-500"><?php echo htmlspecialchars(ucfirst((string) ($activity['activity_type'] ?? 'activity'))); ?>. Due: <?php echo htmlspecialchars($format_due($activity['due_date'] ?? null)); ?></p></div>
                                <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold <?php echo $pill; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <section class="dashboard-card p-6" data-reveal data-reveal-delay="150">
        <div class="flex items-center justify-between mb-4"><h2 class="text-lg font-semibold text-ink-900">Recent Activity</h2><a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="schoolfeed.php">School feed</a></div>
        <?php if (empty($events)): ?>
            <div class="rounded-xl border border-dashed border-ink-900/15 bg-mist-50 px-4 py-6 text-sm text-slate-500 text-center">Your recent activity will appear here once data is available.</div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($events as $event): ?>
                    <?php
                    $tone = (string) ($event['tone'] ?? 'slate');
                    $icon_bg = 'bg-slate-100 text-slate-700';
                    if ($tone === 'teal') $icon_bg = 'bg-teal-600/10 text-teal-700';
                    elseif ($tone === 'amber') $icon_bg = 'bg-amber-500/10 text-amber-500';
                    elseif ($tone === 'emerald') $icon_bg = 'bg-emerald-100 text-emerald-700';
                    elseif ($tone === 'rose') $icon_bg = 'bg-rose-100 text-rose-700';
                    ?>
                    <div class="flex items-start gap-3"><span class="inline-flex h-9 w-9 items-center justify-center rounded-xl <?php echo $icon_bg; ?>"><i class="fas <?php echo htmlspecialchars((string) ($event['icon'] ?? 'fa-circle')); ?>"></i></span><div><p class="text-sm font-semibold text-ink-900"><?php echo htmlspecialchars((string) ($event['title'] ?? 'Activity')); ?></p><p class="text-xs text-slate-500"><?php echo htmlspecialchars((string) ($event['meta'] ?? '')); ?></p></div></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const analyticsData = <?php echo json_encode($analytics_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const sidebarOverlay = document.getElementById('studentSidebarOverlay');
if (sidebarOverlay) sidebarOverlay.addEventListener('click', () => document.body.classList.remove('sidebar-open'));

const toggleChartState = (canvasId, emptyId, hasData) => {
    const canvas = document.getElementById(canvasId);
    const empty = document.getElementById(emptyId);
    if (!canvas || !empty) return;
    if (hasData) { canvas.classList.remove('hidden'); empty.classList.add('hidden'); empty.classList.remove('flex'); return; }
    canvas.classList.add('hidden'); empty.classList.remove('hidden'); empty.classList.add('flex');
};

const initDashboardCharts = () => {
    if (typeof Chart === 'undefined') return;
    Chart.defaults.color = '#475569';
    Chart.defaults.font.family = "'Manrope', sans-serif";
    const gridColor = 'rgba(148, 163, 184, 0.24)';
    const isMobile = window.matchMedia('(max-width: 640px)').matches;
    const legendConfig = {
        position: 'bottom',
        labels: {
            boxWidth: isMobile ? 10 : 12,
            boxHeight: isMobile ? 10 : 12,
            padding: isMobile ? 10 : 14,
            usePointStyle: true,
            font: { size: isMobile ? 10 : 12 }
        }
    };
    const chartLayout = {
        padding: isMobile
            ? { top: 10, right: 8, bottom: 10, left: 8 }
            : { top: 8, right: 8, bottom: 8, left: 8 }
    };

    const present = (analyticsData.attendance?.present || []).map(v => Number(v || 0));
    const late = (analyticsData.attendance?.late || []).map(v => Number(v || 0));
    const absent = (analyticsData.attendance?.absent || []).map(v => Number(v || 0));
    const attendanceLabels = analyticsData.attendance?.labels || [];
    const hasAttendance = [...present, ...late, ...absent].some(v => v > 0);
    toggleChartState('attendanceTrendChart', 'attendanceTrendChartEmpty', hasAttendance);
    if (hasAttendance) {
        new Chart(document.getElementById('attendanceTrendChart'), {
            type: 'bar',
            data: { labels: attendanceLabels, datasets: [
                { label: 'Present', data: present, backgroundColor: '#14b8a6', borderRadius: 6, maxBarThickness: 34 },
                { label: 'Late', data: late, backgroundColor: '#f59e0b', borderRadius: 6, maxBarThickness: 34 },
                { label: 'Absent', data: absent, backgroundColor: '#ef4444', borderRadius: 6, maxBarThickness: 34 }
            ]},
            options: { maintainAspectRatio: false, layout: chartLayout, interaction: { mode: 'index', intersect: false }, scales: {
                x: { stacked: true, grid: { display: false }, ticks: { maxRotation: 0, minRotation: 0, autoSkip: true, maxTicksLimit: isMobile ? 4 : 6 } },
                y: { stacked: true, beginAtZero: true, ticks: { precision: 0, font: { size: isMobile ? 10 : 12 } }, grid: { color: gridColor } }
            }, plugins: { legend: legendConfig } }
        });
    }

    const rLabels = analyticsData.results?.labels || [];
    const rAvg = (analyticsData.results?.average || []).map(v => Number(v || 0));
    const rEntries = (analyticsData.results?.entries || []).map(v => Number(v || 0));
    const hasResults = rEntries.some(v => v > 0);
    toggleChartState('resultPerformanceChart', 'resultPerformanceChartEmpty', hasResults);
    if (hasResults) {
        new Chart(document.getElementById('resultPerformanceChart'), {
            data: { labels: rLabels, datasets: [
                { type: 'bar', label: 'Entries', data: rEntries, yAxisID: 'yEntries', backgroundColor: 'rgba(14,165,233,.35)', borderColor: '#0284c7', borderWidth: 1, borderRadius: 8, maxBarThickness: 42 },
                { type: 'line', label: 'Average Score', data: rAvg, yAxisID: 'yScore', borderColor: '#0f766e', backgroundColor: 'rgba(15,118,110,.14)', borderWidth: 2, tension: .32, fill: true, pointRadius: 4, pointHoverRadius: 5 }
            ]},
            options: { maintainAspectRatio: false, layout: chartLayout, interaction: { mode: 'index', intersect: false }, scales: {
                yScore: { type: 'linear', position: 'left', beginAtZero: true, suggestedMax: 100, grid: { color: gridColor }, ticks: { font: { size: isMobile ? 10 : 12 } } },
                yEntries: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { precision: 0, font: { size: isMobile ? 10 : 12 } } },
                x: { grid: { display: false }, ticks: { maxRotation: 0, minRotation: 0, autoSkip: true } }
            }, plugins: { legend: legendConfig } }
        });
    }

    const sLabels = analyticsData.submissions?.labels || [];
    const sValues = (analyticsData.submissions?.values || []).map(v => Number(v || 0));
    const hasSubmission = sValues.some(v => v > 0);
    toggleChartState('submissionStatusChart', 'submissionStatusChartEmpty', hasSubmission);
    if (hasSubmission) {
        new Chart(document.getElementById('submissionStatusChart'), {
            type: 'doughnut',
            data: { labels: sLabels, datasets: [{ data: sValues, backgroundColor: ['#14b8a6','#f59e0b','#0ea5e9','#ef4444','#64748b'], borderWidth: 0 }] },
            options: { maintainAspectRatio: false, layout: chartLayout, cutout: isMobile ? '52%' : '60%', plugins: { legend: legendConfig } }
        });
    }
};
if (window.matchMedia('(min-width: 768px)').matches) {
    document.body.classList.remove('sidebar-open');
}
initDashboardCharts();
</script>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>
