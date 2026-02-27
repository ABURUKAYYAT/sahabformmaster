<?php
session_start();
require_once '../config/db.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
require_once '../includes/functions.php';
$current_school_id = require_school_auth();

// Include TCPDF
require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

// Function to generate paper preview PDF
function generatePaperPreviewPDF($selected_questions, $school_name, $school_motto, $school_address,
                               $paper_title, $exam_type, $subject_name, $class_name, $term,
                               $time_allotted, $total_marks, $general_instructions, $specific_instructions, $current_school_id) {
    global $pdo;

    if (empty($selected_questions) || !is_array($selected_questions)) {
        return '';
    }

    // Prepare placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($selected_questions), '?'));

    // Fetch questions with their options
    $stmt = $pdo->prepare("
        SELECT q.*, qo.option_letter, qo.option_text, qo.is_correct
        FROM questions_bank q
        LEFT JOIN question_options qo ON q.id = qo.question_id AND qo.school_id = ?
        WHERE q.id IN ($placeholders) AND q.school_id = ?
        ORDER BY FIELD(q.id, " . implode(',', $selected_questions) . ")
    ");
    $stmt->execute(array_merge([$current_school_id], $selected_questions, [$current_school_id]));
    $questions_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organize questions with their options
    $questions = [];
    foreach ($questions_data as $row) {
        $question_id = $row['id'];
        if (!isset($questions[$question_id])) {
            $questions[$question_id] = [
                'question_text' => $row['question_text'],
                'question_type' => $row['question_type'],
                'marks' => $row['marks'],
                'options' => []
            ];
        }
        if ($row['option_letter']) {
            $questions[$question_id]['options'][] = [
                'letter' => $row['option_letter'],
                'text' => $row['option_text']
            ];
        }
    }

    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator($school_name ?: 'School');
    $pdf->SetTitle($paper_title . ' - Preview');
    $pdf->SetSubject('Exam Paper Preview');

    // Set default header data
    $pdf->SetHeaderData('', 0, '', '');

    // Set header and footer fonts
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

    // Set margins
    $pdf->SetMargins(15, 20, 15);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 25);

    // Set image scale factor
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

    // Set font
    $pdf->SetFont('helvetica', '', 11);

    // Add a page
    $pdf->AddPage();

    // School header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, strtoupper($school_name ?: 'SCHOOL NAME'), 0, 1, 'C', 0, '', 0, false, 'M', 'M');

    if (!empty($school_motto)) {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 5, $school_motto, 0, 1, 'C', 0, '', 0, false, 'M', 'M');
    }

    if (!empty($school_address)) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, $school_address, 0, 1, 'C', 0, '', 0, false, 'M', 'M');
    }

    $pdf->Ln(5);

    // Line separator
    $pdf->SetLineWidth(0.5);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(5);

    // Paper title
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, strtoupper($paper_title ?: 'EXAM PAPER'), 0, 1, 'C', 0, '', 0, false, 'M', 'M');
    $pdf->Ln(5);

    // Exam information
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetFillColor(240, 240, 240);

    // Create table header
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(47.5, 8, 'EXAM TYPE', 1, 0, 'C', 1);
    $pdf->Cell(47.5, 8, 'SUBJECT', 1, 0, 'C', 1);
    $pdf->Cell(47.5, 8, 'CLASS', 1, 0, 'C', 1);
    $pdf->Cell(47.5, 8, 'TIME', 1, 1, 'C', 1);

    // Table data
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(47.5, 8, strtoupper(str_replace('_', ' ', $exam_type)), 1, 0, 'C');
    $pdf->Cell(47.5, 8, strtoupper($subject_name ?: 'N/A'), 1, 0, 'C');
    $pdf->Cell(47.5, 8, strtoupper($class_name ?: 'N/A'), 1, 0, 'C');
    $pdf->Cell(47.5, 8, $time_allotted . ' minutes', 1, 1, 'C');

    $pdf->Ln(5);

    // Instructions
    if (!empty($general_instructions)) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 6, 'GENERAL INSTRUCTIONS:', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 10);
        $instructions = explode("\n", $general_instructions);
        foreach ($instructions as $instruction) {
            $instruction = trim($instruction);
            if (!empty($instruction)) {
                $pdf->Cell(5, 5, '•', 0, 0, 'L');
                $pdf->MultiCell(0, 5, ' ' . $instruction, 0, 'L');
            }
        }
        $pdf->Ln(5);
    }

    // Specific instructions
    if (!empty($specific_instructions)) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 6, 'SPECIFIC INSTRUCTIONS:', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 5, $specific_instructions, 0, 'L');
        $pdf->Ln(5);
    }

    // Questions
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'QUESTIONS:', 0, 1, 'L');
    $pdf->Ln(2);

    $question_counter = 1;
    foreach ($questions as $question_id => $question) {
        // Question number and text
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(10, 6, $question_counter . '.', 0, 0, 'L');

        $pdf->SetFont('helvetica', '', 11);
        $question_width = 170;
        $pdf->MultiCell($question_width, 6, $question['question_text'], 0, 'L');

        // Options if available
        if (!empty($question['options'])) {
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetX(25);

            foreach ($question['options'] as $option) {
                $pdf->Cell(5, 6, '(' . $option['letter'] . ')', 0, 0, 'L');
                $pdf->MultiCell(165, 6, ' ' . $option['text'], 0, 'L');
                $pdf->SetX(25);
            }
        }

        $pdf->Ln(5);
        $question_counter++;
    }

    // Summary
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'SUMMARY:', 0, 1, 'L');
    $pdf->Ln(2);

    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Total Questions: ' . count($questions), 0, 1, 'L');
    $pdf->Cell(0, 6, 'Total Marks: ' . $total_marks, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Time Allotted: ' . $time_allotted . ' minutes', 0, 1, 'L');

    // Return PDF as string
    return $pdf->Output('', 'S');
}

