<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit();
}

$student_user_id = (int) ($_SESSION['student_id'] ?? 0);
$current_school_id = get_current_school_id();
$current_month = date('Y-m');
$selected_month = $_GET['month'] ?? $current_month;
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = $current_month;
}

$parts = explode('-', $selected_month);
$year = (int) ($parts[0] ?? date('Y'));
$month = (int) ($parts[1] ?? date('m'));
$year = ($year >= 2000 && $year <= 2100) ? $year : (int) date('Y');
$month = ($month >= 1 && $month <= 12) ? $month : (int) date('m');
$selected_month = sprintf('%04d-%02d', $year, $month);

$days_in_month = (int) date('t', strtotime($selected_month . '-01'));
$first_day = (int) date('N', strtotime($selected_month . '-01'));
$today = date('Y-m-d');
$selected_month_label = date('F Y', strtotime($selected_month . '-01'));

$student_sql = "SELECT s.*, c.class_name
                FROM students s
                JOIN classes c ON s.class_id = c.id
                WHERE s.id = :id AND s.school_id = :school_id";
$student_stmt = $pdo->prepare($student_sql);
$student_stmt->execute([
    ':id' => $student_user_id,
    ':school_id' => $current_school_id,
]);
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die('Student information not found.');
}

$student_name = trim((string) ($student['full_name'] ?? 'Student'));
$admission_no = trim((string) ($student['admission_no'] ?? ''));

$attendance_sql = "SELECT a.date, a.status, a.notes
                   FROM attendance a
                   JOIN students s ON a.student_id = s.id
                   WHERE a.student_id = :id
                     AND s.school_id = :school_id
                     AND DATE_FORMAT(a.date, '%Y-%m') = :selected_month
                   ORDER BY a.date DESC";
$attendance_stmt = $pdo->prepare($attendance_sql);
$attendance_stmt->execute([
    ':id' => (int) $student['id'],
    ':school_id' => $current_school_id,
    ':selected_month' => $selected_month,
]);
$attendance_records = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);

$monthly_counts = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'leave' => 0,
];
foreach ($attendance_records as $record) {
    $status_key = strtolower((string) ($record['status'] ?? ''));
    if (array_key_exists($status_key, $monthly_counts)) {
        $monthly_counts[$status_key]++;
    }
}

$monthly_total_records = count($attendance_records);
$monthly_attendance_rate = $monthly_total_records > 0
    ? round((($monthly_counts['present'] + $monthly_counts['late']) / $monthly_total_records) * 100, 1)
    : 0;

$attendance_map = [];
foreach ($attendance_records as $record) {
    $attendance_map[$record['date']] = $record;
}

