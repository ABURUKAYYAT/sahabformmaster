<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$current_school_id = get_current_school_id();
$student_id = $_SESSION['student_id'];
$test_id = intval($_POST['test_id'] ?? 0);
$attempt_id = intval($_POST['attempt_id'] ?? 0);
$answers = $_POST['answers'] ?? [];

if ($test_id <= 0 || $attempt_id <= 0) {
    header("Location: cbt_tests.php");
    exit;
}

// Fetch questions
$qstmt = $pdo->prepare("SELECT id, correct_option FROM cbt_questions WHERE test_id = ?");
$qstmt->execute([$test_id]);
$questions = $qstmt->fetchAll();

$score = 0;
$total = count($questions);

foreach ($questions as $q) {
    $qid = $q['id'];
    $selected = $answers[$qid] ?? null;
    if ($selected === null) continue;
    $is_correct = ($selected === $q['correct_option']) ? 1 : 0;
    if ($is_correct) $score++;

    $ins = $pdo->prepare("
        INSERT INTO cbt_answers (attempt_id, question_id, selected_option, is_correct)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE selected_option = VALUES(selected_option), is_correct = VALUES(is_correct)
    ");
    $ins->execute([$attempt_id, $qid, $selected, $is_correct]);
}

$update = $pdo->prepare("
    UPDATE cbt_attempts
    SET score = ?, total_questions = ?, submitted_at = NOW(), status = 'submitted'
    WHERE id = ? AND student_id = ?
");
$update->execute([$score, $total, $attempt_id, $student_id]);

header("Location: cbt_tests.php");
exit;
