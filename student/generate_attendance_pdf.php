<?php
require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

class AttendanceReportPDF extends TCPDF
{
    public function Header(): void
    {
        $this->SetFont('helvetica', 'B', 46);
        $this->SetTextColor(225, 231, 240);
        $this->Rotate(42, 100, 205);
        $this->Text(24, 190, 'ATTENDANCE');
        $this->Rotate(0);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer(): void
    {
        $this->SetY(-14);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 10, 'Generated on ' . date('F d, Y h:i A'), 0, false, 'L', 0, '', 0, false, 'T', 'M');
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
    }
}

session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

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

$schoolInfoStmt = $pdo->query('SELECT * FROM school_profile LIMIT 1');
$schoolInfo = $schoolInfoStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$student_sql = "SELECT s.*, c.class_name
                FROM students s
                JOIN classes c ON s.class_id = c.id
                WHERE s.id = :id
                  AND s.school_id = :school_id";
$student_stmt = $pdo->prepare($student_sql);
$student_stmt->execute([
    ':id' => $student_id,
    ':school_id' => $current_school_id,
]);
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die('Student information not found.');
}

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
    $status = strtolower((string) ($record['status'] ?? ''));
    if (isset($monthly_counts[$status])) {
        $monthly_counts[$status]++;
    }
}

$total_monthly_days = array_sum($monthly_counts);
$monthly_rate = $total_monthly_days > 0
    ? round((($monthly_counts['present'] + $monthly_counts['late']) / $total_monthly_days) * 100, 1)
    : 0;

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
$overall_rate = $stats['total_days'] > 0
    ? round((($stats['present_days'] + $stats['late_days']) / $stats['total_days']) * 100, 1)
    : 0;

$school_name = htmlspecialchars((string) ($schoolInfo['school_name'] ?? get_school_display_name() ?? 'School'));
$school_address = htmlspecialchars((string) ($schoolInfo['school_address'] ?? ''));
$school_phone = htmlspecialchars((string) ($schoolInfo['school_phone'] ?? 'N/A'));
$school_email = htmlspecialchars((string) ($schoolInfo['school_email'] ?? 'N/A'));

$pdf = new AttendanceReportPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator($school_name);
$pdf->SetAuthor($school_name);
$pdf->SetTitle('Attendance Report - ' . $student['full_name']);
$pdf->SetSubject('Student Attendance Report');
$pdf->SetKeywords('attendance, student, report');
$pdf->SetHeaderData('', 0, '', '');
$pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN]);
$pdf->setFooterFont([PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA]);
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
$pdf->SetMargins(12, 12, 12);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(8);
$pdf->SetAutoPageBreak(true, 16);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
$pdf->SetFont('helvetica', '', 10);
$pdf->AddPage();

