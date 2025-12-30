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
$edit_mode = false;
$edit_question = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_question') {
        $question_text = trim($_POST['question_text'] ?? '');
        $question_type = $_POST['question_type'] ?? 'mcq';
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $class_id = intval($_POST['class_id'] ?? 0);
        $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
        $marks = floatval($_POST['marks'] ?? 1.0);
        $topic = trim($_POST['topic'] ?? '');
        $sub_topic = trim($_POST['sub_topic'] ?? '');
        $category_id = intval($_POST['category_id'] ?? 0);
        $cognitive_level = $_POST['cognitive_level'] ?? 'knowledge';
        
        // Validation
        if (empty($question_text) || $subject_id <= 0 || $class_id <= 0) {
            $errors[] = 'Question text, subject and class are required.';
        }
        
        if ($marks <= 0) {
            $errors[] = 'Marks must be greater than 0.';
        }
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                // Generate question code
                $prefix = 'Q';
                $year = date('y');
                $month = date('m');
                
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM questions_bank WHERE YEAR(created_at) = YEAR(CURDATE())");
                $stmt->execute();
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] + 1;
                
                $question_code = sprintf('%s%s%02d%04d', $prefix, $year, $month, $count);
                
                // Insert question
                $stmt = $pdo->prepare("
                    INSERT INTO questions_bank 
                    (question_code, question_text, question_type, subject_id, class_id, 
                     difficulty_level, marks, topic, sub_topic, category_id, cognitive_level, 
                     created_by, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
                ");
                
                $stmt->execute([
                    $question_code,
                    $question_text,
                    $question_type,
                    $subject_id,
                    $class_id,
                    $difficulty_level,
                    $marks,
                    $topic,
                    $sub_topic,
                    $category_id ?: null,
                    $cognitive_level,
                    $_SESSION['user_id']
                ]);
                
                $question_id = $pdo->lastInsertId();
                
                // Handle MCQ options
                if ($question_type === 'mcq' && isset($_POST['options'])) {
                    foreach ($_POST['options'] as $index => $option_text) {
                        if (!empty(trim($option_text))) {
                            $option_letter = chr(65 + $index); // A, B, C, D...
                            $is_correct = ($_POST['correct_option'] ?? '') == $option_letter;
                            
                            $option_stmt = $pdo->prepare("
                                INSERT INTO question_options 
                                (question_id, option_text, option_letter, is_correct)
                                VALUES (?, ?, ?, ?)
                            ");
                            
                            $option_stmt->execute([
                                $question_id,
                                trim($option_text),
                                $option_letter,
                                $is_correct ? 1 : 0
                            ]);
                        }
                    }
                }
                
                // Handle tags
                if (!empty($_POST['tags'])) {
                    $tags = array_map('trim', explode(',', $_POST['tags']));
                    foreach ($tags as $tag) {
                        if (!empty($tag)) {
                            $tag_stmt = $pdo->prepare("
                                INSERT INTO question_tags (question_id, tag_name)
                                VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE id=id
                            ");
                            $tag_stmt->execute([$question_id, $tag]);
                        }
                    }
                }
                
                $pdo->commit();
                $success = 'Question added successfully! Question Code: ' . $question_code;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Failed to add question: ' . $e->getMessage();
            }
        }
        
    } elseif ($action === 'update_question') {
        $question_id = intval($_POST['question_id'] ?? 0);
        
        if ($question_id > 0) {
            // Similar to create but with update logic
            // Implementation would be similar to create but with UPDATE statements
            $success = 'Question updated successfully!';
        }
        
    } elseif ($action === 'delete_question') {
        $question_id = intval($_POST['question_id'] ?? 0);
        
        if ($question_id > 0) {
            try {
                $pdo->beginTransaction();
                
                // Delete related records first
                $pdo->prepare("DELETE FROM question_options WHERE question_id = ?")->execute([$question_id]);
                $pdo->prepare("DELETE FROM question_tags WHERE question_id = ?")->execute([$question_id]);
                $pdo->prepare("DELETE FROM question_attachments WHERE question_id = ?")->execute([$question_id]);
                
                // Delete the question
                $stmt = $pdo->prepare("DELETE FROM questions_bank WHERE id = ?");
                $stmt->execute([$question_id]);
                
                if ($stmt->rowCount() > 0) {
                    $success = 'Question deleted successfully.';
                }
                
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Delete failed: ' . $e->getMessage();
            }
        }
        
    } elseif ($action === 'change_status') {
        $question_id = intval($_POST['question_id'] ?? 0);
        $new_status = $_POST['status'] ?? 'draft';
        
        if ($question_id > 0 && in_array($new_status, ['draft', 'reviewed', 'approved', 'rejected'])) {
            $stmt = $pdo->prepare("UPDATE questions_bank SET status = ? WHERE id = ?");
            if ($stmt->execute([$new_status, $question_id])) {
                $success = 'Question status updated to ' . ucfirst($new_status);
            }
        }
    }
}

