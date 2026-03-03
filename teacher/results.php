<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow class teachers to access this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

// Get current school context
$current_school_id = require_school_auth();
$teacher_id = intval($_SESSION['user_id']);
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';

$class_id = $_GET['filter_class_id'] ?? $_GET['id'] ?? $_GET['class_id'] ?? $_REQUEST['class'] ?? $_POST['class_id'] ?? null;

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
$admission_no_filter = trim($_GET['admission_no'] ?? '');
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

// Fetch teacher's classes - school-filtered
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.class_name
    FROM classes c
    JOIN subject_assignments sa ON c.id = sa.class_id
    WHERE sa.teacher_id = :teacher_id AND c.school_id = :school_id
    ORDER BY c.class_name
");
$stmt->execute(['teacher_id' => $teacher_id, 'school_id' => $current_school_id]);
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
$class_id = $filter_class_id;

// Ensure teacher is assigned to this class and class belongs to school
$stmt = $pdo->prepare("SELECT COUNT(*) FROM subject_assignments sa JOIN classes c ON sa.class_id = c.id WHERE sa.class_id = :class_id AND sa.teacher_id = :teacher_id AND c.school_id = :school_id");
$stmt->execute(['class_id' => $class_id, 'teacher_id' => $teacher_id, 'school_id' => $current_school_id]);
if ((int)$stmt->fetchColumn() === 0) {
    die("Access denied. You are not assigned to this class or the class does not belong to your school.");
}

// Fetch class information - school-filtered
$stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :id AND school_id = :school_id");
$stmt->execute(['id' => $class_id, 'school_id' => $current_school_id]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$class) {
    header("Location: index.php?error=Class not found.");
    exit;
}

