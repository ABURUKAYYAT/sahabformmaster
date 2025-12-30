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

$errors = [];
$success = '';
$generated_html = '';
$paper_id = 0;
$answer_key_html = '';

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

// Fetch data
$subjects = $pdo->query("SELECT id, subject_name FROM subjects ORDER BY subject_name")->fetchAll(PDO::FETCH_ASSOC);
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);

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
    WHERE q.status = 'approved'
";

$params = [];
$param_types = '';

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
$questions = [];
if (!empty($params)) {
    $stmt = $pdo->prepare($questions_query);
    $stmt->execute($params);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $questions = $pdo->query($questions_query)->fetchAll(PDO::FETCH_ASSOC);
}

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
    $html .= '
            </div>
            
            <!-- Footer -->
            <div class="footer">
                <div>--- End of Paper ---</div>
                <div>Total Marks: ' . $total_marks_calculated . '</div>
                <div>Paper Prepared by: ' . htmlspecialchars($paper['teacher_name'] ?? 'Teacher') . '</div>
            </div>
        </div>
        
        <!-- Print Button (for web view only) -->
        <div class="no-print" style="text-align: center; margin: 20px;">
            <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px;">
                <i class="fas fa-print"></i> Print Paper
            </button>
            <button onclick="generatePDF()" style="padding: 10px 20px; font-size: 16px; margin-left: 10px;">
                <i class="fas fa-download"></i> Download as PDF
            </button>
        </div>
        
        <script>
            function generatePDF() {
                window.location.href = "generate_pdf.php?paper_id=' . $paper_id . '";
            }
            
            // Auto-print option
            setTimeout(() => {
                if (window.location.hash === "#autoprint") {
                    window.print();
                }
            }, 1000);
        </script>
    </body>
    </html>';
    
    return $html;
}

