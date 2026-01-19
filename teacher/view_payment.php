<?php
// teacher/view_payment.php - Modernized Design
session_start();
require_once '../config/db.php';
require_once '../helpers/payment_helper.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
require_once '../includes/functions.php';
$current_school_id = require_school_auth();

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$userName = $_SESSION['full_name'] ?? 'Admin';
$paymentHelper = new PaymentHelper();

// Get payment ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: payments.php");
    exit;
}

$paymentId = $_GET['id'];

// Get payment details with student and class info
$stmt = $pdo->prepare("SELECT sp.*,
                      s.full_name as student_name, s.admission_no, s.phone, s.guardian_name, s.guardian_phone,
                      c.class_name, c.id as class_id,
                      u.full_name as verified_by_name,
                      b.bank_name, b.account_number as bank_account_number
                      FROM student_payments sp
                      JOIN students s ON sp.student_id = s.id
                      JOIN classes c ON sp.class_id = c.id
                      LEFT JOIN users u ON sp.verified_by = u.id
                      LEFT JOIN school_bank_accounts b ON sp.bank_account_id = b.id
                      WHERE sp.id = ? AND sp.school_id = ?");
$stmt->execute([$paymentId, $current_school_id]);
$payment = $stmt->fetch();

if (!$payment) {
    header("Location: payments.php?error=payment_not_found");
    exit;
}

// Get payment attachments
$attachments = $pdo->prepare("SELECT * FROM payment_attachments WHERE payment_id = ? ORDER BY uploaded_at DESC");
$attachments->execute([$paymentId]);
$attachments = $attachments->fetchAll();

// Get installments if any
$installments = $pdo->prepare("SELECT * FROM payment_installments WHERE payment_id = ? ORDER BY installment_number");
$installments->execute([$paymentId]);
$installments = $installments->fetchAll();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        if (isset($_POST['verify_payment'])) {
            // Verify payment
            $notes = $_POST['verification_notes'] ?? '';

            $stmt = $pdo->prepare("UPDATE student_payments
                                  SET status = 'completed',
                                      verified_by = ?,
                                      verified_at = NOW(),
                                      notes = CONCAT(IFNULL(notes, ''), '\n[Verified by admin: ', ?, ' - ', NOW(), ']')
                                  WHERE id = ? AND school_id = ?");
            $stmt->execute([$userId, $notes, $paymentId, $current_school_id]);

            // Update installment status if it's an installment payment
            if ($payment['payment_type'] === 'installment') {
                $stmt = $pdo->prepare("UPDATE payment_installments
                                      SET status = 'paid',
                                          payment_date = CURDATE()
                                      WHERE payment_id = ? AND installment_number = ?");
                $stmt->execute([$paymentId, $payment['installment_number']]);
            }

            $success = "Payment verified successfully!";

        } elseif (isset($_POST['reject_payment'])) {
            // Reject payment
            $rejection_reason = $_POST['rejection_reason'] ?? '';

            $stmt = $pdo->prepare("UPDATE student_payments
                                  SET status = 'cancelled',
                                      verified_by = ?,
                                      verified_at = NOW(),
                                      notes = CONCAT(IFNULL(notes, ''), '\n[Rejected by admin: ', ?, ' - ', NOW(), ']')
                                  WHERE id = ? AND school_id = ?");
            $stmt->execute([$userId, $rejection_reason, $paymentId, $current_school_id]);

            $success = "Payment rejected!";

        } elseif (isset($_POST['add_note'])) {
            // Add admin note
            $note = $_POST['admin_note'] ?? '';

            $stmt = $pdo->prepare("UPDATE student_payments
                                  SET notes = CONCAT(IFNULL(notes, ''), '\n[Admin note: ', ?, ' - ', ?, ' - ', NOW(), ']')
                                  WHERE id = ? AND school_id = ?");
            $stmt->execute([$note, $_SESSION['full_name'] ?? 'Admin', $paymentId, $current_school_id]);

            $success = "Note added successfully!";

        } elseif (isset($_POST['upload_attachment'])) {
            // Upload attachment
            if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] === 0) {
                $uploadDir = '../uploads/payments/admin/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $fileName = time() . '_admin_' . basename($_FILES['attachment_file']['name']);
                $filePath = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['attachment_file']['tmp_name'], $filePath)) {
                    $stmt = $pdo->prepare("INSERT INTO payment_attachments
                                          (payment_id, file_name, file_path, uploaded_by, file_type)
                                          VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$paymentId, $fileName, $filePath, $userId, 'admin_attachment']);

                    $success = "Attachment uploaded successfully!";
                } else {
                    $error = "Failed to upload file.";
                }
            }
        }

        $pdo->commit();

        // Refresh payment data
        $stmt = $pdo->prepare("SELECT sp.*,
                              s.full_name as student_name, s.admission_no, s.phone, s.guardian_name, s.guardian_phone,
                              c.class_name, c.id as class_id,
                              u.full_name as verified_by_name,
                              b.bank_name, b.account_number as bank_account_number
                              FROM student_payments sp
                              JOIN students s ON sp.student_id = s.id
                              JOIN classes c ON sp.class_id = c.id
                              LEFT JOIN users u ON sp.verified_by = u.id
                              LEFT JOIN school_bank_accounts b ON sp.bank_account_id = b.id
                              WHERE sp.id = ? AND sp.school_id = ?");
        $stmt->execute([$paymentId, $current_school_id]);
        $payment = $stmt->fetch();

        // Refresh attachments
        $attachments = $pdo->prepare("SELECT * FROM payment_attachments WHERE payment_id = ? ORDER BY uploaded_at DESC");
        $attachments->execute([$paymentId]);
        $attachments = $attachments->fetchAll();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get student's other payments for context
$studentPayments = $pdo->prepare("SELECT * FROM student_payments
                                 WHERE student_id = ? AND id != ? AND school_id = ?
                                 ORDER BY payment_date DESC LIMIT 5");
$studentPayments->execute([$payment['student_id'], $paymentId, $current_school_id]);
$studentPayments = $studentPayments->fetchAll();

// Get class teacher if any
$classTeacher = $pdo->prepare("SELECT u.full_name FROM class_teachers ct
                              JOIN users u ON ct.teacher_id = u.id
                              WHERE ct.class_id = ? AND ct.school_id = ? LIMIT 1");
$classTeacher->execute([$payment['class_id'], $current_school_id]);
$classTeacher = $classTeacher->fetch();

// Calculate payment statistics
$paymentStats = [
    'total_attachments' => count($attachments),
    'completion_percentage' => ($payment['total_amount'] > 0) ? round(($payment['amount_paid'] / $payment['total_amount']) * 100, 1) : 0,
    'days_overdue' => (strtotime($payment['due_date']) < time() && $payment['status'] !== 'completed') ? floor((time() - strtotime($payment['due_date'])) / (60*60*24)) : 0
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Payment - SahabFormMaster</title>
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
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="10" cy="90" r="0.5" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
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

        .stat-present .stat-icon-modern {
            background: var(--gradient-success);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-absent .stat-icon-modern {
            background: var(--gradient-error);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-late .stat-icon-modern {
            background: var(--gradient-warning);
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

        /* Info Sections */
        .info-grid-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-section-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-soft);
            border-left: 4px solid var(--primary-500);
        }

        .info-section-modern h3 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .info-section-modern h3 i {
            color: var(--primary-600);
        }

        .info-item-modern {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .info-item-modern:last-child {
            border-bottom: none;
        }

        .info-label-modern {
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.875rem;
        }

        .info-value-modern {
            color: var(--gray-900);
            font-weight: 500;
        }

        /* Amount Highlight */
        .amount-highlight-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 24px;
            padding: 3rem 2rem;
            text-align: center;
            box-shadow: var(--shadow-soft);
            margin-bottom: 2rem;
        }

        .amount-value-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 4rem;
            font-weight: 800;
            color: var(--success-600);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .amount-subtitle-modern {
            font-size: 1.125rem;
            color: var(--gray-600);
            font-weight: 500;
            margin-bottom: 1rem;
        }

        .amount-balance-modern {
            font-size: 1rem;
            color: var(--gray-500);
        }

        /* Status Banner */
        .status-banner-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 24px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-soft);
            border-left: 6px solid var(--primary-500);
        }

        .status-banner-completed {
            border-left-color: var(--success-500);
        }

        .status-banner-pending {
            border-left-color: var(--warning-500);
        }

        .status-banner-cancelled {
            border-left-color: var(--error-500);
        }

        .status-header-modern {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .status-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .status-subtitle-modern {
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        .status-badge-modern {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-completed-modern {
            background: var(--success-100);
            color: var(--success-700);
        }

        .status-pending-modern {
            background: var(--warning-100);
            color: var(--warning-700);
        }

        .status-cancelled-modern {
            background: var(--error-100);
            color: var(--error-700);
        }

        .status-verified-modern {
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-top: 0.5rem;
        }

        /* Tabs */
        .tabs-modern {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px 20px 0 0;
            padding: 0 2rem;
            margin-bottom: 0;
            box-shadow: var(--shadow-soft);
        }

        .tabs-list-modern {
            display: flex;
            border-bottom: 1px solid var(--gray-200);
            margin-bottom: 0;
        }

        .tab-modern {
            padding: 1.25rem 1.5rem;
            cursor: pointer;
            border: 1px solid transparent;
            border-bottom: none;
            background: transparent;
            color: var(--gray-600);
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }

        .tab-modern:hover {
            color: var(--primary-600);
        }

        .tab-modern.active {
            background: white;
            border-color: var(--gray-200) var(--gray-200) white;
            border-radius: 12px 12px 0 0;
            font-weight: 600;
            color: var(--primary-700);
        }

        .tab-modern.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 20px;
            height: 3px;
            background: var(--primary-500);
            border-radius: 2px;
        }

        .tab-content-modern {
            display: none;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-top: none;
            border-radius: 0 0 24px 24px;
            padding: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .tab-content-modern.active {
            display: block;
        }

        /* Buttons */
        .btn-modern {
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            box-shadow: var(--shadow-medium);
        }

        .btn-modern-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-modern-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-strong);
        }

        .btn-modern-success {
            background: var(--gradient-success);
            color: white;
        }

        .btn-modern-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-strong);
        }

        .btn-modern-danger {
            background: var(--gradient-error);
            color: white;
        }

        .btn-modern-danger:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-strong);
        }

        .btn-modern-warning {
            background: var(--gradient-warning);
            color: white;
        }

        .btn-modern-warning:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-strong);
        }

        .btn-modern-secondary {
            background: var(--gray-100);
            color: var(--gray-700);
            border: 1px solid var(--gray-200);
        }

        .btn-modern-secondary:hover {
            background: var(--gray-200);
            transform: translateY(-2px);
        }

        /* Action Buttons */
        .action-buttons-modern {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        /* Verification Box */
        .verification-box-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 20px;
            padding: 2rem;
            margin-top: 2rem;
            box-shadow: var(--shadow-soft);
            border-left: 4px solid var(--warning-500);
        }

        .verification-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1rem;
        }

        .verification-text-modern {
            color: var(--gray-600);
            margin-bottom: 1.5rem;
        }

        /* Table */
        .table-container-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--shadow-soft);
        }

        .table-wrapper-modern {
            overflow-x: auto;
        }

        .table-modern {
            width: 100%;
            border-collapse: collapse;
        }

        .table-modern th {
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

        .table-modern td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.2s ease;
        }

        .table-modern tr:nth-child(even) {
            background: var(--gray-50);
        }

        .table-modern tr:hover {
            background: var(--primary-50);
        }

        /* Attachments Grid */
        .attachments-grid-modern {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .attachment-card-modern {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow-soft);
            transition: all 0.3s ease;
        }

        .attachment-card-modern:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-strong);
        }

        .attachment-name-modern {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 1rem;
        }

        .attachment-preview-modern {
            width: 100%;
            max-width: 200px;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid var(--gray-200);
        }

        .attachment-placeholder-modern {
            width: 100%;
            max-width: 200px;
            height: 150px;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: var(--gray-400);
            background: var(--gray-100);
        }

        .attachment-actions-modern {
            display: flex;
            gap: 0.5rem;
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        .attachment-link-modern {
            color: var(--primary-600);
            text-decoration: none;
        }

        .attachment-link-modern:hover {
            color: var(--primary-700);
            text-decoration: underline;
        }

        /* Progress Bar */
        .progress-modern {
            background: var(--gray-200);
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-bar-modern {
            height: 100%;
            background: var(--gradient-primary);
            transition: width 0.3s ease;
        }

        /* Timeline */
        .timeline-modern {
            position: relative;
            padding-left: 2rem;
        }

        .timeline-item-modern {
            position: relative;
            margin-bottom: 2rem;
            padding-left: 1rem;
        }

        .timeline-item-modern::before {
            content: '';
            position: absolute;
            left: -2.5rem;
            top: 0.5rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary-500);
        }

        .timeline-item-modern.completed::before {
            background: var(--success-500);
        }

        .timeline-item-modern.rejected::before {
            background: var(--error-500);
        }

        .timeline-content-modern {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 12px;
            padding: 1rem;
            box-shadow: var(--shadow-soft);
        }

        .timeline-title-modern {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }

        .timeline-meta-modern {
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        /* Modals */
        .modal-modern {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
        }

        .modal-content-modern {
            background: white;
            margin: 5% auto;
            padding: 0;
            width: 90%;
            max-width: 500px;
            border-radius: 20px;
            box-shadow: var(--shadow-strong);
            overflow: hidden;
        }

        .modal-header-modern {
            padding: 2rem;
            background: var(--gradient-primary);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title-modern {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
        }

        .modal-close-modern {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            padding: 0.5rem;
            border-radius: 8px;
            transition: background 0.2s ease;
        }

        .modal-close-modern:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .modal-body-modern {
            padding: 2rem;
        }

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

        .form-textarea-modern {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
        }

        .form-textarea-modern:focus {
            outline: none;
            border-color: var(--primary-500);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .modal-footer-modern {
            padding: 1.5rem 2rem;
            background: var(--gray-50);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
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

            .stats-modern {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .info-grid-modern {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .tabs-list-modern {
                flex-wrap: wrap;
            }

            .tab-modern {
                padding: 0.75rem 1rem;
                font-size: 0.8rem;
            }

            .action-buttons-modern {
                flex-direction: column;
            }

            .modal-content-modern {
                width: 95%;
                margin: 10% auto;
            }

            .table-modern th,
            .table-modern td {
                padding: 0.75rem;
                font-size: 0.8rem;
            }

            .attachments-grid-modern {
                grid-template-columns: 1fr;
            }

            .amount-value-modern {
                font-size: 2.5rem;
            }
        }

        @media (max-width: 480px) {
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

            .status-header-modern {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .modal-header-modern,
            .modal-body-modern,
            .modal-footer-modern {
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

        .gradient-success { background: linear-gradient(135deg, var(--success-500) 0%, var(--success-600) 100%); }
        .gradient-error { background: linear-gradient(135deg, var(--error-500) 0%, var(--error-600) 100%); }
        .gradient-warning { background: linear-gradient(135deg, var(--warning-500) 0%, var(--warning-600) 100%); }
    </style>
    <script>
        function showTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked tab
            event.currentTarget.classList.add('active');
        }
        
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function confirmReject() {
            var reason = document.getElementById('rejection_reason').value;
            if (!reason.trim()) {
                alert('Please provide a reason for rejection.');
                return false;
            }
            return confirm('Are you sure you want to reject this payment?');
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</head>
<body>
    <!-- Modern Header -->
    <header class="modern-header">
        <div class="header-content">
            <div class="header-brand">
                <a href="payments.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Payments</span> 
                </a>
                <div class="logo-container">
                    <i class="fas fa-credit-card"></i>
                </div>
                <div class="brand-text">
                    <h1>SahabFormMaster</h1>
                    <p>Payment Details</p>
                </div>
            </div>
            <div class="header-actions">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($userName, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <p><?php echo ucfirst($userRole); ?></p>
                        <span><?php echo htmlspecialchars($userName); ?></span>
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
                    <i class="fas fa-file-invoice-dollar"></i>
                    Payment Management System
                </h2>
                <p class="card-subtitle-modern">
                    View and manage student payment details with complete audit trail
                </p>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-modern">
            <div class="stat-card-modern stat-total animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-paperclip"></i>
                </div>
                <div class="stat-value-modern"><?php echo $paymentStats['total_attachments']; ?></div>
                <div class="stat-label-modern">Attachments</div>
            </div>

            <div class="stat-card-modern stat-present animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-value-modern"><?php echo $paymentStats['completion_percentage']; ?>%</div>
                <div class="stat-label-modern">Completion</div>
            </div>

            <?php if ($paymentStats['days_overdue'] > 0): ?>
            <div class="stat-card-modern stat-absent animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value-modern"><?php echo $paymentStats['days_overdue']; ?></div>
                <div class="stat-label-modern">Days Overdue</div>
            </div>
            <?php endif; ?>

            <div class="stat-card-modern stat-late animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-balance-scale"></i>
                </div>
                <div class="stat-value-modern">₦<?php echo number_format($payment['balance'], 2); ?></div>
                <div class="stat-label-modern">Balance</div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert-modern alert-success-modern animate-fade-in-up">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert-modern alert-error-modern animate-fade-in-up">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
            </div>
        <?php endif; ?>

        <!-- Status Banner -->
        <div class="status-banner-modern status-banner-<?php echo $payment['status']; ?> animate-fade-in-up">
            <div class="status-header-modern">
                <div>
                    <h2 class="status-title-modern">
                        <i class="fas fa-receipt"></i>
                        Payment #<?php echo htmlspecialchars($payment['receipt_number']); ?>
                    </h2>
                    <p class="status-subtitle-modern">
                        <?php echo htmlspecialchars($payment['student_name']); ?> -
                        <?php echo htmlspecialchars($payment['class_name']); ?> -
                        <?php echo htmlspecialchars($payment['term']); ?> <?php echo htmlspecialchars($payment['academic_year']); ?>
                    </p>
                </div>
                <span class="status-badge-modern status-<?php echo $payment['status']; ?>-modern">
                    <?php echo strtoupper($payment['status']); ?>
                </span>
            </div>
            <?php if ($payment['verified_by_name']): ?>
                <div class="status-verified-modern">
                    <i class="fas fa-user-check"></i>
                    Verified by <?php echo htmlspecialchars($payment['verified_by_name']); ?>
                    on <?php echo date('F j, Y H:i', strtotime($payment['verified_at'])); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Amount Highlight -->
        <div class="amount-highlight-modern animate-fade-in-up">
            <div class="amount-value-modern">₦<?php echo number_format($payment['amount_paid'], 2); ?></div>
            <div class="amount-subtitle-modern">
                of ₦<?php echo number_format($payment['total_amount'], 2); ?> total fee
            </div>
            <div class="amount-balance-modern">
                Balance: ₦<?php echo number_format($payment['balance'], 2); ?>
            </div>
        </div>

        <!-- Modern Tabs -->
        <div class="tabs-modern">
            <div class="tabs-list-modern">
                <button class="tab-modern active" onclick="showTab('details-tab')">
                    <i class="fas fa-info-circle"></i>
                    Payment Details
                </button>
                <button class="tab-modern" onclick="showTab('attachments-tab')">
                    <i class="fas fa-paperclip"></i>
                    Attachments (<?php echo count($attachments); ?>)
                </button>
                <button class="tab-modern" onclick="showTab('student-tab')">
                    <i class="fas fa-user-graduate"></i>
                    Student Info
                </button>
                <button class="tab-modern" onclick="showTab('history-tab')">
                    <i class="fas fa-history"></i>
                    Payment History
                </button>
                <?php if ($payment['payment_type'] === 'installment'): ?>
                    <button class="tab-modern" onclick="showTab('installments-tab')">
                        <i class="fas fa-calendar-alt"></i>
                        Installments
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Details Tab -->
        <div id="details-tab" class="tab-content-modern active">
            <div class="info-grid-modern">
                <!-- Payment Information -->
                <div class="info-section-modern">
                    <h3><i class="fas fa-credit-card"></i> Payment Information</h3>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Payment Date</span>
                        <span class="info-value-modern"><?php echo date('F j, Y', strtotime($payment['payment_date'])); ?></span>
                    </div>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Payment Method</span>
                        <span class="info-value-modern"><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></span>
                    </div>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Payment Type</span>
                        <span class="info-value-modern">
                            <?php echo ucfirst($payment['payment_type']); ?>
                            <?php if ($payment['payment_type'] === 'installment'): ?>
                                (Installment <?php echo $payment['installment_number']; ?> of <?php echo $payment['total_installments']; ?>)
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Transaction ID</span>
                        <span class="info-value-modern"><?php echo htmlspecialchars($payment['transaction_id']); ?></span>
                    </div>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Due Date</span>
                        <span class="info-value-modern"><?php echo date('F j, Y', strtotime($payment['due_date'])); ?></span>
                    </div>
                    <?php if ($payment['bank_name']): ?>
                        <div class="info-item-modern">
                            <span class="info-label-modern">Bank Account</span>
                            <span class="info-value-modern">
                                <?php echo htmlspecialchars($payment['bank_name']); ?><br>
                                <?php echo htmlspecialchars($payment['bank_account_number']); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Student Information -->
                <div class="info-section-modern">
                    <h3><i class="fas fa-user"></i> Student Information</h3>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Student Name</span>
                        <span class="info-value-modern"><?php echo htmlspecialchars($payment['student_name']); ?></span>
                    </div>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Admission Number</span>
                        <span class="info-value-modern"><?php echo htmlspecialchars($payment['admission_no']); ?></span>
                    </div>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Class</span>
                        <span class="info-value-modern"><?php echo htmlspecialchars($payment['class_name']); ?></span>
                    </div>
                    <?php if ($classTeacher): ?>
                        <div class="info-item-modern">
                            <span class="info-label-modern">Class Teacher</span>
                            <span class="info-value-modern"><?php echo htmlspecialchars($classTeacher['full_name']); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Student Phone</span>
                        <span class="info-value-modern"><?php echo htmlspecialchars($payment['phone']); ?></span>
                    </div>
                </div>

                <!-- Fee Information -->
                <div class="info-section-modern">
                    <h3><i class="fas fa-money-bill-wave"></i> Fee Information</h3>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Academic Year</span>
                        <span class="info-value-modern"><?php echo htmlspecialchars($payment['academic_year']); ?></span>
                    </div>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Term</span>
                        <span class="info-value-modern"><?php echo htmlspecialchars($payment['term']); ?></span>
                    </div>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Total Fee</span>
                        <span class="info-value-modern">₦<?php echo number_format($payment['total_amount'], 2); ?></span>
                    </div>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Amount Paid</span>
                        <span class="info-value-modern">₦<?php echo number_format($payment['amount_paid'], 2); ?></span>
                    </div>
                    <div class="info-item-modern">
                        <span class="info-label-modern">Balance</span>
                        <span class="info-value-modern">₦<?php echo number_format($payment['balance'], 2); ?></span>
                    </div>
                </div>
            </div>

            <!-- Notes Section -->
            <?php if (!empty($payment['notes'])): ?>
                <div class="modern-card animate-fade-in-up">
                    <div class="card-body-modern">
                        <h3 style="margin-bottom: 1rem;"><i class="fas fa-sticky-note"></i> Payment Notes</h3>
                        <div style="background: var(--gray-50); padding: 1.5rem; border-radius: 12px; white-space: pre-line; border-left: 4px solid var(--primary-500);">
                            <?php echo htmlspecialchars($payment['notes']); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Verification Box (Only for pending payments) -->
            <?php if ($payment['status'] == 'pending'): ?>
                <div class="verification-box-modern animate-fade-in-up">
                    <h3 class="verification-title-modern"><i class="fas fa-check-circle"></i> Payment Verification</h3>
                    <p class="verification-text-modern">Review payment details and attachments before verifying or rejecting this payment.</p>

                    <div class="action-buttons-modern">
                        <button class="btn-modern btn-modern-success" onclick="openModal('verifyModal')">
                            <i class="fas fa-check"></i>
                            <span>Verify Payment</span>
                        </button>
                        <button class="btn-modern btn-modern-danger" onclick="openModal('rejectModal')">
                            <i class="fas fa-times"></i>
                            <span>Reject Payment</span>
                        </button>
                        <button class="btn-modern btn-modern-warning" onclick="openModal('noteModal')">
                            <i class="fas fa-sticky-note"></i>
                            <span>Add Note</span>
                        </button>
                        <button class="btn-modern btn-modern-secondary" onclick="openModal('attachmentModal')">
                            <i class="fas fa-paperclip"></i>
                            <span>Upload File</span>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Attachments Tab -->
        <div id="attachments-tab" class="tab-content-modern">
            <div class="modern-card">
                <div class="card-body-modern">
                    <h3 style="margin-bottom: 1rem;"><i class="fas fa-paperclip"></i> Payment Attachments</h3>
                    <p style="color: var(--gray-600); margin-bottom: 2rem;">Supporting documents and proof of payment files</p>

                    <?php if (empty($attachments)): ?>
                        <div style="text-align: center; padding: 3rem; color: var(--gray-500);">
                            <i class="fas fa-file-alt" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                            <p>No attachments found for this payment.</p>
                        </div>
                    <?php else: ?>
                        <div class="attachments-grid-modern">
                            <?php foreach ($attachments as $attachment): ?>
                                <?php
                                $fileExt = strtolower(pathinfo($attachment['file_name'], PATHINFO_EXTENSION));
                                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];
                                $pdfExtensions = ['pdf'];
                                ?>
                                <div class="attachment-card-modern">
                                    <div class="attachment-name-modern">
                                        <?php echo htmlspecialchars($attachment['file_name']); ?>
                                    </div>

                                    <?php if (in_array($fileExt, $imageExtensions)): ?>
                                        <img src="<?php echo htmlspecialchars($attachment['file_path']); ?>"
                                             alt="Attachment" class="attachment-preview-modern"
                                             onclick="window.open('<?php echo htmlspecialchars($attachment['file_path']); ?>', '_blank')"
                                             style="cursor: pointer;">
                                    <?php elseif (in_array($fileExt, $pdfExtensions)): ?>
                                        <div class="attachment-placeholder-modern gradient-error">
                                            <i class="fas fa-file-pdf"></i>
                                        </div>
                                    <?php else: ?>
                                        <div class="attachment-placeholder-modern gradient-primary">
                                            <i class="fas fa-file"></i>
                                        </div>
                                    <?php endif; ?>

                                    <div class="attachment-actions-modern">
                                        <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>"
                                           target="_blank" class="attachment-link-modern">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>"
                                           download class="attachment-link-modern">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.5rem;">
                                        Uploaded: <?php echo date('M j, Y H:i', strtotime($attachment['uploaded_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top: 2rem;">
                        <button class="btn-modern btn-modern-secondary" onclick="openModal('attachmentModal')">
                            <i class="fas fa-plus"></i>
                            <span>Upload New Attachment</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Student Tab -->
        <div id="student-tab" class="tab-content-modern">
            <div class="modern-card">
                <div class="card-body-modern">
                    <h3 style="margin-bottom: 1rem;"><i class="fas fa-user-graduate"></i> Student Information</h3>

                    <div class="info-grid-modern">
                        <div class="info-section-modern">
                            <h4 style="font-size: 1rem; margin-bottom: 1rem;"><i class="fas fa-address-card"></i> Contact Information</h4>
                            <div class="info-item-modern">
                                <span class="info-label-modern">Full Name</span>
                                <span class="info-value-modern"><?php echo htmlspecialchars($payment['student_name']); ?></span>
                            </div>
                            <div class="info-item-modern">
                                <span class="info-label-modern">Admission Number</span>
                                <span class="info-value-modern"><?php echo htmlspecialchars($payment['admission_no']); ?></span>
                            </div>
                            <div class="info-item-modern">
                                <span class="info-label-modern">Phone Number</span>
                                <span class="info-value-modern"><?php echo htmlspecialchars($payment['phone']); ?></span>
                            </div>
                            <div class="info-item-modern">
                                <span class="info-label-modern">Class</span>
                                <span class="info-value-modern"><?php echo htmlspecialchars($payment['class_name']); ?></span>
                            </div>
                        </div>

                        <div class="info-section-modern">
                            <h4 style="font-size: 1rem; margin-bottom: 1rem;"><i class="fas fa-user-friends"></i> Guardian Information</h4>
                            <?php if ($payment['guardian_name']): ?>
                                <div class="info-item-modern">
                                    <span class="info-label-modern">Guardian Name</span>
                                    <span class="info-value-modern"><?php echo htmlspecialchars($payment['guardian_name']); ?></span>
                                </div>
                                <div class="info-item-modern">
                                    <span class="info-label-modern">Guardian Phone</span>
                                    <span class="info-value-modern"><?php echo htmlspecialchars($payment['guardian_phone']); ?></span>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                    <i class="fas fa-info-circle" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                    <p>No guardian information available.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Student's Recent Payments -->
                    <h4 style="margin-top: 3rem; margin-bottom: 1rem;"><i class="fas fa-history"></i> Recent Payments</h4>
                    <?php if (!empty($studentPayments)): ?>
                        <div class="table-container-modern">
                            <div class="table-wrapper-modern">
                                <table class="table-modern">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Receipt No</th>
                                            <th>Term</th>
                                            <th>Amount</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($studentPayments as $otherPayment): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y', strtotime($otherPayment['payment_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($otherPayment['receipt_number']); ?></td>
                                                <td><?php echo htmlspecialchars($otherPayment['term']); ?></td>
                                                <td>₦<?php echo number_format($otherPayment['amount_paid'], 2); ?></td>
                                                <td>
                                                    <?php echo ucfirst($otherPayment['payment_type']); ?>
                                                    <?php if ($otherPayment['payment_type'] === 'installment'): ?>
                                                        <br><small style="color: var(--gray-500);">(<?php echo $otherPayment['installment_number']; ?>/<?php echo $otherPayment['total_installments']; ?>)</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge-modern status-<?php echo $otherPayment['status']; ?>-modern">
                                                        <?php echo ucfirst($otherPayment['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="view_payment.php?id=<?php echo $otherPayment['id']; ?>"
                                                       class="btn-modern btn-modern-primary" style="padding: 0.5rem 1rem; font-size: 0.8rem;">
                                                        <i class="fas fa-eye"></i>
                                                        <span>View</span>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 3rem; color: var(--gray-500);">
                            <i class="fas fa-receipt" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                            <p>No other payments found for this student.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- History Tab -->
        <div id="history-tab" class="tab-content-modern">
            <div class="modern-card">
                <div class="card-body-modern">
                    <h3 style="margin-bottom: 1rem;"><i class="fas fa-history"></i> Payment History & Audit Trail</h3>

                    <div class="info-grid-modern">
                        <div class="info-section-modern">
                            <h4 style="font-size: 1rem; margin-bottom: 1rem;"><i class="fas fa-timeline"></i> Payment Timeline</h4>
                            <div class="timeline-modern">
                                <!-- Created -->
                                <div class="timeline-item-modern">
                                    <div class="timeline-content-modern">
                                        <div class="timeline-title-modern">Payment Created</div>
                                        <div class="timeline-meta-modern">
                                            <?php echo date('F j, Y \a\t H:i:s', strtotime($payment['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Verified (if applicable) -->
                                <?php if ($payment['verified_at']): ?>
                                    <div class="timeline-item-modern completed">
                                        <div class="timeline-content-modern">
                                            <div class="timeline-title-modern">Payment Verified</div>
                                            <div class="timeline-meta-modern">
                                                By: <?php echo htmlspecialchars($payment['verified_by_name']); ?><br>
                                                <?php echo date('F j, Y \a\t H:i:s', strtotime($payment['verified_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Last Updated -->
                                <?php if ($payment['updated_at'] != $payment['created_at']): ?>
                                    <div class="timeline-item-modern">
                                        <div class="timeline-content-modern">
                                            <div class="timeline-title-modern">Last Updated</div>
                                            <div class="timeline-meta-modern">
                                                <?php echo date('F j, Y \a\t H:i:s', strtotime($payment['updated_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="info-section-modern">
                            <h4 style="font-size: 1rem; margin-bottom: 1rem;"><i class="fas fa-chart-bar"></i> Payment Statistics</h4>
                            <div class="info-item-modern">
                                <span class="info-label-modern">Amount Paid</span>
                                <span class="info-value-modern" style="font-weight: 700; color: var(--success-600);">₦<?php echo number_format($payment['amount_paid'], 2); ?></span>
                            </div>
                            <div class="info-item-modern">
                                <span class="info-label-modern">Total Fee</span>
                                <span class="info-value-modern">₦<?php echo number_format($payment['total_amount'], 2); ?></span>
                            </div>
                            <div class="info-item-modern">
                                <span class="info-label-modern">Balance</span>
                                <span class="info-value-modern" style="color: <?php echo $payment['balance'] > 0 ? 'var(--error-600)' : 'var(--success-600)'; ?>; font-weight: 600;">
                                    ₦<?php echo number_format($payment['balance'], 2); ?>
                                </span>
                            </div>
                            <div class="info-item-modern">
                                <span class="info-label-modern">Completion Rate</span>
                                <span class="info-value-modern" style="font-weight: 600;">
                                    <?php echo $paymentStats['completion_percentage']; ?>%
                                </span>
                            </div>

                            <!-- Progress Bar -->
                            <div class="progress-modern">
                                <div class="progress-bar-modern" style="width: <?php echo $paymentStats['completion_percentage']; ?>%;"></div>
                            </div>
                            <div style="text-align: center; margin-top: 0.5rem; font-size: 0.8rem; color: var(--gray-600);">
                                <?php echo $paymentStats['completion_percentage']; ?>% of total fee paid
                            </div>
                        </div>
                    </div>

                    <!-- System Notes -->
                    <?php if (!empty($payment['notes'])): ?>
                        <div style="margin-top: 3rem;">
                            <h4 style="margin-bottom: 1rem;"><i class="fas fa-sticky-note"></i> System Notes</h4>
                            <div style="background: var(--gray-50); padding: 1.5rem; border-radius: 12px; max-height: 300px; overflow-y: auto; border-left: 4px solid var(--primary-500);">
                                <?php
                                $notes = explode("\n", $payment['notes']);
                                foreach ($notes as $note):
                                    if (trim($note)):
                                ?>
                                    <div style="padding: 0.5rem 0; border-bottom: 1px solid var(--gray-200); font-size: 0.9rem; color: var(--gray-700);">
                                        <?php echo htmlspecialchars($note); ?>
                                    </div>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Installments Tab (Only for installment payments) -->
        <?php if ($payment['payment_type'] === 'installment'): ?>
            <div id="installments-tab" class="tab-content-modern">
                <div class="modern-card">
                    <div class="card-body-modern">
                        <h3 style="margin-bottom: 0.5rem;"><i class="fas fa-calendar-alt"></i> Installment Payment Schedule</h3>
                        <p style="color: var(--gray-600); margin-bottom: 2rem;">
                            Payment plan: <?php echo $payment['total_installments']; ?> installments of
                            ₦<?php echo number_format($payment['total_amount'] / $payment['total_installments'], 2); ?> each
                        </p>

                        <?php if (!empty($installments)): ?>
                            <div class="table-container-modern">
                                <div class="table-wrapper-modern">
                                    <table class="table-modern">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Due Date</th>
                                                <th>Amount Due</th>
                                                <th>Amount Paid</th>
                                                <th>Payment Date</th>
                                                <th>Status</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($installments as $installment): ?>
                                                <tr style="<?php echo $installment['installment_number'] == $payment['installment_number'] ? 'background: var(--primary-50);' : ''; ?>">
                                                    <td>
                                                        <span style="font-weight: 600;"><?php echo $installment['installment_number']; ?></span>
                                                        <?php if ($installment['installment_number'] == $payment['installment_number']): ?>
                                                            <br><small style="color: var(--primary-600); font-weight: 600;">(Current)</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo date('M j, Y', strtotime($installment['due_date'])); ?>
                                                        <?php if (date('Y-m-d') > $installment['due_date'] && $installment['status'] != 'paid'): ?>
                                                            <br><small style="color: var(--error-600); font-weight: 600;">(Overdue)</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>₦<?php echo number_format($installment['amount_due'], 2); ?></td>
                                                    <td>₦<?php echo number_format($installment['amount_paid'], 2); ?></td>
                                                    <td>
                                                        <?php echo $installment['payment_date'] ? date('M j, Y', strtotime($installment['payment_date'])) : '-'; ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge-modern status-<?php echo $installment['status']; ?>-modern">
                                                            <?php echo ucfirst($installment['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($installment['notes'] ?: '-'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Installment Summary -->
                            <div style="margin-top: 3rem; background: var(--gray-50); padding: 2rem; border-radius: 16px; border-left: 4px solid var(--primary-500);">
                                <h4 style="margin-bottom: 1.5rem;"><i class="fas fa-chart-pie"></i> Installment Summary</h4>
                                <?php
                                $totalPaid = array_sum(array_column($installments, 'amount_paid'));
                                $totalDue = array_sum(array_column($installments, 'amount_due'));
                                $remaining = $totalDue - $totalPaid;
                                ?>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 2rem;">
                                    <div style="text-align: center;">
                                        <div style="font-size: 2rem; font-weight: 800; color: var(--gray-900); margin-bottom: 0.5rem;">₦<?php echo number_format($totalDue, 2); ?></div>
                                        <div style="color: var(--gray-600); font-weight: 600;">Total Amount Due</div>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="font-size: 2rem; font-weight: 800; color: var(--success-600); margin-bottom: 0.5rem;">₦<?php echo number_format($totalPaid, 2); ?></div>
                                        <div style="color: var(--gray-600); font-weight: 600;">Total Paid</div>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="font-size: 2rem; font-weight: 800; color: var(--error-600); margin-bottom: 0.5rem;">₦<?php echo number_format($remaining, 2); ?></div>
                                        <div style="color: var(--gray-600); font-weight: 600;">Remaining Balance</div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 3rem; color: var(--gray-500);">
                                <i class="fas fa-calendar-times" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                                <p>No installment schedule found for this payment.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        
    </div>
    
    <!-- Verification Modal -->
    <div id="verifyModal" class="modal-modern">
        <div class="modal-content-modern">
            <div class="modal-header-modern">
                <h3 class="modal-title-modern"><i class="fas fa-check-circle"></i> Verify Payment</h3>
                <button class="modal-close-modern" onclick="closeModal('verifyModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body-modern">
                    <div class="form-group-modern">
                        <label class="form-label-modern">Verification Notes (Optional)</label>
                        <textarea name="verification_notes" class="form-textarea-modern" rows="3"
                                  placeholder="Add any notes about this verification..."></textarea>
                    </div>
                    <p style="color: var(--gray-600); font-size: 0.875rem; margin-bottom: 1.5rem;">
                        <strong>Note:</strong> Verifying this payment will mark it as completed and update the student's payment record.
                    </p>
                </div>
                <div class="modal-footer-modern">
                    <button type="button" class="btn-modern btn-modern-secondary" onclick="closeModal('verifyModal')">Cancel</button>
                    <button type="submit" name="verify_payment" class="btn-modern btn-modern-success">
                        <i class="fas fa-check"></i>
                        <span>Confirm Verification</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div id="rejectModal" class="modal-modern">
        <div class="modal-content-modern">
            <div class="modal-header-modern">
                <h3 class="modal-title-modern"><i class="fas fa-times-circle"></i> Reject Payment</h3>
                <button class="modal-close-modern" onclick="closeModal('rejectModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" onsubmit="return confirmReject()">
                <div class="modal-body-modern">
                    <div class="form-group-modern">
                        <label class="form-label-modern">Reason for Rejection *</label>
                        <textarea name="rejection_reason" id="rejection_reason" class="form-textarea-modern" rows="3" required
                                  placeholder="Please provide a reason for rejecting this payment..."></textarea>
                    </div>
                    <div style="background: var(--error-50); border: 1px solid var(--error-200); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <i class="fas fa-exclamation-triangle" style="color: var(--error-600);"></i>
                            <strong style="color: var(--error-700);">Warning</strong>
                        </div>
                        <p style="color: var(--error-700); font-size: 0.875rem; margin: 0;">
                            Rejecting this payment will mark it as cancelled. The student will need to make a new payment.
                        </p>
                    </div>
                </div>
                <div class="modal-footer-modern">
                    <button type="button" class="btn-modern btn-modern-secondary" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" name="reject_payment" class="btn-modern btn-modern-danger">
                        <i class="fas fa-times"></i>
                        <span>Confirm Rejection</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Note Modal -->
    <div id="noteModal" class="modal-modern">
        <div class="modal-content-modern">
            <div class="modal-header-modern">
                <h3 class="modal-title-modern"><i class="fas fa-sticky-note"></i> Add Admin Note</h3>
                <button class="modal-close-modern" onclick="closeModal('noteModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body-modern">
                    <div class="form-group-modern">
                        <label class="form-label-modern">Note *</label>
                        <textarea name="admin_note" class="form-textarea-modern" rows="4" required
                                  placeholder="Add an administrative note about this payment..."></textarea>
                    </div>
                    <p style="color: var(--gray-600); font-size: 0.875rem; margin-bottom: 1.5rem;">
                        Notes are visible to all administrators and are logged in the payment history.
                    </p>
                </div>
                <div class="modal-footer-modern">
                    <button type="button" class="btn-modern btn-modern-secondary" onclick="closeModal('noteModal')">Cancel</button>
                    <button type="submit" name="add_note" class="btn-modern btn-modern-primary">
                        <i class="fas fa-save"></i>
                        <span>Add Note</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Upload Attachment Modal -->
    <div id="attachmentModal" class="modal-modern">
        <div class="modal-content-modern">
            <div class="modal-header-modern">
                <h3 class="modal-title-modern"><i class="fas fa-paperclip"></i> Upload Attachment</h3>
                <button class="modal-close-modern" onclick="closeModal('attachmentModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body-modern">
                    <div class="form-group-modern">
                        <label class="form-label-modern">Select File *</label>
                        <input type="file" name="attachment_file" class="form-input-modern" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" required>
                        <small style="color: var(--gray-500); font-size: 0.8rem; display: block; margin-top: 0.25rem;">
                            Accepted formats: JPG, PNG, PDF, DOC, DOCX (Max 5MB)
                        </small>
                    </div>
                    <div style="background: var(--primary-50); border: 1px solid var(--primary-200); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <i class="fas fa-info-circle" style="color: var(--primary-600);"></i>
                            <strong style="color: var(--primary-700);">Upload Information</strong>
                        </div>
                        <p style="color: var(--primary-700); font-size: 0.875rem; margin: 0;">
                            Upload supporting documents, correspondence, or other files related to this payment.
                        </p>
                    </div>
                </div>
                <div class="modal-footer-modern">
                    <button type="button" class="btn-modern btn-modern-secondary" onclick="closeModal('attachmentModal')">Cancel</button>
                    <button type="submit" name="upload_attachment" class="btn-modern btn-modern-primary">
                        <i class="fas fa-upload"></i>
                        <span>Upload File</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Initialize first tab as active
        document.addEventListener('DOMContentLoaded', function() {
            showTab('details-tab');
        });
    </script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>
