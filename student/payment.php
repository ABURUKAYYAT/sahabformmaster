<?php
// student/payment.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../helpers/payment_helper.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$studentId = $_SESSION['student_id'];
$current_school_id = get_current_school_id();
$student_name = $_SESSION['student_name'];
$admission_number = $_SESSION['admission_no'];
$paymentHelper = new PaymentHelper();

// Get student details
$stmt = $pdo->prepare("SELECT s.*, c.class_name FROM students s
                      JOIN classes c ON s.class_id = c.id AND c.school_id = ?
                      WHERE s.id = ? AND s.school_id = ?");
$stmt->execute([$current_school_id, $studentId, $current_school_id]);
$student = $stmt->fetch();

// Get current term/year
$currentTerm = '1st Term';
$currentYear = date('Y') . '/' . (date('Y') + 1);

// Get fee breakdown
$feeBreakdown = $paymentHelper->getFeeBreakdown($student['class_id'], $currentTerm, $currentYear);

// Calculate total fee and balance
$totalFee = $feeBreakdown['total'];
$paymentHistory = $paymentHelper->getStudentPaymentHistory($student['id']);
$paidAmount = 0;
foreach ($paymentHistory as $payment) {
    if ($payment['term'] == $currentTerm && $payment['academic_year'] == $currentYear) {
        $paidAmount += $payment['amount_paid'];
    }
}
$balance = max(0, $totalFee - $paidAmount);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Payment Portal - SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        /* ===================================================
           Payment Page - Modern Internal Styles
           Based on Dashboard Pattern
           =================================================== */

        :root {
            /* Color Palette - Student Focused */
            --primary-color: #6366f1;
            --primary-dark: #4f46e5;
            --secondary-color: #06b6d4;
            --accent-color: #8b5cf6;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --info-color: #3b82f6;

            /* Gradient Colors for Cards */
            --gradient-1: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            --gradient-2: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            --gradient-3: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --gradient-4: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --gradient-5: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            --gradient-6: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);

            /* Neutral Colors */
            --white: #ffffff;
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

            /* Shadows */
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);

            /* Border Radius */
            --border-radius-sm: 0.375rem;
            --border-radius-md: 0.5rem;
            --border-radius-lg: 0.75rem;
            --border-radius-xl: 1rem;

            /* Transitions */
            --transition-fast: 0.15s ease-in-out;
            --transition-normal: 0.3s ease-in-out;
            --transition-slow: 0.5s ease-in-out;
        }

        /* ===================================================
           Global Styles
           =================================================== */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            min-height: 100vh;
            color: var(--gray-800);
            line-height: 1.6;
        }

        /* ===================================================
           Mobile Menu Toggle
           =================================================== */

        .mobile-menu-toggle {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 999;
            background: var(--primary-color);
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
            transition: var(--transition-normal);
        }

        .mobile-menu-toggle:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }

        .mobile-menu-toggle.active {
            background: var(--error-color);
        }

        /* ===================================================
           Header Styles
           =================================================== */

        .dashboard-header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 1000;
            transition: var(--transition-normal);
        }

        .dashboard-header.scrolled {
            box-shadow: var(--shadow-md);
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 80px;
        }

        .school-logo-container {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .school-logo {
            width: 50px;
            height: 50px;
            border-radius: var(--border-radius-md);
            object-fit: cover;
            box-shadow: var(--shadow-sm);
        }

        .school-info h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.125rem;
        }

        .school-tagline {
            font-size: 0.875rem;
            color: var(--gray-500);
            font-weight: 500;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .student-info {
            text-align: right;
        }

        .student-label {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .student-name {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-900);
            display: block;
        }

        .admission-number {
            font-size: 0.875rem;
            color: var(--primary-color);
            font-weight: 500;
        }

        .btn-logout {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--error-color);
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius-md);
            font-weight: 500;
            transition: var(--transition-fast);
            box-shadow: var(--shadow-sm);
        }

        .btn-logout:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* ===================================================
           Dashboard Container
           =================================================== */

        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 80px);
        }

        /* ===================================================
           Sidebar Styles
           =================================================== */

        .sidebar {
            width: 280px;
            background: var(--white);
            box-shadow: var(--shadow-md);
            position: fixed;
            left: 0;
            top: 80px;
            height: calc(100vh - 80px);
            overflow-y: auto;
            z-index: 999;
            transition: var(--transition-normal);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sidebar-header h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
        }

        .sidebar-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--gray-400);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--border-radius-sm);
            transition: var(--transition-fast);
        }

        .sidebar-close:hover {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-list {
            list-style: none;
        }

        .nav-item {
            margin-bottom: 0.25rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            color: var(--gray-600);
            text-decoration: none;
            transition: var(--transition-fast);
            border-left: 3px solid transparent;
        }

        .nav-link:hover {
            background: var(--gray-50);
            color: var(--primary-color);
            border-left-color: var(--primary-color);
        }

        .nav-link.active {
            background: var(--primary-color);
            color: white;
            border-left-color: var(--primary-color);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .nav-icon {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .nav-text {
            font-weight: 500;
            font-size: 0.95rem;
        }

        /* ===================================================
           Main Content Styles
           =================================================== */

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            max-width: calc(100vw - 280px);
        }

        /* ===================================================
           Card Styles
           =================================================== */

        .card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition-normal);
            border: 1px solid var(--gray-200);
        }

        .card:hover {
            box-shadow: var(--shadow-xl);
            transform: translateY(-5px);
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: var(--gray-50);
        }

        .card-header h4 {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* ===================================================
           Row and Column Grid
           =================================================== */

        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -0.75rem;
        }

        .col-md-4, .col-md-6 {
            padding: 0 0.75rem;
            margin-bottom: 1.5rem;
        }

        .col-md-4 {
            flex: 0 0 33.333333%;
            max-width: 33.333333%;
        }

        .col-md-6 {
            flex: 0 0 50%;
            max-width: 50%;
        }

        /* ===================================================
           Form Styles
           =================================================== */

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius-lg);
            background: var(--white);
            color: var(--gray-700);
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: var(--transition-fast);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .form-control[readonly] {
            background: var(--gray-50);
            cursor: not-allowed;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius-md);
            font-size: 0.95rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition-fast);
            font-family: 'Inter', sans-serif;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .btn-outline-primary {
            background: transparent;
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }

        .btn-outline-primary:hover {
            background: var(--primary-color);
            color: white;
        }

        /* ===================================================
           Table Styles
           =================================================== */

        .table-responsive {
            overflow-x: auto;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-sm);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            background: var(--white);
        }

        .table th,
        .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }

        .table th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--gray-900);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .table td {
            color: var(--gray-700);
            font-size: 0.9rem;
        }

        .table-striped tbody tr:nth-child(odd) {
            background: var(--gray-50);
        }

        .table-striped tbody tr:hover {
            background: var(--gray-100);
        }

        /* ===================================================
           Badge Styles
           =================================================== */

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .badge-success {
            background: var(--success-color);
            color: white;
        }

        .badge-warning {
            background: var(--warning-color);
            color: white;
        }

        .badge-secondary {
            background: var(--gray-400);
            color: white;
        }

        /* ===================================================
           Footer Styles
           =================================================== */

        .dashboard-footer {
            background: var(--gray-900);
            color: var(--gray-300);
            margin-top: 4rem;
        }

        .footer-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 3rem 2rem 1rem;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .footer-section h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 1rem;
        }

        .footer-section p {
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.5rem;
        }

        .footer-links a {
            color: var(--gray-300);
            text-decoration: none;
            transition: var(--transition-fast);
        }

        .footer-links a:hover {
            color: var(--primary-color);
        }

        .footer-bottom {
            padding-top: 2rem;
            border-top: 1px solid var(--gray-700);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .footer-copyright {
            color: var(--gray-400);
            font-size: 0.9rem;
        }

        .footer-version {
            color: var(--gray-400);
            font-size: 0.9rem;
        }

        .footer-bottom-links {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .footer-bottom-links a {
            color: var(--gray-400);
            text-decoration: none;
            font-size: 0.9rem;
            transition: var(--transition-fast);
        }

        .footer-bottom-links a:hover {
            color: var(--primary-color);
        }

        /* ===================================================
           Responsive Design
           =================================================== */

        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 999;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1.5rem;
                width: 100%;
                max-width: 100%;
            }

            .mobile-menu-toggle {
                display: flex;
            }

            .col-md-4, .col-md-6 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }

        @media (max-width: 768px) {
            .header-container {
                padding: 0 1rem;
                height: 70px;
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .school-logo-container {
                order: 1;
                flex: 1;
                min-width: 0;
            }

            .school-info h1 {
                font-size: 1.25rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .school-tagline {
                font-size: 0.8rem;
            }

            .header-right {
                order: 2;
                gap: 0.75rem;
            }

            .student-info {
                text-align: left;
            }

            .student-label {
                font-size: 0.7rem;
            }

            .student-name {
                font-size: 0.85rem;
            }

            .admission-number {
                font-size: 0.75rem;
            }

            .btn-logout {
                padding: 0.5rem 0.75rem;
                font-size: 0.85rem;
            }

            .btn-logout span:last-child {
                display: none;
            }

            .card-body {
                padding: 1rem;
            }

            .table th,
            .table td {
                padding: 0.75rem 0.5rem;
                font-size: 0.8rem;
            }

            .footer-content {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .footer-section h4 {
                font-size: 1rem;
            }

            .footer-bottom {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .footer-bottom-links {
                justify-content: center;
            }
        }

        /* ===================================================
           Utility Classes
           =================================================== */

        .text-center { text-align: center; }
        .text-muted { color: var(--gray-500); font-size: 0.875rem; }

        /* ===================================================
           Payment Specific Styles
           =================================================== */

        .payment-summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }

        .fee-summary-cards .card {
            transition: var(--transition-normal);
        }

        .fee-summary-cards .card:hover {
            transform: translateY(-3px);
        }

        .fee-summary-cards .card-body {
            text-align: center;
            padding: 2rem 1.5rem;
        }

        .fee-summary-cards .card-body .fa-2x {
            margin-bottom: 1rem;
            opacity: 0.9;
        }

        .fee-summary-cards h3 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .fee-summary-cards p {
            margin: 0;
            opacity: 0.9;
            font-weight: 500;
        }

        .card-blue { border-left: 4px solid #007bff; }
        .card-green { border-left: 4px solid #28a745; }
        .card-yellow { border-left: 4px solid #ffc107; }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--gray-600);
        }

        .empty-state .fa-3x {
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h5 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--gray-700);
        }

        .empty-state p {
            margin: 0;
            font-size: 0.95rem;
        }

        /* Animation Classes */
        .fade-in {
            animation: fadeIn 0.6s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <!-- Logo and School Name -->
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Student Portal</p>
                    </div>
                </div>
            </div>

            <!-- Student Info and Logout -->
            <div class="header-right">
                <div class="student-info">
                    <p class="student-label">Student</p>
                    <span class="student-name"><?php echo htmlspecialchars($student_name); ?></span>
                    <span class="admission-number"><?php echo htmlspecialchars($admission_number); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="dashboard-container">
        <?php include '../includes/student_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Welcome Section -->
            <div class="card" style="margin-bottom: 2rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
                <div class="card-body" style="padding: 2rem;">
                    <h2 style="margin-bottom: 0.5rem;"><i class="fas fa-credit-card"></i> Student Payment Portal</h2>
                    <p style="margin: 0; opacity: 0.9;">Welcome, <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['class_name']); ?>)</p>
                </div>
            </div>

            <!-- Fee Summary -->
            <div class="row" style="margin-bottom: 2rem;">
                <div class="col-md-4">
                    <div class="card text-center" style="border-left: 4px solid #007bff;">
                        <div class="card-body">
                            <div style="font-size: 2rem; color: #007bff; margin-bottom: 0.5rem;"><i class="fas fa-money-bill-wave"></i></div>
                            <h3 style="color: #004085;"><?php echo $paymentHelper->formatCurrency($totalFee); ?></h3>
                            <p style="margin: 0; color: #004085;">Total Fee</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center" style="border-left: 4px solid #28a745;">
                        <div class="card-body">
                            <div style="font-size: 2rem; color: #28a745; margin-bottom: 0.5rem;"><i class="fas fa-check-circle"></i></div>
                            <h3 style="color: #155724;"><?php echo $paymentHelper->formatCurrency($paidAmount); ?></h3>
                            <p style="margin: 0; color: #155724;">Amount Paid</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center" style="border-left: 4px solid #ffc107;">
                        <div class="card-body">
                            <div style="font-size: 2rem; color: #ffc107; margin-bottom: 0.5rem;"><i class="fas fa-balance-scale"></i></div>
                            <h3 style="color: #856404;"><?php echo $paymentHelper->formatCurrency($balance); ?></h3>
                            <p style="margin: 0; color: #856404;">Balance Due</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Form -->
            <div class="card" style="margin-bottom: 2rem;">
                <div class="card-header">
                    <h4 style="margin: 0;"><i class="fas fa-plus-circle"></i> Make a Payment</h4>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group" style="margin-bottom: 1.5rem;">
                                    <label>Academic Year</label>
                                    <input type="text" class="form-control" value="<?php echo $currentYear; ?>" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group" style="margin-bottom: 1.5rem;">
                                    <label>Term</label>
                                    <select class="form-control" name="term">
                                        <option value="1st Term" selected>1st Term</option>
                                        <option value="2nd Term">2nd Term</option>
                                        <option value="3rd Term">3rd Term</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group" style="margin-bottom: 1.5rem;">
                                    <label>Payment Method</label>
                                    <select class="form-control" name="payment_method" required>
                                        <option value="bank_transfer" selected>Bank Transfer</option>
                                        <option value="cash">Cash</option>
                                        <option value="online">Online Payment</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group" style="margin-bottom: 1.5rem;">
                                    <label>Payment Type</label>
                                    <select class="form-control" name="payment_type" required>
                                        <option value="full" selected>Full Payment</option>
                                        <option value="installment">Installment Payment</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 1.5rem;">
                            <label>Amount (₦)</label>
                            <input type="number" class="form-control" name="amount" min="1000" step="0.01"
                                   value="<?php echo $balance; ?>" required>
                            <small class="text-muted">Available balance: <?php echo $paymentHelper->formatCurrency($balance); ?></small>
                        </div>

                        <div class="form-group" style="margin-bottom: 1.5rem;">
                            <label>Payment Proof</label>
                            <input type="file" class="form-control" name="payment_proof" accept=".jpg,.jpeg,.png,.pdf">
                            <small class="text-muted">Upload bank slip or payment receipt (Max: 5MB)</small>
                        </div>

                        <div class="form-group" style="margin-bottom: 1.5rem;">
                            <label>Notes</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="Optional notes..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-paper-plane"></i> Submit Payment
                        </button>
                    </form>
                </div>
            </div>

            <!-- Payment History -->
            <div class="card">
                <div class="card-header">
                    <h4 style="margin: 0;"><i class="fas fa-history"></i> Payment History</h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($paymentHistory)): ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Receipt No</th>
                                        <th>Date</th>
                                        <th>Term</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paymentHistory as $payment): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($payment['receipt_number']); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($payment['term']); ?></td>
                                            <td><?php echo $paymentHelper->formatCurrency($payment['amount_paid']); ?></td>
                                            <td><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                            <td>
                                                <span class="badge badge-<?php
                                                    echo $payment['status'] === 'completed' ? 'success' :
                                                         ($payment['status'] === 'pending_verification' ? 'warning' : 'secondary');
                                                ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $payment['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewPayment(<?php echo $payment['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 3rem; color: #6c757d;">
                            <i class="fas fa-receipt" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                            <h5>No Payment History</h5>
                            <p>You haven't made any payments yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarClose = document.getElementById('sidebarClose');

        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });

        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });

        // Add active class to current page in sidebar
        document.addEventListener('DOMContentLoaded', () => {
            const currentPage = window.location.pathname.split('/').pop();
            const navLinks = document.querySelectorAll('.nav-link');

            navLinks.forEach(link => {
                if (link.getAttribute('href') === currentPage) {
                    link.classList.add('active');
                }
            });
        });

        // Add scroll effect to header
        window.addEventListener('scroll', () => {
            const header = document.querySelector('.dashboard-header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Animate cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                }
            });
        }, observerOptions);

        // Observe cards
        document.querySelectorAll('.card').forEach(card => {
            observer.observe(card);
        });

        function viewPayment(paymentId) {
            // Redirect to payment details page
            window.location.href = 'payment_details.php?id=' + paymentId;
        }

        // Form validation enhancement
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', (e) => {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                        submitBtn.disabled = true;
                    }
                });
            }
        });

        // File input enhancement
        document.addEventListener('DOMContentLoaded', () => {
            const fileInput = document.querySelector('input[name="payment_proof"]');
            if (fileInput) {
                fileInput.addEventListener('change', (e) => {
                    const file = e.target.files[0];
                    if (file) {
                        const fileSize = file.size / 1024 / 1024; // MB
                        if (fileSize > 5) {
                            alert('File size must be less than 5MB');
                            e.target.value = '';
                        }
                    }
                });
            }
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
