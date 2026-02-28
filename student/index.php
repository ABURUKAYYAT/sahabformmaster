<?php
// student/index.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $admission_no = trim($_POST['admission_no']);
    $full_name = $_POST['student_name'];
 
    if (empty($admission_no) || empty($full_name)) {
        $error = "Please enter both admission number and name.";
    } else {
        // Prepare SQL to find student
        $stmt = $pdo->prepare("SELECT * FROM students WHERE admission_no = :admission_no");
        $stmt->execute(['admission_no' => $admission_no]);
        $student = $stmt->fetch();

        // Verify student exists and name matches
        if ($student && $full_name === $student['full_name']) {
            // Login Success
            $_SESSION['student_id'] = $student['id'];
            $_SESSION['admission_no'] = $student['admission_no'];
            $_SESSION['student_name'] = $student['name'];
            $_SESSION['school_id'] = $student['school_id'];

            // Redirect to student dashboard
            header("Location: dashboard.php");
            exit;
        } else {
            $error = "Invalid admission number or name.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="landing">
    <main class="auth-shell">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-brand">
                    <span class="brand-mark">iS</span>
                    <span class="brand-text">iSchool</span>
                </div>
                <h1 class="auth-title">Student Access</h1>
                <p class="auth-subtitle">Sign in to view your academic dashboard.</p>
            </div>

            <?php if($error): ?>
                <div class="auth-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form action="index.php" method="POST" class="auth-form">
                <div class="auth-field">
                    <label for="admission_no">Admission Number</label>
                    <input type="text" name="admission_no" id="admission_no" class="auth-input" placeholder="Enter your admission number" required autofocus>
                </div>

                <div class="auth-field">
                    <label for="student_name">Full Name</label>
                    <input type="text" name="student_name" id="student_name" class="auth-input" placeholder="Enter your full name" required>
                </div>

                <button type="submit" class="btn btn-primary w-full">Login securely</button>
            </form>

            <div class="auth-footer">
                <a href="../login.php" class="auth-link">Staff login</a>
                <span> &middot; </span>
                <a href="../index.php" class="auth-link">Back to homepage</a>
            </div>
        </div>
    </main>
</body>
</html>
