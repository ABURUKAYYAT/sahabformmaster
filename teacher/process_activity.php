<?php
// process_activity.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../index.php');
    exit();
}
$current_school_id = require_school_auth();

$teacher_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create new activity
    $activity_type = $_POST['activity_type'];
    $title = $_POST['title'];
    $subject_id = intval($_POST['subject_id']);
    $class_id = intval($_POST['class_id']);
    $description = $_POST['description'];
    $instructions = $_POST['instructions'];
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $total_marks = floatval($_POST['total_marks']);
    $status = $_POST['status'];
    
    // Check if teacher is assigned to this class and subject
    $check_query = "SELECT 1 FROM subject_assignments 
                    WHERE teacher_id = ? AND subject_id = ? AND class_id = ?";
    $check_stmt = $pdo->prepare($check_query);
    $check_stmt->execute([$teacher_id, $subject_id, $class_id]);
    
    if (!$check_stmt->rowCount()) {
        $_SESSION['error'] = 'You are not assigned to teach this subject for the selected class.';
        header('Location: teacher_class_activities.php');
        exit();
    }
    
    // Handle file upload
    $attachment_path = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/activities/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_name = time() . '_' . basename($_FILES['attachment']['name']);
        $target_path = $upload_dir . $file_name;
        
        // Check file size (max 10MB)
        if ($_FILES['attachment']['size'] > 10 * 1024 * 1024) {
            $_SESSION['error'] = 'File size exceeds 10MB limit.';
            header('Location: teacher_class_activities.php');
            exit();
        }
        
        // Check file type
        $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip'];
        $file_ext = strtolower(pathinfo($target_path, PATHINFO_EXTENSION));
        if (!in_array($file_ext, $allowed_types)) {
            $_SESSION['error'] = 'Invalid file type. Allowed: PDF, Word, Images, ZIP';
            header('Location: teacher_class_activities.php');
            exit();
        }
        
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
            $attachment_path = $target_path;
        }
    }
    
    // Insert into database
    $query = "INSERT INTO class_activities (activity_type, title, description, subject_id, class_id, 
              teacher_id, due_date, total_marks, instructions, attachment_path, status) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($query);
    
    try {
        $stmt->execute([
            $activity_type, $title, $description, $subject_id, $class_id, 
            $teacher_id, $due_date, $total_marks, $instructions, $attachment_path, $status
        ]);
        
        $_SESSION['success'] = 'Activity created successfully!';
        
        // If published, create submission records for all students in the class - school-filtered
        if ($status === 'published') {
            $activity_id = $pdo->lastInsertId();

            $students_query = "SELECT id FROM students WHERE class_id = ? AND school_id = ?";
            $students_stmt = $pdo->prepare($students_query);
            $students_stmt->execute([$class_id, $current_school_id]);
            $students = $students_stmt->fetchAll();
            
            $insert_submission = $pdo->prepare("
                INSERT INTO student_submissions (activity_id, student_id, status) 
                VALUES (?, ?, 'pending')
            ");
            
            foreach ($students as $student) {
                $insert_submission->execute([$activity_id, $student['id']]);
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Failed to create activity. Please try again.';
    }
    
    header('Location: teacher_class_activities.php');
    exit();
} elseif (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    // Delete activity
    $activity_id = intval($_GET['id']);
    
    // Verify ownership
    $verify_query = "SELECT id FROM class_activities WHERE id = ? AND teacher_id = ?";
    $verify_stmt = $pdo->prepare($verify_query);
    $verify_stmt->execute([$activity_id, $teacher_id]);
    
    if ($verify_stmt->rowCount()) {
        $delete_query = "DELETE FROM class_activities WHERE id = ?";
        $delete_stmt = $pdo->prepare($delete_query);
        
        try {
            $delete_stmt->execute([$activity_id]);
            $_SESSION['success'] = 'Activity deleted successfully.';
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to delete activity.';
        }
    } else {
        $_SESSION['error'] = 'Activity not found or access denied.';
    }
    
    header('Location: teacher_class_activities.php?action=activities');
    exit();
}
