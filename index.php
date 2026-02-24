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
    <title>Login | SahabFormMaster</title>
    <link rel="stylesheet" href="assets/css/education-theme-main.css">
            <style>
        :root {
            --fb-blue: #1877f2;
            --fb-blue-dark: #166fe5;
            --fb-bg: #f0f2f5;
            --text: #1c1e21;
            --muted: #606770;
            --border: #dddfe2;
            --card: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Helvetica, Arial, sans-serif;
            background: var(--fb-bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: var(--text);
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            background: var(--card);
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.12);
            overflow: hidden;
        }

        .login-header {
            padding: 24px 24px 12px;
            text-align: center;
            border-bottom: 1px solid var(--border);
            background: #fff;
        }

        .login-header h1,
        .login-header h2 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 6px;
        }

        .login-header p {
            margin: 0;
            font-size: 14px;
            color: var(--muted);
        }

        .login-body,
        .login-form {
            padding: 20px 24px 24px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            height: 48px;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 15px;
            background: #fff;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--fb-blue);
            box-shadow: 0 0 0 3px rgba(24, 119, 242, 0.15);
        }

        .btn-primary,
        .btn-login {
            width: 100%;
            height: 48px;
            background: var(--fb-blue);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-primary:hover,
        .btn-login:hover {
            background: var(--fb-blue-dark);
            box-shadow: 0 2px 8px rgba(24, 119, 242, 0.2);
        }

        .btn-primary:active,
        .btn-login:active {
            transform: translateY(1px);
        }

        .error-message,
        .alert {
            background: #fdecea;
            color: #b42318;
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 13px;
            border: 1px solid #f5c6cb;
            text-align: center;
        }

        .alert-danger {
            background: #fdecea;
            color: #b42318;
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background: #ecfdf3;
            color: #067647;
            border: 1px solid #abefc6;
        }

        .login-links,
        .login-footer {
            text-align: center;
            padding: 16px 24px 20px;
            border-top: 1px solid var(--border);
            background: #fff;
        }

        .login-links a,
        .back-link {
            color: var(--fb-blue);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .login-links a:hover,
        .back-link:hover {
            text-decoration: underline;
        }

        .admin-badge {
            position: absolute;
            top: 16px;
            right: 16px;
            background: #f04438;
            color: #fff;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .security-notice {
            background: #fffaeb;
            border: 1px solid #fcefc7;
            color: #7a5600;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        @media (max-width: 480px) {
            body {
                padding: 16px;
            }

            .login-header {
                padding: 20px 20px 10px;
            }

            .login-body,
            .login-form {
                padding: 16px 20px 20px;
            }
        }
    </style>
</head>
<body>

    <div class="login-container">
        <div class="login-header">
            <h2>SahabFormMaster</h2>
            <p>Staff Access Portal</p>
        </div>

        <div class="login-body">
            <?php if($error): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form action="index.php" method="POST" class="fade-in">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" class="form-control" placeholder="Enter your username" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" class="form-control" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn-primary">Login Securely</button>
            </form>

            <div class="login-links">
                <a href="student/index.php" class="student-link">Are you a Student? Click here to Login</a>
            </div>
        </div>
    </div>

<?php include 'includes/floating-button.php'; ?>
</body>
</html>
