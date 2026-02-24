<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/cbt_helpers.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

ensure_cbt_schema($pdo);

$current_school_id = get_current_school_id();
$student_id = (int)$_SESSION['student_id'];
$test_id = (int)($_POST['test_id'] ?? 0);
$attempt_id = (int)($_POST['attempt_id'] ?? 0);
$answers = $_POST['answers'] ?? [];

if ($test_id <= 0 || $attempt_id <= 0) {
    $_SESSION['cbt_error'] = 'Invalid CBT submission.';
    header("Location: cbt_tests.php");
    exit;
}

if ($current_school_id === false) {
    $school_stmt = $pdo->prepare("SELECT school_id FROM students WHERE id = ? LIMIT 1");
    $school_stmt->execute([$student_id]);
    $resolved_school_id = $school_stmt->fetchColumn();
    if ($resolved_school_id !== false) {
        $_SESSION['school_id'] = $resolved_school_id;
        $current_school_id = $resolved_school_id;
    }
}

$student_stmt = $pdo->prepare("SELECT class_id FROM students WHERE id = ? AND school_id = ? LIMIT 1");
$student_stmt->execute([$student_id, $current_school_id]);
$student = $student_stmt->fetch();
if (!$student) {
    $_SESSION['cbt_error'] = 'Student record not found.';
    header("Location: cbt_tests.php");
    exit;
}

$class_id = (int)$student['class_id'];

$attempt_stmt = $pdo->prepare("
    SELECT
        a.id,
        a.status AS attempt_status,
        a.started_at,
        t.id AS test_id,
        t.class_id,
        t.school_id,
        t.status AS test_status,
        t.duration_minutes,
        t.starts_at,
        t.ends_at
    FROM cbt_attempts a
    JOIN cbt_tests t ON t.id = a.test_id
    WHERE a.id = ?
      AND a.test_id = ?
      AND a.student_id = ?
      AND t.school_id = ?
    LIMIT 1
");
$attempt_stmt->execute([$attempt_id, $test_id, $student_id, $current_school_id]);
$attempt = $attempt_stmt->fetch();

if (!$attempt) {
    $_SESSION['cbt_error'] = 'Attempt not found for this student/test.';
    header("Location: cbt_tests.php");
    exit;
}

if ((int)$attempt['class_id'] !== $class_id) {
    $_SESSION['cbt_error'] = 'You are not allowed to submit this CBT.';
    header("Location: cbt_tests.php");
    exit;
}

if ($attempt['attempt_status'] === 'submitted') {
    $_SESSION['cbt_message'] = 'This test was already submitted.';
    header("Location: cbt_tests.php");
    exit;
}

if ($attempt['test_status'] !== 'published') {
    $_SESSION['cbt_error'] = 'This test is no longer available.';
    header("Location: cbt_tests.php");
    exit;
}

$startTs = !empty($attempt['starts_at']) ? strtotime($attempt['starts_at']) : null;
if ($startTs !== null && time() < $startTs) {
    $_SESSION['cbt_error'] = 'This test has not started.';
    header("Location: cbt_tests.php");
    exit;
}

$qstmt = $pdo->prepare("SELECT id, correct_option FROM cbt_questions WHERE test_id = ?");
$qstmt->execute([$test_id]);
$questions = $qstmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($questions)) {
    $_SESSION['cbt_error'] = 'No questions found for this test.';
    header("Location: cbt_tests.php");
    exit;
}

$score = 0;
$total = count($questions);
$validOptions = ['A', 'B', 'C', 'D'];

try {
    $pdo->beginTransaction();

    $deleteOld = $pdo->prepare("DELETE FROM cbt_answers WHERE attempt_id = ?");
    $deleteOld->execute([$attempt_id]);

    $ins = $pdo->prepare("
        INSERT INTO cbt_answers (attempt_id, question_id, selected_option, is_correct)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($questions as $q) {
        $qid = (int)$q['id'];
        $selected = strtoupper((string)($answers[$qid] ?? ''));
        if (!in_array($selected, $validOptions, true)) {
            continue;
        }

        $is_correct = ($selected === strtoupper((string)$q['correct_option'])) ? 1 : 0;
        if ($is_correct === 1) {
            $score++;
        }
        $ins->execute([$attempt_id, $qid, $selected, $is_correct]);
    }

    $update = $pdo->prepare("
        UPDATE cbt_attempts
        SET score = ?, total_questions = ?, submitted_at = NOW(), status = 'submitted', updated_at = NOW()
        WHERE id = ? AND student_id = ?
    ");
    $update->execute([$score, $total, $attempt_id, $student_id]);

    $pdo->commit();
    $_SESSION['cbt_message'] = "CBT submitted. Score: {$score}/{$total}.";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['cbt_error'] = 'Failed to submit CBT: ' . $e->getMessage();
}

header("Location: cbt_tests.php");
exit;
