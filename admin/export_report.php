<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once 'helpers.php';

// Only allow admin/principal/vice_principal
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['principal', 'admin', 'vice_principal'], true)) {
    header("Location: ../index.php");
    exit;
}

$current_school_id = get_current_school_id();

// Get filter parameters
$teacher_id = $_GET['teacher_id'] ?? 'all';
$report_type = $_GET['report_type'] ?? 'monthly';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$term = $_GET['term'] ?? '1st Term';
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = date('Y-m-t');
}
if ($start_date > $end_date) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}
if ($month < 1 || $month > 12) {
    $month = (int)date('m');
}
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

// Build query based on report type
$query = "SELECT 
            u.id as teacher_id,
            u.full_name,
            u.email,
            DATE(tr.sign_in_time) as attendance_date,
            TIME(tr.sign_in_time) as sign_in_time,
            tr.status,
            tas.expected_arrival,
            CASE 
                WHEN TIME(tr.sign_in_time) > tas.expected_arrival THEN 1 
                ELSE 0 
            END as is_late,
            tr.admin_notes
          FROM users u
          LEFT JOIN time_records tr ON u.id = tr.user_id
          LEFT JOIN teacher_attendance_settings tas ON u.id = tas.user_id
          WHERE u.school_id = ? AND u.role IN ('teacher', 'principal')";

$params = [$current_school_id];

if ($teacher_id !== 'all') {
    $query .= " AND u.id = ?";
    $params[] = $teacher_id;
}

switch ($report_type) {
    case 'daily':
        $query .= " AND DATE(tr.sign_in_time) = ?";
        $params[] = date('Y-m-d');
        break;
    case 'weekly':
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_end = date('Y-m-d', strtotime('sunday this week'));
        $query .= " AND DATE(tr.sign_in_time) BETWEEN ? AND ?";
        $params[] = $week_start;
        $params[] = $week_end;
        break;
    case 'monthly':
        $query .= " AND DATE(tr.sign_in_time) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        break;
    case 'termly':
        $term_dates = getTermDates($term, $year);
        $query .= " AND DATE(tr.sign_in_time) BETWEEN ? AND ?";
        $params[] = $term_dates['start'];
        $params[] = $term_dates['end'];
        break;
    case 'yearly':
        $query .= " AND YEAR(tr.sign_in_time) = ?";
        $params[] = $year;
        break;
}

$query .= " ORDER BY u.full_name, tr.sign_in_time DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rawData = $stmt->fetchAll();

$reportData = processReportData($rawData, $report_type);
$summary = calculateSummary($rawData);

require_once '../TCPDF-main/TCPDF-main/tcpdf.php';
$schoolName = get_school_display_name($current_school_id);

// Create new PDF document
$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator($schoolName);
$pdf->SetAuthor($schoolName);
$pdf->SetTitle('Attendance Report');
$pdf->SetSubject('Teacher Attendance Report');
$pdf->SetMargins(15, 20, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->AddPage();

$html = '<h2>Teacher Attendance Report</h2>';
$html .= '<p><strong>Generated On:</strong> ' . date('F j, Y H:i:s') . '</p>';
$html .= '<p><strong>Report Period:</strong> ' . getReportPeriodText($report_type, $start_date, $end_date, $term, $year) . '</p>';
$html .= '<p><strong>Total Teachers:</strong> ' . count($reportData) . '</p>';

$html .= '<table border="1" cellpadding="5">
            <thead>
                <tr>
                    <th>Teacher</th>
                    <th>Present Days</th>
                    <th>Absent Days</th>
                    <th>Late Days</th>
                    <th>Agreed Days</th>
                    <th>Not Agreed Days</th>
                    <th>Attendance Rate</th>
                </tr>
            </thead>
            <tbody>';

foreach ($reportData as $teacher) {
    $rate = $teacher['summary']['total_days'] > 0
        ? round(($teacher['summary']['present_days'] / $teacher['summary']['total_days']) * 100, 2)
        : 0;

    $html .= '<tr>
                <td>' . htmlspecialchars($teacher['full_name']) . '</td>
                <td>' . $teacher['summary']['present_days'] . '</td>
                <td>' . $teacher['summary']['absent_days'] . '</td>
                <td>' . $teacher['summary']['late_days'] . '</td>
                <td>' . $teacher['summary']['agreed_days'] . '</td>
                <td>' . $teacher['summary']['not_agreed_days'] . '</td>
                <td>' . $rate . '%</td>
              </tr>';
}

$html .= '</tbody></table>';

$html .= '<h3 style="margin-top: 20px;">Summary</h3>';
$html .= '<p><strong>Total Days:</strong> ' . $summary['total_days'] . '</p>';
$html .= '<p><strong>Overall Attendance Rate:</strong> ' . $summary['attendance_rate'] . '%</p>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('attendance_report_' . date('Ymd_His') . '.pdf', 'D');

function getReportPeriodText($report_type, $start_date, $end_date, $term, $year) {
    switch ($report_type) {
        case 'daily':
            return date('F j, Y');
        case 'weekly':
            return 'Week of ' . date('F j, Y', strtotime('monday this week'));
        case 'monthly':
            return date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date));
        case 'termly':
            return $term . ' ' . $year;
        case 'yearly':
            return 'Year ' . $year;
        default:
            return date('F j, Y');
    }
}

