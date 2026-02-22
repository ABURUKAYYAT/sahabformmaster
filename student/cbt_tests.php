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

// Resolve school_id if missing
if ($current_school_id === false) {
    $school_stmt = $pdo->prepare("SELECT school_id FROM students WHERE id = ? LIMIT 1");
    $school_stmt->execute([$student_id]);
    $resolved_school_id = $school_stmt->fetchColumn();
    if ($resolved_school_id !== false) {
        $_SESSION['school_id'] = $resolved_school_id;
        $current_school_id = $resolved_school_id;
    }
}

// Fetch student class
$student_stmt = $pdo->prepare("SELECT class_id, full_name FROM students WHERE id = ? AND school_id = ?");
$student_stmt->execute([$student_id, $current_school_id]);
$student = $student_stmt->fetch();
if (!$student) {
    // Fallback without school_id if data is inconsistent
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

$class_id = $student['class_id'];

$tests_stmt = $pdo->prepare("
    SELECT t.*, s.subject_name
    FROM cbt_tests t
    JOIN subjects s ON t.subject_id = s.id
    WHERE t.school_id = ?
      AND t.class_id = ?
      AND t.status = 'published'
      AND (t.starts_at IS NULL OR t.starts_at <= NOW())
      AND (t.ends_at IS NULL OR t.ends_at >= NOW())
    ORDER BY t.created_at DESC
");
$tests_stmt->execute([$current_school_id, $class_id]);
$tests = $tests_stmt->fetchAll();
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
                <p>Available tests for your class.</p>
            </div>
        </div>

        <div class="modern-card">
            <div class="card-body-modern">
                <?php if (count($tests) === 0): ?>
                    <p>No tests available right now.</p>
                <?php else: ?>
                    <table class="table-modern">
                        <thead>
                        <tr>
                            <th>Title</th>
                            <th>Subject</th>
                            <th>Duration</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tests as $t): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($t['title']); ?></td>
                                <td><?php echo htmlspecialchars($t['subject_name']); ?></td>
                                <td><?php echo intval($t['duration_minutes']); ?> mins</td>
                                <td>
                                    <a class="btn btn-sm btn-primary" href="cbt_take.php?id=<?php echo $t['id']; ?>">Start</a>
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
