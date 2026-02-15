<?php
// Include TCPDF library
require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

// Custom PDF class extending TCPDF
class AttendanceReportPDF extends TCPDF {
    // Page header
    public function Header() {
        // Add watermark
        $this->SetFont('helvetica', 'B', 50);
        $this->SetTextColor(220, 220, 220);
        $this->Rotate(45, 105, 200);
        $this->Text(35, 190, 'SAHAB ACADEMY');
        $this->Rotate(0);

        // Reset text color
        $this->SetTextColor(0, 0, 0);
    }

    // Page footer
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Generated on: ' . date('F d, Y H:i:s'), 0, false, 'C', 0, '', 0, false, 'T', 'M');
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
    }
}

session_start();
require_once '../config/db.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$studentId = $_SESSION['student_id'];
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Get school information
$schoolInfoStmt = $pdo->query("SELECT * FROM school_profile LIMIT 1");
$schoolInfo = $schoolInfoStmt->fetch();

// Get student information
$student_sql = "SELECT s.*, c.class_name
                FROM students s
                JOIN classes c ON s.class_id = c.id
                WHERE s.id = :id";
$student_stmt = $pdo->prepare($student_sql);
$student_stmt->execute([':id' => $studentId]);
$student = $student_stmt->fetch();

if (!$student) {
    die("Student information not found.");
}

// Parse selected month for display
list($year, $month) = explode('-', $selected_month);
$days_in_month = date('t', strtotime($selected_month . '-01'));

// Fetch attendance for selected month
$attendance_sql = "SELECT date, status, notes
                   FROM attendance
                   WHERE student_id = :id
                   AND DATE_FORMAT(date, '%Y-%m') = :selected_month
                   ORDER BY date DESC";
$attendance_stmt = $pdo->prepare($attendance_sql);
$attendance_stmt->execute([
    ':id' => $student['id'],
    ':selected_month' => $selected_month
]);
$attendance_records = $attendance_stmt->fetchAll();

// Calculate attendance statistics for selected month
$monthly_counts = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'leave' => 0
];

foreach ($attendance_records as $record) {
    $monthly_counts[$record['status']]++;
}

$total_monthly_days = array_sum($monthly_counts);
$monthly_rate = ($total_monthly_days > 0)
    ? round(($monthly_counts['present'] / $total_monthly_days) * 100, 1)
    : 0;

// Calculate overall attendance statistics (last 3 months)
$stats_sql = "SELECT
    COUNT(*) as total_days,
    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
    SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days
    FROM attendance
    WHERE student_id = :id
    AND date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([':id' => $student['id']]);
$stats = $stats_stmt->fetch();

$overall_rate = ($stats['total_days'] > 0)
    ? round(($stats['present_days'] / $stats['total_days']) * 100, 1)
    : 0;

// Create new PDF document
$pdf = new AttendanceReportPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Sahab Academy');
$pdf->SetTitle('Attendance Report - ' . $student['full_name']);
$pdf->SetSubject('Official Attendance Report');
$pdf->SetKeywords('attendance, report, school, student');

// Set default header data
$pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE, PDF_HEADER_STRING);

// Set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Set font
$pdf->SetFont('helvetica', '', 10);

// Add a page
$pdf->AddPage();

