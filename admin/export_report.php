<?php
session_start();
require_once '../config/db.php';

// Only allow teachers
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
// Include TCPDF library for PDF export
require_once('tcpdf/tcpdf.php');

// Get filter parameters
$teacher_id = $_GET['teacher_id'] ?? 'all';
$report_type = $_GET['report_type'] ?? 'monthly';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$term = $_GET['term'] ?? '1st Term';
$year = $_GET['year'] ?? date('Y');

// Generate report data (similar to attendance_reports.php)
// ... (include the same report generation logic from attendance_reports.php)

// Create new PDF document
$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('School Management System');
$pdf->SetAuthor('School Admin');
$pdf->SetTitle('Attendance Report');
$pdf->SetSubject('Teacher Attendance Report');

// Set default header data
$pdf->SetHeaderData('', 0, 'Teacher Attendance Report', 
    "Report Type: " . ucfirst($report_type) . "\n" .
    "Date: " . date('F j, Y'));

// Set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(15, 25, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 15);

// Add a page
$pdf->AddPage();

// Add content to PDF
$html = '<h2>Teacher Attendance Report</h2>';
$html .= '<p><strong>Generated On:</strong> ' . date('F j, Y H:i:s') . '</p>';
$html .= '<p><strong>Report Period:</strong> ' . getReportPeriodText() . '</p>';

// Add table with report data
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

// Add rows (you need to populate this with actual data)
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

// Add summary
$html .= '<h3 style="margin-top: 20px;">Summary</h3>';
$html .= '<p><strong>Total Days:</strong> ' . $summary['total_days'] . '</p>';
$html .= '<p><strong>Overall Attendance Rate:</strong> ' . $summary['attendance_rate'] . '%</p>';

// Output the HTML content
$pdf->writeHTML($html, true, false, true, false, '');

// Close and output PDF document
$pdf->Output('attendance_report_' . date('Ymd_His') . '.pdf', 'D');

function getReportPeriodText() {
    global $report_type, $start_date, $end_date, $term, $year;
    
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
?>
