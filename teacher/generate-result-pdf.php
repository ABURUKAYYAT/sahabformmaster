<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
$current_school_id = require_school_auth();

// Validate POST data
$student_id = $_POST['student_id'] ?? null;
$class_id = $_POST['class_id'] ?? null;
$term = $_POST['term'] ?? null;

if (!$student_id || !$class_id || !$term) {
    die("Invalid request parameters.");
}

// Fetch school profile - filtered by school_id
$stmt = $pdo->prepare("SELECT * FROM school_profile WHERE school_id = ?");
$stmt->execute([$current_school_id]);
$school = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch class information - filtered by school_id
$stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :class_id AND school_id = :school_id");
$stmt->execute(['class_id' => $class_id, 'school_id' => $current_school_id]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch student information - filtered by school_id
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = :student_id AND school_id = :school_id");
$stmt->execute(['student_id' => $student_id, 'school_id' => $current_school_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch results for the student - filtered by school_id
$stmt = $pdo->prepare("
    SELECT r.*, sub.subject_name
    FROM results r
    JOIN subjects sub ON r.subject_id = sub.id
    WHERE r.student_id = :student_id AND r.term = :term AND sub.school_id = :school_id
    ORDER BY sub.subject_name
");
$stmt->execute(['student_id' => $student_id, 'term' => $term, 'school_id' => $current_school_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate class statistics - filtered by school_id
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT r.student_id) as total_students,
           AVG(r.total_ca + r.exam) as class_average,
           MAX(r.total_ca + r.exam) as highest_score,
           MIN(r.total_ca + r.exam) as lowest_score
    FROM results r
    JOIN students s ON r.student_id = s.id
    WHERE s.class_id = :class_id AND r.term = :term AND s.school_id = :school_id
");
$stmt->execute(['class_id' => $class_id, 'term' => $term, 'school_id' => $current_school_id]);
$class_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate student's position in class
if (!empty($results)) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 as student_position
        FROM (
            SELECT r.student_id, AVG(r.total_ca + r.exam) as avg_score
            FROM results r
            JOIN students s ON r.student_id = s.id
            WHERE s.class_id = :class_id AND r.term = :term
            GROUP BY r.student_id
        ) student_averages
        WHERE student_averages.avg_score > (
            SELECT AVG(r2.total_ca + r2.exam)
            FROM results r2
            WHERE r2.student_id = :student_id AND r2.term = :term
        )
    ");
    $stmt->execute(['class_id' => $class_id,'school_id' => $current_school_id, 'student_id' => $student_id, 'term' => $term]);
    $position = $stmt->fetchColumn();
} else {
    $position = 'N/A';
}

// Extend TCPDF class for custom header/footer
class StudentTranscriptPDF extends TCPDF {

    private $school;
    private $student;
    private $class;
    private $term;

    public function __construct($school, $student, $class, $term) {
        parent::__construct();
        $this->school = $school;
        $this->student = $student;
        $this->class = $class;
        $this->term = $term;
    }

    // Page header
    public function Header() {
        // School logo
        if (!empty($this->school['school_logo']) && file_exists('../' . $this->school['school_logo'])) {
            $this->Image('../' . $this->school['school_logo'], 15, 10, 25, 25, '', '', '', false, 300, '', false, false, 0);
        }

        // School name and motto
        $this->SetFont('helvetica', 'B', 16);
        $this->SetXY(45, 12);
        $this->Cell(0, 8, strtoupper($this->school['school_name']), 0, 1, 'L');

        $this->SetFont('helvetica', 'I', 12);
        $this->SetXY(45, 20);
        $this->Cell(0, 6, $this->school['school_motto'], 0, 1, 'L');

        // Address
        $this->SetFont('helvetica', '', 9);
        $this->SetXY(45, 26);
        $this->Cell(0, 5, $this->school['school_address'], 0, 1, 'L');

        // Decorative line
        $this->SetLineWidth(0.5);
        $this->Line(15, 35, 195, 35);

        // Document title
        $this->SetFont('helvetica', 'B', 14);
        $this->SetXY(15, 40);
        $this->Cell(0, 8, 'STUDENT ACADEMIC TRANSCRIPT', 0, 1, 'C');

        // Student information box
        $this->SetLineWidth(0.3);
        $this->Rect(15, 50, 180, 25);

        $this->SetFont('helvetica', '', 10);
        $this->SetXY(17, 52);
        $this->Cell(40, 5, 'Student Name:', 0, 0, 'L');
        $this->Cell(0, 5, $this->student['full_name'], 0, 1, 'L');

        $this->SetXY(17, 58);
        $this->Cell(40, 5, 'Admission No:', 0, 0, 'L');
        $this->Cell(50, 5, $this->student['admission_no'], 0, 0, 'L');
        $this->Cell(30, 5, 'Class:', 0, 0, 'L');
        $this->Cell(0, 5, $this->class['class_name'], 0, 1, 'L');

        $this->SetXY(17, 64);
        $this->Cell(40, 5, 'Term:', 0, 0, 'L');
        $this->Cell(50, 5, $this->term, 0, 0, 'L');
        $this->Cell(30, 5, 'Session:', 0, 0, 'L');
        $this->Cell(0, 5, date('Y') . '/' . (date('Y') + 1), 0, 1, 'L');

        $this->SetY(80);
    }

    // Page footer
    public function Footer() {
        $this->SetY(-25);

        // Signature lines
        $this->SetFont('helvetica', '', 9);
        $this->SetXY(15, -20);
        $this->Cell(60, 5, '_______________________________', 0, 0, 'C');
        $this->Cell(60, 5, '_______________________________', 0, 0, 'C');
        $this->Cell(60, 5, '_______________________________', 0, 1, 'C');

        $this->SetXY(15, -15);
        $this->Cell(60, 5, 'Class Teacher', 0, 0, 'C');
        $this->Cell(60, 5, 'Principal', 0, 0, 'C');
        $this->Cell(60, 5, 'Parent/Guardian', 0, 1, 'C');

        // Page number
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

// Create PDF instance
$pdf = new StudentTranscriptPDF($school, $student, $class, $term);

// Set document information
$pdf->SetCreator('SahabFormMaster School Management System');
$pdf->SetAuthor($school['school_name']);
$pdf->SetTitle('Academic Transcript - ' . $student['full_name']);
$pdf->SetSubject('Student Academic Results for ' . $term);

// Set margins
$pdf->SetMargins(15, 85, 15); // Left, Top, Right
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(30);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 35);

// Add first page
$pdf->AddPage();

// Academic Performance Section
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 10, 'ACADEMIC PERFORMANCE', 0, 1, 'L');

// Results table
if (!empty($results)) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(240, 240, 240);

    // Table header
    $pdf->Cell(8, 8, 'S/N', 1, 0, 'C', 1);
    $pdf->Cell(45, 8, 'Subject', 1, 0, 'C', 1);
    $pdf->Cell(15, 8, '1st CA', 1, 0, 'C', 1);
    $pdf->Cell(15, 8, '2nd CA', 1, 0, 'C', 1);
    $pdf->Cell(18, 8, 'CA Total', 1, 0, 'C', 1);
    $pdf->Cell(15, 8, 'Exam', 1, 0, 'C', 1);
    $pdf->Cell(18, 8, 'Total', 1, 0, 'C', 1);
    $pdf->Cell(12, 8, 'Grade', 1, 0, 'C', 1);
    $pdf->Cell(24, 8, 'Remark', 1, 1, 'C', 1);

    // Table data
    $pdf->SetFont('helvetica', '', 8);
    $total_score = 0;
    $subject_count = count($results);

    foreach ($results as $index => $result) {
        $first_ca = floatval($result['first_ca'] ?? 0);
        $second_ca = floatval($result['second_ca'] ?? 0);
        $ca_total = $first_ca + $second_ca;
        $exam = floatval($result['exam'] ?? 0);
        $grand_total = $ca_total + $exam;
        $total_score += $grand_total;

        $grade_data = calculateGrade($grand_total);

        $pdf->Cell(8, 6, ($index + 1), 1, 0, 'C');
        $pdf->Cell(45, 6, substr($result['subject_name'], 0, 20), 1, 0, 'L');
        $pdf->Cell(15, 6, number_format($first_ca, 1), 1, 0, 'C');
        $pdf->Cell(15, 6, number_format($second_ca, 1), 1, 0, 'C');
        $pdf->Cell(18, 6, number_format($ca_total, 1), 1, 0, 'C');
        $pdf->Cell(15, 6, number_format($exam, 1), 1, 0, 'C');
        $pdf->Cell(18, 6, number_format($grand_total, 1), 1, 0, 'C');
        $pdf->Cell(12, 6, $grade_data['grade'], 1, 0, 'C');
        $pdf->Cell(24, 6, $grade_data['remark'], 1, 1, 'C');
    }

    // Summary row
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(8, 8, '', 1, 0, 'C', 1);
    $pdf->Cell(45, 8, 'OVERALL PERFORMANCE', 1, 0, 'L', 1);
    $pdf->Cell(15, 8, '', 1, 0, 'C', 1);
    $pdf->Cell(15, 8, '', 1, 0, 'C', 1);
    $pdf->Cell(18, 8, '', 1, 0, 'C', 1);
    $pdf->Cell(15, 8, '', 1, 0, 'C', 1);

    $overall_average = $subject_count > 0 ? $total_score / $subject_count : 0;
    $overall_grade = calculateGrade($overall_average);

    $pdf->Cell(18, 8, number_format($overall_average, 1), 1, 0, 'C', 1);
    $pdf->Cell(12, 8, $overall_grade['grade'], 1, 0, 'C', 1);
    $pdf->Cell(24, 8, $overall_grade['remark'], 1, 1, 'C', 1);

    $pdf->Ln(5);

    // Class Statistics
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'CLASS STATISTICS', 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(40, 6, 'Class Position:', 0, 0, 'L');
    $pdf->Cell(30, 6, $position . ' out of ' . ($class_stats['total_students'] ?? 0), 0, 0, 'L');
    $pdf->Cell(40, 6, 'Class Average:', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($class_stats['class_average'] ?? 0, 1) . '%', 0, 1, 'L');

    $pdf->Cell(40, 6, 'Highest Score:', 0, 0, 'L');
    $pdf->Cell(30, 6, number_format($class_stats['highest_score'] ?? 0, 1) . '%', 0, 0, 'L');
    $pdf->Cell(40, 6, 'Lowest Score:', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($class_stats['lowest_score'] ?? 0, 1) . '%', 0, 1, 'L');

    $pdf->Ln(10);

    // Comments section
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'COMMENTS AND REMARKS', 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(25, 6, 'Class Teacher:', 0, 1, 'L');
    $pdf->Cell(0, 10, '___________________________________________________________________', 0, 1, 'L');
    $pdf->Cell(0, 4, 'Signature: ___________________________ Date: _______________', 0, 1, 'L');

    $pdf->Ln(5);

    $pdf->Cell(25, 6, 'Principal:', 0, 1, 'L');
    $pdf->Cell(0, 10, '___________________________________________________________________', 0, 1, 'L');
    $pdf->Cell(0, 4, 'Signature: ___________________________ Date: _______________', 0, 1, 'L');

    $pdf->Ln(10);

    // Important notes
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(0, 6, 'IMPORTANT NOTES:', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->MultiCell(0, 4, "• This transcript is issued by " . $school['school_name'] . " and is valid for official purposes.\n• Any alteration or falsification of this document is punishable by law.\n• For verification purposes, contact the school administration.", 0, 'L');

} else {
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 20, 'No results available for the selected term.', 0, 1, 'C');
}

// Generate filename and output
$safe_name = preg_replace('/[^A-Za-z0-9\-_]/', '_', $student['full_name']);
$safe_term = preg_replace('/[^A-Za-z0-9\-_]/', '_', $term);
$filename = 'Transcript_' . $safe_name . '_' . $safe_term . '_' . date('Ymd') . '.pdf';

// Create directory if it doesn't exist
$export_dir = dirname(__DIR__) . '/exports/transcripts/';
if (!file_exists($export_dir)) {
    mkdir($export_dir, 0777, true);
}

$filepath = $export_dir . $filename;

// Save PDF file
$pdf->Output($filepath, 'F');

// Log the export (optional - skip if table doesn't exist)
try {
    $stmt = $pdo->prepare("
        INSERT INTO export_logs (user_id, export_type, file_path, exported_at, ip_address)
        VALUES (?, 'transcript', ?, NOW(), ?)
    ");
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $filepath,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
} catch (PDOException $e) {
    // Silently skip logging if export_logs table doesn't exist
    // This is not critical functionality
}

// Output PDF to browser
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: bytes');
header('Cache-Control: private');
readfile($filepath);
exit;

// Function to calculate grade and remark
function calculateGrade($score) {
    if ($score >= 90) return ['grade' => 'A', 'remark' => 'Excellent'];
    if ($score >= 80) return ['grade' => 'B', 'remark' => 'Very Good'];
    if ($score >= 70) return ['grade' => 'C', 'remark' => 'Good'];
    if ($score >= 60) return ['grade' => 'D', 'remark' => 'Fair'];
    if ($score >= 50) return ['grade' => 'E', 'remark' => 'Pass'];
    return ['grade' => 'F', 'remark' => 'Fail'];
}
?>
