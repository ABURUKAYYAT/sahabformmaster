<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
$current_school_id = require_school_auth();

$question_id = intval($_GET['id'] ?? 0);

if ($question_id <= 0) {
    header("Location: questions.php");
    exit;
}

// Fetch question with all details - school-filtered
$stmt = $pdo->prepare("
    SELECT q.*, s.subject_name, c.class_name, cat.category_name,
           u.full_name as created_by_name, r.full_name as reviewed_by_name
    FROM questions_bank q
    LEFT JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN classes c ON q.class_id = c.id
    LEFT JOIN question_categories cat ON q.category_id = cat.id
    LEFT JOIN users u ON q.created_by = u.id
    LEFT JOIN users r ON q.reviewed_by = r.id
    WHERE q.id = ? AND q.school_id = ?
");
$stmt->execute([$question_id, $current_school_id]);
$question = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$question) {
    header("Location: questions.php");
    exit;
}

// Fetch options for MCQ
$options = [];
if ($question['question_type'] === 'mcq') {
    $stmt = $pdo->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY option_letter");
    $stmt->execute([$question_id]);
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch tags
$stmt = $pdo->prepare("SELECT tag_name FROM question_tags WHERE question_id = ?");
$stmt->execute([$question_id]);
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch usage history
$stmt = $pdo->prepare("
    SELECT u.*, e.exam_name 
    FROM question_usage u 
    LEFT JOIN exams e ON u.exam_id = e.id 
    WHERE u.question_id = ? 
    ORDER BY u.used_at DESC 
    LIMIT 5
");
$stmt->execute([$question_id]);
$usage_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Details - <?php echo htmlspecialchars($question['question_code']); ?></title>
    <link rel="stylesheet" href="../assets/css/admin-students.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Similar detailed view structure as student_details.php -->
    <!-- Implementation would show complete question details -->
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
