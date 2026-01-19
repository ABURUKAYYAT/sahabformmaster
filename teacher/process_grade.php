<?php
// process_grade.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../index.php');
    exit();
}
$current_school_id = require_school_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submission_id = intval($_POST['submission_id']);
    $marks = floatval($_POST['marks']);
    $feedback = $_POST['feedback'];
    
    // Get activity details to verify teacher ownership
    $verify_query = "
        SELECT ca.id, ca.total_marks 
        FROM student_submissions ss 
        JOIN class_activities ca ON ss.activity_id = ca.id 
        WHERE ss.id = ? AND ca.teacher_id = ?
    ";
    $verify_stmt = $pdo->prepare($verify_query);
    $verify_stmt->execute([$submission_id, $_SESSION['user_id']]);
    $activity = $verify_stmt->fetch();
    
    if (!$activity) {
        $_SESSION['error'] = 'Submission not found or access denied.';
        header('Location: teacher_class_activities.php?action=submissions');
        exit();
    }
    
    // Validate marks
    if ($marks < 0 || $marks > $activity['total_marks']) {
        $_SESSION['error'] = 'Invalid marks. Must be between 0 and ' . $activity['total_marks'];
        header('Location: teacher_class_activities.php?action=submissions');
        exit();
    }
    
    // Update submission
    $update_query = "
        UPDATE student_submissions 
        SET marks_obtained = ?, feedback = ?, status = 'graded', updated_at = NOW() 
        WHERE id = ?
    ";
    $update_stmt = $pdo->prepare($update_query);
    
    try {
        $update_stmt->execute([$marks, $feedback, $submission_id]);
        $_SESSION['success'] = 'Submission graded successfully!';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Failed to grade submission.';
    }
    
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit();
}
