<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../helpers/payment_helper.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: index.php');
    exit;
}

$studentId = (int) $_SESSION['student_id'];
$current_school_id = get_current_school_id();
$student_name = (string) ($_SESSION['student_name'] ?? '');
$admission_number = (string) ($_SESSION['admission_no'] ?? '');
$paymentHelper = new PaymentHelper();
PaymentHelper::ensureSchema();
$paymentConfig = include '../config/payment_config.php';
$feeTypeLabels = $paymentConfig['fee_types'] ?? [];
$bankAccounts = PaymentHelper::getSchoolBankAccounts($current_school_id);

$stmt = $pdo->prepare("SELECT s.*, c.class_name FROM students s JOIN classes c ON s.class_id = c.id AND c.school_id = ? WHERE s.id = ? AND s.school_id = ? LIMIT 1");
$stmt->execute([$current_school_id, $studentId, $current_school_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    session_destroy();
    header('Location: index.php?error=access_denied');
    exit;
}
if ($student_name === '') {
    $student_name = (string) ($student['full_name'] ?? 'Student');
}
if ($admission_number === '') {
    $admission_number = (string) ($student['admission_no'] ?? '');
}

$currentTerm = '1st Term';
$currentYear = date('Y') . '/' . (date('Y') + 1);
$terms = ['1st Term', '2nd Term', '3rd Term'];

$yearsStmt = $pdo->prepare("SELECT DISTINCT academic_year FROM fee_structure WHERE class_id = ? AND school_id = ? ORDER BY academic_year DESC");
$yearsStmt->execute([(int) $student['class_id'], $current_school_id]);
$academicYears = array_map('trim', $yearsStmt->fetchAll(PDO::FETCH_COLUMN));
if (empty($academicYears)) {
    $academicYears = [$currentYear];
}

$selectedYear = $_POST['academic_year'] ?? $_GET['academic_year'] ?? $academicYears[0] ?? $currentYear;
if (!in_array($selectedYear, $academicYears, true)) {
    $selectedYear = $academicYears[0] ?? $currentYear;
}

$feeDataByYearTerm = [];
foreach ($academicYears as $year) {
    foreach ($terms as $term) {
        $feeDataByYearTerm[$year][$term] = $paymentHelper->getFeeBreakdown((int) $student['class_id'], $term, $year, null, $current_school_id);
    }
}

$firstAvailableYearByTerm = [];
foreach ($terms as $term) {
    foreach ($academicYears as $year) {
        if (!empty($feeDataByYearTerm[$year][$term]['total'])) {
            $firstAvailableYearByTerm[$term] = $year;
            break;
        }
    }
}

$paymentHistory = $paymentHelper->getStudentPaymentHistory((int) $student['id'], $current_school_id);
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
        // keep error
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
            $stmt = $pdo->prepare("INSERT INTO student_payments (student_id, school_id, class_id, amount_paid, total_amount, payment_date, academic_year, payment_method, payment_type, fee_type, status, term, transaction_id, notes) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, 'pending', ?, ?, ?)");
            $stmt->execute([$studentId, $current_school_id, (int) $student['class_id'], $amount, $totalFee, $academicYear, $paymentMethod, $paymentType, $feeType, $term, $transactionId, $notes]);
            $paymentId = (int) $pdo->lastInsertId();

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
                $attachStmt = $pdo->prepare("INSERT INTO payment_attachments (payment_id, school_id, file_name, file_path, uploaded_by, file_type, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $attachStmt->execute([$paymentId, $current_school_id, $fileName, $filePath, $studentId, $fileType, 'student']);
            }

            $pdo->commit();
            $successMessage = 'Payment submitted successfully. Awaiting verification.';
            $paymentHistory = $paymentHelper->getStudentPaymentHistory((int) $student['id'], $current_school_id);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = $e->getMessage();
        }
    }
}

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
$displayPaid = (float) ($paymentTotalsByYearTerm[$selectedYear][$selectedTerm][$selectedFeeType] ?? 0);
$displayBalance = max(0, $displayTotalFee - $displayPaid);

