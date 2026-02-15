<?php
// teacher/timebook.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow teachers
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

// School authentication and context
$current_school_id = require_school_auth();
$user_id = $_SESSION['user_id'];

// Ensure system_settings table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    // Table might already exist, continue
}

// Check if teacher sign-in is enabled
$signinEnabledStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
$signinEnabledStmt->execute(['teacher_signin_enabled']);
$signin_enabled = $signinEnabledStmt->fetchColumn();
$signin_enabled = $signin_enabled !== false ? (bool)$signin_enabled : true; // Default to true if not set

// Generate CSRF token
$csrf_token = generate_csrf_token();

// Handle sign in/out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $signin_enabled) {
    // Validate CSRF token
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = "Security validation failed. Please refresh the page.";
        Security::logSecurityEvent('csrf_violation', ['action' => 'timebook_signin', 'user_id' => $user_id]);
        header("Location: timebook.php");
        exit;
    }

    if (isset($_POST['sign_in'])) {
        $current_time = date('Y-m-d H:i:s');
        $notes = $_POST['notes'] ?? '';

        // Check if already signed in today
        $checkStmt = $pdo->prepare("SELECT id FROM time_records WHERE user_id = ? AND school_id = ? AND DATE(sign_in_time) = CURDATE()");
        $checkStmt->execute([$user_id, $current_school_id]);

        if ($checkStmt->rowCount() === 0) {
            $stmt = $pdo->prepare("INSERT INTO time_records (user_id, school_id, sign_in_time, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $current_school_id, $current_time, $notes]);
            $_SESSION['success'] = "Successfully signed in!";
        } else {
            $_SESSION['message'] = "You have already signed in today.";
        }

        header("Location: timebook.php");
        exit();
    }
}

// Get user info
$userStmt = $pdo->prepare("SELECT full_name, email, expected_arrival FROM users WHERE id = ? AND school_id = ?");
$userStmt->execute([$user_id, $current_school_id]);
$user = $userStmt->fetch();

// Get today's record
$todayStmt = $pdo->prepare("SELECT * FROM time_records WHERE user_id = ? AND school_id = ? AND DATE(sign_in_time) = CURDATE()");
$todayStmt->execute([$user_id, $current_school_id]);
$todayRecord = $todayStmt->fetch();

// Get this month's records
$monthStmt = $pdo->prepare("SELECT * FROM time_records WHERE user_id = ? AND school_id = ? AND MONTH(sign_in_time) = MONTH(CURDATE()) ORDER BY sign_in_time DESC");
$monthStmt->execute([$user_id, $current_school_id]);
$monthRecords = $monthStmt->fetchAll();

