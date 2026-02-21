<?php
// student/payment_details.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../helpers/payment_helper.php';
require_once '../TCPDF-main/TCPDF-main/tcpdf.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$studentId = $_SESSION['student_id'];
$current_school_id = get_current_school_id();
$paymentHelper = new PaymentHelper();

// Get payment ID from URL
$paymentId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$paymentId) {
    header("Location: payment.php");
    exit;
}

// Fetch payment details with school isolation
$stmt = $pdo->prepare("
    SELECT sp.*, s.full_name, s.admission_no, s.class_id, c.class_name
    FROM student_payments sp
    JOIN students s ON sp.student_id = s.id
    JOIN classes c ON s.class_id = c.id
    WHERE sp.id = ? AND sp.student_id = ? AND s.school_id = ?
");
$stmt->execute([$paymentId, $studentId, $current_school_id]);
$payment = $stmt->fetch();

if (!$payment) {
    header("Location: payment.php");
    exit;
}

// Fetch school information
$stmt = $pdo->prepare("
    SELECT school_name, school_code, address, phone, email, logo, motto, principal_name
    FROM schools
    WHERE id = ?
");
$stmt->execute([$current_school_id]);
$school = $stmt->fetch();

// Handle PDF download
if (isset($_GET['download']) && $_GET['download'] === 'pdf') {
    if ($payment['status'] !== 'completed' || empty($payment['receipt_number'])) {
        header("Location: payment_details.php?id=" . $paymentId . "&error=receipt_unavailable");
        exit;
    }
    generatePaymentReceiptPDF($payment, $paymentHelper, $school);
    exit;
}

function generatePaymentReceiptPDF($payment, $paymentHelper, $school) {
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator($school['school_name'] . ' - SahabFormMaster');
    $pdf->SetAuthor($school['school_name']);
    $pdf->SetTitle('Payment Receipt - ' . $payment['receipt_number']);
    $pdf->SetSubject('School Fee Payment Receipt');

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Set margins
    $pdf->SetMargins(15, 15, 15);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);

    // Add a page
    $pdf->AddPage();

    // School Header with Logo
    if (!empty($school['logo']) && file_exists('../' . $school['logo'])) {
        // Add school logo
        $pdf->Image('../' . $school['logo'], 15, 15, 30, 30, '', '', '', false, 300, '', false, false, 0, false, false, false);
        $pdf->SetXY(50, 15);
    } else {
        $pdf->SetXY(15, 15);
    }

    // School name and motto
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, $school['school_name'], 0, 1, 'L');

    if (!empty($school['motto'])) {
        $pdf->SetFont('helvetica', 'I', 12);
        $pdf->Cell(0, 8, '"' . $school['motto'] . '"', 0, 1, 'L');
    }

    // School contact information
    $pdf->SetFont('helvetica', '', 10);
    $contactInfo = [];
    if (!empty($school['address'])) $contactInfo[] = $school['address'];
    if (!empty($school['phone'])) $contactInfo[] = 'Tel: ' . $school['phone'];
    if (!empty($school['email'])) $contactInfo[] = 'Email: ' . $school['email'];

    if (!empty($contactInfo)) {
        $pdf->Cell(0, 6, implode(' | ', $contactInfo), 0, 1, 'L');
    }

    $pdf->Ln(15);

    // Receipt title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 15, 'PAYMENT RECEIPT', 0, 1, 'C');
    $pdf->Ln(5);

    // Receipt title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 15, 'PAYMENT RECEIPT', 0, 1, 'C');
    $pdf->Ln(5);

    // Receipt number and date
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(95, 10, 'Receipt No: ' . $payment['receipt_number'], 1, 0, 'L');
    $pdf->Cell(95, 10, 'Date: ' . date('d/m/Y', strtotime($payment['payment_date'])), 1, 1, 'L');

    $pdf->Ln(5);

    // Student information
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Student Information', 1, 1, 'L');
    $pdf->SetFont('helvetica', '', 11);

    $pdf->Cell(50, 8, 'Full Name:', 1, 0, 'L');
    $pdf->Cell(0, 8, $payment['full_name'], 1, 1, 'L');

    $pdf->Cell(50, 8, 'Admission No:', 1, 0, 'L');
    $pdf->Cell(0, 8, $payment['admission_no'], 1, 1, 'L');

    $pdf->Cell(50, 8, 'Class:', 1, 0, 'L');
    $pdf->Cell(0, 8, $payment['class_name'], 1, 1, 'L');

    $pdf->Ln(5);

    // Payment details
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Payment Details', 1, 1, 'L');
    $pdf->SetFont('helvetica', '', 11);

    $pdf->Cell(50, 8, 'Academic Year:', 1, 0, 'L');
    $pdf->Cell(0, 8, $payment['academic_year'], 1, 1, 'L');

    $pdf->Cell(50, 8, 'Term:', 1, 0, 'L');
    $pdf->Cell(0, 8, $payment['term'], 1, 1, 'L');

    $pdf->Cell(50, 8, 'Payment Method:', 1, 0, 'L');
    $pdf->Cell(0, 8, ucwords(str_replace('_', ' ', $payment['payment_method'])), 1, 1, 'L');

    $pdf->Cell(50, 8, 'Payment Type:', 1, 0, 'L');
    $pdf->Cell(0, 8, ucwords(str_replace('_', ' ', $payment['payment_type'])), 1, 1, 'L');

    $pdf->Cell(50, 8, 'Amount Paid:', 1, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, $paymentHelper->formatCurrency($payment['amount_paid']), 1, 1, 'L');
    $pdf->SetFont('helvetica', '', 11);

    $pdf->Cell(50, 8, 'Status:', 1, 0, 'L');
    $status = ucfirst(str_replace('_', ' ', $payment['status']));
    $pdf->Cell(0, 8, $status, 1, 1, 'L');

    if (!empty($payment['notes'])) {
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Notes', 1, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        $pdf->MultiCell(0, 8, $payment['notes'], 1, 'L');
    }

    $pdf->Ln(15);

    // Footer
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell(0, 10, 'This is a computer-generated receipt. Thank you for your payment.', 0, 1, 'C');

    // Output PDF
    $pdf->Output('payment_receipt_' . $payment['receipt_number'] . '.pdf', 'D');
}