// Function to generate AJAX preview HTML
function generateAjaxPreviewHTML($selected_questions, $school_name, $school_motto, $school_address, 
                                $paper_title, $exam_type, $subject_name, $class_name, $term, 
                                $time_allotted, $total_marks, $general_instructions, $specific_instructions) {
    global $pdo;
    
    if (empty($selected_questions) || !is_array($selected_questions)) {
        return '<div class="alert alert-info">No questions selected for preview.</div>';
    }
    
    // Prepare placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($selected_questions), '?'));
    
    // Fetch questions with their options
    $stmt = $pdo->prepare("
        SELECT q.*, qo.option_letter, qo.option_text, qo.is_correct
        FROM questions_bank q
        LEFT JOIN question_options qo ON q.id = qo.question_id
        WHERE q.id IN ($placeholders)
        ORDER BY FIELD(q.id, " . implode(',', $selected_questions) . ")
    ");
    $stmt->execute($selected_questions);
    $questions_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize questions with their options
    $questions = [];
    foreach ($questions_data as $row) {
        $question_id = $row['id'];
        if (!isset($questions[$question_id])) {
            $questions[$question_id] = [
                'question_text' => $row['question_text'],
                'question_type' => $row['question_type'],
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
    
    // Generate preview HTML
    $html = '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Paper Preview: ' . htmlspecialchars($paper_title) . '</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                padding: 20px;
                max-width: 800px;
                margin: 0 auto;
                background: #f9f9f9;
            }
            
            .preview-container {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            
            /* School Information */
            .school-header {
                text-align: center;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 2px solid #3498db;
            }
            
            .school-name {
                font-size: 22px;
                font-weight: bold;
                color: #2c3e50;
                margin-bottom: 5px;
            }
            
            .school-motto {
                font-size: 14px;
                font-style: italic;
                color: #7f8c8d;
                margin-bottom: 10px;
            }
            
            .school-address {
                font-size: 12px;
                color: #666;
                margin-bottom: 15px;
            }
            
            /* Paper Title */
            .paper-title {
                font-size: 20px;
                font-weight: bold;
                color: #2c3e50;
                text-align: center;
                margin: 15px 0;
                padding: 10px;
                background: #e8f4fc;
                border-radius: 5px;
            }
            
            /* Exam Information */
            .exam-info {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 10px;
                margin: 20px 0;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 5px;
                border-left: 4px solid #3498db;
            }
            
            .info-item {
                margin: 5px 0;
            }
            
            .info-label {
                font-weight: bold;
                color: #2c3e50;
                display: inline-block;
                width: 120px;
            }
            
            .info-value {
                color: #333;
            }
            
            /* Instructions */
            .instructions-section {
                margin: 20px 0;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 5px;
                border-left: 4px solid #2ecc71;
            }
            
            .instructions-title {
                font-weight: bold;
                color: #2c3e50;
                margin-bottom: 10px;
                font-size: 16px;
            }
            
            .instructions-list {
                margin: 0;
                padding-left: 0;
                list-style: none;
                counter-reset: instruction-counter;
            }
            
            .instruction-item {
                margin: 5px 0;
                color: #333;
                display: inline-block;
                margin-right: 15px;
            }
            
            .instruction-item::before {
                content: counter(instruction-counter) ". ";
                counter-increment: instruction-counter;
                font-weight: bold;
                color: #3498db;
            }
            
            /* Questions */
            .questions-section {
                margin: 25px 0;
            }
            
            .questions-title {
                font-weight: bold;
                color: #2c3e50;
                margin-bottom: 15px;
                padding-bottom: 8px;
                border-bottom: 1px solid #ddd;
                font-size: 18px;
            }
            
            .question-list {
                counter-reset: question-counter;
            }
            
            .question-item {
                margin: 15px 0;
                padding: 12px;
                border-bottom: 1px solid #eee;
                display: flex;
                align-items: flex-start;
            }
            
            .question-item:last-child {
                border-bottom: none;
            }
            
            .question-number {
                font-weight: bold;
                color: #2c3e50;
                margin-right: 10px;
                min-width: 25px;
                font-size: 16px;
            }
            
            .question-number::after {
                content: ".";
            }
            
            .question-content {
                flex: 1;
            }
            
            .question-text {
                margin-bottom: 8px;
                color: #333;
                display: inline;
            }
            
            .options-inline {
                display: inline;
                margin-left: 10px;
                color: #555;
                font-size: 14px;
            }
            
            .option-item {
                display: inline-block;
                margin-right: 15px;
            }
            
            .option-letter {
                font-weight: bold;
                color: #3498db;
            }
            
            /* Specific instructions styling */
            .specific-instructions {
                margin: 15px 0;
                padding: 10px;
                background: #fff3cd;
                border-radius: 5px;
                border-left: 4px solid #ffc107;
                font-size: 14px;
            }
        </style>
    </head>
    <body>
        <div class="preview-container">
            <!-- School Information -->
            <div class="school-header">
                <div class="school-name">' . htmlspecialchars($school_name ?: 'School Name') . '</div>';
    
    if (!empty($school_motto)) {
        $html .= '<div class="school-motto">' . htmlspecialchars($school_motto) . '</div>';
    }
    
    if (!empty($school_address)) {
        $html .= '<div class="school-address">' . htmlspecialchars($school_address) . '</div>';
    }
    
    $html .= '</div>';
    
    // Paper Title
    $html .= '<div class="paper-title">' . htmlspecialchars($paper_title ?: 'Exam Paper') . '</div>';
    
    // Exam Information
    $html .= '<div class="exam-info">
                <div class="info-item">
                    <span class="info-label">Exam Type:</span>
                    <span class="info-value">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $exam_type))) . '</span>
                </div>';
    
    if (!empty($subject_name)) {
        $html .= '<div class="info-item">
                    <span class="info-label">Subject:</span>
                    <span class="info-value">' . htmlspecialchars($subject_name) . '</span>
                </div>';
    }
    
    if (!empty($class_name)) {
        $html .= '<div class="info-item">
                    <span class="info-label">Class:</span>
                    <span class="info-value">' . htmlspecialchars($class_name) . '</span>
                </div>';
    }
    
    if (!empty($term)) {
        $html .= '<div class="info-item">
                    <span class="info-label">Term:</span>
                    <span class="info-value">' . htmlspecialchars($term) . '</span>
                </div>';
    }
    
    $html .= '<div class="info-item">
                <span class="info-label">Time Allotted:</span>
                <span class="info-value">' . $time_allotted . ' minutes</span>
            </div>
            <div class="info-item">
                <span class="info-label">Total Marks:</span>
                <span class="info-value">' . $total_marks . '</span>
            </div>
        </div>';
    
    // Instructions (Numbered and inline)
    if (!empty($general_instructions)) {
        $html .= '<div class="instructions-section">
                    <div class="instructions-title">General Instructions:</div>
                    <ul class="instructions-list">';
        
        // Split instructions by newline and number them
        $instructions_list = explode("\n", $general_instructions);
        foreach ($instructions_list as $instruction) {
            $instruction = trim($instruction);
            if (!empty($instruction)) {
                $html .= '<li class="instruction-item">' . htmlspecialchars($instruction) . '</li>';
            }
        }
        
        $html .= '</ul></div>';
    }
    
    // Specific Instructions
    if (!empty($specific_instructions)) {
        $html .= '<div class="specific-instructions">
                    <strong>Specific Instructions:</strong><br>
                    ' . nl2br(htmlspecialchars($specific_instructions)) . '
                  </div>';
    }
    
    // Questions
    $html .= '<div class="questions-section">
                <div class="questions-title">Questions:</div>
                <div class="question-list">';
    
    $question_counter = 1;
    foreach ($questions as $question_id => $question) {
        $html .= '
                <div class="question-item">
                    <div class="question-number">' . $question_counter . '</div>
                    <div class="question-content">
                        <span class="question-text">' . htmlspecialchars($question['question_text']) . '</span>';
        
        // Display options inline if available
        if (!empty($question['options'])) {
            $html .= '<div class="options-inline">';
            foreach ($question['options'] as $option) {
                $html .= '<span class="option-item">
                            <span class="option-letter">(' . $option['letter'] . ')</span> ' . htmlspecialchars($option['text']) . '
                          </span>';
            }
            $html .= '</div>';
        }
        
        $html .= '
                    </div>
                </div>';
        
        $question_counter++;
    }
    
    $html .= '
            </div>
        </div>';
    
    $html .= '
        </div>
    </body>
    </html>';
    
    return $html;
}

// Function to generate AJAX preview PDF using TCPDF
function generateAjaxPreviewPDF($selected_questions, $school_name, $school_motto, $school_address,
                               $paper_title, $exam_type, $subject_name, $class_name, $term,
                               $time_allotted, $total_marks, $general_instructions, $specific_instructions) {
    global $pdo;

    if (empty($selected_questions) || !is_array($selected_questions)) {
        return '';
    }

    // Include TCPDF
    require_once '../TCPDF-main/tcpdf.php';

    // Prepare placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($selected_questions), '?'));

    // Fetch questions with their options
    $stmt = $pdo->prepare("
        SELECT q.*, qo.option_letter, qo.option_text, qo.is_correct
        FROM questions_bank q
        LEFT JOIN question_options qo ON q.id = qo.question_id
        WHERE q.id IN ($placeholders)
        ORDER BY FIELD(q.id, " . implode(',', $selected_questions) . ")
    ");
    $stmt->execute($selected_questions);
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
    $pdf->SetCreator('Sahab School Management System');
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

// Function to generate answer key HTML (Simplified version)
function generateAnswerKeyHTML($selected_questions) {
    global $pdo;

    if (empty($selected_questions)) {
        return '<div class="alert alert-info">No questions selected for answer key.</div>';
    }

    // Convert JSON string to array if needed
    if (is_string($selected_questions)) {
        $selected_questions = json_decode($selected_questions, true);
    }

    // Prepare placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($selected_questions), '?'));

    // Fetch questions with correct answers
    $stmt = $pdo->prepare("
        SELECT q.id, q.question_text,
               GROUP_CONCAT(CASE WHEN qo.is_correct = 1 THEN qo.option_letter END SEPARATOR ', ') as correct_answers
        FROM questions_bank q
        LEFT JOIN question_options qo ON q.id = qo.question_id
        WHERE q.id IN ($placeholders)
        GROUP BY q.id
        ORDER BY FIELD(q.id, " . implode(',', $selected_questions) . ")
    ");
    $stmt->execute($selected_questions);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate simple answer key HTML
    $html = '<div class="simple-answer-key">
        <h3>Answer Key</h3>
        <table style="width: 100%; border-collapse: collapse; font-family: Arial, sans-serif;">
            <thead>
                <tr style="background: #f2f2f2;">
                    <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd; width: 30%;">Question No.</th>
                    <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd; width: 70%;">Correct Answer</th>
                </tr>
            </thead>
            <tbody>';

    $question_counter = 1;
    foreach ($questions as $question) {
        $correct_answer = $question['correct_answers'] ? $question['correct_answers'] : 'N/A';
        $row_bg = $question_counter % 2 == 0 ? 'background: #f9f9f9;' : '';

        $html .= '
                <tr style="' . $row_bg . '">
                    <td style="padding: 10px; border-bottom: 1px solid #eee;">' . $question_counter . '</td>
                    <td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; color: #28a745;">' . htmlspecialchars($correct_answer) . '</td>
                </tr>';

        $question_counter++;
    }

    $html .= '
            </tbody>
        </table>
        <div style="margin-top: 15px; font-size: 14px; color: #666;">
            Total Questions: ' . count($questions) . '
        </div>
    </div>';

    return $html;
}

// Create PDF generation file if it doesn't exist
$pdf_generator_path = '../generate_pdf.php';
if (!file_exists($pdf_generator_path)) {
    $pdf_generator_content = '<?php
// This file would use a PDF library like TCPDF or mPDF
// For now, it just redirects to the HTML version
$paper_id = $_GET[\'paper_id\'] ?? 0;
if ($paper_id > 0) {
    // In a real implementation, generate PDF here
    echo "PDF generation would be implemented here with a library like TCPDF";
    exit;
}
?>';
    file_put_contents($pdf_generator_path, $pdf_generator_content);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modern Exam Paper Generator</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --secondary-blue: #3b82f6;
            --light-blue: #dbeafe;
            --dark-blue: #1e40af;
            --accent-blue: #60a5fa;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, var(--light-blue) 0%, #f0f9ff 100%);
            min-height: 100vh;
            color: var(--gray-800);
            line-height: 1.6;
        }

        .topbar {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--dark-blue) 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }

        .header-content h1 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header-content h1 i {
            color: var(--accent-blue);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.9rem;
        }

        .badge {
            background: var(--accent-blue);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn.primary {
            background: var(--secondary-blue);
            color: white;
        }

        .btn.primary:hover {
            background: var(--primary-blue);
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        .btn.secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn.secondary:hover {
            background: var(--gray-300);
        }

        .btn.success {
            background: var(--success);
            color: white;
        }

        .btn.success:hover {
            background: #059669;
        }

        .btn i {
            font-size: 1rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: var(--danger);
        }

        .alert-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: var(--success);
        }

        .alert-info {
            background: var(--light-blue);
            border: 1px solid var(--secondary-blue);
            color: var(--primary-blue);
        }

        .alert i {
            font-size: 1.25rem;
            margin-top: 0.125rem;
        }

        .generator-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .generator-container {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
        }

        .panel {
            background: white;
            border-radius: 1rem;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panel-header {
            background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--primary-blue) 100%);
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .panel-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .panel-header h2 i {
            color: var(--accent-blue);
        }

        .panel-content {
            padding: 2rem;
        }

        @media (max-width: 768px) {
            .panel-content {
                padding: 1.5rem;
            }
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .form-section h3 {
            color: var(--primary-blue);
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-section h3 i {
            color: var(--secondary-blue);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        @media (max-width: 640px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--gray-200);
            border-radius: 0.5rem;
            font-size: 0.9rem;
            transition: all 0.2s;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-control.select2-container--default .select2-selection--single {
            border: 2px solid var(--gray-200);
            border-radius: 0.5rem;
            height: 2.75rem;
            background: white;
        }

        .form-control.select2-container--default.select2-container--focus .select2-selection--single {
            border-color: var(--secondary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .school-info-card {
            background: linear-gradient(135deg, var(--light-blue) 0%, #e0f2fe 100%);
            border: 1px solid var(--secondary-blue);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .school-info-card h4 {
            color: var(--primary-blue);
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .school-info-card h4 i {
            color: var(--secondary-blue);
        }

        .selected-questions-panel {
            background: var(--gray-50);
            border: 2px dashed var(--gray-300);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-top: 1.5rem;
            min-height: 150px;
        }

        .selected-questions-panel h4 {
            color: var(--primary-blue);
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .selected-questions-panel h4 i {
            color: var(--secondary-blue);
        }

        .selected-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .selected-item {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
        }

        .selected-item:hover {
            border-color: var(--secondary-blue);
            box-shadow: var(--shadow);
        }

        .selected-item-content {
            flex: 1;
        }

        .selected-item-title {
            font-weight: 600;
            color: var(--gray-800);
            font-size: 0.9rem;
        }

        .selected-item-meta {
            font-size: 0.8rem;
            color: var(--gray-500);
            margin-top: 0.25rem;
        }

        .remove-selected {
            color: var(--danger);
            cursor: pointer;
            font-size: 1.25rem;
            transition: all 0.2s;
        }

        .remove-selected:hover {
            color: #dc2626;
            transform: scale(1.1);
        }

        .marks-summary {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: white;
            padding: 1rem;
            border-radius: 0.5rem;
            text-align: center;
            font-weight: 700;
            font-size: 1.125rem;
            margin-top: 1rem;
            box-shadow: var(--shadow);
        }

        .marks-summary.hidden {
            display: none;
        }

        .questions-bank {
            max-height: 600px;
            overflow-y: auto;
        }

        .question-item {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 0.75rem;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
            cursor: pointer;
        }

        .question-item:hover {
            border-color: var(--secondary-blue);
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .question-item.selected {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border-color: var(--success);
        }

        .question-header {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .question-checkbox {
            margin-top: 0.25rem;
            width: 1.125rem;
            height: 1.125rem;
            accent-color: var(--secondary-blue);
        }

        .question-content {
            flex: 1;
        }

        .question-text {
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.5rem;
            line-height: 1.5;
        }

        .question-options {
            margin-left: 1rem;
            margin-top: 0.5rem;
        }

        .question-option {
            font-size: 0.875rem;
            color: var(--gray-600);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .question-option-letter {
            font-weight: 600;
            color: var(--secondary-blue);
            min-width: 1rem;
        }

        .question-meta {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            flex-wrap: wrap;
        }

        .meta-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .meta-badge.marks {
            background: var(--secondary-blue);
            color: white;
        }

        .meta-badge.type {
            background: var(--primary-blue);
            color: white;
        }

        .meta-badge.difficulty {
            background: var(--gray-500);
            color: white;
        }

        .search-section {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .search-section h3 {
            color: var(--primary-blue);
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-section h3 i {
            color: var(--secondary-blue);
        }

        .search-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        @media (max-width: 640px) {
            .search-form {
                grid-template-columns: 1fr;
            }
        }

        .question-counter {
            background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--primary-blue) 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 0.875rem;
        }

        .paper-preview {
            grid-column: 1 / -1;
            background: white;
            border-radius: 1rem;
            box-shadow: var(--shadow);
            overflow: hidden;
            display: none;
        }

        .paper-preview.active {
            display: block;
        }

        .preview-header {
            background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--primary-blue) 100%);
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .preview-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .preview-header h2 i {
            color: var(--accent-blue);
        }

        .preview-content {
            padding: 2rem;
        }

        .preview-frame {
            width: 100%;
            height: 800px;
            border: 2px solid var(--gray-200);
            border-radius: 0.5rem;
            background: var(--gray-50);
        }

        @media (max-width: 768px) {
            .preview-frame {
                height: 500px;
            }
        }

        .preview-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-content {
            background: white;
            padding: 2rem;
            border-radius: 1rem;
            text-align: center;
            box-shadow: var(--shadow-lg);
        }

        .loading-spinner {
            width: 3rem;
            height: 3rem;
            border: 4px solid var(--gray-200);
            border-top: 4px solid var(--secondary-blue);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-content p {
            color: var(--gray-700);
            font-weight: 600;
            margin: 0;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 1.5rem;
            transition: all 0.2s;
        }

        .back-link:hover {
            color: var(--dark-blue);
            transform: translateX(-2px);
        }

        .back-link i {
            font-size: 1rem;
        }

        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .topbar {
                padding: 1rem;
            }

            .header-content {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .user-info {
                flex-direction: column;
                gap: 0.5rem;
            }

            .panel-content {
                padding: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .search-form {
                grid-template-columns: 1fr;
            }

            .preview-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .btn {
                justify-content: center;
            }
        }

        /* Scrollbar styling */
        .questions-bank::-webkit-scrollbar {
            width: 6px;
        }

        .questions-bank::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 3px;
        }

        .questions-bank::-webkit-scrollbar-thumb {
            background: var(--secondary-blue);
            border-radius: 3px;
        }

        .questions-bank::-webkit-scrollbar-thumb:hover {
            background: var(--primary-blue);
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="header-content">
            <h1><i class="fas fa-file-alt"></i> Modern Exam Paper Generator</h1>
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></span>
                <span class="badge"><?php echo ucfirst($_SESSION['role'] ?? 'User'); ?></span>
            </div>
        </div>
    </header>

    <main class="container">
        <a href="questions.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Questions Bank
        </a>

        <!-- Messages -->
        <?php if($errors): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php foreach($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
                <?php if($paper_id > 0): ?>
                    <div class="alert" style="margin-top: 1rem;">
                        <a href="view_paper.php?id=<?php echo $paper_id; ?>" target="_blank" class="btn">
                            <i class="fas fa-eye"></i> View Paper
                        </a>
                        <button onclick="printPaper()" class="btn primary" style="margin-left: 0.5rem;">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <a href="generate_pdf.php?paper_id=<?php echo $paper_id; ?>" target="_blank" class="btn secondary" style="margin-left: 0.5rem;">
                            <i class="fas fa-download"></i> Download PDF
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="generator-container">
            <!-- Left Column: Paper Settings -->
            <div class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-cog"></i> Paper Settings</h2>
                </div>
                <div class="panel-content">
                    <form id="paperForm" method="POST">
                        <input type="hidden" name="action" value="generate_paper">

                        <!-- School Information -->
                        <div class="school-info-card">
                            <h4><i class="fas fa-school"></i> School Information</h4>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>School Name *</label>
                                    <input type="text" name="school_name" class="form-control" placeholder="School Name" required
                                           value="<?php echo htmlspecialchars($school_info['school_name'] ?? 'Sahab Form Master School'); ?>">
                                </div>
                                <div class="form-group">
                                    <label>School Motto</label>
                                    <input type="text" name="school_motto" class="form-control" placeholder="School Motto"
                                           value="<?php echo htmlspecialchars($school_info['motto'] ?? 'Excellence in Education'); ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>School Address</label>
                                <textarea name="school_address" class="form-control" placeholder="School Address"><?php echo htmlspecialchars($school_info['address'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3><i class="fas fa-clipboard-list"></i> Exam Information</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Paper Title *</label>
                                    <input type="text" name="paper_title" class="form-control" placeholder="Paper Title *" required
                                           value="End of Term Examination - <?php echo date('Y'); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Exam Type *</label>
                                    <select name="exam_type" class="form-control" required>
                                        <option value="">Select Exam Type *</option>
                                        <option value="unit_test">Unit Test</option>
                                        <option value="mid_term">Mid-Term</option>
                                        <option value="final" selected>Final Examination</option>
                                        <option value="pre_board">Pre-Board</option>
                                        <option value="quiz">Quiz</option>
                                        <option value="assignment">Assignment</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Term</label>
                                    <input type="text" name="term" class="form-control" placeholder="e.g., First Term, Second Term"
                                           value="First Term">
                                </div>
                                <div class="form-group">
                                    <label>Subject *</label>
                                    <select name="subject_id" id="subjectSelect" class="form-control" required>
                                        <option value="">Select Subject *</option>
                                        <?php foreach($subjects as $subject): ?>
                                            <option value="<?php echo $subject['id']; ?>" <?php echo ($subject_filter == $subject['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Class *</label>
                                    <select name="class_id" id="classSelect" class="form-control" required>
                                        <option value="">Select Class *</option>
                                        <?php foreach($classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>" <?php echo ($class_filter == $class['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Time Allotted (minutes) *</label>
                                    <input type="number" name="time_allotted" class="form-control" placeholder="Time (minutes) *"
                                           min="30" max="360" step="5" value="180" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Total Marks *</label>
                                <input type="number" name="total_marks" class="form-control" placeholder="Total Marks *"
                                       min="10" max="500" step="5" value="100" required>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3><i class="fas fa-info-circle"></i> Instructions</h3>
                            <div class="form-group">
                                <label>General Instructions</label>
                                <textarea name="general_instructions" class="form-control"
                                          placeholder="General instructions for all students...">1. All questions are compulsory.
2. Read each question carefully before answering.
3. Write your answers neatly and legibly.
4. Marks are indicated against each question.
5. Use black or blue pen only.</textarea>
                            </div>

                            <div class="form-group">
                                <label>Specific Instructions (Optional)</label>
                                <textarea name="instructions" class="form-control"
                                          placeholder="Specific instructions for this paper...">- Section A contains multiple choice questions.
- Section B contains short answer questions.
- Section C contains essay type questions.</textarea>
                            </div>
                        </div>

                        <!-- Selected Questions Summary -->
                        <div class="selected-questions-panel">
                            <h4><i class="fas fa-list-check"></i> Selected Questions</h4>
                            <div id="selectedList" class="selected-list">
                                <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                    <i class="fas fa-question-circle fa-2x" style="color: var(--gray-400); margin-bottom: 1rem;"></i>
                                    <p>No questions selected yet</p>
                                    <p style="font-size: 0.9rem;">Select questions from the right panel</p>
                                </div>
                            </div>

                            <div id="marksSummary" class="marks-summary hidden">
                                Total Marks: <span id="marksTotal">0</span> / <span id="marksTarget">100</span>
                            </div>
                        </div>

                        <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 2rem;">
                            <button type="submit" class="btn primary">
                                <i class="fas fa-magic"></i> Generate Exam Paper
                            </button>
                            <button type="button" class="btn" onclick="generateFormattedPaper()" style="background: var(--warning); color: white;">
                                <i class="fas fa-file-alt"></i> Generate Formatted Paper
                            </button>
                            <button type="button" class="btn secondary" onclick="previewPaper()">
                                <i class="fas fa-eye"></i> Preview
                            </button>
                            <button type="button" class="btn success" onclick="generateAnswerKey()">
                                <i class="fas fa-key"></i> Generate Answer Key
                            </button>
                            <button type="reset" class="btn" style="background: var(--gray-600); color: white;">
                                <i class="fas fa-redo"></i> Reset
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Column: Questions Selection -->
            <div class="panel">
                <div class="panel-header">
                    <h2><i class="fas fa-question-circle"></i> Questions Bank</h2>
                    <div class="question-counter" id="questionCounter">0 selected</div>
                </div>
                <div class="panel-content">
                    <!-- Search Section -->
                    <div class="search-section">
                        <h3><i class="fas fa-search"></i> Search & Filter Questions</h3>
                        <form method="GET" id="searchForm">
                            <input type="hidden" name="page" value="generate_paper">

                            <div class="search-form">
                                <div class="form-group">
                                    <label>Subject</label>
                                    <select name="subject_filter" id="subjectFilter" class="form-control">
                                        <option value="">All Subjects</option>
                                        <?php foreach($subjects as $subject): ?>
                                            <option value="<?php echo $subject['id']; ?>" <?php echo ($subject_filter == $subject['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Class</label>
                                    <select name="class_filter" id="classFilter" class="form-control">
                                        <option value="">All Classes</option>
                                        <?php foreach($classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>" <?php echo ($class_filter == $class['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Question Type</label>
                                    <select name="type_filter" id="typeFilter" class="form-control">
                                        <option value="">All Types</option>
                                        <option value="mcq" <?php echo ($type_filter == 'mcq') ? 'selected' : ''; ?>>MCQ</option>
                                        <option value="true_false" <?php echo ($type_filter == 'true_false') ? 'selected' : ''; ?>>True/False</option>
                                        <option value="short_answer" <?php echo ($type_filter == 'short_answer') ? 'selected' : ''; ?>>Short Answer</option>
                                        <option value="essay" <?php echo ($type_filter == 'essay') ? 'selected' : ''; ?>>Essay</option>
                                        <option value="fill_blank" <?php echo ($type_filter == 'fill_blank') ? 'selected' : ''; ?>>Fill in Blank</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Difficulty</label>
                                    <select name="difficulty_filter" id="difficultyFilter" class="form-control">
                                        <option value="">All Difficulty</option>
                                        <option value="easy" <?php echo ($difficulty_filter == 'easy') ? 'selected' : ''; ?>>Easy</option>
                                        <option value="medium" <?php echo ($difficulty_filter == 'medium') ? 'selected' : ''; ?>>Medium</option>
                                        <option value="hard" <?php echo ($difficulty_filter == 'hard') ? 'selected' : ''; ?>>Hard</option>
                                        <option value="very_hard" <?php echo ($difficulty_filter == 'very_hard') ? 'selected' : ''; ?>>Very Hard</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Search Text</label>
                                    <input type="text" name="search_text" id="searchText" class="form-control" placeholder="Search question text..."
                                           value="<?php echo htmlspecialchars($search_text); ?>">
                                </div>

                                <div class="form-group">
                                    <button type="submit" class="btn primary" style="width: 100%;">
                                        <i class="fas fa-filter"></i> Search
                                    </button>
                                </div>

                                <div class="form-group">
                                    <button type="button" class="btn" onclick="clearSearch()" style="width: 100%; background: var(--gray-600); color: white;">
                                        <i class="fas fa-times"></i> Clear
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Questions List -->
                    <div id="questionsList" class="questions-bank">
                        <?php if(empty($questions)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No questions found.
                                <?php if($subject_filter || $class_filter || $search_text): ?>
                                    Try changing your search criteria.
                                <?php else: ?>
                                    Please use the search filters above or add questions to the bank.
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php foreach($questions as $index => $question): ?>
                                <div class="question-item" data-id="<?php echo $question['id']; ?>"
                                     data-type="<?php echo $question['question_type']; ?>"
                                     data-difficulty="<?php echo $question['difficulty_level']; ?>"
                                     data-marks="<?php echo $question['marks']; ?>">
                                    <div class="question-header">
                                        <input type="checkbox" class="question-checkbox"
                                               id="q_<?php echo $question['id']; ?>"
                                               onchange="toggleQuestionSelection(this, <?php echo $question['id']; ?>)">
                                        <div class="question-content">
                                            <div class="question-text">
                                                <?php echo htmlspecialchars($question['question_text']); ?>
                                            </div>

                                            <?php if(!empty($question['options'])): ?>
                                            <div class="question-options">
                                                <?php foreach($question['options'] as $option): ?>
                                                    <div class="question-option">
                                                        <span class="question-option-letter"><?php echo $option['option_letter']; ?>)</span>
                                                        <?php echo htmlspecialchars($option['option_text']); ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>

                                            <div class="question-meta">
                                                <span class="meta-badge marks"><?php echo $question['marks']; ?> marks</span>
                                                <span class="meta-badge type"><?php echo strtoupper($question['question_type']); ?></span>
                                                <span class="meta-badge difficulty"><?php echo ucfirst(str_replace('_', ' ', $question['difficulty_level'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Paper Preview -->
        <div id="paperPreview" class="paper-preview">
            <div class="preview-header">
                <h2><i class="fas fa-file-pdf"></i> Paper Preview</h2>
                <button class="btn" onclick="closePreview()" style="background: var(--gray-600); color: white;">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>

            <div class="preview-content">
                <iframe id="previewFrame" class="preview-frame"
                        srcdoc="<html><body style='padding:20px;text-align:center;color:#666;'><h3>Preview will appear here</h3><p>Click the Preview button to generate preview</p></body></html>">
                </iframe>

                <div class="preview-actions">
                    <button onclick="printPreview()" class="btn primary">
                        <i class="fas fa-print"></i> Print Preview
                    </button>
                    <button onclick="downloadPreviewPDF()" class="btn secondary">
                        <i class="fas fa-download"></i> Download as PDF
                    </button>
                </div>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div id="loadingOverlay" class="loading-overlay">
            <div class="loading-content">
                <div class="loading-spinner"></div>
                <p>Generating preview...</p>
            </div>
        </div>
    </main>

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
</body>
</html>