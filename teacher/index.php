<?php
// teacher/index.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if teacher is logged in and get school_id
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$teacher_name = $_SESSION['full_name'];
$current_school_id = require_school_auth();

// Get dynamic stats for the teacher
$teacher_id = $_SESSION['user_id'];

// Get teacher's class count
$query = "SELECT COUNT(*) as class_count FROM class_teachers ct JOIN classes c ON ct.class_id = c.id WHERE ct.teacher_id = ? AND c.school_id = ?";
$class_stmt = $pdo->prepare($query);
$class_stmt->execute([$teacher_id, $current_school_id]);
$class_count = $class_stmt->fetch()['class_count'];

// Get student's count for teacher's classes
$query = "
    SELECT COUNT(DISTINCT s.id) as student_count
    FROM students s
    JOIN class_teachers ct ON s.class_id = ct.class_id
    JOIN classes c ON s.class_id = c.id
    WHERE ct.teacher_id = ? AND s.school_id = ? AND c.school_id = ?
";
$student_stmt = $pdo->prepare($query);
$student_stmt->execute([$teacher_id, $current_school_id, $current_school_id]);
$student_count = $student_stmt->fetch()['student_count'];

// Get gender split for teacher's students
$query = "
    SELECT
        COUNT(DISTINCT CASE WHEN LOWER(TRIM(s.gender)) = 'female' THEN s.id END) AS female_count,
        COUNT(DISTINCT CASE WHEN LOWER(TRIM(s.gender)) = 'male' THEN s.id END) AS male_count
    FROM students s
    JOIN class_teachers ct ON s.class_id = ct.class_id
    JOIN classes c ON s.class_id = c.id
    WHERE ct.teacher_id = ? AND s.school_id = ? AND c.school_id = ?
";
$gender_stmt = $pdo->prepare($query);
$gender_stmt->execute([$teacher_id, $current_school_id, $current_school_id]);
$gender_counts = $gender_stmt->fetch();
$female_student_count = (int) ($gender_counts['female_count'] ?? 0);
$male_student_count = (int) ($gender_counts['male_count'] ?? 0);
$other_student_count = max(0, (int) $student_count - $female_student_count - $male_student_count);

// Get lesson plans count
$query = "SELECT COUNT(*) as lesson_count FROM lesson_plans WHERE teacher_id = ? AND school_id = ?";
$lesson_stmt = $pdo->prepare($query);
$lesson_stmt->execute([$teacher_id, $current_school_id]);
$lesson_count = $lesson_stmt->fetch()['lesson_count'];

// Get subjects count
$query = "
    SELECT COUNT(DISTINCT sa.subject_id) as subject_count
    FROM subject_assignments sa
    JOIN class_teachers ct ON sa.class_id = ct.class_id
    JOIN classes c ON sa.class_id = c.id
    JOIN subjects sub ON sa.subject_id = sub.id
    WHERE ct.teacher_id = ? AND c.school_id = ? AND sub.school_id = ?
";
$subject_stmt = $pdo->prepare($query);
$subject_stmt->execute([$teacher_id, $current_school_id, $current_school_id]);
$subject_count = $subject_stmt->fetch()['subject_count'];

// Attendance trend (last 6 months) for teacher's classes
$attendance_query = "
    SELECT
        DATE_FORMAT(a.date, '%Y-%m') AS month_key,
        SUM(CASE WHEN LOWER(TRIM(a.status)) = 'present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN LOWER(TRIM(a.status)) = 'late' THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN LOWER(TRIM(a.status)) = 'absent' THEN 1 ELSE 0 END) AS absent_count
    FROM attendance a
    WHERE a.school_id = ?
      AND a.date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
      AND EXISTS (
          SELECT 1
          FROM class_teachers ct
          WHERE ct.class_id = a.class_id
            AND ct.teacher_id = ?
      )
    GROUP BY month_key
    ORDER BY month_key ASC
";
$attendance_stmt = $pdo->prepare($attendance_query);
$attendance_stmt->execute([$current_school_id, $teacher_id]);
$attendance_rows = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);

