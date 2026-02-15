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
        /* ===== MONOCHROMATIC GLASSMORPHISM - STUDENT LOGIN ===== */

        body {
            font-family: var(--font-family-primary);
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-200) 50%, var(--gray-50) 100%);
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            color: var(--gray-900);
            overflow-x: hidden;
        }

        /* Ultra-fine dot pattern background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="0.8" fill="rgba(0,0,0,0.02)"/><circle cx="80" cy="80" r="0.8" fill="rgba(0,0,0,0.02)"/><circle cx="40" cy="60" r="0.6" fill="rgba(0,0,0,0.015)"/><circle cx="60" cy="30" r="0.6" fill="rgba(0,0,0,0.015)"/><circle cx="10" cy="70" r="0.4" fill="rgba(0,0,0,0.01)"/><circle cx="90" cy="40" r="0.4" fill="rgba(0,0,0,0.01)"/><circle cx="30" cy="10" r="0.5" fill="rgba(0,0,0,0.012)"/><circle cx="70" cy="90" r="0.5" fill="rgba(0,0,0,0.012)"/></svg>') repeat;
            pointer-events: none;
            z-index: -1;
        }

        .login-container {
            background: var(--glass-white);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: var(--border-width-1) solid var(--glass-border);
            border-radius: var(--border-radius-2xl);
            box-shadow: var(--glass-shadow);
            width: 100%;
            max-width: 480px;
            overflow: hidden;
            position: relative;
            z-index: 1;
            transition: all var(--transition-normal);
        }

        /* Monochromatic accent bar */
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, var(--gray-800), var(--gray-600));
            border-radius: var(--border-radius-2xl) var(--border-radius-2xl) 0 0;
        }

        .login-container:hover {
            background: var(--glass-white-dark);
            box-shadow: var(--glass-shadow-hover);
            transform: translateY(-4px);
        }

        .login-header {
            background: var(--glass-gray-medium);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            color: var(--gray-900);
            padding: 3rem 2.5rem 2.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
            border-bottom: var(--border-width-1) solid var(--glass-border-dark);
        }

        .login-header h2 {
            font-family: var(--font-family-heading);
            font-size: var(--text-3xl);
            font-weight: var(--font-bold);
            margin-bottom: var(--space-2);
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .login-header p {
            font-size: var(--text-lg);
            opacity: 0.8;
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
            border: var(--border-width-1) solid var(--glass-border);
            border-radius: var(--border-radius-lg);
            font-size: var(--text-base);
            transition: all var(--transition-normal);
            background: var(--glass-white-light);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            color: var(--gray-900);
            font-family: inherit;
            outline: none;
            box-shadow: var(--glass-shadow-light);
        }

        .form-control:focus {
            border-color: var(--glass-border);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1), var(--glass-shadow-light);
            background: var(--white);
            transform: translateY(-1px);
        }

        .form-control::placeholder {
            color: var(--gray-500);
        }

        .btn-primary {
            width: 100%;
            padding: var(--space-4) var(--space-6);
            background: var(--glass-white-dark);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            color: var(--gray-900);
            border: var(--border-width-1) solid rgba(255, 255, 255, 0.3);
            border-radius: var(--border-radius-lg);
            font-size: var(--text-base);
            font-weight: var(--font-semibold);
            cursor: pointer;
            transition: all var(--transition-normal);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            box-shadow: var(--glass-shadow);
            min-height: 56px;
            position: relative;
            overflow: hidden;
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.6s;
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .btn-primary:hover {
            background: rgba(255, 255, 255, 0.4);
            border-color: rgba(255, 255, 255, 0.4);
            transform: translateY(-2px);
            box-shadow: var(--glass-shadow-hover);
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
            background: var(--error-light);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: var(--border-width-1) solid rgba(64, 64, 64, 0.2);
            color: var(--error-dark);
            box-shadow: var(--glass-shadow-light);
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
            border-top: var(--border-width-1) solid var(--glass-border-dark);
        }

        .staff-link {
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            color: var(--gray-700);
            text-decoration: none;
            font-weight: var(--font-medium);
            font-size: var(--text-sm);
            transition: all var(--transition-normal);
            padding: var(--space-3) var(--space-6);
            border-radius: var(--border-radius-full);
            border: var(--border-width-1) solid var(--glass-border);
            background: var(--glass-white-light);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: var(--glass-shadow-light);
        }

        .staff-link:hover {
            background: var(--glass-white);
            border-color: var(--glass-border);
            transform: translateY(-2px);
            box-shadow: var(--glass-shadow);
            color: var(--gray-900);
        }

        .fade-in {
            animation: fadeInGlass 0.8s ease-out;
        }

        @keyframes fadeInGlass {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.98);
                filter: blur(5px);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
                filter: blur(0);
            }
        }

        /* ===== ULTRA RESPONSIVE DESIGN ===== */

        /* Large Desktop */
        @media (min-width: 1200px) {
            body {
                padding: 2rem;
            }

            .login-container {
                max-width: 520px;
            }

            .login-header {
                padding: 3.5rem 3rem 3rem;
            }

            .login-header h2 {
                font-size: var(--text-4xl);
            }

            .login-body {
                padding: 3.5rem 3rem;
            }
        }

        /* Desktop */
        @media (max-width: 1199px) and (min-width: 1025px) {
            .login-container {
                max-width: 500px;
            }

            .login-header h2 {
                font-size: var(--text-3xl);
            }
        }

        /* Small Desktop / Large Tablet */
        @media (max-width: 1024px) and (min-width: 769px) {
            .login-container {
                max-width: 460px;
            }

            .login-header {
                padding: 2.75rem 2.25rem 2.25rem;
            }

            .login-header h2 {
                font-size: var(--text-2xl);
            }

            .login-header p {
                font-size: var(--text-base);
            }

            .login-body {
                padding: 2.75rem 2.25rem;
            }

            .form-control {
                padding: var(--space-3);
            }

            .btn-primary {
                min-height: 52px;
            }
        }

        /* Tablet */
        @media (max-width: 768px) and (min-width: 641px) {
            body {
                padding: 1.5rem;
            }

            .login-container {
                max-width: 420px;
                border-radius: var(--border-radius-xl);
            }

            .login-header {
                padding: 2.5rem 2rem 2rem;
            }

            .login-header h2 {
                font-size: var(--text-2xl);
            }

            .login-header p {
                font-size: var(--text-base);
            }

            .login-body {
                padding: 2.5rem 2rem;
            }

            .form-control {
                font-size: var(--text-sm);
            }

            .btn-primary {
                min-height: 50px;
                font-size: var(--text-sm);
            }
        }

        /* Large Mobile */
        @media (max-width: 640px) and (min-width: 481px) {
            body {
                padding: 1.25rem;
            }

            .login-container {
                max-width: 400px;
                border-radius: var(--border-radius-xl);
            }

            .login-header {
                padding: 2.25rem 1.75rem 1.75rem;
            }

            .login-header h2 {
                font-size: var(--text-xl);
            }

            .login-header p {
                font-size: var(--text-sm);
            }

            .login-body {
                padding: 2.25rem 1.75rem;
            }

            .form-group {
                margin-bottom: var(--space-5);
            }

            .form-control {
                padding: var(--space-3);
                font-size: var(--text-sm);
            }

            .btn-primary {
                min-height: 48px;
                font-size: var(--text-sm);
            }
        }

        /* Mobile */
        @media (max-width: 480px) and (min-width: 361px) {
            body {
                padding: 1rem;
            }

            .login-container {
                max-width: none;
                margin: 0.5rem;
                border-radius: var(--border-radius-lg);
            }

            .login-container::before {
                height: 5px;
            }

            .login-header {
                padding: 2rem 1.5rem 1.5rem;
            }

            .login-header h2 {
                font-size: var(--text-xl);
                margin-bottom: var(--space-1);
            }

            .login-header p {
                font-size: var(--text-sm);
            }

            .login-body {
                padding: 2rem 1.5rem;
            }

            .form-group {
                margin-bottom: var(--space-4);
            }

            .form-group label {
                font-size: var(--text-xs);
                margin-bottom: var(--space-1);
            }

            .form-control {
                padding: var(--space-3);
                font-size: var(--text-sm);
            }

            .btn-primary {
                min-height: 46px;
                font-size: var(--text-sm);
                padding: var(--space-3) var(--space-4);
            }

            .alert {
                padding: var(--space-3);
                margin-bottom: var(--space-4);
                font-size: var(--text-xs);
            }

            .login-links {
                margin-top: var(--space-6);
                padding-top: var(--space-4);
            }

            .staff-link {
                font-size: var(--text-xs);
                padding: var(--space-2) var(--space-4);
            }
        }

        /* Small Mobile */
        @media (max-width: 360px) {
            body {
                padding: 0.75rem;
            }

            .login-container {
                margin: 0.25rem;
                border-radius: var(--border-radius-md);
            }

            .login-header {
                padding: 1.75rem 1.25rem 1.25rem;
            }

            .login-header h2 {
                font-size: var(--text-lg);
                margin-bottom: var(--space-1);
            }

            .login-header p {
                font-size: var(--text-xs);
                line-height: var(--leading-normal);
            }

            .login-body {
                padding: 1.75rem 1.25rem;
            }

            .form-group {
                margin-bottom: var(--space-3);
            }

            .form-group label {
                font-size: 0.6875rem;
                margin-bottom: var(--space-1);
            }

            .form-control {
                padding: var(--space-2);
                font-size: var(--text-xs);
                min-height: 40px;
            }

            .btn-primary {
                min-height: 44px;
                font-size: var(--text-xs);
                padding: var(--space-2) var(--space-3);
                letter-spacing: 0.025em;
            }

            .alert {
                padding: var(--space-2);
                margin-bottom: var(--space-3);
                font-size: 0.6875rem;
                border-radius: var(--border-radius-sm);
            }

            .login-links {
                margin-top: var(--space-4);
                padding-top: var(--space-3);
            }

            .staff-link {
                font-size: 0.6875rem;
                padding: var(--space-1) var(--space-3);
                gap: var(--space-1);
            }
        }

        /* ===== ACCESSIBILITY ENHANCEMENTS ===== */

        @media (prefers-contrast: high) {
            .login-container {
                background: var(--white);
                border: 3px solid var(--black);
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
            }

            .login-header {
                background: var(--gray-100);
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
            }

            .btn-primary {
                background: var(--black);
                border: 3px solid var(--black);
                color: var(--white);
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
            }

            .staff-link {
                background: var(--white);
                border: 3px solid var(--black);
                color: var(--black);
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
            }

            .form-control {
                background: var(--white);
                border: 3px solid var(--black);
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .login-container,
            .btn-primary,
            .staff-link,
            .form-control {
                animation: none;
                transition: none;
            }

            .login-container:hover,
            .btn-primary:hover,
            .staff-link:hover,
            .form-control:focus {
                transform: none;
            }

            .btn-primary::before {
                display: none;
            }
        }

        /* Touch target enhancements for mobile */
        @media (hover: none) and (pointer: coarse) {
            .form-control {
                min-height: 48px;
            }

            .btn-primary {
                min-height: 52px;
            }

            .staff-link {
                min-height: 48px;
                padding: var(--space-4) var(--space-6);
            }
        }

        /* Landscape orientation adjustments */
        @media (max-height: 500px) and (orientation: landscape) {
            body {
                padding: 0.5rem;
            }

            .login-container {
                max-width: none;
                margin: 0.25rem;
            }

            .login-header {
                padding: 1.5rem 1.25rem 1rem;
            }

            .login-header h2 {
                font-size: var(--text-lg);
            }

            .login-body {
                padding: 1.5rem 1.25rem;
            }

            .form-group {
                margin-bottom: var(--space-3);
            }

            .btn-primary {
                min-height: 42px;
                padding: var(--space-2) var(--space-3);
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