// Set some HTML content
$html = '
<style>
    .report-container {
        font-family: Arial, sans-serif;
        max-width: 800px;
        margin: 0 auto;
        padding: 20px;
        border: 2px solid #000;
    }

    .report-header {
        text-align: center;
        margin-bottom: 30px;
        border-bottom: 3px double #000;
        padding-bottom: 20px;
    }

    .report-header h2 {
        font-size: 24px;
        margin-bottom: 10px;
        color: #000;
        font-weight: bold;
    }

    .report-header h3 {
        font-size: 18px;
        margin-bottom: 10px;
        color: #333;
        font-weight: bold;
    }

    .report-header p {
        font-size: 12px;
        color: #666;
        margin: 5px 0;
    }

    .report-details {
        margin: 25px 0;
    }

    .report-details table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
        font-size: 12px;
    }

    .report-details th,
    .report-details td {
        border: 1px solid #000;
        padding: 8px;
        text-align: left;
    }

    .report-details th {
        background-color: #f2f2f2;
        font-weight: bold;
        font-size: 11px;
    }

    .attendance-table {
        margin: 20px 0;
    }

    .attendance-table th {
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        color: white;
        padding: 10px;
    }

    .attendance-table td {
        padding: 8px;
    }

    .status-present { background-color: #d4edda; color: #155724; }
    .status-absent { background-color: #f8d7da; color: #721c24; }
    .status-late { background-color: #fff3cd; color: #856404; }
    .status-leave { background-color: #d1ecf1; color: #0c5460; }

    .summary-section {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin: 20px 0;
        border: 1px solid #ddd;
    }

    .summary-grid {
        display: table;
        width: 100%;
        margin: 10px 0;
    }

    .summary-item {
        display: table-cell;
        text-align: center;
        padding: 10px;
    }

    .summary-value {
        font-size: 24px;
        font-weight: bold;
        display: block;
    }

    .summary-label {
        font-size: 12px;
        color: #666;
    }

    .report-footer {
        margin-top: 40px;
        text-align: center;
    }

    .signature-section {
        margin-top: 50px;
        display: flex;
        justify-content: space-between;
    }

    .signature-line {
        border-top: 1px solid #000;
        width: 180px;
        text-align: center;
        padding-top: 8px;
        font-size: 11px;
    }

    .watermark {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-45deg);
        font-size: 60px;
        color: rgba(0,0,0,0.08);
        z-index: 1000;
        pointer-events: none;
        font-weight: bold;
    }

    .important-notice {
        border-top: 2px solid #000;
        padding-top: 12px;
        margin-top: 25px;
        font-size: 10px;
        color: #666;
        line-height: 1.4;
    }

    .important-notice p {
        margin: 5px 0;
    }
</style>

<div class="report-container">
    <!-- Watermark -->
    <div class="watermark">' . htmlspecialchars($schoolInfo['school_name'] ?? 'SAHAB ACADEMY') . '</div>

    <!-- Report Header -->
    <div class="report-header">
        <h2>' . htmlspecialchars($schoolInfo['school_name'] ?? 'SAHAB ACADEMY') . '</h2>
        <h3>ATTENDANCE REPORT</h3>
        <p>' . htmlspecialchars($schoolInfo['school_address'] ?? 'School Address') . '</p>
        <p>Tel: ' . htmlspecialchars($schoolInfo['school_phone'] ?? 'N/A') . ' | Email: ' . htmlspecialchars($schoolInfo['school_email'] ?? 'N/A') . '</p>
    </div>

    <!-- Student Information -->
    <div class="report-details">
        <table>
            <tr>
                <th colspan="4" style="background: #f2f2f2; text-align: center;">STUDENT INFORMATION</th>
            </tr>
            <tr>
                <td><strong>Student Name:</strong></td>
                <td>' . htmlspecialchars($student['full_name']) . '</td>
                <td><strong>Admission No:</strong></td>
                <td>' . htmlspecialchars($student['admission_no']) . '</td>
            </tr>
            <tr>
                <td><strong>Class:</strong></td>
                <td>' . htmlspecialchars($student['class_name']) . '</td>
                <td><strong>Report Period:</strong></td>
                <td>' . date('F Y', strtotime($selected_month . '-01')) . '</td>
            </tr>
            <tr>
                <td><strong>Guardian:</strong></td>
                <td>' . htmlspecialchars($student['guardian_name']) . '</td>
                <td><strong>Guardian Phone:</strong></td>
                <td>' . htmlspecialchars($student['guardian_phone']) . '</td>
            </tr>
        </table>
    </div>

    <!-- Monthly Summary -->
    <div class="summary-section">
        <h4 style="margin-bottom: 15px; text-align: center; color: #2c3e50;">MONTHLY ATTENDANCE SUMMARY - ' . date('F Y', strtotime($selected_month . '-01')) . '</h4>
        <div class="summary-grid">
            <div class="summary-item">
                <span class="summary-value" style="color: #27ae60;">' . $monthly_counts['present'] . '</span>
                <span class="summary-label">Present Days</span>
            </div>
            <div class="summary-item">
                <span class="summary-value" style="color: #e74c3c;">' . $monthly_counts['absent'] . '</span>
                <span class="summary-label">Absent Days</span>
            </div>
            <div class="summary-item">
                <span class="summary-value" style="color: #f39c12;">' . $monthly_counts['late'] . '</span>
                <span class="summary-label">Late Days</span>
            </div>
            <div class="summary-item">
                <span class="summary-value" style="color: #3498db;">' . $monthly_counts['leave'] . '</span>
                <span class="summary-label">Leave Days</span>
            </div>
            <div class="summary-item">
                <span class="summary-value" style="color: #9b59b6;">' . $monthly_rate . '%</span>
                <span class="summary-label">Attendance Rate</span>
            </div>
        </div>
    </div>

    <!-- Overall Summary -->
    <div class="summary-section">
        <h4 style="margin-bottom: 15px; text-align: center; color: #2c3e50;">OVERALL ATTENDANCE SUMMARY - Last 3 Months</h4>
        <div class="summary-grid">
            <div class="summary-item">
                <span class="summary-value" style="color: #27ae60;">' . $stats['present_days'] . '</span>
                <span class="summary-label">Present Days</span>
            </div>
            <div class="summary-item">
                <span class="summary-value" style="color: #e74c3c;">' . $stats['absent_days'] . '</span>
                <span class="summary-label">Absent Days</span>
            </div>
            <div class="summary-item">
                <span class="summary-value" style="color: #f39c12;">' . $stats['late_days'] . '</span>
                <span class="summary-label">Late Days</span>
            </div>
            <div class="summary-item">
                <span class="summary-value" style="color: #3498db;">' . $stats['leave_days'] . '</span>
                <span class="summary-label">Leave Days</span>
            </div>
            <div class="summary-item">
                <span class="summary-value" style="color: #9b59b6;">' . $overall_rate . '%</span>
                <span class="summary-label">Overall Rate</span>
            </div>
        </div>
    </div>

    <!-- Attendance Details -->
    <div class="attendance-table">
        <table>
            <tr>
                <th colspan="4" style="background: #f2f2f2; text-align: center;">DAILY ATTENDANCE RECORD - ' . date('F Y', strtotime($selected_month . '-01')) . '</th>
            </tr>
            <tr>
                <th style="width: 20%;">Date</th>
                <th style="width: 15%;">Day</th>
                <th style="width: 20%;">Status</th>
                <th style="width: 45%;">Remarks</th>
            </tr>';

foreach($attendance_records as $record):
    $status_class = 'status-' . $record['status'];
    $html .= '
            <tr>
                <td>' . date('F j, Y', strtotime($record['date'])) . '</td>
                <td>' . date('l', strtotime($record['date'])) . '</td>
                <td class="' . $status_class . '">' . ucfirst($record['status']) . '</td>
                <td>' . htmlspecialchars($record['notes'] ?? '-') . '</td>
            </tr>';
endforeach;

$html .= '
        </table>
    </div>

    <!-- Report Footer -->
    <div class="report-footer">
        <div style="margin: 30px 0; text-align: center;">
            <p><strong>Report Generated By:</strong></p>
            <div class="signature-section">
                <div class="signature-line">
                    <p>_________________________</p>
                    <p>Student Signature</p>
                </div>
                <div class="signature-line">
                    <p>_________________________</p>
                    <p>School Authority</p>
                </div>
            </div>
        </div>

        <div class="important-notice">
            <p><strong>Important Notice:</strong></p>
            <p>1. This attendance report is an official document from ' . htmlspecialchars($schoolInfo['school_name'] ?? 'Sahab Academy') . '.</p>
            <p>2. Please keep this report for your records and present it when required.</p>
            <p>3. For any discrepancies or concerns, contact the school administration immediately.</p>
            <p>4. Report generated on: ' . date('F d, Y h:i A') . '</p>
        </div>
    </div>
</div>';

// Print text using writeHTMLCell()
$pdf->writeHTML($html, true, false, true, false, '');

// Close and output PDF document
$filename = 'Attendance_Report_' . $student['admission_no'] . '_' . $selected_month . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D');
exit;
?>

