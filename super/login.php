<?php
session_start();
require_once '../config/db.php';

// Redirect if already logged in as super admin
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'super_admin') {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $errors[] = 'Please enter both email and password.';
    } else {
        try {
            // Check if super admin exists, if not create with default credentials
            $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'super_admin' LIMIT 1");
            $stmt->execute();
            $super_admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$super_admin) {
                // Create default super admin - REQUIRE MANUAL SETUP
                // REMOVED: Automatic creation with hardcoded password for security
                $errors[] = 'Super admin account not found. Please contact system administrator for setup.';
                // Log this security event
                Security::logSecurityEvent('super_admin_setup_required', ['attempted_by' => $email]);
            } else {
                $super_admin_id = $super_admin['id'];
            }

            // Now authenticate
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'super_admin'");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['school_id'] = $user['school_id'];

                // Log login activity
                $stmt = $pdo->prepare("INSERT INTO access_logs (user_id, action, resource_type, status, ip_address, user_agent) VALUES (?, 'login', 'system', 'success', ?, ?)");
                $stmt->execute([
                    $user['id'],
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]);

                $success = 'Login successful! Redirecting...';
                header('Location: dashboard.php');
                exit;
            } else {
                $errors[] = 'Invalid email or password.';

                // Log failed login
                $stmt = $pdo->prepare("INSERT INTO access_logs (user_id, action, resource_type, status, ip_address, user_agent, message) VALUES (?, 'login', 'system', 'denied', ?, ?, ?)");
                $stmt->execute([
                    $super_admin_id ?? 0,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'Invalid credentials'
                ]);
            }
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login | SahabFormMaster</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
        <div class="admin-badge">
            <i class="fas fa-crown"></i> Super Admin
        </div>

        <div class="login-header">
            <h1><i class="fas fa-shield-alt"></i> Super Admin Portal</h1>
            <p>System-wide Administration Access</p>
        </div>

        <div class="login-form">
            <div class="security-notice">
                <i class="fas fa-lock"></i>
                <strong>Secure Access:</strong> This portal provides system-wide administrative privileges. Access is restricted and monitored.
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php foreach ($errors as $error): ?>
                        <?php echo htmlspecialchars($error); ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="email">
                        <i class="fas fa-envelope"></i> Email Address
                    </label>
                    <input type="email" id="email" name="email" class="form-control"
                           placeholder="Enter your email address" required
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <input type="password" id="password" name="password" class="form-control"
                           placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Access Super Admin Panel
                </button>
            </form>
        </div>

        <div class="login-footer">
            <p>Super admin account must be manually configured by system administrator.</p>
            <a href="../index.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Main Site
            </a>
        </div>
    </div>

    <script>
        // Auto-focus email field
        document.getElementById('email').focus();

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value.trim();

            if (!email || !password) {
                e.preventDefault();
                alert('Please fill in both email and password fields.');
                return false;
            }

            // Show loading state
            const button = document.querySelector('.btn-login');
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Authenticating...';
            button.disabled = true;
        });

        // Enter key support
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.querySelector('form').submit();
            }
        });
    </script>
</body>
</html>
