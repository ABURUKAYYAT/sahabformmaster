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
$message = '';
$error = '';

if (!empty($_SESSION['cbt_message'])) {
    $message = (string)$_SESSION['cbt_message'];
    unset($_SESSION['cbt_message']);
}
if (!empty($_SESSION['cbt_error'])) {
    $error = (string)$_SESSION['cbt_error'];
    unset($_SESSION['cbt_error']);
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

$student_stmt = $pdo->prepare("SELECT class_id, full_name FROM students WHERE id = ? AND school_id = ?");
$student_stmt->execute([$student_id, $current_school_id]);
$student = $student_stmt->fetch();

if (!$student) {
    $student_stmt = $pdo->prepare("SELECT class_id, full_name, school_id FROM students WHERE id = ? LIMIT 1");
    $student_stmt->execute([$student_id]);
    $student = $student_stmt->fetch();
    if (!$student) {
        header("Location: index.php");
        exit;
    }
    if ($current_school_id === false && !empty($student['school_id'])) {
        $_SESSION['school_id'] = $student['school_id'];
        $current_school_id = $student['school_id'];
    }
}

$class_id = (int)($student['class_id'] ?? 0);

$tests_stmt = $pdo->prepare("
    SELECT
        t.id,
        t.title,
        t.duration_minutes,
        t.starts_at,
        t.ends_at,
        t.status,
        t.created_at,
        s.subject_name,
        COALESCE(qc.question_count, 0) AS question_count,
        a.id AS attempt_id,
        a.status AS attempt_status,
        a.score,
        a.total_questions,
        a.submitted_at,
        a.started_at
    FROM cbt_tests t
    JOIN subjects s ON t.subject_id = s.id
    LEFT JOIN (
        SELECT test_id, COUNT(*) AS question_count
        FROM cbt_questions
        GROUP BY test_id
    ) qc ON qc.test_id = t.id
    LEFT JOIN cbt_attempts a ON a.test_id = t.id AND a.student_id = ?
    WHERE t.school_id = ?
      AND t.class_id = ?
      AND t.status = 'published'
    ORDER BY COALESCE(t.starts_at, t.created_at) DESC, t.id DESC
");
$tests_stmt->execute([$student_id, $current_school_id, $class_id]);
$tests = $tests_stmt->fetchAll();

$nowTs = time();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CBT Tests</title>
    <link rel="stylesheet" href="../assets/css/student-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<header class="dashboard-header">
    <div class="header-container">
        <div class="header-left">
            <div class="school-logo-container">
                <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                <div class="school-info">
                    <h1 class="school-name">SahabFormMaster</h1>
                    <p class="school-tagline">CBT Tests</p>
                </div>
            </div>
        </div>
        <div class="header-right">
            <div class="student-info">
                <p class="student-label">Student</p>
                <span class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></span>
            </div>
            <a href="logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <?php include '../includes/student_sidebar.php'; ?>
    <main class="main-content">
        <div class="content-header">
            <div class="welcome-section">
                <h2>CBT Tests</h2>
                <p>View scheduled tests, take active tests, and track your scores.</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success" style="margin-bottom: 1rem;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom: 1rem;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="modern-card">
            <div class="card-body-modern">
                <?php if (count($tests) === 0): ?>
                    <p>No published CBT tests for your class yet.</p>
                <?php else: ?>
                    <table class="table-modern">
                        <thead>
                        <tr>
                            <th>Title</th>
                            <th>Subject</th>
                            <th>Schedule</th>
                            <th>Duration</th>
                            <th>Questions</th>
                            <th>Status</th>
                            <th>Score</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tests as $t): ?>
                            <?php
                                $startTs = !empty($t['starts_at']) ? strtotime($t['starts_at']) : null;
                                $endTs = !empty($t['ends_at']) ? strtotime($t['ends_at']) : null;
                                $hasQuestions = ((int)$t['question_count']) > 0;
                                $attemptStatus = (string)($t['attempt_status'] ?? '');
                                $isSubmitted = ($attemptStatus === 'submitted');
                                $isInProgress = ($attemptStatus === 'in_progress');
                                $isScheduled = ($startTs !== null && $startTs > $nowTs);
                                $isClosedByTime = ($endTs !== null && $endTs < $nowTs);
                                $isAvailable = !$isSubmitted && !$isScheduled && !$isClosedByTime && $hasQuestions;

                                if ($isSubmitted) {
                                    $statusText = 'Completed';
                                } elseif (!$hasQuestions) {
                                    $statusText = 'Not Ready';
                                } elseif ($isScheduled) {
                                    $statusText = 'Scheduled';
                                } elseif ($isClosedByTime) {
                                    $statusText = 'Closed';
                                } elseif ($isInProgress) {
                                    $statusText = 'In Progress';
                                } else {
                                    $statusText = 'Available';
                                }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($t['title']); ?></td>
                                <td><?php echo htmlspecialchars($t['subject_name']); ?></td>
                                <td>
                                    <?php if (!empty($t['starts_at'])): ?>
                                        <?php echo date('M d, Y H:i', strtotime($t['starts_at'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Immediate</span>
                                    <?php endif; ?>
                                    <?php if (!empty($t['ends_at'])): ?>
                                        <br><small>to <?php echo date('M d, Y H:i', strtotime($t['ends_at'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$t['duration_minutes']; ?> mins</td>
                                <td><?php echo (int)$t['question_count']; ?></td>
                                <td><?php echo htmlspecialchars($statusText); ?></td>
                                <td>
                                    <?php if ($isSubmitted): ?>
                                        <?php echo (int)$t['score']; ?> / <?php echo (int)$t['total_questions']; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isAvailable): ?>
                                        <a class="btn btn-sm btn-primary" href="cbt_take.php?id=<?php echo (int)$t['id']; ?>">
                                            <?php echo $isInProgress ? 'Resume' : 'Start'; ?>
                                        </a>
                                    <?php elseif ($isSubmitted): ?>
                                        <span class="text-muted">Submitted</span>
                                    <?php elseif ($isScheduled): ?>
                                        <span class="text-muted">Wait for start</span>
                                    <?php elseif (!$hasQuestions): ?>
                                        <span class="text-muted">Teacher preparing</span>
                                    <?php else: ?>
                                        <span class="text-muted">Unavailable</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
</body>
</html>
