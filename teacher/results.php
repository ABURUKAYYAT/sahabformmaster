<?php
// filepath: c:\xampp\htdocs\sahabformmaster\teacher\results.php
session_start();
require_once '../config/db.php';

// Only allow class teachers to access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];
$class_id = $_GET['id'] ?? null; // Class ID should be passed via URL
$term = $_GET['term'] ?? 'First Term'; // Default term
$errors = [];
$success = '';

// Validate class_id
if (!$class_id || !is_numeric($class_id)) {
    die("Invalid or missing class ID."); // Ensure class_id is valid
}

// Fetch class information
$stmt = $pdo->prepare("SELECT * FROM classes WHERE id = :id");
$stmt->execute(['id' => $class_id]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    header("Location: index.php?error=Class not found.");
    exit;
}

// Fetch students in the class
$stmt = $pdo->prepare("SELECT * FROM students WHERE class_id = :class_id ORDER BY full_name ASC");
$stmt->execute(['class_id' => $class_id]); // Corrected column name
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch subjects for the class
$stmt = $pdo->query("SELECT * FROM subjects ORDER BY subject_name ASC");
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add or Update Results
    if ($action === 'save_result') {
        $student_id = $_POST['student_id'] ?? null;
        $subject_id = $_POST['subject_id'] ?? null;
        $first_ca = floatval($_POST['first_ca'] ?? 0);
        $second_ca = floatval($_POST['second_ca'] ?? 0);
        $exam = floatval($_POST['exam'] ?? 0);

        if (!$student_id || !$subject_id) {
            $errors[] = "Student and subject are required.";
        }

        if (empty($errors)) {
            // Check if result already exists
            $stmt = $pdo->prepare("SELECT id FROM results WHERE student_id = :student_id AND subject_id = :subject_id AND term = :term");
            $stmt->execute([
                'student_id' => $student_id,
                'subject_id' => $subject_id,
                'term' => $term
            ]);
            $existing_result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_result) {
                // Update existing result
                $stmt = $pdo->prepare("UPDATE results SET first_ca = :first_ca, second_ca = :second_ca, exam = :exam WHERE id = :id");
                $stmt->execute([
                    'first_ca' => $first_ca,
                    'second_ca' => $second_ca,
                    'exam' => $exam,
                    'id' => $existing_result['id']
                ]);
                $success = "Result updated successfully.";
            } else {
                // Insert new result
                $stmt = $pdo->prepare("INSERT INTO results (student_id, subject_id, term, first_ca, second_ca, exam) VALUES (:student_id, :subject_id, :term, :first_ca, :second_ca, :exam)");
                $stmt->execute([
                    'student_id' => $student_id,
                    'subject_id' => $subject_id,
                    'term' => $term,
                    'first_ca' => $first_ca,
                    'second_ca' => $second_ca,
                    'exam' => $exam
                ]);
                $success = "Result added successfully.";
            }
        }
    }

    // Delete Result
    if ($action === 'delete_result') {
        $result_id = $_POST['result_id'] ?? null;
        if ($result_id) {
            $stmt = $pdo->prepare("DELETE FROM results WHERE id = :id");
            $stmt->execute(['id' => $result_id]);
            $success = "Result deleted successfully.";
        }
    }
}

// Fetch results for the class and term
$stmt = $pdo->prepare("SELECT r.*, s.full_name AS student_name, sub.subject_name 
                       FROM results r
                       JOIN students s ON r.student_id = s.id
                       JOIN subjects sub ON r.subject_id = sub.id
                       WHERE s.class_id = :class_id AND r.term = :term
                       ORDER BY s.full_name, sub.subject_name");
$stmt->execute(['class_id' => $class_id, 'term' => $term]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to calculate grade and remark
function calculateGrade($grand_total) {
    if ($grand_total >= 90) return ['grade' => 'A', 'remark' => 'Excellent'];
    if ($grand_total >= 80) return ['grade' => 'B', 'remark' => 'Very Good'];
    if ($grand_total >= 70) return ['grade' => 'C', 'remark' => 'Good'];
    if ($grand_total >= 60) return ['grade' => 'D', 'remark' => 'Fair'];
    if ($grand_total >= 50) return ['grade' => 'E', 'remark' => 'Pass'];
    return ['grade' => 'F', 'remark' => 'Fail'];
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Results | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/results.css">
</head>
<body>
<header class="dashboard-header">
    <div class="header-container">
        <h1>Manage Results</h1>
        <p>Class: <?php echo htmlspecialchars($class['class_name']); ?> | Term: <?php echo htmlspecialchars($term); ?></p>
    </div>
</header>

<div class="dashboard-container">
    <main class="main-content">
        <div class="content-header">
            <h2>Results for <?php echo htmlspecialchars($class['class_name']); ?></h2>
            <p class="small-muted">Manage student results for the selected class and term.</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Results Table -->
        <section class="results-section">
            <table class="results-table">
                <thead>
                    <tr>
                        <th>S/N</th>
                        <th>Student Name</th>
                        <th>Subject</th>
                        <th>First C.A.</th>
                        <th>Second C.A.</th>
                        <th>C.A. Total</th>
                        <th>Exam</th>
                        <th>Grand Total</th>
                        <th>Grade</th>
                        <th>Remark</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($results) === 0): ?>
                        <tr>
                            <td colspan="11" class="text-center">No results found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($results as $index => $result): 
                            $ca_total = $result['first_ca'] + $result['second_ca'];
                            $grand_total = $ca_total + $result['exam'];
                            $grade_data = calculateGrade($grand_total);
                        ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($result['subject_name']); ?></td>
                            <td><?php echo $result['first_ca']; ?></td>
                            <td><?php echo $result['second_ca']; ?></td>
                            <td><?php echo $ca_total; ?></td>
                            <td><?php echo $result['exam']; ?></td>
                            <td><?php echo $grand_total; ?></td>
                            <td><?php echo $grade_data['grade']; ?></td>
                            <td><?php echo $grade_data['remark']; ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_result">
                                    <input type="hidden" name="result_id" value="<?php echo $result['id']; ?>">
                                    <button type="submit" class="btn-delete">Delete</button>
                                </form>
                            </td>
                            <td>
                                <form method="POST" action="generate-result-pdf.php" style="display: inline;">
                                    <input type="hidden" name="student_id" value="<?php echo $result['student_id']; ?>">
                                    <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                                    <input type="hidden" name="term" value="<?php echo $term; ?>">
                                    <button type="submit" class="btn-pdf">Generate PDF</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>
</body>
</html>