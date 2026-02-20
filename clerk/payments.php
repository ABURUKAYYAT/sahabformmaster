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

$query = "SELECT sp.*, s.full_name as student_name, s.admission_no, c.class_name, u.full_name as verified_by_name
          FROM student_payments sp
          JOIN students s ON sp.student_id = s.id
          JOIN classes c ON sp.class_id = c.id
          LEFT JOIN users u ON sp.verified_by = u.id
          WHERE sp.school_id = ?";
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

$query .= " ORDER BY sp.payment_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$classesStmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
$classesStmt->execute([$current_school_id]);
$classes = $classesStmt->fetchAll();

$studentsStmt = $pdo->prepare("SELECT id, full_name, admission_no, class_id FROM students WHERE school_id = ? ORDER BY full_name ASC");
$studentsStmt->execute([$current_school_id]);
$students = $studentsStmt->fetchAll();

$feeTypeOptions = include('../config/payment_config.php');
$feeTypeOptions = $feeTypeOptions['fee_types'] ?? [];

$yearStmt = $pdo->prepare("SELECT DISTINCT academic_year FROM fee_structure WHERE school_id = ? ORDER BY academic_year DESC");
$yearStmt->execute([$current_school_id]);
$academicYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <meta name="pwa-sw" content="../sw.js">
    <title>Payments - Clerk</title>
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/education-theme-main.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="stylesheet" href="../assets/css/offline-status.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --clerk-surface: #ffffff;
            --clerk-ink: #0f172a;
            --clerk-muted: #64748b;
            --clerk-border: #e2e8f0;
            --clerk-accent: #0ea5e9;
            --clerk-accent-strong: #2563eb;
            --clerk-radius: 12px;
        }
        .page-container { padding: 24px; }
        .filters { background: var(--clerk-surface); padding: 16px; border-radius: var(--clerk-radius); margin-bottom: 16px; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06); }
        .filters h4 { margin: 0 0 12px; font-size: 1rem; color: var(--clerk-ink); }
        .filters form { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        .filters input,
        .filters select,
        .filters textarea {
            width: 100%;
            min-height: 42px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--clerk-border);
            background: #f8fafc;
            color: var(--clerk-ink);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        .filters input:focus,
        .filters select:focus,
        .filters textarea:focus {
            border-color: var(--clerk-accent);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15);
            background: #ffffff;
        }
        .filters .btn { min-height: 42px; }
        .filters .text-muted { display: inline-block; margin-top: 6px; color: var(--clerk-muted); }
        .table-container { background: var(--clerk-surface); border-radius: var(--clerk-radius); padding: 16px; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06); }
        .table-scroll { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 720px; }
        th, td { padding: 12px; border-bottom: 1px solid var(--clerk-border); text-align: left; font-size: 0.95rem; }
        .badge { padding: 4px 10px; border-radius: 999px; font-size: 0.8rem; font-weight: 600; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-verified { background: #dbeafe; color: #1e40af; }
        .badge-completed { background: #dcfce7; color: #166534; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .badge-partial { background: #e0f2fe; color: #075985; }
        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { padding: 6px 12px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.85rem; }
        .btn-verify { background: #2563eb; color: #fff; }
        .btn-complete { background: #16a34a; color: #fff; }
        .btn-reject { background: #dc2626; color: #fff; }
        .btn-view { background: #0ea5e9; color: #fff; text-decoration: none; }
        .notice { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
        .notice.success { background: #dcfce7; color: #166534; }
        .notice.error { background: #fee2e2; color: #991b1b; }
        .btn-logout.clerk-logout { background: #dc2626; }
        .btn-logout.clerk-logout:hover { background: #b91c1c; }
        @media (max-width: 900px) {
            .filters form { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .page-container { padding: 16px; }
            .table-container { padding: 12px; }
            table { font-size: 0.9rem; min-width: 640px; }
            th, td { padding: 10px; }
        }
        @media (max-width: 520px) {
            .action-buttons { flex-direction: column; align-items: stretch; }
            .btn, .btn-view { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
<?php include '../includes/mobile_navigation.php'; ?>

<header class="dashboard-header">
    <div class="header-container">
        <div class="header-left">
            <div class="school-logo-container">
                <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                <div class="school-info">
                    <h1 class="school-name">SahabFormMaster</h1>
                    <p class="school-tagline">Clerk Portal</p>
                </div>
            </div>
        </div>
        <div class="header-right">
            <div class="teacher-info">
                <p class="teacher-label">Clerk</p>
                <span class="teacher-name"><?php echo htmlspecialchars($clerk_name); ?></span>
            </div>
            <a href="logout.php" class="btn-logout clerk-logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <?php include '../includes/clerk_sidebar.php'; ?>
    <main class="main-content">
        <div class="page-container">
            <h2>Payments</h2>

            <?php if ($message): ?>
                <div class="notice success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="filters" style="margin-bottom: 20px;">
                <h4 style="margin-bottom: 12px;">Record Cash Payment</h4>
                <form method="POST" data-offline-sync="1">
                    <input type="hidden" name="action" value="record_cash">
                    <select name="class_id" id="cashClassSelect" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="student_id" id="cashStudentSelect" required>
                        <option value="">Select Student</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?php echo $student['id']; ?>" data-class="<?php echo $student['class_id']; ?>">
                                <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['admission_no']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="term" id="cashTermSelect" required>
                        <option value="1st Term">1st Term</option>
                        <option value="2nd Term">2nd Term</option>
                        <option value="3rd Term">3rd Term</option>
                    </select>
                    <select name="academic_year" id="cashYearInput" required>
                        <?php foreach ($academicYears as $year): ?>
                            <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="fee_type" id="cashFeeTypeSelect">
                        <?php foreach ($feeTypeOptions as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="amount_display" id="cashAmountDisplay" placeholder="Amount" readonly>
                    <input type="text" name="notes" placeholder="Notes (optional)">
                    <button type="submit" class="btn btn-complete">Record Cash Payment</button>
                </form>
                <small class="text-muted" id="cashAmountHelp">Amount is calculated from fee structure.</small>
            </div>

            <div class="filters">
                <form method="GET">
                    <input type="text" name="search" placeholder="Search student, receipt, admission..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    <select name="status">
                        <option value="">All Status</option>
                        <?php foreach (['pending','verified','partial','completed','rejected'] as $status): ?>
                            <option value="<?php echo $status; ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                                <?php echo ucfirst($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="class_id">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo (string)$classFilter === (string)$class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-verify">Filter</button>
                </form>
            </div>

            <div class="table-container">
                <div class="table-scroll">
                <table>
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
                            <tr><td colspan="7">No payments found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($payment['receipt_number'] ?? '—'); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($payment['student_name']); ?><br>
                                        <small><?php echo htmlspecialchars($payment['admission_no']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['class_name']); ?></td>
                                    <td><?php echo $payment['payment_date'] ? date('d/m/Y', strtotime($payment['payment_date'])) : '—'; ?></td>
                                    <td><?php echo $paymentHelper->formatCurrency($payment['amount_paid']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo htmlspecialchars($payment['status']); ?>">
                                            <?php echo ucfirst($payment['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a class="btn btn-view" href="view_payment.php?id=<?php echo $payment['id']; ?>">View</a>
                                            <?php if ($payment['status'] === 'pending'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                    <input type="hidden" name="action" value="verify">
                                                    <button class="btn btn-verify" type="submit">Verify</button>
                                                </form>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <button class="btn btn-reject" type="submit">Reject</button>
                                                </form>
                                            <?php elseif (in_array($payment['status'], ['verified', 'partial'], true)): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                    <input type="hidden" name="action" value="complete">
                                                    <button class="btn btn-complete" type="submit">Complete</button>
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
            </div>
        </div>
    </main>
</div>
<script>
    const cashClassSelect = document.getElementById('cashClassSelect');
    const cashStudentSelect = document.getElementById('cashStudentSelect');
    const cashTermSelect = document.getElementById('cashTermSelect');
    const cashYearInput = document.getElementById('cashYearInput');
    const cashFeeTypeSelect = document.getElementById('cashFeeTypeSelect');
    const cashAmountDisplay = document.getElementById('cashAmountDisplay');
    const cashAmountHelp = document.getElementById('cashAmountHelp');

    function filterStudentsByClass() {
        const classId = cashClassSelect.value;
        Array.from(cashStudentSelect.options).forEach(option => {
            if (!option.value) return;
            option.style.display = option.dataset.class === classId ? '' : 'none';
        });
        cashStudentSelect.value = '';
    }

    async function updateCashAmount() {
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
            cashAmountDisplay.value = total.toFixed(2);
            cashAmountHelp.textContent = total > 0 ? 'Amount is calculated from fee structure.' : 'No fee structure found for this selection.';
        } catch (e) {
            cashAmountHelp.textContent = 'Failed to load fee amount.';
        }
    }

    filterStudentsByClass();
    updateCashAmount();

    cashClassSelect.addEventListener('change', () => {
        filterStudentsByClass();
        updateCashAmount();
    });
    cashTermSelect.addEventListener('change', updateCashAmount);
    cashYearInput.addEventListener('change', updateCashAmount);
    cashFeeTypeSelect.addEventListener('change', updateCashAmount);
</script>
<<<<<<< HEAD
<?php include '../includes/floating-button.php'; ?>
=======
<script src="../assets/js/offline-core.js" defer></script>
>>>>>>> c0a9436a5bdaac6265b3db8717fd0cfbf68b59a7
</body>
</html>
