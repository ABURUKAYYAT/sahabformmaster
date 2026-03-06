<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header('Location: ../index.php');
    exit;
}

$principal_name = $_SESSION['full_name'];
$current_school_id = require_school_auth();

function admin_dashboard_int($value): int
{
    return (int) ($value ?? 0);
}

function admin_dashboard_float($value): float
{
    return (float) ($value ?? 0);
}

function admin_dashboard_currency_compact(float $amount): string
{
    $absolute = abs($amount);

    if ($absolute >= 1000000000) {
        return '?' . number_format($amount / 1000000000, 1) . 'B';
    }

    if ($absolute >= 1000000) {
        return '?' . number_format($amount / 1000000, 1) . 'M';
    }

    if ($absolute >= 1000) {
        return '?' . number_format($amount / 1000, 1) . 'K';
    }

    return '?' . number_format($amount, 0);
}

$total_students = 0;
$new_students = 0;
$total_teachers = 0;
$total_classes = 0;
$total_results = 0;
$pending_complaints = 0;
$unpaid_fees = 0;
$recent_activities = [];

$attendance_stats = [
    'present_count' => 0,
    'late_count' => 0,
    'absent_count' => 0,
    'total_records' => 0,
    'attendance_rate' => 0,
];

$fee_stats = [
    'total_collected' => 0,
    'total_outstanding' => 0,
    'payment_records' => 0,
];

$month_keys = [];
$month_labels = [];
$month_cursor = new DateTime('first day of this month');
$month_cursor->modify('-5 months');
for ($month_index = 0; $month_index < 6; $month_index++) {
    $month_keys[] = $month_cursor->format('Y-m');
    $month_labels[] = $month_cursor->format('M Y');
    $month_cursor->modify('+1 month');
}

$enrollment_series = array_fill(0, 6, 0);
$attendance_present_series = array_fill(0, 6, 0);
$attendance_late_series = array_fill(0, 6, 0);
$attendance_absent_series = array_fill(0, 6, 0);
$payment_collected_series = array_fill(0, 6, 0);
$payment_outstanding_series = array_fill(0, 6, 0);
$class_distribution_labels = [];
$class_distribution_values = [];

try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE is_active = 1 AND school_id = ?');
    $stmt->execute([$current_school_id]);
    $total_students = admin_dashboard_int($stmt->fetchColumn());
} catch (PDOException $exception) {
    error_log('Admin dashboard total_students error: ' . $exception->getMessage());
}

try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE school_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $stmt->execute([$current_school_id]);
    $new_students = admin_dashboard_int($stmt->fetchColumn());
} catch (PDOException $exception) {
    error_log('Admin dashboard new_students error: ' . $exception->getMessage());
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE school_id = ? AND is_active = 1 AND role IN ('teacher', 'principal')");
    $stmt->execute([$current_school_id]);
    $total_teachers = admin_dashboard_int($stmt->fetchColumn());
} catch (PDOException $exception) {
    error_log('Admin dashboard total_teachers error: ' . $exception->getMessage());
}

try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE school_id = ?');
    $stmt->execute([$current_school_id]);
    $total_classes = admin_dashboard_int($stmt->fetchColumn());
} catch (PDOException $exception) {
    error_log('Admin dashboard total_classes error: ' . $exception->getMessage());
}

try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM results WHERE school_id = ?');
    $stmt->execute([$current_school_id]);
    $total_results = admin_dashboard_int($stmt->fetchColumn());
} catch (PDOException $exception) {
    error_log('Admin dashboard total_results error: ' . $exception->getMessage());
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'present' THEN 1 ELSE 0 END), 0) AS present_count,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'late' THEN 1 ELSE 0 END), 0) AS late_count,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'absent' THEN 1 ELSE 0 END), 0) AS absent_count
        FROM attendance
        WHERE school_id = ?
          AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
    );
    $stmt->execute([$current_school_id]);
    $attendance_summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $attendance_stats['present_count'] = admin_dashboard_int($attendance_summary['present_count'] ?? 0);
    $attendance_stats['late_count'] = admin_dashboard_int($attendance_summary['late_count'] ?? 0);
    $attendance_stats['absent_count'] = admin_dashboard_int($attendance_summary['absent_count'] ?? 0);
    $attendance_stats['total_records'] = $attendance_stats['present_count'] + $attendance_stats['late_count'] + $attendance_stats['absent_count'];
    $attendance_stats['attendance_rate'] = $attendance_stats['total_records'] > 0
        ? round(($attendance_stats['present_count'] / $attendance_stats['total_records']) * 100, 1)
        : 0;
} catch (PDOException $exception) {
    error_log('Admin dashboard attendance_stats error: ' . $exception->getMessage());
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'completed' THEN amount_paid ELSE 0 END), 0) AS total_collected,
            COALESCE(SUM(GREATEST(total_amount - amount_paid, 0)), 0) AS total_outstanding,
            COUNT(*) AS payment_records
        FROM student_payments
        WHERE school_id = ?"
    );
    $stmt->execute([$current_school_id]);
    $payment_summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $fee_stats['total_collected'] = admin_dashboard_float($payment_summary['total_collected'] ?? 0);
    $fee_stats['total_outstanding'] = admin_dashboard_float($payment_summary['total_outstanding'] ?? 0);
    $fee_stats['payment_records'] = admin_dashboard_int($payment_summary['payment_records'] ?? 0);
} catch (PDOException $exception) {
    error_log('Admin dashboard fee_stats error: ' . $exception->getMessage());
}
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM results_complaints WHERE school_id = ? AND status = 'pending'");
    $stmt->execute([$current_school_id]);
    $pending_complaints = admin_dashboard_int($stmt->fetchColumn());
} catch (PDOException $exception) {
    error_log('Admin dashboard pending_complaints error: ' . $exception->getMessage());
}

