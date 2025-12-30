
<?php
// student/payment.php
session_start();
require_once '../config/db.php';
require_once '../helpers/payment_helper.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
} 

$studentId = $_SESSION['student_id'];
$paymentHelper = new PaymentHelper();

// Get student details
$stmt = $pdo->prepare("SELECT s.*, c.class_name FROM students s 
                      JOIN classes c ON s.class_id = c.id 
                      WHERE s.id = ?");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

if (!$student) {
    die("Student not found");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        $term = $_POST['term'];
        $academicYear = $_POST['academic_year'];
        $paymentMethod = $_POST['payment_method'];
        $paymentType = $_POST['payment_type'];
        $installments = $_POST['installments'] ?? 1;
        $amount = $_POST['amount'];
        $notes = $_POST['notes'] ?? '';
        
        // Get fee breakdown for current term/year
        $feeBreakdown = $paymentHelper->getFeeBreakdown($student['class_id'], $term, $academicYear);
        
        // Validate amount
        if ($amount > $feeBreakdown['total']) {
            throw new Exception("Amount cannot exceed total fee: " . $paymentHelper->formatCurrency($feeBreakdown['total']));
        }
        
        if ($paymentType === 'installment' && $installments > 1) {
            // Validate installment amount
            $installmentAmount = $feeBreakdown['total'] / $installments;
            if ($installmentAmount < 5000) {
                throw new Exception("Each installment must be at least ₦5,000");
            }
        }
        
        // Create payment record
        $receiptNumber = $paymentHelper->generateReceiptNumber();
        $transactionId = $paymentHelper->generateTransactionId($student['id']);
        
        $stmt = $pdo->prepare("INSERT INTO student_payments 
                              (student_id, class_id, academic_year, term, 
                               total_amount, amount_paid, payment_method, payment_type, 
                               installment_number, total_installments, receipt_number, 
                               transaction_id, payment_date, due_date, status, notes) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?)");
        
        $dueDate = date('Y-m-d', strtotime('+30 days'));
        $status = ($paymentType === 'full' && $amount == $feeBreakdown['total']) ? 'pending_verification' : 'partial';
        
        $stmt->execute([
            $student['id'], $student['class_id'], $academicYear, $term,
            $feeBreakdown['total'], $amount, $paymentMethod, $paymentType, 
            1, $installments, $receiptNumber, $transactionId, 
            $dueDate, $status, $notes
        ]);
        
        $paymentId = $pdo->lastInsertId();
        
        // Create installments if needed
        if ($paymentType === 'installment' && $installments > 1) {
            $paymentHelper->createInstallments($paymentId, $feeBreakdown['total'], $installments, $dueDate);
        }
        
        // Handle file upload - FIXED FOREIGN KEY ISSUE
        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === 0) {
            $uploadDir = '../uploads/payments/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileName = time() . '_' . basename($_FILES['payment_proof']['name']);
            $filePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $filePath)) {
                // Get a system admin user ID or set to NULL
                $adminStmt = $pdo->query("SELECT id FROM users WHERE role = 'principal' OR role = 'admin' LIMIT 1");
                $admin = $adminStmt->fetch();
                $uploadedBy = $admin ? $admin['id'] : NULL;
                
                $stmt = $pdo->prepare("INSERT INTO payment_attachments 
                                      (payment_id, file_name, file_path, uploaded_by) 
                                      VALUES (?, ?, ?, ?)");
                $stmt->execute([$paymentId, $fileName, $filePath, $uploadedBy]);
            }
        }
        
        $pdo->commit();
        
        // Redirect to same page with print parameter
        header("Location: payment.php?success=1&print_id=" . $paymentId);
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Get school information
$schoolInfoStmt = $pdo->query("SELECT * FROM school_profile LIMIT 1");
$schoolInfo = $schoolInfoStmt->fetch();

// Get available fee types for current term/year
$currentTerm = '1st Term';
$currentYear = date('Y') . '/' . (date('Y') + 1);

