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
        /* ===== LOGIN PAGE SPECIFIC STYLES ===== */

        body {
            background: linear-gradient(135deg, var(--primary-50) 0%, var(--primary-100) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            font-family: var(--font-family-primary);
        }

        .login-container {
            background: var(--white);
            border-radius: var(--border-radius-2xl);
            box-shadow: var(--shadow-2xl);
            width: 100%;
            max-width: 480px;
            overflow: hidden;
            position: relative;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, var(--primary-800), var(--primary-600));
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary-800), var(--primary-900));
            color: var(--white);
            padding: 3rem 2.5rem 2.5rem;
            text-align: center;
            position: relative;
        }

        .login-header h2 {
            font-family: var(--font-family-heading);
            font-size: var(--text-3xl);
            font-weight: var(--font-bold);
            margin-bottom: var(--space-2);
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .login-header p {
            font-size: var(--text-lg);
            opacity: 0.95;
            font-weight: var(--font-medium);
            line-height: var(--leading-relaxed);
        }

        .login-body {
            padding: 3rem 2.5rem;
        }

        .form-group {
            margin-bottom: var(--space-6);
        }

        .form-group label {
            display: block;
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            color: var(--gray-700);
            margin-bottom: var(--space-2);
            letter-spacing: 0.025em;
        }

        .form-control {
            width: 100%;
            padding: var(--space-4);
            border: var(--border-width-2) solid var(--gray-300);
            border-radius: var(--border-radius-lg);
            font-size: var(--text-base);
            transition: all var(--transition-normal);
            background: var(--white);
            color: var(--gray-900);
            font-family: inherit;
            outline: none;
            box-shadow: var(--shadow-sm);
        }

        .form-control:focus {
            border-color: var(--primary-800);
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
            transform: translateY(-1px);
        }

        .form-control::placeholder {
            color: var(--gray-400);
        }

        .btn-primary {
            width: 100%;
            padding: var(--space-4) var(--space-6);
            background: var(--primary-800);
            color: var(--white);
            border: none;
            border-radius: var(--border-radius-lg);
            font-size: var(--text-base);
            font-weight: var(--font-semibold);
            cursor: pointer;
            transition: all var(--transition-normal);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            box-shadow: var(--shadow-md);
            min-height: 56px;
        }

        .btn-primary:hover {
            background: var(--primary-900);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .alert {
            padding: var(--space-4);
            margin-bottom: var(--space-6);
            border-radius: var(--border-radius-lg);
            font-size: var(--text-sm);
            text-align: center;
            font-weight: var(--font-medium);
            border: var(--border-width-1) solid transparent;
        }

        .alert-error {
            background: var(--error-50);
            color: var(--error-700);
            border-color: var(--error-200);
            box-shadow: 0 0 0 1px var(--error-200);
        }

        .alert-error::before {
            content: '⚠️';
            margin-right: var(--space-2);
            font-size: var(--text-lg);
        }

        .login-links {
            text-align: center;
            margin-top: var(--space-8);
            padding-top: var(--space-6);
            border-top: var(--border-width-1) solid var(--gray-200);
        }

        .student-link {
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            color: var(--primary-800);
            text-decoration: none;
            font-weight: var(--font-medium);
            font-size: var(--text-sm);
            transition: all var(--transition-normal);
            padding: var(--space-3) var(--space-6);
            border-radius: var(--border-radius-full);
            border: var(--border-width-2) solid var(--primary-200);
            background: var(--primary-50);
            box-shadow: var(--shadow-sm);
        }

        .student-link:hover {
            background: var(--primary-100);
            border-color: var(--primary-300);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
            color: var(--primary-900);
        }

        .fade-in {
            animation: fadeInProfessional 0.8s ease-out;
        }

        @keyframes fadeInProfessional {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ===== RESPONSIVE DESIGN ===== */

        @media (max-width: 640px) {
            body {
                padding: var(--space-4);
            }

            .login-container {
                margin: var(--space-2);
                max-width: none;
                border-radius: var(--border-radius-xl);
            }

            .login-header {
                padding: 2.5rem var(--space-6) 2rem;
            }

            .login-header h2 {
                font-size: var(--text-2xl);
            }

            .login-header p {
                font-size: var(--text-base);
            }

            .login-body {
                padding: 2.5rem var(--space-6);
            }

            .form-control {
                padding: var(--space-3);
                font-size: var(--text-sm);
            }

            .btn-primary {
                padding: var(--space-3) var(--space-4);
                font-size: var(--text-sm);
                min-height: 48px;
            }
        }

        @media (max-width: 480px) {
            .login-container {
                border-radius: var(--border-radius-lg);
            }

            .login-header {
                padding: 2rem var(--space-4) 1.5rem;
            }

            .login-header h2 {
                font-size: var(--text-xl);
            }

            .login-body {
                padding: 2rem var(--space-4);
            }

            .form-group {
                margin-bottom: var(--space-4);
            }

            .login-links {
                margin-top: var(--space-6);
                padding-top: var(--space-4);
            }

            .student-link {
                font-size: var(--text-xs);
                padding: var(--space-2) var(--space-4);
            }
        }

        /* ===== ACCESSIBILITY ENHANCEMENTS ===== */

        @media (prefers-contrast: high) {
            .login-container {
                border: 2px solid #000000;
            }

            .btn-primary {
                background: #000000;
                border: 2px solid #000000;
            }

            .student-link {
                border: 2px solid #000000;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .login-container,
            .btn-primary,
            .student-link {
                animation: none;
                transition: none;
            }

            .btn-primary:hover,
            .student-link:hover {
                transform: none;
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

</body>
</html>
