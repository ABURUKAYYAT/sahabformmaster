<?php
// index.php
session_start();
require_once 'config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        // Prepare SQL to find user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        // Verify user exists and password is correct
        if ($user && $password === $user['password']) {
            // Login Success
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];

            // Redirect based on Role
            if ($user['role'] === 'principal') {
                header("Location: admin/index.php");
            } else {
                header("Location: teacher/index.php");
            }
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | SahabFormMaster</title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>

    <div class="login-container">
        <div class="login-header">
            <h2>SahabFormMaster</h2>
            <p>Staff Access Portal</p>
        </div>

        <?php if($error): ?>
            <div class="alert">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form action="index.php" method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" id="username" class="form-control" placeholder="Enter username" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="Enter password" required>
            </div>

            <button type="submit" class="btn-gold">Login Securely</button>
        </form>

        <a href="student/index.php" class="student-link">Are you a Student? Click here to Login</a>
    </div>

</body>
</html>