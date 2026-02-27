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

function fetch_payment($pdo, $paymentId, $schoolId) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Details - Clerk</title>
    <link rel="stylesheet" href="../assets/css/education-theme-main.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
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
        .card { background: var(--clerk-surface); border-radius: var(--clerk-radius); padding: 16px; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06); margin-bottom: 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
        .badge { padding: 4px 10px; border-radius: 999px; font-size: 0.8rem; font-weight: 600; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-verified { background: #dbeafe; color: #1e40af; }
        .badge-completed { background: #dcfce7; color: #166534; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .badge-partial { background: #e0f2fe; color: #075985; }
        .action-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; align-items: center; }
        .btn { padding: 8px 14px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-verify { background: #2563eb; color: #fff; }
        .btn-complete { background: #16a34a; color: #fff; }
        .btn-reject { background: #dc2626; color: #fff; }
        .btn-receipt { background: #0ea5e9; color: #fff; }
        .notice { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
        .notice.success { background: #dcfce7; color: #166534; }
        .notice.error { background: #fee2e2; color: #991b1b; }
        .attachment { border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; }
        .attachment a { text-decoration: none; color: #0f172a; } 
        .btn-logout.clerk-logout { background: #dc2626; }
        .btn-logout.clerk-logout:hover { background: #b91c1c; }
        .form-field,
        .form-file {
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
        .form-field:focus,
        .form-file:focus {
            border-color: var(--clerk-accent);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15);
            background: #ffffff;
        }
        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 0 0 10px;
        }
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .page-container { padding: 16px; }
            .card { padding: 14px; }
        }
        @media (max-width: 520px) {
            .action-row { flex-direction: column; align-items: stretch; }
            .btn { width: 100%; }
        }
       
    </style>
</head>
<body>
<?php include '../includes/header.php'; ?>
<?php include '../includes/mobile_navigation.php'; ?>

<header class="dashboard-header">
    <div class="header-container">
        <div class="header-left">
            <div class="school-logo-container">
                <img src="<?php echo htmlspecialchars(get_school_logo_url()); ?>" alt="School Logo" class="school-logo">
                <div class="school-info">
                    <h1 class="school-name"><?php echo htmlspecialchars(get_school_display_name()); ?></h1>
                    <p class="school-tagline">Clerk Portal</p>
                </div>
            </div>
        </div>
        <div class="header-right">
            <div class="teacher-info">
                <p class="teacher-label">Clerk</p>
                <span class="teacher-name"><?php echo htmlspecialchars($userName); ?></span>
            </div>
            <a href="logout.php" class="btn-logout clerk-logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
</header>

<div class="dashboard-wrapper">
    <?php include '../includes/clerk_sidebar.php'; ?>
    <main class="main-content">
        <div class="page-container">
            <h2>Payment Details</h2>

            <?php if ($message): ?>
                <div class="notice success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="grid">
                    <div>
                        <strong>Student</strong><br>
                        <?php echo htmlspecialchars($payment['student_name']); ?><br>
                        <small><?php echo htmlspecialchars($payment['admission_no']); ?></small>
                    </div>
                    <div>
                        <strong>Class</strong><br>
                        <?php echo htmlspecialchars($payment['class_name']); ?>
                    </div>
                    <div>
                        <strong>Amount Paid</strong><br>
                        <?php echo $paymentHelper->formatCurrency($payment['amount_paid']); ?>
                    </div>
                    <div>
                        <strong>Status</strong><br>
                        <span class="badge badge-<?php echo htmlspecialchars($payment['status']); ?>">
                            <?php echo ucfirst($payment['status']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="grid">
                    <div><strong>Receipt</strong><br><?php echo htmlspecialchars($payment['receipt_number'] ?? '—'); ?></div>
                    <div><strong>Payment Date</strong><br><?php echo $payment['payment_date'] ? date('d/m/Y H:i', strtotime($payment['payment_date'])) : '—'; ?></div>
                    <div><strong>Method</strong><br><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $payment['payment_method']))); ?></div>
                    <div><strong>Type</strong><br><?php echo htmlspecialchars(ucfirst($payment['payment_type'] ?? 'full')); ?></div>
                </div>

                <form method="POST" class="action-row">
                    <input type="hidden" name="payment_id" value="<?php echo $paymentId; ?>">
                    <textarea name="notes" rows="2" placeholder="Optional notes..." class="form-field" style="flex: 1 1 240px;"></textarea>
                    <?php if ($payment['status'] === 'pending'): ?>
                        <button class="btn btn-verify" type="submit" name="action" value="verify">Verify</button>
                        <button class="btn btn-reject" type="submit" name="action" value="reject">Reject</button>
                    <?php elseif (in_array($payment['status'], ['verified', 'partial'], true)): ?>
                        <button class="btn btn-complete" type="submit" name="action" value="complete">Mark Completed</button>
                    <?php endif; ?>
                    <a class="btn btn-receipt" href="receipt.php?id=<?php echo $paymentId; ?>" target="_blank">
                        <i class="fas fa-receipt"></i>
                        <span>Generate Receipt</span>
                    </a>
                </form>
            </div>

            <div class="card">
                <div class="section-title">
                    <h4>Attachments</h4>
                    <a class="btn btn-receipt" href="receipt.php?id=<?php echo $paymentId; ?>" target="_blank">
                        <i class="fas fa-receipt"></i>
                        <span>Receipt</span>
                    </a>
                </div>
                <?php if (empty($attachments)): ?>
                    <p>No attachments uploaded.</p>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($attachments as $attachment): ?>
                            <div class="attachment">
                                <div><strong><?php echo htmlspecialchars($attachment['file_name']); ?></strong></div>
                                <div><small><?php echo $attachment['uploaded_at'] ? date('d/m/Y H:i', strtotime($attachment['uploaded_at'])) : '—'; ?></small></div>
                                <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" target="_blank">View</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" style="margin-top: 12px; display: grid; gap: 10px;">
                    <input type="hidden" name="action" value="upload_attachment">
                    <input class="form-file" type="file" name="attachment_file" required>
                    <button class="btn btn-verify" type="submit">
                        <i class="fas fa-upload"></i>
                        <span>Upload Attachment</span>
                    </button>
                </form>
            </div>
        </div>
    </main>
</div>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