$feeTypesStmt = $pdo->prepare("SELECT DISTINCT fee_type FROM fee_structure 
                              WHERE class_id = ? AND term = ? AND academic_year = ? 
                              AND is_active = 1");
$feeTypesStmt->execute([$student['class_id'], $currentTerm, $currentYear]);
$availableFeeTypes = $feeTypesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get fee type labels
$feeTypeLabels = [
    'tuition' => 'Tuition Fee',
    'exam' => 'Examination Fee',
    'sports' => 'Sports Fee',
    'library' => 'Library Fee',
    'development' => 'Development Levy',
    'other' => 'Other Charges'
];

// Get payment history
$paymentHistory = $paymentHelper->getStudentPaymentHistory($student['id']);

// Get school bank accounts
$bankAccounts = $paymentHelper->getSchoolBankAccounts();

// Calculate total fee
$totalFee = $paymentHelper->calculateStudentFee($student['id'], $student['class_id'], $currentTerm, $currentYear);

// Get fee breakdown
$fullBreakdown = $paymentHelper->getFullFeeBreakdown($student['id'], $student['class_id'], $currentTerm, $currentYear);

// Calculate paid amount and balance
$paidAmount = 0;
foreach ($paymentHistory as $payment) {
    if ($payment['term'] == $currentTerm && $payment['academic_year'] == $currentYear) {
        $paidAmount += $payment['amount_paid'];
    }
}
$balance = max(0, $totalFee - $paidAmount);

// Get specific payment details for receipt printing
$paymentDetails = null;
if (isset($_GET['print_id'])) {
    $printId = intval($_GET['print_id']);
    $stmt = $pdo->prepare("SELECT sp.*, s.full_name, s.admission_no, s.guardian_name, s.guardian_phone,
                          c.class_name, spb.bank_name, spb.account_name, spb.account_number
                          FROM student_payments sp
                          JOIN students s ON sp.student_id = s.id
                          JOIN classes c ON sp.class_id = c.id
                          LEFT JOIN school_bank_accounts spb ON sp.bank_account_id = spb.id
                          WHERE sp.id = ? AND sp.student_id = ?");
    $stmt->execute([$printId, $studentId]);
    $paymentDetails = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Payment Portal - <?php echo htmlspecialchars($student['full_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #6366f1;
            --primary-dark: #4f46e5;
            --secondary-color: #06b6d4;
            --accent-color: #f59e0b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --light-bg: #f8fafc;
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
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --border-radius-xl: 16px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: var(--white);
            color: var(--gray-800);
            line-height: 1.6;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Modern Header */
        .header {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius-xl);
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(255, 255, 255, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-content {
            flex: 1;
        }

        .header-title {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header-title i {
            color: var(--primary-color);
            font-size: 1.5rem;
        }

        .header-subtitle {
            color: var(--gray-600);
            font-size: 1rem;
            font-weight: 500;
        }

        .nav-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .nav-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            text-decoration: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow);
        }

        .nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
        }

        .nav-btn-secondary {
            background: linear-gradient(135deg, var(--gray-500), var(--gray-600));
        }

        .nav-btn-secondary:hover {
            background: linear-gradient(135deg, var(--gray-600), var(--gray-700));
        }

        /* Modern Cards */
        .card {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            padding: 2.5rem;
            margin-bottom: 2.5rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08), 0 4px 20px rgba(0, 0, 0, 0.04);
            border: 2px solid transparent;
            background: linear-gradient(145deg, var(--white), #fafbfc);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color), var(--accent-color));
            border-radius: var(--border-radius-xl) var(--border-radius-xl) 0 0;
        }

        .card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(99, 102, 241, 0.1), transparent);
        }

        .card:hover {
            transform: translateY(-6px) scale(1.01);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.12), 0 8px 30px rgba(0, 0, 0, 0.06);
            border-color: rgba(99, 102, 241, 0.15);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-100);
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-title i {
            color: var(--primary-color);
            font-size: 1.25rem;
        }

        .card-subtitle {
            color: var(--gray-600);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        /* Fee Summary Card */
        .fee-summary-card {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border: none;
        }

        .fee-summary-card .card-title,
        .fee-summary-card .card-subtitle {
            color: white;
        }

        .fee-summary-card .card-title i {
            color: rgba(255, 255, 255, 0.9);
        }

        /* Bank Details Card */
        .bank-card {
            background: linear-gradient(135deg, var(--secondary-color), #0891b2);
            color: white;
            border: none;
        }

        .bank-card .card-title {
            color: white;
        }

        .bank-card .card-title i {
            color: rgba(255, 255, 255, 0.9);
        }

        /* Grid Layout */
        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (min-width: 768px) {
            .grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1200px) {
            .grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        /* Fee Type Grid */
        .fee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }

        @media (max-width: 480px) {
            .fee-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .fee-item {
            background: var(--gray-50);
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius-lg);
            padding: 1.25rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-align: center;
            position: relative;
        }

        .fee-item:hover {
            border-color: var(--primary-color);
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            background: rgba(99, 102, 241, 0.05);
        }

        .fee-item.selected {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(79, 70, 229, 0.05));
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .fee-item.selected::after {
            content: '✓';
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: var(--success-color);
            color: white;
            border-radius: 50%;
            width: 1.5rem;
            height: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
        }

        .fee-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--gray-900);
            font-size: 0.875rem;
            text-transform: capitalize;
        }

        .fee-amount {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        /* Modern Form Elements */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--gray-900);
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-label i {
            color: var(--primary-color);
        }

        .form-control {
            width: 100%;
            padding: 1.25rem 1.5rem;
            border: 3px solid var(--gray-300);
            border-radius: var(--border-radius-xl);
            font-size: 1.1rem;
            font-weight: 600;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            background: linear-gradient(145deg, var(--white), var(--gray-50));
            color: var(--gray-900);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            backdrop-filter: blur(10px);
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 5px rgba(99, 102, 241, 0.2), 0 8px 24px rgba(99, 102, 241, 0.15);
            background: var(--white);
            transform: translateY(-2px) scale(1.02);
            font-weight: 700;
        }

        .form-control::placeholder {
            color: var(--gray-500);
            font-weight: 500;
            font-style: italic;
        }

        .form-control:hover {
            border-color: var(--primary-color);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
            transform: translateY(-1px);
        }

        .form-control:active,
        .form-control:focus {
            transform: translateY(-2px) scale(1.02);
        }

        .form-select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 1rem center;
            background-repeat: no-repeat;
            background-size: 1.25rem;
            padding-right: 3rem;
            cursor: pointer;
        }

        .form-select:focus {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236366f1' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
            line-height: 1.5;
        }

        input[type="file"].form-control {
            padding: 0.75rem 1.25rem;
            cursor: pointer;
        }

        input[type="file"].form-control::file-selector-button {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-right: 1rem;
        }

        input[type="file"].form-control::file-selector-button:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        /* Modern Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.875rem 1.75rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            width: 100%;
        }

        @media (min-width: 768px) {
            .btn {
                width: auto;
            }
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            box-shadow: var(--shadow);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--gray-500), var(--gray-600));
            color: white;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, var(--gray-600), var(--gray-700));
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #059669);
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #059669, var(--success-color));
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        /* Progress Components */
        .progress-container {
            background: rgba(255, 255, 255, 0.2);
            height: 12px;
            border-radius: 6px;
            overflow: hidden;
            margin: 1rem 0;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(135deg, #10b981, #059669);
            transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 6px;
            position: relative;
        }

        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .progress-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
            margin-top: 0.5rem;
            color: white;
            font-weight: 500;
        }

        /* Alert Components */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--border-radius-lg);
            margin-bottom: 2rem;
            border-left: 4px solid;
            animation: slideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
            border-left-color: var(--success-color);
        }

        .alert-success i {
            color: var(--success-color);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            color: #7f1d1d;
            border-left-color: var(--danger-color);
        }

        .alert-danger i {
            color: var(--danger-color);
        }

        /* Bank Account Styling */
        .bank-item {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius-lg);
            padding: 1.25rem;
            margin-bottom: 1rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .bank-item:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
        }

        .bank-item p {
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.9);
        }

        .bank-name {
            font-size: 1.125rem;
            font-weight: 700;
            color: white;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .bank-name i {
            color: rgba(255, 255, 255, 0.8);
        }

        .account-number {
            font-family: 'Courier New', monospace;
            font-size: 1.1rem;
            font-weight: 600;
            letter-spacing: 2px;
            color: white;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem;
            border-radius: var(--border-radius);
            margin-top: 0.5rem;
            display: inline-block;
        }

        .primary-indicator {
            background: rgba(16, 185, 129, 0.9);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            margin-top: 0.75rem;
        }

        .primary-indicator i {
            font-size: 0.625rem;
        }

        /* Payment Summary */
        .payment-summary {
            background: linear-gradient(135deg, var(--gray-50), var(--gray-100));
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            padding: 0.5rem 0;
        }

        .summary-row.total {
            border-top: 2px solid var(--gray-300);
            padding-top: 1rem;
            margin-top: 1rem;
            font-weight: 700;
            font-size: 1.125rem;
            color: var(--gray-900);
        }

        .summary-label {
            color: var(--gray-700);
        }

        .summary-value {
            font-weight: 600;
            color: var(--gray-900);
        }

        .summary-value.positive {
            color: var(--success-color);
        }

        .summary-value.negative {
            color: var(--danger-color);
        }

        /* Table Styling */
        .table-container {
            overflow-x: auto;
            margin-top: 2rem;
            border-radius: var(--border-radius-xl);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08), 0 8px 25px rgba(0, 0, 0, 0.04);
            background: linear-gradient(145deg, var(--white), #fafbfc);
            padding: 0.5rem;
            border: 2px solid rgba(99, 102, 241, 0.08);
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 800px;
            font-size: 0.9rem;
            border-radius: var(--border-radius-lg);
            overflow: hidden;
        }

        .table thead th {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark), var(--secondary-color));
            color: white;
            padding: 1.25rem 1.5rem;
            font-weight: 700;
            text-align: left;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            border: none;
            position: relative;
        }

        .table thead th::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, rgba(255, 255, 255, 0.3), transparent, rgba(255, 255, 255, 0.3));
        }

        .table tbody td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            color: var(--gray-800);
            font-weight: 500;
            position: relative;
        }

        .table tbody tr {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-left: 4px solid transparent;
        }

        .table tbody tr:hover {
            background: linear-gradient(90deg, rgba(99, 102, 241, 0.02), rgba(6, 182, 212, 0.02));
            transform: translateX(4px);
            border-left-color: var(--primary-color);
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.1);
        }

        .table tbody tr:nth-child(even) {
            background: rgba(248, 250, 252, 0.3);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .table tbody tr:last-child {
            border-bottom-left-radius: var(--border-radius-lg);
            border-bottom-right-radius: var(--border-radius-lg);
        }

        .table tbody td:first-child {
            font-weight: 700;
            color: var(--primary-color);
        }

        .table tbody td strong {
            color: var(--gray-900);
            font-weight: 700;
        }

        /* Action buttons in table */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-buttons .btn {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
            border-radius: var(--border-radius);
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .action-buttons .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.875rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-pending_verification {
            background: linear-gradient(135deg, var(--warning-color), #d97706);
            color: white;
        }

        .status-verified, .status-completed {
            background: linear-gradient(135deg, var(--success-color), #059669);
            color: white;
        }

        .status-partial {
            background: linear-gradient(135deg, var(--secondary-color), #0891b2);
            color: white;
        }

        .status-overdue {
            background: linear-gradient(135deg, var(--danger-color), #dc2626);
            color: white;
        }

        .status-cancelled {
            background: linear-gradient(135deg, var(--gray-500), var(--gray-600));
            color: white;
        }

        /* Installment Options */
        .installment-options {
            display: none;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-top: 1rem;
        }

        .installment-calc {
            background: var(--primary-color);
            color: white;
            padding: 0.75rem;
            border-radius: var(--border-radius);
            font-weight: 600;
            text-align: center;
            margin-top: 0.5rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-500);
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: var(--gray-300);
        }

        .empty-state h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        /* Modal Styling */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1050;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius-xl);
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: modalFadeIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray-400);
            width: 2rem;
            height: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
        }

        .modal-close:hover {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }

        /* Print Styles */
        #receiptContent {
            display: none;
        }

        @media print {
            body * {
                visibility: hidden;
            }

            #receiptContent, #receiptContent * {
                visibility: visible;
            }

            #receiptContent {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                padding: 20px;
                background: white;
            }

            .no-print {
                display: none !important;
            }

            .receipt-container {
                max-width: 800px;
                margin: 0 auto;
                border: 2px solid #000;
                padding: 30px;
                font-family: 'Times New Roman', Times, serif;
            }

            .receipt-header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 3px double #000;
                padding-bottom: 20px;
            }

            .receipt-header h2 {
                font-size: 28px;
                margin-bottom: 10px;
                color: #000;
            }

            .receipt-header h3 {
                font-size: 20px;
                margin-bottom: 10px;
                color: #333;
            }

            .receipt-details {
                margin: 25px 0;
            }

            .receipt-details table {
                width: 100%;
                border-collapse: collapse;
                margin: 15px 0;
            }

            .receipt-details th,
            .receipt-details td {
                border: 1px solid #000;
                padding: 12px;
                text-align: left;
                font-size: 14px;
            }

            .receipt-details th {
                background-color: #f2f2f2;
                font-weight: bold;
            }

            .receipt-total {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
                border: 1px solid #ddd;
            }

            .receipt-total .total-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
                font-size: 16px;
            }

            .receipt-total .final-total {
                font-size: 20px;
                font-weight: bold;
                border-top: 2px solid #000;
                padding-top: 10px;
                margin-top: 10px;
            }

            .receipt-footer {
                margin-top: 50px;
                text-align: center;
            }

            .signature-section {
                margin-top: 60px;
                display: flex;
                justify-content: space-between;
            }

            .signature-line {
                border-top: 1px solid #000;
                width: 200px;
                text-align: center;
                padding-top: 10px;
            }

            .watermark {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) rotate(-45deg);
                font-size: 80px;
                color: rgba(0,0,0,0.1);
                z-index: 1000;
                pointer-events: none;
            }

            .print-navigation {
                display: none !important;
            }
        }

        .receipt-navigation {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray-200);
        }

        .receipt-navigation a {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            text-decoration: none;
            padding: 0.875rem 1.75rem;
            border-radius: var(--border-radius);
            font-weight: 600;
            margin: 0 0.5rem;
            transition: all 0.3s ease;
        }

        .receipt-navigation a:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 0.5rem;
            }

            .header {
                padding: 1rem;
                margin-bottom: 1rem;
            }

            .header-title {
                font-size: 1.5rem;
            }

            .card {
                padding: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .grid {
                gap: 1.5rem;
            }

            .fee-grid {
                grid-template-columns: 1fr;
            }

            .nav-buttons {
                flex-direction: column;
                width: 100%;
            }

            .nav-btn {
                width: 100%;
                justify-content: center;
            }

            .modal-content {
                margin: 0.5rem;
                max-height: 95vh;
            }

            .modal-body {
                padding: 1.5rem;
            }

            .table {
                font-size: 0.75rem;
            }

            .table thead th,
            .table tbody td {
                padding: 0.75rem;
            }
        }

        @media (max-width: 480px) {
            .header-title {
                font-size: 1.25rem;
            }

            .card-title {
                font-size: 1.25rem;
            }

            .fee-item {
                padding: 1rem;
            }

            .fee-label {
                font-size: 0.75rem;
            }

            .fee-amount {
                font-size: 1rem;
            }

            .btn {
                padding: 0.75rem 1.25rem;
                font-size: 0.875rem;
            }

            .form-control {
                padding: 0.75rem;
            }

            .status-badge {
                font-size: 0.625rem;
                padding: 0.25rem 0.625rem;
            }
        }

        /* Loading Animation */
        .loading {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .loading::after {
            content: '';
            width: 1rem;
            height: 1rem;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Success Animation */
        @keyframes successPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .success-animation {
            animation: successPulse 0.6s ease;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header with Navigation -->
        <div class="header">
            <div class="header-content">
                <h1><i class="fas fa-credit-card"></i> Student Payment Portal</h1>
                <p>Welcome, <strong><?php echo htmlspecialchars($student['full_name']); ?></strong> 
                   (<?php echo htmlspecialchars($student['class_name']); ?>)</p>
            </div>
            <div class="navigation-buttons">
                <a href="dashboard.php" class="nav-btn">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="logout.php" class="nav-btn nav-btn-secondary">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
        
        <!-- Success Message After Payment -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Payment submitted successfully! Your receipt has been generated.
                <?php if (isset($_GET['print_id'])): ?>
                    <div style="margin-top: 10px;">
                        <a href="generate_payment_pdf.php?id=<?php echo $_GET['print_id']; ?>" target="_blank" class="btn btn-sm btn-primary">
                            <i class="fas fa-download"></i> Download PDF
                        </a>
                        <a href="payment.php" class="btn btn-sm btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Payments
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Error/Success Messages -->
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <!-- Top Row - Bank Details and Fee Summary -->
        <div class="grid" style="margin-bottom: 1rem;">
            <!-- Bank Details (Smaller) -->
            <div class="card bank-card" style="padding: 1.25rem;">
                <div class="card-header" style="margin-bottom: 1rem; padding-bottom: 0.75rem;">
                    <h3 class="card-title" style="font-size: 1.25rem; margin: 0;">
                        <i class="fas fa-university"></i> Bank Accounts
                    </h3>
                </div>
                <div style="font-size: 0.875rem;">
                    <?php if (!empty($bankAccounts)): ?>
                        <?php foreach ($bankAccounts as $account): ?>
                            <div class="bank-item" style="padding: 0.75rem; margin-bottom: 0.75rem; font-size: 0.8rem;">
                                <div class="bank-name" style="font-size: 0.9rem; margin-bottom: 0.5rem;">
                                    <i class="fas fa-landmark"></i>
                                    <strong><?php echo htmlspecialchars($account['bank_name']); ?></strong>
                                </div>
                                <p style="margin-bottom: 0.25rem; font-size: 0.75rem;">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($account['account_name']); ?>
                                </p>
                                <div class="account-number" style="font-size: 0.9rem; padding: 0.375rem; margin-top: 0.25rem;">
                                    <?php echo htmlspecialchars($account['account_number']); ?>
                                </div>
                                <?php if ($account['is_primary']): ?>
                                    <div class="primary-indicator" style="font-size: 0.625rem; padding: 0.125rem 0.5rem;">
                                        <i class="fas fa-star"></i> Primary
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="font-size: 0.8rem; color: rgba(255,255,255,0.8);">No bank accounts available.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Fee Summary -->
            <div class="card fee-summary-card">
                <h3><i class="fas fa-file-invoice-dollar"></i> Fee Summary</h3>
                <p><strong><?php echo $currentTerm . ' ' . $currentYear; ?>:</strong>
                   <?php echo $paymentHelper->formatCurrency($totalFee); ?></p>

                <div class="progress-container">
                    <div class="progress-bar" style="width: <?php echo $totalFee > 0 ? min(100, ($paidAmount / $totalFee * 100)) : 0; ?>%"></div>
                </div>
                <div class="progress-info">
                    <span><?php echo $totalFee > 0 ? number_format(($paidAmount / $totalFee * 100), 1) : 0; ?>% Paid</span>
                    <span><?php echo $paymentHelper->formatCurrency($balance); ?> Balance</span>
                </div>

                <h4 style="font-size: 0.9rem;">Fee Types:</h4>
                <?php if (!empty($availableFeeTypes)): ?>
                    <div class="fee-grid">
                        <?php foreach ($availableFeeTypes as $feeType): ?>
                            <?php
                            // Get fee amount for this type
                            $feeAmount = 0;
                            if (is_array($fullBreakdown) && isset($fullBreakdown['breakdown'])) {
                                foreach ($fullBreakdown['breakdown'] as $fee) {
                                    if (isset($fee['fee_type']) && $fee['fee_type'] == $feeType) {
                                        $feeAmount = $fee['adjusted_amount'] ?? $fee['amount'] ?? 0;
                                        break;
                                    }
                                }
                            }
                            ?>
                            <?php if ($feeAmount > 0): ?>
                                <div class="fee-item" onclick="selectFeeType('<?php echo $feeType; ?>', <?php echo $feeAmount; ?>)">
                                    <div class="fee-label">
                                        <?php echo $feeTypeLabels[$feeType] ?? ucfirst($feeType); ?>
                                    </div>
                                    <div class="fee-amount">
                                        <?php echo $paymentHelper->formatCurrency($feeAmount); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="fee_type" id="fee_type" value="<?php echo !empty($availableFeeTypes) ? $availableFeeTypes[0] : ''; ?>" required>
                <?php else: ?>
                    <p style="color: rgba(255,255,255,0.8); padding: 8px; background: rgba(231, 76, 60, 0.1); border-radius: 4px; font-size: 0.8rem;">
                        <i class="fas fa-exclamation-triangle"></i> No active fee types available for this term.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Form -->
        <div class="card">
            <h3><i class="fas fa-money-check-alt"></i> Make Payment</h3>
            <form method="POST" enctype="multipart/form-data" id="paymentForm">
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Term *</label>
                    <select name="term" required>
                        <option value="1st Term" <?php echo $currentTerm == '1st Term' ? 'selected' : ''; ?>>1st Term</option>
                        <option value="2nd Term" <?php echo $currentTerm == '2nd Term' ? 'selected' : ''; ?>>2nd Term</option>
                        <option value="3rd Term" <?php echo $currentTerm == '3rd Term' ? 'selected' : ''; ?>>3rd Term</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-graduation-cap"></i> Academic Year *</label>
                    <input type="text" name="academic_year" value="<?php echo $currentYear; ?>" required readonly>
                </div>

                <div class="payment-summary">
                    <div class="payment-summary-item">
                        <span>Total Fee:</span>
                        <span><?php echo $paymentHelper->formatCurrency($totalFee); ?></span>
                    </div>
                    <div class="payment-summary-item">
                        <span>Amount Paid:</span>
                        <span style="color: #27ae60;"><?php echo $paymentHelper->formatCurrency($paidAmount); ?></span>
                    </div>
                    <div class="payment-summary-item total">
                        <span>Balance Due:</span>
                        <span style="color: #e74c3c;"><?php echo $paymentHelper->formatCurrency($balance); ?></span>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-credit-card"></i> Payment Method *</label>
                    <select name="payment_method" required>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cash">Cash</option>
                        <option value="cheque">Cheque</option>
                        <option value="online">Online Payment</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-layer-group"></i> Payment Type *</label>
                    <select name="payment_type" id="payment_type" onchange="toggleInstallments()" required>
                        <option value="full">Full Payment</option>
                        <option value="installment">Installment Payment</option>
                    </select>
                </div>

                <div id="installment_options" class="installment-option">
                    <div class="form-group">
                        <label><i class="fas fa-calendar-day"></i> Number of Installments (2-4)</label>
                        <select name="installments" id="installments" onchange="calculateInstallments()">
                            <option value="2">2 Installments</option>
                            <option value="3">3 Installments</option>
                            <option value="4">4 Installments</option>
                        </select>
                        <p id="installment_amount" style="margin-top: 10px; font-weight: 600; color: #667eea;"></p>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-money-bill-wave"></i> Amount to Pay (₦) *</label>
                    <input type="number" name="amount" id="amount" min="1000" step="0.01" required>
                    <small style="display: block; margin-top: 5px; color: #7f8c8d;">
                        Available balance: <?php echo $paymentHelper->formatCurrency($balance); ?>
                    </small>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-file-upload"></i> Payment Proof (Bank Slip/Receipt)</label>
                    <input type="file" name="payment_proof" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                    <small style="display: block; margin-top: 5px; color: #7f8c8d;">
                        Upload clear image or PDF of your payment proof (Max: 5MB)
                    </small>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-sticky-note"></i> Notes</label>
                    <textarea name="notes" rows="3" placeholder="Enter payment reference number or any additional information..."></textarea>
                </div>

                <button type="submit" class="btn">
                    <i class="fas fa-paper-plane"></i> Submit Payment
                </button>
            </form>
        </div>
        
        <!-- Payment History -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                <h3><i class="fas fa-history"></i> Payment History</h3>
                <div class="action-buttons">
                    <button class="btn btn-sm btn-secondary" onclick="refreshHistory()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
            
            <div class="table-container">
                <?php if (!empty($paymentHistory)): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Receipt No</th>
                                <th>Date</th>
                                <th>Term</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentHistory as $payment): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($payment['receipt_number']); ?></strong>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($payment['term']); ?></td>
                                    <td>
                                        <strong><?php echo $paymentHelper->formatCurrency($payment['amount_paid']); ?></strong>
                                        <?php if ($payment['total_amount'] > 0): ?>
                                            <br><small>of <?php echo $paymentHelper->formatCurrency($payment['total_amount']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="text-transform: capitalize;">
                                            <?php echo str_replace('_', ' ', $payment['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        echo ucfirst($payment['payment_type']);
                                        if ($payment['payment_type'] == 'installment') {
                                            echo ' (' . $payment['installment_number'] . '/' . $payment['total_installments'] . ')';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $payment['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $payment['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons" style="margin: 0; justify-content: flex-start;">
                                            <button class="btn btn-sm" onclick="viewPaymentDetails(<?php echo $payment['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
            <?php if ($payment['status'] == 'verified' || $payment['status'] == 'completed' || $payment['status'] == 'partial'): ?>
                                                <button class="btn btn-sm btn-primary" onclick="downloadPaymentPDF(<?php echo $payment['id']; ?>)">
                                                    <i class="fas fa-download"></i> PDF
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-receipt"></i>
                        <h3>No Payment History</h3>
                        <p>You haven't made any payments yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Receipt Content (Hidden for Printing) -->
    <div id="receiptContent">
        <?php if ($paymentDetails): ?>
        <div class="receipt-container">
            <!-- Watermark -->
            <div class="watermark"><?php echo htmlspecialchars($schoolInfo['school_name'] ?? 'SAHAB ACADEMY'); ?></div>
            
            <!-- Receipt Header -->
            <div class="receipt-header">
                <h2><?php echo htmlspecialchars($schoolInfo['school_name'] ?? 'SAHAB ACADEMY'); ?></h2>
                <h3>OFFICIAL PAYMENT RECEIPT</h3>
                <p><?php echo htmlspecialchars($schoolInfo['school_address'] ?? 'School Address'); ?></p>
                <p>Tel: <?php echo htmlspecialchars($schoolInfo['school_phone'] ?? 'N/A'); ?> | Email: <?php echo htmlspecialchars($schoolInfo['school_email'] ?? 'N/A'); ?></p>
            </div>
            
            <!-- Receipt Details -->
            <div class="receipt-details">
                <table>
                    <tr>
                        <th colspan="4" style="background: #f2f2f2; text-align: center;">RECEIPT INFORMATION</th>
                    </tr>
                    <tr>
                        <td><strong>Receipt Number:</strong></td>
                        <td><?php echo htmlspecialchars($paymentDetails['receipt_number']); ?></td>
                        <td><strong>Date Issued:</strong></td>
                        <td><?php echo date('F d, Y', strtotime($paymentDetails['payment_date'])); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Transaction ID:</strong></td>
                        <td colspan="3"><?php echo htmlspecialchars($paymentDetails['transaction_id']); ?></td>
                    </tr>
                </table>
                
                <table style="margin-top: 20px;">
                    <tr>
                        <th colspan="4" style="background: #f2f2f2; text-align: center;">STUDENT INFORMATION</th>
                    </tr>
                    <tr>
                        <td><strong>Student Name:</strong></td>
                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                        <td><strong>Admission No:</strong></td>
                        <td><?php echo htmlspecialchars($student['admission_no']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Class:</strong></td>
                        <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                        <td><strong>Guardian:</strong></td>
                        <td><?php echo htmlspecialchars($student['guardian_name']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Guardian Phone:</strong></td>
                        <td colspan="3"><?php echo htmlspecialchars($student['guardian_phone']); ?></td>
                    </tr>
                </table>
                
                <table style="margin-top: 20px;">
                    <tr>
                        <th colspan="4" style="background: #f2f2f2; text-align: center;">PAYMENT DETAILS</th>
                    </tr>
                    <tr>
                        <td><strong>Academic Year:</strong></td>
                        <td><?php echo htmlspecialchars($paymentDetails['academic_year']); ?></td>
                        <td><strong>Term:</strong></td>
                        <td><?php echo htmlspecialchars($paymentDetails['term']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Payment Method:</strong></td>
                        <td><?php echo ucwords(str_replace('_', ' ', $paymentDetails['payment_method'])); ?></td>
                        <td><strong>Payment Type:</strong></td>
                        <td><?php echo ucfirst($paymentDetails['payment_type']); ?></td>
                    </tr>
                    <?php if ($paymentDetails['payment_type'] == 'installment'): ?>
                    <tr>
                        <td><strong>Installment:</strong></td>
                        <td colspan="3"><?php echo $paymentDetails['installment_number'] . ' of ' . $paymentDetails['total_installments']; ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                
                <!-- Payment Breakdown -->
                <table style="margin-top: 20px;">
                    <tr>
                        <th colspan="2" style="background: #f2f2f2; text-align: center;">PAYMENT BREAKDOWN</th>
                    </tr>
                    <tr>
                        <td><strong>Total Fee:</strong></td>
                        <td style="text-align: right;"><?php echo $paymentHelper->formatCurrency($paymentDetails['total_amount']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Amount Paid:</strong></td>
                        <td style="text-align: right; color: #27ae60; font-weight: bold;">
                            <?php echo $paymentHelper->formatCurrency($paymentDetails['amount_paid']); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Balance Due:</strong></td>
                        <td style="text-align: right; color: #e74c3c; font-weight: bold;">
                            <?php echo $paymentHelper->formatCurrency($paymentDetails['total_amount'] - $paymentDetails['amount_paid']); ?>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Payment Status -->
            <div style="margin: 30px 0; padding: 15px; background: #f8f9fa; border-radius: 8px; text-align: center;">
                <h4 style="margin: 0; color: #2c3e50;">
                    Payment Status: 
                    <span style="color: <?php 
                        echo $paymentDetails['status'] == 'completed' ? '#27ae60' : 
                             ($paymentDetails['status'] == 'partial' ? '#3498db' : '#f39c12'); 
                    ?>;">
                        <?php echo strtoupper(str_replace('_', ' ', $paymentDetails['status'])); ?>
                    </span>
                </h4>
                <?php if ($paymentDetails['status'] == 'partial'): ?>
                <p style="margin: 10px 0 0 0; color: #e74c3c;">
                    <i class="fas fa-exclamation-circle"></i> 
                    Next installment due: <?php echo date('F d, Y', strtotime($paymentDetails['due_date'])); ?>
                </p>
                <?php endif; ?>
            </div>
            
            <!-- Bank Details -->
            <?php if (!empty($paymentDetails['bank_name'])): ?>
            <div style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 8px;">
                <h4 style="margin-bottom: 10px; color: #2c3e50;">Bank Payment Details</h4>
                <p><strong>Bank:</strong> <?php echo htmlspecialchars($paymentDetails['bank_name']); ?></p>
                <p><strong>Account Name:</strong> <?php echo htmlspecialchars($paymentDetails['account_name']); ?></p>
                <p><strong>Account Number:</strong> <?php echo htmlspecialchars($paymentDetails['account_number']); ?></p>
            </div>
            <?php endif; ?>
            
            <!-- Receipt Footer with Navigation -->
            <div class="receipt-footer">
                <div class="receipt-navigation print-navigation">
                    <a href="payment.php">
                        <i class="fas fa-arrow-left"></i> Back to Payments
                    </a>
                    <a href="dashboard.php">
                        <i class="fas fa-home"></i> Go to Dashboard
                    </a>
                </div>
                
                <div style="margin: 40px 0; text-align: center;">
                    <p><strong>Payment Verified and Approved By:</strong></p>
                    <div class="signature-section">
                        <div class="signature-line">
                            <p>_________________________</p>
                            <p>Student Signature</p>
                        </div>
                        <div class="signature-line">
                            <p>_________________________</p>
                            <p>School Authority</p>
                        </div>
                    </div>
                </div>
                
                <div style="border-top: 2px solid #000; padding-top: 15px; margin-top: 30px;">
                    <p><strong>Important Notice:</strong></p>
                    <p style="font-size: 12px; color: #666;">
                        1. This receipt must be presented for verification purposes.<br>
                        2. Keep this receipt in a safe place for future reference.<br>
                        3. For any discrepancies, contact the school accounts office within 7 days.<br>
                        4. Receipt generated on: <?php echo date('F d, Y h:i A'); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Payment Details Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Payment Details</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div id="paymentDetails">
                <!-- Payment details will be loaded here via AJAX -->
            </div>
        </div>
    </div>
    
    <script>
        let selectedFeeTypeAmount = <?php echo !empty($availableFeeTypes) && isset($feeAmount) ? $feeAmount : 0; ?>;
        let selectedFeeType = '<?php echo !empty($availableFeeTypes) ? $availableFeeTypes[0] : ''; ?>';
        
        function selectFeeType(feeType, amount) {
            selectedFeeType = feeType;
            selectedFeeTypeAmount = amount;
            
            // Update UI
            document.querySelectorAll('.fee-type-card').forEach(card => {
                card.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            // Update amount field
            document.getElementById('amount').value = amount.toFixed(2);
            
            // Recalculate installments if needed
            calculateInstallments();
        }
        
        function toggleInstallments() {
            var paymentType = document.getElementById('payment_type').value;
            var installmentDiv = document.getElementById('installment_options');
            if (paymentType === 'installment') {
                installmentDiv.style.display = 'block';
                calculateInstallments();
            } else {
                installmentDiv.style.display = 'none';
                // Reset to full amount
                document.getElementById('amount').value = selectedFeeTypeAmount.toFixed(2);
            }
        }
        
        function calculateInstallments() {
            var installments = document.getElementById('installments').value;
            var installmentAmount = selectedFeeTypeAmount / installments;
            document.getElementById('installment_amount').innerHTML = 
                '<i class="fas fa-calendar-check"></i> ' + 
                '₦' + installmentAmount.toFixed(2) + ' per installment';
            
            // Update amount field for installment
            if (document.getElementById('payment_type').value === 'installment') {
                document.getElementById('amount').value = installmentAmount.toFixed(2);
            }
        }
        
        function viewPaymentDetails(paymentId) {
            // Show loading message
            document.getElementById('paymentDetails').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #667eea;"></i>
                    <p style="margin-top: 20px;">Loading payment details...</p>
                </div>
            `;
            
            document.getElementById('paymentModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
            
            // Fetch payment details via AJAX
            fetch(`get_payment_details.php?id=${paymentId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(html => {
                    document.getElementById('paymentDetails').innerHTML = html;
                    
                    // Add PDF download button to modal if not present
                    if (!document.querySelector('.modal-pdf-btn')) {
                        const pdfBtn = document.createElement('button');
                        pdfBtn.className = 'btn btn-primary modal-pdf-btn';
                        pdfBtn.innerHTML = '<i class="fas fa-download"></i> Download PDF';
                        pdfBtn.onclick = function() {
                            downloadPaymentPDF(paymentId);
                        };
                        document.getElementById('paymentDetails').appendChild(pdfBtn);
                    }
                })
                .catch(error => {
                    document.getElementById('paymentDetails').innerHTML = 
                        '<div style="color: #e74c3c; text-align: center; padding: 20px;">' +
                        '<i class="fas fa-exclamation-circle"></i> ' +
                        'Error loading payment details. Please try again.' +
                        '</div>';
                });
        }
        
        function printPaymentReceipt(paymentId) {
            // This function is kept for compatibility but no longer used
            // All print functionality has been removed as requested
            showToast('info', 'Print functionality has been disabled. Please use PDF download instead.');
        }

        function downloadPaymentPDF(paymentId) {
            // Close modal if open
            closeModal();

            // Show loading message
            const loadingToast = showToast('info', 'Generating PDF receipt...');

            // Open PDF download in new window/tab
            window.open(`generate_payment_pdf.php?id=${paymentId}`, '_blank');

            // Hide loading message after a delay
            setTimeout(() => {
                if (loadingToast) {
                    loadingToast.style.display = 'none';
                }
            }, 2000);
        }
        
        function closeModal() {
            document.getElementById('paymentModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function refreshHistory() {
            location.reload();
        }
        
        // Form validation
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            var amount = parseFloat(document.getElementById('amount').value);
            var balance = parseFloat(<?php echo $balance; ?>);
            
            if (amount > balance) {
                e.preventDefault();
                alert('Payment amount cannot exceed your balance of ₦' + balance.toFixed(2));
                return false;
            }
            
            if (amount < 1000) {
                e.preventDefault();
                alert('Minimum payment amount is ₦1,000');
                return false;
            }
            
            // Validate file size if uploaded
            const fileInput = document.querySelector('input[name="payment_proof"]');
            if (fileInput.files.length > 0) {
                const fileSize = fileInput.files[0].size; // in bytes
                const maxSize = 5 * 1024 * 1024; // 5MB in bytes
                
                if (fileSize > maxSize) {
                    e.preventDefault();
                    alert('File size must be less than 5MB');
                    return false;
                }
            }
            
            return true;
        });
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Select first fee type by default
            const firstFeeTypeCard = document.querySelector('.fee-type-card');
            if (firstFeeTypeCard) {
                firstFeeTypeCard.classList.add('selected');
            }
            
            // Set initial amount
            if (selectedFeeTypeAmount > 0) {
                document.getElementById('amount').value = selectedFeeTypeAmount.toFixed(2);
            }
            
            // Calculate initial installments
            calculateInstallments();
            
            // Close modal on outside click
            window.onclick = function(event) {
                const modal = document.getElementById('paymentModal');
                if (event.target == modal) {
                    closeModal();
                }
            }
            
            // Add keyboard event listener for ESC key
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeModal();
                }
            });
            
            // Auto-print if print_id is in URL and it's from form submission
            const urlParams = new URLSearchParams(window.location.search);
            const printId = urlParams.get('print_id');
            const success = urlParams.get('success');
            
            if (printId && success) {
                // Show success message and auto-print
                setTimeout(() => {
                    printPaymentReceipt(printId);
                }, 1000);
            }
        });
        
        // Handle responsive adjustments
        window.addEventListener('resize', function() {
            // Adjust fee type grid on resize
            const feeTypeGrid = document.querySelector('.fee-type-grid');
            if (feeTypeGrid) {
                if (window.innerWidth <= 480) {
                    feeTypeGrid.style.gridTemplateColumns = 'repeat(2, 1fr)';
                } else {
                    feeTypeGrid.style.gridTemplateColumns = 'repeat(auto-fill, minmax(150px, 1fr))';
                }
            }
        });
        
        // Handle print event
        window.addEventListener('afterprint', function() {
            // Hide receipt content after printing
            document.getElementById('receiptContent').style.display = 'none';
        });

        // Toast notification function
        window.showToast = function(type, message) {
            // Remove existing toasts
            const existingToasts = document.querySelectorAll('.toast-notification');
            existingToasts.forEach(toast => toast.remove());

            // Create toast HTML
            const toastClass = type === 'success' ? 'bg-success' : 'bg-danger';
            const iconClass = type === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle';
            const toastHtml = `
                <div class="toast-notification position-fixed top-0 end-0 p-3" style="z-index: 9999;">
                    <div class="toast show align-items-center text-white ${toastClass} border-0" role="alert">
                        <div class="d-flex">
                            <div class="toast-body">
                                <i class="bi ${iconClass} me-2"></i>${message}
                            </div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.parentElement.parentElement.parentElement.remove()"></button>
                        </div>
                    </div>
                </div>
            `;

            // Add toast to body
            document.body.insertAdjacentHTML('beforeend', toastHtml);

            // Auto remove after 3 seconds
            setTimeout(() => {
                const toast = document.querySelector('.toast-notification');
                if (toast) {
                    toast.remove();
                }
            }, 3000);
        };
    </script>
</body>
</html>