// Fetch students in the class - school-filtered
$students_sql = "SELECT * FROM students WHERE class_id = :class_id AND school_id = :school_id";
$students_params = ['class_id' => $class_id, 'school_id' => $current_school_id];
if ($admission_no_filter !== '') {
    $students_sql .= " AND admission_no LIKE :admission_no";
    $students_params['admission_no'] = '%' . $admission_no_filter . '%';
}
$students_sql .= " ORDER BY full_name ASC";
$stmt = $pdo->prepare($students_sql);
$stmt->execute($students_params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch subjects assigned to this teacher for this class - school-filtered
$stmt = $pdo->prepare("
    SELECT s.*
    FROM subjects s
    JOIN subject_assignments sa ON s.id = sa.subject_id
    WHERE sa.class_id = :class_id AND sa.teacher_id = :teacher_id AND s.school_id = :school_id
    ORDER BY s.subject_name ASC
");
$stmt->execute(['class_id' => $class_id, 'teacher_id' => $teacher_id, 'school_id' => $current_school_id]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch academic sessions - school-filtered (from results that belong to school)
$stmt = $pdo->prepare("SELECT DISTINCT r.academic_session FROM results r JOIN students s ON r.student_id = s.id WHERE s.school_id = ? ORDER BY r.academic_session DESC");
$stmt->execute([$current_school_id]);
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
                
                // Check if student belongs to class and school
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id = :id AND class_id = :class_id AND school_id = :school_id");
                $stmt->execute(['id' => $student_id, 'class_id' => $class_id, 'school_id' => $current_school_id]);
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
                    $stmt = $pdo->prepare("SELECT id FROM results WHERE student_id = :student_id AND subject_id = :subject_id AND term = :term AND school_id = :school_id");
                    $stmt->execute([
                        'student_id' => $student_id,
                        'subject_id' => $subject_id,
                        'term' => $term,
                        'school_id' => $current_school_id
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
                              (student_id, subject_id, term, academic_session, total_ca, first_ca, second_ca, exam, school_id, created_at)
                            VALUES
                              (:student_id, :subject_id, :term, :academic_session, :total_ca, :first_ca, :second_ca, :exam, :school_id, NOW())
                        ");
                        $stmt->execute([
                            'student_id' => $student_id,
                            'subject_id' => $subject_id,
                            'term' => $term,
                            'academic_session' => $academic_session,
                            'total_ca' => $total_ca,
                            'first_ca' => $first_ca,
                            'second_ca' => $second_ca,
                            'exam' => $exam,
                            'school_id' => $current_school_id
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
            $stmt = $pdo->prepare("SELECT id FROM results WHERE student_id = :student_id AND subject_id = :subject_id AND term = :term AND school_id = :school_id");
            $stmt->execute([
                'student_id' => $student_id,
                'subject_id' => $subject_id,
                'term' => $term,
                'school_id' => $current_school_id
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
                      (student_id, subject_id, term, academic_session, total_ca, first_ca, second_ca, exam, school_id, created_at)
                    VALUES
                      (:student_id, :subject_id, :term, :academic_session, :total_ca, :first_ca, :second_ca, :exam, :school_id, NOW())
                ");
                $stmt->execute([
                    'student_id' => $student_id,
                    'subject_id' => $subject_id,
                    'term' => $term,
                    'academic_session' => $academic_session,
                    'total_ca' => $total_ca,
                    'first_ca' => $first_ca,
                    'second_ca' => $second_ca,
                    'exam' => $exam,
                    'school_id' => $current_school_id
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
        
        // Check if student belongs to class and school
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id = :id AND class_id = :class_id AND school_id = :school_id");
        $stmt->execute(['id' => $student_id, 'class_id' => $class_id, 'school_id' => $current_school_id]);
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
                $stmt = $pdo->prepare("SELECT id FROM results WHERE student_id = :student_id AND subject_id = :subject_id AND term = :term AND school_id = :school_id");
                $stmt->execute([
                    'student_id' => $student_id,
                    'subject_id' => $subject_id,
                    'term' => $term,
                    'school_id' => $current_school_id
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
                          (student_id, subject_id, term, academic_session, total_ca, first_ca, second_ca, exam, school_id, created_at)
                        VALUES
                          (:student_id, :subject_id, :term, :academic_session, :total_ca, :first_ca, :second_ca, :exam, :school_id, NOW())
                    ");
                    $stmt->execute([
                        'student_id' => $student_id,
                        'subject_id' => $subject_id,
                        'term' => $term,
                        'academic_session' => $academic_session,
                        'total_ca' => $total_ca,
                        'first_ca' => $first_ca,
                        'second_ca' => $second_ca,
                        'exam' => $exam,
                        'school_id' => $current_school_id
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
            $stmt = $pdo->prepare("SELECT r.id FROM results r JOIN students s ON r.student_id = s.id WHERE r.id = :id AND s.class_id = :class_id AND s.school_id = :school_id AND r.school_id = :school_id_result");
            $stmt->execute(['id' => $result_id, 'class_id' => $class_id, 'school_id' => $current_school_id, 'school_id_result' => $current_school_id]);
            if ($stmt->fetchColumn()) {
                $pdo->prepare("DELETE FROM results WHERE id = :id AND school_id = :school_id")->execute(['id' => $result_id, 'school_id' => $current_school_id]);
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
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id = :id AND class_id = :class_id AND school_id = :school_id");
            $stmt->execute(['id' => $student_id, 'class_id' => $class_id, 'school_id' => $current_school_id]);
            if ((int)$stmt->fetchColumn() > 0) {
                $stmt = $pdo->prepare("DELETE FROM results WHERE student_id = :student_id AND term = :term AND school_id = :school_id");
                $stmt->execute(['student_id' => $student_id, 'term' => $delete_term, 'school_id' => $current_school_id]);
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
            $stmt = $pdo->prepare("
                UPDATE results_complaints rc
                JOIN results r ON rc.result_id = r.id
                JOIN students s ON r.student_id = s.id
                SET rc.status = 'resolved', rc.teacher_response = :response, rc.resolved_at = NOW()
                WHERE rc.id = :id AND s.class_id = :class_id AND s.school_id = :school_id AND r.school_id = :school_id_result
            ");
            $stmt->execute([
                'response' => $response,
                'id' => $complaint_id,
                'class_id' => $class_id,
                'school_id' => $current_school_id,
                'school_id_result' => $current_school_id
            ]);
            if ($stmt->rowCount() > 0) {
                $success = "Complaint marked resolved.";
            } else {
                $errors[] = "Complaint not found for this class.";
            }
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
      AND s.school_id = :school_id AND r.school_id = :school_id_result AND sub.school_id = :school_id_subject
      " . ($admission_no_filter !== '' ? " AND s.admission_no LIKE :admission_no " : "") . "
    ORDER BY s.full_name, sub.subject_name
");
$results_params = [
    'class_id' => $class_id,
    'term' => $term,
    'school_id' => $current_school_id,
    'school_id_result' => $current_school_id,
    'school_id_subject' => $current_school_id
];
if ($admission_no_filter !== '') {
    $results_params['admission_no'] = '%' . $admission_no_filter . '%';
}
$stmt->execute($results_params);
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
              AND s.school_id = :school_id AND r.school_id = :school_id_result AND sub.school_id = :school_id_subject
              " . ($admission_no_filter !== '' ? " AND s.admission_no LIKE :admission_no " : "") . "
            ORDER BY rc.status ASC, rc.created_at DESC
        ");
        $complaint_params = [
            'class_id' => $class_id,
            'term' => $term,
            'school_id' => $current_school_id,
            'school_id_result' => $current_school_id,
            'school_id_subject' => $current_school_id
        ];
        if ($admission_no_filter !== '') {
            $complaint_params['admission_no'] = '%' . $admission_no_filter . '%';
        }
        $stmt->execute($complaint_params);
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
$compiled_count = count($compiled_students);
$student_total = count($students);
$pending_count = max(0, $student_total - $compiled_count);
$result_entry_count = count($results);
$complaint_total = count($complaints);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <meta name="pwa-sw" content="../sw.js">
    <title>Manage Results | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/css/offline-status.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-50: #eff6ff;
            --primary-100: #dbeafe;
            --primary-200: #bfdbfe;
            --primary-300: #93c5fd;
            --primary-400: #60a5fa;
            --primary-500: #3b82f6;
            --primary-600: #2563eb;
            --primary-700: #1d4ed8;
            --primary-800: #1e40af;
            --primary-900: #1e3a8a;

            --accent-50: #fdf4ff;
            --accent-100: #fae8ff;
            --accent-200: #f5d0fe;
            --accent-300: #f0abfc;
            --accent-400: #e879f9;
            --accent-500: #d946ef;
            --accent-600: #c026d3;
            --accent-700: #a21caf;
            --accent-800: #86198f;
            --accent-900: #701a75;

            --success-50: #f0fdf4;
            --success-100: #dcfce7;
            --success-500: #22c55e;
            --success-600: #16a34a;
            --success-700: #15803d;

            --error-50: #fef2f2;
            --error-100: #fee2e2;
            --error-500: #ef4444;
            --error-600: #dc2626;

            --warning-50: #fffbeb;
            --warning-100: #fef3c7;
            --warning-500: #f59e0b;
            --warning-600: #d97706;

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

            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 32px rgba(0, 0, 0, 0.12);
            --shadow-strong: 0 16px 48px rgba(0, 0, 0, 0.15);

            --gradient-primary: linear-gradient(135deg, var(--primary-500) 0%, var(--primary-700) 100%);
            --gradient-accent: linear-gradient(135deg, var(--accent-500) 0%, var(--accent-700) 100%);
            --gradient-bg: linear-gradient(135deg, var(--primary-50) 0%, var(--accent-50) 50%, var(--primary-100) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--gradient-bg);
            color: var(--gray-800);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* Modern Header */
        .modern-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-soft);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-container {
            width: 56px;
            height: 56px;
            background: var(--gradient-primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: var(--shadow-medium);
        }

        .brand-text h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.125rem;
        }

        .brand-text p {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 500;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 12px;
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .back-btn:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--gradient-accent);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .user-details p {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.125rem;
        }

        .user-details span {
            font-weight: 600;
            color: var(--gray-900);
        }

        .logout-btn {
            padding: 0.75rem 1.25rem;
            background: var(--error-500);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: var(--error-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Main Container */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Modern Cards */
        .modern-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            transition: all 0.3s ease;
            margin-bottom: 2rem;
        }

        .modern-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-strong);
        }

        .card-header-modern {
            padding: 2rem;
            background: var(--gradient-primary);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .card-header-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.05)"/><circle cx="10" cy="90" r="0.5" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .card-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .card-subtitle-modern {
            font-size: 1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .card-body-modern {
            padding: 2rem;
        }

        /* Form Controls */
        .form-group-modern {
            position: relative;
        }

        .form-label-modern {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            letter-spacing: 0.025em;
        }

        .form-input-modern {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-input-modern:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .form-input-modern::placeholder {
            color: var(--gray-400);
        }

        .btn-modern-primary {
            padding: 1rem 2rem;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-medium);
        }

        .btn-modern-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-strong);
        }

        /* Alert Styles */
        .alert-modern {
            padding: 1.25rem 1.5rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            font-weight: 500;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .alert-success-modern {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success-700);
            border-left: 4px solid var(--success-500);
        }

        .alert-error-modern {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-700);
            border-left: 4px solid var(--error-500);
        }

        /* Modern Tabs */
        .tabs-modern {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .tab-modern {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            background: rgba(255, 255, 255, 0.8);
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            color: var(--gray-700);
            backdrop-filter: blur(10px);
            box-shadow: var(--shadow-soft);
        }

        .tab-modern:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
            border-color: var(--primary-300);
        }

        .tab-modern.active {
            background: var(--gradient-primary);
            color: white;
            border-color: var(--primary-500);
            box-shadow: var(--shadow-medium);
        }

        .tab-modern i {
            font-size: 1.1rem;
        }

        .tab-modern.active i {
            transform: scale(1.1);
        }

        /* Footer */
        .footer-modern {
            background: var(--gray-900);
            color: var(--gray-300);
            padding: 3rem 2rem 2rem;
            margin-top: 4rem;
            position: relative;
        }

        .footer-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gray-700), transparent);
        }

        .footer-content-modern {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .footer-section-modern h4 {
            color: white;
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 1rem;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .footer-section-modern p {
            margin-bottom: 0.75rem;
            line-height: 1.6;
        }

        .footer-section-modern ul {
            list-style: none;
            padding: 0;
        }

        .footer-section-modern li {
            margin-bottom: 0.5rem;
        }

        .footer-section-modern a {
            color: var(--gray-300);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-section-modern a:hover {
            color: var(--primary-400);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .modern-header {
                padding: 1rem;
            }

            .header-content {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .header-brand {
                order: 1;
            }

            .header-actions {
                order: 2;
                width: 100%;
            }

            .logo-container {
                width: 48px;
                height: 48px;
                font-size: 1.25rem;
            }

            .brand-text h1 {
                font-size: 1.25rem;
            }

            .brand-text p {
                font-size: 0.75rem;
            }

            .back-btn {
                padding: 0.625rem 1rem;
                font-size: 0.875rem;
            }

            .user-info {
                flex-direction: column;
                gap: 0.5rem;
            }

            .user-avatar {
                width: 36px;
                height: 36px;
                font-size: 0.875rem;
            }

            .user-details p,
            .user-details span {
                font-size: 0.75rem;
            }

            .logout-btn {
                padding: 0.625rem 1rem;
                font-size: 0.875rem;
            }

            .main-container {
                padding: 1rem;
            }

            .modern-card {
                margin-bottom: 1.5rem;
            }

            .card-header-modern,
            .card-body-modern {
                padding: 1.5rem;
            }

            .card-title-modern {
                font-size: 1.5rem;
            }

            .stats-modern {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .stat-card-modern {
                padding: 1.5rem;
            }

            .stat-icon-modern {
                width: 56px;
                height: 56px;
                font-size: 1.5rem;
            }

            .stat-value-modern {
                font-size: 2rem;
            }

            .tabs-modern {
                flex-direction: column;
                gap: 0.75rem;
            }

            .tab-modern {
                padding: 0.875rem 1.25rem;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .modern-header {
                padding: 0.75rem;
            }

            .header-content {
                gap: 0.75rem;
            }

            .logo-container {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .brand-text h1 {
                font-size: 1.125rem;
            }

            .back-btn {
                padding: 0.5rem 0.875rem;
                font-size: 0.8rem;
            }

            .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.75rem;
            }

            .user-details p,
            .user-details span {
                font-size: 0.7rem;
            }

            .logout-btn {
                padding: 0.5rem 0.875rem;
                font-size: 0.8rem;
            }

            .main-container {
                padding: 0.75rem;
            }

            .stats-modern {
                grid-template-columns: 1fr;
            }

            .stat-card-modern {
                padding: 1.25rem;
            }

            .stat-icon-modern {
                width: 48px;
                height: 48px;
                font-size: 1.25rem;
            }

            .stat-value-modern {
                font-size: 1.75rem;
            }

            .form-input-modern {
                padding: 0.875rem 1rem;
                font-size: 0.9rem;
            }

            .btn-modern-primary {
                padding: 0.875rem 1.5rem;
                font-size: 0.9rem;
            }

            .tabs-modern {
                gap: 0.5rem;
            }

            .tab-modern {
                padding: 0.75rem 1rem;
                font-size: 0.85rem;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .animate-slide-in-left {
            animation: slideInLeft 0.6s ease-out;
        }

        .animate-slide-in-right {
            animation: slideInRight 0.6s ease-out;
        }

        /* Results specific styles */
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

        body {
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

        @media (max-width: 768px) {
            .main-container {
                padding: 1rem;
            }
        }

        :root {
            --primary-500: #168575;
            --primary-600: #0f6a5c;
            --primary-700: #0c574b;
            --accent-500: #1e9bb3;
            --success-500: #0f9f6e;
            --success-700: #0a7f58;
            --error-500: #e11d48;
            --error-700: #be123c;
            --warning-500: #d97706;
            --gray-50: #f8fafc;
            --gray-100: #eef2f7;
            --gray-200: #dbe3ee;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-900: #0f1f2d;
            --gradient-primary: linear-gradient(135deg, #0f6a5c 0%, #168575 56%, #1e9bb3 100%);
            --gradient-bg: linear-gradient(180deg, #eef7f4 0%, #f7f3ea 54%, #ffffff 100%);
            --shadow-soft: 0 14px 32px rgba(15, 31, 45, 0.08);
            --shadow-medium: 0 18px 40px rgba(15, 31, 45, 0.12);
            --shadow-strong: 0 22px 48px rgba(15, 31, 45, 0.16);
        }

        body {
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            background: var(--gradient-bg);
            color: var(--gray-900);
            overflow-x: hidden;
        }

        .results-page {
            overflow-x: hidden;
        }

        .results-page main,
        .results-page section {
            padding-top: 0;
            padding-bottom: 0;
        }

        .results-page .container,
        .results-page main,
        .results-page .main-container,
        .results-page .modern-card,
        .results-page .card-body-modern,
        .results-page .section-card,
        .results-page .filter-form,
        .results-page .filter-grid,
        .results-page .results-stats,
        .results-page .table-shell,
        .results-page .tabs-modern,
        .results-page .tab-content,
        .results-page .result-action-group,
        .results-page .complaint-response-form {
            min-width: 0;
        }

        .main-container {
            max-width: 1120px;
            padding: 0;
        }

        .modern-card {
            background: rgba(255, 255, 255, 0.97);
            border: 1px solid rgba(15, 31, 45, 0.08);
            border-radius: 1.75rem;
            box-shadow: var(--shadow-soft);
            margin-bottom: 1.5rem;
        }

        .modern-card:hover {
            transform: none;
            box-shadow: var(--shadow-medium);
        }

        .card-header-modern {
            padding: 1.75rem;
            background: var(--gradient-primary);
        }

        .card-header-modern,
        .card-header-modern h1,
        .card-header-modern h2,
        .card-header-modern h3,
        .card-header-modern p,
        .card-header-modern span,
        .card-header-modern i {
            color: #fff;
        }

        .card-title-modern {
            font-family: 'Fraunces', Georgia, serif;
        }

        .card-body-modern {
            padding: 1.5rem;
        }

        .alert-modern {
            border: 1px solid rgba(15, 31, 45, 0.08);
            border-radius: 1.1rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-soft);
        }

        .results-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .results-stat-card {
            padding: 1.2rem 1.25rem;
            border-radius: 1.25rem;
            border: 1px solid rgba(15, 31, 45, 0.08);
            background: linear-gradient(180deg, #ffffff 0%, #f8fbfd 100%);
            box-shadow: var(--shadow-soft);
        }

        .results-stat-label {
            display: block;
            margin-bottom: 0.4rem;
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--gray-500);
        }

        .results-stat-value {
            display: block;
            color: var(--gray-900);
            font-size: 1.55rem;
            line-height: 1.15;
        }

        .results-stat-meta {
            display: block;
            margin-top: 0.45rem;
            font-size: 0.88rem;
            color: var(--gray-600);
        }

        .form-input-modern {
            border-radius: 1rem;
            border-width: 1px;
            border-color: rgba(15, 31, 45, 0.12);
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        .form-input-modern:focus {
            border-color: var(--primary-500);
            box-shadow: 0 0 0 4px rgba(22, 133, 117, 0.12);
        }

        .btn-modern-primary,
        .btn-batch,
        .btn-pdf,
        .btn-delete,
        .btn-small {
            border-radius: 999px;
            font-weight: 700;
            box-shadow: none;
        }

        .btn-modern-primary,
        .btn-batch,
        .btn-pdf {
            background: var(--gradient-primary);
            color: #fff;
        }

        .btn-delete {
            background: var(--error-500);
            color: #fff;
        }

        .btn-small {
            background: var(--gray-100);
            color: var(--gray-700);
            border: 1px solid rgba(15, 31, 45, 0.08);
        }

        .btn-secondary-soft {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-danger-soft {
            background: var(--error-500);
            color: #fff;
        }

        .tabs-modern {
            gap: 0.75rem;
        }

        .tab-modern {
            border-radius: 999px;
            border-width: 1px;
            padding: 0.9rem 1.2rem;
            box-shadow: none;
        }

        .tab-modern.active {
            border-color: transparent;
        }

        .section-card {
            border-radius: 1.35rem;
            border: 1px solid rgba(15, 31, 45, 0.08);
            background: rgba(255, 255, 255, 0.97);
            box-shadow: var(--shadow-soft);
        }

        .section-card h3 {
            font-family: 'Fraunces', Georgia, serif;
            color: var(--gray-900);
        }

        .results-table {
            width: 100%;
            min-width: 760px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .batch-results-table {
            min-width: max-content;
        }

        .results-table th {
            background: #f8fafc;
            color: var(--gray-600);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .results-table th,
        .results-table td {
            border-color: rgba(15, 31, 45, 0.08);
        }

        .student-checkbox {
            border-radius: 1rem;
            border: 1px solid rgba(15, 31, 45, 0.08);
            background: #fff;
            box-shadow: var(--shadow-soft);
        }

        .student-select-panel {
            margin-bottom: 1.5rem;
            padding: 1rem;
            border-radius: 1.25rem;
            border: 1px solid rgba(15, 31, 45, 0.08);
            background: linear-gradient(180deg, #ffffff 0%, #f8fbfd 100%);
            box-shadow: var(--shadow-soft);
        }

        .student-multi-select {
            min-height: 220px;
            padding: 0.85rem;
        }

        .student-multi-select option {
            padding: 0.7rem 0.85rem;
            border-radius: 0.75rem;
            margin-bottom: 0.25rem;
        }

        .student-batch-column.is-hidden {
            display: none;
        }

        .subject-cell {
            font-weight: 700;
            min-width: 180px;
        }

        .filter-grid,
        .single-form-grid,
        .single-score-grid,
        .multi-meta-grid {
            display: grid;
            gap: 1rem;
        }

        .filter-grid,
        .multi-meta-grid {
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            margin-bottom: 1.5rem;
        }

        .single-form-grid {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }

        .single-score-grid {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            margin-top: 1.25rem;
        }

        .filter-actions,
        .batch-actions,
        .form-submit-row,
        .multi-actions,
        .result-action-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }

        .session-row {
            margin-bottom: 1.25rem;
        }

        .table-shell {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 1rem;
        }

        .table-shell.batch-table-shell {
            border: 1px solid rgba(15, 31, 45, 0.08);
            background: rgba(248, 250, 252, 0.6);
        }

        .subject-score-shell {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 15px;
        }

        .complaint-response-form {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .complaint-response-form input[type="text"] {
            min-width: 180px;
        }

        .session-select {
            max-width: 300px;
        }

        .selection-help {
            margin-top: 0.75rem;
        }

        .selection-count {
            margin-top: 0.4rem;
            font-weight: 700;
        }

        .table-align-center {
            text-align: center;
        }

        .multi-clear-button {
            margin-left: 10px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }

        .status-pill-success {
            background: rgba(15, 159, 110, 0.12);
            color: var(--success-700);
        }

        .inline-action-form {
            display: inline;
        }

        .complaint-response-input {
            padding: 0.8rem 0.95rem;
            border: 1px solid rgba(15, 31, 45, 0.14);
            border-radius: 0.85rem;
            flex: 1 1 220px;
            min-width: 0;
            background: #fff;
        }

        .subject-scores,
        .multi-subject-form {
            overflow: hidden;
        }

        .small-muted {
            color: var(--gray-500);
        }

        .results-page .nav-wrap {
            align-items: center;
            flex-wrap: nowrap;
            gap: 0.85rem;
        }

        .results-page .nav-wrap > :first-child {
            min-width: 0;
            flex: 1 1 auto;
        }

        @media (max-width: 1024px) {
            .tabs-modern {
                overflow-x: auto;
                padding-bottom: 0.25rem;
                flex-wrap: nowrap;
            }

            .tab-modern {
                flex: 0 0 auto;
            }
        }

        @media (max-width: 768px) {
            .results-page .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .results-page .nav-wrap {
                gap: 0.75rem;
                padding-top: 0.85rem;
                padding-bottom: 0.85rem;
            }

            .card-header-modern,
            .card-body-modern,
            .section-card {
                padding: 1.25rem;
            }

            .card-title-modern {
                font-size: 1.55rem;
                line-height: 1.2;
            }

            .results-stats {
                grid-template-columns: 1fr 1fr;
            }

            .filter-actions,
            .batch-actions,
            .form-submit-row,
            .multi-actions,
            .result-action-group,
            .complaint-response-form,
            .subject-score-row {
                flex-direction: column;
                align-items: stretch;
            }

            .btn-modern-primary,
            .btn-batch,
            .btn-pdf,
            .btn-delete,
            .btn-small {
                width: 100%;
                justify-content: center;
            }

            .student-select-panel {
                padding: 0.9rem;
            }

            .results-page .main-container {
                width: 100%;
                max-width: 100%;
            }

            .student-multi-select {
                min-height: 180px;
                font-size: 0.95rem;
            }

            .session-select {
                max-width: none;
            }

            .results-table th,
            .results-table td {
                white-space: nowrap;
            }

            .batch-table-shell {
                margin-inline: -0.2rem;
            }

            .batch-results-table th,
            .batch-results-table td {
                padding: 0.65rem 0.45rem;
                font-size: 0.8rem;
            }

            .batch-results-table th:first-child,
            .batch-results-table td:first-child {
                position: sticky;
                left: 0;
                z-index: 1;
                background: #fff;
            }

            .batch-results-table thead th:first-child {
                z-index: 2;
                background: #f8fafc;
            }

            .batch-results-table .score-input {
                width: 4rem;
                min-width: 4rem;
                padding: 0.55rem 0.3rem;
                font-size: 0.82rem;
            }

            .subject-score-shell {
                padding: 0.9rem;
            }

            .subject-score-row {
                grid-template-columns: 1fr;
                gap: 0.75rem;
                padding: 0.9rem 0;
            }

            .subject-score-row > div {
                display: grid;
                gap: 0.35rem;
            }

            .subject-score-row > div[data-label]::before {
                content: attr(data-label);
                font-size: 0.72rem;
                font-weight: 800;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                color: var(--gray-500);
            }

            .complaint-response-form input[type="text"] {
                width: 100%;
                min-width: 0;
            }

            .multi-clear-button {
                margin-left: 0;
            }
        }

        @media (max-width: 640px) {
            .results-page .nav-wrap {
                align-items: center;
            }

            .results-page .nav-wrap > :last-child {
                display: flex;
                align-items: center;
                gap: 0.5rem;
                flex: 0 0 auto;
            }

            .results-page .nav-wrap > :last-child .btn {
                padding: 0.5rem 0.85rem;
                font-size: 0.8125rem;
            }

            .results-stats {
                grid-template-columns: 1fr;
            }

            .results-table {
                min-width: 620px;
            }

            .card-header-modern {
                padding: 1.15rem;
            }

            .card-title-modern {
                font-size: 1.35rem;
            }

            .card-subtitle-modern {
                font-size: 0.92rem;
                color: rgba(255, 255, 255, 0.9);
            }

            .filter-grid,
            .single-form-grid,
            .single-score-grid,
            .multi-meta-grid {
                grid-template-columns: 1fr;
            }

            .tabs-modern {
                gap: 0.6rem;
                margin: 0 -0.1rem;
                padding-bottom: 0.4rem;
            }

            .tab-modern {
                min-width: 170px;
                justify-content: center;
            }

            .section-card h3 {
                font-size: 1.25rem;
            }

            .subject-score-row.header {
                display: none;
            }

            .subject-score-row > div label {
                font-weight: 700;
                color: var(--gray-700);
            }

            .results-table.mobile-card-table {
                min-width: 0;
                display: block;
            }

            .results-table.mobile-card-table thead {
                display: none;
            }

            .results-table.mobile-card-table tbody,
            .results-table.mobile-card-table tr,
            .results-table.mobile-card-table td {
                display: block;
                width: 100%;
            }

            .results-table.mobile-card-table tbody {
                padding-top: 0.1rem;
            }

            .results-table.mobile-card-table tr {
                margin-bottom: 0.9rem;
                padding: 1rem;
                border: 1px solid rgba(15, 31, 45, 0.08);
                border-radius: 1rem;
                background: #fff;
                box-shadow: var(--shadow-soft);
            }

            .results-table.mobile-card-table td {
                white-space: normal;
                border: 0;
                padding: 0.45rem 0;
            }

            .results-table.mobile-card-table td::before {
                content: attr(data-label);
                display: block;
                margin-bottom: 0.2rem;
                font-size: 0.72rem;
                font-weight: 800;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                color: var(--gray-500);
            }

            .results-table.mobile-card-table td[data-label="Action"]::before,
            .results-table.mobile-card-table td[data-label="Response"]::before {
                margin-bottom: 0.45rem;
            }

            .inline-action-form {
                display: block;
                width: 100%;
            }
        }

        [data-sidebar] {
            overflow: hidden;
        }

        .sidebar-scroll-shell {
            height: 100%;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-y: contain;
            touch-action: pan-y;
            padding-bottom: max(1rem, env(safe-area-inset-bottom));
        }
    </style>
</head>
<body class="landing bg-slate-50 results-page">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="h-10 w-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Teacher Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="hidden md:block text-sm text-slate-600">Welcome, <?php echo htmlspecialchars($teacher_name); ?></span>
                <a class="btn btn-outline" href="index.php">Dashboard</a>
                <a class="btn btn-primary" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container grid gap-6 py-8 lg:grid-cols-[280px_1fr]">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 -translate-x-full transform border-r border-ink-900/10 bg-white shadow-lift transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell h-full overflow-y-auto">
                <?php include '../includes/teacher_sidebar.php'; ?>
            </div>
        </aside>
        <main class="space-y-6">
            <div class="main-container">
        <!-- Welcome Section -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern">
                <h2 class="card-title-modern">
                    <i class="fas fa-chart-line"></i>
                    Results Management Dashboard
                </h2>
                <p class="card-subtitle-modern">
                    Manage and track student results for <?php echo htmlspecialchars($class['class_name']); ?> - <?php echo htmlspecialchars($term); ?>
                </p>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($errors): ?>
            <div class="alert-modern alert-error-modern animate-fade-in-up">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-modern alert-success-modern animate-fade-in-up">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <div class="results-stats">
            <div class="results-stat-card">
                <span class="results-stat-label">Class</span>
                <strong class="results-stat-value"><?php echo htmlspecialchars($class['class_name']); ?></strong>
                <span class="results-stat-meta"><?php echo htmlspecialchars($term); ?></span>
            </div>
            <div class="results-stat-card">
                <span class="results-stat-label">Students</span>
                <strong class="results-stat-value"><?php echo $student_total; ?></strong>
                <span class="results-stat-meta">Visible in current filter</span>
            </div>
            <div class="results-stat-card">
                <span class="results-stat-label">Compiled</span>
                <strong class="results-stat-value"><?php echo $compiled_count; ?></strong>
                <span class="results-stat-meta"><?php echo $pending_count; ?> pending</span>
            </div>
            <div class="results-stat-card">
                <span class="results-stat-label">Entries</span>
                <strong class="results-stat-value"><?php echo $result_entry_count; ?></strong>
                <span class="results-stat-meta"><?php echo $complaint_total; ?> complaints</span>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-body-modern">
                <form method="GET" class="filter-form">
                    <input type="hidden" name="id" value="<?php echo intval($class_id); ?>">
                    <div class="filter-grid">
                        <div class="form-group-modern">
                            <label class="form-label-modern" for="term_filter">Academic Term</label>
                            <select id="term_filter" name="term" class="form-input-modern">
                                <option value="1st Term" <?php echo $term === '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                                <option value="2nd Term" <?php echo $term === '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                                <option value="3rd Term" <?php echo $term === '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                            </select>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern" for="class_filter">Class</label>
                            <select id="class_filter" name="filter_class_id" class="form-input-modern">
                                <?php foreach ($teacher_classes as $tc): ?>
                                    <option value="<?php echo intval($tc['id']); ?>" <?php echo $tc['id'] == $filter_class_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tc['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label-modern" for="admission_no">Admission Number</label>
                            <input id="admission_no" name="admission_no" class="form-input-modern"
                                   value="<?php echo htmlspecialchars($_GET['admission_no'] ?? ''); ?>"
                                   placeholder="Filter by admission number">
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-modern-primary">
                            <i class="fas fa-search"></i>
                            <span>Apply Filter</span>
                        </button>
                        <a href="results.php?id=<?php echo intval($class_id); ?>" class="btn-modern-primary btn-secondary-soft">
                            <i class="fas fa-undo"></i>
                            <span>Reset</span>
                        </a>
                    </div>
                </form>
            </div>
        </div>
                
        <!-- Navigation Tabs -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-body-modern">
                <div class="tabs-modern">
                    <button class="tab-modern active" type="button" onclick="switchTab('batch', this)">
                        <i class="fas fa-layer-group"></i>
                        <span>Batch Entry</span>
                    </button>
                    <button class="tab-modern" type="button" onclick="switchTab('single', this)">
                        <i class="fas fa-plus"></i>
                        <span>Single Entry</span>
                    </button>
                    <button class="tab-modern" type="button" onclick="switchTab('multi', this)">
                        <i class="fas fa-list"></i>
                        <span>Multiple Subjects</span>
                    </button>
                    <button class="tab-modern" type="button" onclick="switchTab('view', this)">
                        <i class="fas fa-eye"></i>
                        <span>View Results</span>
                    </button>
                    <button class="tab-modern" type="button" onclick="switchTab('complaints', this)">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Complaints</span>
                    </button>
                </div>
            </div>
        </div>
                
                <!-- Batch Entry Tab -->
                <div id="batch-tab" class="tab-content active">
                    <div class="section-card">
                        <h3>Batch Entry - Multiple Students & Subjects</h3>
                        <form method="POST" id="batch-form" data-offline-sync="1">
                            <input type="hidden" name="action" value="save_batch_results">
                            
                            <!-- Academic Session Selection -->
                            <div class="session-row">
                                <label for="academic_session_batch">Academic Session *</label>
                                <select id="academic_session_batch" name="academic_session" class="form-input-modern session-select" required>
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
                            <div class="student-select-panel">
                                <label for="batch_student_ids" class="form-label-modern">Choose one or more students</label>
                                <select id="batch_student_ids" name="student_ids[]" class="form-input-modern student-multi-select" multiple size="<?php echo max(5, min(10, count($students))); ?>">
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo intval($student['id']); ?>" selected>
                                            <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['admission_no'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="small-muted selection-help">Hold Ctrl or Cmd to pick specific students. Only selected students will be saved in this batch.</p>
                                <p id="batchSelectionCount" class="small-muted selection-count">0 students selected</p>
                            </div>
                            
                            <div class="batch-actions">
                                <button type="button" class="btn-modern-primary" onclick="selectAllStudents()">
                                    <i class="fas fa-check-square"></i>
                                    <span>Select All</span>
                                </button>
                                <button type="button" class="btn-modern-primary btn-danger-soft" onclick="deselectAllStudents()">
                                    <i class="fas fa-square"></i>
                                    <span>Deselect All</span>
                                </button>
                            </div>
                            
                            <!-- Subject Scores Grid -->
                            <?php if (!empty($subjects)): ?>
                                <div class="subject-scores">
                                    <h4>Enter Scores</h4>
                                    <div class="table-shell batch-table-shell">
                                        <table class="results-table batch-results-table">
                                            <thead>
                                                <tr>
                                                    <th>Subject</th>
                                                    <?php foreach ($students as $student): ?>
                                                        <th colspan="3" class="student-batch-column table-align-center" data-student-id="<?php echo intval($student['id']); ?>">
                                                            <?php echo htmlspecialchars($student['full_name']); ?>
                                                        </th>
                                                    <?php endforeach; ?>
                                                </tr>
                                                <tr>
                                                    <th></th>
                                                    <?php foreach ($students as $student): ?>
                                                        <th class="student-batch-column" data-student-id="<?php echo intval($student['id']); ?>">1st CA</th>
                                                        <th class="student-batch-column" data-student-id="<?php echo intval($student['id']); ?>">2nd CA</th>
                                                        <th class="student-batch-column" data-student-id="<?php echo intval($student['id']); ?>">Exam</th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($subjects as $subject): ?>
                                                    <tr>
                                                        <td class="subject-cell"><?php echo htmlspecialchars($subject['subject_name']); ?></td>
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
                                                            <td class="student-batch-column" data-student-id="<?php echo intval($student['id']); ?>">
                                                                <input type="number" 
                                                                       name="first_ca_<?php echo $student['id']; ?>_<?php echo $subject['id']; ?>"
                                                                       class="score-input"
                                                                       min="0" max="100" step="0.1"
                                                                       value="<?php echo $existing_score ? htmlspecialchars($existing_score['first_ca']) : '0'; ?>">
                                                            </td>
                                                            <td class="student-batch-column" data-student-id="<?php echo intval($student['id']); ?>">
                                                                <input type="number" 
                                                                       name="second_ca_<?php echo $student['id']; ?>_<?php echo $subject['id']; ?>"
                                                                       class="score-input"
                                                                       min="0" max="100" step="0.1"
                                                                       value="<?php echo $existing_score ? htmlspecialchars($existing_score['second_ca']) : '0'; ?>">
                                                            </td>
                                                            <td class="student-batch-column" data-student-id="<?php echo intval($student['id']); ?>">
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
                                
                                <div class="form-submit-row">
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
                        <form method="POST" data-offline-sync="1">
                            <input type="hidden" name="action" value="save_single_result">
                            
                            <div class="single-form-grid">
                                <div>
                                    <label for="student_id_single">Student *</label>
                                    <select id="student_id_single" name="student_id" class="form-input-modern" required>
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
                                    <select id="subject_id_single" name="subject_id" class="form-input-modern" required>
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
                                           class="form-input-modern" value="<?php echo $default_academic_session; ?>" required>
                                </div>
                            </div>
                            
                            <div class="single-score-grid">
                                <div>
                                    <label for="first_ca_single">First C.A.</label>
                                    <input type="number" id="first_ca_single" name="first_ca" class="form-input-modern"
                                           min="0" max="100" step="0.1" value="0" required>
                                </div>

                                <div>
                                    <label for="second_ca_single">Second C.A.</label>
                                    <input type="number" id="second_ca_single" name="second_ca" class="form-input-modern"
                                           min="0" max="100" step="0.1" value="0" required>
                                </div>

                                <div>
                                    <label for="exam_single">Exam</label>
                                    <input type="number" id="exam_single" name="exam" class="form-input-modern"
                                           min="0" max="100" step="0.1" value="0" required>
                                </div>
                            </div>
                            
                            <div class="form-submit-row">
                                <button type="submit" class="btn-modern-primary">
                                    <i class="fas fa-save"></i>
                                    <span>Save Result</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Multiple Subjects Tab (NEW) -->
                <div id="multi-tab" class="tab-content">
                    <div class="section-card">
                        <h3>Multiple Subjects - Single Student</h3>
                        <p>Enter scores for all subjects for one student at once.</p>
                        
                        <form method="POST" id="multi-subject-form" class="multi-subject-form" data-offline-sync="1">
                            <input type="hidden" name="action" value="save_multiple_subjects">
                            
                            <div class="multi-meta-grid">
                                <div>
                                    <label for="student_id_multi">Select Student *</label>
                                    <select id="student_id_multi" name="student_id_multi" class="form-input-modern" required onchange="loadStudentScores()">
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
                                    <select id="academic_session_multi" name="academic_session_multi" class="form-input-modern session-select" required>
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
                                    
                                    <div class="subject-score-shell">
                                        <div class="subject-score-row header">
                                            <div><strong>Subject</strong></div>
                                            <div><strong>1st CA</strong></div>
                                            <div><strong>2nd CA</strong></div>
                                            <div><strong>Exam</strong></div>
                                        </div>
                                        
                                        <?php foreach ($subjects as $subject): ?>
                                            <div class="subject-score-row">
                                                <div data-label="Subject">
                                                    <label for="subject_<?php echo $subject['id']; ?>">
                                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                                    </label>
                                                </div>
                                                <div data-label="1st CA">
                                                    <input type="number" 
                                                           id="first_ca_multi_<?php echo $subject['id']; ?>"
                                                           name="first_ca_multi_<?php echo $subject['id']; ?>"
                                                           class="score-input"
                                                           min="0" max="100" step="0.1"
                                                           value="0"
                                                           placeholder="0">
                                                </div>
                                                <div data-label="2nd CA">
                                                    <input type="number" 
                                                           id="second_ca_multi_<?php echo $subject['id']; ?>"
                                                           name="second_ca_multi_<?php echo $subject['id']; ?>"
                                                           class="score-input"
                                                           min="0" max="100" step="0.1"
                                                           value="0"
                                                           placeholder="0">
                                                </div>
                                                <div data-label="Exam">
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
                                    
                                    <div class="multi-actions">
                                        <button type="submit" class="btn-batch">Save All Subjects for This Student</button>
                                        <button type="button" class="btn-small multi-clear-button" onclick="clearAllScores()">Clear All</button>
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
                            <div class="table-shell">
                                <table class="results-table mobile-card-table">
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
                                            <td data-label="S/N"><?php echo $sn++; ?></td>
                                            <td data-label="Adm No"><?php echo htmlspecialchars($student['admission_no']); ?></td>
                                            <td data-label="Name"><?php echo htmlspecialchars($student['student_name']); ?></td>
                                            <td data-label="Status"><span class="status-pill status-pill-success">Compiled</span></td>
                                            <td data-label="Action">
                                                <div class="result-action-group">
                                                    <form method="POST" class="inline-action-form" onsubmit="return confirm('Delete all results for this student? This action cannot be undone.')">
                                                        <input type="hidden" name="action" value="delete_student_results">
                                                        <input type="hidden" name="student_id" value="<?php echo intval($student['student_id']); ?>">
                                                        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                                                        <button type="submit" class="btn-delete">Delete</button>
                                                    </form>

                                                    <form method="POST" action="generate-result-pdf.php" class="inline-action-form">
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
                            <div class="table-shell">
                                <table class="results-table mobile-card-table">
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
                                                <td data-label="#"><?php echo $i + 1; ?></td>
                                                <td data-label="Student"><?php echo htmlspecialchars($c['full_name']); ?></td>
                                                <td data-label="Subject"><?php echo htmlspecialchars($c['subject_name']); ?></td>
                                                <td data-label="Complaint"><?php echo htmlspecialchars($c['complaint_text']); ?></td>
                                                <td data-label="Status">
                                                    <span class="status-<?php echo $c['status']; ?>">
                                                        <?php echo htmlspecialchars(ucfirst($c['status'])); ?>
                                                    </span>
                                                </td>
                                                <td data-label="Submitted"><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                                                <td data-label="Response"><?php echo htmlspecialchars($c['teacher_response'] ?? 'Not responded'); ?></td>
                                                <td data-label="Action">
                                                    <?php if ($c['status'] !== 'resolved'): ?>
                                                        <form method="POST" class="complaint-response-form">
                                                            <input type="hidden" name="action" value="resolve_complaint">
                                                            <input type="hidden" name="complaint_id" value="<?php echo intval($c['id']); ?>">
                                                            <input type="text" name="response" placeholder="Enter response" required class="complaint-response-input">
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
        function switchTab(tabName, triggerButton = null) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-modern').forEach(tabBtn => {
                tabBtn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');

            // Activate selected tab button
            if (triggerButton) {
                triggerButton.classList.add('active');
            }
        }

        function getBatchStudentSelect() {
            return document.getElementById('batch_student_ids');
        }

        function getSelectedBatchStudentIds() {
            const select = getBatchStudentSelect();
            if (!select) return [];
            return Array.from(select.options)
                .filter(option => option.selected)
                .map(option => String(option.value));
        }

        function updateBatchSelectionCount() {
            const summary = document.getElementById('batchSelectionCount');
            if (!summary) return;
            const count = getSelectedBatchStudentIds().length;
            summary.textContent = `${count} student${count === 1 ? '' : 's'} selected`;
        }

        function updateBatchStudentVisibility() {
            const selectedIds = getSelectedBatchStudentIds();
            const showAll = selectedIds.length === 0;

            document.querySelectorAll('.student-batch-column').forEach(cell => {
                const studentId = cell.dataset.studentId;
                const shouldShow = showAll || selectedIds.includes(String(studentId));
                cell.classList.toggle('is-hidden', !shouldShow);
            });

            updateBatchSelectionCount();
        }
        
        function selectAllStudents() {
            const select = getBatchStudentSelect();
            if (!select) return;
            Array.from(select.options).forEach(option => {
                option.selected = true;
            });
            updateBatchStudentVisibility();
        }
        
        function deselectAllStudents() {
            const select = getBatchStudentSelect();
            if (!select) return;
            Array.from(select.options).forEach(option => {
                option.selected = false;
            });
            updateBatchStudentVisibility();
        }
        
        // Auto-fill academic session for single entry when batch session changes
        const batchSessionSelect = document.getElementById('academic_session_batch');
        if (batchSessionSelect) {
            batchSessionSelect.addEventListener('change', function() {
                const singleSession = document.getElementById('academic_session_single');
                const multiSession = document.getElementById('academic_session_multi');
                if (singleSession) singleSession.value = this.value;
                if (multiSession) multiSession.value = this.value;
            });
        }
        
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
            const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
            const sidebar = document.querySelector('[data-sidebar]');
            const overlay = document.querySelector('[data-sidebar-overlay]');
            const body = document.body;

            const openSidebar = () => {
                if (!sidebar || !overlay) return;
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('opacity-0', 'pointer-events-none');
                overlay.classList.add('opacity-100');
                body.classList.add('nav-open');
            };

            const closeSidebar = () => {
                if (!sidebar || !overlay) return;
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('opacity-0', 'pointer-events-none');
                overlay.classList.remove('opacity-100');
                body.classList.remove('nav-open');
            };

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', () => {
                    if (sidebar.classList.contains('-translate-x-full')) {
                        openSidebar();
                    } else {
                        closeSidebar();
                    }
                });
            }

            if (overlay) {
                overlay.addEventListener('click', closeSidebar);
            }

            const batchStudentSelect = getBatchStudentSelect();
            if (batchStudentSelect) {
                batchStudentSelect.addEventListener('change', updateBatchStudentVisibility);
                updateBatchStudentVisibility();
            }

            // Auto-switch to single entry tab if there's an error in that tab
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('tab') && urlParams.get('tab') === 'multi') {
                switchTab('multi', document.querySelectorAll('.tab-modern')[2] || null);
            }
        });
    </script>

    <script src="../assets/js/offline-core.js" defer></script>
    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
