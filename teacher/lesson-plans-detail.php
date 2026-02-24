<?php
// filepath: c:\xampp\htdocs\sahabformmaster\teacher\lesson-plans-detail.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow principal (admin) and teachers to access
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['principal', 'teacher'])) {
    header("Location: ../index.php");
    exit;
}

// School authentication and context
$current_school_id = require_school_auth();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['full_name'] ?? 'User';
$is_principal = ($user_role === 'principal');

$errors = [];
$success = '';

// Get lesson plan ID from URL
$plan_id = intval($_GET['id'] ?? 0);
if ($plan_id <= 0) {
    header("Location: lesson-plans.php");
    exit;
}

// Fetch lesson plan details - filtered by school_id
$stmt = $pdo->prepare("SELECT lp.*, s.subject_name, c.class_name, u.full_name as teacher_name,
                             u2.full_name as approved_by_name
                      FROM lesson_plans lp
                      JOIN subjects s ON lp.subject_id = s.id
                      JOIN classes c ON lp.class_id = c.id
                      JOIN users u ON lp.teacher_id = u.id
                      LEFT JOIN users u2 ON lp.approved_by = u2.id
                      WHERE lp.id = :id AND s.school_id = :school_id AND c.school_id = :school_id2");
$stmt->execute(['id' => $plan_id, 'school_id' => $current_school_id, 'school_id2' => $current_school_id]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    header("Location: lesson-plans.php");
    exit;
}

// Check permissions: teacher can only view own plans, principal can view all
if ($user_role === 'teacher' && $plan['teacher_id'] != $user_id) {
    header("Location: lesson-plans.php");
    exit;
}

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_comment') {
        $comment = trim($_POST['comment'] ?? '');

        if ($comment === '') {
            $errors[] = 'Comment cannot be empty.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO lesson_plan_feedback (lesson_plan_id, user_id, comment) 
                                  VALUES (:lesson_plan_id, :user_id, :comment)");
            $stmt->execute([
                'lesson_plan_id' => $plan_id,
                'user_id' => $user_id,
                'comment' => $comment
            ]);
            $success = 'Comment added successfully.';
            // Refresh page to show new comment
            header("Location: lesson-plans-detail.php?id=" . $plan_id);
            exit;
        }
    }

    if ($action === 'delete_comment' && $is_principal) {
        $comment_id = intval($_POST['comment_id'] ?? 0);
        if ($comment_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM lesson_plan_feedback WHERE id = :id AND lesson_plan_id = :plan_id");
            $stmt->execute(['id' => $comment_id, 'plan_id' => $plan_id]);
            $success = 'Comment deleted.';
            header("Location: lesson-plans-detail.php?id=" . $plan_id);
            exit;
        }
    }
}

