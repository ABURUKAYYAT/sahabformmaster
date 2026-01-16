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
    </script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>


