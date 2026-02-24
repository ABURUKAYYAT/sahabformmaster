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
$test_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($current_school_id === false) {
    $school_stmt = $pdo->prepare("SELECT school_id FROM students WHERE id = ? LIMIT 1");
    $school_stmt->execute([$student_id]);
    $resolved_school_id = $school_stmt->fetchColumn();
    if ($resolved_school_id !== false) {
        $_SESSION['school_id'] = $resolved_school_id;
        $current_school_id = $resolved_school_id;
    }
}

$student_stmt = $pdo->prepare("SELECT class_id, full_name FROM students WHERE id = ? AND school_id = ?");
$student_stmt->execute([$student_id, $current_school_id]);
$student = $student_stmt->fetch();
if (!$student) {
    $_SESSION['cbt_error'] = 'Student profile not found for CBT.';
    header("Location: cbt_tests.php");
    exit;
}

$class_id = (int)$student['class_id'];

$test_stmt = $pdo->prepare("
    SELECT t.*, s.subject_name
    FROM cbt_tests t
    JOIN subjects s ON t.subject_id = s.id
    WHERE t.id = ?
      AND t.school_id = ?
      AND t.class_id = ?
      AND t.status = 'published'
    LIMIT 1
");
$test_stmt->execute([$test_id, $current_school_id, $class_id]);
$test = $test_stmt->fetch();

if (!$test) {
    $_SESSION['cbt_error'] = 'Test not found or not available for your class.';
    header("Location: cbt_tests.php");
    exit;
}

$nowTs = time();
$startTs = !empty($test['starts_at']) ? strtotime($test['starts_at']) : null;
$endTs = !empty($test['ends_at']) ? strtotime($test['ends_at']) : null;

if ($startTs !== null && $nowTs < $startTs) {
    $_SESSION['cbt_error'] = 'This test has not started yet.';
    header("Location: cbt_tests.php");
    exit;
}

if ($endTs !== null && $nowTs > $endTs) {
    $_SESSION['cbt_error'] = 'This test is closed.';
    header("Location: cbt_tests.php");
    exit;
}

$questions_stmt = $pdo->prepare("SELECT * FROM cbt_questions WHERE test_id = ? ORDER BY question_order ASC, id ASC");
$questions_stmt->execute([$test_id]);
$questions = $questions_stmt->fetchAll();

if (empty($questions)) {
    $_SESSION['cbt_error'] = 'No questions available for this test yet.';
    header("Location: cbt_tests.php");
    exit;
}

$attempt_stmt = $pdo->prepare("SELECT * FROM cbt_attempts WHERE test_id = ? AND student_id = ? LIMIT 1");
$attempt_stmt->execute([$test_id, $student_id]);
$attempt = $attempt_stmt->fetch();

if (!$attempt) {
    $create_attempt = $pdo->prepare("
        INSERT INTO cbt_attempts (test_id, student_id, total_questions, started_at, status)
        VALUES (?, ?, ?, NOW(), 'in_progress')
    ");
    $create_attempt->execute([$test_id, $student_id, count($questions)]);
    $attempt_id = (int)$pdo->lastInsertId();

    $attempt_stmt->execute([$test_id, $student_id]);
    $attempt = $attempt_stmt->fetch();
} else {
    $attempt_id = (int)$attempt['id'];
    if (($attempt['status'] ?? '') === 'submitted') {
        $_SESSION['cbt_message'] = 'You have already submitted this test.';
        header("Location: cbt_tests.php");
        exit;
    }

    $update_attempt = $pdo->prepare("UPDATE cbt_attempts SET total_questions = ? WHERE id = ? AND status = 'in_progress'");
    $update_attempt->execute([count($questions), $attempt_id]);
}

$saved_answers = [];
$ans_stmt = $pdo->prepare("SELECT question_id, selected_option FROM cbt_answers WHERE attempt_id = ?");
$ans_stmt->execute([$attempt_id]);
foreach ($ans_stmt->fetchAll() as $row) {
    $saved_answers[(int)$row['question_id']] = $row['selected_option'];
}

$duration_seconds = max(60, ((int)$test['duration_minutes']) * 60);
$started_at_ts = !empty($attempt['started_at']) ? strtotime($attempt['started_at']) : $nowTs;
if ($started_at_ts === false) {
    $started_at_ts = $nowTs;
}
$elapsed_seconds = max(0, $nowTs - $started_at_ts);
$remaining_seconds = $duration_seconds - $elapsed_seconds;

if ($remaining_seconds <= 0) {
    $_SESSION['cbt_error'] = 'Your CBT time has elapsed. Please submit from the test list.';
    header("Location: cbt_tests.php");
    exit;
}
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
        let duration = <?php echo (int)$remaining_seconds; ?>;
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
            <input type="hidden" name="test_id" value="<?php echo (int)$test_id; ?>">
            <input type="hidden" name="attempt_id" value="<?php echo (int)$attempt_id; ?>">

            <?php foreach ($questions as $index => $q): ?>
                <?php $qid = (int)$q['id']; ?>
                <div class="modern-card" style="margin-bottom: 1.5rem;">
                    <div class="card-body-modern">
                        <h4>Q<?php echo $index + 1; ?>. <?php echo htmlspecialchars($q['question_text']); ?></h4>
                        <div>
                            <label><input type="radio" name="answers[<?php echo $qid; ?>]" value="A" <?php echo (($saved_answers[$qid] ?? '') === 'A') ? 'checked' : ''; ?>> A. <?php echo htmlspecialchars($q['option_a']); ?></label><br>
                            <label><input type="radio" name="answers[<?php echo $qid; ?>]" value="B" <?php echo (($saved_answers[$qid] ?? '') === 'B') ? 'checked' : ''; ?>> B. <?php echo htmlspecialchars($q['option_b']); ?></label><br>
                            <label><input type="radio" name="answers[<?php echo $qid; ?>]" value="C" <?php echo (($saved_answers[$qid] ?? '') === 'C') ? 'checked' : ''; ?>> C. <?php echo htmlspecialchars($q['option_c']); ?></label><br>
                            <label><input type="radio" name="answers[<?php echo $qid; ?>]" value="D" <?php echo (($saved_answers[$qid] ?? '') === 'D') ? 'checked' : ''; ?>> D. <?php echo htmlspecialchars($q['option_d']); ?></label>
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
