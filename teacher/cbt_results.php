<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/cbt_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();
ensure_cbt_schema($pdo);
$teacher_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT a.*, t.title, s.full_name, c.class_name, subj.subject_name
    FROM cbt_attempts a
    JOIN cbt_tests t ON a.test_id = t.id
    JOIN students s ON a.student_id = s.id
    JOIN classes c ON t.class_id = c.id
    JOIN subjects subj ON t.subject_id = subj.id
    WHERE t.teacher_id = ? AND t.school_id = ?
      AND a.status = 'submitted'
    ORDER BY a.submitted_at DESC
");
$stmt->execute([$teacher_id, $current_school_id]);
$attempts = $stmt->fetchAll();
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';
$format_datetime = static function (?string $value, string $format = 'd M Y, h:i A'): string {
    if (empty($value)) {
        return '-';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '-';
    }

    return date($format, $timestamp);
};
$get_score_badge_class = static function (float $percent): string {
    if ($percent >= 70) {
        return 'is-high';
    }

    if ($percent >= 50) {
        return 'is-mid';
    }

    return 'is-low';
};

$attempt_count = count($attempts);
$student_ids = [];
$test_ids = [];
$total_percent = 0.0;
$highest_percent = 0.0;
$highest_score_label = 'No submissions yet';

foreach ($attempts as &$attempt) {
    $total_questions = (int)($attempt['total_questions'] ?? 0);
    $score = (int)($attempt['score'] ?? 0);
    $attempt['percent'] = $total_questions > 0 ? round(($score / $total_questions) * 100, 1) : 0.0;
    $student_ids[] = (int)($attempt['student_id'] ?? 0);
    $test_ids[] = (int)($attempt['test_id'] ?? 0);
    $total_percent += $attempt['percent'];

    if ($attempt['percent'] >= $highest_percent) {
        $highest_percent = $attempt['percent'];
        $highest_score_label = trim((string)($attempt['full_name'] ?? '')) !== ''
            ? $attempt['full_name'] . ' - ' . $attempt['percent'] . '%'
            : $attempt['percent'] . '%';
    }
}
unset($attempt);

$average_percent = $attempt_count > 0 ? round($total_percent / $attempt_count, 1) : 0.0;
$student_count = count(array_unique(array_filter($student_ids)));
$test_count = count(array_unique(array_filter($test_ids)));
$latest_submission = $attempts[0]['submitted_at'] ?? null;
?>
<?php include '../includes/teacher_cbt_results_page.php'; ?>
