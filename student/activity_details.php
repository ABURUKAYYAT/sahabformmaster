<?php
session_start();

$student_id = $_SESSION['student_id'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;
$admission_no = $_SESSION['admission_no'] ?? null;

if (!$student_id && !$user_id && !$admission_no) {
    header('Location: index.php');
    exit;
}

$activity_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$params = [];
if ($activity_id > 0) {
    $params['id'] = $activity_id;
    $params['view'] = 'details';
}

$target = 'school_diary.php' . (!empty($params) ? '?' . http_build_query($params) : '');
header('Location: ' . $target);
exit;
