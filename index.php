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
    <style>
        /* Glassmorphic CSS Variables */
        :root {
            --glass-bg: rgba(255, 255, 255, 0.25);
            --glass-border: rgba(255, 255, 255, 0.18);
            --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
            --glass-shadow-hover: 0 12px 40px 0 rgba(31, 38, 135, 0.45);
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --accent-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.8);
            --input-bg: rgba(255, 255, 255, 0.15);
            --input-border: rgba(255, 255, 255, 0.3);
            --error-bg: rgba(239, 68, 68, 0.2);
            --error-border: rgba(239, 68, 68, 0.3);
            --success-bg: rgba(34, 197, 94, 0.2);
            --success-border: rgba(34, 197, 94, 0.3);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text-primary);
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="80" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="40" cy="60" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="60" cy="30" r="1" fill="rgba(255,255,255,0.1)"/></svg>') repeat;
            pointer-events: none;
            z-index: -1;
        }

        .login-container {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            box-shadow: var(--glass-shadow);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
            transition: var(--transition);
            position: relative;
            z-index: 1;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--accent-gradient);
            border-radius: 20px 20px 0 0;
        }

        .login-container:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: var(--glass-shadow-hover);
        }

        .login-header {
            background: var(--primary-gradient);
            color: var(--text-primary);
            padding: 2.5rem 2rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-header::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: shimmer 6s ease-in-out infinite;
        }

        @keyframes shimmer {
            0%, 100% { transform: scale(1) rotate(0deg); }
            50% { transform: scale(1.05) rotate(180deg); }
        }

        .login-header h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
            background: linear-gradient(45deg, #ffffff, rgba(255, 255, 255, 0.8));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .login-header p {
            font-size: 1.1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .login-body {
            padding: 2.5rem 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .form-control {
            width: 100%;
            padding: 1rem;
            border: 1px solid var(--input-border);
            border-radius: 12px;
            font-size: 1rem;
            transition: var(--transition);
            background: var(--input-bg);
            backdrop-filter: blur(10px);
            color: var(--text-primary);
            font-family: inherit;
            outline: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .form-control:focus {
            border-color: rgba(255, 255, 255, 0.5);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.7);
            font-weight: 400;
        }

        .btn-primary {
            width: 100%;
            padding: 1rem 1.5rem;
            background: var(--accent-gradient);
            color: var(--text-primary);
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            transition: left 0.6s;
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .btn-primary:active {
            transform: translateY(-1px);
        }

        .alert {
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            border-radius: 12px;
            font-size: 0.9rem;
            text-align: center;
            font-weight: 500;
            position: relative;
            border: 1px solid transparent;
            backdrop-filter: blur(10px);
        }

        .alert-error {
            background: var(--error-bg);
            color: #ff6b6b;
            border-color: var(--error-border);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.2);
        }

        .alert::before {
            content: '⚠️';
            margin-right: 0.5rem;
            font-size: 1.2rem;
        }

        .login-links {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--glass-border);
        }

        .student-link {
            display: inline-block;
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: var(--transition);
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            border: 1px solid var(--glass-border);
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .student-link:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
            color: var(--text-primary);
        }

        .fade-in {
            animation: fadeInGlass 0.8s ease-out;
        }

        @keyframes fadeInGlass {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.95);
                filter: blur(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
                filter: blur(0);
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .login-container {
                margin: 1rem;
                max-width: none;
                border-radius: 15px;
            }

            .login-header {
                padding: 2rem 1.5rem 1.5rem;
            }

            .login-header h2 {
                font-size: 1.5rem;
            }

            .login-header p {
                font-size: 1rem;
            }

            .login-body {
                padding: 2rem 1.5rem;
            }

            .form-control {
                padding: 0.875rem;
                font-size: 0.9rem;
            }

            .btn-primary {
                padding: 0.875rem 1.25rem;
                font-size: 1rem;
            }
        }

        @media (max-width: 480px) {
            .login-container {
                border-radius: 12px;
            }

            .login-header {
                padding: 1.5rem 1.25rem 1.25rem;
            }

            .login-header h2 {
                font-size: 1.25rem;
            }

            .login-body {
                padding: 1.5rem 1.25rem;
            }

            .form-group {
                margin-bottom: 1.25rem;
            }

            .login-links {
                margin-top: 1.5rem;
                padding-top: 1.25rem;
            }

            .student-link {
                font-size: 0.85rem;
                padding: 0.5rem 1rem;
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