// Get POST data
$selected_questions = $_POST['selected_questions'] ?? '';
$paper_title = trim($_POST['paper_title'] ?? '');
$general_instructions = trim($_POST['general_instructions'] ?? '');
$specific_instructions = trim($_POST['instructions'] ?? '');
$school_name = trim($_POST['school_name'] ?? '');
$school_motto = trim($_POST['school_motto'] ?? '');
$school_address = trim($_POST['school_address'] ?? '');
$exam_type = $_POST['exam_type'] ?? '';
$subject_id = intval($_POST['subject_id'] ?? 0);
$class_id = intval($_POST['class_id'] ?? 0);
$term = trim($_POST['term'] ?? '');
$time_allotted = intval($_POST['time_allotted'] ?? 0);
$total_marks = floatval($_POST['total_marks'] ?? 0);

// Decode selected questions if it's a JSON string
if (is_string($selected_questions) && !empty($selected_questions)) {
    $selected_questions = json_decode($selected_questions, true);
    if ($selected_questions === null) {
        die('Invalid JSON format for selected questions.');
    }
}

if (empty($selected_questions) || !is_array($selected_questions)) {
    die('Please select at least one question to preview.');
}

// Get subject and class names
$subject_name = '';
$class_name = '';

if ($subject_id > 0) {
    $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ? AND school_id = ?");
    $stmt->execute([$subject_id, $current_school_id]);
    $subject = $stmt->fetch(PDO::FETCH_ASSOC);
    $subject_name = $subject['subject_name'] ?? '';
}

if ($class_id > 0) {
    $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$class_id, $current_school_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    $class_name = $class['class_name'] ?? '';
}

// Generate PDF
$pdf_content = generatePaperPreviewPDF(
    $selected_questions,
    $school_name,
    $school_motto,
    $school_address,
    $paper_title,
    $exam_type,
    $subject_name,
    $class_name,
    $term,
    $time_allotted,
    $total_marks,
    $general_instructions,
    $specific_instructions,
    $current_school_id
);

// Output PDF to browser
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . ($paper_title ?: 'exam_paper_preview') . '_preview.pdf"');
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: bytes');
echo $pdf_content;
exit;
?>