$attendance_index = [];
foreach ($attendance_rows as $row) {
    $attendance_index[$row['month_key']] = [
        'present' => (int) ($row['present_count'] ?? 0),
        'late' => (int) ($row['late_count'] ?? 0),
        'absent' => (int) ($row['absent_count'] ?? 0),
    ];
}

$attendance_month_labels = [];
$attendance_present_series = [];
$attendance_late_series = [];
$attendance_absent_series = [];
$attendance_cursor = new DateTime('first day of this month');
$attendance_cursor->modify('-5 months');
for ($i = 0; $i < 6; $i++) {
    $month_key = $attendance_cursor->format('Y-m');
    $attendance_month_labels[] = $attendance_cursor->format('M Y');
    $attendance_present_series[] = $attendance_index[$month_key]['present'] ?? 0;
    $attendance_late_series[] = $attendance_index[$month_key]['late'] ?? 0;
    $attendance_absent_series[] = $attendance_index[$month_key]['absent'] ?? 0;
    $attendance_cursor->modify('+1 month');
}

// Lesson plan approval pipeline
$lesson_status_query = "
    SELECT
        COALESCE(NULLIF(LOWER(TRIM(approval_status)), ''), 'pending') AS status_key,
        COUNT(*) AS total
    FROM lesson_plans
    WHERE teacher_id = ? AND school_id = ?
    GROUP BY status_key
";
$lesson_status_stmt = $pdo->prepare($lesson_status_query);
$lesson_status_stmt->execute([$teacher_id, $current_school_id]);
$lesson_status_rows = $lesson_status_stmt->fetchAll(PDO::FETCH_ASSOC);

$lesson_status_counts = [
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0,
    'other' => 0,
];
foreach ($lesson_status_rows as $row) {
    $status_key = strtolower((string) ($row['status_key'] ?? ''));
    $total = (int) ($row['total'] ?? 0);

    if ($status_key === 'approved') {
        $lesson_status_counts['approved'] += $total;
    } elseif ($status_key === 'pending' || $status_key === 'submitted' || $status_key === 'draft') {
        $lesson_status_counts['pending'] += $total;
    } elseif ($status_key === 'rejected') {
        $lesson_status_counts['rejected'] += $total;
    } else {
        $lesson_status_counts['other'] += $total;
    }
}

// Result analytics by term for students in teacher's classes
$result_query = "
    SELECT
        r.term,
        AVG(COALESCE(r.first_ca, 0) + COALESCE(r.second_ca, 0) + COALESCE(r.exam, 0)) AS avg_score,
        COUNT(*) AS entry_count
    FROM results r
    JOIN students s ON s.id = r.student_id
    WHERE r.school_id = ?
      AND s.school_id = ?
      AND EXISTS (
          SELECT 1
          FROM class_teachers ct
          WHERE ct.class_id = s.class_id
            AND ct.teacher_id = ?
      )
    GROUP BY r.term
";
$result_stmt = $pdo->prepare($result_query);
$result_stmt->execute([$current_school_id, $current_school_id, $teacher_id]);
$result_rows = $result_stmt->fetchAll(PDO::FETCH_ASSOC);

$result_term_labels = ['First Term', 'Second Term', 'Third Term'];
$result_avg_series = [0, 0, 0];
$result_entry_series = [0, 0, 0];

foreach ($result_rows as $row) {
    $term_raw = strtolower(trim((string) ($row['term'] ?? '')));
    $term_index = null;

    if (str_contains($term_raw, 'first') || str_contains($term_raw, '1st')) {
        $term_index = 0;
    } elseif (str_contains($term_raw, 'second') || str_contains($term_raw, '2nd')) {
        $term_index = 1;
    } elseif (str_contains($term_raw, 'third') || str_contains($term_raw, '3rd')) {
        $term_index = 2;
    }

    if ($term_index === null) {
        continue;
    }

    $result_avg_series[$term_index] = round((float) ($row['avg_score'] ?? 0), 1);
    $result_entry_series[$term_index] = (int) ($row['entry_count'] ?? 0);
}

