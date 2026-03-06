<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../helpers/payment_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'clerk') {
    header("Location: ../index.php");
    exit;
}

$current_school_id = require_school_auth();
PaymentHelper::ensureSchema();

$userId = $_SESSION['user_id'];
$userName = $_SESSION['full_name'] ?? 'Clerk';
$paymentHelper = new PaymentHelper();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: payments.php");
    exit;
}

$paymentId = (int) $_GET['id'];

function fetch_payment($pdo, $paymentId, $schoolId)
{
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
    $stmt->execute([$paymentId, $schoolId]);
    return $stmt->fetch();
}

$payment = fetch_payment($pdo, $paymentId, $current_school_id);
if (!$payment) {
    header("Location: payments.php?error=payment_not_found");
    exit;
}

$attachmentsStmt = $pdo->prepare("SELECT * FROM payment_attachments WHERE payment_id = ? ORDER BY uploaded_at DESC");
$attachmentsStmt->execute([$paymentId]);
$attachments = $attachmentsStmt->fetchAll();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    try {
        $pdo->beginTransaction();

        if ($action === 'verify') {
            $status = 'verified';
            if ($payment['payment_type'] === 'installment' && (float) $payment['amount_paid'] < (float) $payment['total_amount']) {
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
        } elseif ($action === 'upload_attachment') {
            if (!isset($_FILES['attachment_file']) || $_FILES['attachment_file']['error'] !== 0) {
                throw new Exception('File upload failed.');
            }

            $allowed = ['image/jpeg', 'image/png', 'application/pdf'];
            $fileType = $_FILES['attachment_file']['type'] ?? '';
            $fileSize = $_FILES['attachment_file']['size'] ?? 0;
            if (!in_array($fileType, $allowed, true)) {
                throw new Exception('Invalid file type.');
            }
            if ($fileSize > 5 * 1024 * 1024) {
                throw new Exception('File size exceeds 5MB.');
            }

            $uploadDir = '../uploads/payment_proofs/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['attachment_file']['name']));
            $fileName = time() . '_clerk_' . $safeName;
            $filePath = $uploadDir . $fileName;

            if (!move_uploaded_file($_FILES['attachment_file']['tmp_name'], $filePath)) {
                throw new Exception('Failed to move uploaded file.');
            }

            $stmt = $pdo->prepare("INSERT INTO payment_attachments
                                  (payment_id, school_id, file_name, file_path, uploaded_by, file_type, role)
                                  VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$paymentId, $current_school_id, $fileName, $filePath, $userId, $fileType, 'clerk']);
            $message = 'Attachment uploaded.';
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }

    $payment = fetch_payment($pdo, $paymentId, $current_school_id);
    $attachmentsStmt = $pdo->prepare("SELECT * FROM payment_attachments WHERE payment_id = ? ORDER BY uploaded_at DESC");
    $attachmentsStmt->execute([$paymentId]);
    $attachments = $attachmentsStmt->fetchAll();
}

$statusClasses = [
    'pending' => 'bg-amber-500/10 text-amber-700',
    'verified' => 'bg-sky-500/10 text-sky-700',
    'partial' => 'bg-indigo-500/10 text-indigo-700',
    'completed' => 'bg-teal-600/10 text-teal-700',
    'rejected' => 'bg-red-500/10 text-red-700',
    'other' => 'bg-slate-500/10 text-slate-700',
];

$statusRaw = strtolower(trim((string) ($payment['status'] ?? '')));
$statusKey = array_key_exists($statusRaw, $statusClasses) ? $statusRaw : 'other';
$statusLabel = $statusKey === 'other' ? (trim((string) ($payment['status'] ?? '')) ?: 'Other') : ucfirst($statusKey);

