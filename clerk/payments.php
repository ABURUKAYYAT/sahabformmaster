<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../helpers/payment_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'clerk') {
    header('Location: ../index.php');
    exit;
}

$current_school_id = require_school_auth();
PaymentHelper::ensureSchema();

$userId = $_SESSION['user_id'];
$paymentHelper = new PaymentHelper();
$message = '';
$error = '';
$clerk_name = $_SESSION['full_name'] ?? 'Clerk';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_cash') {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $classId = (int)($_POST['class_id'] ?? 0);
    $term = trim($_POST['term'] ?? '');
    $academicYear = trim($_POST['academic_year'] ?? '');
    $feeType = trim($_POST['fee_type'] ?? 'all');
    $notes = trim($_POST['notes'] ?? '');

    if ($studentId <= 0 || $classId <= 0 || $term === '' || $academicYear === '') {
        $error = 'Please select class, student, term, and academic year.';
    } else {
        // Validate student belongs to class and school
        $studentCheck = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id = ? AND class_id = ? AND school_id = ?");
        $studentCheck->execute([$studentId, $classId, $current_school_id]);
        if ((int)$studentCheck->fetchColumn() === 0) {
            $error = 'Selected student not found for this class.';
        } else {
            $breakdown = $paymentHelper->getFeeBreakdown($classId, $term, $academicYear, null, $current_school_id);
            $totalAmount = 0;
            if ($feeType === 'all') {
                $totalAmount = (float)($breakdown['total'] ?? 0);
            } else {
                foreach ($breakdown['breakdown'] as $fee) {
                    if ($fee['fee_type'] === $feeType) {
                        $totalAmount += (float)$fee['amount'];
                    }
                }
            }

            if ($totalAmount <= 0) {
                $yearStmt = $pdo->prepare("SELECT DISTINCT academic_year FROM fee_structure WHERE class_id = ? AND term = ? AND school_id = ? ORDER BY academic_year DESC");
                $yearStmt->execute([$classId, $term, $current_school_id]);
                $fallbackYear = $yearStmt->fetchColumn();
                if ($fallbackYear) {
                    $breakdown = $paymentHelper->getFeeBreakdown($classId, $term, $fallbackYear, null, $current_school_id);
                    $totalAmount = 0;
                    if ($feeType === 'all') {
                        $totalAmount = (float)($breakdown['total'] ?? 0);
                    } else {
                        foreach ($breakdown['breakdown'] as $fee) {
                            if ($fee['fee_type'] === $feeType) {
                                $totalAmount += (float)$fee['amount'];
                            }
                        }
                    }
                    $academicYear = $fallbackYear;
                }
            }

            if ($totalAmount <= 0) {
                $error = 'No fee structure found for the selected class/term/year.';
            } else {
                $transactionId = PaymentHelper::generateTransactionId($studentId);
                $receiptNumber = PaymentHelper::generateReceiptNumber($current_school_id);
                $insert = $pdo->prepare("INSERT INTO student_payments
                    (student_id, school_id, class_id, amount_paid, total_amount, payment_date, academic_year,
                     payment_method, payment_type, fee_type, status, term, transaction_id, verified_by, verified_at, notes, receipt_number)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?, 'cash', 'full', ?, 'completed', ?, ?, ?, NOW(), ?, ?)");
                $insert->execute([
                    $studentId,
                    $current_school_id,
                    $classId,
                    $totalAmount,
                    $totalAmount,
                    $academicYear,
                    $feeType ?: 'all',
                    $term,
                    $transactionId,
                    $userId,
                    $notes,
                    $receiptNumber
                ]);
                $message = 'Cash payment recorded successfully.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['payment_id'])) {
    $paymentId = (int) $_POST['payment_id'];
    $action = $_POST['action'];
    $notes = trim($_POST['notes'] ?? '');

    $paymentStmt = $pdo->prepare("SELECT * FROM student_payments WHERE id = ? AND school_id = ?");
    $paymentStmt->execute([$paymentId, $current_school_id]);
    $payment = $paymentStmt->fetch();

    if (!$payment) {
        $error = 'Payment not found or access denied.';
    } else {
        try {
            $pdo->beginTransaction();

            if ($action === 'verify') {
                $status = 'verified';
                if ($payment['payment_type'] === 'installment' && (float)$payment['amount_paid'] < (float)$payment['total_amount']) {
                    $status = 'partial';
                }
                $receiptNumber = $payment['receipt_number'] ?: PaymentHelper::generateReceiptNumber($current_school_id);

                $stmt = $pdo->prepare("UPDATE student_payments
                                      SET status = ?,
                                          receipt_number = ?,
                                          verified_by = ?,
                                          verified_at = NOW(),
                                          verification_notes = ?
                                      WHERE id = ? AND school_id = ?");
                $stmt->execute([$status, $receiptNumber, $userId, $notes, $paymentId, $current_school_id]);
                $message = 'Payment verified.';
            } elseif ($action === 'complete') {
                $receiptNumber = $payment['receipt_number'] ?: PaymentHelper::generateReceiptNumber($current_school_id);
                $stmt = $pdo->prepare("UPDATE student_payments
                                      SET status = 'completed',
                                          receipt_number = ?,
                                          verified_by = ?,
                                          verified_at = NOW(),
                                          verification_notes = ?
                                      WHERE id = ? AND school_id = ?");
                $stmt->execute([$receiptNumber, $userId, $notes, $paymentId, $current_school_id]);
                $message = 'Payment marked as completed.';
            } elseif ($action === 'reject') {
                $stmt = $pdo->prepare("UPDATE student_payments
                                      SET status = 'rejected',
                                          verified_by = ?,
                                          verified_at = NOW(),
                                          verification_notes = ?
                                      WHERE id = ? AND school_id = ?");
                $stmt->execute([$userId, $notes, $paymentId, $current_school_id]);
                $message = 'Payment rejected.';
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Action failed: ' . $e->getMessage();
        }
    }
}

// AJAX: fee amount lookup
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fee_amount') {
    header('Content-Type: application/json');
    $class_id = (int)($_GET['class_id'] ?? 0);
    $term = trim($_GET['term'] ?? '');
    $academic_year = trim($_GET['academic_year'] ?? '');
    $fee_type = trim($_GET['fee_type'] ?? 'all');

    if ($class_id <= 0 || $term === '' || $academic_year === '') {
        echo json_encode(['total' => 0, 'count' => 0]);
        exit;
    }

    $breakdown = $paymentHelper->getFeeBreakdown($class_id, $term, $academic_year, null, $current_school_id);
    $total = 0;
    if ($fee_type === 'all') {
        $total = (float)($breakdown['total'] ?? 0);
    } else {
        $matched = false;
        foreach ($breakdown['breakdown'] as $fee) {
            if ($fee['fee_type'] === $fee_type) {
                $total += (float)$fee['amount'];
                $matched = true;
            }
        }
        if (!$matched) {
            $feeTypeOptions = include('../config/payment_config.php');
            $feeTypeOptions = $feeTypeOptions['fee_types'] ?? [];
            $label = $feeTypeOptions[$fee_type] ?? '';
            if ($label) {
                foreach ($breakdown['breakdown'] as $fee) {
                    $feeLabel = $feeTypeOptions[$fee['fee_type']] ?? '';
                    if ($feeLabel && $feeLabel === $label) {
                        $total += (float)$fee['amount'];
                    }
                }
            }
        }
    }

    if ($total <= 0) {
        $yearStmt = $pdo->prepare("SELECT DISTINCT academic_year FROM fee_structure WHERE class_id = ? AND term = ? AND school_id = ? ORDER BY academic_year DESC");
        $yearStmt->execute([$class_id, $term, $current_school_id]);
        $fallbackYear = $yearStmt->fetchColumn();
        if ($fallbackYear) {
            $breakdown = $paymentHelper->getFeeBreakdown($class_id, $term, $fallbackYear, null, $current_school_id);
            $total = 0;
            if ($fee_type === 'all') {
                $total = (float)($breakdown['total'] ?? 0);
            } else {
                $matched = false;
                foreach ($breakdown['breakdown'] as $fee) {
                    if ($fee['fee_type'] === $fee_type) {
                        $total += (float)$fee['amount'];
                        $matched = true;
                    }
                }
                if (!$matched) {
                    $feeTypeOptions = include('../config/payment_config.php');
                    $feeTypeOptions = $feeTypeOptions['fee_types'] ?? [];
                    $label = $feeTypeOptions[$fee_type] ?? '';
                    if ($label) {
                        foreach ($breakdown['breakdown'] as $fee) {
                            $feeLabel = $feeTypeOptions[$fee['fee_type']] ?? '';
                            if ($feeLabel && $feeLabel === $label) {
                                $total += (float)$fee['amount'];
                            }
                        }
                    }
                }
            }
            echo json_encode(['total' => $total, 'count' => count($breakdown['breakdown'] ?? []), 'fallback_year' => $fallbackYear]);
            exit;
        }
    }

    echo json_encode(['total' => $total, 'count' => count($breakdown['breakdown'] ?? [])]);
    exit;
}

// Filters
$searchTerm = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$classFilter = $_GET['class_id'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$fromQuery = " FROM student_payments sp
               JOIN students s ON sp.student_id = s.id
               JOIN classes c ON sp.class_id = c.id
               LEFT JOIN users u ON sp.verified_by = u.id
               WHERE sp.school_id = ?";
$params = [$current_school_id];

if ($searchTerm) {
    $fromQuery .= " AND (s.full_name LIKE ? OR s.admission_no LIKE ? OR sp.receipt_number LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

if ($statusFilter) {
    $fromQuery .= " AND sp.status = ?";
    $params[] = $statusFilter;
}

if ($classFilter) {
    $fromQuery .= " AND sp.class_id = ?";
    $params[] = $classFilter;
}

$countStmt = $pdo->prepare("SELECT COUNT(*)" . $fromQuery);
$countStmt->execute($params);
$totalPaymentRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalPaymentRecords / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$query = "SELECT sp.*, s.full_name as student_name, s.admission_no, c.class_name, u.full_name as verified_by_name" .
    $fromQuery .
    " ORDER BY sp.payment_date DESC, sp.id DESC LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$showingFrom = $totalPaymentRecords > 0 ? ($offset + 1) : 0;
$showingTo = $totalPaymentRecords > 0 ? min($offset + $perPage, $totalPaymentRecords) : 0;
$paginationQuery = $_GET;
unset($paginationQuery['page']);

$buildPageUrl = static function (int $targetPage) use ($paginationQuery): string {
    $queryParams = $paginationQuery;
    $queryParams['page'] = max(1, $targetPage);
    return 'payments.php?' . http_build_query($queryParams);
};

$maxPageLinks = 5;
$pageWindowStart = max(1, $page - 2);
$pageWindowEnd = min($totalPages, $pageWindowStart + $maxPageLinks - 1);
$pageWindowStart = max(1, $pageWindowEnd - $maxPageLinks + 1);

$classesStmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
$classesStmt->execute([$current_school_id]);
$classes = $classesStmt->fetchAll();

$studentsStmt = $pdo->prepare("SELECT id, full_name, admission_no, class_id FROM students WHERE school_id = ? ORDER BY full_name ASC");
$studentsStmt->execute([$current_school_id]);
$students = $studentsStmt->fetchAll();

$classAnalyticsStmt = $pdo->prepare("SELECT
    c.id,
    c.class_name,
    COUNT(s.id) AS total_students,
    SUM(CASE
        WHEN s.id IS NOT NULL AND COALESCE(payment_flags.is_completed, 0) = 1 THEN 1
        ELSE 0
    END) AS paid_students,
    SUM(CASE
        WHEN s.id IS NOT NULL
             AND COALESCE(payment_flags.is_completed, 0) = 0
             AND COALESCE(payment_flags.is_partial, 0) = 1 THEN 1
        ELSE 0
    END) AS partial_students,
    SUM(CASE
        WHEN s.id IS NOT NULL
             AND COALESCE(payment_flags.has_payment_progress, 0) = 0 THEN 1
        ELSE 0
    END) AS unpaid_students
FROM classes c
LEFT JOIN students s
    ON s.class_id = c.id
   AND s.school_id = c.school_id
LEFT JOIN (
    SELECT
        school_id,
        class_id,
        student_id,
        MAX(CASE WHEN COALESCE(NULLIF(LOWER(TRIM(status)), ''), 'pending') = 'completed' THEN 1 ELSE 0 END) AS is_completed,
        MAX(CASE WHEN COALESCE(NULLIF(LOWER(TRIM(status)), ''), 'pending') = 'partial' THEN 1 ELSE 0 END) AS is_partial,
        MAX(CASE WHEN COALESCE(NULLIF(LOWER(TRIM(status)), ''), 'pending') IN ('verified', 'partial', 'completed') THEN 1 ELSE 0 END) AS has_payment_progress
    FROM student_payments
    WHERE school_id = ?
    GROUP BY school_id, class_id, student_id
) payment_flags
    ON payment_flags.school_id = s.school_id
   AND payment_flags.class_id = s.class_id
   AND payment_flags.student_id = s.id
WHERE c.school_id = ?
GROUP BY c.id, c.class_name
ORDER BY c.class_name ASC");
$classAnalyticsStmt->execute([$current_school_id, $current_school_id]);
$classAnalyticsRows = $classAnalyticsStmt->fetchAll();

$feeTypeOptions = include('../config/payment_config.php');
$feeTypeOptions = $feeTypeOptions['fee_types'] ?? [];

$yearStmt = $pdo->prepare("SELECT DISTINCT academic_year FROM fee_structure WHERE school_id = ? ORDER BY academic_year DESC");
$yearStmt->execute([$current_school_id]);
$academicYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

$statusSummary = [
    'pending' => 0,
    'verified' => 0,
    'partial' => 0,
    'completed' => 0,
    'rejected' => 0,
    'other' => 0,
];
$visibleTotalAmount = 0.0;
$clearedTotalAmount = 0.0;

foreach ($payments as $paymentRow) {
    $statusKey = strtolower(trim((string) ($paymentRow['status'] ?? '')));
    if (!array_key_exists($statusKey, $statusSummary)) {
        $statusKey = 'other';
    }

    $statusSummary[$statusKey]++;
    $amountPaid = (float) ($paymentRow['amount_paid'] ?? 0);
    $visibleTotalAmount += $amountPaid;
    if (in_array($statusKey, ['verified', 'partial', 'completed'], true)) {
        $clearedTotalAmount += $amountPaid;
    }
}

$totalStudentsInSchool = count($students);
$paidStudentsInSchool = 0;
$partialStudentsInSchool = 0;
$unpaidStudentsInSchool = 0;
$classAnalyticsChart = [
    'labels' => [],
    'totals' => [],
    'paid' => [],
    'partial' => [],
    'unpaid' => [],
];

foreach ($classAnalyticsRows as &$classAnalyticsRow) {
    $classAnalyticsRow['total_students'] = (int) ($classAnalyticsRow['total_students'] ?? 0);
    $classAnalyticsRow['paid_students'] = (int) ($classAnalyticsRow['paid_students'] ?? 0);
    $classAnalyticsRow['partial_students'] = (int) ($classAnalyticsRow['partial_students'] ?? 0);
    $classAnalyticsRow['unpaid_students'] = (int) ($classAnalyticsRow['unpaid_students'] ?? 0);

    $paidStudentsInSchool += $classAnalyticsRow['paid_students'];
    $partialStudentsInSchool += $classAnalyticsRow['partial_students'];
    $unpaidStudentsInSchool += $classAnalyticsRow['unpaid_students'];

    $classAnalyticsChart['labels'][] = (string) ($classAnalyticsRow['class_name'] ?? 'Unknown');
    $classAnalyticsChart['totals'][] = $classAnalyticsRow['total_students'];
    $classAnalyticsChart['paid'][] = $classAnalyticsRow['paid_students'];
    $classAnalyticsChart['partial'][] = $classAnalyticsRow['partial_students'];
    $classAnalyticsChart['unpaid'][] = $classAnalyticsRow['unpaid_students'];
}
unset($classAnalyticsRow);

$studentAnalyticsPayload = [
    'schoolStatus' => [
        'labels' => ['Paid Students', 'Part Payments', 'Unpaid Students'],
        'values' => [$paidStudentsInSchool, $partialStudentsInSchool, $unpaidStudentsInSchool],
    ],
    'classBreakdown' => $classAnalyticsChart,
];

$statusClasses = [
    'pending' => 'bg-amber-500/10 text-amber-700',
    'verified' => 'bg-sky-500/10 text-sky-700',
    'partial' => 'bg-indigo-500/10 text-indigo-700',
    'completed' => 'bg-teal-600/10 text-teal-700',
    'rejected' => 'bg-red-500/10 text-red-700',
    'other' => 'bg-slate-500/10 text-slate-700',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <meta name="pwa-sw" content="../sw.js">
    <title>Payments | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/css/offline-status.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        .clerk-dashboard {
            overflow-x: hidden;
        }

        .clerk-dashboard .dashboard-shell {
            z-index: auto;
        }

        .clerk-dashboard .dashboard-shell > * {
            min-width: 0;
        }

        .clerk-dashboard .workspace-header-actions {
            min-width: 0;
        }

        body.nav-open {
            overflow: hidden;
        }

        [data-sidebar] {
            overflow: hidden;
        }

        [data-sidebar-overlay] {
            z-index: 35;
        }

        .sidebar-scroll-shell {
            height: 100%;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-y: contain;
            touch-action: pan-y;
            padding-bottom: max(1rem, env(safe-area-inset-bottom));
        }

        .form-grid {
            display: grid;
            gap: 0.9rem;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }

        .control-label {
            display: block;
            margin-bottom: 0.35rem;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #475569;
        }

        .control-field {
            width: 100%;
            min-height: 2.8rem;
            border-radius: 0.8rem;
            border: 1px solid rgba(15, 23, 42, 0.15);
            background: #ffffff;
            padding: 0.62rem 0.78rem;
            font-size: 0.92rem;
            color: #0f172a;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .control-field:focus {
            outline: none;
            border-color: rgba(13, 148, 136, 0.7);
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.15);
        }

        .control-field[readonly] {
            background: #f8fafc;
            color: #334155;
            font-weight: 600;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.25rem 0.62rem;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .payment-table-wrap {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            max-height: 70vh;
            overflow: auto;
            -webkit-overflow-scrolling: touch;
        }

        .payment-table {
            width: 100%;
            min-width: 950px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .payment-table thead th {
            padding: 0.78rem 0.7rem;
            border-bottom: 1px solid rgba(15, 23, 42, 0.1);
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #64748b;
            text-align: left;
            background: #f8fafc;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .payment-table tbody td {
            padding: 0.9rem 0.7rem;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            font-size: 0.88rem;
            color: #0f172a;
            vertical-align: top;
            word-break: break-word;
        }

        .payment-table tbody tr:hover {
            background: rgba(226, 232, 240, 0.35);
        }

        .action-cluster {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }

        .action-cluster form {
            margin: 0;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 0.68rem;
            border: 1px solid transparent;
            padding: 0.4rem 0.62rem;
            font-size: 0.74rem;
            font-weight: 700;
            line-height: 1.05;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.12s ease, opacity 0.12s ease;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            opacity: 0.94;
        }

        .action-btn-view {
            background: #e0f2fe;
            border-color: #bae6fd;
            color: #075985;
        }

        .action-btn-verify {
            background: #dbeafe;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }

        .action-btn-complete {
            background: #ccfbf1;
            border-color: #99f6e4;
            color: #0f766e;
        }

        .action-btn-reject {
            background: #fee2e2;
            border-color: #fecaca;
            color: #b91c1c;
        }

        .analytics-chart-wrap {
            position: relative;
            height: 18rem;
        }

        .analytics-table-wrap {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 1rem;
        }

        .analytics-table {
            width: 100%;
            min-width: 620px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .analytics-table thead th {
            padding: 0.8rem 0.75rem;
            border-bottom: 1px solid rgba(15, 23, 42, 0.1);
            background: #f8fafc;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #64748b;
            text-align: left;
        }

        .analytics-table tbody td,
        .analytics-table tfoot td {
            padding: 0.88rem 0.75rem;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            font-size: 0.88rem;
            color: #0f172a;
        }

        .analytics-table tfoot td {
            font-weight: 700;
            background: #f8fafc;
        }

        .pagination-wrap {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-top: 0.95rem;
        }

        .pagination-meta {
            font-size: 0.78rem;
            color: #64748b;
        }

        .pagination-links {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .pagination-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2rem;
            height: 2rem;
            padding: 0 0.65rem;
            border-radius: 0.65rem;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #fff;
            color: #0f172a;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.16s ease, border-color 0.16s ease, color 0.16s ease;
        }

        .pagination-link:hover {
            background: rgba(20, 184, 166, 0.1);
            border-color: rgba(20, 184, 166, 0.4);
            color: #0f766e;
        }

        .pagination-link-active {
            background: #0f766e;
            border-color: #0f766e;
            color: #fff;
            pointer-events: none;
        }

        .pagination-link-disabled {
            opacity: 0.45;
            cursor: not-allowed;
            pointer-events: none;
        }

        @media (max-width: 1024px) {
            .clerk-dashboard .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }

        @media (max-width: 920px) {
            .payment-table {
                min-width: 820px;
            }
        }

        @media (max-width: 640px) {
            .clerk-dashboard main {
                padding-top: 0;
                padding-bottom: 0;
                gap: 1rem;
            }

            .clerk-dashboard section {
                border-radius: 1.1rem;
                padding: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .clerk-dashboard .nav-wrap {
                gap: 0.65rem;
            }

            .clerk-dashboard .workspace-header-actions {
                margin-left: auto;
                gap: 0.45rem;
            }

            .clerk-dashboard .workspace-header-actions .btn {
                padding: 0.45rem 0.7rem;
                font-size: 0.75rem;
                line-height: 1.05;
            }

            .clerk-dashboard .workspace-header-actions .btn-outline {
                display: none;
            }

            .clerk-dashboard .workspace-header-actions .btn span {
                display: none;
            }

            .clerk-dashboard .workspace-header-actions .btn i {
                margin: 0;
            }

            .clerk-dashboard .rounded-2xl {
                border-radius: 0.95rem;
            }

            .clerk-dashboard h1.text-3xl {
                font-size: 1.55rem;
                line-height: 1.25;
            }

            .clerk-dashboard h2.text-2xl {
                font-size: 1.35rem;
                line-height: 1.3;
            }

            .clerk-dashboard [data-sidebar] {
                width: min(18rem, 86vw);
            }

            .payment-table-wrap {
                max-height: none;
                overflow-x: auto;
                overflow-y: hidden;
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 0.85rem;
            }

            .payment-table {
                min-width: 760px;
                border-spacing: 0;
            }

            .payment-table thead {
                display: table-header-group;
            }

            .payment-table tbody {
                display: table-row-group;
            }

            .payment-table tbody tr {
                display: table-row;
            }

            .payment-table tbody td {
                display: table-cell;
                padding: 0.68rem 0.62rem;
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                font-size: 0.82rem;
            }

            .payment-table tbody td:last-child {
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            }

            .payment-table tbody td[colspan] {
                text-align: center;
            }

            .action-cluster {
                display: flex;
                flex-wrap: wrap;
                gap: 0.35rem;
            }

            .action-cluster .action-btn,
            .action-cluster form .action-btn {
                width: auto;
            }

            .pagination-wrap {
                flex-direction: column;
                align-items: flex-start;
            }

            .pagination-links {
                width: 100%;
                gap: 0.3rem;
            }

            .pagination-link {
                flex: 1 1 auto;
            }

            .analytics-chart-wrap {
                height: 14rem;
            }
        }

        @media (max-width: 768px) {
            .clerk-dashboard .container {
                padding-left: 0.9rem;
                padding-right: 0.9rem;
            }

            .clerk-dashboard .dashboard-shell {
                gap: 1rem;
                padding-top: 1rem;
                padding-bottom: 1.25rem;
            }

            .clerk-dashboard h1.text-3xl {
                font-size: 1.6rem;
                line-height: 1.25;
            }

            .clerk-dashboard h2.text-2xl {
                font-size: 1.3rem;
                line-height: 1.3;
            }

            .clerk-dashboard section {
                padding: 1rem;
                border-radius: 1.15rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .clerk-dashboard .form-grid > * {
                min-width: 0;
            }

            .payment-table-wrap {
                max-height: none;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 0.9rem;
                background: #ffffff;
            }

            .payment-table {
                min-width: 760px;
                display: table;
                border-spacing: 0;
            }

            .payment-table thead {
                display: table-header-group;
            }

            .payment-table tbody {
                display: table-row-group;
            }

            .payment-table tbody tr {
                display: table-row;
            }

            .payment-table tbody td {
                display: table-cell;
                padding: 0.72rem 0.65rem;
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                font-size: 0.82rem;
            }

            .payment-table tbody td[colspan] {
                text-align: center;
            }

            .action-cluster {
                width: auto;
            }

            .action-cluster form {
                width: auto;
            }

            .action-cluster .action-btn,
            .action-cluster form .action-btn {
                width: auto;
                justify-content: center;
                white-space: nowrap;
            }

            .pagination-wrap {
                gap: 0.85rem;
            }

            .pagination-links {
                width: 100%;
                justify-content: flex-start;
            }
        }

        @media (max-width: 460px) {
            .payment-table {
                min-width: 680px;
            }

            .payment-table thead th,
            .payment-table tbody td,
            .analytics-table thead th,
            .analytics-table tbody td,
            .analytics-table tfoot td {
                padding: 0.7rem 0.55rem;
            }

            .pagination-link {
                min-width: 2.5rem;
            }
        }

        @media (max-width: 768px) {
            .control-field {
                font-size: 16px;
            }
        }
    </style>
</head>
<body class="landing clerk-dashboard">
    <header class="site-header">
        <div class="container nav-wrap">
            <div class="flex items-center gap-4">
                <button class="nav-toggle lg:hidden" type="button" data-sidebar-toggle aria-label="Open menu"><span></span><span></span><span></span></button>
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="w-10 h-10 rounded-xl object-cover">
                    <div class="hidden sm:block">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Clerk Portal</p>
                        <p class="text-lg font-semibold text-ink-900"><?php echo htmlspecialchars(get_school_display_name()); ?></p>
                    </div>
                </div>
            </div>
            <div class="workspace-header-actions flex items-center gap-3">
                <span class="hidden text-sm text-slate-600 md:block">Welcome, <?php echo htmlspecialchars($clerk_name); ?></span>
                <a class="btn btn-outline" href="payments.php">All Payments</a>
                <a class="btn btn-primary" href="logout.php" aria-label="Logout"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container dashboard-shell grid gap-6 lg:grid-cols-[280px_1fr] py-8">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 bg-white shadow-lift border-r border-ink-900/10 transform -translate-x-full transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell h-full overflow-y-auto">
                <div class="p-6 border-b border-ink-900/10">
                    <h2 class="text-lg font-semibold text-ink-900">Navigation</h2>
                    <p class="text-sm text-slate-500">Clerk workspace</p>
                </div>
                <nav class="p-4 space-y-1 text-sm">
                    <a href="index.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="payments.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold bg-teal-600/10 text-teal-700">
                        <i class="fas fa-money-check-alt"></i>
                        <span>Payments</span>
                    </a>
                    <a href="fee_structure.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-layer-group"></i>
                        <span>Fee Structure</span>
                    </a>
                    <a href="receipt.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-receipt"></i>
                        <span>Receipts</span>
                    </a>
                </nav>
            </div>
        </aside>
        <main class="space-y-6">
            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Operations</p>
                        <h1 class="text-3xl font-display text-ink-900">Payments Workspace</h1>
                        <p class="text-slate-600">Record cash transactions, verify submissions, and keep payment status updates consistent.</p>
                    </div>
                    <div class="grid w-full gap-3 sm:grid-cols-2 xl:w-auto xl:grid-cols-4">
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Visible Records</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo number_format(count($payments)); ?></p>
                        </div>
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Pending</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($statusSummary['pending']); ?></p>
                        </div>
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Cleared Value</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars($paymentHelper->formatCurrency($clearedTotalAmount)); ?></p>
                        </div>
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Visible Value</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars($paymentHelper->formatCurrency($visibleTotalAmount)); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($message || $error): ?>
                <section class="rounded-3xl bg-white p-5 shadow-soft border border-ink-900/5 space-y-3">
                    <?php if ($message): ?>
                        <div class="rounded-xl border border-teal-600/20 bg-teal-600/10 px-4 py-3 text-sm text-teal-800">
                            <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-700">
                            <i class="fas fa-circle-exclamation mr-2"></i><?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="mb-4 flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-2xl font-display text-ink-900">Student Payment Analytics</h2>
                        <p class="text-sm text-slate-600">Paid students are counted from completed payments, part payments are counted from partial records without a completed payment, and unpaid students have no verified, partial, or completed payment record yet.</p>
                    </div>
                    <span class="text-xs uppercase tracking-wide text-slate-500">School-wide class breakdown</span>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Total Students</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($totalStudentsInSchool); ?></p>
                    </div>
                    <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Paid Students</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($paidStudentsInSchool); ?></p>
                    </div>
                    <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Part Payments</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($partialStudentsInSchool); ?></p>
                    </div>
                    <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Unpaid Students</p>
                        <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($unpaidStudentsInSchool); ?></p>
                    </div>
                </div>

                <div class="mt-6 grid gap-4 xl:grid-cols-2">
                    <article class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4">
                        <div class="mb-3">
                            <h3 class="text-lg font-semibold text-ink-900">School Payment Status</h3>
                            <p class="text-sm text-slate-600">A quick view of paid, partial, and unpaid students across the school.</p>
                        </div>
                        <div class="analytics-chart-wrap">
                            <canvas id="studentStatusChart" class="h-full w-full"></canvas>
                            <p id="studentStatusChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No student payment analytics available yet.</p>
                        </div>
                    </article>

                    <article class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4">
                        <div class="mb-3">
                            <h3 class="text-lg font-semibold text-ink-900">Class Breakdown</h3>
                            <p class="text-sm text-slate-600">Compare total, paid, part-payment, and unpaid students across classes.</p>
                        </div>
                        <div class="analytics-chart-wrap">
                            <canvas id="classBreakdownChart" class="h-full w-full"></canvas>
                            <p id="classBreakdownChartEmpty" class="hidden h-full items-center justify-center text-sm text-slate-500">No class analytics available yet.</p>
                        </div>
                    </article>
                </div>

                <div class="mt-6 analytics-table-wrap">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Total Students</th>
                                <th>Paid Students</th>
                                <th>Unpaid Students</th>
                                <th>Part Payments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($classAnalyticsRows)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-slate-500">No class analytics available yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($classAnalyticsRows as $classAnalyticsRow): ?>
                                    <tr>
                                        <td class="font-semibold text-ink-900"><?php echo htmlspecialchars((string) ($classAnalyticsRow['class_name'] ?? 'Unknown')); ?></td>
                                        <td><?php echo number_format((int) $classAnalyticsRow['total_students']); ?></td>
                                        <td><?php echo number_format((int) $classAnalyticsRow['paid_students']); ?></td>
                                        <td><?php echo number_format((int) $classAnalyticsRow['unpaid_students']); ?></td>
                                        <td><?php echo number_format((int) $classAnalyticsRow['partial_students']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td><?php echo number_format($totalStudentsInSchool); ?></td>
                                <td><?php echo number_format($paidStudentsInSchool); ?></td>
                                <td><?php echo number_format($unpaidStudentsInSchool); ?></td>
                                <td><?php echo number_format($partialStudentsInSchool); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="mb-4">
                    <h2 class="text-2xl font-display text-ink-900">Record Cash Payment</h2>
                    <p class="text-sm text-slate-600">Select class, student, and fee target. Amount is calculated from configured fee structure.</p>
                </div>
                <form method="POST" class="space-y-4" data-offline-sync="1">
                    <input type="hidden" name="action" value="record_cash">
                    <div class="form-grid">
                        <div>
                            <label class="control-label" for="cashClassSelect">Class</label>
                            <select class="control-field" name="class_id" id="cashClassSelect" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="control-label" for="cashStudentSelect">Student</label>
                            <select class="control-field" name="student_id" id="cashStudentSelect" required>
                                <option value="">Select Student</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>" data-class="<?php echo $student['class_id']; ?>">
                                        <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['admission_no']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="control-label" for="cashTermSelect">Term</label>
                            <select class="control-field" name="term" id="cashTermSelect" required>
                                <option value="1st Term">1st Term</option>
                                <option value="2nd Term">2nd Term</option>
                                <option value="3rd Term">3rd Term</option>
                            </select>
                        </div>
                        <div>
                            <label class="control-label" for="cashYearInput">Academic Year</label>
                            <select class="control-field" name="academic_year" id="cashYearInput" required>
                                <?php if (empty($academicYears)): ?>
                                    <?php $fallbackAcademicYear = date('Y') . '/' . (date('Y') + 1); ?>
                                    <option value="<?php echo htmlspecialchars($fallbackAcademicYear); ?>"><?php echo htmlspecialchars($fallbackAcademicYear); ?></option>
                                <?php else: ?>
                                    <?php foreach ($academicYears as $year): ?>
                                        <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div>
                            <label class="control-label" for="cashFeeTypeSelect">Fee Type</label>
                            <select class="control-field" name="fee_type" id="cashFeeTypeSelect">
                                <?php foreach ($feeTypeOptions as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="control-label" for="cashAmountDisplay">Calculated Amount</label>
                            <input class="control-field" type="text" name="amount_display" id="cashAmountDisplay" placeholder="NGN 0.00" readonly>
                        </div>
                        <div class="sm:col-span-2 lg:col-span-2">
                            <label class="control-label" for="cashNotesInput">Notes</label>
                            <input class="control-field" type="text" name="notes" id="cashNotesInput" placeholder="Optional context for this transaction">
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-cash-register"></i>
                            <span>Record Cash Payment</span>
                        </button>
                        <p class="text-xs text-slate-500" id="cashAmountHelp">Amount is calculated from fee structure.</p>
                    </div>
                </form>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-ink-900">Search and Filters</h2>
                        <p class="text-sm text-slate-600">Filter by student details, payment status, and class.</p>
                    </div>
                    <a class="text-sm font-semibold text-teal-700 hover:text-teal-600" href="payments.php">Clear filters</a>
                </div>
                <form method="GET" class="form-grid">
                    <div>
                        <label class="control-label" for="searchInput">Search</label>
                        <input class="control-field" type="text" id="searchInput" name="search" placeholder="Student, receipt, admission number..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <div>
                        <label class="control-label" for="statusFilterSelect">Status</label>
                        <select class="control-field" id="statusFilterSelect" name="status">
                            <option value="">All Status</option>
                            <?php foreach (['pending', 'verified', 'partial', 'completed', 'rejected'] as $status): ?>
                                <option value="<?php echo $status; ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="control-label" for="classFilterSelect">Class</label>
                        <select class="control-field" id="classFilterSelect" name="class_id">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo (string) $classFilter === (string) $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="btn btn-outline w-full sm:w-auto">
                            <i class="fas fa-filter"></i>
                            <span>Apply Filters</span>
                        </button>
                    </div>
                </form>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-lg font-semibold text-ink-900">Payment Records</h2>
                    <span class="text-xs uppercase tracking-wide text-slate-500"><?php echo number_format($totalPaymentRecords); ?> total records</span>
                </div>
                <div class="payment-table-wrap">
                    <table class="payment-table">
                        <thead>
                            <tr>
                                <th>Receipt</th>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-slate-500">No payments found for the current filter.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payments as $payment): ?>
                                    <?php
                                    $rowStatusRaw = strtolower(trim((string) ($payment['status'] ?? '')));
                                    $rowStatusKey = array_key_exists($rowStatusRaw, $statusClasses) ? $rowStatusRaw : 'other';
                                    $rowStatusLabel = $rowStatusKey === 'other'
                                        ? (trim((string) ($payment['status'] ?? '')) !== '' ? (string) $payment['status'] : 'Other')
                                        : ucfirst($rowStatusKey);
                                    ?>
                                    <tr>
                                        <td data-label="Receipt"><?php echo htmlspecialchars((string) ($payment['receipt_number'] ?? 'N/A')); ?></td>
                                        <td data-label="Student">
                                            <p class="font-semibold text-ink-900"><?php echo htmlspecialchars((string) ($payment['student_name'] ?? 'Unknown Student')); ?></p>
                                            <p class="text-xs text-slate-500"><?php echo htmlspecialchars((string) ($payment['admission_no'] ?? '')); ?></p>
                                        </td>
                                        <td data-label="Class"><?php echo htmlspecialchars((string) ($payment['class_name'] ?? 'N/A')); ?></td>
                                        <td data-label="Date"><?php echo !empty($payment['payment_date']) ? htmlspecialchars(date('M j, Y', strtotime((string) $payment['payment_date']))) : 'N/A'; ?></td>
                                        <td data-label="Amount" class="font-semibold"><?php echo htmlspecialchars($paymentHelper->formatCurrency((float) ($payment['amount_paid'] ?? 0))); ?></td>
                                        <td data-label="Status">
                                            <span class="status-pill <?php echo $statusClasses[$rowStatusKey]; ?>">
                                                <?php echo htmlspecialchars($rowStatusLabel); ?>
                                            </span>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="action-cluster">
                                                <a class="action-btn action-btn-view" href="view_payment.php?id=<?php echo $payment['id']; ?>">
                                                    <i class="fas fa-eye"></i>
                                                    <span>View</span>
                                                </a>
                                                <?php if (($payment['status'] ?? '') === 'pending'): ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                        <input type="hidden" name="action" value="verify">
                                                        <button class="action-btn action-btn-verify" type="submit">
                                                            <i class="fas fa-check"></i>
                                                            <span>Verify</span>
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button class="action-btn action-btn-reject" type="submit">
                                                            <i class="fas fa-ban"></i>
                                                            <span>Reject</span>
                                                        </button>
                                                    </form>
                                                <?php elseif (in_array(($payment['status'] ?? ''), ['verified', 'partial'], true)): ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                        <input type="hidden" name="action" value="complete">
                                                        <button class="action-btn action-btn-complete" type="submit">
                                                            <i class="fas fa-check-double"></i>
                                                            <span>Complete</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-wrap">
                    <p class="pagination-meta">
                        Showing <?php echo number_format($showingFrom); ?> - <?php echo number_format($showingTo); ?> of <?php echo number_format($totalPaymentRecords); ?>
                    </p>
                    <?php if ($totalPages > 1): ?>
                        <nav class="pagination-links" aria-label="Payment record pages">
                            <a class="pagination-link <?php echo $page <= 1 ? 'pagination-link-disabled' : ''; ?>" href="<?php echo htmlspecialchars($buildPageUrl($page - 1)); ?>">Prev</a>
                            <?php for ($p = $pageWindowStart; $p <= $pageWindowEnd; $p++): ?>
                                <a class="pagination-link <?php echo $p === $page ? 'pagination-link-active' : ''; ?>" href="<?php echo htmlspecialchars($buildPageUrl($p)); ?>">
                                    <?php echo $p; ?>
                                </a>
                            <?php endfor; ?>
                            <a class="pagination-link <?php echo $page >= $totalPages ? 'pagination-link-disabled' : ''; ?>" href="<?php echo htmlspecialchars($buildPageUrl($page + 1)); ?>">Next</a>
                        </nav>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
<?php include '../includes/floating-button.php'; ?>

<script src="../assets/js/offline-core.js" defer></script>
<script>
    const studentAnalytics = <?php echo json_encode($studentAnalyticsPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.querySelector('[data-sidebar]');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const body = document.body;

    const openSidebar = () => {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('opacity-0', 'pointer-events-none');
        overlay.classList.add('opacity-100');
        body.classList.add('nav-open');
    };

    const closeSidebar = () => {
        if (!sidebar || !overlay) return;
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('opacity-0', 'pointer-events-none');
        overlay.classList.remove('opacity-100');
        body.classList.remove('nav-open');
    };

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            if (sidebar.classList.contains('-translate-x-full')) {
                openSidebar();
            } else {
                closeSidebar();
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    if (sidebar) {
        sidebar.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', closeSidebar);
        });
    }

    const toggleChartState = (canvasId, emptyId, hasData) => {
        const canvas = document.getElementById(canvasId);
        const emptyState = document.getElementById(emptyId);
        if (!canvas || !emptyState) {
            return;
        }

        if (hasData) {
            canvas.classList.remove('hidden');
            emptyState.classList.add('hidden');
            emptyState.classList.remove('flex');
            return;
        }

        canvas.classList.add('hidden');
        emptyState.classList.remove('hidden');
        emptyState.classList.add('flex');
    };

    const initializeAnalyticsCharts = () => {
        if (typeof Chart === 'undefined') {
            return;
        }

        Chart.defaults.color = '#475569';
        Chart.defaults.font.family = "'Manrope', sans-serif";
        const gridColor = 'rgba(148, 163, 184, 0.2)';

        const schoolStatusValues = (studentAnalytics.schoolStatus?.values || []).map((value) => Number(value || 0));
        const hasSchoolStatusData = schoolStatusValues.some((value) => value > 0);
        toggleChartState('studentStatusChart', 'studentStatusChartEmpty', hasSchoolStatusData);
        if (hasSchoolStatusData) {
            const ctx = document.getElementById('studentStatusChart');
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: studentAnalytics.schoolStatus?.labels || [],
                    datasets: [{
                        data: schoolStatusValues,
                        backgroundColor: ['#14b8a6', '#6366f1', '#f59e0b'],
                        borderWidth: 0,
                    }],
                },
                options: {
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                boxHeight: 12,
                            },
                        },
                    },
                },
            });
        }

        const classLabels = studentAnalytics.classBreakdown?.labels || [];
        const classTotals = (studentAnalytics.classBreakdown?.totals || []).map((value) => Number(value || 0));
        const classPaid = (studentAnalytics.classBreakdown?.paid || []).map((value) => Number(value || 0));
        const classPartial = (studentAnalytics.classBreakdown?.partial || []).map((value) => Number(value || 0));
        const classUnpaid = (studentAnalytics.classBreakdown?.unpaid || []).map((value) => Number(value || 0));
        const hasClassData = [...classTotals, ...classPaid, ...classPartial, ...classUnpaid].some((value) => value > 0);
        toggleChartState('classBreakdownChart', 'classBreakdownChartEmpty', hasClassData);
        if (hasClassData) {
            const ctx = document.getElementById('classBreakdownChart');
            new Chart(ctx, {
                data: {
                    labels: classLabels,
                    datasets: [
                        {
                            type: 'line',
                            label: 'Total Students',
                            data: classTotals,
                            borderColor: '#0f172a',
                            backgroundColor: 'rgba(15, 23, 42, 0.08)',
                            borderWidth: 2,
                            tension: 0.25,
                            pointRadius: 3,
                            pointHoverRadius: 4,
                            yAxisID: 'y',
                        },
                        {
                            type: 'bar',
                            label: 'Paid',
                            data: classPaid,
                            backgroundColor: 'rgba(20, 184, 166, 0.72)',
                            borderRadius: 8,
                            maxBarThickness: 34,
                            yAxisID: 'y',
                        },
                        {
                            type: 'bar',
                            label: 'Unpaid',
                            data: classUnpaid,
                            backgroundColor: 'rgba(245, 158, 11, 0.72)',
                            borderRadius: 8,
                            maxBarThickness: 34,
                            yAxisID: 'y',
                        },
                        {
                            type: 'bar',
                            label: 'Part Payments',
                            data: classPartial,
                            backgroundColor: 'rgba(99, 102, 241, 0.72)',
                            borderRadius: 8,
                            maxBarThickness: 34,
                            yAxisID: 'y',
                        },
                    ],
                },
                options: {
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false,
                            },
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0,
                            },
                            grid: {
                                color: gridColor,
                            },
                        },
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                    },
                },
            });
        }
    };

    const cashClassSelect = document.getElementById('cashClassSelect');
    const cashStudentSelect = document.getElementById('cashStudentSelect');
    const cashTermSelect = document.getElementById('cashTermSelect');
    const cashYearInput = document.getElementById('cashYearInput');
    const cashFeeTypeSelect = document.getElementById('cashFeeTypeSelect');
    const cashAmountDisplay = document.getElementById('cashAmountDisplay');
    const cashAmountHelp = document.getElementById('cashAmountHelp');

    function filterStudentsByClass() {
        if (!cashClassSelect || !cashStudentSelect) {
            return;
        }

        const classId = cashClassSelect.value;
        Array.from(cashStudentSelect.options).forEach((option) => {
            if (!option.value) return;
            option.style.display = option.dataset.class === classId ? '' : 'none';
        });
        cashStudentSelect.value = '';
    }

    async function updateCashAmount() {
        if (!cashClassSelect || !cashTermSelect || !cashYearInput || !cashFeeTypeSelect || !cashAmountDisplay || !cashAmountHelp) {
            return;
        }

        const classId = cashClassSelect.value;
        const term = cashTermSelect.value;
        const academicYear = cashYearInput.value;
        const feeType = cashFeeTypeSelect.value;

        if (!classId || !term || !academicYear) {
            cashAmountDisplay.value = '';
            return;
        }

        try {
            const params = new URLSearchParams({
                ajax: 'fee_amount',
                class_id: classId,
                term: term,
                academic_year: academicYear,
                fee_type: feeType
            });
            const res = await fetch(`payments.php?${params.toString()}`);
            const data = await res.json();
            const total = Number(data.total || 0);
            if (data.fallback_year && cashYearInput) {
                cashYearInput.value = data.fallback_year;
            }
            cashAmountDisplay.value = `NGN ${total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            cashAmountHelp.textContent = total > 0 ? 'Amount is calculated from fee structure.' : 'No fee structure found for this selection.';
        } catch (e) {
            cashAmountHelp.textContent = 'Failed to load fee amount.';
        }
    }

    if (cashClassSelect && cashStudentSelect && cashTermSelect && cashYearInput && cashFeeTypeSelect && cashAmountDisplay && cashAmountHelp) {
        filterStudentsByClass();
        updateCashAmount();

        cashClassSelect.addEventListener('change', () => {
            filterStudentsByClass();
            updateCashAmount();
        });
        cashTermSelect.addEventListener('change', updateCashAmount);
        cashYearInput.addEventListener('change', updateCashAmount);
        cashFeeTypeSelect.addEventListener('change', updateCashAmount);
    }

    initializeAnalyticsCharts();
</script>
</body>
</html>

