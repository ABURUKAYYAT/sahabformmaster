<?php
require_once '../TCPDF-main/TCPDF-main/tcpdf.php';
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

class AttendanceReportPDF extends TCPDF
{
    private string $schoolName;
    private string $reportMonth;

    public function __construct(
        string $orientation,
        string $unit,
        string $format,
        bool $unicode,
        string $encoding,
        bool $diskcache,
        string $school_name = 'School',
        string $report_month = ''
    ) {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache);
        $this->schoolName = trim($school_name) !== '' ? $school_name : 'School';
        $this->reportMonth = trim($report_month);
    }

    public function Header(): void
    {
        $this->SetFillColor(15, 23, 42);
        $this->Rect(0, 0, 210, 19, 'F');
        $this->SetFillColor(20, 184, 166);
        $this->Rect(0, 19, 210, 3.2, 'F');

        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 11);
        $this->SetXY(12, 5);
        $this->Cell(115, 5.8, strtoupper($this->schoolName), 0, 0, 'L');

        $this->SetFont('helvetica', '', 8.2);
        $this->SetXY(127, 5.2);
        $month_label = $this->reportMonth !== '' ? $this->reportMonth : date('F Y');
        $this->Cell(71, 5.8, 'Attendance Report | ' . $month_label, 0, 0, 'R');
    }

    public function Footer(): void
    {
        $this->SetY(-13.5);
        $this->SetDrawColor(203, 213, 225);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->SetY(-11);
        $this->SetTextColor(100, 116, 139);
        $this->SetFont('helvetica', '', 7.8);
        $this->Cell(95, 5, 'Generated on ' . date('F d, Y h:i A'), 0, 0, 'L');
        $this->Cell(95, 5, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

function pdf_safe($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function status_badge_style(string $status): array
{
    $map = [
        'present' => ['bg' => '#dcfce7', 'text' => '#166534', 'border' => '#86efac', 'label' => 'Present'],
        'absent' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'border' => '#fca5a5', 'label' => 'Absent'],
        'late' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fcd34d', 'label' => 'Late'],
        'leave' => ['bg' => '#f3e8ff', 'text' => '#6b21a8', 'border' => '#d8b4fe', 'label' => 'Leave'],
    ];

    return $map[$status] ?? ['bg' => '#f1f5f9', 'text' => '#334155', 'border' => '#cbd5e1', 'label' => ucfirst($status)];
}

if (!isset($_SESSION['student_id'])) {
    header('Location: index.php');
    exit();
}

$current_school_id = get_current_school_id();
$student_id = (int) ($_SESSION['student_id'] ?? 0);
$selected_month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}
$selected_month_label = date('F Y', strtotime($selected_month . '-01'));

$schoolInfo = [];
try {
    $school_stmt = $pdo->prepare('SELECT * FROM school_profile WHERE school_id = :school_id LIMIT 1');
    $school_stmt->execute(['school_id' => $current_school_id]);
    $schoolInfo = $school_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $schoolInfo = [];
}

$student_sql = "SELECT s.*, c.class_name
                FROM students s
                JOIN classes c ON s.class_id = c.id AND c.school_id = :school_id_class
                WHERE s.id = :id
                  AND s.school_id = :school_id
                LIMIT 1";
$student_stmt = $pdo->prepare($student_sql);
$student_stmt->execute([
    'id' => $student_id,
    'school_id' => $current_school_id,
    'school_id_class' => $current_school_id,
]);
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die('Student information not found.');
}

$attendance_sql = "SELECT a.date, a.status, a.notes
                   FROM attendance a
                   JOIN students s ON a.student_id = s.id AND s.school_id = :school_id_student
                   WHERE a.student_id = :id
                     AND a.school_id = :school_id
                     AND DATE_FORMAT(a.date, '%Y-%m') = :selected_month
                   ORDER BY a.date DESC";
$attendance_stmt = $pdo->prepare($attendance_sql);
$attendance_stmt->execute([
    'id' => (int) $student['id'],
    'school_id' => $current_school_id,
    'school_id_student' => $current_school_id,
    'selected_month' => $selected_month,
]);
$attendance_records = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);

$monthly_counts = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'leave' => 0,
];
foreach ($attendance_records as $record) {
    $status_key = strtolower(trim((string) ($record['status'] ?? '')));
    if (isset($monthly_counts[$status_key])) {
        $monthly_counts[$status_key]++;
    }
}

