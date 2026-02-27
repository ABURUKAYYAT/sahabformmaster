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

// Function to generate answer key PDF
function generateAnswerKeyPDF($selected_questions, $school_name, $school_motto, $school_address,
                            $paper_title, $exam_type, $subject_name, $class_name, $term,
                            $time_allotted, $total_marks, $current_school_id) {
    global $pdo;

    if (empty($selected_questions) || !is_array($selected_questions)) {
        return '';
    }

    // Prepare placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($selected_questions), '?'));

    // Fetch questions with correct answers
    $stmt = $pdo->prepare("
        SELECT q.id, q.question_text, q.question_type, q.marks,
               GROUP_CONCAT(CASE WHEN qo.is_correct = 1 THEN qo.option_letter END SEPARATOR ', ') as correct_answers,
               GROUP_CONCAT(CASE WHEN qo.is_correct = 1 THEN qo.option_text END SEPARATOR ' | ') as correct_answer_texts
        FROM questions_bank q
        LEFT JOIN question_options qo ON q.id = qo.question_id AND qo.school_id = ?
        WHERE q.id IN ($placeholders) AND q.school_id = ?
        GROUP BY q.id
        ORDER BY FIELD(q.id, " . implode(',', $selected_questions) . ")
    ");
    $stmt->execute(array_merge([$current_school_id], $selected_questions, [$current_school_id]));
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator($school_name ?: 'School');
    $pdf->SetTitle('Answer Key - ' . $paper_title);
    $pdf->SetSubject('Exam Answer Key');

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

    // ============================
    // ANSWER KEY HEADER SECTION
    // ============================

    // School name
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, strtoupper($school_name ?: 'SCHOOL NAME'), 0, 1, 'C', 0, '', 0, false, 'M', 'M');

    // Answer Key title
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'ANSWER KEY', 0, 1, 'C', 0, '', 0, false, 'M', 'M');
    $pdf->Ln(5);

    // Paper title
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, strtoupper($paper_title ?: 'EXAM PAPER'), 0, 1, 'C', 0, '', 0, false, 'M', 'M');
    $pdf->Ln(5);

    // Paper information table
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetFillColor(240, 240, 240);

    // Create table header
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 8, 'SUBJECT', 1, 0, 'C', 1);
    $pdf->Cell(40, 8, 'CLASS', 1, 0, 'C', 1);
    $pdf->Cell(40, 8, 'TERM', 1, 0, 'C', 1);
    $pdf->Cell(40, 8, 'TYPE', 1, 0, 'C', 1);
    $pdf->Cell(30, 8, 'TOTAL MARKS', 1, 1, 'C', 1);

    // Table data
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 8, strtoupper($subject_name ?: 'N/A'), 1, 0, 'C');
    $pdf->Cell(40, 8, strtoupper($class_name ?: 'N/A'), 1, 0, 'C');
    $pdf->Cell(40, 8, strtoupper($term ?: 'N/A'), 1, 0, 'C');
    $pdf->Cell(40, 8, strtoupper(str_replace('_', ' ', $exam_type)), 1, 0, 'C');
    $pdf->Cell(30, 8, $total_marks, 1, 1, 'C');

    $pdf->Ln(10);

    // ============================
    // CONFIDENTIAL NOTICE
    // ============================

    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(255, 0, 0);
    $pdf->Cell(0, 8, 'CONFIDENTIAL - FOR TEACHERS ONLY', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(5);

    // ============================
    // ANSWER KEY TABLE
    // ============================

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(220, 220, 220);

    // Table header
    $pdf->Cell(15, 8, 'Q.No', 1, 0, 'C', 1);
    $pdf->Cell(15, 8, 'Marks', 1, 0, 'C', 1);
    $pdf->Cell(110, 8, 'Question', 1, 0, 'C', 1);
    $pdf->Cell(50, 8, 'Correct Answer', 1, 1, 'C', 1);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(255, 255, 255);

    $question_counter = 1;
    $total_marks_calculated = 0;

    foreach ($questions as $question) {
        $correct_answer = $question['correct_answers'] ?: 'N/A';
        $correct_answer_text = $question['correct_answer_texts'] ?: 'N/A';
        $total_marks_calculated += $question['marks'];

        // Question number
        $pdf->Cell(15, 6, $question_counter, 1, 0, 'C', 0);

        // Marks
        $pdf->Cell(15, 6, $question['marks'], 1, 0, 'C', 0);

        // Question text (truncated if too long)
        $question_text = $question['question_text'];
        if (strlen($question_text) > 80) {
            $question_text = substr($question_text, 0, 77) . '...';
        }
        $pdf->Cell(110, 6, $question_text, 1, 0, 'L', 0);

        // Correct answer
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(0, 128, 0);
        $pdf->Cell(50, 6, $correct_answer, 1, 1, 'C', 0);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 9);

        $question_counter++;
    }

    $pdf->Ln(10);

    // ============================
    // SUMMARY SECTION
    // ============================

    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'SUMMARY', 0, 1, 'L');
    $pdf->Ln(2);

    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetFillColor(240, 240, 240);

    // Summary table
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(80, 8, 'Item', 1, 0, 'C', 1);
    $pdf->Cell(110, 8, 'Details', 1, 1, 'C', 1);

    $pdf->SetFont('helvetica', '', 10);

    $summary_data = [
        ['Total Questions', count($questions)],
        ['Total Marks', $total_marks_calculated],
        ['Paper Title', $paper_title],
        ['Subject', $subject_name ?: 'N/A'],
        ['Class', $class_name ?: 'N/A'],
        ['Exam Type', str_replace('_', ' ', $exam_type)],
        ['Date Generated', date('d/m/Y H:i:s')]
    ];

    foreach ($summary_data as $row) {
        $pdf->Cell(80, 7, $row[0], 1, 0, 'L');
        $pdf->Cell(110, 7, $row[1], 1, 1, 'L');
    }

    $pdf->Ln(15);

    // ============================
    // MARKING SCHEME
    // ============================

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'MARKING SCHEME:', 0, 1, 'L');
    $pdf->Ln(2);

    $pdf->SetFont('helvetica', '', 10);
    $marking_notes = [
        '1. Multiple Choice Questions (MCQ): Award full marks for correct answer.',
        '2. True/False Questions: Award full marks for correct answer.',
        '3. Fill in the Blank: Award full marks if answer matches exactly.',
        '4. Short Answer Questions: Award marks based on completeness and accuracy.',
        '5. Essay Questions: Award marks based on content, structure, and quality.',
        '6. Spelling and grammar may affect marks in subjective questions.',
        '7. Clear and legible handwriting is essential.'
    ];

    foreach ($marking_notes as $note) {
        $pdf->Cell(5, 5, '•', 0, 0, 'L');
        $pdf->MultiCell(0, 5, ' ' . $note, 0, 'L');
    }

    $pdf->Ln(10);

    // ============================
    // FOOTER
    // ============================

    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->SetTextColor(128, 128, 128);
    $pdf->Cell(0, 5, 'This answer key is confidential and should not be distributed to students.', 0, 1, 'C');
    $pdf->Cell(0, 5, 'Generated by Sahab School Management System on ' . date('d/m/Y H:i:s'), 0, 1, 'C');

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
    die('Please select at least one question to generate answer key.');
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
$pdf_content = generateAnswerKeyPDF(
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
    $current_school_id
);

// Output PDF to browser
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . ($paper_title ?: 'exam_answer_key') . '_answer_key_' . date('Ymd_His') . '.pdf"');
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: bytes');
echo $pdf_content;
exit;
?>