try {
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT student_id) FROM student_payments WHERE school_id = ? AND total_amount > amount_paid');
    $stmt->execute([$current_school_id]);
    $unpaid_fees = admin_dashboard_int($stmt->fetchColumn());
} catch (PDOException $exception) {
    error_log('Admin dashboard unpaid_fees error: ' . $exception->getMessage());
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            DATE_FORMAT(created_at, '%Y-%m') AS month_key,
            COUNT(*) AS total_students
        FROM students
        WHERE school_id = ?
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY month_key
        ORDER BY month_key ASC"
    );
    $stmt->execute([$current_school_id]);
    $enrollment_index = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $enrollment_index[$row['month_key']] = admin_dashboard_int($row['total_students'] ?? 0);
    }
    foreach ($month_keys as $month_position => $month_key) {
        $enrollment_series[$month_position] = $enrollment_index[$month_key] ?? 0;
    }
} catch (PDOException $exception) {
    error_log('Admin dashboard enrollment_series error: ' . $exception->getMessage());
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            DATE_FORMAT(date, '%Y-%m') AS month_key,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'present' THEN 1 ELSE 0 END), 0) AS present_count,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'late' THEN 1 ELSE 0 END), 0) AS late_count,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'absent' THEN 1 ELSE 0 END), 0) AS absent_count
        FROM attendance
        WHERE school_id = ?
          AND date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY month_key
        ORDER BY month_key ASC"
    );
    $stmt->execute([$current_school_id]);
    $attendance_index = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $attendance_index[$row['month_key']] = [
            'present' => admin_dashboard_int($row['present_count'] ?? 0),
            'late' => admin_dashboard_int($row['late_count'] ?? 0),
            'absent' => admin_dashboard_int($row['absent_count'] ?? 0),
        ];
    }
    foreach ($month_keys as $month_position => $month_key) {
        $attendance_present_series[$month_position] = $attendance_index[$month_key]['present'] ?? 0;
        $attendance_late_series[$month_position] = $attendance_index[$month_key]['late'] ?? 0;
        $attendance_absent_series[$month_position] = $attendance_index[$month_key]['absent'] ?? 0;
    }
} catch (PDOException $exception) {
    error_log('Admin dashboard attendance_series error: ' . $exception->getMessage());
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            DATE_FORMAT(COALESCE(payment_date, created_at), '%Y-%m') AS month_key,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'completed' THEN amount_paid ELSE 0 END), 0) AS collected_amount,
            COALESCE(SUM(GREATEST(total_amount - amount_paid, 0)), 0) AS outstanding_amount
        FROM student_payments
        WHERE school_id = ?
          AND COALESCE(payment_date, created_at) >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY month_key
        ORDER BY month_key ASC"
    );
    $stmt->execute([$current_school_id]);
    $payment_index = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payment_index[$row['month_key']] = [
            'collected' => round(admin_dashboard_float($row['collected_amount'] ?? 0), 2),
            'outstanding' => round(admin_dashboard_float($row['outstanding_amount'] ?? 0), 2),
        ];
    }
    foreach ($month_keys as $month_position => $month_key) {
        $payment_collected_series[$month_position] = $payment_index[$month_key]['collected'] ?? 0;
        $payment_outstanding_series[$month_position] = $payment_index[$month_key]['outstanding'] ?? 0;
    }
} catch (PDOException $exception) {
    error_log('Admin dashboard payment_series error: ' . $exception->getMessage());
}

try {
    $stmt = $pdo->prepare(
        'SELECT c.class_name, COUNT(s.id) AS student_count
         FROM classes c
         LEFT JOIN students s
           ON s.class_id = c.id
          AND s.school_id = ?
          AND s.is_active = 1
         WHERE c.school_id = ?
         GROUP BY c.id, c.class_name
         ORDER BY student_count DESC, c.class_name ASC
         LIMIT 6'
    );
    $stmt->execute([$current_school_id, $current_school_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $class_distribution_labels[] = (string) ($row['class_name'] ?? 'Unnamed Class');
        $class_distribution_values[] = admin_dashboard_int($row['student_count'] ?? 0);
    }
} catch (PDOException $exception) {
    error_log('Admin dashboard class_distribution error: ' . $exception->getMessage());
}
try {
    $stmt = $pdo->prepare(
        "SELECT *
         FROM (
            SELECT
                'student' AS activity_type,
                'New student profile created' AS activity_label,
                full_name AS activity_name,
                admission_no AS activity_meta,
                created_at AS activity_date,
                'students.php' AS activity_link
            FROM students
            WHERE school_id = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)

            UNION ALL

            SELECT
                'result' AS activity_type,
                'Result record uploaded' AS activity_label,
                CONCAT(s.full_name, ' - ', sub.subject_name) AS activity_name,
                s.admission_no AS activity_meta,
                r.created_at AS activity_date,
                'manage_results.php' AS activity_link
            FROM results r
            INNER JOIN students s
                ON s.id = r.student_id
               AND s.school_id = ?
            INNER JOIN subjects sub
                ON sub.id = r.subject_id
               AND sub.school_id = ?
            WHERE r.school_id = ?
              AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)

            UNION ALL

            SELECT
                'payment' AS activity_type,
                'Fee payment updated' AS activity_label,
                s.full_name AS activity_name,
                CONCAT('?', FORMAT(sp.amount_paid, 0)) AS activity_meta,
                COALESCE(sp.payment_date, sp.created_at) AS activity_date,
                'payments_dashboard.php' AS activity_link
            FROM student_payments sp
            INNER JOIN students s
                ON s.id = sp.student_id
               AND s.school_id = ?
            WHERE sp.school_id = ?
              AND COALESCE(sp.payment_date, sp.created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ) recent_feed
         ORDER BY activity_date DESC
         LIMIT 8"
    );
    $stmt->execute([
        $current_school_id,
        $current_school_id,
        $current_school_id,
        $current_school_id,
        $current_school_id,
        $current_school_id,
    ]);
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $exception) {
    error_log('Admin dashboard recent_activities error: ' . $exception->getMessage());
}