$student_name = $_SESSION['student_name'];
$admission_number = $_SESSION['admission_no'];
?>

<!DOCTYPE html>
<html lang="en">
<head>`r`n<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Details | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        :root {
            --primary-color: #6366f1;
            --primary-dark: #4f46e5;
            --secondary-color: #06b6d4;
            --accent-color: #8b5cf6;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --info-color: #3b82f6;

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

            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);

            --border-radius-sm: 0.375rem;
            --border-radius-md: 0.5rem;
            --border-radius-lg: 0.75rem;
            --border-radius-xl: 1rem;

            --transition-fast: 0.15s ease-in-out;
            --transition-normal: 0.3s ease-in-out;
        }

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

        .dashboard-header {
            background: var(--white);
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 1000;
            transition: var(--transition-normal);
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

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            max-width: calc(100vw - 280px);
        }

        .card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition-normal);
            border: 1px solid var(--gray-200);
            margin-bottom: 2rem;
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

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .receipt-header {
            text-align: center;
            margin-bottom: 2rem;
            padding: 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: var(--border-radius-xl);
        }

        .receipt-title {
            font-family: 'Poppins', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .receipt-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .receipt-number {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .receipt-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .receipt-section {
            background: var(--gray-50);
            padding: 1.5rem;
            border-radius: var(--border-radius-lg);
            border: 1px solid var(--gray-200);
        }

        .section-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .receipt-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .receipt-row:last-child {
            border-bottom: none;
        }

        .receipt-label {
            font-weight: 500;
            color: var(--gray-700);
        }

        .receipt-value {
            font-weight: 600;
            color: var(--gray-900);
        }

        .receipt-amount {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--success-color);
        }

        .status-badge {
            padding: 0.375rem 0.875rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .status-completed {
            background: var(--success-color);
            color: white;
        }

        .status-pending {
            background: var(--warning-color);
            color: white;
        }

        .status-verified {
            background: var(--info-color);
            color: white;
        }

        .status-partial {
            background: var(--secondary-color);
            color: white;
        }

        .status-rejected {
            background: var(--error-color);
            color: white;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
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

            .receipt-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .header-container {
                padding: 0 1rem;
                height: 70px;
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .receipt-header {
                padding: 1.5rem;
            }

            .receipt-title {
                font-size: 1.5rem;
            }

            .action-buttons {
                flex-direction: column;
            }
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
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Student Portal</p>
                    </div>
                </div>
            </div>

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
            <!-- Receipt Header -->
            <div class="receipt-header">
                <h1 class="receipt-title">
                    <i class="fas fa-receipt"></i>
                    Payment Receipt
                </h1>
                <p class="receipt-subtitle">Payment details and confirmation</p>
                <div class="receipt-number">
                    Receipt #<?php echo htmlspecialchars($payment['receipt_number']); ?>
                </div>
            </div>

            <!-- School Information Section -->
            <?php if (!empty($school)): ?>
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-school"></i> School Information</h4>
                </div>
                <div class="card-body">
                    <div style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 1rem;">
                        <?php if (!empty($school['logo']) && file_exists('../' . $school['logo'])): ?>
                            <img src="../<?php echo htmlspecialchars($school['logo']); ?>" alt="School Logo"
                                 style="width: 80px; height: 80px; object-fit: cover; border-radius: var(--border-radius-lg); box-shadow: var(--shadow-md);">
                        <?php else: ?>
                            <div style="width: 80px; height: 80px; background: var(--primary-color); border-radius: var(--border-radius-lg); display: flex; align-items: center; justify-content: center; color: white; font-size: 2rem; box-shadow: var(--shadow-md);">
                                <i class="fas fa-school"></i>
                            </div>
                        <?php endif; ?>

                        <div>
                            <h3 style="margin: 0 0 0.5rem 0; color: var(--gray-900); font-size: 1.5rem;"><?php echo htmlspecialchars($school['school_name']); ?></h3>
                            <?php if (!empty($school['motto'])): ?>
                                <p style="margin: 0; color: var(--primary-color); font-style: italic; font-weight: 500;">
                                    "<?php echo htmlspecialchars($school['motto']); ?>"
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <?php if (!empty($school['address'])): ?>
                        <div class="receipt-row" style="border: none; padding: 0.5rem 0;">
                            <span class="receipt-label"><i class="fas fa-map-marker-alt" style="margin-right: 0.5rem;"></i>Address:</span>
                            <span class="receipt-value"><?php echo htmlspecialchars($school['address']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($school['phone'])): ?>
                        <div class="receipt-row" style="border: none; padding: 0.5rem 0;">
                            <span class="receipt-label"><i class="fas fa-phone" style="margin-right: 0.5rem;"></i>Phone:</span>
                            <span class="receipt-value"><?php echo htmlspecialchars($school['phone']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($school['email'])): ?>
                        <div class="receipt-row" style="border: none; padding: 0.5rem 0;">
                            <span class="receipt-label"><i class="fas fa-envelope" style="margin-right: 0.5rem;"></i>Email:</span>
                            <span class="receipt-value"><?php echo htmlspecialchars($school['email']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($school['principal_name'])): ?>
                        <div class="receipt-row" style="border: none; padding: 0.5rem 0;">
                            <span class="receipt-label"><i class="fas fa-user-tie" style="margin-right: 0.5rem;"></i>Principal:</span>
                            <span class="receipt-value"><?php echo htmlspecialchars($school['principal_name']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Receipt Grid -->
            <div class="receipt-grid">
                <!-- Student Information -->
                <div class="receipt-section">
                    <h3 class="section-title">
                        <i class="fas fa-user-graduate"></i>
                        Student Information
                    </h3>
                    <div class="receipt-row">
                        <span class="receipt-label">Full Name:</span>
                        <span class="receipt-value"><?php echo htmlspecialchars($payment['full_name']); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Admission Number:</span>
                        <span class="receipt-value"><?php echo htmlspecialchars($payment['admission_no']); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Class:</span>
                        <span class="receipt-value"><?php echo htmlspecialchars($payment['class_name']); ?></span>
                    </div>
                </div>

                <!-- Payment Information -->
                <div class="receipt-section">
                    <h3 class="section-title">
                        <i class="fas fa-credit-card"></i>
                        Payment Information
                    </h3>
                    <div class="receipt-row">
                        <span class="receipt-label">Academic Year:</span>
                        <span class="receipt-value"><?php echo htmlspecialchars($payment['academic_year']); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Term:</span>
                        <span class="receipt-value"><?php echo htmlspecialchars($payment['term']); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Payment Method:</span>
                        <span class="receipt-value"><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Payment Type:</span>
                        <span class="receipt-value"><?php echo ucwords(str_replace('_', ' ', $payment['payment_type'])); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Payment Date:</span>
                        <span class="receipt-value"><?php echo date('d/m/Y H:i', strtotime($payment['payment_date'])); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span class="receipt-label">Status:</span>
                        <span class="status-badge status-<?php echo $payment['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $payment['status'])); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Amount Section -->
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-money-bill-wave"></i> Payment Amount</h4>
                </div>
                <div class="card-body">
                    <div class="receipt-row">
                        <span class="receipt-label">Amount Paid:</span>
                        <span class="receipt-amount"><?php echo $paymentHelper->formatCurrency($payment['amount_paid']); ?></span>
                    </div>
                    <?php if (!empty($payment['notes'])): ?>
                    <div style="margin-top: 1.5rem; padding: 1rem; background: var(--gray-50); border-radius: var(--border-radius-md); border-left: 4px solid var(--primary-color);">
                        <strong style="color: var(--gray-900);">Notes:</strong>
                        <p style="margin: 0.5rem 0 0 0; color: var(--gray-700);"><?php echo nl2br(htmlspecialchars($payment['notes'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="payment.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i>
                    Back to Payments
                </a>
                <?php if ($payment['status'] === 'completed' && !empty($payment['receipt_number'])): ?>
                    <a href="?id=<?php echo $paymentId; ?>&download=pdf" class="btn btn-success">
                        <i class="fas fa-download"></i>
                        Download PDF Receipt
                    </a>
                <?php endif; ?>
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

        mobileMenuToggle?.addEventListener('click', () => {
            sidebar?.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
        });

        sidebarClose?.addEventListener('click', () => {
            sidebar?.classList.remove('active');
            mobileMenuToggle?.classList.remove('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 1024) {
                if (sidebar && !sidebar.contains(e.target) && mobileMenuToggle && !mobileMenuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                }
            }
        });
    </script>

    <?php include '../includes/floating-button.php'; ?>
</body>
</html>