$attendance_present_total = array_sum($attendance_present_series);
$attendance_total_records = $attendance_present_total + array_sum($attendance_late_series) + array_sum($attendance_absent_series);
$attendance_rate_percent = $attendance_total_records > 0 ? round(($attendance_present_total / $attendance_total_records) * 100, 1) : 0;

$lesson_plan_total = array_sum($lesson_status_counts);
$lesson_approval_rate = $lesson_plan_total > 0 ? round(($lesson_status_counts['approved'] / $lesson_plan_total) * 100, 1) : 0;

$result_total_entries = array_sum($result_entry_series);
$result_weighted_total = 0.0;
foreach ($result_avg_series as $index => $avg_score) {
    $result_weighted_total += (float) $avg_score * (int) $result_entry_series[$index];
}
$result_overall_average = $result_total_entries > 0 ? round($result_weighted_total / $result_total_entries, 1) : 0;

$analytics_payload = [
    'gender' => [
        'female' => $female_student_count,
        'male' => $male_student_count,
        'other' => $other_student_count,
    ],
    'attendance' => [
        'labels' => $attendance_month_labels,
        'present' => $attendance_present_series,
        'late' => $attendance_late_series,
        'absent' => $attendance_absent_series,
    ],
    'results' => [
        'labels' => $result_term_labels,
        'average' => $result_avg_series,
        'entries' => $result_entry_series,
    ],
    'lesson_plans' => [
        'labels' => ['Approved', 'Pending', 'Rejected', 'Other'],
        'values' => [
            $lesson_status_counts['approved'],
            $lesson_status_counts['pending'],
            $lesson_status_counts['rejected'],
            $lesson_status_counts['other'],
        ],
    ],
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .teacher-dashboard {
            overflow-x: hidden;
        }

        [data-sidebar] {
            overflow: hidden;
        }

        .sidebar-scroll-shell {
            height: 100%;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-y: contain;
            touch-action: pan-y;
            padding-bottom: max(1rem, env(safe-area-inset-bottom));
        }

        .analytics-chart-wrap {
            position: relative;
            height: 18rem;
        }

        @media (max-width: 1024px) {
            .teacher-dashboard .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }

        @media (max-width: 640px) {
            .teacher-dashboard main {
                gap: 1rem;
            }

            .teacher-dashboard section {
                border-radius: 1.1rem;
                padding: 1rem;
            }

            .teacher-dashboard .rounded-2xl {
                border-radius: 0.95rem;
            }

            .analytics-chart-wrap {
                height: 14rem;
            }
        }
    </style>
</head>
<body class="landing teacher-dashboard">
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
            <div class="workspace-header-actions flex items-center gap-3">
                <span class="hidden text-sm text-slate-600 md:block">Welcome, <?php echo htmlspecialchars($teacher_name); ?></span>
                <a class="btn btn-outline" href="index.php">Dashboard</a>
                <a class="btn btn-primary" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 lg:grid-cols-[280px_1fr] py-8">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 bg-white shadow-lift border-r border-ink-900/10 transform -translate-x-full transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell h-full overflow-y-auto">
                <div class="p-6 border-b border-ink-900/10">
                    <h2 class="text-lg font-semibold text-ink-900">Navigation</h2>
                    <p class="text-sm text-slate-500">Teacher workspace</p>
                </div>
                <nav class="p-4 space-y-1 text-sm">
                    <a href="index.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold bg-teal-600/10 text-teal-700">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="schoolfeed.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-newspaper"></i>
                        <span>School Feeds</span>
                    </a>
                    <a href="school_diary.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-book"></i>
                        <span>School Diary</span>
                    </a>
                    <a href="students.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-users"></i>
                        <span>Students</span>
                    </a>
                    <a href="results.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-chart-line"></i>
                        <span>Results</span>
                    </a>
                    <a href="subjects.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-book-open"></i>
                        <span>Subjects</span>
                    </a>
                    <a href="questions.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-question-circle"></i>
                        <span>Question Bank</span>
                    </a>
                    <a href="lesson-plan.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Lesson Plans</span>
                    </a>
                    <a href="curricullum.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Curriculum</span>
                    </a>
                    <a href="teacher_class_activities.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-tasks"></i>
                        <span>Class Activities</span>
                    </a>
                    <a href="cbt_tests.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-laptop-code"></i>
                        <span>CBT Tests</span>
                    </a>
                    <a href="student-evaluation.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-star"></i>
                        <span>Evaluations</span>
                    </a>
                    <a href="class_attendance.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-calendar-check"></i>
                        <span>Attendance</span>
                    </a>
                    <a href="timebook.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-clock"></i>
                        <span>Time Book</span>
                    </a>
                    <a href="permissions.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-key"></i>
                        <span>Permissions</span>
                    </a>
                </nav>
            </div>
        </aside>

        <main class="space-y-6">
            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 class="text-3xl font-display text-ink-900">Welcome back, <?php echo htmlspecialchars($teacher_name); ?></h1>
                        <p class="text-slate-600">Here is a quick snapshot of your classes and activities today.</p>
                    </div>
                    <div class="grid w-full gap-3 sm:grid-cols-3 lg:w-auto">
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Students</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo $student_count; ?></p>
                        </div>
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Classes</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo $class_count; ?></p>
                        </div>
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Subjects</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo $subject_count; ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between mb-6">
                    <div>
                        <h2 class="text-2xl font-display text-ink-900">Performance Analytics</h2>
                        <p class="text-sm text-slate-600">Data from your assigned classes covering attendance, results, and lesson delivery pipeline.</p>
                    </div>
                    <a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="results.php">Open results workspace</a>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-6">
                    <div class="rounded-2xl bg-mist-50 px-4 py-4 border border-ink-900/5">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Attendance Rate</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($attendance_rate_percent, 1); ?>%</p>
                        <p class="text-xs text-slate-500 mt-1">Present records in last 6 months</p>
                    </div>
                    <div class="rounded-2xl bg-mist-50 px-4 py-4 border border-ink-900/5">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Average Result Score</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($result_overall_average, 1); ?></p>
                        <p class="text-xs text-slate-500 mt-1">Across term result entries</p>
                    </div>
                    <div class="rounded-2xl bg-mist-50 px-4 py-4 border border-ink-900/5">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Lesson Approval</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($lesson_approval_rate, 1); ?>%</p>
                        <p class="text-xs text-slate-500 mt-1">Approved lesson plans</p>
                    </div>
                    <div class="rounded-2xl bg-mist-50 px-4 py-4 border border-ink-900/5">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Attendance Records</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($attendance_total_records); ?></p>
                        <p class="text-xs text-slate-500 mt-1">Tracked entries in trend window</p>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <article class="rounded-2xl border border-ink-900/10 bg-white p-4">
                        <h3 class="text-base font-semibold text-ink-900">Student Gender Mix</h3>
                        <p class="text-xs text-slate-500 mt-1 mb-3">Distribution of students currently assigned to your classes.</p>
                        <div class="analytics-chart-wrap">
                            <canvas id="genderDistributionChart" class="h-full w-full"></canvas>
                            <p id="genderDistributionChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No student data available yet.</p>
                        </div>
                    </article>

                    <article class="rounded-2xl border border-ink-900/10 bg-white p-4">
                        <h3 class="text-base font-semibold text-ink-900">Attendance Trend (6 Months)</h3>
                        <p class="text-xs text-slate-500 mt-1 mb-3">Monthly view of present, late, and absent records.</p>
                        <div class="analytics-chart-wrap">
                            <canvas id="attendanceTrendChart" class="h-full w-full"></canvas>
                            <p id="attendanceTrendChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No attendance records available for this period.</p>
                        </div>
                    </article>

                    <article class="rounded-2xl border border-ink-900/10 bg-white p-4">
                        <h3 class="text-base font-semibold text-ink-900">Term Results Performance</h3>
                        <p class="text-xs text-slate-500 mt-1 mb-3">Average scores with total result entries for each term.</p>
                        <div class="analytics-chart-wrap">
                            <canvas id="resultPerformanceChart" class="h-full w-full"></canvas>
                            <p id="resultPerformanceChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No result entries found for your classes.</p>
                        </div>
                    </article>

                    <article class="rounded-2xl border border-ink-900/10 bg-white p-4">
                        <h3 class="text-base font-semibold text-ink-900">Lesson Plan Pipeline</h3>
                        <p class="text-xs text-slate-500 mt-1 mb-3">Current approval status of your lesson plans.</p>
                        <div class="analytics-chart-wrap">
                            <canvas id="lessonPipelineChart" class="h-full w-full"></canvas>
                            <p id="lessonPipelineChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No lesson plans available yet.</p>
                        </div>
                    </article>
                </div>
            </section>

            <section>
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xl font-semibold text-ink-900">Your Workspace</h2>
                    <a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="lesson-plan.php">New lesson plan</a>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-users"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">My Students</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo $student_count; ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="students.php">Manage students</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">My Classes</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo $class_count; ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="students.php">View classes</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-clipboard-list"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Lesson Plans</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo $lesson_count; ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="lesson-plan.php">Create new</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-book-open"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Subjects</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo $subject_count; ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="subjects.php">View subjects</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-chart-line"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Results</p>
                                <p class="text-2xl font-semibold text-ink-900">Pending</p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="results.php">Enter results</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-question-circle"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Question Bank</p>
                                <p class="text-2xl font-semibold text-ink-900">Active</p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="questions.php">View questions</a>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-soft border border-ink-900/5">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                                <i class="fas fa-venus-mars"></i>
                            </span>
                            <div>
                                <p class="text-sm text-slate-500">Students by Gender</p>
                                <p class="text-lg font-semibold text-ink-900">Female & Male Totals</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-xl bg-mist-50 px-3 py-3 border border-ink-900/5">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Female</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo $female_student_count; ?></p>
                            </div>
                            <div class="rounded-xl bg-mist-50 px-3 py-3 border border-ink-900/5">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Male</p>
                                <p class="text-2xl font-semibold text-ink-900"><?php echo $male_student_count; ?></p>
                            </div>
                        </div>
                        <a class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="students.php">View student list</a>
                    </div>
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-ink-900">Quick Actions</h2>
                        <span class="text-xs uppercase tracking-wide text-slate-500">Frequently used</span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <a href="lesson-plan.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                            <i class="fas fa-plus-circle text-teal-700"></i>
                            Create lesson plan
                        </a>
                        <a href="class_attendance.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                            <i class="fas fa-calendar-check text-teal-700"></i>
                            Mark attendance
                        </a>
                        <a href="results.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                            <i class="fas fa-chart-bar text-teal-700"></i>
                            Enter results
                        </a>
                        <a href="teacher_class_activities.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                            <i class="fas fa-tasks text-teal-700"></i>
                            Class activities
                        </a>
                        <a href="permissions.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                            <i class="fas fa-key text-teal-700"></i>
                            Request permission
                        </a>
                        <a href="timebook.php" class="flex items-center gap-3 rounded-xl border border-ink-900/10 px-4 py-3 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10">
                            <i class="fas fa-clock text-teal-700"></i>
                            Time book
                        </a>
                    </div>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-ink-900">Recent Activity</h2>
                    <a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="#">View all</a>
                </div>
                <div class="space-y-4">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                            <i class="fas fa-check"></i>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-ink-900">Lesson plan approved for Mathematics</p>
                            <p class="text-xs text-slate-500">Today, 10:30 AM</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-amber-500/10 text-amber-500">
                            <i class="fas fa-chart-line"></i>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-ink-900">Results submitted for Biology class</p>
                            <p class="text-xs text-slate-500">Yesterday, 3:45 PM</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700">
                            <i class="fas fa-calendar-check"></i>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-ink-900">Attendance marked for JSS 1A</p>
                            <p class="text-xs text-slate-500">2 days ago</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-slate-500/10 text-slate-600">
                            <i class="fas fa-question-circle"></i>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-ink-900">New questions added to Mathematics bank</p>
                            <p class="text-xs text-slate-500">3 days ago</p>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <?php include '../includes/floating-button.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        const analyticsData = <?php echo json_encode($analytics_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

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

        const closeSidebar = () => {
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
                    closeSidebar();
                }
            });
        }

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        if (sidebar) {
            sidebar.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', closeSidebar);
            });
        }

        const toggleChartState = (canvasId, emptyId, hasData) => {
            const canvas = document.getElementById(canvasId);
            const emptyState = document.getElementById(emptyId);
            if (!canvas || !emptyState) {
                return;
            }

            if (hasData) {
                canvas.classList.remove('hidden');
                emptyState.classList.add('hidden');
                emptyState.classList.remove('flex');
                return;
            }

            canvas.classList.add('hidden');
            emptyState.classList.remove('hidden');
            emptyState.classList.add('flex');
        };

        const initializeAnalyticsCharts = () => {
            if (typeof Chart === 'undefined') {
                return;
            }

            Chart.defaults.color = '#475569';
            Chart.defaults.font.family = "'Manrope', sans-serif";
            const gridColor = 'rgba(148, 163, 184, 0.25)';

            const genderValues = [
                Number(analyticsData.gender?.female || 0),
                Number(analyticsData.gender?.male || 0),
                Number(analyticsData.gender?.other || 0),
            ];
            const hasGenderData = genderValues.some((value) => value > 0);
            toggleChartState('genderDistributionChart', 'genderDistributionChartEmpty', hasGenderData);
            if (hasGenderData) {
                const ctx = document.getElementById('genderDistributionChart');
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Female', 'Male', 'Other'],
                        datasets: [{
                            data: genderValues,
                            backgroundColor: ['#f97316', '#0ea5e9', '#64748b'],
                            borderWidth: 0,
                        }],
                    },
                    options: {
                        maintainAspectRatio: false,
                        cutout: '62%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    boxWidth: 12,
                                    boxHeight: 12,
                                },
                            },
                        },
                    },
                });
            }

            const attendancePresent = (analyticsData.attendance?.present || []).map((value) => Number(value || 0));
            const attendanceLate = (analyticsData.attendance?.late || []).map((value) => Number(value || 0));
            const attendanceAbsent = (analyticsData.attendance?.absent || []).map((value) => Number(value || 0));
            const attendanceLabels = analyticsData.attendance?.labels || [];
            const attendancePoints = [...attendancePresent, ...attendanceLate, ...attendanceAbsent];
            const hasAttendanceData = attendancePoints.some((value) => value > 0);
            toggleChartState('attendanceTrendChart', 'attendanceTrendChartEmpty', hasAttendanceData);
            if (hasAttendanceData) {
                const ctx = document.getElementById('attendanceTrendChart');
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: attendanceLabels,
                        datasets: [
                            {
                                label: 'Present',
                                data: attendancePresent,
                                backgroundColor: '#14b8a6',
                                borderRadius: 6,
                                maxBarThickness: 34,
                            },
                            {
                                label: 'Late',
                                data: attendanceLate,
                                backgroundColor: '#f59e0b',
                                borderRadius: 6,
                                maxBarThickness: 34,
                            },
                            {
                                label: 'Absent',
                                data: attendanceAbsent,
                                backgroundColor: '#ef4444',
                                borderRadius: 6,
                                maxBarThickness: 34,
                            },
                        ],
                    },
                    options: {
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        scales: {
                            x: {
                                stacked: true,
                                grid: {
                                    display: false,
                                },
                            },
                            y: {
                                stacked: true,
                                beginAtZero: true,
                                ticks: {
                                    precision: 0,
                                },
                                grid: {
                                    color: gridColor,
                                },
                            },
                        },
                        plugins: {
                            legend: {
                                position: 'bottom',
                            },
                        },
                    },
                });
            }

            const resultLabels = analyticsData.results?.labels || [];
            const resultAverages = (analyticsData.results?.average || []).map((value) => Number(value || 0));
            const resultEntries = (analyticsData.results?.entries || []).map((value) => Number(value || 0));
            const hasResultData = resultEntries.some((value) => value > 0);
            toggleChartState('resultPerformanceChart', 'resultPerformanceChartEmpty', hasResultData);
            if (hasResultData) {
                const ctx = document.getElementById('resultPerformanceChart');
                new Chart(ctx, {
                    data: {
                        labels: resultLabels,
                        datasets: [
                            {
                                type: 'bar',
                                label: 'Result Entries',
                                data: resultEntries,
                                yAxisID: 'yEntries',
                                backgroundColor: 'rgba(14, 165, 233, 0.35)',
                                borderColor: '#0284c7',
                                borderWidth: 1,
                                borderRadius: 8,
                                maxBarThickness: 44,
                            },
                            {
                                type: 'line',
                                label: 'Average Score',
                                data: resultAverages,
                                yAxisID: 'yScore',
                                borderColor: '#0f766e',
                                backgroundColor: 'rgba(15, 118, 110, 0.16)',
                                borderWidth: 2,
                                tension: 0.32,
                                fill: true,
                                pointRadius: 4,
                                pointHoverRadius: 5,
                            },
                        ],
                    },
                    options: {
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        scales: {
                            yScore: {
                                type: 'linear',
                                position: 'left',
                                beginAtZero: true,
                                suggestedMax: 100,
                                grid: {
                                    color: gridColor,
                                },
                                ticks: {
                                    callback: (value) => `${value}`,
                                },
                            },
                            yEntries: {
                                type: 'linear',
                                position: 'right',
                                beginAtZero: true,
                                grid: {
                                    drawOnChartArea: false,
                                },
                                ticks: {
                                    precision: 0,
                                },
                            },
                            x: {
                                grid: {
                                    display: false,
                                },
                            },
                        },
                        plugins: {
                            legend: {
                                position: 'bottom',
                            },
                        },
                    },
                });
            }

            const lessonLabels = analyticsData.lesson_plans?.labels || [];
            const lessonValues = (analyticsData.lesson_plans?.values || []).map((value) => Number(value || 0));
            const hasLessonData = lessonValues.some((value) => value > 0);
            toggleChartState('lessonPipelineChart', 'lessonPipelineChartEmpty', hasLessonData);
            if (hasLessonData) {
                const ctx = document.getElementById('lessonPipelineChart');
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: lessonLabels,
                        datasets: [{
                            label: 'Plans',
                            data: lessonValues,
                            backgroundColor: ['#14b8a6', '#f59e0b', '#ef4444', '#64748b'],
                            borderRadius: 8,
                            maxBarThickness: 20,
                        }],
                    },
                    options: {
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0,
                                },
                                grid: {
                                    color: gridColor,
                                },
                            },
                            y: {
                                grid: {
                                    display: false,
                                },
                            },
                        },
                        plugins: {
                            legend: {
                                display: false,
                            },
                        },
                    },
                });
            }
        };

        initializeAnalyticsCharts();
    </script>
</body>
</html>
