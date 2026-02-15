<?php
session_start();
require_once '../config/db.php';

$current_school_id = get_current_school_id();

$uid = $_SESSION['user_id'] ?? null;
$role = strtolower($_SESSION['role'] ?? '');
$admission_no = $_SESSION['admission_no'] ?? null;
$full_name = $_SESSION['full_name'] ?? null;

// If no identifying session data, redirect to login
if (!$uid && !$admission_no) {
    header("Location: ../index.php");
    exit;
}

// If role is present but not student, redirect to their dashboard
if ($role && $role !== 'student') {
    if ($role === 'teacher') {
        header("Location: ../teacher/subjects.php");
        exit;
    } elseif ($role === 'principal' || $role === 'admin') {
        header("Location: ../admin/subjects.php");
        exit;
    } else {
        header("Location: ../index.php");
        exit;
    }
}

// Resolve student record using admission_no first, fallback to user_id/id
$student = null;
if ($admission_no) {
    $stmt = $pdo->prepare("SELECT id, class_id, admission_no FROM students WHERE admission_no = :admission_no AND school_id = :school_id LIMIT 1");
    $stmt->execute(['admission_no' => $admission_no, 'school_id' => $current_school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$student && $uid) {
    $stmt = $pdo->prepare("SELECT id, class_id, admission_no FROM students WHERE (user_id = :uid OR id = :uid) AND school_id = :school_id LIMIT 1");
    $stmt->execute(['uid' => $uid, 'school_id' => $current_school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$student) {
    // No student mapping found — show friendly message and stop
    die("Student record not found. Please contact administration.");
}

$student_id = $student['id'];
$class_id = $student['class_id'];

// Fetch subjects assigned to that class
$stmt = $pdo->prepare("SELECT s.id, s.subject_name, s.subject_code, u.full_name AS teacher_name
    FROM subject_assignments sa
    JOIN subjects s ON sa.subject_id = s.id
    LEFT JOIN users u ON sa.teacher_id = u.id
    WHERE sa.class_id = :class_id AND sa.school_id = :school_id
    ORDER BY s.subject_name");
$stmt->execute(['class_id' => $class_id, 'school_id' => $current_school_id]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>My Subjects - Student</title>
<link rel="stylesheet" href="../assets/css/subjects.css">
</head>
<body>
<main class="main-content">
    <h2>My Subjects</h2>
    <p>Class: <?php
        $c = $pdo->prepare("SELECT class_name FROM classes WHERE id = :id AND school_id = :school_id");
        $c->execute(['id' => $class_id, 'school_id' => $current_school_id]);
        echo htmlspecialchars($c->fetchColumn() ?: 'N/A');
    ?></p>

    <?php if(empty($subjects)): ?>
        <div class="alert alert-info">No subjects assigned to your class yet.</div>
    <?php else: ?>
        <table class="results-table">
            <thead><tr><th>#</th><th>Subject</th><th>Code</th><th>Teacher</th></tr></thead>
            <tbody>
            <?php foreach($subjects as $s): ?>
                <tr>
                    <td><?php echo intval($s['id']); ?></td>
                    <td><?php echo htmlspecialchars($s['subject_name']); ?></td>
                    <td><?php echo htmlspecialchars($s['subject_code']); ?></td>
                    <td><?php echo htmlspecialchars($s['teacher_name'] ?? 'Unassigned'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