$total_monthly_days = array_sum($monthly_counts);
$monthly_rate = $total_monthly_days > 0
    ? round((($monthly_counts['present'] + $monthly_counts['late']) / $total_monthly_days) * 100, 1)
    : 0.0;

$stats_sql = "SELECT
    COUNT(*) AS total_days,
    SUM(CASE WHEN LOWER(TRIM(a.status)) = 'present' THEN 1 ELSE 0 END) AS present_days,
    SUM(CASE WHEN LOWER(TRIM(a.status)) = 'absent' THEN 1 ELSE 0 END) AS absent_days,
    SUM(CASE WHEN LOWER(TRIM(a.status)) = 'late' THEN 1 ELSE 0 END) AS late_days,
    SUM(CASE WHEN LOWER(TRIM(a.status)) = 'leave' THEN 1 ELSE 0 END) AS leave_days
    FROM attendance a
    JOIN students s ON a.student_id = s.id AND s.school_id = :school_id_student
    WHERE a.student_id = :id
      AND a.school_id = :school_id
      AND a.date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([
    'id' => (int) $student['id'],
    'school_id' => $current_school_id,
    'school_id_student' => $current_school_id,
]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$stats = [
    'total_days' => (int) ($stats['total_days'] ?? 0),
    'present_days' => (int) ($stats['present_days'] ?? 0),
    'absent_days' => (int) ($stats['absent_days'] ?? 0),
    'late_days' => (int) ($stats['late_days'] ?? 0),
    'leave_days' => (int) ($stats['leave_days'] ?? 0),
];
$overall_rate = $stats['total_days'] > 0
    ? round((($stats['present_days'] + $stats['late_days']) / $stats['total_days']) * 100, 1)
    : 0.0;

$attendance_signal = 'Needs Improvement';
if ($monthly_rate >= 90) {
    $attendance_signal = 'Excellent Attendance';
} elseif ($monthly_rate >= 75) {
    $attendance_signal = 'Good Attendance';
} elseif ($monthly_rate >= 60) {
    $attendance_signal = 'Fair Attendance';
}

$school_name_raw = trim((string) ($schoolInfo['school_name'] ?? get_school_display_name($current_school_id) ?? 'School'));
$school_address_raw = trim((string) ($schoolInfo['school_address'] ?? ''));
$school_phone_raw = trim((string) ($schoolInfo['school_phone'] ?? 'N/A'));
$school_email_raw = trim((string) ($schoolInfo['school_email'] ?? 'N/A'));
$school_motto_raw = trim((string) ($schoolInfo['school_motto'] ?? ''));
$guardian_phone_raw = trim((string) ($student['guardian_phone'] ?? $student['guardian_contact'] ?? 'N/A'));

$pdf = new AttendanceReportPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false, $school_name_raw, $selected_month_label);
$pdf->SetCreator($school_name_raw);
$pdf->SetAuthor($school_name_raw);
$pdf->SetTitle('Attendance Report - ' . (string) ($student['full_name'] ?? 'Student'));
$pdf->SetSubject('Student Attendance Report');
$pdf->SetKeywords('attendance, student, report');
$pdf->SetMargins(12, 28, 12);
$pdf->SetHeaderMargin(6);
$pdf->SetFooterMargin(8);
$pdf->SetAutoPageBreak(true, 16);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
$pdf->SetFont('helvetica', '', 9.5);
$pdf->AddPage();

$school_name = pdf_safe($school_name_raw);
$school_address = pdf_safe($school_address_raw !== '' ? $school_address_raw : 'No address provided');
$school_phone = pdf_safe($school_phone_raw !== '' ? $school_phone_raw : 'N/A');
$school_email = pdf_safe($school_email_raw !== '' ? $school_email_raw : 'N/A');
$school_motto = pdf_safe($school_motto_raw !== '' ? $school_motto_raw : 'Excellence and Character');
$student_name = pdf_safe((string) ($student['full_name'] ?? 'Student'));
$student_class = pdf_safe((string) ($student['class_name'] ?? 'N/A'));
$admission_no = pdf_safe((string) ($student['admission_no'] ?? 'N/A'));
$guardian_phone = pdf_safe($guardian_phone_raw !== '' ? $guardian_phone_raw : 'N/A');

