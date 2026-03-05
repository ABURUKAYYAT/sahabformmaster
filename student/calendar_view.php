<?php
session_start();

$student_id = $_SESSION['student_id'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;
$admission_no = $_SESSION['admission_no'] ?? null;

if (!$student_id && !$user_id && !$admission_no) {
    header('Location: index.php');
    exit;
}

header('Location: school_diary.php#calendar-panel');
exit;
