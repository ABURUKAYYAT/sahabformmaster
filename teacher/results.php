<?php
session_start();
require_once '../config/db.php';

// Only allow class teachers to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];

$class_id = $_GET['id'] ?? $_GET['class_id'] ?? $_REQUEST['class'] ?? $_POST['class_id'] ?? null;

function normalize_term(string $t): string {
    $map = [ 
        'first term' => '1st Term', '1st term' => '1st Term', '1st' => '1st Term', 'first' => '1st Term',
        'second term' => '2nd Term', '2nd term' => '2nd Term', '2nd' => '2nd Term', 'second' => '2nd Term',
        'third term' => '3rd Term', '3rd term' => '3rd Term', '3rd' => '3rd Term', 'third' => '3rd Term'
    ];
    $k = strtolower(trim($t));
    return $map[$k] ?? $t;
}

$term = $_GET['term'] ?? $_REQUEST['term'] ?? '1st Term';
$term = normalize_term($term);
$errors = [];
$success = '';

// Validate class_id
if ($class_id !== null) {
    $class_id = filter_var($class_id, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
}

if (!$class_id) {
    // Try to infer a single class assigned to this teacher
    $stmt = $pdo->prepare("SELECT DISTINCT sa.class_id FROM subject_assignments sa WHERE sa.teacher_id = :teacher_id");
    $stmt->execute(['teacher_id' => $teacher_id]);
    $classes_for_teacher = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($classes_for_teacher) === 1) {
        $class_id = (int)$classes_for_teacher[0];
    } else {
        header("Location: ../index.php?error=invalid_or_missing_class_id");
        exit;
    }
}

// Fetch teacher's classes
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.class_name
    FROM classes c
    JOIN subject_assignments sa ON c.id = sa.class_id
    WHERE sa.teacher_id = :teacher_id
    ORDER BY c.class_name
