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

$message = '';
$error = '';
$bankMessage = '';
$bankError = '';
$clerk_name = $_SESSION['full_name'] ?? 'Clerk';

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $fee_type = trim($_POST['fee_type'] ?? '');
    $class_id = trim($_POST['class_id'] ?? '');
    $term = trim($_POST['term'] ?? '');
    $academic_year = trim($_POST['academic_year'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    $due_date = $_POST['due_date'] ?: null;
    $allow_installments = isset($_POST['allow_installments']) ? 1 : 0;
    $max_installments = (int) ($_POST['max_installments'] ?? 1);
    $late_fee_percentage = (float) ($_POST['late_fee_percentage'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE fee_structure
                                  SET fee_type = ?, class_id = ?, term = ?, academic_year = ?, description = ?, amount = ?,
                                      due_date = ?, allow_installments = ?, max_installments = ?, late_fee_percentage = ?, is_active = ?
                                  WHERE id = ? AND school_id = ?");
            $stmt->execute([$fee_type, $class_id, $term, $academic_year, $description, $amount, $due_date, $allow_installments, $max_installments, $late_fee_percentage, $is_active, $id, $current_school_id]);
            $message = 'Fee updated successfully.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO fee_structure
                                  (school_id, fee_type, class_id, term, academic_year, description, amount, due_date,
                                   allow_installments, max_installments, late_fee_percentage, is_active)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$current_school_id, $fee_type, $class_id, $term, $academic_year, $description, $amount, $due_date, $allow_installments, $max_installments, $late_fee_percentage, $is_active]);
            $message = 'Fee created successfully.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM fee_structure WHERE id = ? AND school_id = ?");
        $stmt->execute([$id, $current_school_id]);
        $message = 'Fee deleted successfully.';
    }
}

// Handle bank account create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bank_save') {
    $bankId = isset($_POST['bank_id']) ? (int) $_POST['bank_id'] : 0;
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $is_primary = isset($_POST['is_primary']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($bank_name === '' || $account_name === '' || $account_number === '') {
        $bankError = 'Bank name, account name, and account number are required.';
    } else {
        try {
            if ($is_primary) {
                $pdo->prepare("UPDATE school_bank_accounts SET is_primary = 0 WHERE school_id = ?")->execute([$current_school_id]);
            }

            if ($bankId > 0) {
                $stmt = $pdo->prepare("UPDATE school_bank_accounts
                                      SET bank_name = ?, account_name = ?, account_number = ?, is_primary = ?, is_active = ?
                                      WHERE id = ? AND school_id = ?");
                $stmt->execute([$bank_name, $account_name, $account_number, $is_primary, $is_active, $bankId, $current_school_id]);
                $bankMessage = 'Bank account updated successfully.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO school_bank_accounts
                                      (school_id, bank_name, account_name, account_number, is_primary, is_active)
                                      VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$current_school_id, $bank_name, $account_name, $account_number, $is_primary, $is_active]);
                $bankMessage = 'Bank account added successfully.';
            }
        } catch (Exception $e) {
            $bankError = $e->getMessage();
        }
    }
}

// Handle bank account delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bank_delete') {
    $bankId = (int) ($_POST['bank_id'] ?? 0);
    if ($bankId) {
        $stmt = $pdo->prepare("DELETE FROM school_bank_accounts WHERE id = ? AND school_id = ?");
        $stmt->execute([$bankId, $current_school_id]);
        $bankMessage = 'Bank account deleted successfully.';
    }
}

// Fetch record for edit
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editFee = null;
if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM fee_structure WHERE id = ? AND school_id = ?");
    $stmt->execute([$editId, $current_school_id]);
    $editFee = $stmt->fetch();
}

// Fetch bank account for edit
$editBankId = isset($_GET['edit_bank']) ? (int) $_GET['edit_bank'] : 0;
$editBank = null;
if ($editBankId) {
    $stmt = $pdo->prepare("SELECT * FROM school_bank_accounts WHERE id = ? AND school_id = ?");
    $stmt->execute([$editBankId, $current_school_id]);
    $editBank = $stmt->fetch();
}

$classesStmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
$classesStmt->execute([$current_school_id]);
$classes = $classesStmt->fetchAll();