$html = '
<style>
    .sheet { border: 1px solid #dbe3ee; border-radius: 12px; padding: 12px; }
    .hero { background: #0f172a; color: #ffffff; border-radius: 10px; padding: 14px; }
    .hero-kicker { font-size: 9px; letter-spacing: 1.8px; text-transform: uppercase; color: #a7f3d0; }
    .hero-title { font-size: 21px; font-weight: bold; margin-top: 6px; }
    .hero-sub { font-size: 10px; color: #cbd5e1; margin-top: 4px; }
    .hero-meta { margin-top: 8px; }
    .hero-meta td { font-size: 9px; color: #e2e8f0; }

    .section-title { font-size: 12px; font-weight: bold; color: #0f172a; margin-top: 14px; margin-bottom: 6px; }

    .info-table, .summary-table, .record-table { width: 100%; border-collapse: collapse; }
    .info-table th, .info-table td,
    .summary-table th, .summary-table td,
    .record-table th, .record-table td {
        border: 1px solid #d9e2ec;
        padding: 7px;
        font-size: 9.8px;
    }

    .info-table th,
    .summary-table th,
    .record-table th {
        background: #f8fafc;
        color: #334155;
        font-weight: bold;
    }

    .summary-table td { text-align: center; }
    .summary-value { font-size: 16px; font-weight: bold; }
    .summary-label { font-size: 9px; color: #64748b; }

    .status-present { background: #dcfce7; color: #166534; font-weight: bold; }
    .status-absent { background: #ffe4e6; color: #be123c; font-weight: bold; }
    .status-late { background: #fef3c7; color: #b45309; font-weight: bold; }
    .status-leave { background: #f3e8ff; color: #7e22ce; font-weight: bold; }

    .note-box { margin-top: 12px; border: 1px solid #cbd5e1; background: #f8fafc; border-radius: 8px; padding: 8px; }
    .note-box p { font-size: 9px; color: #475569; line-height: 1.5; }
</style>

<div class="sheet">
    <div class="hero">
        <div class="hero-kicker">Attendance Report</div>
        <div class="hero-title">Student Attendance Analytics</div>
        <div class="hero-sub">Professional monthly attendance summary and trend snapshot.</div>
        <table class="hero-meta" cellpadding="2">
            <tr>
                <td><strong>School:</strong> ' . $school_name . '</td>
                <td><strong>Month:</strong> ' . date('F Y', strtotime($selected_month . '-01')) . '</td>
            </tr>
            <tr>
                <td><strong>Phone:</strong> ' . $school_phone . '</td>
                <td><strong>Email:</strong> ' . $school_email . '</td>
            </tr>
        </table>
    </div>

    <div class="section-title">Student Details</div>
    <table class="info-table" cellpadding="3">
        <tr>
            <th width="20%">Student Name</th>
            <td width="30%">' . htmlspecialchars((string) $student['full_name']) . '</td>
            <th width="20%">Admission No</th>
            <td width="30%">' . htmlspecialchars((string) ($student['admission_no'] ?? '-')) . '</td>
        </tr>
        <tr>
            <th>Class</th>
            <td>' . htmlspecialchars((string) ($student['class_name'] ?? '-')) . '</td>
            <th>Guardian Phone</th>
            <td>' . htmlspecialchars((string) ($student['guardian_phone'] ?? '-')) . '</td>
        </tr>
        <tr>
            <th>School Address</th>
            <td colspan="3">' . $school_address . '</td>
        </tr>
    </table>

    <div class="section-title">Monthly and 3-Month Performance</div>
    <table class="summary-table" cellpadding="3">
        <tr>
            <th>Present</th>
            <th>Absent</th>
            <th>Late</th>
            <th>Leave</th>
            <th>Monthly Rate</th>
            <th>3-Month Rate</th>
        </tr>
        <tr>
            <td><span class="summary-value" style="color:#047857;">' . $monthly_counts['present'] . '</span><br><span class="summary-label">this month</span></td>
            <td><span class="summary-value" style="color:#be123c;">' . $monthly_counts['absent'] . '</span><br><span class="summary-label">this month</span></td>
            <td><span class="summary-value" style="color:#b45309;">' . $monthly_counts['late'] . '</span><br><span class="summary-label">this month</span></td>
            <td><span class="summary-value" style="color:#7e22ce;">' . $monthly_counts['leave'] . '</span><br><span class="summary-label">this month</span></td>
            <td><span class="summary-value" style="color:#0f766e;">' . $monthly_rate . '%</span><br><span class="summary-label">present + late</span></td>
            <td><span class="summary-value" style="color:#1d4ed8;">' . $overall_rate . '%</span><br><span class="summary-label">last 3 months</span></td>
        </tr>
    </table>

    <div class="section-title">Daily Attendance Log (' . date('F Y', strtotime($selected_month . '-01')) . ')</div>
    <table class="record-table" cellpadding="4">
        <tr>
            <th width="20%">Date</th>
            <th width="16%">Day</th>
            <th width="16%">Status</th>
            <th width="48%">Remarks</th>
        </tr>';

if (empty($attendance_records)) {
    $html .= '
        <tr>
            <td colspan="4" style="text-align:center;color:#64748b;">No attendance records found for this month.</td>
        </tr>';
} else {
    foreach ($attendance_records as $record) {
        $status = strtolower((string) ($record['status'] ?? 'absent'));
        $status_class = 'status-' . preg_replace('/[^a-z]/', '', $status);
        $html .= '
        <tr>
            <td>' . date('F j, Y', strtotime((string) $record['date'])) . '</td>
            <td>' . date('l', strtotime((string) $record['date'])) . '</td>
            <td class="' . $status_class . '">' . ucfirst($status) . '</td>
            <td>' . htmlspecialchars((string) ($record['notes'] ?? '-')) . '</td>
        </tr>';
    }
}

$html .= '
    </table>

    <div class="note-box">
        <p><strong>Notes:</strong></p>
        <p>1. This report is an official attendance extract from ' . $school_name . '.</p>
        <p>2. Monthly attendance rate is calculated using present and late entries.</p>
        <p>3. For corrections or disputes, contact school administration immediately.</p>
    </div>
</div>';

$pdf->writeHTML($html, true, false, true, false, '');

$safe_admission = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($student['admission_no'] ?? 'student'));
$filename = 'Attendance_Report_' . $safe_admission . '_' . $selected_month . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D');
exit;
?>