");
$stmt->execute(['teacher_id' => $teacher_id]);
$teacher_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter class
$filter_class_id = isset($_GET['filter_class_id']) ? filter_var($_GET['filter_class_id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : null;
if ($filter_class_id) {
    $assignedIds = array_column($teacher_classes, 'id');
    if (!in_array($filter_class_id, $assignedIds, true)) {
        $filter_class_id = $class_id;
    }
} else {
    $filter_class_id = $class_id;
}

// Ensure teacher is assigned to this class
$stmt = $pdo->prepare("SELECT COUNT(*) FROM subject_assignments WHERE class_id = :class_id AND teacher_id = :teacher_id");
$stmt->execute(['class_id' => $class_id, 'teacher_id' => $teacher_id]);
if ((int)$stmt->fetchColumn() === 0) {
    die("Access denied. You are not assigned to this class.");
}

// Fetch class information
$stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :id");
$stmt->execute(['id' => $class_id]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$class) {
    header("Location: index.php?error=Class not found.");
    exit;
}

// Fetch students in the class
$stmt = $pdo->prepare("SELECT * FROM students WHERE class_id = :class_id ORDER BY full_name ASC");
$stmt->execute(['class_id' => $class_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch subjects assigned to this teacher for this class
$stmt = $pdo->prepare("
    SELECT s.* 
    FROM subjects s 
    JOIN subject_assignments sa ON s.id = sa.subject_id 
    WHERE sa.class_id = :class_id AND sa.teacher_id = :teacher_id 
    ORDER BY s.subject_name ASC
");
$stmt->execute(['class_id' => $class_id, 'teacher_id' => $teacher_id]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch academic sessions
$stmt = $pdo->prepare("SELECT DISTINCT academic_session FROM results ORDER BY academic_session DESC");
$academic_sessions = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_batch_results') {
        $selected_student_ids = $_POST['student_ids'] ?? [];
        $academic_session = trim($_POST['academic_session'] ?? '');
        
        if (empty($selected_student_ids)) {
            $errors[] = "Please select at least one student.";
        }
        
        if (empty($academic_session)) {
            $errors[] = "Academic session is required.";
        }
        
        if (empty($errors)) {
            foreach ($selected_student_ids as $student_id) {
                $student_id = intval($student_id);
                
                // Check if student belongs to class
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id = :id AND class_id = :class_id");
                $stmt->execute(['id' => $student_id, 'class_id' => $class_id]);
                if ((int)$stmt->fetchColumn() === 0) {
                    continue; // Skip invalid student
                }
                
                foreach ($subjects as $subject) {
                    $subject_id = $subject['id'];
                    $first_ca_key = "first_ca_{$student_id}_{$subject_id}";
                    $second_ca_key = "second_ca_{$student_id}_{$subject_id}";
                    $exam_key = "exam_{$student_id}_{$subject_id}";
                    
                    $first_ca = isset($_POST[$first_ca_key]) ? floatval($_POST[$first_ca_key]) : 0;
                    $second_ca = isset($_POST[$second_ca_key]) ? floatval($_POST[$second_ca_key]) : 0;
                    $exam = isset($_POST[$exam_key]) ? floatval($_POST[$exam_key]) : 0;
                    
                    // Clamp scores 0-100
                    $first_ca = max(0, min(100, $first_ca));
                    $second_ca = max(0, min(100, $second_ca));
                    $exam = max(0, min(100, $exam));
                    
                    $total_ca = $first_ca + $second_ca;
                    
                    // Check if result already exists
                    $stmt = $pdo->prepare("SELECT id FROM results WHERE student_id = :student_id AND subject_id = :subject_id AND term = :term");
                    $stmt->execute([
                        'student_id' => $student_id,
                        'subject_id' => $subject_id,
                        'term' => $term
                    ]);
                    $existing_result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing_result) {
                        // Update existing
                        $hasUpdatedAt = (bool) $pdo->query("SHOW COLUMNS FROM `results` LIKE 'updated_at'")->fetchColumn();
                        if ($hasUpdatedAt) {
                            $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam, total_ca = :total_ca, academic_session = :academic_session, updated_at = NOW() WHERE id = :id");
                        } else {
                            $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam, total_ca = :total_ca, academic_session = :academic_session WHERE id = :id");
                        }
                        $stmt->execute([
                            'first_ca' => $first_ca,
                            'second_ca' => $second_ca,
                            'exam' => $exam,
                            'total_ca' => $total_ca,
                            'academic_session' => $academic_session,
                            'id' => $existing_result['id']
                        ]);
                    } else {
                        // Insert new
                        $stmt = $pdo->prepare("
                            INSERT INTO results 
                              (student_id, subject_id, term, academic_session, total_ca, first_ca, second_ca, exam, created_at)
                            VALUES 
                              (:student_id, :subject_id, :term, :academic_session, :total_ca, :first_ca, :second_ca, :exam, NOW())
                        ");
                        $stmt->execute([
                            'student_id' => $student_id,
                            'subject_id' => $subject_id,
                            'term' => $term,
                            'academic_session' => $academic_session,
                            'total_ca' => $total_ca,
                            'first_ca' => $first_ca,
                            'second_ca' => $second_ca,
                            'exam' => $exam
                        ]);
                    }
                }
            }
            $success = "Results saved successfully for selected students!";
        }
    }
    
    if ($action === 'save_single_result') {
        $student_id = intval($_POST['student_id'] ?? 0);
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $first_ca = floatval($_POST['first_ca'] ?? 0);
        $second_ca = floatval($_POST['second_ca'] ?? 0);
        $exam = floatval($_POST['exam'] ?? 0);
        $academic_session = trim($_POST['academic_session'] ?? '');

        // Basic validation
        if ($student_id <= 0 || $subject_id <= 0) $errors[] = "Student and subject are required.";
        if (empty($academic_session)) $errors[] = "Academic session is required.";
        
        // Clamp scores
        $first_ca = max(0, min(100, $first_ca));
        $second_ca = max(0, min(100, $second_ca));
        $exam = max(0, min(100, $exam));
        
        if (empty($errors)) {
            $total_ca = $first_ca + $second_ca;
            
            // Check if result exists
            $stmt = $pdo->prepare("SELECT id FROM results WHERE student_id = :student_id AND subject_id = :subject_id AND term = :term");
            $stmt->execute([
                'student_id' => $student_id,
                'subject_id' => $subject_id,
                'term' => $term
            ]);
            $existing_result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_result) {
                // Update
                $hasUpdatedAt = (bool) $pdo->query("SHOW COLUMNS FROM `results` LIKE 'updated_at'")->fetchColumn();
                if ($hasUpdatedAt) {
                    $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam, total_ca = :total_ca, academic_session = :academic_session, updated_at = NOW() WHERE id = :id");
                } else {
                    $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam, total_ca = :total_ca, academic_session = :academic_session WHERE id = :id");
                }
                $stmt->execute([
                    'first_ca' => $first_ca,
                    'second_ca' => $second_ca,
                    'exam' => $exam,
                    'total_ca' => $total_ca,
                    'academic_session' => $academic_session,
                    'id' => $existing_result['id']
                ]);
                $success = "Result updated successfully.";
            } else {
                // Insert
                $stmt = $pdo->prepare("
                    INSERT INTO results 
                      (student_id, subject_id, term, academic_session, total_ca, first_ca, second_ca, exam, created_at)
                    VALUES 
                      (:student_id, :subject_id, :term, :academic_session, :total_ca, :first_ca, :second_ca, :exam, NOW())
                ");
                $stmt->execute([
                    'student_id' => $student_id,
                    'subject_id' => $subject_id,
                    'term' => $term,
                    'academic_session' => $academic_session,
                    'total_ca' => $total_ca,
                    'first_ca' => $first_ca,
                    'second_ca' => $second_ca,
                    'exam' => $exam
                ]);
                $success = "Result added successfully.";
            }
        }
    }
    
    // NEW: Save multiple subjects for a single student
    if ($action === 'save_multiple_subjects') {
        $student_id = intval($_POST['student_id_multi'] ?? 0);
        $academic_session = trim($_POST['academic_session_multi'] ?? '');
        
        if ($student_id <= 0) {
            $errors[] = "Please select a student.";
        }
        
        if (empty($academic_session)) {
            $errors[] = "Academic session is required.";
        }
        
        // Check if student belongs to class
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id = :id AND class_id = :class_id");
        $stmt->execute(['id' => $student_id, 'class_id' => $class_id]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors[] = "Invalid student selected.";
        }
        
        if (empty($errors)) {
            $saved_count = 0;
            $updated_count = 0;
            
            foreach ($subjects as $subject) {
                $subject_id = $subject['id'];
                $first_ca_key = "first_ca_multi_{$subject_id}";
                $second_ca_key = "second_ca_multi_{$subject_id}";
                $exam_key = "exam_multi_{$subject_id}";
                
                $first_ca = isset($_POST[$first_ca_key]) ? floatval($_POST[$first_ca_key]) : 0;
                $second_ca = isset($_POST[$second_ca_key]) ? floatval($_POST[$second_ca_key]) : 0;
                $exam = isset($_POST[$exam_key]) ? floatval($_POST[$exam_key]) : 0;
                
                // Skip if all scores are 0 (not filled)
                if ($first_ca == 0 && $second_ca == 0 && $exam == 0) {
                    continue;
                }
                
                // Clamp scores 0-100
                $first_ca = max(0, min(100, $first_ca));
                $second_ca = max(0, min(100, $second_ca));
                $exam = max(0, min(100, $exam));
                
                $total_ca = $first_ca + $second_ca;
                
                // Check if result already exists
                $stmt = $pdo->prepare("SELECT id FROM results WHERE student_id = :student_id AND subject_id = :subject_id AND term = :term");
                $stmt->execute([
                    'student_id' => $student_id,
                    'subject_id' => $subject_id,
                    'term' => $term
                ]);
                $existing_result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing_result) {
                    // Update existing
                    $hasUpdatedAt = (bool) $pdo->query("SHOW COLUMNS FROM `results` LIKE 'updated_at'")->fetchColumn();
                    if ($hasUpdatedAt) {
                        $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam, total_ca = :total_ca, academic_session = :academic_session, updated_at = NOW() WHERE id = :id");
                    } else {
                        $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam, total_ca = :total_ca, academic_session = :academic_session WHERE id = :id");
                    }
                    $stmt->execute([
                        'first_ca' => $first_ca,
                        'second_ca' => $second_ca,
                        'exam' => $exam,
                        'total_ca' => $total_ca,
                        'academic_session' => $academic_session,
                        'id' => $existing_result['id']
                    ]);
                    $updated_count++;
                } else {
                    // Insert new
                    $stmt = $pdo->prepare("
                        INSERT INTO results 
                          (student_id, subject_id, term, academic_session, total_ca, first_ca, second_ca, exam, created_at)
                        VALUES 
                          (:student_id, :subject_id, :term, :academic_session, :total_ca, :first_ca, :second_ca, :exam, NOW())
                    ");
                    $stmt->execute([
                        'student_id' => $student_id,
                        'subject_id' => $subject_id,
                        'term' => $term,
                        'academic_session' => $academic_session,
                        'total_ca' => $total_ca,
                        'first_ca' => $first_ca,
                        'second_ca' => $second_ca,
                        'exam' => $exam
                    ]);
                    $saved_count++;
                }
            }
            
            if ($saved_count > 0 || $updated_count > 0) {
                $success = "Results saved successfully! ";
                if ($saved_count > 0) $success .= "Added {$saved_count} new subject(s). ";
                if ($updated_count > 0) $success .= "Updated {$updated_count} subject(s).";
            } else {
                $errors[] = "No scores were entered. Please enter at least one score.";
            }
        }
    }

    if ($action === 'delete_result') {
        $result_id = intval($_POST['result_id'] ?? 0);
        if ($result_id > 0) {
            $stmt = $pdo->prepare("SELECT r.id FROM results r JOIN students s ON r.student_id = s.id WHERE r.id = :id AND s.class_id = :class_id");
            $stmt->execute(['id' => $result_id, 'class_id' => $class_id]);
            if ($stmt->fetchColumn()) {
                $pdo->prepare("DELETE FROM results WHERE id = :id")->execute(['id' => $result_id]);
                $success = "Result deleted successfully.";
            } else {
                $errors[] = "Result not found or does not belong to this class.";
            }
        }
    }

    if ($action === 'delete_student_results') {
        $student_id = intval($_POST['student_id'] ?? 0);
        $delete_term = $_POST['term'] ?? '';
        if ($student_id > 0 && !empty($delete_term)) {
            // Check if student belongs to class
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id = :id AND class_id = :class_id");
            $stmt->execute(['id' => $student_id, 'class_id' => $class_id]);
            if ((int)$stmt->fetchColumn() > 0) {
                $stmt = $pdo->prepare("DELETE FROM results WHERE student_id = :student_id AND term = :term");
                $stmt->execute(['student_id' => $student_id, 'term' => $delete_term]);
                $success = "All results for this student in the term have been deleted successfully.";
            } else {
                $errors[] = "Student not found in this class.";
            }
        } else {
            $errors[] = "Invalid student or term specified.";
        }
    }

    if ($action === 'resolve_complaint') {
        $complaint_id = intval($_POST['complaint_id'] ?? 0);
        $response = trim($_POST['response'] ?? '');
        if ($complaint_id > 0) {
            $stmt = $pdo->prepare("UPDATE results_complaints SET status = 'resolved', teacher_response = :response, resolved_at = NOW() WHERE id = :id");
            $stmt->execute(['response' => $response, 'id' => $complaint_id]);
            $success = "Complaint marked resolved.";
        }
    }
}

// Fetch results for the class and term
$stmt = $pdo->prepare("
    SELECT r.*, s.full_name AS student_name, s.admission_no, sub.subject_name 
    FROM results r
    JOIN students s ON r.student_id = s.id
    JOIN subjects sub ON r.subject_id = sub.id
    WHERE s.class_id = :class_id AND r.term = :term
    ORDER BY s.full_name, sub.subject_name
");
$stmt->execute(['class_id' => $class_id, 'term' => $term]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get students with compiled results (have results for all assigned subjects)
$subject_count = count($subjects);
$compiled_students = [];
$results_by_student = [];

foreach ($results as $result) {
    $student_id = $result['student_id'];
    if (!isset($results_by_student[$student_id])) {
        $results_by_student[$student_id] = [
            'student' => $result,
            'subjects_count' => 0,
            'results' => []
        ];
    }
    $results_by_student[$student_id]['subjects_count']++;
    $results_by_student[$student_id]['results'][] = $result;
}

foreach ($results_by_student as $student_id => $data) {
    if ($data['subjects_count'] == $subject_count) {
        $compiled_students[] = $data;
    }
}

// Fetch complaints for this class/term
$complaints = [];
try {
    $tbl = $pdo->query("SHOW TABLES LIKE 'results_complaints'")->fetchColumn();
    if ($tbl) {
        $stmt = $pdo->prepare("
            SELECT rc.*, r.student_id, r.subject_id, s.full_name, sub.subject_name
            FROM results_complaints rc
            JOIN results r ON rc.result_id = r.id
            JOIN students s ON r.student_id = s.id
            JOIN subjects sub ON r.subject_id = sub.id
            WHERE s.class_id = :class_id AND r.term = :term
            ORDER BY rc.status ASC, rc.created_at DESC
        ");
        $stmt->execute(['class_id' => $class_id, 'term' => $term]);
        $complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $ex) {
    $complaints = [];
}

// Function to calculate grade and remark
function calculateGrade($grand_total) {
    if ($grand_total >= 90) return ['grade' => 'A', 'remark' => 'Excellent'];
    if ($grand_total >= 80) return ['grade' => 'B', 'remark' => 'Very Good'];
    if ($grand_total >= 70) return ['grade' => 'C', 'remark' => 'Good'];
    if ($grand_total >= 60) return ['grade' => 'D', 'remark' => 'Fair'];
    if ($grand_total >= 50) return ['grade' => 'E', 'remark' => 'Pass'];
    return ['grade' => 'F', 'remark' => 'Fail'];
}

// Get current academic year for default
$current_year = date('Y');
$next_year = $current_year + 1;
$default_academic_session = "{$current_year}/{$next_year}";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Results | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/tresults.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Additional styles for results page */
        .results-container {
            padding: 20px;
        }
        
        .section-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .tabs {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 20px;
        }
        
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }
        
        .tab.active {
            border-bottom-color: #4CAF50;
            color: #4CAF50;
            font-weight: bold;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .results-table th, .results-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .results-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .results-table tr:hover {
            background-color: #f9f9f9;
        }
        
        .score-input {
            width: 70px;
            padding: 5px;
            text-align: center;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .batch-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .student-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            background: #f9f9f9;
        }
        
        .subject-scores {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .subject-scores table {
            min-width: 800px;
        }
        
        .btn-batch {
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        
        .btn-batch:hover {
            background: #45a049;
        }
        
        .btn-delete {
            background: #f44336;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .btn-pdf {
            background: #2196F3;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .btn-edit {
            background: #FF9800;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .multi-subject-form {
            margin-top: 20px;
        }
        
        .multi-subject-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .subject-score-row {
            display: grid;
            grid-template-columns: 1fr repeat(3, 80px);
            gap: 10px;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .subject-score-row.header {
            font-weight: bold;
            background-color: #f5f5f5;
            border-bottom: 2px solid #ddd;
        }
        
        @media (max-width: 768px) {
            .results-table {
                font-size: 14px;
            }
            
            .results-table th, .results-table td {
                padding: 8px;
            }
            
            .tabs {
                overflow-x: auto;
                white-space: nowrap;
            }
            
            .batch-form-grid {
                grid-template-columns: 1fr;
            }
            
            .score-input {
                width: 60px;
                font-size: 14px;
            }
            
            .subject-score-row {
                grid-template-columns: 1fr repeat(3, 70px);
                gap: 5px;
                font-size: 14px;
            }
        }
        
        @media (max-width: 480px) {
            .results-table {
                display: block;
                overflow-x: auto;
            }
            
            .section-card {
                padding: 15px;
            }
            
            .tab {
                padding: 8px 15px;
                font-size: 14px;
            }
            
            .subject-score-row {
                grid-template-columns: 1fr repeat(3, 60px);
                gap: 5px;
                font-size: 12px;
            }
            
            .score-input {
                width: 55px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    
<div class="dashboard-container">
    
        <main class="main-content">
                <a href="index.php" style="color: #3498db; text-decoration: none; margin-bottom: 20px;">Back to Dashboard</a>
<br>
            <div class="results-container">
                <div class="content-header">
                    <h1>Manage Results</h1>
                    <p>Class: <?php echo htmlspecialchars($class['class_name']); ?> | Term: <?php echo htmlspecialchars($term); ?></p>
                </div>
                
                <?php if ($errors): ?>
                    <div class="alert alert-error">
                        <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Filter Section -->
                <div class="section-card">
                    <form method="GET" class="filter-form">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                            <div>
                                <label for="term_filter">Term</label>
                                <select id="term_filter" name="term" class="form-control">
                                    <option value="1st Term" <?php echo $term === '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                                    <option value="2nd Term" <?php echo $term === '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                                    <option value="3rd Term" <?php echo $term === '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="class_filter">Class</label>
                                <select id="class_filter" name="filter_class_id" class="form-control">
                                    <?php foreach ($teacher_classes as $tc): ?>
                                        <option value="<?php echo intval($tc['id']); ?>" <?php echo $tc['id'] == $filter_class_id ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tc['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="admission_no">Admission No</label>
                                <input id="admission_no" name="admission_no" class="form-control" 
                                       value="<?php echo htmlspecialchars($_GET['admission_no'] ?? ''); ?>" 
                                       placeholder="Filter by admission no">
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="btn-gold">Apply Filter</button>
                            <a href="results.php?id=<?php echo intval($class_id); ?>" class="btn-small">Reset</a>
                        </div>
                    </form>
                </div>
                
                <!-- Tabs for different views -->
                <div class="tabs">
                    <div class="tab active" onclick="switchTab('batch')"><i class="fas fa-layer-group"></i> Batch Entry</div>
                    <div class="tab" onclick="switchTab('single')"><i class="fas fa-plus"></i> Single Entry</div>
                    <div class="tab" onclick="switchTab('multi')"><i class="fas fa-list"></i> Multiple Subjects</div>
                    <div class="tab" onclick="switchTab('view')"><i class="fas fa-eye"></i> View Results</div>
                    <div class="tab" onclick="switchTab('complaints')"><i class="fas fa-exclamation-triangle"></i> Complaints</div>
                </div>
                
                <!-- Batch Entry Tab -->
                <div id="batch-tab" class="tab-content active">
                    <div class="section-card">
                        <h3>Batch Entry - Multiple Students & Subjects</h3>
                        <form method="POST" id="batch-form">
                            <input type="hidden" name="action" value="save_batch_results">
                            
                            <!-- Academic Session Selection -->
                            <div style="margin-bottom: 20px;">
                                <label for="academic_session_batch">Academic Session *</label>
                                <select id="academic_session_batch" name="academic_session" class="form-control" required style="max-width: 300px;">
                                    <option value="">Select Academic Session</option>
                                    <?php foreach ($academic_sessions as $session): ?>
                                        <option value="<?php echo htmlspecialchars($session); ?>" <?php echo $session == $default_academic_session ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($session); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="<?php echo $default_academic_session; ?>"><?php echo $default_academic_session; ?> (Current)</option>
                                </select>
                            </div>
                            
                            <!-- Student Selection -->
                            <h4>Select Students</h4>
                            <div class="batch-form-grid">
                                <?php foreach ($students as $student): ?>
                                    <div class="student-checkbox">
                                        <input type="checkbox" name="student_ids[]" value="<?php echo intval($student['id']); ?>" 
                                               id="student_<?php echo $student['id']; ?>" class="student-check">
                                        <label for="student_<?php echo $student['id']; ?>">
                                            <?php echo htmlspecialchars($student['full_name']); ?>
                                            <small>(<?php echo htmlspecialchars($student['admission_no']); ?>)</small>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div style="margin: 15px 0; display: flex; gap: 10px;">
                                <button type="button" class="btn-small" onclick="selectAllStudents()">Select All</button>
                                <button type="button" class="btn-small" onclick="deselectAllStudents()">Deselect All</button>
                            </div>
                            
                            <!-- Subject Scores Grid -->
                            <?php if (!empty($subjects)): ?>
                                <div class="subject-scores">
                                    <h4>Enter Scores</h4>
                                    <div style="overflow-x: auto;">
                                        <table class="results-table">
                                            <thead>
                                                <tr>
                                                    <th>Subject</th>
                                                    <?php foreach ($students as $student): ?>
                                                        <th colspan="3" style="text-align: center;">
                                                            <?php echo htmlspecialchars($student['full_name']); ?>
                                                        </th>
                                                    <?php endforeach; ?>
                                                </tr>
                                                <tr>
                                                    <th></th>
                                                    <?php foreach ($students as $student): ?>
                                                        <th>1st CA</th>
                                                        <th>2nd CA</th>
                                                        <th>Exam</th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($subjects as $subject): ?>
                                                    <tr>
                                                        <td style="font-weight: bold;"><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                                        <?php foreach ($students as $student): ?>
                                                            <?php 
                                                                // Check for existing score
                                                                $existing_score = null;
                                                                foreach ($results as $result) {
                                                                    if ($result['student_id'] == $student['id'] && $result['subject_id'] == $subject['id']) {
                                                                        $existing_score = $result;
                                                                        break;
                                                                    }
                                                                }
                                                            ?>
                                                            <td>
                                                                <input type="number" 
                                                                       name="first_ca_<?php echo $student['id']; ?>_<?php echo $subject['id']; ?>"
                                                                       class="score-input"
                                                                       min="0" max="100" step="0.1"
                                                                       value="<?php echo $existing_score ? htmlspecialchars($existing_score['first_ca']) : '0'; ?>">
                                                            </td>
                                                            <td>
                                                                <input type="number" 
                                                                       name="second_ca_<?php echo $student['id']; ?>_<?php echo $subject['id']; ?>"
                                                                       class="score-input"
                                                                       min="0" max="100" step="0.1"
                                                                       value="<?php echo $existing_score ? htmlspecialchars($existing_score['second_ca']) : '0'; ?>">
                                                            </td>
                                                            <td>
                                                                <input type="number" 
                                                                       name="exam_<?php echo $student['id']; ?>_<?php echo $subject['id']; ?>"
                                                                       class="score-input"
                                                                       min="0" max="100" step="0.1"
                                                                       value="<?php echo $existing_score ? htmlspecialchars($existing_score['exam']) : '0'; ?>">
                                                            </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                
                                <div style="margin-top: 20px; text-align: center;">
                                    <button type="submit" class="btn-batch">Save All Results</button>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    No subjects assigned to you for this class.
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <!-- Single Entry Tab -->
                <div id="single-tab" class="tab-content">
                    <div class="section-card">
                        <h3>Single Entry - Individual Result</h3>
                        <form method="POST" class="lesson-form">
                            <input type="hidden" name="action" value="save_single_result">
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                <div>
                                    <label for="student_id_single">Student *</label>
                                    <select id="student_id_single" name="student_id" class="form-control" required>
                                        <option value="">Select student</option>
                                        <?php foreach ($students as $s): ?>
                                            <option value="<?php echo intval($s['id']); ?>">
                                                <?php echo htmlspecialchars($s['full_name'] . ' (' . $s['admission_no'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="subject_id_single">Subject *</label>
                                    <select id="subject_id_single" name="subject_id" class="form-control" required>
                                        <option value="">Select subject</option>
                                        <?php foreach ($subjects as $sub): ?>
                                            <option value="<?php echo intval($sub['id']); ?>">
                                                <?php echo htmlspecialchars($sub['subject_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="academic_session_single">Academic Session *</label>
                                    <input type="text" id="academic_session_single" name="academic_session" 
                                           class="form-control" value="<?php echo $default_academic_session; ?>" required>
                                </div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-top: 20px;">
                                <div>
                                    <label for="first_ca_single">First C.A.</label>
                                    <input type="number" id="first_ca_single" name="first_ca" class="form-control" 
                                           min="0" max="100" step="0.1" value="0" required>
                                </div>
                                
                                <div>
                                    <label for="second_ca_single">Second C.A.</label>
                                    <input type="number" id="second_ca_single" name="second_ca" class="form-control" 
                                           min="0" max="100" step="0.1" value="0" required>
                                </div>
                                
                                <div>
                                    <label for="exam_single">Exam</label>
                                    <input type="number" id="exam_single" name="exam" class="form-control" 
                                           min="0" max="100" step="0.1" value="0" required>
                                </div>
                            </div>
                            
                            <div style="margin-top: 20px;">
                                <button type="submit" class="btn-gold">Save Result</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Multiple Subjects Tab (NEW) -->
                <div id="multi-tab" class="tab-content">
                    <div class="section-card">
                        <h3>Multiple Subjects - Single Student</h3>
                        <p>Enter scores for all subjects for one student at once.</p>
                        
                        <form method="POST" id="multi-subject-form" class="multi-subject-form">
                            <input type="hidden" name="action" value="save_multiple_subjects">
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
                                <div>
                                    <label for="student_id_multi">Select Student *</label>
                                    <select id="student_id_multi" name="student_id_multi" class="form-control" required onchange="loadStudentScores()">
                                        <option value="">-- Select Student --</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo intval($student['id']); ?>">
                                                <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['admission_no'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="academic_session_multi">Academic Session *</label>
                                    <select id="academic_session_multi" name="academic_session_multi" class="form-control" required style="max-width: 300px;">
                                        <option value="">Select Academic Session</option>
                                        <?php foreach ($academic_sessions as $session): ?>
                                            <option value="<?php echo htmlspecialchars($session); ?>" <?php echo $session == $default_academic_session ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($session); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="<?php echo $default_academic_session; ?>"><?php echo $default_academic_session; ?> (Current)</option>
                                    </select>
                                </div>
                            </div>
                            
                            <?php if (!empty($subjects)): ?>
                                <div class="subject-scores">
                                    <h4>Enter Scores for All Subjects</h4>
                                    
                                    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                                        <div class="subject-score-row header">
                                            <div><strong>Subject</strong></div>
                                            <div><strong>1st CA</strong></div>
                                            <div><strong>2nd CA</strong></div>
                                            <div><strong>Exam</strong></div>
                                        </div>
                                        
                                        <?php foreach ($subjects as $subject): ?>
                                            <div class="subject-score-row">
                                                <div>
                                                    <label for="subject_<?php echo $subject['id']; ?>">
                                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                                    </label>
                                                </div>
                                                <div>
                                                    <input type="number" 
                                                           id="first_ca_multi_<?php echo $subject['id']; ?>"
                                                           name="first_ca_multi_<?php echo $subject['id']; ?>"
                                                           class="score-input"
                                                           min="0" max="100" step="0.1"
                                                           value="0"
                                                           placeholder="0">
                                                </div>
                                                <div>
                                                    <input type="number" 
                                                           id="second_ca_multi_<?php echo $subject['id']; ?>"
                                                           name="second_ca_multi_<?php echo $subject['id']; ?>"
                                                           class="score-input"
                                                           min="0" max="100" step="0.1"
                                                           value="0"
                                                           placeholder="0">
                                                </div>
                                                <div>
                                                    <input type="number" 
                                                           id="exam_multi_<?php echo $subject['id']; ?>"
                                                           name="exam_multi_<?php echo $subject['id']; ?>"
                                                           class="score-input"
                                                           min="0" max="100" step="0.1"
                                                           value="0"
                                                           placeholder="0">
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div style="margin-top: 20px; text-align: center;">
                                        <button type="submit" class="btn-batch">Save All Subjects for This Student</button>
                                        <button type="button" class="btn-small" onclick="clearAllScores()" style="margin-left: 10px;">Clear All</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    No subjects assigned to you for this class.
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <!-- View Results Tab -->
                <div id="view-tab" class="tab-content">
                    <div class="section-card">
                        <h3>Compiled Results Summary</h3>
                        <p>Students whose results have been fully compiled for all subjects in this term.</p>
                        <?php if (empty($compiled_students)): ?>
                            <p class="small-muted">No students with fully compiled results found for this term.</p>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="results-table">
                                    <thead>
                                        <tr>
                                            <th>S/N</th>
                                            <th>Adm No</th>
                                            <th>Name</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $sn = 1;
                                        foreach ($compiled_students as $data):
                                            $student = $data['student'];
                                        ?>
                                        <tr>
                                            <td><?php echo $sn++; ?></td>
                                            <td><?php echo htmlspecialchars($student['admission_no']); ?></td>
                                            <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                            <td><span style="color: green; font-weight: bold;">Compiled</span></td>
                                            <td>
                                                <div style="display: flex; gap: 5px;">
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete all results for this student? This action cannot be undone.')">
                                                        <input type="hidden" name="action" value="delete_student_results">
                                                        <input type="hidden" name="student_id" value="<?php echo intval($student['student_id']); ?>">
                                                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                                                        <button type="submit" class="btn-delete">Delete</button>
                                                    </form>

                                                    <form method="POST" action="generate-result-pdf.php" style="display:inline;">
                                                        <input type="hidden" name="student_id" value="<?php echo intval($student['student_id']); ?>">
                                                        <input type="hidden" name="class_id" value="<?php echo intval($class_id); ?>">
                                                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                                                        <button type="submit" class="btn-pdf">View Details</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Complaints Tab -->
                <div id="complaints-tab" class="tab-content">
                    <div class="section-card">
                        <h3>Student Complaints</h3>
                        <?php if (empty($complaints)): ?>
                            <p class="small-muted">No complaints.</p>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="results-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Student</th>
                                            <th>Subject</th>
                                            <th>Complaint</th>
                                            <th>Status</th>
                                            <th>Submitted</th>
                                            <th>Response</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($complaints as $i => $c): ?>
                                            <tr>
                                                <td><?php echo $i + 1; ?></td>
                                                <td><?php echo htmlspecialchars($c['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($c['subject_name']); ?></td>
                                                <td><?php echo htmlspecialchars($c['complaint_text']); ?></td>
                                                <td>
                                                    <span class="status-<?php echo $c['status']; ?>">
                                                        <?php echo htmlspecialchars(ucfirst($c['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                                                <td><?php echo htmlspecialchars($c['teacher_response'] ?? 'Not responded'); ?></td>
                                                <td>
                                                    <?php if ($c['status'] !== 'resolved'): ?>
                                                        <form method="POST" style="display:flex;gap:5px;align-items:center;">
                                                            <input type="hidden" name="action" value="resolve_complaint">
                                                            <input type="hidden" name="complaint_id" value="<?php echo intval($c['id']); ?>">
                                                            <input type="text" name="response" placeholder="Enter response" required 
                                                                   style="padding:5px;border:1px solid #ddd;border-radius:3px;flex:1;">
                                                            <button type="submit" class="btn-small">Resolve</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="small-muted">Resolved</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab').forEach(tabBtn => {
                tabBtn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Activate selected tab button
            event.target.classList.add('active');
        }
        
        function selectAllStudents() {
            document.querySelectorAll('.student-check').forEach(checkbox => {
                checkbox.checked = true;
            });
        }
        
        function deselectAllStudents() {
            document.querySelectorAll('.student-check').forEach(checkbox => {
                checkbox.checked = false;
            });
        }
        
        // Auto-fill academic session for single entry when batch session changes
        document.getElementById('academic_session_batch').addEventListener('change', function() {
            document.getElementById('academic_session_single').value = this.value;
            document.getElementById('academic_session_multi').value = this.value;
        });
        
        // NEW: Function to clear all scores in multi-subject form
        function clearAllScores() {
            if (confirm('Clear all scores for all subjects?')) {
                document.querySelectorAll('#multi-subject-form .score-input').forEach(input => {
                    input.value = '0';
                });
            }
        }
        
        // NEW: Function to load existing scores when student is selected in multi-subject tab
        function loadStudentScores() {
            const studentId = document.getElementById('student_id_multi').value;
            const term = document.getElementById('term_filter').value;
            const classId = <?php echo json_encode($class_id); ?>;
            
            if (!studentId) return;
            
            // Clear all scores first
            document.querySelectorAll('#multi-subject-form .score-input').forEach(input => {
                input.value = '0';
            });
            
            // Fetch existing results for this student
            fetch(`ajax_get_student_results.php?student_id=${studentId}&term=${encodeURIComponent(term)}&class_id=${classId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.results) {
                        data.results.forEach(result => {
                            const firstCaInput = document.getElementById(`first_ca_multi_${result.subject_id}`);
                            const secondCaInput = document.getElementById(`second_ca_multi_${result.subject_id}`);
                            const examInput = document.getElementById(`exam_multi_${result.subject_id}`);
                            
                            if (firstCaInput) firstCaInput.value = result.first_ca || '0';
                            if (secondCaInput) secondCaInput.value = result.second_ca || '0';
                            if (examInput) examInput.value = result.exam || '0';
                        });
                        
                        // Set academic session if available
                        if (data.academic_session && document.getElementById('academic_session_multi')) {
                            document.getElementById('academic_session_multi').value = data.academic_session;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading student scores:', error);
                });
        }
        
        // Initialize tab switching
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-switch to single entry tab if there's an error in that tab
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('tab') && urlParams.get('tab') === 'multi') {
                switchTab('multi');
            }
        });
    </script>
</body>
</html>
