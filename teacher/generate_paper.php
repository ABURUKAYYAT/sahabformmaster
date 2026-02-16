<?php
session_start();
require_once '../config/db.php';

// Check authorization
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Only allow teachers and principal
$allowed_roles = ['principal', 'teacher'];
if (!in_array(strtolower($_SESSION['role'] ?? ''), $allowed_roles)) {
    header("Location: ../index.php");
    exit;
}

// Get current school context
require_once '../includes/functions.php';
$current_school_id = require_school_auth();

$errors = [];
$success = '';
$generated_html = '';
$paper_id = 0;
$answer_key_html = '';

$teacher_name = $_SESSION['full_name'] ?? 'Teacher';

// Fetch school information for the form
$school_info = $pdo->query("SELECT * FROM school_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'generate_paper') {
        $paper_title = trim($_POST['paper_title'] ?? '');
        $exam_type = $_POST['exam_type'] ?? 'unit_test';
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $class_id = intval($_POST['class_id'] ?? 0);
        $total_marks = floatval($_POST['total_marks'] ?? 100);
        $time_allotted = intval($_POST['time_allotted'] ?? 180);
        $instructions = trim($_POST['instructions'] ?? '');
        $general_instructions = trim($_POST['general_instructions'] ?? '');
        $selected_questions = $_POST['selected_questions'] ?? [];
        
        // Get school info from form
        $school_name = trim($_POST['school_name'] ?? '');
        $school_motto = trim($_POST['school_motto'] ?? '');
        $school_address = trim($_POST['school_address'] ?? '');
        $term = trim($_POST['term'] ?? '');

        // Decode selected questions if it's a JSON string
        if (is_string($selected_questions) && !empty($selected_questions)) {
            $selected_questions = json_decode($selected_questions, true);
            if ($selected_questions === null) {
                $errors[] = 'Invalid question selection format.';
            }
        }

        // Validation
        if (empty($paper_title) || $subject_id <= 0 || $class_id <= 0) {
            $errors[] = 'Paper title, subject and class are required.';
        }

        if (empty($selected_questions) || !is_array($selected_questions)) {
            $errors[] = 'Please select at least one question for the paper.';
        }
        
        if ($total_marks <= 0) {
            $errors[] = 'Total marks must be greater than 0.';
        }
        
        if ($time_allotted <= 0) {
            $errors[] = 'Time allotted must be greater than 0 minutes.';
        }
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                // Generate paper code
                $prefix = 'PAP';
                $year = date('y');
                $month = date('m');
                
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM exam_papers WHERE YEAR(created_at) = YEAR(CURDATE())");
                $stmt->execute();
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] + 1;
                
                $paper_code = sprintf('%s%s%02d%04d', $prefix, $year, $month, $count);
                
                // Create exam paper
                $stmt = $pdo->prepare("
                    INSERT INTO exam_papers 
                    (paper_code, paper_title, exam_type, subject_id, class_id, 
                     total_marks, time_allotted, instructions, general_instructions, 
                     school_name, school_motto, school_address, term, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $paper_code,
                    $paper_title,
                    $exam_type,
                    $subject_id,
                    $class_id,
                    $total_marks,
                    $time_allotted,
                    $instructions,
                    $general_instructions,
                    $school_name,
                    $school_motto,
                    $school_address,
                    $term,
                    $_SESSION['user_id']
                ]);
                
                $paper_id = $pdo->lastInsertId();
                
                // Add selected questions
                foreach ($selected_questions as $index => $question_id) {
                    $question_id = intval($question_id);
                    if ($question_id > 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO paper_questions (paper_id, question_id, question_order)
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$paper_id, $question_id, $index + 1]);
                    }
                }
                
                // Generate paper HTML
                $generated_html = generatePaperHTML($paper_id, $_SESSION['user_id']);
                
                // Save generated paper
                $filename = 'paper_' . $paper_code . '_' . time() . '.html';
                $filepath = '../generated_papers/' . $filename;
                
                // Create directory if it doesn't exist
                if (!file_exists('../generated_papers')) {
                    mkdir('../generated_papers', 0777, true);
                }
                
                file_put_contents($filepath, $generated_html);
                
                // Save to database
                $stmt = $pdo->prepare("
                    INSERT INTO generated_papers (paper_id, file_path, file_format, generated_by)
                    VALUES (?, ?, 'html', ?)
                ");
                $stmt->execute([$paper_id, $filepath, $_SESSION['user_id']]);
                
                $pdo->commit();
                $success = 'Exam paper generated successfully! Paper Code: ' . $paper_code;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Failed to generate paper: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'generate_answers') {
        // Generate answer key
        $selected_questions = $_POST['selected_questions'] ?? [];
        
        if (empty($selected_questions)) {
            $errors[] = 'Please select at least one question to generate answer key.';
        } else {
            $answer_key_html = generateAnswerKeyHTML($selected_questions);
        }
    } elseif ($action === 'ajax_preview') {
        // Handle AJAX preview request - Generate PDF using TCPDF
        try {
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
                    echo json_encode(['error' => 'Invalid JSON format for selected questions.']);
                    exit;
                }
            }

            if (empty($selected_questions) || !is_array($selected_questions)) {
                echo json_encode(['error' => 'Please select at least one question to preview.']);
                exit;
            }

            // Get subject and class names
            $subject_name = '';
            $class_name = '';

            if ($subject_id > 0) {
                $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
                $stmt->execute([$subject_id]);
                $subject = $stmt->fetch(PDO::FETCH_ASSOC);
                $subject_name = $subject['subject_name'] ?? '';
            }

            if ($class_id > 0) {
                $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ?");
                $stmt->execute([$class_id]);
                $class = $stmt->fetch(PDO::FETCH_ASSOC);
                $class_name = $class['class_name'] ?? '';
            }

            // Generate PDF preview using TCPDF
            $pdf_data = generateAjaxPreviewPDF(
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
                $specific_instructions
            );

            echo json_encode(['pdf' => base64_encode($pdf_data)]);
            exit;

        } catch (Exception $e) {
            echo json_encode(['error' => 'Failed to generate preview: ' . $e->getMessage()]);
            exit;
        }
    }
}

// Fetch data with school filtering
$subjects = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? ORDER BY subject_name");
$subjects->execute([$current_school_id]);
$subjects = $subjects->fetchAll(PDO::FETCH_ASSOC);

$classes = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name");
$classes->execute([$current_school_id]);
$classes = $classes->fetchAll(PDO::FETCH_ASSOC);

// Get search parameters from GET or POST
$subject_filter = $_GET['subject_filter'] ?? $_POST['subject_filter'] ?? '';
$class_filter = $_GET['class_filter'] ?? $_POST['class_filter'] ?? '';
$type_filter = $_GET['type_filter'] ?? $_POST['type_filter'] ?? '';
$difficulty_filter = $_GET['difficulty_filter'] ?? $_POST['difficulty_filter'] ?? '';
$search_text = $_GET['search_text'] ?? $_POST['search_text'] ?? '';

