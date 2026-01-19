<?php
session_start();
require_once '../config/db.php';

// Include TCPDF
require_once '../tcpdf/tcpdf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
require_once '../includes/functions.php';
$current_school_id = require_school_auth();

$paper_id = intval($_GET['paper_id'] ?? $_POST['paper_id'] ?? 0);

if ($paper_id <= 0) {
    die('Invalid paper ID');
}

// Fetch paper details - school-filtered
$stmt = $pdo->prepare("
    SELECT ep.*, s.subject_name, c.class_name,
           si.school_name, si.motto, si.address as school_address,
           si.phone as school_phone, si.email as school_email,
           u.full_name as teacher_name
    FROM exam_papers ep
    LEFT JOIN subjects s ON ep.subject_id = s.id
    LEFT JOIN classes c ON ep.class_id = c.id
    LEFT JOIN school_info si ON 1=1
    LEFT JOIN users u ON ep.created_by = u.id
    WHERE ep.id = ? AND ep.school_id = ?
    LIMIT 1
");
$stmt->execute([$paper_id, $current_school_id]);
$paper = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$paper) {
    die('Paper not found');
}

// Fetch sections
$sections_stmt = $pdo->prepare("
    SELECT * FROM paper_sections 
    WHERE paper_id = ? 
    ORDER BY section_order
");
$sections_stmt->execute([$paper_id]);
$sections = $sections_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch questions with sections
$questions_stmt = $pdo->prepare("
    SELECT pq.*, qb.*, ps.section_name,
           GROUP_CONCAT(DISTINCT qo.option_text ORDER BY qo.option_letter SEPARATOR '||') as options,
           GROUP_CONCAT(DISTINCT qo.option_letter ORDER BY qo.option_letter SEPARATOR ',') as option_letters,
           GROUP_CONCAT(DISTINCT qo.is_correct ORDER BY qo.option_letter SEPARATOR ',') as correct_options
    FROM paper_questions pq
    LEFT JOIN questions_bank qb ON pq.question_id = qb.id
    LEFT JOIN paper_sections ps ON pq.section_id = ps.id
    LEFT JOIN question_options qo ON qb.id = qo.question_id
    WHERE pq.paper_id = ?
    GROUP BY pq.id
    ORDER BY ps.section_order, pq.question_order
");
$questions_stmt->execute([$paper_id]);
$questions = $questions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize questions by section
$organized_questions = [];
foreach ($questions as $question) {
    $section_id = $question['section_id'] ?: 0;
    if (!isset($organized_questions[$section_id])) {
        $organized_questions[$section_id] = [
            'section_name' => $question['section_name'] ?: 'Main Section',
            'questions' => []
        ];
    }
    $organized_questions[$section_id]['questions'][] = $question;
}

// Create new PDF document
class ExamPaperPDF extends TCPDF {
    
    // School header
    public function Header() {
        // Get paper data from constructor
        $paper = $GLOBALS['paper'];
        
        // Set font
        $this->SetFont('helvetica', 'B', 16);
        
        // School name
        $this->Cell(0, 10, strtoupper($paper['school_name']), 0, 1, 'C', 0, '', 0, false, 'M', 'M');
        
        // School motto
        $this->SetFont('helvetica', 'I', 10);
        $this->Cell(0, 5, $paper['motto'], 0, 1, 'C', 0, '', 0, false, 'M', 'M');
        
        // Line separator
        $this->SetLineWidth(0.5);
        $this->Line(15, 30, 195, 30);
        
        // Reset Y position
        $this->SetY(35);
    }
    
    // Page footer
    public function Footer() {
        // Position at 15 mm from bottom
        $this->SetY(-15);
        
        // Set font
        $this->SetFont('helvetica', 'I', 8);
        
        // Page number
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 
                    0, 0, 'C', 0, '', 0, false, 'T', 'M');
        
        // Confidential notice on first page
        if ($this->page == 1) {
            $this->SetY(-25);
            $this->SetFont('helvetica', 'B', 9);
            $this->Cell(0, 10, 'CONFIDENTIAL - FOR EXAMINATION PURPOSES ONLY', 
                        0, 0, 'C', 0, '', 0, false, 'T', 'M');
        }
    }
    
    // Add instruction box
    public function addInstructionBox($instructions) {
        // Save current position
        $start_y = $this->GetY();
        
        // Draw box
        $this->SetFillColor(240, 240, 240);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.3);
        $this->Rect(15, $start_y, 180, 40, 'DF', array('all' => array('width' => 0.5)));
        
        // Add title
        $this->SetFont('helvetica', 'B', 11);
        $this->SetXY(20, $start_y + 5);
        $this->Cell(170, 6, 'GENERAL INSTRUCTIONS:', 0, 1, 'L');
        
        // Add instructions
        $this->SetFont('helvetica', '', 10);
        $this->SetXY(25, $start_y + 15);
        
        // Split instructions into lines
        $instructions = explode("\n", $instructions);
        foreach ($instructions as $instruction) {
            $instruction = trim($instruction);
            if (!empty($instruction)) {
                $this->Cell(5, 5, '•', 0, 0, 'L');
                $this->MultiCell(160, 5, ' ' . $instruction, 0, 'L');
                $this->SetX(25);
            }
        }
        
        // Move Y position down
        $this->SetY($start_y + 45);
    }
    
    // Add question with proper formatting
    public function addQuestion($number, $question, $marks, $type = 'mcq') {
        $start_y = $this->GetY();
        
        // Question number and marks
        $this->SetFont('helvetica', 'B', 11);
        $this->Cell(10, 6, $number . '.', 0, 0, 'L');
        
        $this->SetFont('helvetica', '', 11);
        $question_width = 150;
        $this->MultiCell($question_width, 6, $question, 0, 'L');
        
        // Marks on the right
        $this->SetXY(170, $start_y);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(20, 6, '[' . $marks . ' marks]', 0, 1, 'R');
        
        // Move to next line
        $this->SetY($this->GetY() + 2);
    }
    
    // Add MCQ options
    public function addMCQOptions($options, $option_letters) {
        $letters = explode(',', $option_letters);
        $option_texts = explode('||', $options);
        
        $this->SetFont('helvetica', '', 10);
        $this->SetX(25); // Indent options
        
        $option_count = count($letters);
        $options_per_row = ($option_count <= 4) ? $option_count : 2;
        $option_width = 85;
        
        for ($i = 0; $i < $option_count; $i++) {
            $row = floor($i / $options_per_row);
            $col = $i % $options_per_row;
            
            $x = 25 + ($col * $option_width);
            $y = $this->GetY() + ($row * 8);
            
            $this->SetXY($x, $y);
            $this->Cell(5, 6, '(' . $letters[$i] . ')', 0, 0, 'L');
            $this->MultiCell($option_width - 5, 6, ' ' . $option_texts[$i], 0, 'L');
            
            // If we just finished a row, update Y position
            if ($col == $options_per_row - 1) {
                $this->SetY($y + 8);
            }
        }
        
        // Move Y to after all options
        $rows_needed = ceil($option_count / $options_per_row);
        $this->SetY($this->GetY() + ($rows_needed * 8) + 5);
    }
    
    // Add answer space
    public function addAnswerSpace($height = 50) {
        $this->SetLineWidth(0.1);
        $this->SetDrawColor(150, 150, 150);
        
        $start_y = $this->GetY();
        $this->Line(25, $start_y, 185, $start_y);
        $this->Line(25, $start_y + $height, 185, $start_y + $height);
        $this->Line(25, $start_y, 25, $start_y + $height);
        $this->Line(185, $start_y, 185, $start_y + $height);
        
        // Add dotted lines inside
        $this->SetLineStyle(array('width' => 0.1, 'dash' => '2,2'));
        for ($i = 1; $i <= 3; $i++) {
            $y = $start_y + ($height / 4) * $i;
            $this->Line(25, $y, 185, $y);
        }
        
        $this->SetY($start_y + $height + 10);
    }
}