// Fetch feedback/comments
$stmt = $pdo->prepare("SELECT lpf.*, u.full_name, u.id as user_id 
                      FROM lesson_plan_feedback lpf 
                      JOIN users u ON lpf.user_id = u.id 
                      WHERE lpf.lesson_plan_id = :plan_id 
                      ORDER BY lpf.created_at DESC");
  $stmt->execute(['plan_id' => $plan_id]);
$feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get status and approval badge colors
function getStatusBadge($status) {
    $classes = [
        'draft' => 'badge-secondary',
        'scheduled' => 'badge-primary',
        'completed' => 'badge-success',
        'on_hold' => 'badge-warning',
        'cancelled' => 'badge-danger'
    ];
    return $classes[$status] ?? 'badge-default';
}

function getApprovalBadge($status) {
    $classes = [
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'pending' => 'badge-warning'
    ];
    return $classes[$status] ?? 'badge-default';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lesson Plan Details | SahabFormMaster</title>
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

        /* Detail Cards */
        .detail-grid-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .detail-item-modern {
            background: var(--gray-50);
            padding: 1.5rem;
            border-radius: 16px;
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }

        .detail-item-modern:hover {
            background: white;
            box-shadow: var(--shadow-medium);
            transform: translateY(-2px);
        }

        .detail-label-modern {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
            display: block;
        }

        .detail-value-modern {
            font-size: 1rem;
            color: var(--gray-900);
            font-weight: 500;
        }

        .detail-content-modern {
            background: var(--gray-50);
            padding: 1.5rem;
            border-radius: 16px;
            border: 1px solid var(--gray-200);
            line-height: 1.6;
            white-space: pre-line;
        }

        /* Status Badges */
        .status-badges-modern {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .status-badge-modern {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .status-draft-modern {
            background: var(--accent-100);
            color: var(--accent-700);
        }

        .status-scheduled-modern {
            background: var(--primary-100);
            color: var(--primary-700);
        }

        .status-completed-modern {
            background: var(--success-100);
            color: var(--success-700);
        }

        .status-on-hold-modern,
        .status-cancelled-modern {
            background: var(--error-100);
            color: var(--error-700);
        }

        .approval-badge-modern {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .approval-pending-modern {
            background: var(--warning-100);
            color: var(--warning-700);
        }

        .approval-approved-modern {
            background: var(--success-100);
            color: var(--success-700);
        }

        .approval-rejected-modern {
            background: var(--error-100);
            color: var(--error-700);
        }

        /* Form Controls */
        .form-group-modern {
            margin-bottom: 1.5rem;
        }

        .form-label-modern {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            letter-spacing: 0.025em;
        }

        .form-textarea-modern {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            font-family: inherit;
            min-height: 100px;
            resize: vertical;
        }

        .form-textarea-modern:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .btn-modern-primary {
            padding: 1rem 2rem;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-medium);
        }

        .btn-modern-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-strong);
        }

        .btn-modern-secondary {
            padding: 1rem 2rem;
            background: var(--gray-100);
            color: var(--gray-700);
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-modern-secondary:hover {
            background: var(--gray-200);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Action Buttons */
        .action-buttons-modern {
            display: flex;
            gap: 1rem;
            justify-content: flex-start;
            flex-wrap: wrap;
        }

        /* Comments Section */
        .comments-section-modern {
            margin-top: 2rem;
        }

        .comment-form-modern {
            background: var(--gray-50);
            padding: 2rem;
            border-radius: 16px;
            border: 1px solid var(--gray-200);
            margin-bottom: 2rem;
        }

        .comment-form-title-modern {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 1.5rem;
        }

        .comments-list-modern {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .comment-item-modern {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow-soft);
            transition: all 0.3s ease;
        }

        .comment-item-modern:hover {
            box-shadow: var(--shadow-medium);
            transform: translateY(-2px);
        }

        .comment-header-modern {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .comment-author-modern {
            font-weight: 600;
            color: var(--gray-900);
            font-size: 1rem;
        }

        .comment-date-modern {
            font-size: 0.875rem;
            color: var(--gray-500);
        }

        .comment-body-modern {
            color: var(--gray-700);
            line-height: 1.6;
            white-space: pre-line;
        }

        .comment-delete-modern {
            background: var(--error-500);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .comment-delete-modern:hover {
            background: var(--error-600);
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

        .alert-error-modern {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-700);
            border-left: 4px solid var(--error-500);
        }

        .alert-warning-modern {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-700);
            border-left: 4px solid var(--warning-500);
        }

        /* Footer */
        .footer-modern {
            background: var(--gray-900);
            color: var(--gray-300);
            padding: 3rem 2rem 2rem;
            margin-top: 4rem;
            position: relative;
        }

        .footer-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gray-700), transparent);
        }

        .footer-content-modern {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .footer-section-modern h4 {
            color: white;
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 1rem;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .footer-section-modern p {
            margin-bottom: 0.75rem;
            line-height: 1.6;
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

            .detail-grid-modern {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .modern-card {
                margin-bottom: 1rem;
            }

            .card-header-modern,
            .card-body-modern {
                padding: 1.5rem;
            }

            .action-buttons-modern {
                flex-direction: column;
            }

            .status-badges-modern {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .modern-card {
                margin-bottom: 1rem;
            }

            .detail-item-modern {
                padding: 1rem;
            }

            .comment-item-modern {
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
        .font-medium { font-weight: 500; }
    </style>
</head>
<body>

    <!-- Modern Header -->
    <header class="modern-header">
        <div class="header-content">
            <div class="header-brand">
                <a href="lesson-plan.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Lesson Plans</span>
                </a>
                <div class="logo-container">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="brand-text">
                    <h1>SahabFormMaster</h1>
                    <p>Lesson Plan Details</p>
                </div>
            </div>
            <div class="header-actions">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <p><?php echo ucfirst($user_role); ?></p>
                        <span><?php echo htmlspecialchars($user_name); ?></span>
                    </div>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

<div class="main-container">
    <!-- Welcome Section -->
    <div class="modern-card animate-fade-in-up">
        <div class="card-header-modern">
            <h2 class="card-title-modern">
                <i class="fas fa-book-open"></i>
                Lesson Plan Details
            </h2>
            <p class="card-subtitle-modern">
                <?php echo htmlspecialchars($plan['subject_name']); ?> - <?php echo htmlspecialchars($plan['topic']); ?>
            </p>
        </div>
    </div>

    <main class="main-content">
        
        <!-- Alerts -->
        <?php if ($errors): ?>
            <div class="alert-modern alert-error-modern animate-fade-in-up">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars(implode(' ', $errors)); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-modern alert-success-modern animate-fade-in-up">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <!-- Plan Overview -->
        <div class="modern-card animate-fade-in-up">
            <div class="card-header-modern">
                <h3 class="card-title-modern">Lesson Plan Overview</h3>
                <div class="status-badges-modern">
                    <span class="status-badge-modern status-<?php echo $plan['status']; ?>-modern">
                        <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                        <?php echo ucfirst($plan['status']); ?>
                    </span>
                    <?php if ($is_principal): ?>
                        <span class="approval-badge-modern approval-<?php echo $plan['approval_status'] ?: 'pending'; ?>-modern">
                            <i class="fas fa-check-circle" style="font-size: 0.75rem;"></i>
                            <?php echo ucfirst($plan['approval_status'] ?? 'pending'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body-modern">
                <div class="detail-grid-modern">
                    <div class="detail-item-modern">
                        <span class="detail-label-modern">Subject</span>
                        <span class="detail-value-modern"><?php echo htmlspecialchars($plan['subject_name']); ?></span>
                    </div>
                    <div class="detail-item-modern">
                        <span class="detail-label-modern">Class</span>
                        <span class="detail-value-modern"><?php echo htmlspecialchars($plan['class_name']); ?></span>
                    </div>
                    <div class="detail-item-modern">
                        <span class="detail-label-modern">Topic/Unit</span>
                        <span class="detail-value-modern"><?php echo htmlspecialchars($plan['topic']); ?></span>
                    </div>
                    <div class="detail-item-modern">
                        <span class="detail-label-modern">Teacher</span>
                        <span class="detail-value-modern"><?php echo htmlspecialchars($plan['teacher_name']); ?></span>
                    </div>
                    <div class="detail-item-modern">
                        <span class="detail-label-modern">Duration</span>
                        <span class="detail-value-modern"><?php echo intval($plan['duration']); ?> minutes</span>
                    </div>
                    <div class="detail-item-modern">
                        <span class="detail-label-modern">Planned Date</span>
                        <span class="detail-value-modern"><?php echo htmlspecialchars($plan['date_planned']); ?></span>
                    </div>
                    <div class="detail-item-modern">
                        <span class="detail-label-modern">Assessment Method</span>
                        <span class="detail-value-modern"><?php echo htmlspecialchars($plan['assessment_method']); ?></span>
                    </div>
                    <div class="detail-item-modern">
                        <span class="detail-label-modern">Created</span>
                        <span class="detail-value-modern"><?php echo date('M d, Y h:i A', strtotime($plan['created_at'])); ?></span>
                    </div>

                    <?php if ($plan['approved_by'] && $plan['approved_by_name']): ?>
                    <div class="detail-item-modern">
                        <span class="detail-label-modern">Approved By</span>
                        <span class="detail-value-modern"><?php echo htmlspecialchars($plan['approved_by_name']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Learning Objectives -->
        <div class="modern-card animate-slide-in-left">
            <div class="card-header-modern">
                <h3 class="card-title-modern">
                    <i class="fas fa-bullseye"></i>
                    Learning Objectives
                </h3>
            </div>
            <div class="card-body-modern">
                <div class="detail-content-modern">
                    <?php echo nl2br(htmlspecialchars($plan['learning_objectives'])); ?>
                </div>
            </div>
        </div>

        <!-- Teaching Methods -->
        <?php if ($plan['teaching_methods']): ?>
        <div class="modern-card animate-slide-in-right">
            <div class="card-header-modern">
                <h3 class="card-title-modern">
                    <i class="fas fa-chalkboard-teacher"></i>
                    Teaching Methods
                </h3>
            </div>
            <div class="card-body-modern">
                <div class="detail-content-modern">
                    <?php echo nl2br(htmlspecialchars($plan['teaching_methods'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Resources -->
        <?php if ($plan['resources']): ?>
        <div class="modern-card animate-slide-in-left">
            <div class="card-header-modern">
                <h3 class="card-title-modern">
                    <i class="fas fa-tools"></i>
                    Learning Resources/Materials
                </h3>
            </div>
            <div class="card-body-modern">
                <div class="detail-content-modern">
                    <?php echo nl2br(htmlspecialchars($plan['resources'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Lesson Content -->
        <?php if ($plan['lesson_content']): ?>
        <div class="modern-card animate-slide-in-right">
            <div class="card-header-modern">
                <h3 class="card-title-modern">
                    <i class="fas fa-file-alt"></i>
                    Detailed Lesson Content
                </h3>
            </div>
            <div class="card-body-modern">
                <div class="detail-content-modern">
                    <?php echo nl2br(htmlspecialchars($plan['lesson_content'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Assessment -->
        <div class="modern-card animate-slide-in-left">
            <div class="card-header-modern">
                <h3 class="card-title-modern">
                    <i class="fas fa-clipboard-check"></i>
                    Assessment
                </h3>
            </div>
            <div class="card-body-modern">
                <div class="detail-item-modern" style="width: 100%;">
                    <span class="detail-label-modern">Assessment Method</span>
                    <span class="detail-value-modern"><?php echo htmlspecialchars($plan['assessment_method']); ?></span>
                </div>
                <?php if ($plan['assessment_tasks']): ?>
                <div class="detail-content-modern" style="margin-top: 1.5rem;">
                    <strong style="display: block; margin-bottom: 0.5rem; color: var(--gray-700);">Assessment Tasks:</strong>
                    <?php echo nl2br(htmlspecialchars($plan['assessment_tasks'])); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Differentiation -->
        <?php if ($plan['differentiation']): ?>
        <div class="modern-card animate-slide-in-right">
            <div class="card-header-modern">
                <h3 class="card-title-modern">
                    <i class="fas fa-users-cog"></i>
                    Differentiation Strategies
                </h3>
            </div>
            <div class="card-body-modern">
                <div class="detail-content-modern">
                    <?php echo nl2br(htmlspecialchars($plan['differentiation'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Homework -->
        <?php if ($plan['homework']): ?>
        <div class="modern-card animate-slide-in-left">
            <div class="card-header-modern">
                <h3 class="card-title-modern">
                    <i class="fas fa-home"></i>
                    Homework/Assignment
                </h3>
            </div>
            <div class="card-body-modern">
                <div class="detail-content-modern">
                    <?php echo nl2br(htmlspecialchars($plan['homework'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Principal's Remarks -->
        <?php if ($plan['principal_remarks']): ?>
        <div class="modern-card animate-slide-in-right" style="border-left: 4px solid var(--warning-500);">
            <div class="card-header-modern" style="background: var(--warning-500);">
                <h3 class="card-title-modern">
                    <i class="fas fa-bell"></i>
                    Principal's Remarks
                </h3>
            </div>
            <div class="card-body-modern">
                <div class="detail-content-modern">
                    <?php echo nl2br(htmlspecialchars($plan['principal_remarks'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="modern-card animate-slide-in-left">
            <div class="card-header-modern">
                <h3 class="card-title-modern">
                    <i class="fas fa-cogs"></i>
                    Actions
                </h3>
            </div>
            <div class="card-body-modern">
                <div class="action-buttons-modern">
                    <!-- <?php if ($user_role === 'teacher' && $plan['teacher_id'] == $user_id && $plan['status'] === 'draft'): ?>
                        <a href="lesson-plans.php?edit=<?php echo intval($plan['id']); ?>" class="btn-modern-primary">
                            <i class="fas fa-edit"></i>
                            <span>Edit Lesson Plan</span>
                        </a>
                    <?php elseif ($is_principal): ?>
                        <a href="lesson-plans.php?edit=<?php echo intval($plan['id']); ?>" class="btn-modern-primary">
                            <i class="fas fa-edit"></i>
                            <span>Edit Lesson Plan</span>
                        </a>
                    <?php endif; ?> -->

                    <a href="lesson-plan.php" class="btn-modern-secondary">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to List</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Feedback/Comments Section -->
        <div class="modern-card animate-slide-in-right">
            <div class="card-header-modern">
                <h3 class="card-title-modern">
                    <i class="fas fa-comments"></i>
                    Feedback & Comments
                </h3>
            </div>
            <div class="card-body-modern">
                <?php if ($plan['approval_status'] === 'rejected'): ?>
                    <div class="alert-modern alert-warning-modern animate-fade-in-up">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><strong>This lesson plan was rejected.</strong> Please review the feedback below and edit accordingly.</span>
                    </div>
                <?php endif; ?>

                <!-- Add Comment Form -->
                <div class="comment-form-modern">
                    <h4 class="comment-form-title-modern">Add Your Comment</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_comment">
                        <div class="form-group-modern">
                            <label class="form-label-modern" for="comment">
                                <i class="fas fa-comment"></i>
                                Your Feedback
                            </label>
                            <textarea name="comment" id="comment" class="form-textarea-modern" rows="3" placeholder="Enter your feedback or comment..." required></textarea>
                        </div>
                        <button type="submit" class="btn-modern-primary">
                            <i class="fas fa-paper-plane"></i>
                            <span>Post Comment</span>
                        </button>
                    </form>
                </div>

                <!-- Comments List -->
                <div class="comments-list-modern">
                    <?php if (count($feedback) === 0): ?>
                        <p style="text-align: center; color: var(--gray-500); padding: 2rem; font-style: italic;">No comments yet.</p>
                    <?php else: ?>
                        <?php foreach ($feedback as $f): ?>
                            <div class="comment-item-modern">
                                <div class="comment-header-modern">
                                    <span class="comment-author-modern">
                                        <i class="fas fa-user" style="margin-right: 0.5rem;"></i>
                                        <?php echo htmlspecialchars($f['full_name']); ?>
                                    </span>
                                    <span class="comment-date-modern">
                                        <i class="fas fa-clock" style="margin-right: 0.25rem;"></i>
                                        <?php echo date('M d, Y h:i A', strtotime($f['created_at'])); ?>
                                    </span>
                                    <?php if ($is_principal): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_comment">
                                            <input type="hidden" name="comment_id" value="<?php echo intval($f['id']); ?>">
                                            <button type="submit" class="comment-delete-modern" onclick="return confirm('Delete this comment?');" title="Delete comment">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <div class="comment-body-modern">
                                    <?php echo nl2br(htmlspecialchars($f['comment'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

    

<?php include '../includes/floating-button.php'; ?>
</body>
</html>
