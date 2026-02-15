<?php
// clark/payments.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../helpers/payment_helper.php';

// Check if user is teacher (only teachers should access this page)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../index.php');
    exit();
}

// Get current school context
$current_school_id = require_school_auth();
$teacher_id = intval($_SESSION['user_id']);

$userId = $_SESSION['user_id'];
$paymentHelper = new PaymentHelper();

// Handle actions
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'verify':
            if (isset($_GET['id'])) {
                $stmt = $pdo->prepare("UPDATE student_payments SET status = 'completed', 
                                      verified_by = ?, verified_at = NOW() 
                                      WHERE id = ?");
                $stmt->execute([$userId, $_GET['id']]);
                header('Location: payments.php?verified=1');
                exit();
            }
            break;
            
        case 'reject':
            if (isset($_GET['id'])) {
                $stmt = $pdo->prepare("UPDATE student_payments SET status = 'cancelled', 
                                      verified_by = ?, verified_at = NOW() 
                                      WHERE id = ?");
                $stmt->execute([$userId, $_GET['id']]);
                header('Location: payments.php?rejected=1');
                exit();
            }
            break;
    }
}

// Search filters
$searchTerm = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$classFilter = $_GET['class_id'] ?? '';

// Build query - school-filtered
$query = "SELECT sp.*, s.full_name as student_name, s.admission_no, c.class_name, u.full_name as verified_by_name
          FROM student_payments sp
          JOIN students s ON sp.student_id = s.id
          JOIN classes c ON sp.class_id = c.id
          LEFT JOIN users u ON sp.verified_by = u.id
          WHERE s.school_id = ?";

$params = [$current_school_id];