$pageTitle = 'Student Payment Portal | ' . (function_exists('get_school_display_name') ? get_school_display_name() : 'iSchool');
$extraHead = <<<'EXTRA'
<link rel="manifest" href="../manifest.json">
<link rel="stylesheet" href="../assets/css/mobile-navigation.css">
<link rel="stylesheet" href="../assets/css/offline-status.css">
<style>
    .student-layout{overflow-x:hidden}
    .student-payment-page section{padding-top:.4rem;padding-bottom:.4rem}
    .dashboard-card{border-radius:1.5rem;border:1px solid rgba(15,31,45,.06);background:#fff;box-shadow:0 10px 24px rgba(15,31,51,.08);padding:1.85rem !important}
    .student-sidebar-overlay{position:fixed;inset:0;background:rgba(2,6,23,.45);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:30}
    .sidebar{position:fixed;top:73px;left:0;width:16rem;height:calc(100vh - 73px);background:#fff;border-right:1px solid rgba(15,31,45,.1);box-shadow:0 18px 40px rgba(15,31,51,.12);transform:translateX(-106%);transition:transform .22s ease;z-index:40;overflow-y:auto}
    body.sidebar-open .sidebar{transform:translateX(0)} body.sidebar-open .student-sidebar-overlay{opacity:1;pointer-events:auto}
    .sidebar-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid rgba(15,31,45,.08)}
    .sidebar-header h3{margin:0;font-size:1rem;font-weight:700;color:#0f1f2d}
    .sidebar-close{border:0;border-radius:.55rem;padding:.35rem .55rem;background:rgba(15,31,45,.08);color:#334155;font-size:.8rem;line-height:1;cursor:pointer}
    .sidebar-nav{padding:.8rem}.nav-list{list-style:none;margin:0;padding:0;display:grid;gap:.2rem}
    .nav-link{display:flex;align-items:center;gap:.65rem;border-radius:.75rem;padding:.62rem .72rem;color:#475569;font-size:.88rem;font-weight:600;text-decoration:none;transition:background-color .15s ease,color .15s ease}
    .nav-link:hover{background:rgba(22,133,117,.1);color:#0f6a5c}.nav-link.active{background:rgba(22,133,117,.14);color:#0f6a5c}.nav-icon{width:1rem;text-align:center}
    #studentMain{min-width:0}
    .payment-metric{border-radius:1rem;border:1px solid rgba(15,31,45,.08);background:#f8fafc;padding:1.2rem}
    .payment-metric-icon{display:inline-flex;height:2.2rem;width:2.2rem;align-items:center;justify-content:center;border-radius:.75rem;background:rgba(15,118,110,.12);color:#0f766e}
    .payment-label{display:block;font-size:.84rem;font-weight:600;color:#334155;margin-bottom:.45rem}
    .payment-input{width:100%;border:1px solid rgba(15,31,45,.14);border-radius:.75rem;padding:.7rem .85rem;background:#fff;color:#0f172a;font-size:.93rem;transition:border-color .15s ease, box-shadow .15s ease}
    .payment-input:focus{outline:none;border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.14)}
    .payment-input[readonly]{background:#f8fafc;color:#334155}
    .payment-input[type="file"]{padding:.55rem .7rem}
    .payment-hint{margin-top:.35rem;font-size:.75rem;color:#64748b}
    .payment-table th{font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:#475569;background:#f8fafc;padding:.92rem .86rem;border-bottom:1px solid rgba(15,31,45,.1)}
    .payment-table td{padding:.9rem .86rem;border-bottom:1px solid rgba(15,31,45,.07);vertical-align:top}
    .payment-table tbody tr:hover{background:rgba(15,118,110,.03)}
    .status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:.25rem .62rem;font-size:.7rem;font-weight:700}
    #submitPaymentButton[disabled]{opacity:.55;cursor:not-allowed}
    @media (min-width:768px){#studentMain{padding-left:16rem !important}.sidebar{transform:translateX(0);top:73px;height:calc(100vh - 73px);padding-top:0}.sidebar-close{display:none}.student-sidebar-overlay{display:none}}
    @media (max-width:767.98px){#studentMain{padding-left:0 !important}}
    @media (max-width:640px){.student-payment-page .dashboard-card{padding:1.25rem !important}.student-payment-page section{padding-top:.3rem;padding-bottom:.3rem}}
</style>
EXTRA;

require __DIR__ . '/../includes/student_header.php';
?>
<div class="student-sidebar-overlay" id="studentSidebarOverlay"></div>

<main class="student-payment-page space-y-6">
    <section class="dashboard-card p-6 sm:p-8" data-reveal>
        <div class="flex flex-col gap-5 xl:grid xl:grid-cols-[1.7fr_1fr_1fr_auto] xl:items-end">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Student Finance Workspace</p>
                <h1 class="mt-2 text-3xl font-display text-ink-900">Payment Center</h1>
                <p class="mt-2 text-sm text-slate-600">Track your fees, submit proof of payment, and monitor verification status for <?php echo htmlspecialchars((string) $student['class_name']); ?>.</p>
            </div>
            <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5">
                <p class="text-xs uppercase tracking-wide text-slate-500">Academic Year</p>
                <p class="text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars((string) $selectedYear); ?></p>
                <p class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars((string) $selectedTerm); ?></p>
            </div>
            <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5">
                <p class="text-xs uppercase tracking-wide text-slate-500">Outstanding Balance</p>
                <p class="text-2xl font-semibold text-ink-900" id="heroBalanceValue"><?php echo $paymentHelper->formatCurrency($displayBalance); ?></p>
                <p class="text-xs text-slate-500 mt-1">Across selected fee scope</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a class="btn btn-outline" href="#paymentHistory"><i class="fas fa-history"></i><span>View History</span></a>
                <button type="button" class="btn btn-primary" id="refreshSummaryBtn"><i class="fas fa-sync-alt"></i><span>Refresh Totals</span></button>
            </div>
        </div>
    </section>

    <?php if ($successMessage !== ''): ?>
        <section class="dashboard-card p-4 border border-emerald-200 bg-emerald-50/70" data-reveal data-reveal-delay="40">
            <div class="flex items-start gap-3 text-emerald-800">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-100"><i class="fas fa-check-circle"></i></span>
                <div><p class="text-sm font-semibold">Payment Submitted</p><p class="text-sm"><?php echo htmlspecialchars($successMessage); ?></p></div>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
        <section class="dashboard-card p-4 border border-rose-200 bg-rose-50/70" data-reveal data-reveal-delay="40">
            <div class="flex items-start gap-3 text-rose-800">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rose-100"><i class="fas fa-exclamation-triangle"></i></span>
                <div><p class="text-sm font-semibold">Unable to Submit Payment</p><p class="text-sm"><?php echo htmlspecialchars($errorMessage); ?></p></div>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($bankAccounts)): ?>
        <section class="dashboard-card p-6" data-reveal data-reveal-delay="70">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-ink-900">Manual Transfer Accounts</h2>
                <p class="text-xs text-slate-500">Use any account below and upload your payment proof.</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <?php foreach (array_slice($bankAccounts, 0, 2) as $account): ?>
                    <article class="rounded-2xl border border-ink-900/10 bg-white px-4 py-4">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="payment-metric-icon"><i class="fas fa-university"></i></span>
                            <div>
                                <p class="text-sm font-semibold text-ink-900"><?php echo htmlspecialchars((string) $account['bank_name']); ?></p>
                                <p class="text-xs text-slate-500">School collection account</p>
                            </div>
                        </div>
                        <p class="text-base font-semibold text-slate-900"><?php echo htmlspecialchars((string) $account['account_number']); ?></p>
                        <p class="text-sm text-slate-600"><?php echo htmlspecialchars((string) $account['account_name']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="grid gap-3 sm:grid-cols-3" data-reveal data-reveal-delay="90">
        <article class="dashboard-card p-5">
            <div class="payment-metric">
                <span class="payment-metric-icon mb-3"><i class="fas fa-money-bill-wave"></i></span>
                <p class="text-xs uppercase tracking-wide text-slate-500">Total Fee</p>
                <p class="text-2xl font-semibold text-ink-900 mt-1" id="totalFeeValue"><?php echo $paymentHelper->formatCurrency($displayTotalFee); ?></p>
            </div>
        </article>
        <article class="dashboard-card p-5">
            <div class="payment-metric">
                <span class="payment-metric-icon mb-3"><i class="fas fa-check-circle"></i></span>
                <p class="text-xs uppercase tracking-wide text-slate-500">Amount Paid</p>
                <p class="text-2xl font-semibold text-ink-900 mt-1" id="paidAmountValue"><?php echo $paymentHelper->formatCurrency($displayPaid); ?></p>
            </div>
        </article>
        <article class="dashboard-card p-5">
            <div class="payment-metric">
                <span class="payment-metric-icon mb-3"><i class="fas fa-balance-scale"></i></span>
                <p class="text-xs uppercase tracking-wide text-slate-500">Balance Due</p>
                <p class="text-2xl font-semibold text-ink-900 mt-1" id="balanceValue"><?php echo $paymentHelper->formatCurrency($displayBalance); ?></p>
            </div>
        </article>
    </section>

    <section class="dashboard-card p-6" data-reveal data-reveal-delay="120">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-xl font-semibold text-ink-900">Submit Payment</h2>
                <p class="text-sm text-slate-600">Select your fee scope, payment type, and upload any supporting proof.</p>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" data-offline-sync="1" class="space-y-5" id="paymentForm">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="payment-label" for="academicYearSelect">Academic Year</label>
                    <select class="payment-input" name="academic_year" id="academicYearSelect">
                        <?php foreach ($academicYears as $year): ?>
                            <option value="<?php echo htmlspecialchars((string) $year); ?>" <?php echo $selectedYear === $year ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $year); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="payment-label" for="termSelect">Term</label>
                    <select class="payment-input" name="term" id="termSelect">
                        <?php foreach ($terms as $term): ?>
                            <option value="<?php echo htmlspecialchars((string) $term); ?>" <?php echo $selectedTerm === $term ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $term); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="payment-label" for="feeSelect">Fee Type</label>
                    <select class="payment-input" name="fee_id" id="feeSelect">
                        <option value="all">All Fees (Total: <?php echo $paymentHelper->formatCurrency($displayTotalFee); ?>)</option>
                        <?php foreach (($feeDataByYearTerm[$selectedYear][$selectedTerm]['breakdown'] ?? []) as $fee): ?>
                            <option value="<?php echo htmlspecialchars((string) $fee['id']); ?>" <?php echo (string) $selectedFeeId === (string) $fee['id'] ? 'selected' : ''; ?>>
                                <?php
                                $labelParts = [(string) ($fee['type_label'] ?? 'Fee')];
                                if (!empty($fee['description'])) {
                                    $labelParts[] = (string) $fee['description'];
                                }
                                echo htmlspecialchars(implode(' - ', $labelParts));
                                ?>
                                (<?php echo $paymentHelper->formatCurrency((float) ($fee['amount'] ?? 0)); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="payment-hint" id="feeMetaText">Select a fee item to load the amount.</p>
                </div>
                <div>
                    <label class="payment-label" for="paymentMethodSelect">Payment Method</label>
                    <select class="payment-input" name="payment_method" id="paymentMethodSelect" required>
                        <option value="bank_transfer" selected>Bank Transfer</option>
                        <option value="cash">Cash</option>
                    </select>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="payment-label" for="paymentTypeSelect">Payment Type</label>
                    <select class="payment-input" name="payment_type" id="paymentTypeSelect" required>
                        <option value="full" selected>Full Payment</option>
                        <option value="installment">Installment Payment</option>
                    </select>
                    <p class="payment-hint hidden" id="installmentHint">Installment amount is auto-calculated for this fee.</p>
                </div>
                <div>
                    <label class="payment-label" for="amountInput">Amount (NGN)</label>
                    <input type="number" class="payment-input" name="amount" id="amountInput" min="0" step="0.01" value="<?php echo htmlspecialchars(number_format($displayBalance, 2, '.', '')); ?>" readonly>
                    <p class="payment-hint" id="balanceHelp">Available balance: <?php echo $paymentHelper->formatCurrency($displayBalance); ?></p>
                </div>
            </div>

            <div>
                <label class="payment-label" for="paymentProofInput">Payment Proof</label>
                <input type="file" class="payment-input" id="paymentProofInput" name="payment_proof" accept=".jpg,.jpeg,.png,.pdf">
                <p class="payment-hint">Upload bank slip or receipt. Maximum file size: 5MB.</p>
            </div>

            <div>
                <label class="payment-label" for="notesInput">Notes</label>
                <textarea class="payment-input" name="notes" id="notesInput" rows="3" placeholder="Optional notes..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary w-full sm:w-auto" id="submitPaymentButton" <?php echo ($displayTotalFee <= 0 || $displayBalance <= 0) ? 'disabled' : ''; ?>>
                <i class="fas fa-paper-plane"></i><span>Submit Payment</span>
            </button>
        </form>
    </section>

    <section id="paymentHistory" class="dashboard-card p-6" data-reveal data-reveal-delay="160">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-semibold text-ink-900">Payment History</h2>
            <p class="text-xs text-slate-500">Track pending, verified, and completed submissions.</p>
        </div>

        <?php if (!empty($paymentHistory)): ?>
            <div class="overflow-x-auto rounded-2xl border border-ink-900/10">
                <table class="min-w-full payment-table text-sm">
                    <thead>
                        <tr>
                            <th>Reference</th><th>Fee Type</th><th>Term</th><th>Year</th><th>Amount Paid</th><th>Total Amount</th><th>Method</th><th>Status</th><th>Date</th><th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentHistory as $payment): ?>
                            <?php
                            $reference = $payment['receipt_number'] ?: ($payment['transaction_id'] ?: 'N/A');
                            $feeTypeKey = $payment['fee_type'] ?: 'all';
                            $feeTypeLabel = $feeTypeLabels[$feeTypeKey] ?? ucwords(str_replace('_', ' ', $feeTypeKey));
                            $paymentDate = $payment['payment_date'] ? date('d/m/Y', strtotime((string) $payment['payment_date'])) : 'N/A';
                            $statusClassMap = ['pending' => 'bg-amber-100 text-amber-700', 'verified' => 'bg-sky-100 text-sky-700', 'partial' => 'bg-indigo-100 text-indigo-700', 'completed' => 'bg-emerald-100 text-emerald-700', 'rejected' => 'bg-rose-100 text-rose-700'];
                            $statusClass = $statusClassMap[$payment['status']] ?? 'bg-slate-100 text-slate-700';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $reference); ?></td>
                                <td><?php echo htmlspecialchars((string) $feeTypeLabel); ?></td>
                                <td><?php echo htmlspecialchars((string) ($payment['term'] ?? 'N/A')); ?></td>
                                <td><?php echo htmlspecialchars((string) ($payment['academic_year'] ?? 'N/A')); ?></td>
                                <td><?php echo $paymentHelper->formatCurrency((float) ($payment['amount_paid'] ?? 0)); ?></td>
                                <td><?php echo $paymentHelper->formatCurrency((float) ($payment['total_amount'] ?? 0)); ?></td>
                                <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($payment['payment_method'] ?? '')))); ?></td>
                                <td><span class="status-pill <?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($payment['status'] ?? 'pending')))); ?></span></td>
                                <td><?php echo htmlspecialchars((string) $paymentDate); ?></td>
                                <td><a class="inline-flex items-center rounded-lg border border-ink-900/15 px-2.5 py-1.5 text-xs font-semibold text-ink-900 hover:border-teal-600/40 hover:bg-teal-600/10" href="payment_details.php?id=<?php echo (int) $payment['id']; ?>"><i class="fas fa-eye mr-1"></i>View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="rounded-xl border border-dashed border-ink-900/15 bg-mist-50 px-4 py-10 text-center">
                <p class="text-sm font-semibold text-ink-900">No Payment History</p>
                <p class="text-sm text-slate-500 mt-1">Your submitted payments will appear here.</p>
            </div>
        <?php endif; ?>
    </section>
</main>
<script>
const feeDataByYearTerm = <?php echo json_encode($feeDataByYearTerm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const paymentTotalsByYearTerm = <?php echo json_encode($paymentTotalsByYearTerm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const currencySymbol = <?php echo json_encode($paymentConfig['currency'] ?? 'N', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const initialTerm = <?php echo json_encode($selectedTerm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const initialFeeId = <?php echo json_encode((string) $selectedFeeId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const initialYear = <?php echo json_encode($selectedYear, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const firstAvailableYearByTerm = <?php echo json_encode($firstAvailableYearByTerm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

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
const heroBalanceValue = document.getElementById('heroBalanceValue');
const submitButton = document.getElementById('submitPaymentButton');
const refreshSummaryBtn = document.getElementById('refreshSummaryBtn');
const paymentForm = document.getElementById('paymentForm');
const paymentProofInput = document.getElementById('paymentProofInput');
const sidebarOverlay = document.getElementById('studentSidebarOverlay');

const formatCurrency = (amount) => {
    const value = Number(amount || 0);
    return `${currencySymbol}${value.toFixed(2).replace(/\\B(?=(\\d{3})+(?!\\d))/g, ',')}`;
};

const getTermData = (year, term) => {
    if (feeDataByYearTerm && feeDataByYearTerm[year] && feeDataByYearTerm[year][term]) {
        return feeDataByYearTerm[year][term];
    }
    return { breakdown: [], total: 0 };
};

const renderFeeOptions = (year, term, selectedFee) => {
    if (!feeSelect) return;
    const termData = getTermData(year, term);
    const breakdown = Array.isArray(termData.breakdown) ? termData.breakdown : [];
    feeSelect.innerHTML = '';
    feeSelect.add(new Option(`All Fees (Total: ${formatCurrency(termData.total || 0)})`, 'all'));
    breakdown.forEach((fee) => {
        const labelParts = [fee.type_label || 'Fee'];
        if (fee.description) labelParts.push(fee.description);
        const opt = new Option(`${labelParts.join(' - ')} (${formatCurrency(fee.amount || 0)})`, String(fee.id));
        opt.dataset.amount = String(fee.amount || 0);
        opt.dataset.feeType = String(fee.fee_type || 'all');
        opt.dataset.allowInstallments = fee.allow_installments ? '1' : '0';
        opt.dataset.maxInstallments = String(fee.max_installments || 1);
        feeSelect.add(opt);
    });
    const exists = Array.from(feeSelect.options).some((option) => option.value === String(selectedFee));
    feeSelect.value = exists ? String(selectedFee) : 'all';
};

const getSelectedFeeMeta = () => {
    const term = termSelect ? termSelect.value : initialTerm;
    const year = academicYearSelect ? academicYearSelect.value : initialYear;
    const termData = getTermData(year, term);
    const selectedId = feeSelect ? feeSelect.value : 'all';
    if (selectedId === 'all') {
        return { term, year, feeType: 'all', total: Number(termData.total || 0), allowInstallments: false, maxInstallments: 1, description: 'All fees for selected term' };
    }
    const selectedOption = feeSelect ? feeSelect.options[feeSelect.selectedIndex] : null;
    return {
        term,
        year,
        feeType: selectedOption ? (selectedOption.dataset.feeType || 'all') : 'all',
        total: selectedOption ? Number(selectedOption.dataset.amount || 0) : 0,
        allowInstallments: selectedOption ? selectedOption.dataset.allowInstallments === '1' : false,
        maxInstallments: selectedOption ? Number(selectedOption.dataset.maxInstallments || 1) : 1,
        description: selectedOption ? selectedOption.text : '',
    };
};

const updateSummaryCards = () => {
    const meta = getSelectedFeeMeta();
    const paid = paymentTotalsByYearTerm?.[meta.year]?.[meta.term]?.[meta.feeType] ? Number(paymentTotalsByYearTerm[meta.year][meta.term][meta.feeType]) : 0;
    const balance = Math.max(0, meta.total - paid);
    if (totalFeeValue) totalFeeValue.textContent = formatCurrency(meta.total);
    if (paidAmountValue) paidAmountValue.textContent = formatCurrency(paid);
    if (balanceValue) balanceValue.textContent = formatCurrency(balance);
    if (heroBalanceValue) heroBalanceValue.textContent = formatCurrency(balance);

    if (paymentTypeSelect) {
        const installmentOption = paymentTypeSelect.querySelector('option[value=\"installment\"]');
        const installmentEnabled = meta.allowInstallments && meta.maxInstallments > 1;
        if (installmentOption) installmentOption.disabled = !installmentEnabled;
        if (!installmentEnabled && paymentTypeSelect.value === 'installment') paymentTypeSelect.value = 'full';
    }

    const paymentType = paymentTypeSelect ? paymentTypeSelect.value : 'full';
    const showInstallmentHint = paymentType === 'installment' && meta.allowInstallments && meta.maxInstallments > 1;
    let payableAmount = balance;
    if (showInstallmentHint) payableAmount = Math.min(balance, Number((meta.total / meta.maxInstallments).toFixed(2)));

    if (installmentHint) installmentHint.classList.toggle('hidden', !showInstallmentHint);
    if (amountInput) amountInput.value = payableAmount.toFixed(2);
    if (balanceHelp) balanceHelp.textContent = `Available balance: ${formatCurrency(balance)}`;
    if (feeMetaText) feeMetaText.textContent = meta.total > 0 ? (meta.description || 'Select a fee item to load the amount.') : 'No fee structure found for the selected year and term.';
    if (submitButton) submitButton.disabled = meta.total <= 0 || balance <= 0;
};

if (termSelect && feeSelect) {
    renderFeeOptions(initialYear, initialTerm, initialFeeId);
    updateSummaryCards();
    termSelect.addEventListener('change', () => {
        let year = academicYearSelect ? academicYearSelect.value : initialYear;
        const term = termSelect.value;
        const hasTotal = Number(getTermData(year, term).total || 0) > 0;
        if (!hasTotal && firstAvailableYearByTerm && firstAvailableYearByTerm[term]) {
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
if (paymentTypeSelect) paymentTypeSelect.addEventListener('change', updateSummaryCards);
if (refreshSummaryBtn) refreshSummaryBtn.addEventListener('click', updateSummaryCards);
if (sidebarOverlay) sidebarOverlay.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
if (window.matchMedia('(min-width: 768px)').matches) document.body.classList.remove('sidebar-open');

if (paymentForm && submitButton) {
    paymentForm.addEventListener('submit', () => {
        if (submitButton.disabled) return;
        submitButton.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i><span>Submitting...</span>';
        submitButton.disabled = true;
    });
}
if (paymentProofInput) {
    paymentProofInput.addEventListener('change', (event) => {
        const file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
        if (!file) return;
        if ((file.size / 1024 / 1024) > 5) {
            alert('File size must be less than 5MB.');
            event.target.value = '';
        }
    });
}
</script>
<script src="../assets/js/offline-core.js" defer></script>
<?php include __DIR__ . '/../includes/floating-button.php'; ?>
<?php require __DIR__ . '/../includes/student_footer.php'; ?>
