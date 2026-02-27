<?php
// index.php
session_start();

// Load security framework
define('SECURE_ACCESS', true);
require_once 'includes/security.php';
require_once 'config/db.php';

// Check session timeout
if (!Security::checkSessionTimeout()) {
    header("Location: logout.php");
    exit;
}

$error = '';
$csrf_token = Security::generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed. Please refresh the page.";
        Security::logSecurityEvent('csrf_violation', ['action' => 'login_attempt']);
    } else {
        $username = Security::sanitizeString($_POST['username'] ?? '', 100);
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = "Please enter both username and password.";
        } else {
            // Check rate limiting
            $rateLimit = Security::checkLoginAttempts($username);
            if (!$rateLimit['allowed']) {
                $error = "Too many login attempts. Please wait " . ($rateLimit['wait_time'] / 60) . " minutes.";
                Security::logSecurityEvent('rate_limit_exceeded', ['username' => $username]);
            } else {
                try {
                    // Prepare SQL to find user
                    $stmt = $pdo->prepare("SELECT id, username, password, role, full_name, school_id, email, is_active FROM users WHERE username = :username AND is_active = 1");
                    $stmt->execute(['username' => $username]);
                    $user = $stmt->fetch();

                    // Verify user exists and password is correct
                    if ($user && password_verify($password, $user['password'])) {
                        // Reset login attempts on successful login
                        Security::resetLoginAttempts($username);

                        // Login Success
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['school_id'] = $user['school_id'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['last_activity'] = time();

                        // Regenerate session for security
                        Security::regenerateSession();

                        Security::logSecurityEvent('successful_login', ['user_id' => $user['id'], 'role' => $user['role']]);

                        // Redirect based on Role
                        if ($user['role'] === 'principal') {
                            header("Location: admin/index.php");
                        } elseif ($user['role'] === 'teacher') {
                            header("Location: teacher/index.php");
                        } elseif ($user['role'] === 'clerk') {
                            header("Location: clerk/index.php");
                        } elseif ($user['role'] === 'student') {
                            header("Location: student/index.php");
                        } else {
                            $error = "Invalid user role.";
                        }
                        exit;
                    } else {
                        $error = "Invalid username or password.";
                        Security::logSecurityEvent('failed_login', ['username' => $username]);
                    }
                } catch (Exception $e) {
                    $error = "System error. Please try again later.";
                    Security::logSecurityEvent('login_error', ['error' => $e->getMessage()]);
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | iSchool</title>
    <link rel="stylesheet" href="assets/css/tailwind.css">
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
                <h1 class="auth-title">Staff Access</h1>
                <p class="auth-subtitle">Sign in to manage your school operations.</p>
            </div>

            <?php if($error): ?>
                <div class="auth-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="auth-field">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" class="auth-input" placeholder="Enter your username" required autofocus>
                </div>

                <div class="auth-field">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" class="auth-input" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn btn-primary w-full">Login securely</button>
            </form>

            <div class="auth-footer">
                <a href="student/index.php" class="auth-link">Student login</a>
                <span> · </span>
                <a href="index.php" class="auth-link">Back to homepage</a>
            </div>
        </div>
    </main>
</body>
</html>