if ($searchTerm) {
    $query .= " AND (s.full_name LIKE ? OR s.admission_no LIKE ? OR sp.receipt_number LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

if ($statusFilter) {
    $query .= " AND sp.status = ?";
    $params[] = $statusFilter;
}

if ($classFilter) {
    $query .= " AND sp.class_id = ?";
    $params[] = $classFilter;
}

$query .= " ORDER BY sp.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Get classes for filter - school-filtered
$classes = get_school_classes($pdo, $current_school_id);

// Statistics - school-filtered
$totalCollectedStmt = $pdo->prepare("SELECT SUM(amount_paid) FROM student_payments sp JOIN students s ON sp.student_id = s.id WHERE sp.status = 'completed' AND s.school_id = ?");
$totalCollectedStmt->execute([$current_school_id]);
$totalCollected = $totalCollectedStmt->fetchColumn();

$pendingPaymentsStmt = $pdo->prepare("SELECT COUNT(*) FROM student_payments sp JOIN students s ON sp.student_id = s.id WHERE sp.status = 'pending' AND s.school_id = ?");
$pendingPaymentsStmt->execute([$current_school_id]);
$pendingPayments = $pendingPaymentsStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management | SahabFormMaster</title>
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

        .stat-pending .stat-icon-modern {
            background: var(--gradient-warning);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-today .stat-icon-modern {
            background: var(--gradient-success);
            color: white;
            box-shadow: var(--shadow-medium);
        }

        .stat-month .stat-icon-modern {
            background: var(--gradient-accent);
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

        /* Form Controls */
        .controls-modern {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .form-row-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group-modern {
            position: relative;
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

        .form-input-modern::placeholder {
            color: var(--gray-400);
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
            background: white;
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
        }

        .btn-modern-secondary:hover {
            border-color: var(--primary-300);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        /* Quick Actions */
        .actions-modern {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-soft);
        }

        .actions-grid-modern {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .action-btn-modern {
            padding: 1.25rem 1.5rem;
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--gray-700);
            box-shadow: var(--shadow-soft);
            position: relative;
            overflow: hidden;
        }

        .action-btn-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.1), transparent);
            transition: left 0.5s;
        }

        .action-btn-modern:hover::before {
            left: 100%;
        }

        .action-btn-modern:hover {
            transform: translateY(-4px);
            border-color: var(--primary-300);
            box-shadow: var(--shadow-strong);
        }

        .action-icon-modern {
            font-size: 1.5rem;
            color: var(--primary-600);
            transition: transform 0.3s ease;
        }

        .action-btn-modern:hover .action-icon-modern {
            transform: scale(1.1);
        }

        .action-text-modern {
            font-weight: 600;
            font-size: 0.875rem;
            text-align: center;
        }

        /* Payments Table */
        .payments-table-container {
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

        .payments-table-modern {
            width: 100%;
            border-collapse: collapse;
        }

        .payments-table-modern th {
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

        .payments-table-modern td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.2s ease;
        }

        .payments-table-modern tr:nth-child(even) {
            background: var(--gray-50);
        }

        .payments-table-modern tr:hover {
            background: var(--primary-50);
        }

        .receipt-number-modern {
            font-weight: 600;
            color: var(--gray-900);
        }

        .student-name-modern {
            font-weight: 600;
            color: var(--gray-900);
            font-size: 1.125rem;
        }

        .admission-number-modern {
            font-weight: 500;
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        .payment-amount-modern {
            font-weight: 700;
            color: var(--success-600);
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .status-badge-modern {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .status-pending-modern {
            background: var(--warning-100);
            color: var(--warning-700);
        }

        .status-completed-modern {
            background: var(--success-100);
            color: var(--success-700);
        }

        .status-cancelled-modern {
            background: var(--error-100);
            color: var(--error-700);
        }

        .action-buttons-modern {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-modern-sm {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            text-decoration: none;
        }

        .btn-primary-modern {
            background: var(--primary-500);
            color: white;
        }

        .btn-primary-modern:hover {
            background: var(--primary-600);
            transform: translateY(-2px);
        }

        .btn-success-modern {
            background: var(--success-500);
            color: white;
        }

        .btn-success-modern:hover {
            background: var(--success-600);
            transform: translateY(-2px);
        }

        .btn-danger-modern {
            background: var(--error-500);
            color: white;
        }

        .btn-danger-modern:hover {
            background: var(--error-600);
            transform: translateY(-2px);
        }

        .btn-warning-modern {
            background: var(--warning-500);
            color: white;
        }

        .btn-warning-modern:hover {
            background: var(--warning-600);
            transform: translateY(-2px);
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
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .form-row-modern {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .actions-grid-modern {
                grid-template-columns: repeat(2, 1fr);
            }

            .table-header-modern {
                padding: 1rem;
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }

            .payments-table-modern th,
            .payments-table-modern td {
                padding: 0.75rem;
                font-size: 0.8rem;
            }

            .action-buttons-modern {
                flex-direction: column;
                gap: 0.25rem;
            }

            .btn-modern-sm {
                padding: 0.375rem 0.5rem;
                font-size: 0.7rem;
            }
        }

        @media (max-width: 480px) {
            .stats-modern {
                grid-template-columns: 1fr;
            }

            .actions-grid-modern {
                grid-template-columns: 1fr;
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
                <a href="index.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
                <div class="logo-container">
                    <i class="fas fa-credit-card"></i>
                </div>
                <div class="brand-text">
                    <h1>SahabFormMaster</h1>
                    <p>Payment Management</p>
                </div>
            </div>
            <div class="header-actions">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <p><?php echo ucfirst($_SESSION['role'] ?? 'User'); ?></p>
                        <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></span>
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
                    <i class="fas fa-money-bill-wave"></i>
                    Payment Management System
                </h2>
                <p class="card-subtitle-modern">
                    Efficiently manage and track student payments and receipts
                </p>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-modern">
            <div class="stat-card-modern stat-total animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-value-modern"><?php echo $paymentHelper->formatCurrency($totalCollected); ?></div>
                <div class="stat-label-modern">Total Collected</div>
            </div>

            <div class="stat-card-modern stat-pending animate-slide-in-left">
                <div class="stat-icon-modern">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value-modern"><?php echo $pendingPayments; ?></div>
                <div class="stat-label-modern">Pending Payments</div>
            </div>

            <div class="stat-card-modern stat-today animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-value-modern">
                    <?php
                    $todayStmt = $pdo->prepare("SELECT SUM(amount_paid) FROM student_payments sp JOIN students s ON sp.student_id = s.id WHERE DATE(sp.payment_date) = CURDATE() AND sp.status = 'completed' AND s.school_id = ?");
                    $todayStmt->execute([$current_school_id]);
                    $today = $todayStmt->fetchColumn();
                    echo $paymentHelper->formatCurrency($today);
                    ?>
                </div>
                <div class="stat-label-modern">Today's Collection</div>
            </div>

            <div class="stat-card-modern stat-month animate-slide-in-right">
                <div class="stat-icon-modern">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-value-modern">
                    <?php
                    $monthStmt = $pdo->prepare("SELECT SUM(amount_paid) FROM student_payments sp JOIN students s ON sp.student_id = s.id WHERE MONTH(sp.payment_date) = MONTH(CURDATE()) AND YEAR(sp.payment_date) = YEAR(CURDATE()) AND sp.status = 'completed' AND s.school_id = ?");
                    $monthStmt->execute([$current_school_id]);
                    $month = $monthStmt->fetchColumn();
                    echo $paymentHelper->formatCurrency($month);
                    ?>
                </div>
                <div class="stat-label-modern">This Month</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="actions-modern animate-fade-in-up">
            <div class="actions-grid-modern">
                <button class="action-btn-modern" onclick="exportPayments()">
                    <i class="fas fa-download action-icon-modern"></i>
                    <span class="action-text-modern">Export Report</span>
                </button>
                <button class="action-btn-modern" onclick="printPayments()">
                    <i class="fas fa-print action-icon-modern"></i>
                    <span class="action-text-modern">Print Summary</span>
                </button>
                <button class="action-btn-modern" onclick="showAnalytics()">
                    <i class="fas fa-chart-bar action-icon-modern"></i>
                    <span class="action-text-modern">View Analytics</span>
                </button>
                <button class="action-btn-modern" onclick="sendReminders()">
                    <i class="fas fa-bell action-icon-modern"></i>
                    <span class="action-text-modern">Send Reminders</span>
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_GET['verified'])): ?>
            <div class="alert-modern alert-success-modern animate-fade-in-up">
                <i class="fas fa-check-circle"></i>
                <span>Payment verified successfully!</span>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['rejected'])): ?>
            <div class="alert-modern alert-error-modern animate-fade-in-up">
                <i class="fas fa-exclamation-circle"></i>
                <span>Payment rejected successfully!</span>
            </div>
        <?php endif; ?>
        
        <!-- Controls Section -->
        <div class="controls-modern animate-fade-in-up">
            <form method="GET">
                <div class="form-row-modern">
                    <div class="form-group-modern">
                        <label class="form-label-modern">Search Payments</label>
                        <input type="text" class="form-input-modern" name="search"
                               placeholder="Student name, admission no, receipt..."
                               value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Payment Status</label>
                        <select class="form-input-modern" name="status">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $statusFilter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="completed" <?php echo $statusFilter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $statusFilter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">Class Filter</label>
                        <select class="form-input-modern" name="class_id">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"
                                    <?php echo $classFilter == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">&nbsp;</label>
                        <div style="display: flex; gap: 0.5rem;">
                            <button type="submit" class="btn-modern-primary">
                                <i class="fas fa-search"></i>
                                <span>Filter</span>
                            </button>
                            <a href="payments.php" class="btn-modern-secondary">
                                <i class="fas fa-times"></i>
                                <span>Clear</span>
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Payments Table -->
        <div class="payments-table-container animate-fade-in-up">
            <div class="table-header-modern">
                <div class="table-title-modern">
                    <i class="fas fa-list"></i>
                    Payment Records (<?php echo count($payments); ?>)
                </div>
            </div>

            <div class="table-wrapper-modern">
                <table class="payments-table-modern">
                    <thead>
                        <tr>
                            <th>Receipt No</th>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Verified By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td>
                                    <span class="receipt-number-modern"><?php echo htmlspecialchars($payment['receipt_number']); ?></span>
                                </td>
                                <td>
                                    <div class="student-name-modern"><?php echo htmlspecialchars($payment['student_name']); ?></div>
                                    <div class="admission-number-modern"><?php echo htmlspecialchars($payment['admission_no']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($payment['class_name']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                <td>
                                    <span class="payment-amount-modern"><?php echo $paymentHelper->formatCurrency($payment['amount_paid']); ?></span>
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
                                    <span class="status-badge-modern status-<?php echo $payment['status']; ?>-modern">
                                        <i class="fas fa-circle"></i>
                                        <span><?php echo ucfirst($payment['status']); ?></span>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    echo $payment['verified_by_name'] ?: 'Not verified';
                                    if ($payment['verified_at']) {
                                        echo '<br><small>' . date('d/m/Y H:i', strtotime($payment['verified_at'])) . '</small>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="action-buttons-modern">
                                        <a href="view_payment.php?id=<?php echo $payment['id']; ?>" class="btn-modern-sm btn-primary-modern">
                                            <i class="fas fa-eye"></i>
                                            <span>View</span>
                                        </a>
                                        <?php if ($payment['status'] == 'pending'): ?>
                                            <a href="?action=verify&id=<?php echo $payment['id']; ?>" class="btn-modern-sm btn-success-modern"
                                               onclick="return confirm('Verify this payment?')">
                                                <i class="fas fa-check"></i>
                                                <span>Verify</span>
                                            </a>
                                            <a href="?action=reject&id=<?php echo $payment['id']; ?>" class="btn-modern-sm btn-danger-modern"
                                               onclick="return confirm('Reject this payment?')">
                                                <i class="fas fa-times"></i>
                                                <span>Reject</span>
                                            </a>
                                        <?php endif; ?>
                                        <a href="receipt.php?id=<?php echo $payment['id']; ?>" target="_blank" class="btn-modern-sm btn-warning-modern">
                                            <i class="fas fa-receipt"></i>
                                            <span>Receipt</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        
    </div>

    <script>
        function exportPayments() {
            alert('Export functionality would be implemented here');
        }

        function printPayments() {
            window.print();
        }

        function showAnalytics() {
            alert('Analytics view would be implemented here');
        }

        function sendReminders() {
            alert('Send reminders functionality would be implemented here');
        }

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
    </script>`n`n    <?php include '../includes/floating-button.php'; ?>`n`n</body>
</html>