$html = '
<style>
    .report-shell { border: 1px solid #d7e3f4; border-radius: 10px; padding: 10px; }
    .hero { background-color: #0f172a; color: #ffffff; border: 1px solid #1e293b; border-radius: 9px; padding: 12px; }
    .hero-kicker { font-size: 8px; text-transform: uppercase; letter-spacing: 1.8px; color: #99f6e4; }
    .hero-title { font-size: 17px; font-weight: bold; margin-top: 5px; color: #ffffff; }
    .hero-sub { font-size: 9px; color: #cbd5e1; margin-top: 4px; line-height: 1.45; }
    .hero-meta { margin-top: 8px; width: 100%; border-collapse: collapse; }
    .hero-meta td { font-size: 8.5px; color: #e2e8f0; padding: 2px 0; }
    .section-title { font-size: 11px; font-weight: bold; color: #0f172a; margin-top: 12px; margin-bottom: 5px; }
    .info-table, .kpi-table, .log-table { width: 100%; border-collapse: collapse; }
    .info-table th, .info-table td { border: 1px solid #dbe6f1; padding: 6px; font-size: 9px; }
    .info-table th { background-color: #f1f5f9; color: #334155; text-align: left; width: 19%; font-weight: bold; }
    .kpi-table td { padding: 4px; vertical-align: top; }
    .kpi-card { border: 1px solid #d8e3f4; border-radius: 8px; padding: 8px; text-align: center; }
    .kpi-label { font-size: 8px; text-transform: uppercase; letter-spacing: 1.1px; color: #64748b; }
    .kpi-value { font-size: 16px; font-weight: bold; margin-top: 4px; }
    .kpi-note { font-size: 8px; margin-top: 2px; color: #64748b; }
    .signal-box { margin-top: 6px; border: 1px solid #bfdbfe; border-radius: 7px; background-color: #eff6ff; padding: 6px 8px; font-size: 8.7px; color: #1e3a8a; }
    .log-table th, .log-table td { border: 1px solid #d9e2ec; padding: 6px; font-size: 8.7px; }
    .log-table th { background-color: #0f172a; color: #f8fafc; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6px; }
    .note-box { margin-top: 8px; border: 1px solid #dbe6f1; border-radius: 8px; background: #f8fafc; padding: 8px; }
    .note-box p { margin: 0 0 3px 0; font-size: 8.4px; color: #475569; line-height: 1.42; }
</style>

<div class="report-shell">
    <div class="hero">
        <div class="hero-kicker">Student Attendance Report</div>
        <div class="hero-title">Monthly Attendance Analytics</div>
        <div class="hero-sub">A professional summary of attendance behavior for ' . pdf_safe($selected_month_label) . ', including status trends and actionable indicators.</div>
        <table class="hero-meta">
            <tr>
                <td width="58%"><strong>School:</strong> ' . $school_name . '</td>
                <td width="42%"><strong>Reporting Month:</strong> ' . pdf_safe($selected_month_label) . '</td>
            </tr>
            <tr>
                <td><strong>Phone:</strong> ' . $school_phone . '</td>
                <td><strong>Email:</strong> ' . $school_email . '</td>
            </tr>
            <tr>
                <td colspan="2"><strong>Motto:</strong> ' . $school_motto . '</td>
            </tr>
        </table>
    </div>

    <div class="section-title">Student Profile Snapshot</div>
    <table class="info-table">
        <tr>
            <th>Student Name</th>
            <td width="31%">' . $student_name . '</td>
            <th>Admission No</th>
            <td width="31%">' . $admission_no . '</td>
        </tr>
        <tr>
            <th>Class</th>
            <td>' . $student_class . '</td>
            <th>Guardian Phone</th>
            <td>' . $guardian_phone . '</td>
        </tr>
        <tr>
            <th>School Address</th>
            <td colspan="3">' . $school_address . '</td>
        </tr>
    </table>

    <div class="section-title">Attendance Performance Dashboard</div>
    <table class="kpi-table">
        <tr>
            <td width="33.33%">
                <div class="kpi-card" style="background:#ecfdf5;border-color:#86efac;">
                    <div class="kpi-label">Present</div>
                    <div class="kpi-value" style="color:#166534;">' . (int) $monthly_counts['present'] . '</div>
                    <div class="kpi-note">Days marked present</div>
                </div>
            </td>
            <td width="33.33%">
                <div class="kpi-card" style="background:#fee2e2;border-color:#fca5a5;">
                    <div class="kpi-label">Absent</div>
                    <div class="kpi-value" style="color:#991b1b;">' . (int) $monthly_counts['absent'] . '</div>
                    <div class="kpi-note">Days missed</div>
                </div>
            </td>
            <td width="33.33%">
                <div class="kpi-card" style="background:#fef3c7;border-color:#fcd34d;">
                    <div class="kpi-label">Late</div>
                    <div class="kpi-value" style="color:#92400e;">' . (int) $monthly_counts['late'] . '</div>
                    <div class="kpi-note">Late arrivals</div>
                </div>
            </td>
        </tr>
        <tr>
            <td width="33.33%">
                <div class="kpi-card" style="background:#f3e8ff;border-color:#d8b4fe;">
                    <div class="kpi-label">Leave</div>
                    <div class="kpi-value" style="color:#6b21a8;">' . (int) $monthly_counts['leave'] . '</div>
                    <div class="kpi-note">Approved leave days</div>
                </div>
            </td>
            <td width="33.33%">
                <div class="kpi-card" style="background:#ecfeff;border-color:#67e8f9;">
                    <div class="kpi-label">Monthly Rate</div>
                    <div class="kpi-value" style="color:#0f766e;">' . number_format($monthly_rate, 1) . '%</div>
                    <div class="kpi-note">Present + Late over total</div>
                </div>
            </td>
            <td width="33.33%">
                <div class="kpi-card" style="background:#eff6ff;border-color:#93c5fd;">
                    <div class="kpi-label">3-Month Rate</div>
                    <div class="kpi-value" style="color:#1d4ed8;">' . number_format($overall_rate, 1) . '%</div>
                    <div class="kpi-note">Rolling trend indicator</div>
                </div>
            </td>
        </tr>
    </table>
    <div class="signal-box">
        <strong>Performance Signal:</strong> ' . pdf_safe($attendance_signal) . '
        &nbsp;|&nbsp;
        <strong>Total Logged Days (This Month):</strong> ' . (int) $total_monthly_days . '
    </div>

    <div class="section-title">Daily Attendance Log - ' . pdf_safe($selected_month_label) . '</div>
    <table class="log-table">
        <tr>
            <th width="20%">Date</th>
            <th width="16%">Day</th>
            <th width="18%">Status</th>
            <th width="46%">Remarks</th>
        </tr>';

if (empty($attendance_records)) {
    $html .= '
        <tr>
            <td colspan="4" style="text-align:center;color:#64748b;background:#f8fafc;">No attendance records found for this month.</td>
        </tr>';
} else {
    foreach ($attendance_records as $index => $record) {
        $status_key = strtolower(trim((string) ($record['status'] ?? 'absent')));
        $badge = status_badge_style($status_key);
        $row_bg = ($index % 2 === 0) ? '#ffffff' : '#f8fafc';

        $date_ts = strtotime((string) ($record['date'] ?? ''));
        $date_label = $date_ts !== false ? date('M d, Y', $date_ts) : 'Invalid date';
        $day_label = $date_ts !== false ? date('l', $date_ts) : '-';
        $notes = trim((string) ($record['notes'] ?? ''));
        $notes_label = $notes !== '' ? nl2br(pdf_safe($notes)) : '<span style="color:#94a3b8;">No note provided</span>';

        $html .= '
        <tr style="background:' . $row_bg . ';">
            <td>' . pdf_safe($date_label) . '</td>
            <td>' . pdf_safe($day_label) . '</td>
            <td>
                <span style="display:inline-block;padding:3px 8px;border-radius:999px;border:1px solid ' . $badge['border'] . ';background:' . $badge['bg'] . ';color:' . $badge['text'] . ';font-weight:bold;font-size:8px;">
                    ' . pdf_safe($badge['label']) . '
                </span>
            </td>
            <td>' . $notes_label . '</td>
        </tr>';
    }
}

$html .= '
    </table>

    <div class="note-box">
        <p><strong>Policy Notes</strong></p>
        <p>1. This document is an official attendance extract generated by ' . $school_name . '.</p>
        <p>2. Monthly attendance rate is calculated as (Present + Late) / Total recorded days.</p>
        <p>3. Contact the school administration promptly for corrections or attendance disputes.</p>
    </div>
</div>';

$pdf->writeHTML($html, true, false, true, false, '');

$safe_admission = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($student['admission_no'] ?? 'student'));
$filename = 'Attendance_Report_' . $safe_admission . '_' . $selected_month . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D');
exit;
?>
