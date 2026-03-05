<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$allowed_roles = ['teacher', 'principal'];
if (!isset($_SESSION['user_id']) || !in_array(strtolower((string) ($_SESSION['role'] ?? '')), $allowed_roles, true)) {
    header('Location: ../index.php');
    exit();
}

$current_school_id = require_school_auth();
$teacher_id = (int) ($_SESSION['user_id'] ?? 0);
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';
$current_date = date('Y-m-d');
$selected_date = $_GET['date'] ?? $current_date;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = $current_date;
}

$assigned_classes_sql = "
    SELECT c.id, c.class_name
    FROM class_teachers ct
    JOIN classes c ON ct.class_id = c.id
    WHERE ct.teacher_id = :teacher_id
      AND c.school_id = :school_id
    ORDER BY c.class_name
";
$assigned_stmt = $pdo->prepare($assigned_classes_sql);
$assigned_stmt->execute([
    ':teacher_id' => $teacher_id,
    ':school_id' => $current_school_id,
]);
$assigned_classes = $assigned_stmt->fetchAll(PDO::FETCH_ASSOC);

$assigned_class_ids = array_map('intval', array_column($assigned_classes, 'id'));
$assigned_classes_map = [];
foreach ($assigned_classes as $assigned_class) {
    $assigned_classes_map[(int) $assigned_class['id']] = $assigned_class['class_name'];
}

$selected_class = 0;
if (!empty($assigned_class_ids)) {
    $requested_class = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
    $selected_class = in_array($requested_class, $assigned_class_ids, true) ? $requested_class : $assigned_class_ids[0];
}

$status_options = [
    'present' => [
        'label' => 'Present',
        'short' => 'P',
        'icon' => 'fa-check',
        'summary_class' => 'bg-emerald-100 text-emerald-700',
        'accent' => '16, 185, 129',
    ],
    'absent' => [
        'label' => 'Absent',
        'short' => 'A',
        'icon' => 'fa-xmark',
        'summary_class' => 'bg-rose-100 text-rose-700',
        'accent' => '225, 29, 72',
    ],
    'late' => [
        'label' => 'Late',
        'short' => 'L',
        'icon' => 'fa-clock',
        'summary_class' => 'bg-amber-100 text-amber-700',
        'accent' => '217, 119, 6',
    ],
    'leave' => [
        'label' => 'Leave',
        'short' => 'LV',
        'icon' => 'fa-envelope',
        'summary_class' => 'bg-fuchsia-100 text-fuchsia-700',
        'accent' => '162, 28, 175',
    ],
];

