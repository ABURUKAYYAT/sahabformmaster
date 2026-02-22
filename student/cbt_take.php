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
$test_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch test
$test_stmt = $pdo->prepare("
    SELECT t.*, s.subject_name
    FROM cbt_tests t
    JOIN subjects s ON t.subject_id = s.id
    WHERE t.id = ? AND t.school_id = ? AND t.status = 'published'
");
$test_stmt->execute([$test_id, $current_school_id]);
$test = $test_stmt->fetch();

if (!$test) {
    header("Location: cbt_tests.php");
    exit;
}

// Check if attempt exists
$attempt_stmt = $pdo->prepare("SELECT * FROM cbt_attempts WHERE test_id = ? AND student_id = ?");
$attempt_stmt->execute([$test_id, $student_id]);
$attempt = $attempt_stmt->fetch();

if (!$attempt) {
    $create_attempt = $pdo->prepare("INSERT INTO cbt_attempts (test_id, student_id, total_questions) VALUES (?, ?, 0)");
    $create_attempt->execute([$test_id, $student_id]);
    $attempt_id = $pdo->lastInsertId();
} else {
    $attempt_id = $attempt['id'];
    if ($attempt['status'] === 'submitted') {
        header("Location: cbt_tests.php");
        exit;
    }
}

$questions_stmt = $pdo->prepare("SELECT * FROM cbt_questions WHERE test_id = ? ORDER BY id ASC");
$questions_stmt->execute([$test_id]);
$questions = $questions_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take CBT</title>
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
        let duration = <?php echo intval($test['duration_minutes']); ?> * 60;
        function startTimer() {
            const el = document.getElementById('timer');
            const tick = () => {
                if (duration <= 0) {
                    document.getElementById('cbt-form').submit();
                    return;
                }
                const m = Math.floor(duration / 60);
                const s = duration % 60;
                el.textContent = `${m}:${s.toString().padStart(2, '0')}`;
                duration -= 1;
            };
            setInterval(tick, 1000);
            tick();
        }
        window.onload = startTimer;
    </script>
</head>
<body>
<header class="dashboard-header">
    <div class="header-container">
        <div class="header-left">
            <div class="school-logo-container">
                <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                <div class="school-info">
                    <h1 class="school-name"><?php echo htmlspecialchars($test['title']); ?></h1>
                    <p class="school-tagline"><?php echo htmlspecialchars($test['subject_name']); ?></p>
                </div>
            </div>
        </div>
        <div class="header-right">
            <div class="student-info">
                <p class="student-label">Time Left</p>
                <span class="student-name" id="timer"></span>
            </div>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <?php include '../includes/student_sidebar.php'; ?>
    <main class="main-content">
        <form id="cbt-form" method="POST" action="cbt_submit.php">
            <input type="hidden" name="test_id" value="<?php echo $test_id; ?>">
            <input type="hidden" name="attempt_id" value="<?php echo $attempt_id; ?>">

            <?php foreach ($questions as $index => $q): ?>
                <div class="modern-card" style="margin-bottom: 1.5rem;">
                    <div class="card-body-modern">
                        <h4>Q<?php echo $index + 1; ?>. <?php echo htmlspecialchars($q['question_text']); ?></h4>
                        <div>
                            <label><input type="radio" name="answers[<?php echo $q['id']; ?>]" value="A"> A. <?php echo htmlspecialchars($q['option_a']); ?></label><br>
                            <label><input type="radio" name="answers[<?php echo $q['id']; ?>]" value="B"> B. <?php echo htmlspecialchars($q['option_b']); ?></label><br>
                            <label><input type="radio" name="answers[<?php echo $q['id']; ?>]" value="C"> C. <?php echo htmlspecialchars($q['option_c']); ?></label><br>
                            <label><input type="radio" name="answers[<?php echo $q['id']; ?>]" value="D"> D. <?php echo htmlspecialchars($q['option_d']); ?></label>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <button type="submit" class="btn btn-primary">Submit</button>
        </form>
    </main>
</div>
</body>
</html>