$stats_sql = "SELECT
    COUNT(*) AS total_days,
    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_days,
    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_days,
    SUM(CASE WHEN a.status = 'leave' THEN 1 ELSE 0 END) AS leave_days
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE a.student_id = :id
      AND s.school_id = :school_id
      AND a.date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([
    ':id' => (int) $student['id'],
    ':school_id' => $current_school_id,
]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$stats = [
    'total_days' => (int) ($stats['total_days'] ?? 0),
    'present_days' => (int) ($stats['present_days'] ?? 0),
    'absent_days' => (int) ($stats['absent_days'] ?? 0),
    'late_days' => (int) ($stats['late_days'] ?? 0),
    'leave_days' => (int) ($stats['leave_days'] ?? 0),
];

$months_sql = "SELECT DISTINCT DATE_FORMAT(a.date, '%Y-%m') AS month
               FROM attendance a
               JOIN students s ON a.student_id = s.id
               WHERE a.student_id = :id
                 AND s.school_id = :school_id
               ORDER BY month DESC";
$months_stmt = $pdo->prepare($months_sql);
$months_stmt->execute([
    ':id' => (int) $student['id'],
    ':school_id' => $current_school_id,
]);
$months_rows = $months_stmt->fetchAll(PDO::FETCH_ASSOC);
$months = [];
foreach ($months_rows as $month_row) {
    $month_value = (string) ($month_row['month'] ?? '');
    if ($month_value !== '') {
        $months[] = $month_value;
    }
}
if (!in_array($selected_month, $months, true)) {
    array_unshift($months, $selected_month);
}
$months = array_values(array_unique($months));

$streak_sql = "SELECT a.date, a.status
               FROM attendance a
               JOIN students s ON a.student_id = s.id
               WHERE a.student_id = :id
                 AND s.school_id = :school_id
               ORDER BY a.date DESC
               LIMIT 120";
$streak_stmt = $pdo->prepare($streak_sql);
$streak_stmt->execute([
    ':id' => (int) $student['id'],
    ':school_id' => $current_school_id,
]);
$streak_records = $streak_stmt->fetchAll(PDO::FETCH_ASSOC);

$current_streak = 0;
foreach ($streak_records as $row) {
    if (in_array((string) ($row['status'] ?? ''), ['present', 'late'], true)) {
        $current_streak++;
    } else {
        break;
    }
}

$trend_sql = "SELECT
    DATE_FORMAT(a.date, '%Y-%m') AS month,
    COUNT(*) AS total_entries,
    SUM(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) AS attended_entries
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE a.student_id = :id
      AND s.school_id = :school_id
    GROUP BY DATE_FORMAT(a.date, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6";
$trend_stmt = $pdo->prepare($trend_sql);
$trend_stmt->execute([
    ':id' => (int) $student['id'],
    ':school_id' => $current_school_id,
]);
$trend_rows = array_reverse($trend_stmt->fetchAll(PDO::FETCH_ASSOC));

$trend_labels = [];
$trend_rates = [];
foreach ($trend_rows as $trend_row) {
    $month_key = (string) ($trend_row['month'] ?? '');
    $total_entries = (int) ($trend_row['total_entries'] ?? 0);
    $attended_entries = (int) ($trend_row['attended_entries'] ?? 0);
    $trend_labels[] = date('M Y', strtotime($month_key . '-01'));
    $trend_rates[] = $total_entries > 0 ? round(($attended_entries / $total_entries) * 100, 1) : 0;
}

$three_month_attendance_rate = $stats['total_days'] > 0
    ? round((($stats['present_days'] + $stats['late_days']) / $stats['total_days']) * 100, 1)
    : 0;
$absence_pressure = $stats['total_days'] > 0
    ? round(($stats['absent_days'] / $stats['total_days']) * 100, 1)
    : 0;

$status_meta = [
    'present' => ['label' => 'Present', 'icon' => 'fa-check-circle', 'tone' => 'present'],
    'absent' => ['label' => 'Absent', 'icon' => 'fa-xmark-circle', 'tone' => 'absent'],
    'late' => ['label' => 'Late', 'icon' => 'fa-clock', 'tone' => 'late'],
    'leave' => ['label' => 'Leave', 'icon' => 'fa-envelope', 'tone' => 'leave'],
];

$chart_status_labels = [];
$chart_status_values = [];
foreach ($monthly_counts as $status => $value) {
    $chart_status_labels[] = $status_meta[$status]['label'];
    $chart_status_values[] = (int) $value;
}

$attendance_stylesheet_version = @filemtime(__DIR__ . '/../assets/css/student-attendance.css') ?: time();
$pageTitle = 'My Attendance | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool');
$extraHead = '
<link rel="stylesheet" href="../assets/css/student-attendance.css?v=' . $attendance_stylesheet_version . '">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/css/bootstrap-datepicker.min.css">
<style>
    .student-layout{overflow-x:hidden}
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
    @media (min-width:768px){#studentMain{padding-left:16rem !important}.sidebar{transform:translateX(0);top:73px;height:calc(100vh - 73px);padding-top:0}.sidebar-close{display:none}.student-sidebar-overlay{display:none}}
    @media (max-width:767.98px){#studentMain{padding-left:0 !important}}
</style>';
require_once __DIR__ . '/../includes/student_header.php';
?>
<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="attendance-student-page attendance-workspace space-y-6">
    <section class="workspace-hero animate-fade-in">
        <div class="workspace-hero-head">
            <div>
                <p class="hero-eyebrow">Attendance Workspace</p>
                <h1>Attendance insight with the same professional design system used across teacher pages.</h1>
                <p>Review your monthly record, monitor your attendance momentum, and export a polished report for guardians or school follow-up.</p>
            </div>
            <div class="hero-actions">
                <a href="#month-controls" class="hero-action btn-solid">
                    <i class="fas fa-calendar-day"></i>
                    <span>Select Month</span>
                </a>
                <a href="generate_attendance_pdf.php?month=<?php echo urlencode($selected_month); ?>" class="hero-action btn-ghost" target="_blank" rel="noopener">
                    <i class="fas fa-download"></i>
                    <span>Download Report</span>
                </a>
            </div>
        </div>
        <div class="hero-metrics">
            <article class="hero-metric-card">
                <div class="metric-icon tone-ink"><i class="fas fa-calendar-alt"></i></div>
                <p>Current Month</p>
                <h2><?php echo htmlspecialchars($selected_month_label); ?></h2>
                <span><?php echo $monthly_total_records; ?> attendance entries</span>
            </article>
            <article class="hero-metric-card">
                <div class="metric-icon tone-emerald"><i class="fas fa-check-circle"></i></div>
                <p>Attendance Rate</p>
                <h2><?php echo $monthly_attendance_rate; ?>%</h2>
                <span>Present and late combined</span>
            </article>
            <article class="hero-metric-card">
                <div class="metric-icon tone-amber"><i class="fas fa-fire"></i></div>
                <p>Current Streak</p>
                <h2><?php echo $current_streak; ?></h2>
                <span>Consecutive attended days</span>
            </article>
            <article class="hero-metric-card">
                <div class="metric-icon tone-rose"><i class="fas fa-triangle-exclamation"></i></div>
                <p>Absence Pressure</p>
                <h2><?php echo $absence_pressure; ?>%</h2>
                <span>Absence share in last 3 months</span>
            </article>
        </div>
    </section>

    <section class="workspace-panel" id="month-controls">
        <div class="panel-heading">
            <div>
                <p class="panel-kicker">Schedule Filter</p>
                <h2>Choose attendance month</h2>
            </div>
        </div>
        <form method="GET" action="" id="monthForm" class="month-form">
            <label for="attendanceMonth" class="sr-only">Select Month</label>
            <input id="attendanceMonth" type="text" class="monthpicker" name="month" value="<?php echo htmlspecialchars($selected_month); ?>" autocomplete="off">
            <button type="submit" class="month-submit">
                <i class="fas fa-arrows-rotate"></i>
                <span>Load Month</span>
            </button>
        </form>
    </section>

    <section class="workspace-grid">
        <article class="workspace-panel calendar-panel">
            <div class="panel-heading compact">
                <div>
                    <p class="panel-kicker">Calendar View</p>
                    <h2><?php echo htmlspecialchars($selected_month_label); ?></h2>
                </div>
                <span class="panel-pill"><?php echo $monthly_attendance_rate; ?>% attended</span>
            </div>

            <div class="weekday-grid" aria-hidden="true">
                <span>Mon</span>
                <span>Tue</span>
                <span>Wed</span>
                <span>Thu</span>
                <span>Fri</span>
                <span>Sat</span>
                <span>Sun</span>
            </div>

            <div class="calendar-grid">
                <?php
                $day_counter = 1;
                for ($i = 0; $i < 42; $i++) {
                    if (($i < $first_day - 1) || $day_counter > $days_in_month) {
                        echo '<div class="calendar-day empty"></div>';
                        continue;
                    }

                    $current_date = sprintf('%04d-%02d-%02d', $year, $month, $day_counter);
                    $date_str = date('Y-m-d', strtotime($current_date));
                    $classes = ['calendar-day'];
                    $title = date('l, F j, Y', strtotime($current_date));

                    if ($date_str === $today) {
                        $classes[] = 'today';
                        $title .= ' (Today)';
                    }

                    if (isset($attendance_map[$date_str])) {
                        $status_name = (string) ($attendance_map[$date_str]['status'] ?? '');
                        $classes[] = 'status-' . htmlspecialchars($status_name);
                        $title .= ' - ' . ucfirst($status_name);
                    }

                    echo '<div class="' . implode(' ', $classes) . '" title="' . htmlspecialchars($title) . '">';
                    echo '<span>' . $day_counter . '</span>';
                    echo '</div>';
                    $day_counter++;
                }
                ?>
            </div>

            <div class="legend">
                <span><i class="legend-dot present"></i>Present</span>
                <span><i class="legend-dot absent"></i>Absent</span>
                <span><i class="legend-dot late"></i>Late</span>
                <span><i class="legend-dot leave"></i>Leave</span>
                <span><i class="legend-dot today"></i>Today</span>
            </div>
        </article>

        <aside class="workspace-stack">
            <article class="workspace-panel analytics-panel">
                <div class="panel-heading compact">
                    <div>
                        <p class="panel-kicker">Analytics</p>
                        <h2>Status Distribution</h2>
                    </div>
                    <span class="panel-pill"><?php echo $monthly_total_records; ?> entries</span>
                </div>
                <div class="chart-box">
                    <canvas id="statusDistributionChart" aria-label="Attendance status distribution chart" role="img"></canvas>
                    <p class="chart-empty" id="statusEmptyState">No attendance records for this month yet.</p>
                </div>
            </article>

            <article class="workspace-panel analytics-panel">
                <div class="panel-heading compact">
                    <div>
                        <p class="panel-kicker">Trend</p>
                        <h2>Monthly Attendance Rate</h2>
                    </div>
                    <span class="panel-pill">Last 6 months</span>
                </div>
                <div class="chart-box">
                    <canvas id="attendanceTrendChart" aria-label="Monthly attendance trend chart" role="img"></canvas>
                    <p class="chart-empty" id="trendEmptyState">Trend data will appear after more attendance records are available.</p>
                </div>
            </article>

            <article class="workspace-panel summary-panel">
                <div class="panel-heading compact">
                    <div>
                        <p class="panel-kicker">Monthly Summary</p>
                        <h2><?php echo htmlspecialchars($selected_month_label); ?></h2>
                    </div>
                    <span class="panel-pill accent"><?php echo $monthly_attendance_rate; ?>%</span>
                </div>

                <div class="summary-list">
                    <?php foreach ($monthly_counts as $status => $count): ?>
                        <div class="summary-item <?php echo 'tone-' . $status; ?>">
                            <span class="summary-label">
                                <i class="fas <?php echo htmlspecialchars($status_meta[$status]['icon']); ?>"></i>
                                <?php echo htmlspecialchars($status_meta[$status]['label']); ?>
                            </span>
                            <span class="summary-value"><?php echo (int) $count; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="insight-block">
                    <p>Attendance Index (last 3 months)</p>
                    <h3><?php echo $three_month_attendance_rate; ?>%</h3>
                    <span><?php echo $stats['present_days'] + $stats['late_days']; ?> attended days out of <?php echo $stats['total_days']; ?> records</span>
                </div>

                <div class="month-links-wrap">
                    <p class="month-links-title">Available Months</p>
                    <div class="month-links">
                        <?php foreach ($months as $month_value): ?>
                            <a href="?month=<?php echo urlencode($month_value); ?>" class="month-link <?php echo $selected_month === $month_value ? 'active' : ''; ?>">
                                <i class="fas fa-calendar-month"></i>
                                <span><?php echo date('F Y', strtotime($month_value . '-01')); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <a href="generate_attendance_pdf.php?month=<?php echo urlencode($selected_month); ?>" class="download-report" target="_blank" rel="noopener">
                    <i class="fas fa-file-arrow-down"></i>
                    <span>Download Attendance Report</span>
                </a>
            </article>
        </aside>
    </section>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/js/bootstrap-datepicker.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
    const sidebarOverlay = document.getElementById('studentSidebarOverlay');
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
    }

    if (window.matchMedia('(min-width: 768px)').matches) {
        document.body.classList.remove('sidebar-open');
    }

    $(document).ready(function () {
        $('.monthpicker').datepicker({
            format: 'yyyy-mm',
            startView: 'months',
            minViewMode: 'months',
            autoclose: true
        });
    });

    const statusChartLabels = <?php echo json_encode($chart_status_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const statusChartValues = <?php echo json_encode($chart_status_values, JSON_NUMERIC_CHECK); ?>;
    const trendLabels = <?php echo json_encode($trend_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const trendRates = <?php echo json_encode($trend_rates, JSON_NUMERIC_CHECK); ?>;

    const statusChartHasData = statusChartValues.reduce((total, value) => total + Number(value || 0), 0) > 0;
    const trendChartHasData = Array.isArray(trendRates) && trendRates.length > 0;

    const statusEmptyState = document.getElementById('statusEmptyState');
    const trendEmptyState = document.getElementById('trendEmptyState');

    if (statusChartHasData) {
        statusEmptyState?.classList.remove('show');
        const statusCtx = document.getElementById('statusDistributionChart');
        if (statusCtx && window.Chart) {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: statusChartLabels,
                    datasets: [{
                        data: statusChartValues,
                        backgroundColor: ['#10b981', '#e11d48', '#d97706', '#a855f7'],
                        borderColor: '#ffffff',
                        borderWidth: 2,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                font: { family: 'Manrope', weight: 700 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: (context) => {
                                    const total = context.dataset.data.reduce((sum, value) => sum + value, 0);
                                    const percentage = total > 0 ? ((context.parsed / total) * 100).toFixed(1) : '0.0';
                                    return `${context.label}: ${context.parsed} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '62%'
                }
            });
        }
    } else {
        statusEmptyState?.classList.add('show');
    }

    if (trendChartHasData) {
        trendEmptyState?.classList.remove('show');
        const trendCtx = document.getElementById('attendanceTrendChart');
        if (trendCtx && window.Chart) {
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [{
                        label: 'Attendance Rate',
                        data: trendRates,
                        borderColor: '#0f766e',
                        backgroundColor: 'rgba(15, 118, 110, 0.12)',
                        fill: true,
                        tension: 0.32,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#0f766e',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: (context) => `Attendance: ${context.parsed.y}%`
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            suggestedMax: 100,
                            ticks: {
                                callback: (value) => `${value}%`
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }
    } else {
        trendEmptyState?.classList.add('show');
    }
</script>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>