$questions_query = "
    SELECT q.*, s.subject_name, c.class_name,
           GROUP_CONCAT(DISTINCT t.tag_name) as tags
    FROM questions_bank q
    LEFT JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN classes c ON q.class_id = c.id
    LEFT JOIN question_tags t ON q.id = t.question_id
    WHERE q.school_id = ?
";

$params = [$current_school_id];

if (!empty($subject_filter)) {
    $questions_query .= " AND q.subject_id = ?";
    $params[] = $subject_filter;
}

if (!empty($class_filter)) {
    $questions_query .= " AND q.class_id = ?";
    $params[] = $class_filter;
}

if (!empty($type_filter)) {
    $questions_query .= " AND q.question_type = ?";
    $params[] = $type_filter;
}

if (!empty($difficulty_filter)) {
    $questions_query .= " AND q.difficulty_level = ?";
    $params[] = $difficulty_filter;
}

if (!empty($search_text)) {
    $questions_query .= " AND (q.question_text LIKE ? OR q.question_text LIKE ?)";
    $params[] = '%' . $search_text . '%';
    $params[] = '%' . $search_text . '%';
}

$questions_query .= " GROUP BY q.id ORDER BY q.created_at DESC LIMIT 100";

// Fetch questions with their options
$stmt = $pdo->prepare($questions_query);
$stmt->execute($params);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch options for each question
foreach ($questions as &$question) {
    $stmt = $pdo->prepare("
        SELECT option_letter, option_text, is_correct 
        FROM question_options 
        WHERE question_id = ? 
        ORDER BY option_letter
    ");
    $stmt->execute([$question['id']]);
    $question['options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Find correct answer
    $correct_answers = [];
    foreach ($question['options'] as $option) {
        if ($option['is_correct'] == 1) {
            $correct_answers[] = $option['option_letter'];
        }
    }
    $question['correct_answer'] = implode(', ', $correct_answers);
}

// Function to generate answer key HTML
function generateAnswerKeyHTML($question_ids) {
    global $pdo;

    if (empty($question_ids)) {
        return '<div class="alert alert-warning">No questions selected for answer key generation.</div>';
    }

    $placeholders = str_repeat('?,', count($question_ids) - 1) . '?';

    $stmt = $pdo->prepare("
        SELECT qb.question_code, qb.question_text, qb.question_type, qb.marks,
               qo.option_letter, qo.option_text, qo.is_correct,
               s.subject_name, c.class_name
        FROM questions_bank qb
        LEFT JOIN question_options qo ON qb.id = qo.question_id
        LEFT JOIN subjects s ON qb.subject_id = s.id
        LEFT JOIN classes c ON qb.class_id = c.id
        WHERE qb.id IN ($placeholders)
        ORDER BY FIELD(qb.id, $placeholders), qo.option_letter
    ");

    $stmt->execute(array_merge($question_ids, $question_ids));
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organize by question
    $questions = [];
    foreach ($results as $row) {
        $qid = $row['question_code'];
        if (!isset($questions[$qid])) {
            $questions[$qid] = [
                'question_text' => $row['question_text'],
                'question_type' => $row['question_type'],
                'marks' => $row['marks'],
                'subject' => $row['subject_name'],
                'class' => $row['class_name'],
                'options' => []
            ];
        }
        if ($row['option_text']) {
            $questions[$qid]['options'][] = [
                'letter' => $row['option_letter'],
                'text' => $row['option_text'],
                'correct' => $row['is_correct']
            ];
        }
    }

    $html = '<div class="answer-key">';
    $html .= '<h2>Answer Key</h2>';
    $html .= '<div class="key-info">';
    $html .= '<p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>';
    $html .= '<p><strong>Total Questions:</strong> ' . count($questions) . '</p>';
    $html .= '</div>';

    $counter = 1;
    foreach ($questions as $code => $question) {
        $html .= '<div class="question-answer">';
        $html .= '<h4>Question ' . $counter . ' (' . $code . ')</h4>';
        $html .= '<p><strong>Subject:</strong> ' . htmlspecialchars($question['subject']) . ' | ';
        $html .= '<strong>Class:</strong> ' . htmlspecialchars($question['class']) . ' | ';
        $html .= '<strong>Marks:</strong> ' . $question['marks'] . '</p>';

        $html .= '<div class="question-text">' . htmlspecialchars($question['question_text']) . '</div>';

        if ($question['question_type'] === 'mcq' && !empty($question['options'])) {
            $html .= '<div class="correct-answer">';
            $html .= '<strong>Correct Answer(s):</strong> ';
            $correct_letters = [];
            foreach ($question['options'] as $option) {
                if ($option['correct']) {
                    $correct_letters[] = $option['letter'];
                }
            }
            $html .= implode(', ', $correct_letters);
            $html .= '</div>';
        } elseif ($question['question_type'] === 'true_false') {
            $html .= '<div class="correct-answer">';
            $html .= '<strong>Correct Answer:</strong> (To be determined by teacher)';
            $html .= '</div>';
        } else {
            $html .= '<div class="correct-answer">';
            $html .= '<strong>Answer:</strong> (To be provided by teacher)';
            $html .= '</div>';
        }

        $html .= '</div>';
        $counter++;
    }

    $html .= '</div>';
    return $html;
}

// Function to generate AJAX PDF preview
function generateAjaxPreviewPDF($question_ids, $school_name, $school_motto, $school_address, $paper_title, $exam_type, $subject_name, $class_name, $term, $time_allotted, $total_marks, $general_instructions, $specific_instructions) {
    // This is a placeholder - in a real implementation, you would use TCPDF or similar
    // For now, return a simple message
    return "PDF Preview functionality would be implemented using TCPDF library.\n\n" .
           "Paper Title: $paper_title\n" .
           "Questions Selected: " . count($question_ids) . "\n" .
           "Total Marks: $total_marks\n" .
           "Time: $time_allotted minutes\n\n" .
           "Please implement TCPDF integration for full functionality.";
}



// Function to generate paper HTML
function generatePaperHTML($paper_id, $user_id) {
    global $pdo;
    
    // Fetch paper details with school info
    $stmt = $pdo->prepare("
        SELECT ep.*, s.subject_name, c.class_name, u.full_name as teacher_name
        FROM exam_papers ep
        LEFT JOIN subjects s ON ep.subject_id = s.id
        LEFT JOIN classes c ON ep.class_id = c.id
        LEFT JOIN users u ON ep.created_by = u.id
        WHERE ep.id = ?
    ");
    $stmt->execute([$paper_id]);
    $paper = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Fetch paper questions
    $stmt = $pdo->prepare("
        SELECT pq.*, qb.*, qo.option_text, qo.option_letter, qo.is_correct
        FROM paper_questions pq
        LEFT JOIN questions_bank qb ON pq.question_id = qb.id
        LEFT JOIN question_options qo ON qb.id = qo.question_id
        WHERE pq.paper_id = ?
        ORDER BY pq.question_order
    ");
    $stmt->execute([$paper_id]);
    $paper_questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize questions with their options
    $questions = [];
    foreach ($paper_questions as $row) {
        $question_id = $row['question_id'];
        if (!isset($questions[$question_id])) {
            $questions[$question_id] = [
                'question_text' => $row['question_text'],
                'question_type' => $row['question_type'],
                'marks' => $row['marks'],
                'difficulty' => $row['difficulty_level'],
                'options' => []
            ];
        }
        if ($row['option_text']) {
            $questions[$question_id]['options'][] = [
                'letter' => $row['option_letter'],
                'text' => $row['option_text'],
                'is_correct' => $row['is_correct']
            ];
        }
    }
    
    // Generate HTML
    $html = '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($paper['paper_title']) . '</title>
        <style>
            /* Professional Exam Paper Styling */
            @page {
                size: A4;
                margin: 20mm;
            }
            
            body {
                font-family: "Times New Roman", Times, serif;
                font-size: 12pt;
                line-height: 1.5;
                color: #000;
                background: white;
                margin: 0;
                padding: 0;
            }
            
            .exam-paper {
                width: 210mm;
                min-height: 297mm;
                margin: 0 auto;
                padding: 20mm;
                box-sizing: border-box;
                border: 1px solid #ccc;
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
            
            .paper-info {
                width: 100%;
                border-collapse: collapse;
                margin: 15px 0;
                font-size: 11pt;
            }
            
            .paper-info td {
                padding: 5px 10px;
                border: 1px solid #000;
            }
            
            .paper-info .label {
                font-weight: bold;
                background: #f0f0f0;
                width: 30%;
            }
            
            .instructions {
                margin: 20px 0;
                padding: 15px;
                border: 1px solid #000;
                background: #f9f9f9;
            }
            
            .instructions h3 {
                margin-top: 0;
                font-size: 12pt;
            }
            
            .instructions ul {
                margin: 10px 0;
                padding-left: 20px;
            }
            
            .instructions li {
                margin-bottom: 5px;
            }
            
            .question-section {
                margin: 20px 0;
            }
            
            .section-title {
                font-weight: bold;
                font-size: 12pt;
                margin-bottom: 10px;
                padding-bottom: 5px;
                border-bottom: 1px solid #000;
            }
            
            .question {
                margin: 15px 0;
                page-break-inside: avoid;
            }
            
            .question-number {
                font-weight: bold;
                float: left;
                width: 30px;
            }
            
            .question-content {
                margin-left: 30px;
            }
            
            .question-text {
                margin-bottom: 10px;
            }
            
            .options {
                margin: 10px 0 10px 20px;
            }
            
            .option {
                margin-bottom: 5px;
            }
            
            .option-letter {
                font-weight: bold;
                display: inline-block;
                width: 20px;
            }
            
            .true-false {
                margin-left: 20px;
            }
            
            .true-false label {
                margin-right: 20px;
            }
            
            .answer-space {
                margin: 15px 0;
                border-bottom: 1px dashed #000;
                min-height: 50px;
            }
            
            .marks {
                float: right;
                font-weight: bold;
                font-style: italic;
            }
            
            .footer {
                text-align: center;
                margin-top: 40px;
                padding-top: 10px;
                border-top: 1px solid #000;
                font-size: 10pt;
            }
            
            .page-break {
                page-break-after: always;
            }
            
            .no-print {
                display: none;
            }
            
            /* For MCQ questions */
            .mcq-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin: 10px 0;
            }
            
            @media print {
                .exam-paper {
                    border: none;
                    padding: 0;
                }
                
                .no-print {
                    display: none !important;
                }
                
                .page-break {
                    page-break-after: always;
                }
            }
        </style>
    </head>
    <body>
        <div class="exam-paper">
            <!-- Header -->
            <div class="header">
                <div class="school-name">' . htmlspecialchars($paper['school_name'] ?? 'SCHOOL NAME') . '</div>
                <div class="school-motto">' . htmlspecialchars($paper['school_motto'] ?? 'Motto: Excellence in Education') . '</div>
                <div class="school-address">' . htmlspecialchars($paper['school_address'] ?? '') . '</div>
                <div class="paper-title">' . htmlspecialchars($paper['paper_title']) . '</div>
            </div>
            
            <!-- Paper Information -->
            <table class="paper-info">
                <tr>
                    <td class="label">Subject</td>
                    <td>' . htmlspecialchars($paper['subject_name']) . '</td>
                    <td class="label">Class</td>
                    <td>' . htmlspecialchars($paper['class_name']) . '</td>
                </tr>
                <tr>
                    <td class="label">Term</td>
                    <td>' . htmlspecialchars($paper['term'] ?? '') . '</td>
                    <td class="label">Time Allowed</td>
                    <td>' . $paper['time_allotted'] . ' minutes</td>
                </tr>
                <tr>
                    <td class="label">Maximum Marks</td>
                    <td>' . $paper['total_marks'] . '</td>
                    <td class="label">Paper Code</td>
                    <td>' . htmlspecialchars($paper['paper_code']) . '</td>
                </tr>
                <tr>
                    <td class="label">Date</td>
                    <td>' . date('d/m/Y') . '</td>
                    <td class="label">Prepared by</td>
                    <td>' . htmlspecialchars($paper['teacher_name'] ?? 'Teacher') . '</td>
                </tr>
            </table>
            
            <!-- General Instructions -->
            <div class="instructions">
                <h3>GENERAL INSTRUCTIONS:</h3>
                ' . (!empty($paper['general_instructions']) ? 
                    '<p>' . nl2br(htmlspecialchars($paper['general_instructions'])) . '</p>' : 
                    '<ul>
                        <li>All questions are compulsory</li>
                        <li>Read each question carefully before answering</li>
                        <li>Write your answers neatly and legibly</li>
                        <li>Marks are indicated against each question</li>
                    </ul>') . '
            </div>
            
            <!-- Specific Instructions -->
            ' . (!empty($paper['instructions']) ? 
                '<div class="instructions">
                    <h3>SPECIFIC INSTRUCTIONS:</h3>
                    <p>' . nl2br(htmlspecialchars($paper['instructions'])) . '</p>
                </div>' : '') . '
            
            <!-- Questions -->
            <div class="question-section">
                <div class="section-title">SECTION A - ALL QUESTIONS ARE COMPULSORY</div>';

    $question_counter = 1;
    $total_marks_calculated = 0;
    
    foreach ($questions as $question_id => $question) {
        $total_marks_calculated += $question['marks'];
        
        $html .= '
                <div class="question">
                    <div class="question-number">' . $question_counter . '.</div>
                    <div class="question-content">
                        <div class="question-text">' . $question['question_text'] . '</div>
                        <span class="marks">[' . $question['marks'] . ' marks]</span>';
        
        // Display based on question type
        switch ($question['question_type']) {
            case 'mcq':
                $html .= '
                        <div class="options">';
                foreach ($question['options'] as $option) {
                    $html .= '
                            <div class="option">
                                <span class="option-letter">(' . $option['letter'] . ')</span> ' . $option['text'] . '
                            </div>';
                }
                $html .= '
                        </div>';
                break;
                
            case 'true_false':
                $html .= '
                        <div class="true-false">
                            <label><input type="radio" name="q' . $question_id . '"> True</label>
                            <label><input type="radio" name="q' . $question_id . '"> False</label>
                        </div>';
                break;
                
            case 'fill_blank':
                $html .= '
                        <div class="answer-space"></div>';
                break;
                
            case 'short_answer':
                $html .= '
                        <div class="answer-space" style="min-height: 100px;"></div>';
                break;
                
            case 'essay':
                $html .= '
                        <div class="answer-space" style="min-height: 300px;"></div>';
                break;
        }
        
        $html .= '
                    </div>
                </div>';
        
        $question_counter++;
    }
    
    // Add total marks at the end
    $html .= <<<'HTML'
            </div>
             
             
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>
    <script>
        // Global variables
        let selectedQuestions = [];
        let selectedQuestionsData = {};
        let totalMarks = 0;
        
        // Initialize
        $(document).ready(function() {
            $('select').select2({
                width: '100%'
            });
            
            // Initialize drag and drop for selected questions
            const selectedList = document.getElementById('selectedList');
            if (selectedList) {
                Sortable.create(selectedList, {
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                    onEnd: function(evt) {
                        updateSelectedQuestionsOrder();
                    }
                });
            }
        });
        
        // Clear search
        function clearSearch() {
            window.location.href = 'generate_paper.php';
        }
        
        // Toggle question selection
        function toggleQuestionSelection(checkbox, questionId) {
            const questionItem = checkbox.closest('.question-item');
            const marks = parseFloat(questionItem.dataset.marks);
            const questionText = questionItem.querySelector('.question-text').textContent.trim();

            // Extract options if available
            const options = [];
            const optionElements = questionItem.querySelectorAll('.question-option');
            optionElements.forEach(opt => {
                const letter = opt.querySelector('.question-option-letter').textContent.replace(')', '');
                const text = opt.textContent.replace(letter + ')', '').trim();
                options.push({
                    letter: letter,
                    text: text
                });
            });

            if (checkbox.checked) {
                // Add to selected
                selectedQuestions.push(questionId);
                questionItem.classList.add('selected');

                // Store question data
                selectedQuestionsData[questionId] = {
                    id: questionId,
                    text: questionText,
                    marks: marks,
                    type: questionItem.dataset.type,
                    difficulty: questionItem.dataset.difficulty,
                    options: options
                };

                totalMarks += marks;
            } else {
                // Remove from selected
                const index = selectedQuestions.indexOf(questionId);
                if (index > -1) {
                    selectedQuestions.splice(index, 1);
                    delete selectedQuestionsData[questionId];
                    questionItem.classList.remove('selected');
                    totalMarks -= marks;
                }
            }

            updateSelectedList();
            updateQuestionCounter();
            updateTotalMarks();
            updateSelectedQuestionsInput();
        }
        
        // Update selected questions list display
        function updateSelectedList() {
            const selectedList = document.getElementById('selectedList');
            
            if (selectedQuestions.length === 0) {
                selectedList.innerHTML = `
                    <div style="text-align: center; padding: 30px; color: #6c757d;">
                        <i class="fas fa-question-circle fa-2x"></i>
                        <p>No questions selected yet</p>
                        <p>Select questions from the right panel</p>
                    </div>`;
                return;
            }
            
            let html = '';
            selectedQuestions.forEach((questionId, index) => {
                const question = selectedQuestionsData[questionId];
                if (question) {
                    html += `
                        <li data-id="${questionId}">
                            <div>
                                <strong>${index + 1}:</strong> 
                                ${question.text.substring(0, 80)}${question.text.length > 80 ? '...' : ''}
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span style="font-size: 0.8rem; color: #28a745; font-weight: bold;">
                                    ${question.marks} marks
                                </span>
                                <span class="remove-selected" onclick="removeSelectedQuestion(${questionId})">
                                    <i class="fas fa-times"></i>
                                </span>
                            </div>
                        </li>`;
                }
            });
            
            selectedList.innerHTML = '<ul class="selected-list">' + html + '</ul>';
            
            // Reinitialize Sortable on new list
            if (selectedList.querySelector('ul')) {
                Sortable.create(selectedList.querySelector('ul'), {
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                    onEnd: function(evt) {
                        updateSelectedQuestionsOrder();
                    }
                });
            }
        }
        
        // Remove selected question
        function removeSelectedQuestion(questionId) {
            const checkbox = document.querySelector(`.question-item[data-id="${questionId}"] .question-checkbox`);
            if (checkbox) {
                checkbox.checked = false;
                toggleQuestionSelection(checkbox, questionId);
            }
        }
        
        // Update question counter
        function updateQuestionCounter() {
            const counter = document.getElementById('questionCounter');
            counter.textContent = selectedQuestions.length + ' selected';
        }
        
        // Update total marks display
        function updateTotalMarks() {
            const totalMarksDisplay = document.getElementById('totalMarks');
            const marksTotal = document.getElementById('marksTotal');
            const marksTarget = document.getElementById('marksTarget');
            const targetMarks = parseFloat(document.querySelector('input[name="total_marks"]').value) || 100;
            
            if (selectedQuestions.length > 0) {
                marksTotal.textContent = totalMarks.toFixed(1);
                marksTarget.textContent = targetMarks;
                
                // Color code based on target
                if (totalMarks < targetMarks * 0.9) {
                    totalMarksDisplay.style.background = '#ffc107'; // Yellow
                } else if (totalMarks > targetMarks * 1.1) {
                    totalMarksDisplay.style.background = '#dc3545'; // Red
                } else {
                    totalMarksDisplay.style.background = '#28a745'; // Green
                }
                
                totalMarksDisplay.style.display = 'block';
            } else {
                totalMarksDisplay.style.display = 'none';
            }
        }
        
        // Update selected questions order after drag and drop
        function updateSelectedQuestionsOrder() {
            const listItems = document.querySelectorAll('#selectedList li');
            const newOrder = [];
            
            listItems.forEach(item => {
                const questionId = parseInt(item.dataset.id);
                newOrder.push(questionId);
            });
            
            selectedQuestions = newOrder;
            updateSelectedQuestionsInput();
        }
        
        // Update hidden input with selected questions
        function updateSelectedQuestionsInput() {
            document.getElementById('selectedQuestions').value = JSON.stringify(selectedQuestions);
        }
        
        // Generate answer key
        function generateAnswerKey() {
            if (selectedQuestions.length === 0) {
                alert('Please select at least one question to generate answer key.');
                return;
            }

            // Collect form data
            const form = document.getElementById('paperForm');
            const formData = new FormData(form);

            // Update the selected questions in form data
            formData.set('selected_questions', JSON.stringify(selectedQuestions));

            // Create a form to submit to generate_answer_key.php
            const formElement = document.createElement('form');
            formElement.method = 'POST';
            formElement.action = 'generate_answer_key.php';
            formElement.target = '_blank';
            formElement.style.display = 'none';

            // Add form data to the form
            for (const [key, value] of formData.entries()) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                formElement.appendChild(input);
            }

            // Submit the form
            document.body.appendChild(formElement);
            formElement.submit();
            document.body.removeChild(formElement);
        }
        
        // Print answer key
        function printAnswerKey() {
            const answerKeyContent = document.getElementById('answerKeyPreview');
            if (answerKeyContent) {
                const printWindow = window.open('', '_blank');
                printWindow.document.write(`
                    <html>
                    <head>
                        <title>Answer Key</title>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }
                            table { width: 100%; border-collapse: collapse; }
                            th, td { padding: 10px; text-align: left; }
                            th { background: #f2f2f2; }
                        </style>
                    </head>
                    <body>
                        ${answerKeyContent.innerHTML}
                        <script>
                            window.onload = function() {
                                window.print();
                                setTimeout(function() {
                                    window.close();
                                }, 100);
                            }
                        <\/script>
                    </body>
                    </html>
                `);
                printWindow.document.close();
            }
        }
        
        // Download answer key as PDF
        function downloadAnswerKeyPDF() {
            if (selectedQuestions.length === 0) {
                alert('Please select questions and generate answer key first.');
                return;
            }
            
            // In a real implementation, this would call a server-side PDF generator
            alert('PDF download would be implemented via server-side script. For now, use Print > Save as PDF');
        }
        
        // Preview paper using AJAX - FIXED VERSION
        // function previewPaper() {
            // Preview paper using AJAX - SIMPLIFIED VERSION
        // Preview paper using separate page
        function previewPaper() {
            if (selectedQuestions.length === 0) {
                alert('Please select at least one question to preview.');
                return;
            }

            // Collect form data
            const form = document.getElementById('paperForm');
            const formData = new FormData(form);

            // Update the selected questions in form data
            formData.set('selected_questions', JSON.stringify(selectedQuestions));

            // Create a form to submit to preview_paper.php
            const formElement = document.createElement('form');
            formElement.method = 'POST';
            formElement.action = 'preview_paper.php';
            formElement.target = '_blank';
            formElement.style.display = 'none';

            // Add form data to the form
            for (const [key, value] of formData.entries()) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                formElement.appendChild(input);
            }

            // Submit the form
            document.body.appendChild(formElement);
            formElement.submit();
            document.body.removeChild(formElement);
        }
        
        // Close preview
        function closePreview() {
            document.getElementById('paperPreview').classList.remove('active');
        }
        
        // Print preview
        function printPreview() {
            const iframe = document.getElementById('previewFrame');
            try {
                iframe.contentWindow.print();
            } catch (e) {
                console.error('Error printing preview:', e);
                alert('Cannot print preview. Please try generating the full paper first.');
            }
        }
        
        // Download preview as PDF
        function downloadPreviewPDF() {
            if (selectedQuestions.length === 0) {
                alert('Please select questions and generate paper first.');
                return;
            }

            // This would be handled by the server-side PDF generator
            alert('PDF download would be implemented via server-side script. For now, use Print > Save as PDF');
        }

        // Generate formatted paper
        function generateFormattedPaper() {
            if (selectedQuestions.length === 0) {
                alert('Please select at least one question to generate formatted paper.');
                return;
            }

            // Collect form data
            const form = document.getElementById('paperForm');
            const formData = new FormData(form);

            // Update the selected questions in form data
            formData.set('selected_questions', JSON.stringify(selectedQuestions));

            // Create a form to submit to generate_paper_formatted.php
            const formElement = document.createElement('form');
            formElement.method = 'POST';
            formElement.action = 'generate_paper_formatted.php';
            formElement.target = '_blank';
            formElement.style.display = 'none';

            // Add form data to the form
            for (const [key, value] of formData.entries()) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                formElement.appendChild(input);
            }

            // Submit the form
            document.body.appendChild(formElement);
            formElement.submit();
            document.body.removeChild(formElement);
        }
        
        // Print generated paper
        function printPaper() {
            if (<?php echo $paper_id; ?> > 0) {
                window.open('view_paper.php?id=<?php echo $paper_id; ?>#autoprint', '_blank');
            }
        }
        
        // Auto-update marks when total marks input changes
        document.querySelector('input[name="total_marks"]').addEventListener('input', function() {
            updateTotalMarks();
        });
        
        // Initialize checkboxes for already selected questions (if any)
        function initializeSelectedQuestions() {
            // This would initialize checkboxes if there are previously selected questions
            // For now, we'll just ensure the selectedQuestions array is empty on load
            selectedQuestions = [];
            selectedQuestionsData = {};
            updateSelectedList();
            updateQuestionCounter();
            updateTotalMarks();
        }
        
        // Initialize on page load
        window.onload = initializeSelectedQuestions;
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
HTML;

    return $html;
}
?>
<?php
$posted_selected_questions = $_POST['selected_questions'] ?? [];
if (is_string($posted_selected_questions) && $posted_selected_questions !== '') {
    $decoded_selected_questions = json_decode($posted_selected_questions, true);
    if (is_array($decoded_selected_questions)) {
        $posted_selected_questions = $decoded_selected_questions;
    }
}
if (!is_array($posted_selected_questions)) {
    $posted_selected_questions = [];
}
$selected_lookup = [];
foreach ($posted_selected_questions as $sid) {
    $selected_lookup[(int)$sid] = true;
}

$form_data = [
    'paper_title' => $_POST['paper_title'] ?? '',
    'exam_type' => $_POST['exam_type'] ?? 'unit_test',
    'subject_id' => (int)($_POST['subject_id'] ?? 0),
    'class_id' => (int)($_POST['class_id'] ?? 0),
    'total_marks' => $_POST['total_marks'] ?? '100',
    'time_allotted' => $_POST['time_allotted'] ?? '180',
    'term' => $_POST['term'] ?? '',
    'school_name' => $_POST['school_name'] ?? ($school_info['school_name'] ?? ''),
    'school_motto' => $_POST['school_motto'] ?? ($school_info['motto'] ?? ''),
    'school_address' => $_POST['school_address'] ?? ($school_info['address'] ?? ''),
    'general_instructions' => $_POST['general_instructions'] ?? '',
    'instructions' => $_POST['instructions'] ?? ''
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paper Builder | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Source+Serif+4:opsz,wght@8..60,500;8..60,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --sky-50: #f3f8ff;
            --sky-100: #e7f0ff;
            --sky-300: #9ec5ff;
            --sky-500: #3f7de6;
            --sky-700: #2559b6;
            --ink-900: #0f223f;
            --ink-700: #2f4569;
            --ink-500: #4f678d;
            --line: #d7e3f5;
            --success-bg: #e8f7ef;
            --success-line: #98d8b1;
            --error-bg: #feecee;
            --error-line: #f3b0b9;
            --amber: #f0a202;
            --danger: #cd3a53;
            --surface: #ffffff;
            --gradient-primary: linear-gradient(135deg, #2563eb 0%, #1d4ed8 45%, #1e40af 100%);
            --shadow-soft: 0 12px 28px rgba(15, 23, 42, 0.08);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--ink-900);
            background: #f5f7fb;
        }

        .dashboard-container .main-content {
            width: 100%;
        }

        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .hero {
            background: var(--gradient-primary);
            color: #fff;
            border-radius: 18px;
            padding: 22px 24px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 18px;
            box-shadow: var(--shadow-soft);
        }

        .hero h1 {
            margin: 0;
            font-size: 1.7rem;
            font-weight: 800;
            letter-spacing: 0.2px;
        }

        .hero p {
            margin: 8px 0 0;
            color: #d8e8ff;
            max-width: 760px;
        }

        .hero-metrics {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
            align-content: flex-start;
        }

        .chip {
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 0.84rem;
            white-space: nowrap;
        }

        .hero-link {
            background: #ffffff;
            color: #153f78;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 0.84rem;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .notice {
            border-radius: 12px;
            padding: 11px 14px;
            margin-top: 12px;
            border: 1px solid;
        }

        .notice-error { background: var(--error-bg); border-color: var(--error-line); }
        .notice-success { background: var(--success-bg); border-color: var(--success-line); }

        .notice-list { margin: 0; padding-left: 18px; }
        .notice-list li { margin: 4px 0; }

        .toolbar {
            margin-top: 14px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 16px;
            display: grid;
            grid-template-columns: repeat(5, minmax(120px, 1fr)) 1.4fr auto;
            gap: 10px;
            align-items: end;
            box-shadow: var(--shadow-soft);
        }

        .field label {
            display: block;
            font-size: 0.76rem;
            color: var(--ink-700);
            font-weight: 700;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .field input,
        .field select,
        .field textarea {
            width: 100%;
            border: 1px solid #c8d9f0;
            border-radius: 10px;
            padding: 10px 11px;
            font: inherit;
            color: var(--ink-900);
            background: #fff;
        }

        .field textarea { resize: vertical; min-height: 88px; }

        .btn {
            border: 0;
            border-radius: 10px;
            padding: 10px 14px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            display: inline-block;
        }

        .btn-primary { background: var(--sky-500); color: #fff; }
        .btn-secondary { background: #e7eef9; color: var(--ink-900); }
        .btn-ghost { background: #fff; color: var(--ink-700); border: 1px solid #c8d9f0; }
        .btn-success { background: #198754; color: #fff; }

        .workspace {
            margin-top: 14px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
            gap: 14px;
            align-items: start;
        }

        .card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 16px;
            box-shadow: var(--shadow-soft);
        }

        .card h2 {
            margin: 0 0 12px;
            font-family: 'Source Serif 4', serif;
            font-size: 1.3rem;
            color: #17345f;
        }

        .setup-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .span-2 { grid-column: 1 / -1; }

        .question-list { margin-top: 12px; display: grid; gap: 10px; }

        .question-card {
            border: 1px solid #d6e4f6;
            border-radius: 12px;
            padding: 12px;
            background: linear-gradient(180deg, #ffffff 0%, #f9fcff 100%);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .question-card.is-selected {
            border-color: #5e97ea;
            box-shadow: 0 0 0 2px rgba(63, 125, 230, 0.12);
        }

        .question-head {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 10px;
            align-items: start;
        }

        .question-text { margin: 0; line-height: 1.45; }

        .tag-row { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 9px; }

        .tag {
            font-size: 0.72rem;
            padding: 4px 8px;
            border-radius: 999px;
            border: 1px solid;
        }

        .tag-type { color: #214d8d; background: #e9f1ff; border-color: #bbd2f7; }
        .tag-difficulty { color: #614200; background: #fff2d8; border-color: #f4d086; }
        .tag-subject { color: #134460; background: #ddf2ff; border-color: #94d4f7; }
        .tag-status { color: #553c7b; background: #efe5ff; border-color: #cfb5ff; }

        .option-list {
            margin: 8px 0 0 26px;
            color: var(--ink-700);
            font-size: 0.9rem;
        }

        .builder-sidebar {
            position: sticky;
            top: 16px;
            display: grid;
            gap: 10px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .mini {
            border: 1px solid #d8e5f7;
            border-radius: 10px;
            padding: 8px;
            background: #f7fbff;
        }

        .mini .label {
            font-size: 0.72rem;
            color: var(--ink-700);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .mini .value {
            margin-top: 5px;
            font-size: 1.2rem;
            font-weight: 800;
        }

        .progress-wrap { margin-top: 10px; }
        .progress-track {
            width: 100%;
            height: 9px;
            border-radius: 999px;
            background: #e3edf9;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            width: 0;
            background: #3f7de6;
            transition: width 0.2s ease;
        }

        .selected-list {
            margin: 10px 0 0;
            padding: 0;
            list-style: none;
            max-height: 280px;
            overflow: auto;
            display: grid;
            gap: 7px;
        }

        .selected-item {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: center;
            border: 1px solid #d8e5f7;
            border-radius: 10px;
            padding: 8px;
            background: #fff;
        }

        .selected-item small { color: var(--ink-700); }
        .selected-item .remove {
            border: 0;
            background: #ffe8ec;
            color: #9a2240;
            width: 26px;
            height: 26px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 800;
        }

        .action-stack { display: grid; gap: 8px; margin-top: 8px; }
        .empty-note { color: var(--ink-700); font-size: 0.92rem; }

        @media (max-width: 1100px) {
            .toolbar { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .workspace { grid-template-columns: 1fr; }
            .builder-sidebar { position: static; }
        }

        @media (max-width: 768px) {
            .hero {
                grid-template-columns: 1fr;
                text-align: left;
            }

            .hero-metrics {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Navigation Component -->
    <?php include '../includes/mobile_navigation.php'; ?>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Teacher Portal</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="teacher-info">
                    <p class="teacher-label">Teacher</p>
                    <span class="teacher-name"><?php echo htmlspecialchars($teacher_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <?php include '../includes/teacher_sidebar.php'; ?>
        <main class="main-content">
            <div class="main-container">
    <header class="hero">
        <div>
            <h1>Paper Builder</h1>
            <p>Create exams from all question statuses, tune marks and timing, then generate paper PDF, formatted print view, and answer key from the same setup.</p>
        </div>
        <div class="hero-metrics">
            <div class="chip">Question Bank: <?php echo count($questions); ?></div>
            <div class="chip">Subjects: <?php echo count($subjects); ?></div>
            <div class="chip">Classes: <?php echo count($classes); ?></div>
            <a class="hero-link" href="questions.php">Manage Questions</a>
        </div>
    </header>

    <?php if (!empty($errors)): ?>
        <div class="notice notice-error">
            <ul class="notice-list">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="notice notice-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($paper_id > 0): ?>
        <div class="notice notice-success" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <strong>Paper Ready:</strong>
            <a class="btn btn-secondary" href="view_paper.php?id=<?php echo (int)$paper_id; ?>" target="_blank" rel="noopener">Open Saved Paper</a>
            <a class="btn btn-secondary" href="generate_paper_pdf.php?paper_id=<?php echo (int)$paper_id; ?>" target="_blank" rel="noopener">Download PDF</a>
        </div>
    <?php endif; ?>

    <form class="toolbar" method="get" action="generate_paper.php">
        <div class="field">
            <label>Subject Filter</label>
            <select name="subject_filter">
                <option value="">All Subjects</option>
                <?php foreach ($subjects as $subject): ?>
                    <option value="<?php echo (int)$subject['id']; ?>" <?php echo ((string)$subject_filter === (string)$subject['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Class Filter</label>
            <select name="class_filter">
                <option value="">All Classes</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo (int)$class['id']; ?>" <?php echo ((string)$class_filter === (string)$class['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($class['class_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Type</label>
            <select name="type_filter">
                <option value="">All Types</option>
                <?php foreach (['mcq'=>'MCQ','true_false'=>'True/False','fill_blank'=>'Fill Blank','short_answer'=>'Short Answer','essay'=>'Essay'] as $v => $t): ?>
                    <option value="<?php echo $v; ?>" <?php echo ($type_filter === $v) ? 'selected' : ''; ?>><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Difficulty</label>
            <select name="difficulty_filter">
                <option value="">All Levels</option>
                <?php foreach (['easy'=>'Easy','medium'=>'Medium','hard'=>'Hard'] as $v => $t): ?>
                    <option value="<?php echo $v; ?>" <?php echo ($difficulty_filter === $v) ? 'selected' : ''; ?>><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Search Text</label>
            <input type="text" name="search_text" value="<?php echo htmlspecialchars($search_text); ?>" placeholder="Search question text">
        </div>
        <button class="btn btn-primary" type="submit">Apply Filters</button>
        <a class="btn btn-ghost" href="generate_paper.php">Reset</a>
    </form>

    <form id="paperForm" method="post" action="generate_paper.php">
        <input type="hidden" name="action" value="generate_paper">

        <div class="workspace">
            <div style="display:grid;gap:12px;">
                <section class="card">
                    <h2>Paper Setup</h2>
                    <div class="setup-grid">
                        <div class="field">
                            <label>Paper Title</label>
                            <input type="text" name="paper_title" value="<?php echo htmlspecialchars($form_data['paper_title']); ?>" required>
                        </div>
                        <div class="field">
                            <label>Exam Type</label>
                            <select name="exam_type" required>
                                <?php foreach (['unit_test'=>'Unit Test','mid_term'=>'Mid Term','final_exam'=>'Final Exam','quiz'=>'Quiz'] as $v => $t): ?>
                                    <option value="<?php echo $v; ?>" <?php echo ($form_data['exam_type'] === $v) ? 'selected' : ''; ?>><?php echo $t; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Subject</label>
                            <select name="subject_id" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo (int)$subject['id']; ?>" <?php echo ((int)$form_data['subject_id'] === (int)$subject['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Class</label>
                            <select name="class_id" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo (int)$class['id']; ?>" <?php echo ((int)$form_data['class_id'] === (int)$class['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Total Marks Target</label>
                            <input id="totalMarksInput" type="number" name="total_marks" min="1" step="0.5" value="<?php echo htmlspecialchars((string)$form_data['total_marks']); ?>">
                        </div>
                        <div class="field">
                            <label>Time Allotted (Minutes)</label>
                            <input type="number" name="time_allotted" min="1" value="<?php echo htmlspecialchars((string)$form_data['time_allotted']); ?>">
                        </div>
                        <div class="field span-2">
                            <label>Term</label>
                            <input type="text" name="term" value="<?php echo htmlspecialchars($form_data['term']); ?>">
                        </div>
                        <div class="field">
                            <label>School Name</label>
                            <input type="text" name="school_name" value="<?php echo htmlspecialchars($form_data['school_name']); ?>">
                        </div>
                        <div class="field">
                            <label>School Motto</label>
                            <input type="text" name="school_motto" value="<?php echo htmlspecialchars($form_data['school_motto']); ?>">
                        </div>
                        <div class="field span-2">
                            <label>School Address</label>
                            <input type="text" name="school_address" value="<?php echo htmlspecialchars($form_data['school_address']); ?>">
                        </div>
                        <div class="field span-2">
                            <label>General Instructions</label>
                            <textarea name="general_instructions"><?php echo htmlspecialchars($form_data['general_instructions']); ?></textarea>
                        </div>
                        <div class="field span-2">
                            <label>Specific Instructions</label>
                            <textarea name="instructions"><?php echo htmlspecialchars($form_data['instructions']); ?></textarea>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <h2>Question Bank</h2>
                    <div class="empty-note">Showing <?php echo count($questions); ?> question(s) from all statuses. Select the exact set for this paper.</div>

                    <div class="question-list" id="questionList">
                        <?php if (empty($questions)): ?>
                            <div class="empty-note">No questions found for current filters.</div>
                        <?php else: ?>
                            <?php foreach ($questions as $q): ?>
                                <?php
                                    $qid = (int)$q['id'];
                                    $is_selected = isset($selected_lookup[$qid]);
                                    $raw_text = trim(strip_tags((string)($q['question_text'] ?? '')));
                                    $data_text = htmlspecialchars($raw_text, ENT_QUOTES, 'UTF-8');
                                ?>
                                <div class="question-card<?php echo $is_selected ? ' is-selected' : ''; ?>" data-question-id="<?php echo $qid; ?>">
                                    <div class="question-head">
                                        <input class="question-check" type="checkbox" name="selected_questions[]" value="<?php echo $qid; ?>" data-marks="<?php echo htmlspecialchars((string)$q['marks']); ?>" data-text="<?php echo $data_text; ?>" <?php echo $is_selected ? 'checked' : ''; ?>>
                                        <p class="question-text"><?php echo htmlspecialchars($raw_text); ?></p>
                                        <strong><?php echo htmlspecialchars((string)$q['marks']); ?>m</strong>
                                    </div>
                                    <div class="tag-row">
                                        <span class="tag tag-type"><?php echo htmlspecialchars(str_replace('_', ' ', (string)$q['question_type'])); ?></span>
                                        <span class="tag tag-difficulty"><?php echo htmlspecialchars((string)($q['difficulty_level'] ?? 'n/a')); ?></span>
                                        <span class="tag tag-status"><?php echo htmlspecialchars((string)($q['status'] ?? 'unknown')); ?></span>
                                        <span class="tag tag-subject"><?php echo htmlspecialchars((string)($q['subject_name'] ?? '')); ?> | <?php echo htmlspecialchars((string)($q['class_name'] ?? '')); ?></span>
                                    </div>
                                    <?php if (!empty($q['options']) && is_array($q['options'])): ?>
                                        <ul class="option-list">
                                            <?php foreach ($q['options'] as $opt): ?>
                                                <li><?php echo htmlspecialchars((string)$opt['option_letter']); ?>) <?php echo htmlspecialchars((string)$opt['option_text']); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <aside class="builder-sidebar">
                <section class="card">
                    <h2>Selection Summary</h2>
                    <div class="summary-grid">
                        <div class="mini">
                            <div class="label">Selected</div>
                            <div class="value" id="selectedCount">0</div>
                        </div>
                        <div class="mini">
                            <div class="label">Marks</div>
                            <div class="value" id="selectedMarks">0</div>
                        </div>
                    </div>
                    <div class="progress-wrap">
                        <div class="empty-note">Target: <strong id="targetMarksDisplay"><?php echo htmlspecialchars((string)$form_data['total_marks']); ?></strong></div>
                        <div class="progress-track"><div class="progress-bar" id="marksProgress"></div></div>
                    </div>
                    <ul class="selected-list" id="selectedList"></ul>
                </section>

                <section class="card action-stack">
                    <button class="btn btn-success" type="submit">Save & Generate Paper</button>
                    <button class="btn btn-secondary" id="previewBtn" type="button">Preview Paper PDF</button>
                    <button class="btn btn-secondary" id="formattedBtn" type="button">Open Formatted Paper</button>
                    <button class="btn btn-secondary" id="answerKeyBtn" type="button">Download Answer Key</button>
                </section>
            </aside>
        </div>
    </form>

    <?php if (!empty($answer_key_html)): ?>
        <section class="card" style="margin-top:14px;">
            <h2>Generated Answer Key</h2>
            <?php echo $answer_key_html; ?>
        </section>
    <?php endif; ?>
</div>
        </main>
    </div>

<?php include '../includes/floating-button.php'; ?>

<script>
(function () {
    const form = document.getElementById('paperForm');
    const checks = Array.from(document.querySelectorAll('.question-check'));
    const selectedCount = document.getElementById('selectedCount');
    const selectedMarks = document.getElementById('selectedMarks');
    const selectedList = document.getElementById('selectedList');
    const totalMarksInput = document.getElementById('totalMarksInput');
    const targetMarksDisplay = document.getElementById('targetMarksDisplay');
    const marksProgress = document.getElementById('marksProgress');

    function collectSelected() {
        const selected = [];
        checks.forEach((check) => {
            if (check.checked) {
                selected.push({
                    id: check.value,
                    marks: parseFloat(check.dataset.marks || '0'),
                    text: check.dataset.text || ''
                });
            }
        });
        return selected;
    }

    function syncCardStates() {
        checks.forEach((check) => {
            const card = check.closest('.question-card');
            if (card) {
                card.classList.toggle('is-selected', check.checked);
            }
        });
    }

    function renderSummary() {
        const selected = collectSelected();
        const marks = selected.reduce((sum, q) => sum + q.marks, 0);
        const target = parseFloat(totalMarksInput.value || '0') || 0;

        selectedCount.textContent = String(selected.length);
        selectedMarks.textContent = marks.toFixed(1);
        targetMarksDisplay.textContent = target > 0 ? target.toFixed(1) : '0';

        const pct = target > 0 ? Math.min((marks / target) * 100, 100) : 0;
        marksProgress.style.width = pct.toFixed(1) + '%';
        marksProgress.style.background = marks > target ? '#cd3a53' : (marks < target * 0.85 ? '#f0a202' : '#198754');

        selectedList.innerHTML = '';
        if (selected.length === 0) {
            const li = document.createElement('li');
            li.className = 'empty-note';
            li.textContent = 'No questions selected yet.';
            selectedList.appendChild(li);
            return;
        }

        selected.forEach((q, idx) => {
            const item = document.createElement('li');
            item.className = 'selected-item';

            const textWrap = document.createElement('div');
            const title = document.createElement('div');
            const shortText = q.text.length > 100 ? q.text.slice(0, 100) + '...' : q.text;
            title.textContent = (idx + 1) + '. ' + shortText;
            const meta = document.createElement('small');
            meta.textContent = q.marks.toFixed(1) + ' marks';
            textWrap.appendChild(title);
            textWrap.appendChild(meta);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'remove';
            remove.textContent = 'x';
            remove.addEventListener('click', function () {
                const check = checks.find(c => c.value === q.id);
                if (check) {
                    check.checked = false;
                    syncCardStates();
                    renderSummary();
                }
            });

            item.appendChild(textWrap);
            item.appendChild(remove);
            selectedList.appendChild(item);
        });
    }

    function getPayload() {
        const selected = collectSelected().map(q => q.id);
        if (selected.length === 0) {
            alert('Please select at least one question first.');
            return null;
        }

        return {
            selected_questions: JSON.stringify(selected),
            paper_title: form.paper_title.value || '',
            exam_type: form.exam_type.value || '',
            subject_id: form.subject_id.value || '',
            class_id: form.class_id.value || '',
            term: form.term.value || '',
            time_allotted: form.time_allotted.value || '',
            total_marks: form.total_marks.value || '',
            school_name: form.school_name.value || '',
            school_motto: form.school_motto.value || '',
            school_address: form.school_address.value || '',
            general_instructions: form.general_instructions.value || '',
            instructions: form.instructions.value || ''
        };
    }

    function postTo(url, openInNewTab) {
        const payload = getPayload();
        if (!payload) {
            return;
        }

        const tempForm = document.createElement('form');
        tempForm.method = 'POST';
        tempForm.action = url;
        if (openInNewTab) {
            tempForm.target = '_blank';
        }
        tempForm.style.display = 'none';

        Object.keys(payload).forEach((key) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = payload[key];
            tempForm.appendChild(input);
        });

        document.body.appendChild(tempForm);
        tempForm.submit();
        document.body.removeChild(tempForm);
    }

    checks.forEach((check) => {
        check.addEventListener('change', function () {
            syncCardStates();
            renderSummary();
        });
    });

    totalMarksInput.addEventListener('input', renderSummary);

    form.addEventListener('submit', function (event) {
        if (collectSelected().length === 0) {
            event.preventDefault();
            alert('Please select at least one question to generate a paper.');
        }
    });

    document.getElementById('previewBtn').addEventListener('click', function () {
        postTo('preview_paper.php', true);
    });

    document.getElementById('formattedBtn').addEventListener('click', function () {
        postTo('generate_paper_formatted.php', true);
    });

    document.getElementById('answerKeyBtn').addEventListener('click', function () {
        postTo('generate_answer_key.php', true);
    });

    syncCardStates();
    renderSummary();
})();
</script>
</body>
</html>
