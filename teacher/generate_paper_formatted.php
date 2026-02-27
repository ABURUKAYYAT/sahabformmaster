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

// Get POST data
$selected_questions = $_POST['selected_questions'] ?? '';
$paper_title = trim($_POST['paper_title'] ?? 'Exam Paper');
$general_instructions = trim($_POST['general_instructions'] ?? '');
$specific_instructions = trim($_POST['instructions'] ?? '');
$exam_type = $_POST['exam_type'] ?? '';
$subject_id = intval($_POST['subject_id'] ?? 0);
$class_id = intval($_POST['class_id'] ?? 0);
$term = trim($_POST['term'] ?? '');
$time_allotted = intval($_POST['time_allotted'] ?? 0);
$total_marks = floatval($_POST['total_marks'] ?? 0);

// Fetch school information from database
$school_info_query = $pdo->prepare("SELECT * FROM school_info WHERE id = 1");
$school_info_query->execute();
$school_info = $school_info_query->fetch(PDO::FETCH_ASSOC);

$school_name = $school_info['school_name'] ?? 'School Name';
$school_motto = $school_info['motto'] ?? '';
$school_address = $school_info['address'] ?? '';

// Decode selected questions if it's a JSON string
if (is_string($selected_questions) && !empty($selected_questions)) {
    $selected_questions = json_decode($selected_questions, true);
    if ($selected_questions === null) {
        die('Invalid JSON format for selected questions.');
    }
}

if (empty($selected_questions) || !is_array($selected_questions)) {
    die('Please select at least one question to generate paper.');
}

// Get subject and class names - school-filtered
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