$stats = [
    'total_students' => 0,
    'present_count' => 0,
    'absent_count' => 0,
    'late_count' => 0,
    'leave_count' => 0,
];
$students = [];
$monthly_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $attendance_date = $_POST['attendance_date'] ?? '';
    $class_id = (int) ($_POST['class_id'] ?? 0);
    $allowed_statuses = array_keys($status_options);

    if (empty($assigned_class_ids)) {
        $_SESSION['error'] = 'No class is assigned to this account yet.';
        header('Location: class_attendance.php');
        exit();
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendance_date)) {
        $_SESSION['error'] = 'Invalid attendance date format.';
        header('Location: class_attendance.php');
        exit();
    }

    if (!in_array($class_id, $assigned_class_ids, true)) {
        $_SESSION['error'] = 'You are not authorized to submit attendance for this class.';
        header('Location: class_attendance.php');
        exit();
    }

    try {
        $pdo->beginTransaction();

        $student_ids_stmt = $pdo->prepare("
            SELECT id
            FROM students
            WHERE class_id = :class_id
              AND school_id = :school_id
        ");
        $student_ids_stmt->execute([
            ':class_id' => $class_id,
            ':school_id' => $current_school_id,
        ]);
        $valid_student_ids = array_map('intval', array_column($student_ids_stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
        $valid_student_lookup = array_flip($valid_student_ids);

        $insert_count = 0;
        $update_count = 0;
        $posted_attendance = $_POST['attendance'] ?? [];

        foreach ($posted_attendance as $student_id => $status) {
            $student_id = (int) $student_id;
            if (!isset($valid_student_lookup[$student_id])) {
                continue;
            }

            if (!in_array($status, $allowed_statuses, true)) {
                $status = 'absent';
            }

            $remarks = trim((string) ($_POST['remarks'][$student_id] ?? ''));

            $check_stmt = $pdo->prepare("
                SELECT id
                FROM attendance
                WHERE student_id = :student_id
                  AND date = :attendance_date
                  AND school_id = :school_id
            ");
            $check_stmt->execute([
                ':student_id' => $student_id,
                ':attendance_date' => $attendance_date,
                ':school_id' => $current_school_id,
            ]);
            $existing_record = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_record) {
                $attendance_sql = "
                    UPDATE attendance
                    SET status = :status,
                        recorded_by = :recorded_by,
                        notes = :notes,
                        recorded_at = NOW()
                    WHERE student_id = :student_id
                      AND date = :attendance_date
                      AND school_id = :school_id
                ";
                $update_count += 1;
            } else {
                $attendance_sql = "
                    INSERT INTO attendance (
                        student_id, class_id, date, status, recorded_by, notes, school_id, recorded_at
                    ) VALUES (
                        :student_id, :class_id, :attendance_date, :status, :recorded_by, :notes, :school_id, NOW()
                    )
                ";
                $insert_count += 1;
            }

            $attendance_stmt = $pdo->prepare($attendance_sql);
            $attendance_stmt->execute([
                ':student_id' => $student_id,
                ':class_id' => $class_id,
                ':attendance_date' => $attendance_date,
                ':status' => $status,
                ':recorded_by' => $teacher_id,
                ':notes' => $remarks,
                ':school_id' => $current_school_id,
            ]);
        }

        $pdo->commit();
        $_SESSION['success'] = "Attendance submitted successfully. {$insert_count} new and {$update_count} updated.";
        header('Location: class_attendance.php?date=' . urlencode($attendance_date) . '&class_id=' . $class_id);
        exit();
    } catch (Exception $exception) {
        $pdo->rollBack();
        error_log('ATTENDANCE ERROR: ' . $exception->getMessage());
        $_SESSION['error'] = 'Attendance could not be saved right now.';
        header('Location: class_attendance.php?date=' . urlencode($attendance_date) . '&class_id=' . $class_id);
        exit();
    }
}

if ($selected_class > 0) {
    $students_stmt = $pdo->prepare("
        SELECT s.id, s.full_name, s.admission_no, a.status, a.notes
        FROM students s
        LEFT JOIN attendance a
            ON s.id = a.student_id
           AND a.date = :selected_date
           AND a.school_id = :attendance_school_id
        WHERE s.class_id = :class_id
          AND s.school_id = :student_school_id
        ORDER BY s.full_name
    ");
    $students_stmt->execute([
        ':selected_date' => $selected_date,
        ':class_id' => $selected_class,
        ':attendance_school_id' => $current_school_id,
        ':student_school_id' => $current_school_id,
    ]);
    $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats_stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT s.id) AS total_students,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN a.status = 'leave' THEN 1 ELSE 0 END) AS leave_count
        FROM students s
        LEFT JOIN attendance a
            ON s.id = a.student_id
           AND a.date = :selected_date
           AND a.school_id = :attendance_school_id
        WHERE s.class_id = :class_id
          AND s.school_id = :student_school_id
    ");
    $stats_stmt->execute([
        ':selected_date' => $selected_date,
        ':class_id' => $selected_class,
        ':attendance_school_id' => $current_school_id,
        ':student_school_id' => $current_school_id,
    ]);
    $raw_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats = [
        'total_students' => (int) ($raw_stats['total_students'] ?? 0),
        'present_count' => (int) ($raw_stats['present_count'] ?? 0),
        'absent_count' => (int) ($raw_stats['absent_count'] ?? 0),
        'late_count' => (int) ($raw_stats['late_count'] ?? 0),
        'leave_count' => (int) ($raw_stats['leave_count'] ?? 0),
    ];

    $monthly_stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(date, '%Y-%m') AS month,
            COUNT(*) AS total_days,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present_days,
            AVG(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100 AS attendance_rate
        FROM attendance
        WHERE school_id = :attendance_school_id
          AND student_id IN (
              SELECT id
              FROM students
              WHERE class_id = :class_id
                AND school_id = :student_school_id
          )
          AND date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
        GROUP BY DATE_FORMAT(date, '%Y-%m')
        ORDER BY month DESC
    ");
    $monthly_stmt->execute([
        ':class_id' => $selected_class,
        ':attendance_school_id' => $current_school_id,
        ':student_school_id' => $current_school_id,
    ]);
    $monthly_data = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$attendance_rate = $stats['total_students'] > 0
    ? round(($stats['present_count'] / $stats['total_students']) * 100, 1)
    : 0;
$recorded_count = $stats['present_count'] + $stats['absent_count'] + $stats['late_count'] + $stats['leave_count'];
$pending_count = max($stats['total_students'] - $recorded_count, 0);
$completion_rate = $stats['total_students'] > 0
    ? round(($recorded_count / $stats['total_students']) * 100, 1)
    : 0;
$selected_class_name = $selected_class > 0 ? ($assigned_classes_map[$selected_class] ?? 'Assigned class') : 'No class assigned';
$selected_day_label = date('l, j F Y', strtotime($selected_date));
$success_message = $_SESSION['success'] ?? '';
$error_message = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Attendance | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        [data-sidebar] { overflow: hidden; }
        .sidebar-scroll-shell { height: 100%; overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior-y: contain; touch-action: pan-y; padding-bottom: max(1rem, env(safe-area-inset-bottom)); }
        .attendance-page { overflow-x: hidden; }
        .attendance-page .container,
        .attendance-page main,
        .attendance-page section,
        .attendance-page article,
        .attendance-page form,
        .attendance-page label,
        .attendance-page div { min-width: 0; }
        .attendance-page .container { padding-left: 1rem; padding-right: 1rem; }
        .attendance-page .nav-wrap {
            align-items: center;
            flex-wrap: nowrap;
            gap: 0.85rem;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
        }
        .attendance-page .nav-wrap > :first-child { min-width: 0; flex: 1 1 auto; }
        .subject-header-actions {
            width: auto;
            display: flex;
            align-items: center;
            flex: 0 0 auto;
            flex-wrap: nowrap;
            gap: 0.75rem;
        }
        .subject-header-actions .btn {
            width: auto;
            justify-content: center;
            padding: 0.5rem 0.85rem;
            font-size: 0.8125rem;
        }
        .subject-hero {
            padding: 1.25rem;
            background:
                radial-gradient(circle at top right, rgba(248, 198, 102, 0.24), transparent 30%),
                linear-gradient(135deg, #0f766e 0%, #0f766e 18%, #0f172a 100%);
        }
        .subject-hero,
        .subject-hero h1,
        .subject-hero h2,
        .subject-hero h3,
        .subject-hero p { color: #fff; }
        .subject-hero h1 { font-size: 1.95rem; line-height: 1.15; }
        .subject-hero-actions { grid-template-columns: 1fr; }
        .attendance-page .subject-hero-actions > * { min-width: 0; }
        .subject-metrics-grid { grid-template-columns: 1fr; }
        .subject-metric-card {
            display: grid;
            gap: 0.45rem;
            border: 1px solid rgba(15, 31, 45, 0.06);
            border-radius: 1.35rem;
            background: #f8fbfb;
            padding: 1rem;
            box-shadow: 0 10px 24px rgba(15, 31, 45, 0.06);
        }
        .subject-metric-icon {
            display: inline-flex;
            width: 3rem;
            height: 3rem;
            align-items: center;
            justify-content: center;
            border-radius: 1rem;
            margin-bottom: 0.25rem;
        }
        .attendance-table-wrapper {
            overflow-x: auto;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .attendance-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
        }
        .table-scroll-hint { display: none; }
        .status-toggle {
            display: inline-flex;
            min-width: 2.8rem;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            border-radius: 9999px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: #fff;
            padding: 0.55rem 0.8rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: #475569;
            transition: all 0.18s ease;
        }
        .status-toggle:hover {
            transform: translateY(-1px);
            border-color: rgba(15, 118, 110, 0.35);
        }
        .status-toggle.is-active {
            border-color: rgba(var(--status-accent), 0.55);
            background: rgba(var(--status-accent), 0.12);
            color: rgb(var(--status-accent));
            box-shadow: inset 0 0 0 1px rgba(var(--status-accent), 0.12);
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border-radius: 9999px;
            padding: 0.45rem 0.8rem;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .history-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(15, 23, 42, 0.56);
            backdrop-filter: blur(8px);
            z-index: 60;
        }
        .history-modal.is-open { display: flex; }
        .history-modal-card {
            width: min(720px, 100%);
            max-height: 88vh;
            overflow: auto;
        }
        .history-loading {
            display: grid;
            gap: 0.85rem;
            justify-items: center;
            padding: 2rem 1rem;
            color: #64748b;
        }
        .history-spinner {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 9999px;
            border: 3px solid rgba(15, 118, 110, 0.12);
            border-top-color: #0f766e;
            animation: spin 0.9s linear infinite;
        }
        .history-list { display: grid; gap: 0.85rem; }
        .history-row {
            display: grid;
            gap: 0.85rem;
            border-radius: 1.1rem;
            border: 1px solid rgba(15, 31, 45, 0.08);
            background: #f8fafc;
            padding: 1rem;
        }
        .history-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .history-empty {
            border-radius: 1.25rem;
            border: 1px dashed rgba(148, 163, 184, 0.7);
            background: #f8fafc;
            padding: 1.5rem;
            text-align: center;
            color: #64748b;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @media (max-width: 767px) {
            .attendance-page .container { padding-left: 1rem; padding-right: 1rem; }
            main > section,
            .history-modal-card,
            #attendance-register > div { border-radius: 1.25rem !important; }
            .attendance-table-wrapper {
                max-height: min(64vh, 30rem);
                border-radius: 1rem;
                border: 1px solid rgba(15, 23, 42, 0.08);
                background: #fff;
            }
            .attendance-table { min-width: 1040px !important; border-collapse: separate; border-spacing: 0; }
            .table-scroll-hint {
                display: flex;
                align-items: center;
                gap: 0.45rem;
                margin-top: 0.75rem;
                font-size: 0.75rem;
                font-weight: 700;
                color: #64748b;
            }
        }
        @media (max-width: 640px) {
            .history-modal { padding: 0.75rem; }
            .rounded-3xl,
            .rounded-2xl { border-radius: 1rem !important; }
            .attendance-page .nav-wrap {
                flex-wrap: wrap;
                align-items: flex-start;
                gap: 0.65rem;
            }
            .attendance-page .nav-wrap > :first-child {
                flex: 1 1 100%;
            }
            .subject-header-actions {
                width: 100%;
                justify-content: flex-start;
                gap: 0.5rem;
            }
            .subject-header-actions > span { display: none; }
            .subject-header-actions .btn {
                display: inline-flex;
                padding: 0.45rem 0.72rem;
                font-size: 0.78rem;
            }
        }
        @media (min-width: 640px) {
            .attendance-page .container { padding-left: 1.25rem; padding-right: 1.25rem; }
            .subject-hero { padding: 1.5rem 2rem; }
            .subject-hero-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .subject-metrics-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 768px) {
            .subject-hero h1 { font-size: 2.4rem; }
        }
        @media (min-width: 1024px) {
            .attendance-page .nav-wrap {
                gap: 1rem;
                padding-top: 1rem;
                padding-bottom: 1rem;
            }
            .subject-header-actions .btn {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }
            .subject-hero { padding: 1.75rem 2rem; }
        }
        @media (min-width: 1200px) {
            .attendance-page .container { padding-left: 2.75rem; padding-right: 2.75rem; }
        }
        @media (min-width: 1280px) {
            .subject-metrics-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        }
    </style>
</head>
<body class="landing bg-slate-50 attendance-page">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="h-10 w-10 shrink-0 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Teacher Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="subject-header-actions flex items-center gap-3">
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

    <div class="container grid gap-6 py-8 lg:grid-cols-[280px_1fr]">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 -translate-x-full transform border-r border-ink-900/10 bg-white shadow-lift transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell">
                <?php include '../includes/teacher_sidebar.php'; ?>
            </div>
        </aside>

        <main class="space-y-6">
            <section class="overflow-hidden rounded-3xl border border-ink-900/5 shadow-lift" data-reveal>
                <div class="subject-hero p-6 text-white sm:p-8">
                    <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                        <div class="max-w-3xl">
                            <p class="text-xs uppercase tracking-[0.32em] text-white/75">Attendance Workspace</p>
                            <h1 class="mt-3 text-3xl font-display font-semibold leading-tight sm:text-4xl">Class attendance with the same clear teacher workspace pattern used across the assessment pages.</h1>
                            <p class="mt-3 max-w-2xl text-sm text-white/80 sm:text-base">Load the daily register, mark the whole class quickly, review monthly attendance rhythm, and keep every attendance action inside one cleaner layout.</p>
                        </div>
                        <div class="subject-hero-actions grid gap-3 sm:grid-cols-2">
                            <a href="#attendance-filters" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-teal-900 shadow-soft transition hover:-translate-y-0.5 hover:bg-white/95">
                                <i class="fas fa-calendar-day"></i>
                                <span>Load Register</span>
                            </a>
                            <button type="button" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15" onclick="markAllStatus('present')" <?php echo empty($students) ? 'disabled' : ''; ?>>
                                <i class="fas fa-check-circle"></i>
                                <span>Mark All Present</span>
                            </button>
                            <button type="button" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15" onclick="printAttendance()" <?php echo empty($students) ? 'disabled' : ''; ?>>
                                <i class="fas fa-print"></i>
                                <span>Print Report</span>
                            </button>
                            <a href="students.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/25 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-white/15">
                                <i class="fas fa-user-group"></i>
                                <span>Open Students</span>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="subject-metrics-grid grid gap-3 bg-white p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-teal-600/10 text-teal-700">
                            <i class="fas fa-chalkboard"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Active Class</p>
                        <h2 class="mt-1 text-xl font-semibold text-ink-900"><?php echo htmlspecialchars($selected_class_name); ?></h2>
                        <p class="text-sm text-slate-500">Register currently in view</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-sky-100 text-sky-700">
                            <i class="fas fa-users"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Students</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $stats['total_students']; ?></h2>
                        <p class="text-sm text-slate-500">Students in the selected register</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-emerald-600/10 text-emerald-700">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Present</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $stats['present_count']; ?></h2>
                        <p class="text-sm text-slate-500">Recorded as present for this date</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-rose-100 text-rose-700">
                            <i class="fas fa-user-xmark"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Absent</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $stats['absent_count']; ?></h2>
                        <p class="text-sm text-slate-500">Recorded absences for this date</p>
                    </article>
                    <article class="subject-metric-card">
                        <div class="subject-metric-icon bg-amber-500/10 text-amber-700">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Completion</p>
                        <h2 class="mt-1 text-2xl font-semibold text-ink-900"><?php echo $completion_rate; ?>%</h2>
                        <p class="text-sm text-slate-500">Students with a recorded attendance entry</p>
                    </article>
                </div>
            </section>
            <?php if ($success_message !== ''): ?>
                <section class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900 shadow-soft" data-reveal>
                    <p class="font-semibold">Attendance updated</p>
                    <p class="mt-1 text-sm"><?php echo htmlspecialchars($success_message); ?></p>
                </section>
            <?php endif; ?>

            <?php if ($error_message !== ''): ?>
                <section class="rounded-3xl border border-red-200 bg-red-50 p-5 text-red-800 shadow-soft" data-reveal>
                    <p class="font-semibold">Action required</p>
                    <p class="mt-1 text-sm"><?php echo htmlspecialchars($error_message); ?></p>
                </section>
            <?php endif; ?>

            <?php if (empty($assigned_class_ids)): ?>
                <section class="rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center shadow-soft" data-reveal>
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
                        <i class="fas fa-calendar-xmark text-xl"></i>
                    </div>
                    <h2 class="mt-4 text-2xl font-display text-ink-900">No class is assigned yet</h2>
                    <p class="mx-auto mt-3 max-w-2xl text-sm text-slate-600">This attendance workspace needs at least one class assignment before a register can be loaded. Once a class is linked to this account, the same daily attendance tools will appear here.</p>
                </section>
            <?php else: ?>
                <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5" id="attendance-filters" data-reveal>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Schedule and Filters</p>
                            <h2 class="mt-2 text-2xl font-display text-ink-900">Choose the attendance register</h2>
                            <p class="mt-2 text-sm text-slate-600">Load a teaching date and assigned class, then use the register below to save the final attendance state.</p>
                        </div>
                        <div class="rounded-2xl bg-mist-50 px-4 py-3 border border-ink-900/5">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Current Register</p>
                            <p class="text-sm font-semibold text-ink-900"><?php echo htmlspecialchars($selected_class_name); ?>, <?php echo htmlspecialchars($selected_day_label); ?></p>
                        </div>
                    </div>

                    <form method="GET" class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-[1.2fr_1fr_auto]">
                        <div>
                            <label for="date" class="mb-2 block text-sm font-semibold text-slate-700">Attendance Date</label>
                            <input id="date" name="date" type="date" value="<?php echo htmlspecialchars($selected_date); ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        </div>
                        <div>
                            <label for="class_id" class="mb-2 block text-sm font-semibold text-slate-700">Assigned Class</label>
                            <select id="class_id" name="class_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                <?php foreach ($assigned_classes_map as $class_id => $class_name): ?>
                                    <option value="<?php echo $class_id; ?>" <?php echo $selected_class === $class_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end gap-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-arrows-rotate"></i>
                                <span>Load Register</span>
                            </button>
                            <a href="class_attendance.php" class="btn btn-outline">
                                <i class="fas fa-calendar-week"></i>
                                <span>Today</span>
                            </a>
                        </div>
                    </form>
                </section>

                <section class="grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_340px]">
                    <div class="rounded-3xl bg-white shadow-lift border border-ink-900/5 overflow-hidden" id="attendance-register" data-reveal>
                        <div class="flex flex-col gap-4 border-b border-ink-900/5 p-6 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Daily Register</p>
                                <h2 class="mt-2 text-2xl font-display text-ink-900"><?php echo htmlspecialchars($selected_class_name); ?> attendance</h2>
                                <p class="mt-2 text-sm text-slate-600"><?php echo htmlspecialchars($selected_day_label); ?>. Use the quick actions first if most students share the same status, then correct the exceptions row by row.</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="inline-flex items-center gap-2 rounded-full bg-teal-50 px-4 py-2 text-sm font-semibold text-teal-700">
                                    <i class="fas fa-chart-pie"></i>
                                    <span>Attendance rate: <?php echo $attendance_rate; ?>%</span>
                                </span>
                                <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-600">
                                    <i class="fas fa-pen-to-square"></i>
                                    <span><?php echo $recorded_count; ?> recorded</span>
                                </span>
                            </div>
                        </div>

                        <div class="border-b border-ink-900/5 bg-slate-50/60 p-4">
                            <div class="flex flex-wrap gap-2 text-sm font-semibold text-slate-600">
                                <button type="button" class="rounded-full border border-emerald-200 bg-white px-4 py-2 hover:border-emerald-300 hover:text-emerald-700" onclick="markAllStatus('present')">
                                    <i class="fas fa-check-circle mr-2 text-emerald-700"></i>Mark all present
                                </button>
                                <button type="button" class="rounded-full border border-rose-200 bg-white px-4 py-2 hover:border-rose-300 hover:text-rose-700" onclick="markAllStatus('absent')">
                                    <i class="fas fa-xmark-circle mr-2 text-rose-700"></i>Mark all absent
                                </button>
                                <button type="button" class="rounded-full border border-amber-200 bg-white px-4 py-2 hover:border-amber-300 hover:text-amber-700" onclick="markAllStatus('late')">
                                    <i class="fas fa-clock mr-2 text-amber-700"></i>Mark all late
                                </button>
                                <button type="button" class="rounded-full border border-fuchsia-200 bg-white px-4 py-2 hover:border-fuchsia-300 hover:text-fuchsia-700" onclick="markAllStatus('leave')">
                                    <i class="fas fa-envelope mr-2 text-fuchsia-700"></i>Mark all leave
                                </button>
                                <button type="button" class="rounded-full border border-slate-200 bg-white px-4 py-2 hover:border-teal-200 hover:text-teal-700" onclick="printAttendance()">
                                    <i class="fas fa-print mr-2 text-teal-700"></i>Print register
                                </button>
                            </div>
                        </div>

                        <?php if (empty($students)): ?>
                            <div class="p-8 text-center">
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
                                    <i class="fas fa-user-graduate text-xl"></i>
                                </div>
                                <h3 class="mt-4 text-xl font-semibold text-ink-900">No students found for this class</h3>
                                <p class="mt-2 text-sm text-slate-600">The register is ready, but there are no students attached to this class yet.</p>
                            </div>
                        <?php else: ?>
                            <form method="POST" id="attendanceForm" data-offline-sync="1">
                                <input type="hidden" name="attendance_date" value="<?php echo htmlspecialchars($selected_date); ?>">
                                <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                                <input type="hidden" name="submit_attendance" value="1">

                                <div class="attendance-table-wrapper">
                                    <table class="attendance-table min-w-[1040px] w-full bg-white">
                                        <thead>
                                            <tr class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                                <th class="px-4 py-4">#</th>
                                                <th class="px-4 py-4">Admission No</th>
                                                <th class="px-4 py-4">Student</th>
                                                <th class="px-4 py-4">Current Status</th>
                                                <th class="px-4 py-4">Mark Attendance</th>
                                                <th class="px-4 py-4">Remarks</th>
                                                <th class="px-4 py-4">History</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $student_counter = 1; ?>
                                            <?php foreach ($students as $student): ?>
                                                <?php $current_status = $student['status'] ?: 'absent'; ?>
                                                <tr class="border-t border-slate-100 align-top" data-student-row="<?php echo (int) $student['id']; ?>">
                                                    <td class="px-4 py-4 text-sm font-semibold text-slate-500"><?php echo $student_counter++; ?></td>
                                                    <td class="px-4 py-4">
                                                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><?php echo htmlspecialchars($student['admission_no'] ?: 'N/A'); ?></span>
                                                    </td>
                                                    <td class="px-4 py-4">
                                                        <div class="flex flex-col gap-1">
                                                            <span class="font-semibold text-ink-900 student-name"><?php echo htmlspecialchars($student['full_name']); ?></span>
                                                            <span class="text-sm text-slate-500">Ready for daily attendance update</span>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-4">
                                                        <span class="status-pill <?php echo $status_options[$current_status]['summary_class']; ?>" data-status-label data-status="<?php echo htmlspecialchars($current_status); ?>">
                                                            <i class="fas <?php echo htmlspecialchars($status_options[$current_status]['icon']); ?>"></i>
                                                            <span><?php echo htmlspecialchars($status_options[$current_status]['label']); ?></span>
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-4">
                                                        <div class="flex flex-wrap gap-2">
                                                            <?php foreach ($status_options as $status_key => $status_meta): ?>
                                                                <button
                                                                    type="button"
                                                                    class="status-toggle <?php echo $current_status === $status_key ? 'is-active' : ''; ?>"
                                                                    data-student="<?php echo (int) $student['id']; ?>"
                                                                    data-status="<?php echo htmlspecialchars($status_key); ?>"
                                                                    style="--status-accent: <?php echo htmlspecialchars($status_meta['accent']); ?>"
                                                                >
                                                                    <i class="fas <?php echo htmlspecialchars($status_meta['icon']); ?>"></i>
                                                                    <span><?php echo htmlspecialchars($status_meta['short']); ?></span>
                                                                </button>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <input type="hidden" name="attendance[<?php echo (int) $student['id']; ?>]" id="status_<?php echo (int) $student['id']; ?>" value="<?php echo htmlspecialchars($current_status); ?>">
                                                    </td>
                                                    <td class="px-4 py-4">
                                                        <input
                                                            type="text"
                                                            name="remarks[<?php echo (int) $student['id']; ?>]"
                                                            value="<?php echo htmlspecialchars($student['notes'] ?? ''); ?>"
                                                            placeholder="Optional remarks"
                                                            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                                        >
                                                    </td>
                                                    <td class="px-4 py-4">
                                                        <button type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:border-teal-200 hover:text-teal-700" onclick="showStudentHistory(<?php echo (int) $student['id']; ?>)">
                                                            <i class="fas fa-history"></i>
                                                            <span>Open</span>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <p class="table-scroll-hint">
                                    <i class="fas fa-arrows-left-right"></i>
                                    <span>Swipe sideways to review the full attendance register on smaller screens.</span>
                                </p>

                                <div class="flex flex-col gap-4 border-t border-ink-900/5 bg-slate-50/60 p-6 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="text-sm text-slate-600">
                                        <p class="font-semibold text-ink-900">Recommended workflow</p>
                                        <p class="mt-1">Use a bulk status action first, then correct exceptions and submit once the register matches the class.</p>
                                    </div>
                                    <div class="flex flex-wrap gap-3">
                                        <button type="button" class="btn btn-outline" onclick="printAttendance()">
                                            <i class="fas fa-print"></i>
                                            <span>Print</span>
                                        </button>
                                        <button type="button" class="btn btn-primary" onclick="submitAttendance()">
                                            <i class="fas fa-save"></i>
                                            <span>Submit Attendance</span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="space-y-6">
                        <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5" data-reveal>
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Daily Snapshot</p>
                                    <h2 class="mt-2 text-2xl font-display text-ink-900">Status breakdown</h2>
                                </div>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><?php echo $pending_count; ?> pending</span>
                            </div>
                            <div class="mt-5 grid gap-3 text-sm">
                                <div class="flex items-center justify-between rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-3">
                                    <span class="flex items-center gap-2 text-slate-600"><i class="fas fa-check-circle text-emerald-700"></i>Present</span>
                                    <span class="font-semibold text-ink-900"><?php echo $stats['present_count']; ?></span>
                                </div>
                                <div class="flex items-center justify-between rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-3">
                                    <span class="flex items-center gap-2 text-slate-600"><i class="fas fa-xmark-circle text-rose-700"></i>Absent</span>
                                    <span class="font-semibold text-ink-900"><?php echo $stats['absent_count']; ?></span>
                                </div>
                                <div class="flex items-center justify-between rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-3">
                                    <span class="flex items-center gap-2 text-slate-600"><i class="fas fa-clock text-amber-700"></i>Late</span>
                                    <span class="font-semibold text-ink-900"><?php echo $stats['late_count']; ?></span>
                                </div>
                                <div class="flex items-center justify-between rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-3">
                                    <span class="flex items-center gap-2 text-slate-600"><i class="fas fa-envelope text-fuchsia-700"></i>Leave</span>
                                    <span class="font-semibold text-ink-900"><?php echo $stats['leave_count']; ?></span>
                                </div>
                                <div class="flex items-center justify-between rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-3">
                                    <span class="flex items-center gap-2 text-slate-600"><i class="fas fa-hourglass-half text-slate-500"></i>Pending</span>
                                    <span class="font-semibold text-ink-900"><?php echo $pending_count; ?></span>
                                </div>
                            </div>
                        </section>
                        <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5" data-reveal>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Quick Guidance</p>
                            <h2 class="mt-2 text-2xl font-display text-ink-900">Keep the register clean</h2>
                            <div class="mt-5 space-y-3 text-sm text-slate-600">
                                <div class="rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-4">
                                    <p class="font-semibold text-ink-900">1. Start with a bulk action</p>
                                    <p class="mt-1">If most learners share the same status, mark the whole class once and only adjust the exceptions.</p>
                                </div>
                                <div class="rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-4">
                                    <p class="font-semibold text-ink-900">2. Use remarks for exceptions</p>
                                    <p class="mt-1">Leave notes for absences, late arrivals, or approved leave so the daily context stays clear.</p>
                                </div>
                                <div class="rounded-2xl border border-ink-900/5 bg-mist-50 px-4 py-4">
                                    <p class="font-semibold text-ink-900">3. Review history before changing patterns</p>
                                    <p class="mt-1">Open a learner history row to compare against recent attendance before saving unusual updates.</p>
                                </div>
                            </div>
                            <div class="mt-5 grid gap-3 text-sm font-semibold">
                                <a href="students.php" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                    <span><i class="fas fa-user-group mr-3 text-teal-700"></i>Review student list</span>
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                                <a href="index.php" class="flex items-center justify-between rounded-2xl border border-ink-900/10 px-4 py-4 text-ink-900 hover:border-teal-600/30 hover:bg-teal-600/10 hover:text-teal-700">
                                    <span><i class="fas fa-house mr-3 text-teal-700"></i>Return to dashboard</span>
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        </section>

                        <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5" data-reveal>
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Trend View</p>
                                    <h2 class="mt-2 text-2xl font-display text-ink-900">Recent monthly attendance</h2>
                                </div>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Last 3 months</span>
                            </div>

                            <?php if (empty($monthly_data)): ?>
                                <div class="mt-5 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-500">
                                    Monthly attendance trends will appear here after attendance has been recorded for this class.
                                </div>
                            <?php else: ?>
                                <div class="mt-5 space-y-4">
                                    <?php foreach ($monthly_data as $month_row): ?>
                                        <?php
                                        $month_rate = round((float) ($month_row['attendance_rate'] ?? 0), 1);
                                        $month_label = date('M Y', strtotime(($month_row['month'] ?? date('Y-m')) . '-01'));
                                        ?>
                                        <article class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4">
                                            <div class="flex items-center justify-between gap-3">
                                                <div>
                                                    <p class="font-semibold text-ink-900"><?php echo htmlspecialchars($month_label); ?></p>
                                                    <p class="mt-1 text-sm text-slate-500"><?php echo (int) ($month_row['present_days'] ?? 0); ?> present records from <?php echo (int) ($month_row['total_days'] ?? 0); ?> entries</p>
                                                </div>
                                                <span class="rounded-full bg-teal-50 px-3 py-1 text-sm font-semibold text-teal-700"><?php echo $month_rate; ?>%</span>
                                            </div>
                                            <div class="mt-4 h-2 rounded-full bg-slate-200">
                                                <div class="h-2 rounded-full bg-gradient-to-r from-teal-700 via-emerald-600 to-sky-600" style="width: <?php echo max(0, min(100, $month_rate)); ?>%;"></div>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <div id="historyModal" class="history-modal" role="dialog" aria-modal="true" aria-labelledby="historyModalTitle">
        <div class="history-modal-card rounded-3xl border border-ink-900/5 bg-white p-6 shadow-lift">
            <div class="flex items-center justify-between gap-4 border-b border-ink-900/5 pb-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">Attendance History</p>
                    <h2 id="historyModalTitle" class="mt-2 text-2xl font-display text-ink-900">Student attendance timeline</h2>
                </div>
                <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700" onclick="closeHistoryModal()" aria-label="Close history">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div id="historyContent" class="pt-5">
                <div class="history-loading">
                    <div class="history-spinner"></div>
                    <p>Loading attendance history...</p>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/floating-button.php'; ?>
    <script>
        const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
        const sidebar = document.querySelector('[data-sidebar]');
        const overlay = document.querySelector('[data-sidebar-overlay]');
        const pageBody = document.body;
        const historyModal = document.getElementById('historyModal');
        const historyContent = document.getElementById('historyContent');
        const attendanceForm = document.getElementById('attendanceForm');
        const statusLabels = <?php echo json_encode(array_map(static function ($meta) {
            return [
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'summary_class' => $meta['summary_class'],
            ];
        }, $status_options), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const schoolName = <?php echo json_encode(get_school_display_name(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const selectedClassName = <?php echo json_encode($selected_class_name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const selectedDateLabel = <?php echo json_encode($selected_day_label, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const teacherName = <?php echo json_encode($teacher_name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        const openSidebar = () => {
            if (!sidebar || !overlay) return;
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('opacity-0', 'pointer-events-none');
            overlay.classList.add('opacity-100');
            pageBody.classList.add('nav-open');
        };

        const closeSidebarShell = () => {
            if (!sidebar || !overlay) return;
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('opacity-0', 'pointer-events-none');
            overlay.classList.remove('opacity-100');
            pageBody.classList.remove('nav-open');
        };

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => (
                sidebar.classList.contains('-translate-x-full') ? openSidebar() : closeSidebarShell()
            ));
        }

        if (overlay) {
            overlay.addEventListener('click', closeSidebarShell);
        }

        if (sidebar) {
            sidebar.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', closeSidebarShell);
            });
        }

        const updateStatusBadge = (studentId, status) => {
            const row = document.querySelector('[data-student-row="' + studentId + '"]');
            if (!row || !statusLabels[status]) return;
            const badge = row.querySelector('[data-status-label]');
            if (!badge) return;
            badge.className = 'status-pill ' + statusLabels[status].summary_class;
            badge.dataset.status = status;
            badge.innerHTML = '<i class="fas ' + statusLabels[status].icon + '"></i><span>' + statusLabels[status].label + '</span>';
        };

        const setStudentStatus = (studentId, status) => {
            const input = document.getElementById('status_' + studentId);
            if (!input) return;
            input.value = status;

            document.querySelectorAll('.status-toggle[data-student="' + studentId + '"]').forEach((button) => {
                button.classList.toggle('is-active', button.dataset.status === status);
            });

            updateStatusBadge(studentId, status);
        };

        document.querySelectorAll('.status-toggle').forEach((button) => {
            button.addEventListener('click', () => {
                setStudentStatus(button.dataset.student, button.dataset.status);
            });
        });

        function markAllStatus(status) {
            document.querySelectorAll('.status-toggle[data-status="' + status + '"]').forEach((button) => {
                setStudentStatus(button.dataset.student, status);
            });
        }

        function submitAttendance() {
            if (!attendanceForm) return;
            const confirmed = window.confirm('Submit attendance for ' + selectedDateLabel + '?');
            if (confirmed) {
                attendanceForm.requestSubmit();
            }
        }
        const escapeHtml = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        function printAttendance() {
            const rows = Array.from(document.querySelectorAll('[data-student-row]')).map((row, index) => {
                const statusInput = row.querySelector('input[name^="attendance["]');
                const remarksInput = row.querySelector('input[name^="remarks["]');
                const nameCell = row.querySelector('.student-name');
                const admissionCell = row.children[1];
                const status = statusInput ? statusInput.value : 'absent';
                const label = statusLabels[status] ? statusLabels[status].label : status;
                return {
                    index: index + 1,
                    admissionNo: admissionCell ? admissionCell.textContent.trim() : 'N/A',
                    studentName: nameCell ? nameCell.textContent.trim() : 'Student',
                    status,
                    label,
                    remarks: remarksInput && remarksInput.value.trim() !== '' ? remarksInput.value.trim() : '-',
                };
            });

            if (!rows.length) {
                window.alert('No attendance rows are available to print.');
                return;
            }

            const statusClass = {
                present: 'background:#dcfce7;color:#15803d;',
                absent: 'background:#ffe4e6;color:#be123c;',
                late: 'background:#fef3c7;color:#b45309;',
                leave: 'background:#fae8ff;color:#a21caf;'
            };

            const htmlRows = rows.map((row) => (
                '<tr>' +
                    '<td>' + row.index + '</td>' +
                    '<td>' + escapeHtml(row.admissionNo) + '</td>' +
                    '<td>' + escapeHtml(row.studentName) + '</td>' +
                    '<td><span style="display:inline-flex;border-radius:9999px;padding:6px 10px;font-weight:700;' + (statusClass[row.status] || '') + '">' + escapeHtml(row.label) + '</span></td>' +
                    '<td>' + escapeHtml(row.remarks) + '</td>' +
                '</tr>'
            )).join('');

            const printWindow = window.open('', '_blank', 'width=1100,height=800');
            if (!printWindow) return;

            printWindow.document.write(
                '<!DOCTYPE html><html><head><title>Attendance Report</title><style>' +
                'body{font-family:Manrope,Arial,sans-serif;padding:28px;background:#f8fafc;color:#0f172a;}' +
                '.card{background:#fff;border:1px solid rgba(15,31,45,.08);border-radius:24px;padding:24px;box-shadow:0 18px 40px rgba(15,31,45,.08);}' +
                '.hero{background:linear-gradient(135deg,#0f766e 0%,#0f766e 20%,#0f172a 100%);color:#fff;}' +
                '.meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:18px;}' +
                '.meta div{background:rgba(255,255,255,.1);border-radius:16px;padding:12px;}' +
                'table{width:100%;border-collapse:collapse;margin-top:24px;background:#fff;overflow:hidden;border-radius:20px;}' +
                'th,td{border:1px solid #e2e8f0;padding:12px;text-align:left;vertical-align:top;}' +
                'th{background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;}' +
                '.footer{margin-top:20px;font-size:12px;color:#64748b;}' +
                '</style></head><body>' +
                '<section class="card hero">' +
                '<p style="margin:0;font-size:12px;letter-spacing:.2em;text-transform:uppercase;opacity:.75;">Attendance Workspace</p>' +
                '<h1 style="margin:12px 0 0;font-size:32px;">Class attendance register</h1>' +
                '<p style="margin:12px 0 0;max-width:760px;opacity:.82;">Printed from the redesigned teacher attendance workspace.</p>' +
                '<div class="meta">' +
                '<div><strong>School</strong><br>' + escapeHtml(schoolName) + '</div>' +
                '<div><strong>Class</strong><br>' + escapeHtml(selectedClassName) + '</div>' +
                '<div><strong>Date</strong><br>' + escapeHtml(selectedDateLabel) + '</div>' +
                '<div><strong>Teacher</strong><br>' + escapeHtml(teacherName) + '</div>' +
                '</div>' +
                '</section>' +
                '<section class="card" style="margin-top:20px;">' +
                '<table><thead><tr><th>#</th><th>Admission No</th><th>Student</th><th>Status</th><th>Remarks</th></tr></thead><tbody>' + htmlRows + '</tbody></table>' +
                '<div class="footer">Generated on ' + new Date().toLocaleString() + '</div>' +
                '</section>' +
                '</body></html>'
            );
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
        }

        const renderHistoryLoading = () => {
            historyContent.innerHTML =
                '<div class="history-loading">' +
                    '<div class="history-spinner"></div>' +
                    '<p>Loading attendance history...</p>' +
                '</div>';
        };

        function showStudentHistory(studentId) {
            if (!historyModal || !historyContent) return;
            renderHistoryLoading();
            historyModal.classList.add('is-open');

            fetch('../ajax/get_student_history.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({ student_id: studentId }).toString(),
            })
                .then((response) => response.text())
                .then((markup) => {
                    historyContent.innerHTML = markup;
                })
                .catch(() => {
                    historyContent.innerHTML =
                        '<div class="history-empty">' +
                            '<p class="font-semibold text-ink-900">History could not be loaded</p>' +
                            '<p class="mt-2 text-sm">Try again in a moment.</p>' +
                        '</div>';
                });
        }

        function closeHistoryModal() {
            if (!historyModal) return;
            historyModal.classList.remove('is-open');
        }

        if (historyModal) {
            historyModal.addEventListener('click', (event) => {
                if (event.target === historyModal) {
                    closeHistoryModal();
                }
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeHistoryModal();
            }
        });

        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });

        document.querySelectorAll('[data-reveal]').forEach((element) => {
            revealObserver.observe(element);
        });
    </script>
</body>
</html>