// Calculate statistics
$statsStmt = $pdo->prepare("SELECT
    COUNT(*) as total_days,
    SUM(CASE WHEN status = 'agreed' THEN 1 ELSE 0 END) as agreed_days,
    SUM(CASE WHEN status = 'not_agreed' THEN 1 ELSE 0 END) as not_agreed_days,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_days
    FROM time_records
    WHERE user_id = ? AND school_id = ? AND MONTH(sign_in_time) = MONTH(CURDATE())");
$statsStmt->execute([$user_id, $current_school_id]);
$stats = $statsStmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Timebook - SahabFormMaster</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-50: #eff6ff;
            --primary-100: #dbeafe;
            --primary-200: #bfdbfe;
            --primary-300: #93c5fd;
            --primary-400: #60a5fa;
            --primary-500: #3b82f6;
            --primary-600: #2563eb;
            --primary-700: #1d4ed8;
            --primary-800: #1e40af;
            --primary-900: #1e3a8a;

            --accent-50: #fdf4ff;
            --accent-100: #fae8ff;
            --accent-200: #f5d0fe;
            --accent-300: #f0abfc;
            --accent-400: #e879f9;
            --accent-500: #d946ef;
            --accent-600: #c026d3;
            --accent-700: #a21caf;
            --accent-800: #86198f;
            --accent-900: #701a75;

            --success-50: #f0fdf4;
            --success-100: #dcfce7;
            --success-500: #22c55e;
            --success-600: #16a34a;
            --success-700: #15803d;

            --error-50: #fef2f2;
            --error-100: #fee2e2;
            --error-500: #ef4444;
            --error-600: #dc2626;

            --warning-50: #fffbeb;
            --warning-100: #fef3c7;
            --warning-500: #f59e0b;
            --warning-600: #d97706;

            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;

            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 32px rgba(0, 0, 0, 0.12);
            --shadow-strong: 0 16px 48px rgba(0, 0, 0, 0.15);

            --gradient-primary: linear-gradient(135deg, var(--primary-500) 0%, var(--primary-700) 100%);
            --gradient-accent: linear-gradient(135deg, var(--accent-500) 0%, var(--accent-700) 100%);
            --gradient-bg: linear-gradient(135deg, var(--primary-50) 0%, var(--accent-50) 50%, var(--primary-100) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--gradient-bg);
            color: var(--gray-800);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* Modern Header */
        .modern-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-soft);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-container {
            width: 56px;
            height: 56px;
            background: var(--gradient-primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: var(--shadow-medium);
        }

        .brand-text h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.125rem;
        }

        .brand-text p {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 500;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 12px;
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .back-btn:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--gradient-accent);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .user-details p {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.125rem;
        }

        .user-details span {
            font-weight: 600;
            color: var(--gray-900);
        }

        .logout-btn {
            padding: 0.75rem 1.25rem;
            background: var(--error-500);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: var(--error-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Main Container */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Modern Cards */
        .modern-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            transition: all 0.3s ease;
            margin-bottom: 2rem;
        }

        .modern-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-strong);
        }

        .card-header-modern {
            padding: 2rem;
            background: var(--gradient-primary);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .card-header-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.05)"/><circle cx="10" cy="90" r="0.5" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .card-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .card-subtitle-modern {
            font-size: 1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .card-body-modern {
            padding: 2rem;
        }

        /* Statistics Grid */
        .stats-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-soft);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }

        .stat-card-modern:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-strong);
        }

        .stat-icon-modern {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        .stat-total .stat-icon-modern {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-agreed .stat-icon-modern {
            background: var(--gradient-success);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-pending .stat-icon-modern {
            background: var(--gradient-warning);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-not-agreed .stat-icon-modern {
            background: var(--gradient-error);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-value-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-label-modern {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Sign-in Card */
        .signin-card-modern {
            background: var(--gradient-primary);
            color: white;
            border-radius: 24px;
            padding: 2rem;
            box-shadow: var(--shadow-medium);
            position: relative;
            overflow: hidden;
        }

        .signin-card-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.05)"/><circle cx="10" cy="90" r="0.5" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .time-display-modern {
            font-family: 'Courier New', monospace;
            font-size: 3.5rem;
            font-weight: 700;
            text-align: center;
            margin: 1rem 0;
            position: relative;
            z-index: 1;
        }

        .signin-form-modern {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            padding: 2rem;
            position: relative;
            z-index: 1;
        }

        .signin-btn-modern {
            background: white;
            color: var(--primary-600);
            border: none;
            padding: 1rem 2rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-medium);
        }

        .signin-btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-strong);
        }

        .signin-btn-modern:disabled {
            background: rgba(255, 255, 255, 0.5);
            color: var(--gray-400);
        }

        /* Attendance Table */
        .attendance-table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-soft);
        }

        .table-header-modern {
            background: var(--gradient-primary);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .table-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .table-wrapper-modern {
            overflow-x: auto;
        }

        .attendance-table-modern {
            width: 100%;
            border-collapse: collapse;
        }

        .attendance-table-modern th {
            background: var(--gray-50);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 1.25rem 1.5rem;
            text-align: left;
            border-bottom: 2px solid var(--gray-200);
        }

        .attendance-table-modern td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.2s ease;
        }

        .attendance-table-modern tr:nth-child(even) {
            background: var(--gray-50);
        }

        .attendance-table-modern tr:hover {
            background: var(--primary-50);
        }

        .status-indicator-modern {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .status-agreed-modern {
            background: var(--success-100);
            color: var(--success-700);
            border: 1px solid var(--success-200);
        }

        .status-not-agreed-modern {
            background: var(--error-100);
            color: var(--error-700);
            border: 1px solid var(--error-200);
        }

        .status-pending-modern {
            background: var(--warning-100);
            color: var(--warning-700);
            border: 1px solid var(--warning-200);
        }

        /* Form Controls */
        .form-input-modern {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-input-modern:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .form-input-modern::placeholder {
            color: var(--gray-400);
        }

        /* Alerts */
        .alert-modern {
            padding: 1.25rem 1.5rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            font-weight: 500;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .alert-success-modern {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success-700);
            border-left: 4px solid var(--success-500);
        }

        .alert-warning-modern {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-700);
            border-left: 4px solid var(--warning-500);
        }

        .alert-info-modern {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary-700);
            border-left: 4px solid var(--primary-500);
        }

        /* Modal */
        .modal-custom-modern {
            border-radius: 20px;
            border: none;
            box-shadow: var(--shadow-strong);
            backdrop-filter: blur(20px);
        }

        .modal-content-custom {
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header-content {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
            }

            .main-container {
                padding: 1rem;
            }

            .stats-modern {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .modern-card {
                margin-bottom: 1rem;
            }

            .card-header-modern,
            .card-body-modern {
                padding: 1.5rem;
            }

            .stat-card-modern {
                padding: 1.5rem;
            }

            .stat-icon-modern {
                width: 48px;
                height: 48px;
                font-size: 1.25rem;
            }

            .stat-value-modern {
                font-size: 2rem;
            }

            .time-display-modern {
                font-size: 2.5rem;
            }

            .signin-form-modern {
                padding: 1.5rem;
            }

            .table-header-modern {
                padding: 1rem;
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }

            .attendance-table-modern th,
            .attendance-table-modern td {
                padding: 0.75rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 480px) {
            .stats-modern {
                grid-template-columns: 1fr;
            }

            .stat-card-modern {
                padding: 1rem;
            }

            .modern-card {
                margin-bottom: 1rem;
            }

            .card-header-modern,
            .card-body-modern {
                padding: 1rem;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .animate-slide-in-left {
            animation: slideInLeft 0.6s ease-out;
        }

        .animate-slide-in-right {
            animation: slideInRight 0.6s ease-out;
        }

        /* Utility Classes */
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }
        .font-semibold { font-weight: 600; }

        .gradient-success { background: linear-gradient(135deg, var(--success-500) 0%, var(--success-600) 100%); }
        .gradient-error { background: linear-gradient(135deg, var(--error-500) 0%, var(--error-600) 100%); }
        .gradient-warning { background: linear-gradient(135deg, var(--warning-500) 0%, var(--warning-600) 100%); }
    </style>
</head>
<body>
    <!-- Modern Header -->
    <header class="modern-header">
        <div class="header-content">
            <div class="header-brand">
                <a href="index.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
                <div class="logo-container">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="brand-text">
                    <h1>SahabFormMaster</h1>
                    <p>Teacher Timebook</p>
                </div>
            </div>
            <div class="header-actions">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <p>Teacher</p>
                        <span><?php echo htmlspecialchars($user['full_name']); ?></span>
                    </div>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Welcome Section -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern">
                <h2 class="card-title-modern">
                    <i class="fas fa-clock"></i>
                    Teacher Timebook Management
                </h2>
                <p class="card-subtitle-modern">
                    Track your daily attendance and monitor your time records efficiently
                </p>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-modern">
            <div class="stat-card-modern stat-total animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-value-modern"><?php echo $stats['total_days'] ?? 0; ?></div>
                <div class="stat-label-modern">Days Worked</div>
            </div>

            <div class="stat-card-modern stat-agreed animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value-modern"><?php echo $stats['agreed_days'] ?? 0; ?></div>
                <div class="stat-label-modern">Agreed Days</div>
            </div>

            <div class="stat-card-modern stat-pending animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value-modern"><?php echo $stats['pending_days'] ?? 0; ?></div>
                <div class="stat-label-modern">Pending Review</div>
            </div>

            <div class="stat-card-modern stat-not-agreed animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-value-modern"><?php echo $stats['not_agreed_days'] ?? 0; ?></div>
                <div class="stat-label-modern">Not Agreed</div>
            </div>
        </div>

        <!-- Sign-in Card -->
        <div class="modern-card animate-fade-in-up">
            <div class="signin-card-modern">
                <div class="text-center mb-4">
                    <h3 class="mb-2">Today's Attendance</h3>
                    <p class="opacity-75">Sign in when you arrive at school</p>
                </div>

                <div class="time-display-modern" id="currentTime">
                    <?php echo date('H:i:s'); ?>
                </div>

                <div class="signin-form-modern">
                    <?php if ($todayRecord): ?>
                        <div class="alert-modern alert-info-modern">
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <strong>You signed in at <?php echo date('H:i:s', strtotime($todayRecord['sign_in_time'])); ?></strong>
                                <?php if ($todayRecord['status'] !== 'pending'): ?>
                                    <br>
                                    <span class="status-indicator-modern status-<?php echo $todayRecord['status']; ?>-modern mt-2 d-inline-block">
                                        Status: <?php
                                        $statusLabels = [
                                            'agreed' => 'Agreed ✓',
                                            'not_agreed' => 'Not Agreed ✗'
                                        ];
                                        echo $statusLabels[$todayRecord['status']];
                                        ?>
                                    </span>
                                <?php else: ?>
                                    <br>
                                    <span class="status-indicator-modern status-pending-modern mt-2 d-inline-block">
                                        Awaiting Review
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($todayRecord['admin_notes'])): ?>
                            <div class="alert-modern alert-warning-modern mt-3">
                                <i class="fas fa-sticky-note"></i>
                                <div>
                                    <small class="font-semibold">Admin Notes:</small>
                                    <p class="mb-0"><?php echo htmlspecialchars($todayRecord['admin_notes']); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php elseif (!$signin_enabled): ?>
                        <div class="alert-modern alert-warning-modern">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>
                                <strong>Sign-in Temporarily Disabled</strong><br>
                                Teacher sign-in has been disabled by the administrator. Please contact the admin for assistance.
                            </div>
                        </div>
                    <?php else: ?>
                        <form method="POST" id="signInForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <div class="mb-4">
                                <textarea class="form-input-modern" name="notes" rows="3"
                                          placeholder="Add any notes for today (optional)"></textarea>
                            </div>
                            <button type="submit" name="sign_in" class="signin-btn-modern">
                                <i class="fas fa-sign-in-alt"></i>
                                <span>Sign In Now</span>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert-modern alert-success-modern animate-fade-in-up">
                <i class="fas fa-check-circle"></i>
                <span><?php safe_echo($_SESSION['success']); unset($_SESSION['success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['message'])): ?>
            <div class="alert-modern alert-info-modern animate-fade-in-up">
                <i class="fas fa-info-circle"></i>
                <span><?php safe_echo($_SESSION['message']); unset($_SESSION['message']); ?></span>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert-modern alert-error-modern animate-fade-in-up">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php safe_echo($_SESSION['error']); unset($_SESSION['error']); ?></span>
            </div>
        <?php endif; ?>

        <!-- Attendance Records Table -->
        <div class="attendance-table-container animate-fade-in-up">
            <div class="table-header-modern">
                <div class="table-title-modern">
                    <i class="fas fa-history"></i>
                    Recent Attendance Records
                </div>
            </div>

            <div class="table-wrapper-modern">
                <?php if (empty($monthRecords)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <h5>No records found</h5>
                        <p class="text-muted">Your attendance records will appear here.</p>
                    </div>
                <?php else: ?>
                    <table class="attendance-table-modern">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Sign In Time</th>
                                <th>Expected Time</th>
                                <th>Status</th>
                                <th>Admin Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthRecords as $record): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($record['sign_in_time'])); ?></td>
                                    <td>
                                        <span class="font-bold"><?php echo date('H:i:s', strtotime($record['sign_in_time'])); ?></span>
                                    </td>
                                    <td><?php echo $user['expected_arrival']; ?></td>
                                    <td>
                                        <span class="status-indicator-modern status-<?php echo $record['status']; ?>-modern">
                                            <?php
                                            $statusLabels = [
                                                'pending' => 'Pending',
                                                'agreed' => 'Agreed',
                                                'not_agreed' => 'Not Agreed'
                                            ];
                                            echo $statusLabels[$record['status']];
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($record['admin_notes'])): ?>
                                            <button class="btn btn-sm btn-outline-secondary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#notesModal"
                                                    data-notes="<?php echo htmlspecialchars($record['admin_notes']); ?>">
                                                <i class="fas fa-sticky-note me-1"></i> View Notes
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Notes Modal -->
    <div class="modal fade" id="notesModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-custom-modern">
            <div class="modal-content modal-content-custom">
                <div class="modal-header">
                    <h5 class="modal-title">Admin Notes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="modalNotesContent"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update current time every second
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour12: false });
            document.getElementById('currentTime').textContent = timeString;
        }

        // Initialize time and update every second
        updateTime();
        setInterval(updateTime, 1000);

        // Handle notes modal
        const notesModal = document.getElementById('notesModal');
        notesModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const notes = button.getAttribute('data-notes');
            const modalBody = notesModal.querySelector('#modalNotesContent');
            modalBody.textContent = notes;
        });

        // Auto-resize textarea
        const textarea = document.querySelector('textarea[name="notes"]');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }

        // Show success message if exists
        <?php if (isset($_SESSION['success'])): ?>
            showToast("<?php echo $_SESSION['success']; ?>", "success");
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        // Show info message if exists
        <?php if (isset($_SESSION['message'])): ?>
            showToast("<?php echo $_SESSION['message']; ?>", "info");
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type} border-0 position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 1055; min-width: 300px;';
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            document.body.appendChild(toast);

            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();

            setTimeout(() => {
                toast.remove();
            }, 4000);
        }

        // Smooth scroll for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Header scroll effect
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.modern-header');
            if (window.scrollY > 50) {
                header.style.background = 'rgba(255, 255, 255, 0.98)';
                header.style.backdropFilter = 'blur(20px)';
            } else {
                header.style.background = 'rgba(255, 255, 255, 0.95)';
            }
        });

        // Add entrance animations on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe all animated elements
        document.querySelectorAll('.animate-fade-in-up, .animate-slide-in-left, .animate-slide-in-right').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
            observer.observe(el);
        });

        // Add loading animation for buttons
        document.querySelectorAll('.signin-btn-modern, .logout-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (this.type === 'submit') {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><span>Processing...</span>';
                    this.disabled = true;

                    // Re-enable after 3 seconds (fallback)
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.disabled = false;
                    }, 3000);
                }
            });
        });

        // Enhanced form validation
        const signInForm = document.getElementById('signInForm');
        if (signInForm) {
            signInForm.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('.signin-btn-modern');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><span>Signing In...</span>';
                    submitBtn.disabled = true;
                }
            });
        }

        // Add ripple effect to buttons
        document.querySelectorAll('.signin-btn-modern, .logout-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                ripple.style.position = 'absolute';
                ripple.style.borderRadius = '50%';
                ripple.style.background = 'rgba(255, 255, 255, 0.6)';
                ripple.style.transform = 'scale(0)';
                ripple.style.animation = 'ripple 0.6s linear';
                ripple.style.left = (e.offsetX - 10) + 'px';
                ripple.style.top = (e.offsetY - 10) + 'px';
                ripple.style.width = '20px';
                ripple.style.height = '20px';

                this.style.position = 'relative';
                this.style.overflow = 'hidden';
                this.appendChild(ripple);

                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // Add CSS animation for ripple
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }

            .stat-card-modern:hover .stat-icon-modern {
                transform: scale(1.1);
                transition: transform 0.3s ease;
            }

            .modern-card:hover {
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            }
        `;
        document.head.appendChild(style);
    </script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>