$attendance_rate = admin_dashboard_float($attendance_stats['attendance_rate']);
$total_collected = admin_dashboard_float($fee_stats['total_collected']);
$total_outstanding = admin_dashboard_float($fee_stats['total_outstanding']);
$collection_base = $total_collected + $total_outstanding;
$collection_rate = $collection_base > 0 ? round(($total_collected / $collection_base) * 100, 1) : 0;
$student_teacher_ratio = $total_teachers > 0 ? round($total_students / $total_teachers, 1) : 0;
$average_class_size = $total_classes > 0 ? round($total_students / $total_classes, 1) : 0;
$alert_count = $pending_complaints + $unpaid_fees;
$new_student_share = $total_students > 0 ? round(($new_students / $total_students) * 100, 1) : 0;

$analytics_payload = [
    'enrollment' => [
        'labels' => $month_labels,
        'values' => $enrollment_series,
    ],
    'attendance' => [
        'labels' => $month_labels,
        'present' => $attendance_present_series,
        'late' => $attendance_late_series,
        'absent' => $attendance_absent_series,
        'distribution' => [
            $attendance_stats['present_count'],
            $attendance_stats['late_count'],
            $attendance_stats['absent_count'],
        ],
    ],
    'payments' => [
        'labels' => $month_labels,
        'collected' => $payment_collected_series,
        'outstanding' => $payment_outstanding_series,
    ],
    'classes' => [
        'labels' => $class_distribution_labels,
        'values' => $class_distribution_values,
    ],
];