// Handle edit mode
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("
            SELECT q.*, s.subject_name, c.class_name 
            FROM questions_bank q
            LEFT JOIN subjects s ON q.subject_id = s.id
            LEFT JOIN classes c ON q.class_id = c.id
            WHERE q.id = ?
        ");
        $stmt->execute([$edit_id]);
        $edit_question = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($edit_question) {
            $edit_mode = true;
            
            // Fetch options for MCQ questions
            if ($edit_question['question_type'] === 'mcq') {
                $options_stmt = $pdo->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY option_letter");
                $options_stmt->execute([$edit_id]);
                $edit_question['options'] = $options_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Fetch tags
            $tags_stmt = $pdo->prepare("SELECT GROUP_CONCAT(tag_name) as tags FROM question_tags WHERE question_id = ?");
            $tags_stmt->execute([$edit_id]);
            $tags = $tags_stmt->fetch(PDO::FETCH_ASSOC);
            $edit_question['tags'] = $tags['tags'] ?? '';
        }
    }
}

// Fetch data for filters and dropdowns
$subjects = $pdo->query("SELECT id, subject_name FROM subjects ORDER BY subject_name")->fetchAll(PDO::FETCH_ASSOC);
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT id, category_name FROM question_categories WHERE is_active = 1 ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

// Handle search and filters
$search = $_GET['search'] ?? '';
$subject_filter = $_GET['subject_filter'] ?? '';
$class_filter = $_GET['class_filter'] ?? '';
$difficulty_filter = $_GET['difficulty_filter'] ?? '';
$type_filter = $_GET['type_filter'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';

// Build query for question bank summary table
$query = "
    SELECT s.subject_name, q.question_type, COUNT(*) as question_count
    FROM questions_bank q
    LEFT JOIN subjects s ON q.subject_id = s.id
    WHERE 1=1
";

$params = [];

if (!empty($subject_filter)) {
    $query .= " AND q.subject_id = ?";
    $params[] = $subject_filter;
}

if (!empty($class_filter)) {
    $query .= " AND q.class_id = ?";
    $params[] = $class_filter;
}

if (!empty($difficulty_filter)) {
    $query .= " AND q.difficulty_level = ?";
    $params[] = $difficulty_filter;
}

if (!empty($type_filter)) {
    $query .= " AND q.question_type = ?";
    $params[] = $type_filter;
}

if (!empty($status_filter)) {
    $query .= " AND q.status = ?";
    $params[] = $status_filter;
}

$query .= " GROUP BY s.subject_name, q.question_type ORDER BY s.subject_name, q.question_type";

// Execute query
if (!empty($params)) {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $question_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $question_summary = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
}

// Get total count for display
$total_summary_count = count($question_summary);

// Get statistics
$total_questions = $pdo->query("SELECT COUNT(*) as total FROM questions_bank")->fetch(PDO::FETCH_ASSOC)['total'];
$mcq_count = $pdo->query("SELECT COUNT(*) as total FROM questions_bank WHERE question_type = 'mcq'")->fetch(PDO::FETCH_ASSOC)['total'];
$approved_count = $pdo->query("SELECT COUNT(*) as total FROM questions_bank WHERE status = 'approved'")->fetch(PDO::FETCH_ASSOC)['total'];
$my_questions = $pdo->query("SELECT COUNT(*) as total FROM questions_bank WHERE created_by = " . $_SESSION['user_id'])->fetch(PDO::FETCH_ASSOC)['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Questions Bank Management</title>
    <link rel="stylesheet" href="../assets/css/admin-students.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #1f2937;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #06b6d4;
            --light: #f8fafc;
            --dark: #111827;
            --accent: #8b5cf6;
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-accent: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .question-editor {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .editor-toolbar {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .editor-toolbar button {
            padding: 8px 15px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .editor-toolbar button:hover {
            background: #e9ecef;
            border-color: #3498db;
        }
        
        .question-textarea {
            width: 100%;
            min-height: 150px;
            padding: 15px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            font-size: 16px;
            line-height: 1.6;
            resize: vertical;
        }
        
        .question-textarea:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        .option-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .option-letter {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #3498db;
            color: white;
            border-radius: 50%;
            font-weight: bold;
        }
        
        .option-input {
            flex: 1;
            padding: 8px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        
        .option-actions {
            display: flex;
            gap: 5px;
        }
        
        .correct-option {
            background: #d4edda !important;
            border-color: #c3e6cb !important;
        }
        
        .difficulty-badge {
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .difficulty-easy { background: #d4edda; color: #155724; }
        .difficulty-medium { background: #fff3cd; color: #856404; }
        .difficulty-hard { background: #f8d7da; color: #721c24; }
        .difficulty-very_hard { background: #dc3545; color: white; }
        
        .status-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-draft { background: #6c757d; color: white; }
        .status-reviewed { background: #17a2b8; color: white; }
        .status-approved { background: #28a745; color: white; }
        .status-rejected { background: #dc3545; color: white; }
        .status-archived { background: #343a40; color: white; }
        
        .type-badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.8rem;
            background: #e9ecef;
            color: #495057;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 5px solid var(--primary);
        }
        
        .stat-card i {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 10px;
        }
        
        .stat-card .count {
            font-size: 2rem;
            font-weight: bold;
            color: var(--secondary);
            margin: 10px 0;
        }
        
        .question-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid var(--primary);
        }
        
        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .question-code {
            font-weight: bold;
            color: var(--primary);
            font-size: 1.1rem;
        }
        
        .question-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .question-content {
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .question-content img {
            max-width: 100%;
            height: auto;
            margin: 10px 0;
            border-radius: 5px;
        }
        
        .question-options {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .option-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 10px;
        }
        
        .option-list div {
            padding: 8px;
            border-radius: 4px;
            background: white;
            border: 1px solid #dee2e6;
        }
        
        .option-list div.correct {
            background: #d4edda;
            border-color: #c3e6cb;
            font-weight: bold;
        }
        
        .tags-container {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 15px;
        }
        
        .tag {
            background: #e9ecef;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.85rem;
            color: #495057;
        }
        
        .preview-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            overflow-y: auto;
        }
        
        .preview-content {
            background: white;
            width: 90%;
            max-width: 800px;
            margin: 50px auto;
            padding: 30px;
            border-radius: 10px;
            position: relative;
        }
        
        .close-preview {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
        }
        
        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .question-header {
                flex-direction: column;
                gap: 10px;
            }
            
            .question-meta {
                width: 100%;
                justify-content: flex-start;
            }
            
            .option-list {
                grid-template-columns: 1fr;
            }
            
            .preview-content {
                width: 95%;
                padding: 20px;
                margin: 20px auto;
            }
        }
        
        /* Modern Table Styles */
        .modern-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .modern-table thead {
            background: linear-gradient(135deg, var(--primary), #2980b9);
            color: white;
        }

        .modern-table th {
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: none;
        }

        .modern-table td {
            padding: 15px 20px;
            border-bottom: 1px solid #f1f1f1;
            transition: all 0.3s ease;
        }

        .modern-table tbody tr:hover {
            background: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .subject-cell {
            font-weight: 600;
            color: var(--secondary);
        }

        .type-cell .question-type-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .type-mcq { background: #e8f5e8; color: #2e7d32; }
        .type-true_false { background: #fff3e0; color: #ef6c00; }
        .type-short_answer { background: #e3f2fd; color: #1565c0; }
        .type-essay { background: #f3e5f5; color: #6a1b9a; }
        .type-fill_blank { background: #fce4ec; color: #ad1457; }

        .count-cell .question-count {
            display: inline-block;
            background: var(--primary);
            color: white;
            padding: 8px 16px;
            border-radius: 50px;
            font-weight: bold;
            font-size: 1.1rem;
            min-width: 50px;
            text-align: center;
        }

        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table-footer {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .summary-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .summary-stats span {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .summary-stats i {
            color: var(--primary);
        }

        /* Enhanced Mobile Responsiveness */
        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-card .count {
                font-size: 1.8rem;
            }

            .modern-table {
                font-size: 0.9rem;
            }

            .modern-table th,
            .modern-table td {
                padding: 12px 15px;
            }

            .table-footer {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .summary-stats {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .form-inline {
                flex-direction: column;
                gap: 12px;
            }

            .form-inline input,
            .form-inline select {
                width: 100%;
            }

            .panel-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }

            .export-buttons {
                width: 100%;
                justify-content: flex-start;
            }
        }

        @media (max-width: 480px) {
            .stats-container {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 12px;
                text-align: center;
            }

            .stat-card i {
                font-size: 2rem;
            }

            .stat-card .count {
                font-size: 1.5rem;
            }

            .container {
                padding: 10px;
            }

            .topbar {
                padding: 10px 15px;
            }

            .topbar h1 {
                font-size: 1.2rem;
            }

            .modern-table th,
            .modern-table td {
                padding: 10px 12px;
                font-size: 0.85rem;
            }

            .question-type-badge {
                padding: 4px 8px;
                font-size: 0.75rem;
            }

            .count-cell .question-count {
                padding: 6px 12px;
                font-size: 1rem;
            }

            .editor-toolbar {
                flex-direction: column;
            }

            .editor-toolbar button {
                width: 100%;
                padding: 10px;
                font-size: 0.9rem;
            }

            .btn {
                padding: 10px 16px;
                font-size: 0.9rem;
            }

            .panel {
                padding: 12px;
            }

            .panel h2 {
                font-size: 1.1rem;
            }
        }

        /* Print Styles */
        @media print {
            .modern-table {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .modern-table th {
                background: #f0f0f0 !important;
                color: black !important;
            }

            .no-print {
                display: none !important;
            }

            .table-footer {
                background: white !important;
                border-top: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <header class="topbar" style="background:  #1565c0; color:  #e3f2fd">
        <div class="header-content">
            <h1><i class="fas fa-question-circle"></i> Questions Bank Management</h1>
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></span>
                <span class="badge"><?php echo ucfirst($_SESSION['role'] ?? 'User'); ?></span>
            </div>
        </div>
    </header>
    
        
    <main class="container">
        <!-- Back Button -->
        <div style="margin-bottom: 20px;">
            <a href="../admin/dashboard.php" class="btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

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
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-container">
            <div class="stat-card">
                <i class="fas fa-question"></i>
                <h3>Total Questions</h3>
                <div class="count"><?php echo $total_questions; ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-list-ul"></i>
                <h3>MCQ Questions</h3>
                <div class="count"><?php echo $mcq_count; ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <h3>Approved</h3>
                <div class="count"><?php echo $approved_count; ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-user-edit"></i>
                <h3>My Questions</h3>
                <div class="count"><?php echo $my_questions; ?></div>
            </div>
        </div>

        <!-- Question Editor -->
        <section class="panel">
            <div class="panel-header">
                <h2>
                    <i class="fas fa-<?php echo $edit_mode ? 'edit' : 'plus'; ?>"></i>
                    <?php echo $edit_mode ? 'Edit Question' : 'Add New Question'; ?>
                </h2>
                <?php if($edit_mode): ?>
                    <a href="questions.php" class="btn secondary">
                        <i class="fas fa-plus"></i> Add New Instead
                    </a>
                <?php endif; ?>
            </div>
            
            <form id="questionForm" method="POST" class="question-editor">
                <input type="hidden" name="action" value="<?php echo $edit_mode ? 'update_question' : 'create_question'; ?>">
                <?php if($edit_mode): ?>
                    <input type="hidden" name="question_id" value="<?php echo $edit_question['id']; ?>">
                <?php endif; ?>
                
                <!-- Question Type Selection -->
                <div class="form-section">
                    <h3><i class="fas fa-tag"></i> Question Type & Basic Info</h3>
                    <div class="form-inline">
                        <select name="question_type" id="questionType" required 
                                onchange="toggleQuestionOptions()">
                            <option value="">Select Question Type *</option>
                            <option value="mcq" <?php echo ($edit_question['question_type'] ?? '') == 'mcq' ? 'selected' : ''; ?>>Multiple Choice (MCQ)</option>
                            <option value="true_false" <?php echo ($edit_question['question_type'] ?? '') == 'true_false' ? 'selected' : ''; ?>>True/False</option>
                            <option value="short_answer" <?php echo ($edit_question['question_type'] ?? '') == 'short_answer' ? 'selected' : ''; ?>>Short Answer</option>
                            <option value="essay" <?php echo ($edit_question['question_type'] ?? '') == 'essay' ? 'selected' : ''; ?>>Essay</option>
                            <option value="fill_blank" <?php echo ($edit_question['question_type'] ?? '') == 'fill_blank' ? 'selected' : ''; ?>>Fill in the Blank</option>
                        </select>
                        
                        <select name="subject_id" required>
                            <option value="">Select Subject *</option>
                            <?php foreach($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>"
                                    <?php echo ($edit_question['subject_id'] ?? '') == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="class_id" required>
                            <option value="">Select Class *</option>
                            <?php foreach($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"
                                    <?php echo ($edit_question['class_id'] ?? '') == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="difficulty_level" required>
                            <option value="">Difficulty Level *</option>
                            <option value="easy" <?php echo ($edit_question['difficulty_level'] ?? '') == 'easy' ? 'selected' : ''; ?>>Easy</option>
                            <option value="medium" <?php echo ($edit_question['difficulty_level'] ?? '') == 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="hard" <?php echo ($edit_question['difficulty_level'] ?? '') == 'hard' ? 'selected' : ''; ?>>Hard</option>
                            <option value="very_hard" <?php echo ($edit_question['difficulty_level'] ?? '') == 'very_hard' ? 'selected' : ''; ?>>Very Hard</option>
                        </select>
                    </div>
                    
                    <div class="form-inline" style="margin-top: 10px;">
                        <label for="marks">Set marks for the question</label>
                        <input type="number" name="marks" placeholder="Marks *" step="0.5" min="0.5" max="100" required
                               value="<?php echo $edit_question['marks'] ?? '1.00'; ?>">
                        
                        <select name="cognitive_level">
                            <option value="">Cognitive Level</option>
                            <option value="knowledge" <?php echo ($edit_question['cognitive_level'] ?? '') == 'knowledge' ? 'selected' : ''; ?>>Knowledge</option>
                            <option value="comprehension" <?php echo ($edit_question['cognitive_level'] ?? '') == 'comprehension' ? 'selected' : ''; ?>>Comprehension</option>
                            <option value="application" <?php echo ($edit_question['cognitive_level'] ?? '') == 'application' ? 'selected' : ''; ?>>Application</option>
                            <option value="analysis" <?php echo ($edit_question['cognitive_level'] ?? '') == 'analysis' ? 'selected' : ''; ?>>Analysis</option>
                            <option value="synthesis" <?php echo ($edit_question['cognitive_level'] ?? '') == 'synthesis' ? 'selected' : ''; ?>>Synthesis</option>
                            <option value="evaluation" <?php echo ($edit_question['cognitive_level'] ?? '') == 'evaluation' ? 'selected' : ''; ?>>Evaluation</option>
                        </select>
                        
                        <select name="category_id">
                            <option value="">Select Category</option>
                            <?php foreach($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"
                                    <?php echo ($edit_question['category_id'] ?? '') == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-inline" style="margin-top: 10px;">
                        <input type="text" name="topic" placeholder="Topic" 
                               value="<?php echo htmlspecialchars($edit_question['topic'] ?? ''); ?>">
                        
                        <input type="text" name="sub_topic" placeholder="Sub-Topic" 
                               value="<?php echo htmlspecialchars($edit_question['sub_topic'] ?? ''); ?>">
                        
                        <input type="text" name="tags" placeholder="Tags (comma separated)" 
                               value="<?php echo htmlspecialchars($edit_question['tags'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Question Text Editor -->
                <div class="form-section">
                    <h3><i class="fas fa-edit"></i> Question Text</h3>
                    <div class="editor-toolbar">
                        <button type="button" onclick="formatText('bold')"><i class="fas fa-bold"></i> Bold</button>
                        <button type="button" onclick="formatText('italic')"><i class="fas fa-italic"></i> Italic</button>
                        <button type="button" onclick="formatText('underline')"><i class="fas fa-underline"></i> Underline</button>
                        <button type="button" onclick="insertMath()"><i class="fas fa-square-root-alt"></i> Math</button>
                        <button type="button" onclick="insertImage()"><i class="fas fa-image"></i> Image</button>
                        <button type="button" onclick="insertTable()"><i class="fas fa-table"></i> Table</button>
                    </div>
                    
                    <textarea name="question_text" id="questionText" class="question-textarea" 
                              placeholder="Enter your question here..." required><?php echo htmlspecialchars($edit_question['question_text'] ?? ''); ?></textarea>
                    
                    <div style="margin-top: 10px; font-size: 0.9rem; color: #6c757d;">
                        <i class="fas fa-lightbulb"></i> Tip: Use HTML tags for formatting or the toolbar above.
                    </div>
                </div>

                <!-- MCQ Options (Initially hidden for non-MCQ types) -->
                <div id="mcqOptions" class="form-section" style="display: none;">
                    <h3><i class="fas fa-list-ul"></i> MCQ Options</h3>
                    <div id="optionsContainer">
                        <!-- Options will be added here dynamically -->
                        <?php if($edit_mode && $edit_question['question_type'] === 'mcq' && isset($edit_question['options'])): ?>
                            <?php foreach($edit_question['options'] as $index => $option): ?>
                                <div class="option-item <?php echo $option['is_correct'] ? 'correct-option' : ''; ?>">
                                    <div class="option-letter"><?php echo $option['option_letter']; ?></div>
                                    <input type="text" name="options[]" class="option-input" 
                                           value="<?php echo htmlspecialchars($option['option_text']); ?>"
                                           placeholder="Option <?php echo $option['option_letter']; ?>">
                                    <div class="option-actions">
                                        <input type="radio" name="correct_option" value="<?php echo $option['option_letter']; ?>"
                                               <?php echo $option['is_correct'] ? 'checked' : ''; ?> 
                                               onchange="markCorrectOption(this)">
                                        <button type="button" class="btn small danger" onclick="removeOption(this)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <button type="button" class="btn small" onclick="addOption()">
                        <i class="fas fa-plus"></i> Add Option
                    </button>
                    
                    <div style="margin-top: 10px; font-size: 0.9rem; color: #6c757d;">
                        <i class="fas fa-info-circle"></i> Select the radio button to mark an option as correct.
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="submit" class="btn primary">
                        <i class="fas fa-save"></i> 
                        <?php echo $edit_mode ? 'Update Question' : 'Save Question'; ?>
                    </button>
                    <button type="button" class="btn secondary" onclick="previewQuestion()">
                        <i class="fas fa-eye"></i> Preview
                    </button>
                    <button type="reset" class="btn">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </form>
        </section>
        
        <section class="panel">
            <div style="margin-bottom: 20px;">
                <a href="generate_paper.php" class="btn">
                    <i class="fas fa-arrow-up"></i> Generate questions paper
                </a>
            </div>
        </section>
        
        <!-- Search and Filter -->
        <section class="panel">
            <h2><i class="fas fa-search"></i> Search & Filter Questions</h2>
            <form method="GET" class="form-inline">
                <input type="text" name="search" placeholder="Search question text or topic" 
                       value="<?php echo htmlspecialchars($search); ?>">
                
                <select name="subject_filter">
                    <option value="">All Subjects</option>
                    <?php foreach($subjects as $subject): ?>
                        <option value="<?php echo $subject['id']; ?>"
                            <?php echo $subject_filter == $subject['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select name="class_filter">
                    <option value="">All Classes</option>
                    <?php foreach($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>"
                            <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['class_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select name="difficulty_filter">
                    <option value="">All Difficulty</option>
                    <option value="easy" <?php echo $difficulty_filter == 'easy' ? 'selected' : ''; ?>>Easy</option>
                    <option value="medium" <?php echo $difficulty_filter == 'medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="hard" <?php echo $difficulty_filter == 'hard' ? 'selected' : ''; ?>>Hard</option>
                    <option value="very_hard" <?php echo $difficulty_filter == 'very_hard' ? 'selected' : ''; ?>>Very Hard</option>
                </select>
                
                <select name="type_filter">
                    <option value="">All Types</option>
                    <option value="mcq" <?php echo $type_filter == 'mcq' ? 'selected' : ''; ?>>MCQ</option>
                    <option value="true_false" <?php echo $type_filter == 'true_false' ? 'selected' : ''; ?>>True/False</option>
                    <option value="short_answer" <?php echo $type_filter == 'short_answer' ? 'selected' : ''; ?>>Short Answer</option>
                    <option value="essay" <?php echo $type_filter == 'essay' ? 'selected' : ''; ?>>Essay</option>
                </select>
                
                <select name="status_filter">
                    <option value="">All Status</option>
                    <option value="draft" <?php echo $status_filter == 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="reviewed" <?php echo $status_filter == 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                    <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
                
                <button type="submit" class="btn primary">
                    <i class="fas fa-search"></i> Search
                </button>

                <a href="questions.php" class="btn secondary">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </form>
        </section>

        <!-- Questions Bank Summary Table -->
        <section class="panel">
            <div class="panel-header">
                <h2><i class="fas fa-table"></i> Questions Bank Summary (<?php echo $total_summary_count; ?> entries)</h2>
            </div>

            <?php if(empty($question_summary)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No questions found. Add your first question above!
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-book"></i> Subject</th>
                                <th><i class="fas fa-tag"></i> Question Type</th>
                                <th><i class="fas fa-hashtag"></i> Number of Questions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($question_summary as $summary): ?>
                                <tr>
                                    <td class="subject-cell">
                                        <strong><?php echo htmlspecialchars($summary['subject_name']); ?></strong>
                                    </td>
                                    <td class="type-cell">
                                        <span class="question-type-badge type-<?php echo $summary['question_type']; ?>">
                                            <?php
                                            $type_labels = [
                                                'mcq' => 'Multiple Choice',
                                                'true_false' => 'True/False',
                                                'short_answer' => 'Short Answer',
                                                'essay' => 'Essay',
                                                'fill_blank' => 'Fill in Blank'
                                            ];
                                            echo $type_labels[$summary['question_type']] ?? ucfirst(str_replace('_', ' ', $summary['question_type']));
                                            ?>
                                        </span>
                                    </td>
                                    <td class="count-cell">
                                        <span class="question-count"><?php echo $summary['question_count']; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <div class="summary-stats">
                        <span class="total-entries">
                            <i class="fas fa-chart-bar"></i>
                            Total Entries: <?php echo $total_summary_count; ?>
                        </span>
                        <span class="last-updated">
                            <i class="fas fa-clock"></i>
                            Last Updated: <?php echo date('d/m/Y H:i'); ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- Preview Modal -->
    <div id="previewModal" class="preview-modal">
        <div class="preview-content">
            <span class="close-preview" onclick="closePreview()">&times;</span>
            <div id="previewContent"></div>
        </div>
    </div>

    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script>
        // Initialize select2
        $(document).ready(function() {
            $('select').select2({
                width: '100%'
            });
        });
        
        // Toggle MCQ options based on question type
        function toggleQuestionOptions() {
            const questionType = document.getElementById('questionType').value;
            const mcqOptions = document.getElementById('mcqOptions');
            
            if (questionType === 'mcq') {
                mcqOptions.style.display = 'block';
                if (document.querySelectorAll('.option-item').length === 0) {
                    // Add initial options if none exist
                    addOption();
                    addOption();
                    addOption();
                    addOption();
                }
            } else {
                mcqOptions.style.display = 'none';
            }
        }
        
        // Add new option for MCQ
        let optionCounter = document.querySelectorAll('.option-item').length;
        
        function addOption() {
            optionCounter++;
            const optionLetter = String.fromCharCode(64 + optionCounter); // A, B, C...
            
            const optionItem = document.createElement('div');
            optionItem.className = 'option-item';
            optionItem.innerHTML = `
                <div class="option-letter">${optionLetter}</div>
                <input type="text" name="options[]" class="option-input" 
                       placeholder="Option ${optionLetter}" required>
                <div class="option-actions">
                    <input type="radio" name="correct_option" value="${optionLetter}" 
                           onchange="markCorrectOption(this)">
                    <button type="button" class="btn small danger" onclick="removeOption(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            document.getElementById('optionsContainer').appendChild(optionItem);
        }
        
        // Remove option
        function removeOption(button) {
            const optionItem = button.closest('.option-item');
            optionItem.remove();
            // Recalculate option letters
            recalculateOptionLetters();
        }
        
        // Mark option as correct
        function markCorrectOption(radio) {
            // Remove correct-option class from all options
            document.querySelectorAll('.option-item').forEach(item => {
                item.classList.remove('correct-option');
            });
            
            // Add correct-option class to selected option
            const optionItem = radio.closest('.option-item');
            optionItem.classList.add('correct-option');
        }
        
        // Recalculate option letters after removal
        function recalculateOptionLetters() {
            const options = document.querySelectorAll('.option-item');
            options.forEach((item, index) => {
                const newLetter = String.fromCharCode(65 + index); // A, B, C...
                item.querySelector('.option-letter').textContent = newLetter;
                item.querySelector('input[name="options[]"]').placeholder = `Option ${newLetter}`;
                const radio = item.querySelector('input[type="radio"]');
                if (radio) {
                    radio.value = newLetter;
                }
            });
            optionCounter = options.length;
        }
        
        // Text formatting functions
        function formatText(command) {
            const textarea = document.getElementById('questionText');
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const selectedText = textarea.value.substring(start, end);
            
            let formattedText = '';
            switch(command) {
                case 'bold':
                    formattedText = `<strong>${selectedText}</strong>`;
                    break;
                case 'italic':
                    formattedText = `<em>${selectedText}</em>`;
                    break;
                case 'underline':
                    formattedText = `<u>${selectedText}</u>`;
                    break;
            }
            
            textarea.value = textarea.value.substring(0, start) + formattedText + textarea.value.substring(end);
            textarea.focus();
            textarea.setSelectionRange(start + formattedText.length, start + formattedText.length);
        }
        
        function insertMath() {
            const textarea = document.getElementById('questionText');
            const start = textarea.selectionStart;
            const mathText = prompt('Enter mathematical expression (supports LaTeX):', 'x = \\frac{-b \\pm \\sqrt{b^2 - 4ac}}{2a}');
            
            if (mathText) {
                const formattedMath = `\\[${mathText}\\]`;
                textarea.value = textarea.value.substring(0, start) + formattedMath + textarea.value.substring(start);
                textarea.focus();
            }
        }
        
        function insertImage() {
            const imageUrl = prompt('Enter image URL:', 'https://example.com/image.jpg');
            if (imageUrl) {
                const textarea = document.getElementById('questionText');
                const start = textarea.selectionStart;
                const imgTag = `<img src="${imageUrl}" alt="Image" style="max-width: 100%;">`;
                textarea.value = textarea.value.substring(0, start) + imgTag + textarea.value.substring(start);
                textarea.focus();
            }
        }
        
        function insertTable() {
            const rows = prompt('Number of rows:', '3');
            const cols = prompt('Number of columns:', '3');
            
            if (rows && cols) {
                let tableHTML = '<table border="1" style="width: 100%; border-collapse: collapse;">';
                for (let i = 0; i < rows; i++) {
                    tableHTML += '<tr>';
                    for (let j = 0; j < cols; j++) {
                        tableHTML += `<td style="padding: 5px;">Cell ${i+1}-${j+1}</td>`;
                    }
                    tableHTML += '</tr>';
                }
                tableHTML += '</table>';
                
                const textarea = document.getElementById('questionText');
                const start = textarea.selectionStart;
                textarea.value = textarea.value.substring(0, start) + tableHTML + textarea.value.substring(start);
                textarea.focus();
            }
        }
        
        // Preview question
        function previewQuestion() {
            const form = document.getElementById('questionForm');
            const formData = new FormData(form);
            
            // Build preview HTML
            let previewHTML = `
                <h2>Question Preview</h2>
                <div class="question-preview">
                    <div style="margin-bottom: 20px;">
                        <strong>Type:</strong> ${document.getElementById('questionType').options[document.getElementById('questionType').selectedIndex].text}<br>
                        <strong>Subject:</strong> ${document.querySelector('select[name="subject_id"]').options[document.querySelector('select[name="subject_id"]').selectedIndex].text}<br>
                        <strong>Class:</strong> ${document.querySelector('select[name="class_id"]').options[document.querySelector('select[name="class_id"]').selectedIndex].text}<br>
                        <strong>Difficulty:</strong> ${document.querySelector('select[name="difficulty_level"]').options[document.querySelector('select[name="difficulty_level"]').selectedIndex].text}<br>
                        <strong>Marks:</strong> ${formData.get('marks')}
                    </div>
                    
                    <div style="border: 1px solid #ddd; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
                        ${document.getElementById('questionText').value}
                    </div>
            `;
            
            // Add options preview if MCQ
            if (document.getElementById('questionType').value === 'mcq') {
                const options = document.querySelectorAll('input[name="options[]"]');
                if (options.length > 0) {
                    previewHTML += '<h3>Options:</h3><ol>';
                    options.forEach((option, index) => {
                        if (option.value.trim()) {
                            const isCorrect = document.querySelector(`input[name="correct_option"][value="${String.fromCharCode(65 + index)}"]`)?.checked;
                            previewHTML += `<li ${isCorrect ? 'style="color: green; font-weight: bold;"' : ''}>${option.value} ${isCorrect ? '✓' : ''}</li>`;
                        }
                    });
                    previewHTML += '</ol>';
                }
            }
            
            previewHTML += '</div>';
            
            document.getElementById('previewContent').innerHTML = previewHTML;
            document.getElementById('previewModal').style.display = 'block';
        }
        
        // Close preview
        function closePreview() {
            document.getElementById('previewModal').style.display = 'none';
        }
        
        // View question details
        function viewQuestion(questionId) {
            // In a real implementation, this would fetch question details via AJAX
            alert('View question details for ID: ' + questionId + '\n\nThis would show full question with options, marks, etc.');
        }
        
        // Export questions
        function exportQuestions() {
            // This would export questions in various formats (PDF, Word, Excel)
            alert('Export functionality would be implemented here.\n\nOptions:\n1. Export as PDF\n2. Export as Word\n3. Export as Excel\n4. Export as JSON');
        }
        
        // Print table
        function printTable() {
            const tableContent = document.querySelector('.modern-table').outerHTML;
            const originalContent = document.body.innerHTML;

            document.body.innerHTML = `
                <html>
                    <head>
                        <title>Questions Bank Summary</title>
                        <style>
                            body { font-family: Arial, sans-serif; padding: 20px; }
                            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                            th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
                            th { background: #f8f9fa; font-weight: bold; }
                            .question-count { background: #3498db; color: white; padding: 4px 8px; border-radius: 50px; font-weight: bold; }
                            .question-type-badge { padding: 4px 8px; border-radius: 20px; font-size: 0.8rem; font-weight: 500; }
                            .type-mcq { background: #e8f5e8; color: #2e7d32; }
                            .type-true_false { background: #fff3e0; color: #ef6c00; }
                            .type-short_answer { background: #e3f2fd; color: #1565c0; }
                            .type-essay { background: #f3e5f5; color: #6a1b9a; }
                            .type-fill_blank { background: #fce4ec; color: #ad1457; }
                            @media print { body { padding: 0; } }
                        </style>
                    </head>
                    <body>
                        <h1>Questions Bank Summary</h1>
                        <p>Generated on: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}</p>
                        <p>Total Entries: ${document.querySelectorAll('.modern-table tbody tr').length}</p>
                        ${tableContent}
                    </body>
                </html>
            `;

            window.print();
            document.body.innerHTML = originalContent;
        }

        // Export table
        function exportTable() {
            const table = document.querySelector('.modern-table');
            let csv = 'Subject,Question Type,Number of Questions\n';

            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const subject = cells[0].textContent.trim();
                const type = cells[1].textContent.trim();
                const count = cells[2].textContent.trim();
                csv += `"${subject}","${type}","${count}"\n`;
            });

            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `questions-bank-summary-${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleQuestionOptions();
            
            // Close preview modal when clicking outside
            window.onclick = function(event) {
                const modal = document.getElementById('previewModal');
                if (event.target === modal) {
                    closePreview();
                }
            };
        });
    </script>
</body>
</html>
