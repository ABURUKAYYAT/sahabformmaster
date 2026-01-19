<?php
// teacher/logout.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

// School authentication and context
$current_school_id = require_school_auth();

// Destroy session after all session operations
session_destroy();
header("Location: ../index.php");
exit;
?>
