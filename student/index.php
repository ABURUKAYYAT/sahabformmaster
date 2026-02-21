<?php
// student/index.php
session_start();
require_once '../config/db.php';

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
    <title>Student Login | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/education-theme-main.css">
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
            <p>Student Portal</p>
        </div>

        <div class="login-body">
            <?php if($error): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form action="index.php" method="POST" class="fade-in">
                <div class="form-group">
                    <label for="admission_number">Admission Number</label>
                    <input type="text" name="admission_no" id="admission_no" class="form-control" placeholder="Enter your admission number" required>
                </div>

                <div class="form-group">
                    <label for="student_name">Full Name</label>
                    <input type="text" name="student_name" id="student_name" class="form-control" placeholder="Enter your full name" required>
                </div>

                <button type="submit" class="btn-primary">Login</button>
            </form>

            <div class="login-links">
                <a href="../index.php" class="student-link">Are you a Staff Member? Click here to Login</a>
            </div>
        </div>
    </div>

</body>
</html>

