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
            padding: 20px;
        }

        .exam-paper {
            max-width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 20mm;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
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
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 15px 0;
        }

        .exam-info {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
            justify-content: center;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .info-label {
            font-weight: bold;
            color: #2c3e50;
        }

        .info-value {
            color: #333;
        }

        .instructions-container {
            display: block;
            margin: 20px 0;
        }

        .instructions-section {
            display: inline-block;
            margin-right: 20px;
            vertical-align: top;
        }

        .instructions-title {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 14px;
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
            margin: 5px 0;
            color: #333;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .instruction-bullet {
            font-weight: bold;
            color: #3498db;
            min-width: 15px;
        }

        .question-section {
            margin: 25px 0;
            page-break-inside: avoid;
        }

        .section-header {
            font-weight: bold;
            font-size: 14pt;
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #3498db;
            text-transform: uppercase;
        }

        .question {
            margin: 15px 0;
            padding: 12px;
            border-bottom: 1px solid #eee;
            page-break-inside: avoid;
        }

        .question:last-child {
            border-bottom: none;
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
            color: #2c3e50;
            min-width: 25px;
        }

        .question-marks {
            font-weight: bold;
            font-style: italic;
            color: #e74c3c;
            font-size: 11pt;
        }

        .question-text {
            margin-bottom: 10px;
            line-height: 1.6;
            color: #333;
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
            color: #27ae60;
            min-width: 15px;
        }

        .option-text {
            color: #555;
            line-height: 1.4;
        }

        .true-false-options {
            display: flex;
            gap: 30px;
            margin-top: 8px;
        }

        .true-false-option {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .answer-space {
            margin: 15px 0;
            border-bottom: 1px dashed #000;
            min-height: 40px;
            padding: 8px 0;
        }

        .answer-space.short {
            min-height: 60px;
        }

        .answer-space.essay {
            min-height: 150px;
        }

        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 15px;
            border-top: 1px solid #000;
            font-size: 10pt;
            color: #666;
        }

        .summary-box {
            background: #ecf0f1;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            text-align: center;
        }

        .summary-item {
            display: inline-block;
            margin: 0 15px;
            font-weight: bold;
            color: #2c3e50;
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
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            margin: 0 10px;
        }

        .print-btn:hover {
            background: #2980b9;
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
                <span class="info-value"><?php echo $total_marks; ?></span>
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
                                <div class="true-false-option">
                                    <input type="radio" name="q<?php echo $question_id; ?>" value="true">
                                    <span>True</span>
                                </div>
                                <div class="true-false-option">
                                    <input type="radio" name="q<?php echo $question_id; ?>" value="false">
                                    <span>False</span>
                                </div>
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

    <script>
        function downloadPDF() {
            // This would integrate with a PDF generation library
            alert('PDF download functionality would be implemented with a server-side PDF generator.');
            window.print(); // Fallback to print
        }
    </script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>