// Create PDF instance
$pdf = new ExamPaperPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Sahab School Management System');
$pdf->SetAuthor($paper['teacher_name']);
$pdf->SetTitle($paper['paper_title']);
$pdf->SetSubject($paper['subject_name'] . ' - ' . $paper['class_name']);
$pdf->SetKeywords('Exam, Question Paper, ' . $paper['subject_name'] . ', ' . $paper['class_name']);

// Set default header data (will be overridden by custom Header())
$pdf->SetHeaderData('', 0, '', '');

// Set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(15, 40, 15); // Left, Top, Right
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
    $pdf->addInstructionBox($paper['general_instructions']);
    $pdf->Ln(5);
}

// Specific instructions (if any)
if (!empty($paper['instructions'])) {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'SPECIFIC INSTRUCTIONS:', 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 10);
    $instructions = explode("\n", $paper['instructions']);
    foreach ($instructions as $instruction) {
        $instruction = trim($instruction);
        if (!empty($instruction)) {
            $pdf->Cell(5, 5, '•', 0, 0, 'L');
            $pdf->MultiCell(0, 5, ' ' . $instruction, 0, 'L');
        }
    }
    $pdf->Ln(10);
}

// ============================
// QUESTIONS SECTION
// ============================

$question_counter = 1;

foreach ($organized_questions as $section_id => $section_data) {
    // Section heading
    if ($section_id != 0 || !empty($section_data['section_name'])) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(0, 8, 'SECTION ' . chr(64 + $section_id) . ' - ' . strtoupper($section_data['section_name']), 0, 1, 'L', 1);
        $pdf->Ln(2);
    }
    
    // Section instructions (if any)
    $section_info = null;
    if ($section_id != 0) {
        foreach ($sections as $sec) {
            if ($sec['id'] == $section_id) {
                $section_info = $sec;
                break;
            }
        }
        
        if ($section_info && !empty($section_info['section_instruction'])) {
            $pdf->SetFont('helvetica', 'I', 10);
            $pdf->MultiCell(0, 5, $section_info['section_instruction'], 0, 'L');
            $pdf->Ln(2);
        }
    }
    
    // Questions in this section
    foreach ($section_data['questions'] as $question) {
        // Check if we need a new page
        if ($pdf->GetY() > 250 && $question['question_type'] == 'essay') {
            $pdf->AddPage();
        }
        
        // Add the question
        $pdf->addQuestion($question_counter, $question['question_text'], $question['marks']);
        
        // Handle different question types
        switch ($question['question_type']) {
            case 'mcq':
                if (!empty($question['options'])) {
                    $pdf->addMCQOptions($question['options'], $question['option_letters']);
                }
                $pdf->Ln(3);
                break;
                
            case 'true_false':
                $pdf->SetFont('helvetica', '', 10);
                $pdf->SetX(30);
                $pdf->Cell(20, 6, '(   ) True', 0, 0, 'L');
                $pdf->Cell(20, 6, '(   ) False', 0, 1, 'L');
                $pdf->Ln(3);
                break;
                
            case 'fill_blank':
                // For fill in blanks, we'll leave space
                $pdf->Ln(5);
                $pdf->SetFont('helvetica', 'I', 9);
                $pdf->SetX(30);
                $pdf->Cell(0, 6, 'Write your answer in the space provided:', 0, 1, 'L');
                $pdf->addAnswerSpace(20);
                break;
                
            case 'short_answer':
                $pdf->Ln(5);
                $pdf->SetFont('helvetica', 'I', 9);
                $pdf->SetX(30);
                $pdf->Cell(0, 6, 'Answer the following:', 0, 1, 'L');
                $pdf->addAnswerSpace(60);
                break;
                
            case 'essay':
                $pdf->Ln(5);
                $pdf->SetFont('helvetica', 'I', 9);
                $pdf->SetX(30);
                $pdf->Cell(0, 6, 'Write your essay answer below:', 0, 1, 'L');
                $pdf->addAnswerSpace(150);
                break;
                
            default:
                $pdf->Ln(5);
                $pdf->addAnswerSpace(50);
                break;
        }
        
        // Add spacing between questions
        if ($question['question_type'] != 'essay') {
            $pdf->Ln(5);
        }
        
        $question_counter++;
    }
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
    $pdf->addAnswerSpace(80);
    $pdf->Ln(5);
}

// ============================
// FINAL PAGE - SUMMARY
// ============================

$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'EXAMINATION SUMMARY', 0, 1, 'C');
$pdf->Ln(10);

// Calculate total marks
$total_marks = 0;
foreach ($questions as $q) {
    $total_marks += $q['marks'];
}

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
    ['Total Questions', count($questions)],
    ['Total Marks', $total_marks],
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

// ============================
// SAVE AND OUTPUT PDF
// ============================

// Create directory if it doesn't exist
$pdf_dir = '../generated_papers/';
if (!file_exists($pdf_dir)) {
    mkdir($pdf_dir, 0777, true);
}

// Generate filename
$filename = 'Exam_Paper_' . $paper['paper_code'] . '_' . date('Ymd_His') . '.pdf';
$filepath = $pdf_dir . $filename;

// Save PDF file
$pdf->Output($filepath, 'F');

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
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: bytes');
readfile($filepath);
exit;
?>