// Fetch questions with their options - school-filtered
$placeholders = implode(',', array_fill(0, count($selected_questions), '?'));
$stmt = $pdo->prepare("
    SELECT q.*, qo.option_letter, qo.option_text, qo.is_correct
    FROM questions_bank q
    LEFT JOIN question_options qo ON q.id = qo.question_id
    WHERE q.id IN ($placeholders) AND q.school_id = ?
    ORDER BY FIELD(q.id, " . implode(',', $selected_questions) . ")
");
$params = array_merge($selected_questions, [$current_school_id]);
$stmt->execute($params);
$questions_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize questions by type and with their options
$questions_by_type = [];
foreach ($questions_data as $row) {
    $question_id = $row['id'];
    $question_type = $row['question_type'];

    if (!isset($questions_by_type[$question_type])) {
        $questions_by_type[$question_type] = [];
    }

    if (!isset($questions_by_type[$question_type][$question_id])) {
        $questions_by_type[$question_type][$question_id] = [
            'question_text' => $row['question_text'],
            'question_type' => $row['question_type'],
            'marks' => $row['marks'],
            'difficulty_level' => $row['difficulty_level'],
            'options' => []
        ];
    }

    if ($row['option_letter']) {
        $questions_by_type[$question_type][$question_id]['options'][] = [
            'letter' => $row['option_letter'],
            'text' => $row['option_text'],
            'is_correct' => $row['is_correct']
        ];
    }
}

// Order sections in a standard sequence
$ordered_types = ['mcq', 'true_false', 'short_answer', 'essay', 'fill_blank'];
$ordered_questions_by_type = [];
foreach ($ordered_types as $type) {
    if (!empty($questions_by_type[$type])) {
        $ordered_questions_by_type[$type] = $questions_by_type[$type];
        unset($questions_by_type[$type]);
    }
}
foreach ($questions_by_type as $type => $items) {
    $ordered_questions_by_type[$type] = $items;
}
$questions_by_type = $ordered_questions_by_type;

// Function to get section title for question type
function getSectionTitle($question_type) {
    $titles = [
        'mcq' => 'SECTION A: MULTIPLE CHOICE QUESTIONS',
        'true_false' => 'SECTION B: TRUE/FALSE QUESTIONS',
        'short_answer' => 'SECTION C: SHORT ANSWER QUESTIONS',
        'essay' => 'SECTION D: ESSAY QUESTIONS',
        'fill_blank' => 'SECTION E: FILL IN THE BLANK QUESTIONS'
    ];

    return $titles[$question_type] ?? 'SECTION: ' . strtoupper(str_replace('_', ' ', $question_type)) . ' QUESTIONS';
}

// Calculate total marks
$total_calculated_marks = 0;
foreach ($questions_by_type as $type_questions) {
    foreach ($type_questions as $question) {
        $total_calculated_marks += $question['marks'];
    }
}

if (isset($_POST['download_pdf']) && $_POST['download_pdf'] === '1') {
    require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator($school_name ?: 'School');
    $pdf->SetAuthor($school_name ?: 'School');
    $pdf->SetTitle($paper_title);
    $pdf->SetSubject($subject_name ?: 'Exam Paper');
    $pdf->SetHeaderData('', 0, '', '');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 18, 15);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->SetFont('times', '', 11);
    $pdf->AddPage();

    // Header
    $pdf->SetFont('times', 'B', 14);
    $pdf->Cell(0, 6, strtoupper($school_name ?: 'SCHOOL NAME'), 0, 1, 'C');
    if (!empty($school_motto)) {
        $pdf->SetFont('times', 'I', 10);
        $pdf->Cell(0, 5, $school_motto, 0, 1, 'C');
    }
    if (!empty($school_address)) {
        $pdf->SetFont('times', '', 9);
        $pdf->Cell(0, 4, $school_address, 0, 1, 'C');
    }
    $pdf->Ln(4);

    $pdf->SetFont('times', 'B', 12);
    $pdf->Cell(0, 6, strtoupper($paper_title), 0, 1, 'C');
    $pdf->Ln(2);

    // Paper info
    $pdf->SetFont('times', '', 10);
    $info = [
        'Subject' => $subject_name,
        'Class' => $class_name,
        'Term' => $term,
        'Time' => $time_allotted ? ($time_allotted . ' minutes') : '',
        'Total Marks' => $total_marks ?: $total_calculated_marks
    ];
    $line = '';
    foreach ($info as $label => $value) {
        if (!empty($value)) {
            $line .= $label . ': ' . $value . '    ';
        }
    }
    if (!empty($line)) {
        $pdf->MultiCell(0, 5, trim($line), 0, 'C');
        $pdf->Ln(2);
    }

    // Instructions
    if (!empty($general_instructions)) {
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(0, 6, 'GENERAL INSTRUCTIONS:', 0, 1);
        $pdf->SetFont('times', '', 10);
        $instructions = explode("\n", $general_instructions);
        $counter = 1;
        foreach ($instructions as $instruction) {
            $instruction = trim($instruction);
            if (!empty($instruction)) {
                $pdf->MultiCell(0, 5, $counter . '. ' . $instruction, 0, 'L');
                $counter++;
            }
        }
        $pdf->Ln(2);
    }

    if (!empty($specific_instructions)) {
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(0, 6, 'SPECIFIC INSTRUCTIONS:', 0, 1);
        $pdf->SetFont('times', '', 10);
        $pdf->MultiCell(0, 5, $specific_instructions, 0, 'L');
        $pdf->Ln(2);
    }

    // Questions
    $question_counter = 1;
    foreach ($questions_by_type as $question_type => $type_questions) {
        $section_title = getSectionTitle($question_type);
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(0, 7, $section_title, 0, 1);
        $pdf->Ln(2);

        foreach ($type_questions as $question) {
            $pdf->SetFont('times', '', 11);
            $marks_text = $question['marks'] ? ' (' . $question['marks'] . ' marks)' : '';
            $pdf->MultiCell(0, 6, $question_counter . '. ' . strip_tags($question['question_text']) . $marks_text, 0, 'L');

            if ($question_type === 'mcq' && !empty($question['options'])) {
                $pdf->SetFont('times', '', 10);
                foreach ($question['options'] as $option) {
                    $pdf->MultiCell(0, 5, '   (' . $option['letter'] . ') ' . $option['text'], 0, 'L');
                }
                $pdf->Ln(1);
            } elseif ($question_type === 'true_false') {
                $pdf->SetFont('times', '', 10);
                $pdf->Cell(0, 5, '[   ] True      [   ] False', 0, 1);
                $pdf->Ln(1);
            } elseif ($question_type === 'fill_blank') {
                $pdf->Ln(2);
            } elseif ($question_type === 'short_answer') {
                $pdf->Ln(3);
            } elseif ($question_type === 'essay') {
                $pdf->Ln(6);
            }

            $question_counter++;
        }
        $pdf->Ln(2);
    }

    $filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($paper_title)) . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($paper_title); ?> - Formatted</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12pt;
            line-height: 1.5;
            color: #000;
            background: white;
            margin: 0;
            padding: 0;
        }

        .exam-paper {
            max-width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 20mm 18mm;
            background: white;
        }

        .header {
            text-align: center;
            margin-bottom: 16px;
            padding-bottom: 0;
        }

        .school-name {
            font-size: 18pt;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .school-motto {
            font-size: 10pt;
            font-style: italic;
            margin-bottom: 10px;
        }

        .school-address {
            font-size: 10pt;
            margin-bottom: 10px;
        }

        .paper-title {
            font-size: 13pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 10px 0 4px;
        }

        .exam-info {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin: 10px 0 6px;
            padding: 0;
            justify-content: center;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .info-label {
            font-weight: bold;
            color: #000;
        }

        .info-value {
            color: #333;
        }

        .instructions-container {
            display: block;
            margin: 14px 0;
        }

        .instructions-section {
            display: inline-block;
            margin-right: 20px;
            vertical-align: top;
        }

        .instructions-title {
            font-weight: bold;
            color: #000;
            margin-bottom: 6px;
            font-size: 11pt;
            text-transform: uppercase;
        }

        .instructions-list {
            margin: 0;
            padding-left: 0;
            list-style: none;
            display: inline;
        }

        .instructions-section p {
            display: inline;
        }

        .instruction-item {
            margin: 4px 0;
            color: #000;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .instruction-bullet {
            font-weight: bold;
            color: #000;
            min-width: 15px;
        }

        .question-section {
            margin: 25px 0;
            page-break-inside: avoid;
        }

        .section-header {
            font-weight: bold;
            font-size: 12pt;
            color: #000;
            margin-bottom: 8px;
            padding-bottom: 0;
            text-transform: uppercase;
        }

        .question {
            margin: 6px 0 6px;
            padding: 0;
            page-break-inside: avoid;
        }

        .question-header {
            display: flex;
            align-items: flex-start;
            margin-bottom: 8px;
            gap: 10px;
        }

        .question-number {
            font-weight: bold;
            font-size: 12pt;
            color: #000;
            min-width: 24px;
        }

        .question-marks {
            font-weight: bold;
            font-style: italic;
            color: #000;
            font-size: 11pt;
        }

        .question-text {
            margin-bottom: 4px;
            line-height: 1.6;
            color: #000;
            flex: 1;
        }

        .options-inline {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 8px;
        }

        .option-item {
            display: flex;
            align-items: flex-start;
            gap: 5px;
            min-width: 120px;
        }

        .option-letter {
            font-weight: bold;
            color: #000;
            min-width: 15px;
        }

        .option-text {
            color: #000;
            line-height: 1.4;
        }

        .true-false-options {
            display: flex;
            gap: 20px;
            margin-top: 6px;
        }

        .true-false-option {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .answer-space {
            margin: 4px 0 0;
            min-height: 24px;
        }

        .answer-space.short {
            min-height: 40px;
        }

        .answer-space.essay {
            min-height: 90px;
        }

        .footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 0;
            font-size: 10pt;
            color: #000;
        }

        .summary-box {
            background: #fff;
            padding: 10px 0;
            margin: 12px 0;
            text-align: center;
        }

        .summary-item {
            display: inline-block;
            margin: 0 12px;
            font-weight: bold;
            color: #000;
        }

        @media print {
            body {
                padding: 0;
                background: white;
            }

            .exam-paper {
                box-shadow: none;
                padding: 0;
            }

            .no-print {
                display: none !important;
            }
        }

        .no-print {
            text-align: center;
            margin: 20px 0;
            padding: 15px;
        }

        .print-btn {
            background: #111827;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            margin: 0 8px;
        }
    </style>
</head>
<body>
    <div class="exam-paper">
        <!-- Header -->
        <div class="header">
            <div class="school-name"><?php echo htmlspecialchars($school_name ?: 'SCHOOL NAME'); ?></div>
            <?php if (!empty($school_motto)): ?>
                <div class="school-motto"><?php echo htmlspecialchars($school_motto); ?></div>
            <?php endif; ?>
            <?php if (!empty($school_address)): ?>
                <div class="school-address"><?php echo htmlspecialchars($school_address); ?></div>
            <?php endif; ?>
            <div class="paper-title"><?php echo htmlspecialchars($paper_title); ?></div>
        </div>

        <!-- Exam Information -->
        <div class="exam-info">
            <?php if (!empty($exam_type)): ?>
                <div class="info-item">
                    <span class="info-label">Exam Type:</span>
                    <span class="info-value"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $exam_type))); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($subject_name)): ?>
                <div class="info-item">
                    <span class="info-label">Subject:</span>
                    <span class="info-value"><?php echo htmlspecialchars($subject_name); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($class_name)): ?>
                <div class="info-item">
                    <span class="info-label">Class:</span>
                    <span class="info-value"><?php echo htmlspecialchars($class_name); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($term)): ?>
                <div class="info-item">
                    <span class="info-label">Term:</span>
                    <span class="info-value"><?php echo htmlspecialchars($term); ?></span>
                </div>
            <?php endif; ?>

            <div class="info-item">
                <span class="info-label">Time:</span>
                <span class="info-value"><?php echo $time_allotted; ?> minutes</span>
            </div>

            <div class="info-item">
                <span class="info-label">Total Marks:</span>
                <span class="info-value"><?php echo $total_marks ?: $total_calculated_marks; ?></span>
            </div>
        </div>


        <!-- Instructions -->
        <div class="instructions-container">
            <?php if (!empty($general_instructions)): ?>
                <div class="instructions-section">
                    <div class="instructions-title">GENERAL INSTRUCTIONS:</div>
                    <ul class="instructions-list">
                        <?php
                        $instructions = explode("\n", $general_instructions);
                        $counter = 1;
                        foreach ($instructions as $instruction):
                            $instruction = trim($instruction);
                            if (!empty($instruction)):
                        ?>
                            <li class="instruction-item">
                                <span class="instruction-bullet"><?php echo $counter; ?>.</span>
                                <span><?php echo htmlspecialchars($instruction); ?></span>
                            </li>
                        <?php
                            $counter++;
                            endif;
                        endforeach;
                        ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($specific_instructions)): ?>
                <div class="instructions-section">
                    <div class="instructions-title">SPECIFIC INSTRUCTIONS:</div>
                    <p><?php echo nl2br(htmlspecialchars($specific_instructions)); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Questions by Type -->
        <?php
        $question_counter = 1;
        foreach ($questions_by_type as $question_type => $type_questions):
            $section_title = getSectionTitle($question_type);
        ?>
            <div class="question-section">
                <div class="section-header"><?php echo htmlspecialchars($section_title); ?></div>

                <?php foreach ($type_questions as $question_id => $question): ?>
                    <div class="question">
                        <div class="question-header">
                            <span class="question-number"><?php echo $question_counter; ?>.</span>
                            <span class="question-text"><?php echo htmlspecialchars($question['question_text']); ?></span>
                            <span class="question-marks">[<?php echo $question['marks']; ?> marks]</span>
                        </div>

                        <?php if ($question_type === 'mcq' && !empty($question['options'])): ?>
                            <!-- Multiple Choice Questions - Options Inline -->
                            <div class="options-inline">
                                <?php foreach ($question['options'] as $option): ?>
                                    <div class="option-item">
                                        <span class="option-letter">(<?php echo $option['letter']; ?>)</span>
                                        <span class="option-text"><?php echo htmlspecialchars($option['text']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($question_type === 'true_false'): ?>
                            <!-- True/False Questions -->
                            <div class="true-false-options">
                                <div class="true-false-option">[ ] True</div>
                                <div class="true-false-option">[ ] False</div>
                            </div>
                        <?php elseif ($question_type === 'fill_blank'): ?>
                            <!-- Fill in the Blank -->
                            <div class="answer-space"></div>
                        <?php elseif ($question_type === 'short_answer'): ?>
                            <!-- Short Answer -->
                            <div class="answer-space short"></div>
                        <?php elseif ($question_type === 'essay'): ?>
                            <!-- Essay -->
                            <div class="answer-space essay"></div>
                        <?php endif; ?>
                    </div>
                <?php
                    $question_counter++;
                    endforeach;
                ?>
            </div>
        <?php endforeach; ?>

        <!-- Summary -->
        <div class="summary-box">
            <div class="summary-item">Total Questions: <?php echo count($selected_questions); ?></div>
            <div class="summary-item">Total Marks: <?php echo $total_calculated_marks; ?></div>
            <div class="summary-item">Time Allotted: <?php echo $time_allotted; ?> minutes</div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div>--- End of Paper ---</div>
            <div>Generated on: <?php echo date('d/m/Y H:i'); ?></div>
            <div>Prepared by: <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Teacher'); ?></div>
        </div>
    </div>

    <!-- Print Controls -->
    <div class="no-print">
        <button class="print-btn" onclick="window.print()">🖨️ Print Paper</button>
        <button class="print-btn" onclick="downloadPDF()">📄 Download as PDF</button>
        <button class="print-btn" onclick="window.location.href='generate_paper.php'">⬅️ Back</button>
    </div>

    <form id="pdfDownloadForm" method="POST" action="generate_paper_formatted.php" style="display: none;">
        <input type="hidden" name="download_pdf" value="1">
        <input type="hidden" name="selected_questions" value="<?php echo htmlspecialchars(json_encode($selected_questions)); ?>">
        <input type="hidden" name="paper_title" value="<?php echo htmlspecialchars($paper_title); ?>">
        <input type="hidden" name="general_instructions" value="<?php echo htmlspecialchars($general_instructions); ?>">
        <input type="hidden" name="instructions" value="<?php echo htmlspecialchars($specific_instructions); ?>">
        <input type="hidden" name="exam_type" value="<?php echo htmlspecialchars($exam_type); ?>">
        <input type="hidden" name="subject_id" value="<?php echo htmlspecialchars((string)$subject_id); ?>">
        <input type="hidden" name="class_id" value="<?php echo htmlspecialchars((string)$class_id); ?>">
        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
        <input type="hidden" name="time_allotted" value="<?php echo htmlspecialchars((string)$time_allotted); ?>">
        <input type="hidden" name="total_marks" value="<?php echo htmlspecialchars((string)$total_marks); ?>">
    </form>

    <script>
        function downloadPDF() {
            const form = document.getElementById('pdfDownloadForm');
            if (form) {
                form.submit();
            }
        }
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