$feesStmt = $pdo->prepare("SELECT fs.*, c.class_name
                          FROM fee_structure fs
                          JOIN classes c ON fs.class_id = c.id
                          WHERE fs.school_id = ?
                          ORDER BY fs.academic_year DESC, fs.term DESC, c.class_name ASC");
$feesStmt->execute([$current_school_id]);
$fees = $feesStmt->fetchAll();

$feeTypeOptions = include('../config/payment_config.php');
$feeTypeOptions = $feeTypeOptions['fee_types'] ?? [];

$bankStmt = $pdo->prepare("SELECT * FROM school_bank_accounts WHERE school_id = ? ORDER BY is_primary DESC, bank_name ASC");
$bankStmt->execute([$current_school_id]);
$bankAccounts = $bankStmt->fetchAll();

$paymentHelper = new PaymentHelper();
$termOptions = ['1st Term', '2nd Term', '3rd Term'];
$feeItemCount = count($fees);
$bankAccountCount = count($bankAccounts);
$activeFeeCount = 0;
$installmentFeeCount = 0;
$totalConfiguredAmount = 0.0;
$activeBankCount = 0;
$primaryBankName = '';
$feeResetUrl = 'fee_structure.php' . ($editBank ? '?edit_bank=' . urlencode((string) $editBank['id']) : '');
$bankResetUrl = 'fee_structure.php' . ($editFee ? '?edit=' . urlencode((string) $editFee['id']) : '');

foreach ($fees as $fee) {
    $totalConfiguredAmount += (float) ($fee['amount'] ?? 0);
    if (!empty($fee['is_active'])) {
        $activeFeeCount++;
    }
    if (!empty($fee['allow_installments'])) {
        $installmentFeeCount++;
    }
}

foreach ($bankAccounts as $account) {
    if (!empty($account['is_active'])) {
        $activeBankCount++;
    }
    if ($primaryBankName === '' && !empty($account['is_primary'])) {
        $primaryBankName = (string) ($account['bank_name'] ?? '');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <title>Fee Structure | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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

        textarea.control-field {
            min-height: 7rem;
        }

        .control-field:focus {
            outline: none;
            border-color: rgba(13, 148, 136, 0.7);
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.15);
        }

        .toggle-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .toggle-option {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            border-radius: 1rem;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: #f8fafc;
            padding: 0.75rem 0.95rem;
            font-size: 0.88rem;
            font-weight: 600;
            color: #0f172a;
        }

        .toggle-option input {
            width: 1rem;
            height: 1rem;
            accent-color: #168575;
        }

        .field-span-2 {
            grid-column: span 2;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.25rem 0.62rem;
            font-size: 0.72rem;
            font-weight: 700;
            white-space: nowrap;
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

        .action-btn-edit {
            background: #e0f2fe;
            border-color: #bae6fd;
            color: #075985;
        }

        .action-btn-delete {
            background: #fee2e2;
            border-color: #fecaca;
            color: #b91c1c;
        }

        .record-table-wrap {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            overflow: auto;
            -webkit-overflow-scrolling: touch;
        }

        .payment-table-wrap {
            overflow-x: auto;
            overflow-y: hidden;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 1rem;
            background: #ffffff;
        }

        .record-table {
            width: 100%;
            min-width: 860px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .record-table thead th {
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

        .record-table tbody td {
            padding: 0.9rem 0.7rem;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            font-size: 0.88rem;
            color: #0f172a;
            vertical-align: top;
            word-break: break-word;
        }

        .record-table tbody tr:hover {
            background: rgba(226, 232, 240, 0.35);
        }

        .detail-stack {
            display: grid;
            gap: 0.22rem;
        }

        .table-subtext {
            font-size: 0.76rem;
            color: #64748b;
        }

        .empty-state {
            text-align: center;
            color: #64748b;
            padding: 1.2rem 0;
        }

        @media (max-width: 1023px) {
            .workspace-header-actions {
                flex-wrap: wrap;
                justify-content: flex-start;
            }
        }

        @media (max-width: 860px) {
            .field-span-2 {
                grid-column: span 1;
            }

            .record-table {
                min-width: 100%;
            }

            .record-table thead {
                display: none;
            }

            .record-table tbody,
            .record-table tbody tr,
            .record-table tbody td {
                display: block;
                width: 100%;
            }

            .record-table tbody tr {
                padding: 1rem 0;
            }

            .record-table tbody td {
                display: flex;
                justify-content: space-between;
                gap: 1rem;
                padding: 0.55rem 0;
                border-bottom: none;
            }

            .record-table tbody td::before {
                content: attr(data-label);
                flex: 0 0 42%;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                color: #64748b;
            }

            .record-table tbody td[data-label="Actions"] {
                display: block;
                margin-top: 0.4rem;
                padding-top: 0.9rem;
                border-top: 1px solid rgba(15, 23, 42, 0.08);
            }

            .record-table tbody td[data-label="Actions"]::before,
            .record-table tbody td[colspan]::before {
                display: none;
            }

            .record-table tbody td[colspan] {
                display: block;
                text-align: center;
                padding: 0.9rem 0;
            }

            .payment-table-wrap {
                border-radius: 0.9rem;
            }

            .payment-table-wrap .record-table {
                min-width: 720px;
            }

            .payment-table-wrap .record-table thead {
                display: table-header-group;
            }

            .payment-table-wrap .record-table tbody {
                display: table-row-group;
            }

            .payment-table-wrap .record-table tbody tr {
                display: table-row;
                padding: 0;
            }

            .payment-table-wrap .record-table thead th,
            .payment-table-wrap .record-table tbody td {
                display: table-cell;
                width: auto;
            }

            .payment-table-wrap .record-table tbody td {
                padding: 0.9rem 0.7rem;
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            }

            .payment-table-wrap .record-table tbody td::before {
                content: none;
            }

            .payment-table-wrap .record-table tbody td[data-label="Actions"] {
                display: table-cell;
                margin-top: 0;
                padding-top: 0.9rem;
                border-top: none;
            }

            .payment-table-wrap .record-table tbody td[colspan] {
                display: table-cell;
                padding: 1rem 0.7rem;
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
                <a class="btn btn-outline" href="payments.php">Payments</a>
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
                    <a href="payments.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700">
                        <i class="fas fa-money-check-alt"></i>
                        <span>Payments</span>
                    </a>
                    <a href="fee_structure.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold bg-teal-600/10 text-teal-700">
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
                        <h1 class="text-3xl font-display text-ink-900">Fee Structure Workspace</h1>
                        <p class="text-slate-600">Configure billable items, installment rules, and receiving accounts with the same payment workspace structure used across the clerk portal.</p>
                    </div>
                    <div class="grid w-full gap-3 sm:grid-cols-2 xl:w-auto xl:grid-cols-4">
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Configured Fees</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($feeItemCount); ?></p>
                            <p class="mt-1 text-xs text-slate-500"><?php echo number_format($activeFeeCount); ?> active</p>
                        </div>
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Installment Ready</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($installmentFeeCount); ?></p>
                            <p class="mt-1 text-xs text-slate-500">Fees with staged payments enabled</p>
                        </div>
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Configured Value</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars($paymentHelper->formatCurrency($totalConfiguredAmount)); ?></p>
                            <p class="mt-1 text-xs text-slate-500">Total value across visible fee items</p>
                        </div>
                        <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5 min-w-0">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Active Bank Accounts</p>
                            <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($activeBankCount); ?></p>
                            <p class="mt-1 text-xs text-slate-500"><?php echo htmlspecialchars($primaryBankName !== '' ? 'Primary: ' . $primaryBankName : 'No primary account set'); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($message || $error || $bankMessage || $bankError): ?>
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
                    <?php if ($bankMessage): ?>
                        <div class="rounded-xl border border-teal-600/20 bg-teal-600/10 px-4 py-3 text-sm text-teal-800">
                            <i class="fas fa-building-columns mr-2"></i><?php echo htmlspecialchars($bankMessage); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($bankError): ?>
                        <div class="rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-700">
                            <i class="fas fa-circle-exclamation mr-2"></i><?php echo htmlspecialchars($bankError); ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="grid gap-6 xl:grid-cols-[1.4fr_1fr]">
                <article class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-2xl font-display text-ink-900"><?php echo $editFee ? 'Edit Fee Item' : 'Create Fee Item'; ?></h2>
                            <p class="text-sm text-slate-600">Define charge type, timing, amount, and installment behavior for a class.</p>
                        </div>
                        <?php if ($editFee): ?>
                            <a class="btn btn-outline" href="<?php echo htmlspecialchars($feeResetUrl); ?>"><i class="fas fa-rotate-left"></i><span>Reset</span></a>
                        <?php endif; ?>
                    </div>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="save">
                        <?php if ($editFee): ?>
                            <input type="hidden" name="id" value="<?php echo (int) $editFee['id']; ?>">
                        <?php endif; ?>

                        <div class="form-grid">
                            <div>
                                <label class="control-label" for="feeTypeSelect">Fee Type</label>
                                <select class="control-field" name="fee_type" id="feeTypeSelect" required>
                                    <option value="" <?php echo !$editFee ? 'selected' : ''; ?> disabled>Select fee type</option>
                                    <?php foreach ($feeTypeOptions as $key => $label): ?>
                                        <option value="<?php echo htmlspecialchars((string) $key); ?>" <?php echo $editFee && $editFee['fee_type'] === $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) $label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="control-label" for="classSelect">Class</label>
                                <select class="control-field" name="class_id" id="classSelect" required>
                                    <option value="" <?php echo !$editFee ? 'selected' : ''; ?> disabled>Select class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo (int) $class['id']; ?>" <?php echo $editFee && (string) $editFee['class_id'] === (string) $class['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) $class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="control-label" for="termSelect">Term</label>
                                <select class="control-field" name="term" id="termSelect" required>
                                    <option value="" <?php echo !$editFee ? 'selected' : ''; ?> disabled>Select term</option>
                                    <?php foreach ($termOptions as $term): ?>
                                        <option value="<?php echo htmlspecialchars($term); ?>" <?php echo $editFee && $editFee['term'] === $term ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($term); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="control-label" for="academicYearInput">Academic Year</label>
                                <input class="control-field" type="text" name="academic_year" id="academicYearInput" value="<?php echo htmlspecialchars((string) ($editFee['academic_year'] ?? '')); ?>" placeholder="2025/2026" required>
                            </div>
                            <div class="field-span-2">
                                <label class="control-label" for="descriptionInput">Description</label>
                                <input class="control-field" type="text" name="description" id="descriptionInput" value="<?php echo htmlspecialchars((string) ($editFee['description'] ?? '')); ?>" placeholder="Optional note shown internally for this fee item">
                            </div>
                            <div>
                                <label class="control-label" for="amountInput">Amount</label>
                                <input class="control-field" type="number" name="amount" id="amountInput" step="0.01" min="0" value="<?php echo htmlspecialchars((string) ($editFee['amount'] ?? '')); ?>" required>
                            </div>
                            <div>
                                <label class="control-label" for="dueDateInput">Due Date</label>
                                <input class="control-field" type="date" name="due_date" id="dueDateInput" value="<?php echo htmlspecialchars((string) ($editFee['due_date'] ?? '')); ?>">
                            </div>
                            <div>
                                <label class="control-label" for="lateFeeInput">Late Fee %</label>
                                <input class="control-field" type="number" name="late_fee_percentage" id="lateFeeInput" step="0.01" min="0" value="<?php echo htmlspecialchars((string) ($editFee['late_fee_percentage'] ?? '0')); ?>">
                            </div>
                            <div>
                                <label class="control-label" for="maxInstallmentsInput">Max Installments</label>
                                <input class="control-field" type="number" name="max_installments" id="maxInstallmentsInput" min="1" value="<?php echo htmlspecialchars((string) ($editFee['max_installments'] ?? '1')); ?>">
                            </div>
                        </div>

                        <div>
                            <span class="control-label">Controls</span>
                            <div class="toggle-group">
                                <label class="toggle-option">
                                    <input type="checkbox" name="allow_installments" <?php echo !empty($editFee['allow_installments']) ? 'checked' : ''; ?>>
                                    <span>Allow installments</span>
                                </label>
                                <label class="toggle-option">
                                    <input type="checkbox" name="is_active" <?php echo !empty($editFee['is_active']) || !$editFee ? 'checked' : ''; ?>>
                                    <span>Fee is active</span>
                                </label>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-layer-group"></i>
                                <span><?php echo $editFee ? 'Update Fee Item' : 'Create Fee Item'; ?></span>
                            </button>
                            <?php if ($editFee): ?>
                                <a class="btn btn-outline" href="<?php echo htmlspecialchars($feeResetUrl); ?>">Cancel edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </article>

                <article class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-2xl font-display text-ink-900"><?php echo $editBank ? 'Edit Bank Account' : 'Bank Account Setup'; ?></h2>
                            <p class="text-sm text-slate-600">Keep the collection account list current so payment instructions stay accurate.</p>
                        </div>
                        <?php if ($editBank): ?>
                            <a class="btn btn-outline" href="<?php echo htmlspecialchars($bankResetUrl); ?>"><i class="fas fa-rotate-left"></i><span>Reset</span></a>
                        <?php endif; ?>
                    </div>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="bank_save">
                        <?php if ($editBank): ?>
                            <input type="hidden" name="bank_id" value="<?php echo (int) $editBank['id']; ?>">
                        <?php endif; ?>

                        <div class="form-grid">
                            <div>
                                <label class="control-label" for="bankNameInput">Bank Name</label>
                                <input class="control-field" type="text" name="bank_name" id="bankNameInput" value="<?php echo htmlspecialchars((string) ($editBank['bank_name'] ?? '')); ?>" placeholder="Example: Zenith Bank" required>
                            </div>
                            <div>
                                <label class="control-label" for="accountNameInput">Account Name</label>
                                <input class="control-field" type="text" name="account_name" id="accountNameInput" value="<?php echo htmlspecialchars((string) ($editBank['account_name'] ?? '')); ?>" placeholder="Official receiving account name" required>
                            </div>
                            <div class="field-span-2">
                                <label class="control-label" for="accountNumberInput">Account Number</label>
                                <input class="control-field" type="text" name="account_number" id="accountNumberInput" value="<?php echo htmlspecialchars((string) ($editBank['account_number'] ?? '')); ?>" placeholder="10-digit account number" required>
                            </div>
                        </div>

                        <div>
                            <span class="control-label">Account Flags</span>
                            <div class="toggle-group">
                                <label class="toggle-option">
                                    <input type="checkbox" name="is_primary" <?php echo !empty($editBank['is_primary']) ? 'checked' : ''; ?>>
                                    <span>Set as primary account</span>
                                </label>
                                <label class="toggle-option">
                                    <input type="checkbox" name="is_active" <?php echo !empty($editBank['is_active']) || !$editBank ? 'checked' : ''; ?>>
                                    <span>Account is active</span>
                                </label>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-ink-900/5 bg-mist-50 p-4">
                            <p class="text-xs uppercase tracking-wide text-slate-500">Account Coverage</p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($bankAccountCount); ?></p>
                                    <p class="text-sm text-slate-600">Total bank accounts stored</p>
                                </div>
                                <div>
                                    <p class="text-2xl font-semibold text-ink-900"><?php echo number_format($activeBankCount); ?></p>
                                    <p class="text-sm text-slate-600">Currently available for collections</p>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-building-columns"></i>
                                <span><?php echo $editBank ? 'Update Bank Account' : 'Save Bank Account'; ?></span>
                            </button>
                            <?php if ($editBank): ?>
                                <a class="btn btn-outline" href="<?php echo htmlspecialchars($bankResetUrl); ?>">Cancel edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </article>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-ink-900">School Bank Accounts</h2>
                        <p class="text-sm text-slate-600">Review the payout and verification accounts available to parents and students.</p>
                    </div>
                    <span class="text-xs uppercase tracking-wide text-slate-500"><?php echo number_format($bankAccountCount); ?> saved accounts</span>
                </div>
                <div class="record-table-wrap payment-table-wrap">
                    <table class="record-table">
                        <thead>
                            <tr>
                                <th>Bank</th>
                                <th>Account Holder</th>
                                <th>Account Number</th>
                                <th>Account Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bankAccounts)): ?>
                                <tr>
                                    <td colspan="5" class="empty-state">No bank accounts added yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bankAccounts as $account): ?>
                                    <tr>
                                        <td data-label="Bank">
                                            <div class="detail-stack">
                                                <span class="font-semibold text-ink-900"><?php echo htmlspecialchars((string) $account['bank_name']); ?></span>
                                                <span class="table-subtext"><?php echo !empty($account['is_primary']) ? 'Primary collection account' : 'Secondary account'; ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Account Holder"><?php echo htmlspecialchars((string) $account['account_name']); ?></td>
                                        <td data-label="Account Number"><span class="font-semibold text-ink-900"><?php echo htmlspecialchars((string) $account['account_number']); ?></span></td>
                                        <td data-label="Account Status">
                                            <div class="action-cluster">
                                                <span class="status-pill <?php echo !empty($account['is_primary']) ? 'bg-sky-500/10 text-sky-700' : 'bg-slate-500/10 text-slate-700'; ?>">
                                                    <?php echo !empty($account['is_primary']) ? 'Primary' : 'Secondary'; ?>
                                                </span>
                                                <span class="status-pill <?php echo !empty($account['is_active']) ? 'bg-teal-600/10 text-teal-700' : 'bg-red-500/10 text-red-700'; ?>">
                                                    <?php echo !empty($account['is_active']) ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="action-cluster">
                                                <a class="action-btn action-btn-edit" href="fee_structure.php?edit_bank=<?php echo (int) $account['id']; ?><?php echo $editFee ? '&amp;edit=' . urlencode((string) $editFee['id']) : ''; ?>">
                                                    <i class="fas fa-pen"></i>
                                                    <span>Edit</span>
                                                </a>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="bank_delete">
                                                    <input type="hidden" name="bank_id" value="<?php echo (int) $account['id']; ?>">
                                                    <button class="action-btn action-btn-delete" type="submit" onclick="return confirm('Delete this bank account?')">
                                                        <i class="fas fa-trash"></i>
                                                        <span>Delete</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-ink-900">Fee Items</h2>
                        <p class="text-sm text-slate-600">Review configured charges by class, session timing, amount rules, and activation status.</p>
                    </div>
                    <span class="text-xs uppercase tracking-wide text-slate-500"><?php echo number_format($feeItemCount); ?> visible items</span>
                </div>
                <div class="record-table-wrap payment-table-wrap">
                    <table class="record-table">
                        <thead>
                            <tr>
                                <th>Fee Item</th>
                                <th>Class</th>
                                <th>Schedule</th>
                                <th>Amount Rules</th>
                                <th>Installments</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fees)): ?>
                                <tr>
                                    <td colspan="6" class="empty-state">No fee items found yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($fees as $fee): ?>
                                    <tr>
                                        <td data-label="Fee Item">
                                            <div class="detail-stack">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="font-semibold text-ink-900"><?php echo htmlspecialchars((string) ($feeTypeOptions[$fee['fee_type']] ?? $fee['fee_type'])); ?></span>
                                                    <span class="status-pill <?php echo !empty($fee['is_active']) ? 'bg-teal-600/10 text-teal-700' : 'bg-red-500/10 text-red-700'; ?>">
                                                        <?php echo !empty($fee['is_active']) ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </div>
                                                <span class="table-subtext"><?php echo htmlspecialchars((string) (($fee['description'] ?? '') !== '' ? $fee['description'] : 'No internal description')); ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Class"><span class="font-semibold text-ink-900"><?php echo htmlspecialchars((string) $fee['class_name']); ?></span></td>
                                        <td data-label="Schedule">
                                            <div class="detail-stack">
                                                <span><?php echo htmlspecialchars((string) $fee['term']); ?></span>
                                                <span class="table-subtext"><?php echo htmlspecialchars((string) $fee['academic_year']); ?></span>
                                                <span class="table-subtext"><?php echo !empty($fee['due_date']) ? 'Due: ' . htmlspecialchars(date('M j, Y', strtotime((string) $fee['due_date']))) : 'No due date'; ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Amount Rules">
                                            <div class="detail-stack">
                                                <span class="font-semibold text-ink-900"><?php echo htmlspecialchars($paymentHelper->formatCurrency((float) ($fee['amount'] ?? 0))); ?></span>
                                                <span class="table-subtext">Late fee: <?php echo htmlspecialchars(number_format((float) ($fee['late_fee_percentage'] ?? 0), 2)); ?>%</span>
                                            </div>
                                        </td>
                                        <td data-label="Installments">
                                            <div class="detail-stack">
                                                <span class="status-pill <?php echo !empty($fee['allow_installments']) ? 'bg-indigo-500/10 text-indigo-700' : 'bg-slate-500/10 text-slate-700'; ?>">
                                                    <?php echo !empty($fee['allow_installments']) ? 'Allowed' : 'Disabled'; ?>
                                                </span>
                                                <span class="table-subtext">Max: <?php echo number_format((int) ($fee['max_installments'] ?? 1)); ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="action-cluster">
                                                <a class="action-btn action-btn-edit" href="fee_structure.php?edit=<?php echo (int) $fee['id']; ?><?php echo $editBank ? '&amp;edit_bank=' . urlencode((string) $editBank['id']) : ''; ?>">
                                                    <i class="fas fa-pen"></i>
                                                    <span>Edit</span>
                                                </a>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int) $fee['id']; ?>">
                                                    <button class="action-btn action-btn-delete" type="submit" onclick="return confirm('Delete this fee item?')">
                                                        <i class="fas fa-trash"></i>
                                                        <span>Delete</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
<?php include '../includes/floating-button.php'; ?>

<script>
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

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });
</script>
</body>
</html>