$amountPaid = (float) ($payment['amount_paid'] ?? 0);
$totalAmount = (float) ($payment['total_amount'] ?? 0);
$outstanding = max(0, $totalAmount - $amountPaid);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <meta name="pwa-sw" content="../sw.js">
    <title>View Payment | <?php echo htmlspecialchars(get_school_display_name()); ?></title>
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="../assets/css/offline-status.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;600;700&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .clerk-dashboard {
            overflow-x: hidden;
        }

        body.nav-open {
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

        .metric-grid,
        .detail-grid,
        .attachment-grid {
            display: grid;
            gap: 0.85rem;
        }

        .metric-grid {
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        }

        .detail-grid {
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }

        .attachment-grid {
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }

        .detail-card,
        .attachment-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 0.95rem;
            padding: 0.85rem 0.9rem;
            background: #f8fafc;
        }

        .detail-label {
            display: block;
            margin-bottom: 0.28rem;
            font-size: 0.7rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 700;
        }

        .detail-value {
            margin: 0;
            color: #0f172a;
            font-size: 0.95rem;
            font-weight: 700;
            line-height: 1.35;
            word-break: break-word;
        }

        .detail-sub,
        .attachment-meta {
            margin: 0.22rem 0 0;
            color: #475569;
            font-size: 0.8rem;
            line-height: 1.4;
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
            background: #fff;
            padding: 0.62rem 0.78rem;
            font-size: 0.92rem;
            color: #0f172a;
        }

        .control-field:focus {
            outline: none;
            border-color: rgba(13, 148, 136, 0.7);
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.15);
        }

        .control-textarea {
            min-height: 6.2rem;
            resize: vertical;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }

        .action-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.55rem;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            border-radius: 0.72rem;
            border: 1px solid transparent;
            padding: 0.56rem 0.86rem;
            font-size: 0.78rem;
            font-weight: 700;
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

        .action-btn-receipt {
            background: #f0fdfa;
            border-color: #99f6e4;
            color: #115e59;
        }

        .empty-state {
            border: 1px dashed rgba(15, 23, 42, 0.16);
            border-radius: 0.95rem;
            background: #f8fafc;
            padding: 1rem;
            color: #475569;
            font-size: 0.9rem;
        }

        @media (max-width: 900px) {
            .detail-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .clerk-dashboard main {
                padding-top: 0;
                padding-bottom: 0;
                gap: 1rem;
            }

            .clerk-dashboard section {
                border-radius: 1rem;
                padding: 1rem;
            }

            .clerk-dashboard h1.text-3xl {
                font-size: 1.5rem;
                line-height: 1.25;
            }

            .clerk-dashboard .workspace-header-actions {
                margin-left: auto;
                gap: 0.45rem;
            }

            .clerk-dashboard .workspace-header-actions .btn {
                padding: 0.45rem 0.68rem;
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

            .clerk-dashboard [data-sidebar] {
                width: min(18rem, 86vw);
            }

            .metric-grid,
            .detail-grid,
            .attachment-grid {
                grid-template-columns: 1fr;
            }

            .hero-actions {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr;
            }

            .hero-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .control-field {
                font-size: 16px;
            }

            .action-row {
                flex-direction: column;
                align-items: stretch;
            }

            .action-btn {
                width: 100%;
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
                <span class="hidden text-sm text-slate-600 md:block">Welcome, <?php echo htmlspecialchars($userName); ?></span>
                <a class="btn btn-outline" href="payments.php">All Payments</a>
                <a class="btn btn-primary" href="logout.php" aria-label="Logout"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 bg-black/40 opacity-0 pointer-events-none transition-opacity lg:hidden" data-sidebar-overlay></div>

    <div class="container dashboard-shell grid gap-6 lg:grid-cols-[280px_1fr] py-8">
        <aside class="fixed inset-y-0 left-0 z-40 h-[100dvh] w-72 bg-white shadow-lift border-r border-ink-900/10 transform -translate-x-full transition-transform duration-200 lg:static lg:inset-auto lg:h-auto lg:translate-x-0" data-sidebar>
            <div class="sidebar-scroll-shell">
                <div class="p-6 border-b border-ink-900/10">
                    <h2 class="text-lg font-semibold text-ink-900">Navigation</h2>
                    <p class="text-sm text-slate-500">Clerk workspace</p>
                </div>
                <nav class="p-4 space-y-1 text-sm">
                    <a href="index.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                    <a href="payments.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold bg-teal-600/10 text-teal-700"><i class="fas fa-money-check-alt"></i><span>Payments</span></a>
                    <a href="fee_structure.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700"><i class="fas fa-layer-group"></i><span>Fee Structure</span></a>
                    <a href="receipt.php" class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold text-slate-600 hover:bg-teal-600/10 hover:text-teal-700"><i class="fas fa-receipt"></i><span>Receipts</span></a>
                </nav>
            </div>
        </aside>

        <main class="space-y-6">
            <section class="rounded-3xl bg-white p-6 shadow-lift border border-ink-900/5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Payment Record</p>
                        <h1 class="text-3xl font-display text-ink-900">Payment Details</h1>
                        <p class="text-slate-600">Review student transaction details, verification status, and attached evidence.</p>
                    </div>
                    <div class="hero-actions">
                        <a class="btn btn-outline" href="payments.php"><i class="fas fa-arrow-left"></i><span>Back to Payments</span></a>
                        <a class="btn btn-primary" href="receipt.php?id=<?php echo $paymentId; ?>" target="_blank"><i class="fas fa-receipt"></i><span>Open Receipt</span></a>
                    </div>
                </div>
                <div class="metric-grid mt-5">
                    <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Amount Paid</p><p class="text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars($paymentHelper->formatCurrency($amountPaid)); ?></p></div>
                    <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Total Amount</p><p class="text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars($paymentHelper->formatCurrency($totalAmount)); ?></p></div>
                    <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Outstanding</p><p class="text-2xl font-semibold text-ink-900"><?php echo htmlspecialchars($paymentHelper->formatCurrency($outstanding)); ?></p></div>
                    <div class="rounded-xl bg-mist-50 px-4 py-3 border border-ink-900/5"><p class="text-xs uppercase tracking-wide text-slate-500">Status</p><div class="mt-2"><span class="status-pill <?php echo $statusClasses[$statusKey]; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></div></div>
                </div>
            </section>

            <?php if ($message || $error): ?>
                <section class="rounded-3xl bg-white p-5 shadow-soft border border-ink-900/5 space-y-3">
                    <?php if ($message): ?><div class="rounded-xl border border-teal-600/20 bg-teal-600/10 px-4 py-3 text-sm text-teal-800"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
                    <?php if ($error): ?><div class="rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-700"><i class="fas fa-circle-exclamation mr-2"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <h2 class="text-2xl font-display text-ink-900 mb-4">Student and Transaction Summary</h2>
                <div class="detail-grid">
                    <article class="detail-card"><span class="detail-label">Student</span><p class="detail-value"><?php echo htmlspecialchars((string) ($payment['student_name'] ?? 'N/A')); ?></p><p class="detail-sub">Admission: <?php echo htmlspecialchars((string) ($payment['admission_no'] ?? 'N/A')); ?></p></article>
                    <article class="detail-card"><span class="detail-label">Class and Term</span><p class="detail-value"><?php echo htmlspecialchars((string) ($payment['class_name'] ?? 'N/A')); ?></p><p class="detail-sub"><?php echo htmlspecialchars((string) ($payment['term'] ?? 'N/A')); ?></p></article>
                    <article class="detail-card"><span class="detail-label">Receipt</span><p class="detail-value"><?php echo htmlspecialchars((string) (($payment['receipt_number'] ?? '') ?: 'N/A')); ?></p><p class="detail-sub">Txn: <?php echo htmlspecialchars((string) ($payment['transaction_id'] ?? 'N/A')); ?></p></article>
                    <article class="detail-card"><span class="detail-label">Payment Date</span><p class="detail-value"><?php echo !empty($payment['payment_date']) ? htmlspecialchars(date('M j, Y g:i A', strtotime((string) $payment['payment_date']))) : 'N/A'; ?></p><p class="detail-sub">Year: <?php echo htmlspecialchars((string) ($payment['academic_year'] ?? 'N/A')); ?></p></article>
                    <article class="detail-card"><span class="detail-label">Method and Type</span><p class="detail-value"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($payment['payment_method'] ?? 'cash')))); ?></p><p class="detail-sub"><?php echo htmlspecialchars(ucfirst((string) ($payment['payment_type'] ?? 'full'))); ?></p></article>
                    <article class="detail-card"><span class="detail-label">Verified By</span><p class="detail-value"><?php echo htmlspecialchars((string) ($payment['verified_by_name'] ?? 'Not yet verified')); ?></p><p class="detail-sub"><?php echo !empty($payment['verified_at']) ? htmlspecialchars(date('M j, Y g:i A', strtotime((string) $payment['verified_at']))) : 'N/A'; ?></p></article>
                </div>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <h2 class="text-lg font-semibold text-ink-900 mb-3">Verification Actions</h2>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="payment_id" value="<?php echo $paymentId; ?>">
                    <div><label class="control-label" for="verificationNotes">Verification Notes</label><textarea class="control-field control-textarea" id="verificationNotes" name="notes" placeholder="Optional notes for audit trail..."></textarea></div>
                    <div class="action-row">
                        <?php if (($payment['status'] ?? '') === 'pending'): ?>
                            <button class="action-btn action-btn-verify" type="submit" name="action" value="verify"><i class="fas fa-check"></i><span>Verify</span></button>
                            <button class="action-btn action-btn-reject" type="submit" name="action" value="reject"><i class="fas fa-ban"></i><span>Reject</span></button>
                        <?php elseif (in_array(($payment['status'] ?? ''), ['verified', 'partial'], true)): ?>
                            <button class="action-btn action-btn-complete" type="submit" name="action" value="complete"><i class="fas fa-check-double"></i><span>Mark Completed</span></button>
                        <?php endif; ?>
                        <a class="action-btn action-btn-receipt" href="receipt.php?id=<?php echo $paymentId; ?>" target="_blank"><i class="fas fa-receipt"></i><span>Generate Receipt</span></a>
                    </div>
                </form>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-soft border border-ink-900/5">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div><h2 class="text-lg font-semibold text-ink-900">Attachments</h2><p class="text-sm text-slate-600">Uploaded payment evidence and support files.</p></div>
                    <span class="text-xs uppercase tracking-wide text-slate-500"><?php echo number_format(count($attachments)); ?> file(s)</span>
                </div>
                <?php if (empty($attachments)): ?>
                    <div class="empty-state">No attachments uploaded for this payment yet.</div>
                <?php else: ?>
                    <div class="attachment-grid">
                        <?php foreach ($attachments as $attachment): ?>
                            <article class="attachment-card">
                                <p class="detail-value"><?php echo htmlspecialchars((string) ($attachment['file_name'] ?? 'Attachment')); ?></p>
                                <p class="attachment-meta">Uploaded: <?php echo !empty($attachment['uploaded_at']) ? htmlspecialchars(date('M j, Y g:i A', strtotime((string) $attachment['uploaded_at']))) : 'N/A'; ?></p>
                                <a class="action-btn action-btn-view" href="<?php echo htmlspecialchars((string) ($attachment['file_path'] ?? '#')); ?>" target="_blank"><i class="fas fa-eye"></i><span>View File</span></a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data" class="mt-5 space-y-3">
                    <input type="hidden" name="action" value="upload_attachment">
                    <div>
                        <label class="control-label" for="attachmentFile">Upload Supporting File</label>
                        <input class="control-field" id="attachmentFile" type="file" name="attachment_file" required>
                        <p class="mt-2 text-xs text-slate-500">Allowed: JPG, PNG, PDF. Max size 5MB.</p>
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="fas fa-upload"></i><span>Upload Attachment</span></button>
                </form>
            </section>
        </main>
    </div>

<?php include '../includes/floating-button.php'; ?>
<script src="../assets/js/offline-core.js" defer></script>
<script>
const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
const sidebar = document.querySelector('[data-sidebar]');
const overlay = document.querySelector('[data-sidebar-overlay]');
const body = document.body;
const openSidebar = () => { if (!sidebar || !overlay) return; sidebar.classList.remove('-translate-x-full'); overlay.classList.remove('opacity-0', 'pointer-events-none'); overlay.classList.add('opacity-100'); body.classList.add('nav-open'); };
const closeSidebar = () => { if (!sidebar || !overlay) return; sidebar.classList.add('-translate-x-full'); overlay.classList.add('opacity-0', 'pointer-events-none'); overlay.classList.remove('opacity-100'); body.classList.remove('nav-open'); };
if (sidebarToggle) { sidebarToggle.addEventListener('click', () => { if (sidebar.classList.contains('-translate-x-full')) { openSidebar(); } else { closeSidebar(); } }); }
if (overlay) { overlay.addEventListener('click', closeSidebar); }
if (sidebar) { sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeSidebar)); }
</script>
</body>
</html>
