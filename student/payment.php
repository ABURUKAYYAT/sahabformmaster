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
PaymentHelper::ensureSchema();
$paymentConfig = include('../config/payment_config.php');
$feeTypeLabels = $paymentConfig['fee_types'] ?? [];
$bankAccounts = PaymentHelper::getSchoolBankAccounts($current_school_id);

// Get student details
$stmt = $pdo->prepare("SELECT s.*, c.class_name FROM students s
                      JOIN classes c ON s.class_id = c.id AND c.school_id = ?
                      WHERE s.id = ? AND s.school_id = ?");
$stmt->execute([$current_school_id, $studentId, $current_school_id]);
$student = $stmt->fetch();

// Get current term/year
$currentTerm = '1st Term';
$currentYear = date('Y') . '/' . (date('Y') + 1);

$terms = ['1st Term', '2nd Term', '3rd Term'];

// Load available academic years from fee structure for this class
$yearsStmt = $pdo->prepare("SELECT DISTINCT academic_year FROM fee_structure WHERE class_id = ? AND school_id = ? ORDER BY academic_year DESC");
$yearsStmt->execute([$student['class_id'], $current_school_id]);
$academicYears = array_map('trim', $yearsStmt->fetchAll(PDO::FETCH_COLUMN));
if (empty($academicYears)) {
    $academicYears = [$currentYear];
}

$selectedYear = $_POST['academic_year'] ?? $_GET['academic_year'] ?? $academicYears[0] ?? $currentYear;
if (!in_array($selectedYear, $academicYears, true)) {
    $selectedYear = $academicYears[0] ?? $currentYear;
}

// Fee data for all terms and years
$feeDataByYearTerm = [];
foreach ($academicYears as $year) {
    foreach ($terms as $term) {
        $feeDataByYearTerm[$year][$term] = $paymentHelper->getFeeBreakdown($student['class_id'], $term, $year, null, $current_school_id);
    }
}

// Find first available year per term (fallback)
$firstAvailableYearByTerm = [];
foreach ($terms as $term) {
    foreach ($academicYears as $year) {
        if (!empty($feeDataByYearTerm[$year][$term]['total'])) {
            $firstAvailableYearByTerm[$term] = $year;
            break;
        }
    }
}

$paymentHistory = $paymentHelper->getStudentPaymentHistory($student['id'], $current_school_id);

// Aggregate payments by year, term and fee type
$paymentTotalsByYearTerm = [];
foreach ($academicYears as $year) {
    foreach ($terms as $term) {
        $paymentTotalsByYearTerm[$year][$term] = ['all' => 0.0];
    }
}
foreach ($paymentHistory as $payment) {
    $yearKey = $payment['academic_year'] ?? $currentYear;
    $termKey = $payment['term'] ?? $currentTerm;
    if (!isset($paymentTotalsByYearTerm[$yearKey][$termKey])) {
        $paymentTotalsByYearTerm[$yearKey][$termKey] = ['all' => 0.0];
    }
    $feeTypeKey = $payment['fee_type'] ?: 'all';
    $amountPaid = (float) $payment['amount_paid'];
    $paymentTotalsByYearTerm[$yearKey][$termKey]['all'] += $amountPaid;
    if (!isset($paymentTotalsByYearTerm[$yearKey][$termKey][$feeTypeKey])) {
        $paymentTotalsByYearTerm[$yearKey][$termKey][$feeTypeKey] = 0.0;
    }
    $paymentTotalsByYearTerm[$yearKey][$termKey][$feeTypeKey] += $amountPaid;
}

$selectedTerm = $_POST['term'] ?? $currentTerm;
if (!in_array($selectedTerm, $terms, true)) {
    $selectedTerm = $currentTerm;
}
$selectedFeeId = $_POST['fee_id'] ?? 'all';

// If selected year/term has no fees, fallback to first available year for the term
if (empty($feeDataByYearTerm[$selectedYear][$selectedTerm]['total']) && !empty($firstAvailableYearByTerm[$selectedTerm])) {
    $selectedYear = $firstAvailableYearByTerm[$selectedTerm];
}

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentMethod = $_POST['payment_method'] ?? '';
    $paymentType = $_POST['payment_type'] ?? 'full';
    $term = $_POST['term'] ?? $currentTerm;
    $academicYear = $_POST['academic_year'] ?? $selectedYear;
    if (!in_array($term, $terms, true)) {
        $term = $currentTerm;
    }
    if (!in_array($academicYear, $academicYears, true)) {
        $academicYear = $selectedYear;
    }
    $feeId = $_POST['fee_id'] ?? 'all';
    $notes = trim($_POST['notes'] ?? '');

    $termFeeData = $feeDataByYearTerm[$academicYear][$term] ?? ['breakdown' => [], 'total' => 0];
    $selectedFeeRecord = null;
    if ($feeId !== 'all') {
        foreach ($termFeeData['breakdown'] as $fee) {
            if ((string) $fee['id'] === (string) $feeId) {
                $selectedFeeRecord = $fee;
                break;
            }
        }
        if (!$selectedFeeRecord) {
            $errorMessage = 'Selected fee item is invalid for this term.';
        }
    }

    $totalFee = ($feeId === 'all') ? (float) $termFeeData['total'] : (float) ($selectedFeeRecord['amount'] ?? 0);
    $feeType = ($feeId === 'all') ? 'all' : ($selectedFeeRecord['fee_type'] ?? 'all');
    $allowInstallments = ($feeId !== 'all') && !empty($selectedFeeRecord['allow_installments']);
    $maxInstallments = ($feeId !== 'all') ? (int) ($selectedFeeRecord['max_installments'] ?? 1) : 1;
    $paidAmountForSelection = $paymentTotalsByYearTerm[$academicYear][$term][$feeType] ?? 0;
    $balance = max(0, $totalFee - $paidAmountForSelection);

    $amount = $balance;
    if ($paymentType === 'installment') {
        if (!$allowInstallments || $maxInstallments < 2) {
            $errorMessage = 'Installment payments are not allowed for the selected fee.';
        } else {
            $installmentAmount = $totalFee / max(1, $maxInstallments);
            $amount = min($balance, round($installmentAmount, 2));
        }
    }

    if ($errorMessage) {
        // keep existing error
    } elseif ($totalFee <= 0) {
        $errorMessage = 'No fee structure is set for your class/term. Please contact the clerk.';
    } elseif ($balance <= 0) {
        $errorMessage = 'No outstanding balance for this term.';
    } elseif (!in_array($paymentMethod, ['bank_transfer', 'cash'], true)) {
        $errorMessage = 'Only manual payments (bank transfer or cash) are allowed.';
    } elseif ($amount <= 0 || $amount > $balance) {
        $errorMessage = 'Invalid amount for the selected fee.';
    } else {
        try {
            $pdo->beginTransaction();

            $transactionId = PaymentHelper::generateTransactionId($studentId);

            $stmt = $pdo->prepare("INSERT INTO student_payments
                                  (student_id, school_id, class_id, amount_paid, total_amount, payment_date,
                                   academic_year, payment_method, payment_type, fee_type, status, term, transaction_id, notes)
                                  VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, 'pending', ?, ?, ?)");
            $stmt->execute([
                $studentId,
                $current_school_id,
                $student['class_id'],
                $amount,
                $totalFee,
                $academicYear,
                $paymentMethod,
                $paymentType,
                $feeType,
                $term,
                $transactionId,
                $notes
            ]);

            $paymentId = (int)$pdo->lastInsertId();

            if (!empty($_FILES['payment_proof']['name'])) {
                $allowed = ['image/jpeg', 'image/png', 'application/pdf'];
                $fileType = $_FILES['payment_proof']['type'] ?? '';
                $fileSize = $_FILES['payment_proof']['size'] ?? 0;

                if (!in_array($fileType, $allowed, true)) {
                    throw new Exception('Invalid file type. Only JPG, PNG, or PDF allowed.');
                }
                if ($fileSize > 5 * 1024 * 1024) {
                    throw new Exception('File size exceeds 5MB.');
                }

                $uploadDir = '../uploads/payment_proofs/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['payment_proof']['name']));
                $fileName = time() . '_student_' . $safeName;
                $filePath = $uploadDir . $fileName;

                if (!move_uploaded_file($_FILES['payment_proof']['tmp_name'], $filePath)) {
                    throw new Exception('Failed to upload proof of payment.');
                }

                $attachStmt = $pdo->prepare("INSERT INTO payment_attachments
                                            (payment_id, school_id, file_name, file_path, uploaded_by, file_type, role)
                                            VALUES (?, ?, ?, ?, ?, ?, ?)");
                $attachStmt->execute([$paymentId, $current_school_id, $fileName, $filePath, $studentId, $fileType, 'student']);
            }

            $pdo->commit();
            $successMessage = 'Payment submitted successfully. Awaiting verification.';

            $paymentHistory = $paymentHelper->getStudentPaymentHistory($student['id'], $current_school_id);
        } catch (Exception $e) {
            $pdo->rollBack();
            $errorMessage = $e->getMessage();
        }
    }
}

// Rebuild payment totals after any updates
$paymentTotalsByYearTerm = [];
foreach ($academicYears as $year) {
    foreach ($terms as $term) {
        $paymentTotalsByYearTerm[$year][$term] = ['all' => 0.0];
    }
}
foreach ($paymentHistory as $payment) {
    $yearKey = $payment['academic_year'] ?? $currentYear;
    $termKey = $payment['term'] ?? $currentTerm;
    if (!isset($paymentTotalsByYearTerm[$yearKey][$termKey])) {
        $paymentTotalsByYearTerm[$yearKey][$termKey] = ['all' => 0.0];
    }
    $feeTypeKey = $payment['fee_type'] ?: 'all';
    $amountPaid = (float) $payment['amount_paid'];
    $paymentTotalsByYearTerm[$yearKey][$termKey]['all'] += $amountPaid;
    if (!isset($paymentTotalsByYearTerm[$yearKey][$termKey][$feeTypeKey])) {
        $paymentTotalsByYearTerm[$yearKey][$termKey][$feeTypeKey] = 0.0;
    }
    $paymentTotalsByYearTerm[$yearKey][$termKey][$feeTypeKey] += $amountPaid;
}

// Resolve selected fee for display
$selectedFeeRecord = null;
$termFeeData = $feeDataByYearTerm[$selectedYear][$selectedTerm] ?? ['breakdown' => [], 'total' => 0];
if ($selectedFeeId !== 'all') {
    foreach ($termFeeData['breakdown'] as $fee) {
        if ((string) $fee['id'] === (string) $selectedFeeId) {
            $selectedFeeRecord = $fee;
            break;
        }
    }
    if (!$selectedFeeRecord) {
        $selectedFeeId = 'all';
    }
}
$selectedFeeType = ($selectedFeeId === 'all') ? 'all' : ($selectedFeeRecord['fee_type'] ?? 'all');
$displayTotalFee = ($selectedFeeId === 'all') ? (float) $termFeeData['total'] : (float) ($selectedFeeRecord['amount'] ?? 0);
$displayPaid = $paymentTotalsByYearTerm[$selectedYear][$selectedTerm][$selectedFeeType] ?? 0;
$displayBalance = max(0, $displayTotalFee - $displayPaid);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <meta name="pwa-sw" content="../sw.js">
    <title>Student Payment Portal - <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="stylesheet" href="../assets/css/offline-status.css">
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
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
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

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success" style="margin-bottom: 1.5rem;">
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
                    <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($bankAccounts)): ?>
                <div class="row" style="margin-bottom: 2rem;">
                    <?php foreach (array_slice($bankAccounts, 0, 2) as $account): ?>
                        <div class="col-md-6">
                            <div class="card" style="border-left: 4px solid #0ea5e9;">
                                <div class="card-body">
                                    <div style="display:flex; align-items:center; gap:12px; margin-bottom: 0.75rem;">
                                        <div style="font-size: 1.5rem; color: #0ea5e9;"><i class="fas fa-university"></i></div>
                                        <div>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($account['bank_name']); ?></div>
                                            <div style="color: #64748b; font-size: 0.9rem;">Manual Transfer Account</div>
                                        </div>
                                    </div>
                                    <div style="font-size: 1.1rem; font-weight: 600; color: #0f172a;">
                                        <?php echo htmlspecialchars($account['account_number']); ?>
                                    </div>
                                    <div style="color: #475569; font-size: 0.95rem;">
                                        <?php echo htmlspecialchars($account['account_name']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Fee Summary -->
            <div class="row" style="margin-bottom: 2rem;">
                <div class="col-md-4">
                    <div class="card text-center" style="border-left: 4px solid #007bff;">
                        <div class="card-body">
                            <div style="font-size: 2rem; color: #007bff; margin-bottom: 0.5rem;"><i class="fas fa-money-bill-wave"></i></div>
                            <h3 style="color: #004085;" id="totalFeeValue"><?php echo $paymentHelper->formatCurrency($displayTotalFee); ?></h3>
                            <p style="margin: 0; color: #004085;">Total Fee</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center" style="border-left: 4px solid #28a745;">
                        <div class="card-body">
                            <div style="font-size: 2rem; color: #28a745; margin-bottom: 0.5rem;"><i class="fas fa-check-circle"></i></div>
                            <h3 style="color: #155724;" id="paidAmountValue"><?php echo $paymentHelper->formatCurrency($displayPaid); ?></h3>
                            <p style="margin: 0; color: #155724;">Amount Paid</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center" style="border-left: 4px solid #ffc107;">
                        <div class="card-body">
                            <div style="font-size: 2rem; color: #ffc107; margin-bottom: 0.5rem;"><i class="fas fa-balance-scale"></i></div>
                            <h3 style="color: #856404;" id="balanceValue"><?php echo $paymentHelper->formatCurrency($displayBalance); ?></h3>
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
                    <form method="POST" enctype="multipart/form-data" data-offline-sync="1">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group" style="margin-bottom: 1.5rem;">
                                    <label>Academic Year</label>
                                    <select class="form-control" name="academic_year" id="academicYearSelect">
                                        <?php foreach ($academicYears as $year): ?>
                                            <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $selectedYear === $year ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($year); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group" style="margin-bottom: 1.5rem;">
                                    <label>Term</label>
                                    <select class="form-control" name="term" id="termSelect">
                                        <?php foreach ($terms as $term): ?>
                                            <option value="<?php echo $term; ?>" <?php echo $selectedTerm === $term ? 'selected' : ''; ?>>
                                                <?php echo $term; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group" style="margin-bottom: 1.5rem;">
                                    <label>Fee Type</label>
                                    <select class="form-control" name="fee_id" id="feeSelect">
                                        <option value="all">All Fees (Total: <?php echo $paymentHelper->formatCurrency($displayTotalFee); ?>)</option>
                                        <?php foreach (($feeDataByYearTerm[$selectedYear][$selectedTerm]['breakdown'] ?? []) as $fee): ?>
                                            <option value="<?php echo htmlspecialchars($fee['id']); ?>" <?php echo (string)$selectedFeeId === (string)$fee['id'] ? 'selected' : ''; ?>>
                                                <?php
                                                    $labelParts = [$fee['type_label']];
                                                    if (!empty($fee['description'])) {
                                                        $labelParts[] = $fee['description'];
                                                    }
                                                    echo htmlspecialchars(implode(' - ', $labelParts));
                                                ?>
                                                (<?php echo $paymentHelper->formatCurrency($fee['amount']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted" id="feeMetaText">Select a fee item to load the amount.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group" style="margin-bottom: 1.5rem;">
                                    <label>Payment Method</label>
                                    <select class="form-control" name="payment_method" required>
                                        <option value="bank_transfer" selected>Bank Transfer</option>
                                        <option value="cash">Cash</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group" style="margin-bottom: 1.5rem;">
                                    <label>Payment Type</label>
                                    <select class="form-control" name="payment_type" id="paymentTypeSelect" required>
                                        <option value="full" selected>Full Payment</option>
                                        <option value="installment">Installment Payment</option>
                                    </select>
                                    <small class="text-muted" id="installmentHint" style="display:none;">Installment amount is auto-calculated for this fee.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group" style="margin-bottom: 1.5rem;">
                                    <label>Amount (NGN)</label>
                                    <input type="number" class="form-control" name="amount" id="amountInput" min="0" step="0.01"
                                           value="<?php echo $displayBalance; ?>" readonly>
                                    <small class="text-muted" id="balanceHelp">Available balance: <?php echo $paymentHelper->formatCurrency($displayBalance); ?></small>
                                </div>
                            </div>
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

                        <button type="submit" class="btn btn-primary" style="width: 100%;" <?php echo ($displayTotalFee <= 0 || $displayBalance <= 0) ? 'disabled' : ''; ?> >
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
                                        <th>Reference</th>
                                        <th>Fee Type</th>
                                        <th>Term</th>
                                        <th>Year</th>
                                        <th>Amount Paid</th>
                                        <th>Total Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paymentHistory as $payment): ?>
                                        <tr>
                                            <?php
                                                $reference = $payment['receipt_number'] ?: ($payment['transaction_id'] ?: '—');
                                                $feeTypeKey = $payment['fee_type'] ?: 'all';
                                                $feeTypeLabel = $feeTypeLabels[$feeTypeKey] ?? ucwords(str_replace('_', ' ', $feeTypeKey));
                                                $paymentDate = $payment['payment_date'] ? date('d/m/Y', strtotime($payment['payment_date'])) : '—';
                                            ?>
                                            <td><?php echo htmlspecialchars($reference); ?></td>
                                            <td><?php echo htmlspecialchars($feeTypeLabel); ?></td>
                                            <td><?php echo htmlspecialchars($payment['term'] ?? '—'); ?></td>
                                            <td><?php echo htmlspecialchars($payment['academic_year'] ?? '—'); ?></td>
                                            <td><?php echo $paymentHelper->formatCurrency($payment['amount_paid']); ?></td>
                                            <td><?php echo $paymentHelper->formatCurrency($payment['total_amount'] ?? 0); ?></td>
                                            <td><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                            <td>
                                                <?php
                                                    $statusClassMap = [
                                                        'pending' => 'warning',
                                                        'verified' => 'info',
                                                        'partial' => 'primary',
                                                        'completed' => 'success',
                                                        'rejected' => 'danger'
                                                    ];
                                                    $statusClass = $statusClassMap[$payment['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge badge-<?php echo $statusClass; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $payment['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $paymentDate; ?></td>
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

        const feeDataByYearTerm = <?php echo json_encode($feeDataByYearTerm); ?>;
        const paymentTotalsByYearTerm = <?php echo json_encode($paymentTotalsByYearTerm); ?>;
        const currencySymbol = <?php echo json_encode($paymentConfig['currency'] ?? 'N'); ?>;
        const initialTerm = <?php echo json_encode($selectedTerm); ?>;
        const initialFeeId = <?php echo json_encode($selectedFeeId); ?>;
        const initialYear = <?php echo json_encode($selectedYear); ?>;
        const firstAvailableYearByTerm = <?php echo json_encode($firstAvailableYearByTerm); ?>;

        const termSelect = document.getElementById('termSelect');
        const academicYearSelect = document.getElementById('academicYearSelect');
        const feeSelect = document.getElementById('feeSelect');
        const paymentTypeSelect = document.getElementById('paymentTypeSelect');
        const amountInput = document.getElementById('amountInput');
        const balanceHelp = document.getElementById('balanceHelp');
        const feeMetaText = document.getElementById('feeMetaText');
        const installmentHint = document.getElementById('installmentHint');
        const totalFeeValue = document.getElementById('totalFeeValue');
        const paidAmountValue = document.getElementById('paidAmountValue');
        const balanceValue = document.getElementById('balanceValue');
        const submitButton = document.querySelector('button[type=\"submit\"]');

        function formatCurrency(amount) {
            const num = Number(amount || 0);
            return `${currencySymbol}${num.toFixed(2).replace(/\\B(?=(\\d{3})+(?!\\d))/g, ',')}`;
        }

        function renderFeeOptions(year, term, selectedFeeId) {
            if (!feeSelect) return;
            const termData = (feeDataByYearTerm[year] && feeDataByYearTerm[year][term]) ? feeDataByYearTerm[year][term] : { breakdown: [], total: 0 };
            feeSelect.innerHTML = '';

            const allOption = new Option(`All Fees (Total: ${formatCurrency(termData.total)})`, 'all');
            feeSelect.add(allOption);

            termData.breakdown.forEach((fee) => {
                const labelParts = [fee.type_label];
                if (fee.description) {
                    labelParts.push(fee.description);
                }
                const label = `${labelParts.join(' - ')} (${formatCurrency(fee.amount)})`;
                const opt = new Option(label, String(fee.id));
                opt.dataset.amount = fee.amount;
                opt.dataset.feeType = fee.fee_type;
                opt.dataset.allowInstallments = fee.allow_installments ? '1' : '0';
                opt.dataset.maxInstallments = fee.max_installments;
                feeSelect.add(opt);
            });

            const targetValue = selectedFeeId && Array.from(feeSelect.options).some(o => o.value === String(selectedFeeId))
                ? String(selectedFeeId)
                : 'all';
            feeSelect.value = targetValue;
        }

        function getSelectedFeeMeta() {
            const term = termSelect ? termSelect.value : initialTerm;
            const year = academicYearSelect ? academicYearSelect.value : initialYear;
            const termData = (feeDataByYearTerm[year] && feeDataByYearTerm[year][term]) ? feeDataByYearTerm[year][term] : { breakdown: [], total: 0 };
            const selectedId = feeSelect ? feeSelect.value : 'all';

            if (selectedId === 'all') {
                return {
                term,
                year,
                feeType: 'all',
                total: Number(termData.total || 0),
                allowInstallments: false,
                maxInstallments: 1,
                description: 'All fees for selected term'
            };
            }

            const selectedOption = feeSelect ? feeSelect.options[feeSelect.selectedIndex] : null;
            const amount = selectedOption ? Number(selectedOption.dataset.amount || 0) : 0;
            const feeType = selectedOption ? (selectedOption.dataset.feeType || 'all') : 'all';
            const allowInstallments = selectedOption ? selectedOption.dataset.allowInstallments === '1' : false;
            const maxInstallments = selectedOption ? Number(selectedOption.dataset.maxInstallments || 1) : 1;

            return {
                term,
                year,
                feeType,
                total: amount,
                allowInstallments,
                maxInstallments,
                description: selectedOption ? selectedOption.text : ''
            };
        }

        function updateSummaryCards() {
            const meta = getSelectedFeeMeta();
            const paid = (paymentTotalsByYearTerm[meta.year] && paymentTotalsByYearTerm[meta.year][meta.term] && paymentTotalsByYearTerm[meta.year][meta.term][meta.feeType])
                ? Number(paymentTotalsByYearTerm[meta.year][meta.term][meta.feeType])
                : 0;
            const balance = Math.max(0, meta.total - paid);

            if (totalFeeValue) totalFeeValue.textContent = formatCurrency(meta.total);
            if (paidAmountValue) paidAmountValue.textContent = formatCurrency(paid);
            if (balanceValue) balanceValue.textContent = formatCurrency(balance);

            if (paymentTypeSelect) {
                const installmentOption = paymentTypeSelect.querySelector('option[value=\"installment\"]');
                if (installmentOption) {
                    installmentOption.disabled = !meta.allowInstallments || meta.maxInstallments < 2;
                }
                if (( !meta.allowInstallments || meta.maxInstallments < 2) && paymentTypeSelect.value === 'installment') {
                    paymentTypeSelect.value = 'full';
                }
            }

            const paymentType = paymentTypeSelect ? paymentTypeSelect.value : 'full';
            let payableAmount = balance;
            if (paymentType === 'installment' && meta.allowInstallments && meta.maxInstallments > 1) {
                payableAmount = Math.min(balance, Number((meta.total / meta.maxInstallments).toFixed(2)));
                if (installmentHint) installmentHint.style.display = 'block';
            } else if (installmentHint) {
                installmentHint.style.display = 'none';
            }

            if (amountInput) amountInput.value = payableAmount.toFixed(2);
            if (balanceHelp) balanceHelp.textContent = `Available balance: ${formatCurrency(balance)}`;
            if (feeMetaText) {
                feeMetaText.textContent = meta.total > 0
                    ? (meta.description || 'Select a fee item to load the amount.')
                    : 'No fee structure found for the selected year and term.';
            }
            if (submitButton) {
                submitButton.disabled = meta.total <= 0 || balance <= 0;
            }
        }

        if (termSelect && feeSelect) {
            renderFeeOptions(initialYear, initialTerm, initialFeeId);
            updateSummaryCards();

            termSelect.addEventListener('change', () => {
                let year = academicYearSelect ? academicYearSelect.value : initialYear;
                const term = termSelect.value;
                if ((!feeDataByYearTerm[year] || !feeDataByYearTerm[year][term] || !feeDataByYearTerm[year][term].total) && firstAvailableYearByTerm[term]) {
                    year = firstAvailableYearByTerm[term];
                    if (academicYearSelect) academicYearSelect.value = year;
                }
                renderFeeOptions(year, term, 'all');
                updateSummaryCards();
            });

            feeSelect.addEventListener('change', updateSummaryCards);
        }

        if (academicYearSelect) {
            academicYearSelect.addEventListener('change', () => {
                const year = academicYearSelect.value;
                const term = termSelect ? termSelect.value : initialTerm;
                renderFeeOptions(year, term, 'all');
                updateSummaryCards();
            });
        }

        if (paymentTypeSelect) {
            paymentTypeSelect.addEventListener('change', updateSummaryCards);
        }

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

    <script src="../assets/js/offline-core.js" defer></script>
    <?php include '../includes/floating-button.php'; ?>

</body>
</html>