if (!function_exists('getTermDates')) {
    function getTermDates($term, $year) {
        $terms = [
            '1st Term' => [
                'start' => $year . '-09-01',
                'end' => $year . '-12-15'
            ],
            '2nd Term' => [
                'start' => ($year + 1) . '-01-08',
                'end' => ($year + 1) . '-04-05'
            ],
            '3rd Term' => [
                'start' => ($year + 1) . '-04-23',
                'end' => ($year + 1) . '-07-20'
            ]
        ];

        return $terms[$term] ?? $terms['1st Term'];
    }
}

function processReportData($data, $report_type) {
    $processed = [];

    foreach ($data as $row) {
        $teacher_id = $row['teacher_id'];

        if (!isset($processed[$teacher_id])) {
            $processed[$teacher_id] = [
                'teacher_id' => $teacher_id,
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'expected_arrival' => $row['expected_arrival'] ?? '08:00:00',
                'daily_records' => [],
                'summary' => [
                    'total_days' => 0,
                    'present_days' => 0,
                    'absent_days' => 0,
                    'late_days' => 0,
                    'agreed_days' => 0,
                    'not_agreed_days' => 0,
                    'pending_days' => 0
                ]
            ];
        }

        if ($row['attendance_date']) {
            $processed[$teacher_id]['daily_records'][] = [
                'date' => $row['attendance_date'],
                'sign_in_time' => $row['sign_in_time'],
                'status' => $row['status'],
                'is_late' => $row['is_late'],
                'admin_notes' => $row['admin_notes']
            ];

            $processed[$teacher_id]['summary']['total_days']++;

            if ($row['status'] === 'agreed') {
                $processed[$teacher_id]['summary']['agreed_days']++;
                $processed[$teacher_id]['summary']['present_days']++;
            } elseif ($row['status'] === 'not_agreed') {
                $processed[$teacher_id]['summary']['not_agreed_days']++;
            } else {
                $processed[$teacher_id]['summary']['pending_days']++;
            }

            if ($row['is_late']) {
                $processed[$teacher_id]['summary']['late_days']++;
            }
        } else {
            $processed[$teacher_id]['summary']['absent_days']++;
        }
    }

    return $processed;
}

function calculateSummary($data) {
    $summary = [
        'total_days' => 0,
        'present_days' => 0,
        'absent_days' => 0,
        'late_days' => 0,
        'agreed_days' => 0,
        'not_agreed_days' => 0,
        'pending_days' => 0,
        'attendance_rate' => 0
    ];

    $uniqueDays = [];
    $teacherDays = [];

    foreach ($data as $row) {
        if ($row['attendance_date']) {
            $dayKey = $row['attendance_date'];
            $teacherKey = $row['teacher_id'] . '-' . $dayKey;

            if (!in_array($dayKey, $uniqueDays, true)) {
                $uniqueDays[] = $dayKey;
                $summary['total_days']++;
            }

            if (!in_array($teacherKey, $teacherDays, true)) {
                $teacherDays[] = $teacherKey;

                if ($row['status'] === 'agreed') {
                    $summary['present_days']++;
                    $summary['agreed_days']++;
                } elseif ($row['status'] === 'not_agreed') {
                    $summary['not_agreed_days']++;
                } else {
                    $summary['pending_days']++;
                }

                if ($row['is_late']) {
                    $summary['late_days']++;
                }
            }
        }
    }

    if ($summary['total_days'] > 0) {
        $summary['attendance_rate'] = round(($summary['present_days'] / $summary['total_days']) * 100, 2);
    }

    return $summary;
}
?>
