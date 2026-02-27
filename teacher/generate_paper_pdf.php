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

// Function to generate final exam paper PDF
function generateFinalExamPaperPDF($paper_id, $user_id, $current_school_id) {
    global $pdo;

    // Fetch paper details with school info - school-filtered
    $stmt = $pdo->prepare("
        SELECT ep.*, s.subject_name, c.class_name, u.full_name as teacher_name,
               si.school_name, si.motto, si.address as school_address,
               si.phone as school_phone, si.email as school_email
        FROM exam_papers ep
        LEFT JOIN subjects s ON ep.subject_id = s.id
        LEFT JOIN classes c ON ep.class_id = c.id
        LEFT JOIN users u ON ep.created_by = u.id
        LEFT JOIN school_info si ON 1=1
        WHERE ep.id = ? AND ep.school_id = ?
    ");
    $stmt->execute([$paper_id, $current_school_id]);
    $paper = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paper) {
        return '';
    }

    // Fetch paper questions with options
    $stmt = $pdo->prepare("
        SELECT pq.*, qb.question_text, qb.question_type, qb.marks,
               GROUP_CONCAT(DISTINCT qo.option_text ORDER BY qo.option_letter SEPARATOR '||') as options,
               GROUP_CONCAT(DISTINCT qo.option_letter ORDER BY qo.option_letter SEPARATOR ',') as option_letters
        FROM paper_questions pq
        LEFT JOIN questions_bank qb ON pq.question_id = qb.id
        LEFT JOIN question_options qo ON qb.id = qo.question_id
        WHERE pq.paper_id = ?
        GROUP BY pq.id
        ORDER BY pq.question_order
    ");
    $stmt->execute([$paper_id]);
    $paper_questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator($paper['school_name'] ?? 'School');
    $pdf->SetAuthor($paper['school_name'] ?? 'School');
    $pdf->SetTitle($paper['paper_title']);
    $pdf->SetSubject($paper['subject_name'] . ' - ' . $paper['class_name']);
    $pdf->SetKeywords('Exam, Question Paper, ' . $paper['subject_name'] . ', ' . $paper['class_name']);

    // Set default header data
    $pdf->SetHeaderData('', 0, '', '');

    // Set header and footer fonts
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

    // Set margins
    $pdf->SetMargins(15, 40, 15);
    $pdf->SetHeaderMargin(10);
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
    // PAPER HEADER SECTION
    // ============================

    // School name
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, strtoupper($paper['school_name'] ?: 'SCHOOL NAME'), 0, 1, 'C', 0, '', 0, false, 'M', 'M');

    // School motto
    if (!empty($paper['school_motto'])) {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 5, $paper['school_motto'], 0, 1, 'C', 0, '', 0, false, 'M', 'M');
    }

    // School address
    if (!empty($paper['school_address'])) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, $paper['school_address'], 0, 1, 'C', 0, '', 0, false, 'M', 'M');
    }

    // Line separator
    $pdf->SetLineWidth(0.5);
    $pdf->Line(15, 35, 195, 35);
    $pdf->SetY(40);

    // Paper title
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, strtoupper($paper['paper_title']), 0, 1, 'C', 0, '', 0, false, 'M', 'M');
    $pdf->Ln(5);

    // Paper information table
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetFillColor(240, 240, 240);

    // Create table header
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(47.5, 8, 'SUBJECT', 1, 0, 'C', 1);
    $pdf->Cell(47.5, 8, 'CLASS', 1, 0, 'C', 1);
    $pdf->Cell(47.5, 8, 'TIME ALLOWED', 1, 0, 'C', 1);
    $pdf->Cell(47.5, 8, 'MAXIMUM MARKS', 1, 1, 'C', 1);

    // Table data
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(47.5, 8, strtoupper($paper['subject_name']), 1, 0, 'C');
    $pdf->Cell(47.5, 8, strtoupper($paper['class_name']), 1, 0, 'C');
    $pdf->Cell(47.5, 8, $paper['time_allotted'] . ' minutes', 1, 0, 'C');
    $pdf->Cell(47.5, 8, $paper['total_marks'], 1, 1, 'C');

    // Second row of table
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(47.5, 8, 'PAPER CODE', 1, 0, 'C', 1);
    $pdf->Cell(47.5, 8, 'DATE', 1, 0, 'C', 1);
    $pdf->Cell(47.5, 8, 'TERM', 1, 0, 'C', 1);
    $pdf->Cell(47.5, 8, 'SESSION', 1, 1, 'C', 1);

    // Second row data
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(47.5, 8, $paper['paper_code'], 1, 0, 'C');
    $pdf->Cell(47.5, 8, date('d/m/Y'), 1, 0, 'C');
    $pdf->Cell(47.5, 8, strtoupper(str_replace('_', ' ', $paper['exam_type'])), 1, 0, 'C');
    $pdf->Cell(47.5, 8, date('Y'), 1, 1, 'C');

    $pdf->Ln(10);

    // ============================
    // INSTRUCTIONS SECTION
    // ============================

    // General instructions
    if (!empty($paper['general_instructions'])) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 6, 'GENERAL INSTRUCTIONS:', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 10);
        $instructions = explode("\n", $paper['general_instructions']);
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
    if (!empty($paper['instructions'])) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 6, 'SPECIFIC INSTRUCTIONS:', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 5, $paper['instructions'], 0, 'L');
        $pdf->Ln(10);
    }

    // ============================
    // QUESTIONS SECTION
    // ============================

    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'SECTION A - ALL QUESTIONS ARE COMPULSORY', 0, 1, 'L');
    $pdf->Ln(2);

    $question_counter = 1;
    $total_marks_calculated = 0;

    foreach ($paper_questions as $question) {
        $total_marks_calculated += $question['marks'];

        // Question number and text
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(10, 6, $question_counter . '.', 0, 0, 'L');

        $pdf->SetFont('helvetica', '', 11);
        $question_width = 150;
        $pdf->MultiCell($question_width, 6, $question['question_text'], 0, 'L');

        // Marks on the right
        $pdf->SetXY(170, $pdf->GetY() - 6);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(20, 6, '[' . $question['marks'] . ' marks]', 0, 1, 'R');

        // Handle different question types
        switch ($question['question_type']) {
            case 'mcq':
                if (!empty($question['options'])) {
                    $options = explode('||', $question['options']);
                    $option_letters = explode(',', $question['option_letters']);

                    $pdf->SetFont('helvetica', '', 10);
                    $pdf->SetX(25);

                    $option_count = count($options);
                    $options_per_row = ($option_count <= 4) ? $option_count : 2;
                    $option_width = 80;

                    for ($i = 0; $i < $option_count; $i++) {
                        $row = floor($i / $options_per_row);
                        $col = $i % $options_per_row;

                        $x = 25 + ($col * $option_width);
                        $y = $pdf->GetY() + ($row * 8);

                        $pdf->SetXY($x, $y);
                        $pdf->Cell(5, 6, '(' . $option_letters[$i] . ')', 0, 0, 'L');
                        $pdf->MultiCell($option_width - 5, 6, ' ' . $options[$i], 0, 'L');

                        if ($col == $options_per_row - 1) {
                            $pdf->SetY($y + 8);
                        }
                    }

                    $rows_needed = ceil($option_count / $options_per_row);
                    $pdf->SetY($pdf->GetY() + ($rows_needed * 8) + 5);
                }
                break;

            case 'true_false':
                $pdf->SetFont('helvetica', '', 10);
                $pdf->SetX(25);
                $pdf->Cell(20, 6, '(   ) True', 0, 0, 'L');
                $pdf->Cell(20, 6, '(   ) False', 0, 1, 'L');
                $pdf->Ln(3);
                break;

            case 'fill_blank':
                $pdf->Ln(5);
                $pdf->SetFont('helvetica', 'I', 9);
                $pdf->SetX(25);
                $pdf->Cell(0, 6, 'Write your answer in the space provided:', 0, 1, 'L');
                // Add answer space
                $pdf->SetLineWidth(0.1);
                $pdf->SetDrawColor(150, 150, 150);
                $start_y = $pdf->GetY();
                $pdf->Line(25, $start_y, 185, $start_y);
                $pdf->Line(25, $start_y + 20, 185, $start_y + 20);
                $pdf->Line(25, $start_y, 25, $start_y + 20);
                $pdf->Line(185, $start_y, 185, $start_y + 20);
                $pdf->SetY($start_y + 25);
                break;

            case 'short_answer':
                $pdf->Ln(5);
                $pdf->SetFont('helvetica', 'I', 9);
                $pdf->SetX(25);
                $pdf->Cell(0, 6, 'Answer the following:', 0, 1, 'L');
                // Add answer space
                $pdf->SetLineWidth(0.1);
                $pdf->SetDrawColor(150, 150, 150);
                $start_y = $pdf->GetY();
                $pdf->Line(25, $start_y, 185, $start_y);
                $pdf->Line(25, $start_y + 60, 185, $start_y + 60);
                $pdf->Line(25, $start_y, 25, $start_y + 60);
                $pdf->Line(185, $start_y, 185, $start_y + 60);
                $pdf->SetY($start_y + 65);
                break;

            case 'essay':
                $pdf->Ln(5);
                $pdf->SetFont('helvetica', 'I', 9);
                $pdf->SetX(25);
                $pdf->Cell(0, 6, 'Write your essay answer below:', 0, 1, 'L');
                // Add answer space
                $pdf->SetLineWidth(0.1);
                $pdf->SetDrawColor(150, 150, 150);
                $start_y = $pdf->GetY();
                $pdf->Line(25, $start_y, 185, $start_y);
                $pdf->Line(25, $start_y + 150, 185, $start_y + 150);
                $pdf->Line(25, $start_y, 25, $start_y + 150);
                $pdf->Line(185, $start_y, 185, $start_y + 150);
                $pdf->SetY($start_y + 155);
                break;

            default:
                $pdf->Ln(5);
                // Add default answer space
                $pdf->SetLineWidth(0.1);
                $pdf->SetDrawColor(150, 150, 150);
                $start_y = $pdf->GetY();
                $pdf->Line(25, $start_y, 185, $start_y);
                $pdf->Line(25, $start_y + 50, 185, $start_y + 50);
                $pdf->Line(25, $start_y, 25, $start_y + 50);
                $pdf->Line(185, $start_y, 185, $start_y + 50);
                $pdf->SetY($start_y + 55);
                break;
        }

        $pdf->Ln(5);
        $question_counter++;
    }

    // ============================
    // ADDITIONAL PAGES FOR ANSWERS
    // ============================

    // Add a new page for answer sheets if needed
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'ADDITIONAL ANSWER SHEETS', 0, 1, 'C');
    $pdf->Ln(10);

    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->MultiCell(0, 5, 'Use this space for any additional answers or rough work. Clearly label the question number for each answer.', 0, 'L');
    $pdf->Ln(5);

    // Add multiple answer spaces
    for ($i = 1; $i <= 5; $i++) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Additional Answer Space ' . $i . ':', 0, 1, 'L');

        // Add answer space
        $pdf->SetLineWidth(0.1);
        $pdf->SetDrawColor(150, 150, 150);
        $start_y = $pdf->GetY();
        $pdf->Line(25, $start_y, 185, $start_y);
        $pdf->Line(25, $start_y + 80, 185, $start_y + 80);
        $pdf->Line(25, $start_y, 25, $start_y + 80);
        $pdf->Line(185, $start_y, 185, $start_y + 80);
        $pdf->SetY($start_y + 85);
    }

    // ============================
    // FINAL PAGE - SUMMARY
    // ============================

    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'EXAMINATION SUMMARY', 0, 1, 'C');
    $pdf->Ln(10);

    // Summary table
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(240, 240, 240);

    $pdf->Cell(95, 8, 'ITEM', 1, 0, 'C', 1);
    $pdf->Cell(95, 8, 'DETAILS', 1, 1, 'C', 1);

    $pdf->SetFont('helvetica', '', 10);

    $summary_data = [
        ['Paper Title', $paper['paper_title']],
        ['Subject', $paper['subject_name']],
        ['Class', $paper['class_name']],
        ['Paper Code', $paper['paper_code']],
        ['Total Questions', count($paper_questions)],
        ['Total Marks', $total_marks_calculated],
        ['Time Allowed', $paper['time_allotted'] . ' minutes'],
        ['Date', date('d/m/Y')],
        ['Prepared By', $paper['teacher_name']],
        ['School', $paper['school_name']]
    ];

    foreach ($summary_data as $row) {
        $pdf->Cell(95, 8, $row[0], 1, 0, 'L');
        $pdf->Cell(95, 8, $row[1], 1, 1, 'L');
    }

    $pdf->Ln(15);

    // Important notes
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'IMPORTANT NOTES:', 0, 1, 'L');
    $pdf->Ln(2);

    $pdf->SetFont('helvetica', '', 10);
    $notes = [
        '1. Check that you have answered all questions',
        '2. Write your answers clearly and legibly',
        '3. Use only blue or black ink',
        '4. Do not write in the margins',
        '5. Rough work should be done only in the provided space',
        '6. Return all pages of the answer script'
    ];

    foreach ($notes as $note) {
        $pdf->Cell(5, 6, '', 0, 0, 'L');
        $pdf->MultiCell(0, 6, $note, 0, 'L');
    }

    // Return PDF as string
    return $pdf->Output('', 'S');
}