$analytics_payload_json = json_encode($analytics_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

$navigation_items = [
    ['href' => 'index.php', 'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'active' => true],
    ['href' => 'schoolnews.php', 'icon' => 'fas fa-newspaper', 'label' => 'School News'],
    ['href' => 'school_diary.php', 'icon' => 'fas fa-book', 'label' => 'School Diary'],
    ['href' => 'students.php', 'icon' => 'fas fa-user-graduate', 'label' => 'Students Registration'],
    ['href' => 'students-evaluations.php', 'icon' => 'fas fa-star', 'label' => 'Students Evaluations'],
    ['href' => 'manage_class.php', 'icon' => 'fas fa-school', 'label' => 'Manage Classes'],
    ['href' => 'manage_results.php', 'icon' => 'fas fa-chart-line', 'label' => 'Manage Results'],
    ['href' => 'lesson-plans.php', 'icon' => 'fas fa-clipboard-list', 'label' => 'Lesson Plans'],
    ['href' => 'manage_curriculum.php', 'icon' => 'fas fa-book-open', 'label' => 'Curriculum'],
    ['href' => 'content_coverage.php', 'icon' => 'fas fa-check-double', 'label' => 'Content Coverage'],
    ['href' => 'manage-school.php', 'icon' => 'fas fa-building-columns', 'label' => 'Manage School'],
    ['href' => 'support.php', 'icon' => 'fas fa-life-ring', 'label' => 'Support'],
    ['href' => 'subscription.php', 'icon' => 'fas fa-credit-card', 'label' => 'Subscription'],
    ['href' => 'subjects.php', 'icon' => 'fas fa-book-reader', 'label' => 'Subjects'],
    ['href' => 'manage_user.php', 'icon' => 'fas fa-users-cog', 'label' => 'Manage Users'],
    ['href' => 'visitors.php', 'icon' => 'fas fa-person-walking', 'label' => 'Visitors'],
    ['href' => 'manage_timebook.php', 'icon' => 'fas fa-clock', 'label' => 'Teachers Time Book'],
    ['href' => 'permissions.php', 'icon' => 'fas fa-user-shield', 'label' => 'Permissions'],
    ['href' => 'manage_attendance.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Attendance Register'],
    ['href' => 'payments_dashboard.php', 'icon' => 'fas fa-money-bill-wave', 'label' => 'School Fees'],
    ['href' => 'sessions.php', 'icon' => 'fas fa-calendar-days', 'label' => 'School Sessions'],
    ['href' => 'school_calendar.php', 'icon' => 'fas fa-calendar-alt', 'label' => 'School Calendar'],
    ['href' => 'applicants.php', 'icon' => 'fas fa-file-lines', 'label' => 'Applicants'],
];

$quick_actions = [
    ['href' => 'students.php', 'icon' => 'fas fa-user-plus', 'label' => 'Register student'],
    ['href' => 'manage_results.php', 'icon' => 'fas fa-chart-column', 'label' => 'Manage results'],
    ['href' => 'payments_dashboard.php', 'icon' => 'fas fa-wallet', 'label' => 'Track school fees'],
    ['href' => 'manage_attendance.php', 'icon' => 'fas fa-clipboard-check', 'label' => 'Review attendance'],
    ['href' => 'schoolnews.php', 'icon' => 'fas fa-bullhorn', 'label' => 'Publish school news'],
    ['href' => '../helpers/generate-excel-export.php?export_type=students', 'icon' => 'fas fa-file-excel', 'label' => 'Export student data'],
    ['href' => 'manage_results.php?tab=complaints', 'icon' => 'fas fa-circle-exclamation', 'label' => 'Review complaints', 'badge' => $pending_complaints],
    ['href' => 'https://www.sahabdata.com.ng', 'icon' => 'fas fa-mobile-screen-button', 'label' => 'Buy VTU services', 'target' => '_blank'],
];

$activity_icon_map = [
    'student' => 'fas fa-user-plus',
    'result' => 'fas fa-square-poll-vertical',
    'payment' => 'fas fa-money-bill-trend-up',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Principal Dashboard | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        .admin-dashboard { overflow-x: hidden; }
        [data-sidebar] { overflow: hidden; }
        .sidebar-scroll-shell { height: 100%; overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior-y: contain; touch-action: pan-y; padding-bottom: max(1rem, env(safe-area-inset-bottom)); }
        .executive-hero { position: relative; overflow: hidden; background: radial-gradient(circle at top right, rgba(45, 212, 191, 0.22), transparent 40%), linear-gradient(135deg, #dff7f2, #bfece4 52%, #a8dfe3); color: #164e63; }
        .executive-hero::after { content: ''; position: absolute; inset: 0; background: linear-gradient(120deg, rgba(255, 255, 255, 0.34), transparent 58%); pointer-events: none; }
        .hero-content, .hero-signals { position: relative; z-index: 1; }
        .hero-chip, .signal-card, .metric-card, .activity-row, .action-tile, .watchlist-card { transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background-color 0.2s ease; }
        .metric-card:hover, .action-tile:hover, .activity-row:hover, .watchlist-card:hover { transform: translateY(-2px); }
        .hero-chip { border: 1px solid rgba(255, 255, 255, 0.45); background: rgba(255, 255, 255, 0.42); backdrop-filter: blur(10px); }
        .signal-card { border: 1px solid rgba(255, 255, 255, 0.38); background: rgba(255, 255, 255, 0.34); backdrop-filter: blur(10px); }
        .metric-card { background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98)); }
        .analytics-chart-wrap { position: relative; height: 18rem; }
        .chart-empty { position: absolute; inset: 0; display: none; align-items: center; justify-content: center; text-align: center; padding: 1rem; color: #64748b; font-size: 0.95rem; border-radius: 1rem; background: linear-gradient(180deg, rgba(248, 250, 252, 0.9), rgba(241, 245, 249, 0.95)); border: 1px dashed rgba(148, 163, 184, 0.45); }
        .chart-empty.is-visible { display: flex; }
        .activity-icon-shell { width: 2.75rem; height: 2.75rem; border-radius: 0.9rem; display: inline-flex; align-items: center; justify-content: center; background: rgba(13, 148, 136, 0.12); color: #0f766e; flex-shrink: 0; }
        .signal-meter { height: 0.55rem; width: 100%; border-radius: 999px; background: rgba(148, 163, 184, 0.18); overflow: hidden; }
        .signal-meter > span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #14b8a6, #0f766e); }
        .watchlist-card { border-left: 4px solid rgba(20, 184, 166, 0.55); }
        .mobile-sidebar { height: 100dvh; }
        .hero-shell { border: 1px solid rgba(255, 255, 255, 0.38); }
        .hero-layout { display: grid; gap: 1.5rem; }
        .analytics-layout { display: grid; gap: 1.5rem; }
        .hero-kicker { letter-spacing: 0.28em; color: #0f766e; }
        .hero-outline-btn { border-color: rgba(20, 83, 45, 0.16); background: rgba(255, 255, 255, 0.46); color: #134e4a; }
        .hero-outline-btn:hover { border-color: rgba(20, 83, 45, 0.24); background: rgba(255, 255, 255, 0.66); color: #134e4a; }
        @media (min-width: 1280px) { .hero-layout { grid-template-columns: 1.2fr 0.8fr; align-items: start; } .analytics-layout { grid-template-columns: 1.45fr 0.85fr; } }
        @media (max-width: 1023px) { .mobile-sidebar { z-index: 70; left: 0.5rem; top: 0.5rem; bottom: 0.5rem; width: min(18rem, calc(100vw - 1rem)); height: auto; border-radius: 1.25rem; } }
        @media (max-width: 1024px) { .admin-dashboard .container { padding-left: 1rem; padding-right: 1rem; } }
        @media (max-width: 640px) { .admin-dashboard main { gap: 1rem; } .admin-dashboard section { border-radius: 1.1rem; padding: 1rem; } .admin-dashboard .rounded-2xl { border-radius: 0.95rem; } .admin-dashboard .hero-content > .flex { flex-direction: column; align-items: stretch; } .admin-dashboard .hero-chip { width: 100%; } .admin-dashboard .metric-card > .mt-4, .admin-dashboard .watchlist-card > div:first-child, .admin-dashboard section > .flex.items-center.justify-between.mb-4 { flex-direction: column; align-items: flex-start; gap: 0.5rem; } .admin-dashboard .action-tile { align-items: flex-start; } .admin-dashboard .activity-row { padding: 0.875rem; } .analytics-chart-wrap { height: 14rem; } }

    </style>
</head>
<body class="landing admin-dashboard">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu"><span></span><span></span><span></span></button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="h-10 w-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Principal Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="workspace-header-actions flex items-center gap-3">
                <span class="hidden text-sm text-slate-600 md:block">Welcome, <?php echo htmlspecialchars($principal_name); ?></span>
                <a class="btn btn-outline" href="manage-school.php"><span class="hidden sm:inline">Manage school</span><span class="sm:hidden">School</span></a>
                <a class="btn btn-primary" href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 lg:grid-cols-[280px_1fr] py-8">
        <aside class="mobile-sidebar fixed inset-y-0 left-0 z-40 w-72 bg-white shadow-lift border-r border-ink-900/10 transform -translate-x-full transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell">
                <div class="p-6 border-b border-ink-900/10">
                    <h2 class="text-lg font-semibold text-ink-900">Navigation</h2>
                    <p class="text-sm text-slate-500">Principal workspace</p>
                </div>
                <nav class="p-4 space-y-1 text-sm">
                    <?php foreach ($navigation_items as $item): ?>
                        <?php $is_active = !empty($item['active']); ?>
                        <a href="<?php echo htmlspecialchars($item['href']); ?>" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold <?php echo $is_active ? 'bg-teal-600/10 text-teal-700' : 'text-slate-600 hover:bg-teal-600/10 hover:text-teal-700'; ?>">
                            <i class="<?php echo htmlspecialchars($item['icon']); ?>"></i>
                            <span><?php echo htmlspecialchars($item['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </aside>

        <main class="space-y-6">
            <section class="executive-hero hero-shell rounded-3xl p-6 shadow-soft">
                <div class="hero-layout">
                    <div class="hero-content space-y-5">
                        <div>
                            <p class="hero-kicker text-xs uppercase">Executive Dashboard</p>
                            <h1 class="mt-3 font-serif text-3xl text-teal-950 sm:text-4xl">Lead the school with sharper visibility.</h1>
                            <p class="mt-3 max-w-2xl text-sm text-slate-700 sm:text-base">Monitor enrolment movement, attendance reliability, payment health, and operational pressure points from one modern dashboard.</p>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <div class="hero-chip rounded-2xl px-4 py-3"><p class="text-xs uppercase tracking-wide text-slate-500">Today</p><p class="mt-1 text-sm font-semibold text-teal-950"><?php echo date('l, j F Y'); ?></p></div>
                            <div class="hero-chip rounded-2xl px-4 py-3"><p class="text-xs uppercase tracking-wide text-slate-500">Attendance rate</p><p class="mt-1 text-sm font-semibold text-teal-950"><?php echo number_format($attendance_rate, 1); ?>% in the last 30 days</p></div>
                            <div class="hero-chip rounded-2xl px-4 py-3"><p class="text-xs uppercase tracking-wide text-slate-500">Collection rate</p><p class="mt-1 text-sm font-semibold text-teal-950"><?php echo number_format($collection_rate, 1); ?>% captured</p></div>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <a class="btn btn-primary" href="payments_dashboard.php"><i class="fas fa-chart-pie"></i><span>Open finance overview</span></a>
                            <a class="btn hero-outline-btn" href="manage_results.php"><i class="fas fa-clipboard-list"></i><span>Review academics</span></a>
                        </div>
                    </div>
                    <div class="hero-signals grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                        <div class="signal-card rounded-2xl p-4"><p class="text-xs uppercase tracking-wide text-slate-500">Student to staff ratio</p><p class="mt-2 text-3xl font-semibold text-teal-950"><?php echo $total_teachers > 0 ? number_format($student_teacher_ratio, 1) . ':1' : '—'; ?></p><p class="mt-1 text-sm text-slate-700">Balances workforce pressure against current enrolment.</p></div>
                        <div class="signal-card rounded-2xl p-4"><p class="text-xs uppercase tracking-wide text-slate-500">Average class size</p><p class="mt-2 text-3xl font-semibold text-teal-950"><?php echo $total_classes > 0 ? number_format($average_class_size, 1) : '0'; ?></p><p class="mt-1 text-sm text-slate-700">Average active students allocated per class.</p></div>
                        <div class="signal-card rounded-2xl p-4"><p class="text-xs uppercase tracking-wide text-slate-500">New student share</p><p class="mt-2 text-3xl font-semibold text-teal-950"><?php echo number_format($new_student_share, 1); ?>%</p><p class="mt-1 text-sm text-slate-700">Portion of the student body created in the last 30 days.</p></div>
                        <div class="signal-card rounded-2xl p-4"><p class="text-xs uppercase tracking-wide text-slate-500">Open alerts</p><p class="mt-2 text-3xl font-semibold text-teal-950"><?php echo number_format($alert_count); ?></p><p class="mt-1 text-sm text-slate-700">Combined pending complaints and students with fee balances.</p></div>
                    </div>
                </div>
            </section>
            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="metric-card rounded-2xl border border-ink-900/5 p-5 shadow-soft">
                    <div class="flex items-start justify-between gap-4"><div><p class="text-sm text-slate-500">Active students</p><p class="mt-2 text-3xl font-semibold text-ink-900"><?php echo number_format($total_students); ?></p></div><span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700"><i class="fas fa-user-graduate"></i></span></div>
                    <div class="mt-4 flex items-center justify-between text-sm"><span class="text-slate-500"><?php echo number_format($new_students); ?> new in 30 days</span><a class="font-semibold text-teal-700 hover:text-teal-600" href="students.php">Open</a></div>
                </article>
                <article class="metric-card rounded-2xl border border-ink-900/5 p-5 shadow-soft">
                    <div class="flex items-start justify-between gap-4"><div><p class="text-sm text-slate-500">Academic staff</p><p class="mt-2 text-3xl font-semibold text-ink-900"><?php echo number_format($total_teachers); ?></p></div><span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700"><i class="fas fa-chalkboard-teacher"></i></span></div>
                    <div class="mt-4 flex items-center justify-between text-sm"><span class="text-slate-500"><?php echo number_format($total_classes); ?> classes in scope</span><a class="font-semibold text-teal-700 hover:text-teal-600" href="manage_user.php">Manage</a></div>
                </article>
                <article class="metric-card rounded-2xl border border-ink-900/5 p-5 shadow-soft">
                    <div class="flex items-start justify-between gap-4"><div><p class="text-sm text-slate-500">Attendance reliability</p><p class="mt-2 text-3xl font-semibold text-ink-900"><?php echo number_format($attendance_rate, 1); ?>%</p></div><span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700"><i class="fas fa-calendar-check"></i></span></div>
                    <div class="mt-4 flex items-center justify-between text-sm"><span class="text-slate-500"><?php echo number_format($attendance_stats['total_records']); ?> records analysed</span><a class="font-semibold text-teal-700 hover:text-teal-600" href="manage_attendance.php">Inspect</a></div>
                </article>
                <article class="metric-card rounded-2xl border border-ink-900/5 p-5 shadow-soft">
                    <div class="flex items-start justify-between gap-4"><div><p class="text-sm text-slate-500">Fees collected</p><p class="mt-2 text-3xl font-semibold text-ink-900"><?php echo htmlspecialchars(admin_dashboard_currency_compact($total_collected)); ?></p></div><span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700"><i class="fas fa-money-bill-wave"></i></span></div>
                    <div class="mt-4 flex items-center justify-between text-sm"><span class="text-slate-500"><?php echo htmlspecialchars(admin_dashboard_currency_compact($total_outstanding)); ?> outstanding</span><a class="font-semibold text-teal-700 hover:text-teal-600" href="payments_dashboard.php">Track</a></div>
                </article>
            </section>

            <div class="analytics-layout">
                <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                    <div class="flex items-center justify-between mb-4">
                        <div><h2 class="text-lg font-semibold text-ink-900">Professional analytics</h2><p class="text-sm text-slate-500">Decision-grade trends across students, attendance, finances, and class demand.</p></div>
                        <span class="text-xs uppercase tracking-wide text-slate-500">Live overview</span>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <article class="rounded-2xl border border-ink-900/5 p-5">
                            <div class="flex items-start justify-between gap-3 mb-4"><div><h3 class="text-base font-semibold text-ink-900">Enrollment growth</h3><p class="text-sm text-slate-500">Monthly intake for the last six months.</p></div><span class="rounded-full bg-teal-600/10 px-3 py-1 text-xs font-semibold text-teal-700">Students</span></div>
                            <div class="analytics-chart-wrap"><canvas id="enrollmentChart" class="h-full w-full"></canvas><p id="enrollmentChartEmpty" class="chart-empty">No student enrollment data is available for this period.</p></div>
                        </article>
                        <article class="rounded-2xl border border-ink-900/5 p-5">
                            <div class="flex items-start justify-between gap-3 mb-4"><div><h3 class="text-base font-semibold text-ink-900">Attendance composition</h3><p class="text-sm text-slate-500">Present, late, and absent records across the last 30 days.</p></div><span class="rounded-full bg-teal-600/10 px-3 py-1 text-xs font-semibold text-teal-700">Attendance</span></div>
                            <div class="analytics-chart-wrap"><canvas id="attendanceChart" class="h-full w-full"></canvas><p id="attendanceChartEmpty" class="chart-empty">No attendance records are available yet.</p></div>
                            <div class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                                <div class="rounded-xl bg-slate-50 px-3 py-3 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Present</p><p class="mt-1 font-semibold text-ink-900"><?php echo number_format($attendance_stats['present_count']); ?></p></div>
                                <div class="rounded-xl bg-slate-50 px-3 py-3 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Late</p><p class="mt-1 font-semibold text-ink-900"><?php echo number_format($attendance_stats['late_count']); ?></p></div>
                                <div class="rounded-xl bg-slate-50 px-3 py-3 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Absent</p><p class="mt-1 font-semibold text-ink-900"><?php echo number_format($attendance_stats['absent_count']); ?></p></div>
                            </div>
                        </article>
                        <article class="rounded-2xl border border-ink-900/5 p-5">
                            <div class="flex items-start justify-between gap-3 mb-4"><div><h3 class="text-base font-semibold text-ink-900">Finance momentum</h3><p class="text-sm text-slate-500">Collected revenue versus outstanding balances by month.</p></div><span class="rounded-full bg-teal-600/10 px-3 py-1 text-xs font-semibold text-teal-700">Finance</span></div>
                            <div class="analytics-chart-wrap"><canvas id="paymentsChart" class="h-full w-full"></canvas><p id="paymentsChartEmpty" class="chart-empty">No fee collection activity is available for this period.</p></div>
                        </article>
                        <article class="rounded-2xl border border-ink-900/5 p-5">
                            <div class="flex items-start justify-between gap-3 mb-4"><div><h3 class="text-base font-semibold text-ink-900">Top class population</h3><p class="text-sm text-slate-500">Largest active classes based on student count.</p></div><span class="rounded-full bg-teal-600/10 px-3 py-1 text-xs font-semibold text-teal-700">Capacity</span></div>
                            <div class="analytics-chart-wrap"><canvas id="classDistributionChart" class="h-full w-full"></canvas><p id="classDistributionChartEmpty" class="chart-empty">No class distribution data is available yet.</p></div>
                        </article>
                    </div>
                </section>
                <div class="space-y-6">
                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <div class="flex items-center justify-between mb-4"><div><h2 class="text-lg font-semibold text-ink-900">Executive watchlist</h2><p class="text-sm text-slate-500">Signals that deserve immediate action.</p></div><span class="text-xs uppercase tracking-wide text-slate-500">Priority</span></div>
                        <div class="space-y-4">
                            <article class="watchlist-card rounded-2xl border border-ink-900/5 bg-slate-50 p-4"><div class="flex items-center justify-between gap-3"><div><p class="text-sm font-semibold text-ink-900">Fee collection efficiency</p><p class="text-sm text-slate-500"><?php echo number_format($fee_stats['payment_records']); ?> payment records tracked.</p></div><span class="text-lg font-semibold text-teal-700"><?php echo number_format($collection_rate, 1); ?>%</span></div><div class="signal-meter mt-3"><span style="width: <?php echo max(0, min(100, $collection_rate)); ?>%;"></span></div></article>
                            <article class="watchlist-card rounded-2xl border border-ink-900/5 bg-slate-50 p-4"><div class="flex items-center justify-between gap-3"><div><p class="text-sm font-semibold text-ink-900">Attendance consistency</p><p class="text-sm text-slate-500">Measured from present records only.</p></div><span class="text-lg font-semibold text-teal-700"><?php echo number_format($attendance_rate, 1); ?>%</span></div><div class="signal-meter mt-3"><span style="width: <?php echo max(0, min(100, $attendance_rate)); ?>%;"></span></div></article>
                            <article class="watchlist-card rounded-2xl border border-ink-900/5 bg-slate-50 p-4"><div class="flex items-center justify-between gap-3"><div><p class="text-sm font-semibold text-ink-900">Pending complaints</p><p class="text-sm text-slate-500">Unresolved academic feedback items.</p></div><span class="text-lg font-semibold text-ink-900"><?php echo number_format($pending_complaints); ?></span></div><a class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="manage_results.php?tab=complaints">Review complaints</a></article>
                            <article class="watchlist-card rounded-2xl border border-ink-900/5 bg-slate-50 p-4"><div class="flex items-center justify-between gap-3"><div><p class="text-sm font-semibold text-ink-900">Students with balances</p><p class="text-sm text-slate-500">Distinct students with unpaid fee exposure.</p></div><span class="text-lg font-semibold text-ink-900"><?php echo number_format($unpaid_fees); ?></span></div><a class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-600" href="payments_dashboard.php">Open payment dashboard</a></article>
                        </div>
                    </section>
                    <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                        <div class="flex items-center justify-between mb-4"><div><h2 class="text-lg font-semibold text-ink-900">Recent activity</h2><p class="text-sm text-slate-500">Latest operational updates from the last seven days.</p></div><span class="text-xs uppercase tracking-wide text-slate-500">Live feed</span></div>
                        <div class="space-y-3">
                            <?php if (!empty($recent_activities)): ?>
                                <?php foreach ($recent_activities as $activity): ?>
                                    <a href="<?php echo htmlspecialchars($activity['activity_link']); ?>" class="activity-row flex items-start gap-3 rounded-2xl border border-ink-900/5 p-4 hover:border-teal-600/30 hover:bg-teal-600/5">
                                        <span class="activity-icon-shell"><i class="<?php echo htmlspecialchars($activity_icon_map[$activity['activity_type']] ?? 'fas fa-bolt'); ?>"></i></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-semibold text-ink-900"><?php echo htmlspecialchars($activity['activity_label']); ?></span>
                                            <span class="mt-1 block text-sm text-slate-600"><?php echo htmlspecialchars($activity['activity_name']); ?></span>
                                            <?php if (!empty($activity['activity_meta'])): ?><span class="mt-1 block text-xs text-slate-500"><?php echo htmlspecialchars($activity['activity_meta']); ?></span><?php endif; ?>
                                            <span class="mt-2 block text-xs uppercase tracking-wide text-slate-400"><?php echo date('M j, Y g:i A', strtotime((string) $activity['activity_date'])); ?></span>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="rounded-2xl border border-dashed border-ink-900/10 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">No recent activity was recorded in the last 7 days.</div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="flex items-center justify-between mb-4"><div><h2 class="text-lg font-semibold text-ink-900">Quick actions</h2><p class="text-sm text-slate-500">Fast access to the workflows principals use most.</p></div><span class="text-xs uppercase tracking-wide text-slate-500">Shortcuts</span></div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <?php foreach ($quick_actions as $action): ?>
                        <a href="<?php echo htmlspecialchars($action['href']); ?>" class="action-tile relative flex items-center gap-3 rounded-2xl border border-ink-900/10 px-4 py-4 text-sm font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10" <?php if (!empty($action['target'])): ?>target="<?php echo htmlspecialchars($action['target']); ?>" rel="noreferrer"<?php endif; ?>>
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600/10 text-teal-700"><i class="<?php echo htmlspecialchars($action['icon']); ?>"></i></span>
                            <span class="pr-6"><?php echo htmlspecialchars($action['label']); ?></span>
                            <?php if (!empty($action['badge'])): ?><span class="absolute right-3 top-3 inline-flex min-h-6 min-w-6 items-center justify-center rounded-full bg-teal-700 px-2 text-xs font-semibold text-white"><?php echo number_format((int) $action['badge']); ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
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
                    return;
                }
                closeSidebar();
            });
        }

        if (overlay) overlay.addEventListener('click', closeSidebar);
        if (sidebar) sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeSidebar));

        const toggleChartState = (canvasId, emptyId, hasData) => {
            const canvas = document.getElementById(canvasId);
            const emptyState = document.getElementById(emptyId);
            if (!canvas || !emptyState) return;
            if (hasData) {
                canvas.classList.remove('hidden');
                emptyState.classList.remove('is-visible');
                return;
            }
            canvas.classList.add('hidden');
            emptyState.classList.add('is-visible');
        };

        const formatCompactNaira = (value) => new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', notation: 'compact', maximumFractionDigits: 1 }).format(Number(value || 0));

        const initializeAnalyticsCharts = () => {
            if (typeof Chart === 'undefined') return;

            const analyticsData = <?php echo $analytics_payload_json ?: '{}'; ?>;
            Chart.defaults.color = '#475569';
            Chart.defaults.font.family = "'Manrope', sans-serif";
            Chart.defaults.plugins.legend.labels.usePointStyle = true;
            Chart.defaults.plugins.legend.labels.boxWidth = 10;
            Chart.defaults.plugins.legend.labels.boxHeight = 10;
            const gridColor = 'rgba(148, 163, 184, 0.18)';

            const enrollmentValues = (analyticsData.enrollment?.values || []).map((value) => Number(value || 0));
            const hasEnrollmentData = enrollmentValues.some((value) => value > 0);
            toggleChartState('enrollmentChart', 'enrollmentChartEmpty', hasEnrollmentData);
            if (hasEnrollmentData) {
                const canvas = document.getElementById('enrollmentChart');
                const context = canvas.getContext('2d');
                const gradient = context.createLinearGradient(0, 0, 0, 260);
                gradient.addColorStop(0, 'rgba(20, 184, 166, 0.25)');
                gradient.addColorStop(1, 'rgba(20, 184, 166, 0.02)');
                new Chart(context, {
                    type: 'line',
                    data: { labels: analyticsData.enrollment.labels || [], datasets: [{ label: 'New students', data: enrollmentValues, borderColor: '#0f766e', backgroundColor: gradient, fill: true, tension: 0.35, pointRadius: 4, pointHoverRadius: 5, borderWidth: 2 }] },
                    options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: gridColor } }, x: { grid: { display: false } } }, plugins: { legend: { display: false } } },
                });
            }

            const attendanceDistribution = (analyticsData.attendance?.distribution || []).map((value) => Number(value || 0));
            const hasAttendanceData = attendanceDistribution.some((value) => value > 0);
            toggleChartState('attendanceChart', 'attendanceChartEmpty', hasAttendanceData);
            if (hasAttendanceData) {
                new Chart(document.getElementById('attendanceChart'), {
                    type: 'doughnut',
                    data: { labels: ['Present', 'Late', 'Absent'], datasets: [{ data: attendanceDistribution, backgroundColor: ['#0f766e', '#f59e0b', '#ef4444'], borderColor: '#ffffff', borderWidth: 4, hoverOffset: 8 }] },
                    options: { maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'bottom' } } },
                });
            }

            const collectedValues = (analyticsData.payments?.collected || []).map((value) => Number(value || 0));
            const outstandingValues = (analyticsData.payments?.outstanding || []).map((value) => Number(value || 0));
            const hasPaymentData = [...collectedValues, ...outstandingValues].some((value) => value > 0);
            toggleChartState('paymentsChart', 'paymentsChartEmpty', hasPaymentData);
            if (hasPaymentData) {
                new Chart(document.getElementById('paymentsChart'), {
                    type: 'bar',
                    data: {
                        labels: analyticsData.payments.labels || [],
                        datasets: [
                            { label: 'Collected', data: collectedValues, backgroundColor: 'rgba(20, 184, 166, 0.85)', borderRadius: 10, maxBarThickness: 28 },
                            { label: 'Outstanding', data: outstandingValues, backgroundColor: 'rgba(148, 163, 184, 0.85)', borderRadius: 10, maxBarThickness: 28 },
                        ],
                    },
                    options: {
                        maintainAspectRatio: false,
                        scales: { y: { beginAtZero: true, grid: { color: gridColor }, ticks: { callback: (value) => formatCompactNaira(value) } }, x: { grid: { display: false } } },
                        plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (context) => `${context.dataset.label}: ${new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(context.parsed.y || 0)}` } } },
                    },
                });
            }

            const classValues = (analyticsData.classes?.values || []).map((value) => Number(value || 0));
            const hasClassData = classValues.some((value) => value > 0);
            toggleChartState('classDistributionChart', 'classDistributionChartEmpty', hasClassData);
            if (hasClassData) {
                new Chart(document.getElementById('classDistributionChart'), {
                    type: 'bar',
                    data: { labels: analyticsData.classes.labels || [], datasets: [{ label: 'Students', data: classValues, backgroundColor: 'rgba(15, 118, 110, 0.85)', borderRadius: 10, maxBarThickness: 22 }] },
                    options: { maintainAspectRatio: false, indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: gridColor } }, y: { grid: { display: false } } }, plugins: { legend: { display: false } } },
                });
            }
        };

        initializeAnalyticsCharts();
    </script>

    <?php include '../includes/floating-button.php'; ?>
</body>
</html>


