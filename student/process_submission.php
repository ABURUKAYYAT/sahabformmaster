<?php
// process_submission.php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: student_login.php');
    exit();
}

$current_school_id = get_current_school_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_SESSION['student_id'];
    $activity_id = intval($_POST['activity_id']);
    $submission_text = $_POST['submission_text'];
    
    // Verify student can submit to this activity
    $verify_query = "
        SELECT ca.id, ca.due_date
        FROM class_activities ca
        JOIN students s ON ca.class_id = s.class_id AND ca.school_id = s.school_id
        WHERE ca.id = ? AND s.id = ? AND ca.status = 'published' AND ca.school_id = ?
    ";
    $verify_stmt = $pdo->prepare($verify_query);
    $verify_stmt->execute([$activity_id, $student_id, $current_school_id]);
    $activity = $verify_stmt->fetch();
    
    if (!$activity) {
        $_SESSION['error'] = 'Activity not found or submission not allowed.';
        header('Location: student_class_activities.php');
        exit();
    }
    
    // Check if already submitted
    $check_query = "SELECT id FROM student_submissions WHERE activity_id = ? AND student_id = ? AND school_id = ?";
    $check_stmt = $pdo->prepare($check_query);
    $check_stmt->execute([$activity_id, $student_id, $current_school_id]);
    
    if ($check_stmt->rowCount()) {
        $_SESSION['error'] = 'You have already submitted this activity.';
        header('Location: student_class_activities.php?action=view&id=' . $activity_id);
        exit();
    }
    
    // Handle file upload
    $attachment_path = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/submissions/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_name = $student_id . '_' . time() . '_' . basename($_FILES['attachment']['name']);
        $target_path = $upload_dir . $file_name;
        
        // Check file size (max 10MB)
        if ($_FILES['attachment']['size'] > 10 * 1024 * 1024) {
            $_SESSION['error'] = 'File size exceeds 10MB limit.';
            header('Location: student_class_activities.php?action=view&id=' . $activity_id);
            exit();
        }
        
        // Check file type
        $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip', 'txt'];
        $file_ext = strtolower(pathinfo($target_path, PATHINFO_EXTENSION));
        if (!in_array($file_ext, $allowed_types)) {
            $_SESSION['error'] = 'Invalid file type. Allowed: PDF, Word, Images, ZIP, TXT';
            header('Location: student_class_activities.php?action=view&id=' . $activity_id);
            exit();
        }
        
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
            $attachment_path = $target_path;
        }
    }
    
    // Determine status (late or submitted)
    $status = 'submitted';
    if ($activity['due_date'] && strtotime($activity['due_date']) < time()) {
        $status = 'late';
    }
    
    // Insert submission
    $query = "
        INSERT INTO student_submissions (activity_id, student_id, submission_text,
                 attachment_path, status, submitted_at, school_id)
        VALUES (?, ?, ?, ?, ?, NOW(), ?)
    ";
    $stmt = $pdo->prepare($query);

    try {
        $stmt->execute([$activity_id, $student_id, $submission_text, $attachment_path, $status, $current_school_id]);
        $_SESSION['success'] = 'Submission successful!';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Failed to submit. Please try again.';
    }
    
    header('Location: student_class_activities.php?action=view&id=' . $activity_id);
    exit();
}
