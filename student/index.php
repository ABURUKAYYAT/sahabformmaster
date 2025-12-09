<?php
// student/index.php
session_start();
require_once '../config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $admission_number = trim($_POST['admission_number']);
    $full_name = $_POST['student_name'];
 
    if (empty($admission_number) || empty($full_name)) {
        $error = "Please enter both admission number and name.";
    } else {
        // Prepare SQL to find student
        $stmt = $pdo->prepare("SELECT * FROM students WHERE admission_number = :admission_number");
        $stmt->execute(['admission_number' => $admission_number]);
        $student = $stmt->fetch();

        // Verify student exists and name matches
        if ($student && $full_name === $student['full_name']) {
            // Login Success
            $_SESSION['student_id'] = $student['id'];
            $_SESSION['admission_number'] = $student['admission_number'];
            $_SESSION['student_name'] = $student['name'];

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
    <title>Student Login | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

    <div class="login-container">
        <div class="login-header">
            <h2>SahabFormMaster</h2>
            <p>Student Portal</p>
        </div>

        <?php if($error): ?>
            <div class="alert">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form action="index.php" method="POST">
            <div class="form-group">
                <label for="admission_number">Admission Number</label>
                <input type="text" name="admission_number" id="admission_number" class="form-control" placeholder="Enter your admission number" required>
            </div>

            <div class="form-group">
                <label for="student_name">Full Name</label>
                <input type="text" name="student_name" id="student_name" class="form-control" placeholder="Enter your full name" required>
            </div>

            <button type="submit" class="btn-gold">Login</button>
        </form>

        <a href="../index.php" class="student-link">Are you a Staff Member? Click here to Login</a>
    </div>

</body>
</html>