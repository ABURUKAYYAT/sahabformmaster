<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../index.php');
    exit();
}

$current_school_id = require_school_auth();

$user_id = $_SESSION['user_id'];
$request_id = $_GET['id'] ?? '';

if ($request_id) {
    try {
        // Check if request belongs to user and is pending
        $stmt = $pdo->prepare("
            UPDATE permissions
            SET status = 'cancelled'
            WHERE id = ? AND staff_id = ? AND school_id = ? AND status = 'pending'
        ");
        $stmt->execute([$request_id, $user_id, $current_school_id]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = "Request cancelled successfully!";
        } else {
            $_SESSION['error'] = "Unable to cancel request. It may have already been processed.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error cancelling request: " . $e->getMessage();
    }
}

header('Location: permissions.php');
exit();
?>