// Get paper ID from GET parameter
$paper_id = intval($_GET['paper_id'] ?? 0);

if ($paper_id <= 0) {
    die('Invalid paper ID');
}

// Generate PDF
$pdf_content = generateFinalExamPaperPDF($paper_id, $_SESSION['user_id'], $current_school_id);

// Create directory if it doesn't exist
$pdf_dir = '../generated_papers/';
if (!file_exists($pdf_dir)) {
    mkdir($pdf_dir, 0777, true);
}

// Generate filename
$filename = 'Exam_Paper_' . $paper_id . '_' . date('Ymd_His') . '.pdf';
$filepath = $pdf_dir . $filename;

// Save PDF file
file_put_contents($filepath, $pdf_content);

// Update database with PDF file path
$stmt = $pdo->prepare("
    UPDATE exam_papers
    SET pdf_file_path = ?, pdf_generated_at = NOW(), paper_version = paper_version + 1
    WHERE id = ?
");
$stmt->execute([$filepath, $paper_id]);

// Also save to generated_papers table
$stmt = $pdo->prepare("
    INSERT INTO generated_papers (paper_id, file_path, file_format, generated_by)
    VALUES (?, ?, 'pdf', ?)
");
$stmt->execute([$paper_id, $filepath, $_SESSION['user_id']]);

// Output PDF to browser
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: bytes');
readfile($filepath);
exit;
?>
