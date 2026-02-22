<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_id = (int)$_SESSION['user_id'];
$principal_name = $_SESSION['full_name'] ?? 'Principal';
$current_school_id = require_school_auth();

$errors = [];
$success = '';
$csrf_token = generate_csrf_token();

function log_subscription_action($school_id, $request_id, $action, $message, $metadata = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO school_subscription_audit_logs
            (school_id, request_id, action, actor_id, actor_role, message, metadata_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $school_id,
            $request_id,
            $action,
            $_SESSION['user_id'] ?? 0,
            $_SESSION['role'] ?? 'principal',
            $message,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null
        ]);
    } catch (Exception $e) {
        error_log('Subscription audit log error: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    }

    $action = $_POST['action'] ?? '';

    if (empty($errors) && $action === 'create_request') {
        $plan_id = 0;
        if (isset($_POST['plan_id'])) {
            $plan_id = (int)$_POST['plan_id'];
        } elseif (isset($_POST['selected_plan_id'])) {
            $plan_id = (int)$_POST['selected_plan_id'];
        } elseif (isset($_POST['plan_submit_id'])) {
            $plan_id = (int)$_POST['plan_submit_id'];
        }
        $request_note = trim($_POST['request_note'] ?? '');

        if ($plan_id <= 0) {
            $errors[] = 'Please select a subscription plan.';
        } else {
            $plan_stmt = $pdo->prepare("SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1");
            $plan_stmt->execute([$plan_id]);
            $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan) {
                $errors[] = 'Selected plan is not available.';
            } else {
                $open_stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM school_subscription_requests
                    WHERE school_id = ? AND status IN ('pending_payment', 'under_review')
                ");
                $open_stmt->execute([$current_school_id]);
                $open_count = (int)$open_stmt->fetchColumn();

                if ($open_count > 0) {
                    $errors[] = 'You already have a pending subscription request. Complete or resolve it first.';
                } else {
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO school_subscription_requests
                        (school_id, plan_id, requested_by, expected_amount, status, request_note)
                        VALUES (?, ?, ?, ?, 'pending_payment', ?)
                    ");
                    $insert_stmt->execute([
                        $current_school_id,
                        $plan_id,
                        $principal_id,
                        $plan['amount'],
                        $request_note !== '' ? $request_note : null
                    ]);
                    $request_id = (int)$pdo->lastInsertId();
                    $success = 'Subscription request created. Upload your bank transfer proof for review.';

                    log_subscription_action($current_school_id, $request_id, 'request_created', 'Principal created subscription request', [
                        'plan_id' => $plan_id,
                        'plan_code' => $plan['plan_code'] ?? null,
                        'amount' => $plan['amount']
                    ]);
                }
            }
        }
    } elseif (empty($errors) && $action === 'upload_proof') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $transfer_date = $_POST['transfer_date'] ?? '';
        $amount_paid = (float)($_POST['amount_paid'] ?? 0);
        $transfer_reference = trim($_POST['transfer_reference'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_name = trim($_POST['account_name'] ?? '');
        $note = trim($_POST['note'] ?? '');

        $request_stmt = $pdo->prepare("
            SELECT sr.*, sp.amount AS plan_amount
            FROM school_subscription_requests sr
            JOIN subscription_plans sp ON sp.id = sr.plan_id
            WHERE sr.id = ? AND sr.school_id = ?
            LIMIT 1
        ");
        $request_stmt->execute([$request_id, $current_school_id]);
        $request = $request_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $errors[] = 'Subscription request not found.';
        } elseif (!in_array($request['status'], ['pending_payment', 'rejected'], true)) {
            $errors[] = 'Payment proof upload is not allowed for this request status.';
        } elseif (empty($transfer_date)) {
            $errors[] = 'Transfer date is required.';
        } elseif ($amount_paid <= 0) {
            $errors[] = 'Amount paid must be greater than zero.';
        } elseif (!isset($_FILES['proof_file']) || $_FILES['proof_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Payment proof file is required.';
        } else {
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
            $file_name = $_FILES['proof_file']['name'];
            $file_size = (int)$_FILES['proof_file']['size'];
            $tmp_name = $_FILES['proof_file']['tmp_name'];
            $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowed_extensions, true)) {
                $errors[] = 'Only JPG, JPEG, PNG, and PDF files are allowed.';
            }
            if ($file_size > 5 * 1024 * 1024) {
                $errors[] = 'Payment proof file must be 5MB or smaller.';
            }
            // Extension check is not enough; enforce MIME type too.
            $allowed_mimes = ['image/jpeg', 'image/png', 'application/pdf'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp_name) ?: '';
            if (!in_array($mime, $allowed_mimes, true)) {
                $errors[] = 'Invalid file type detected. Upload a valid JPG, PNG, or PDF.';
            }

            if (empty($errors)) {
                $upload_dir = "../uploads/subscriptions/{$current_school_id}/";
                if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
                    $errors[] = 'Failed to prepare upload directory.';
                } else {
                    $stored_name = "proof_{$request_id}_" . time() . '_' . bin2hex(random_bytes(4)) . ".{$extension}";
                    $stored_path = $upload_dir . $stored_name;
                    $db_path = "uploads/subscriptions/{$current_school_id}/{$stored_name}";

                    if (!move_uploaded_file($tmp_name, $stored_path)) {
                        $errors[] = 'Failed to upload payment proof file.';
                    } else {
                        $proof_stmt = $pdo->prepare("
                            INSERT INTO school_subscription_payment_proofs
                            (request_id, uploaded_by, transfer_date, amount_paid, transfer_reference, bank_name, account_name, note, proof_file_path, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'under_review')
                        ");
                        $proof_stmt->execute([
                            $request_id,
                            $principal_id,
                            $transfer_date,
                            $amount_paid,
                            $transfer_reference !== '' ? $transfer_reference : null,
                            $bank_name !== '' ? $bank_name : null,
                            $account_name !== '' ? $account_name : null,
                            $note !== '' ? $note : null,
                            $db_path
                        ]);

                        $update_stmt = $pdo->prepare("UPDATE school_subscription_requests SET status = 'under_review', review_note = NULL, updated_at = NOW() WHERE id = ?");
                        $update_stmt->execute([$request_id]);
                        $success = 'Payment proof uploaded successfully. Waiting for super admin verification.';

                        log_subscription_action($current_school_id, $request_id, 'proof_uploaded', 'Principal uploaded payment proof', [
                            'amount_paid' => $amount_paid,
                            'transfer_reference' => $transfer_reference
                        ]);
                    }
                }
            }
        }
    }
}

$subscription_state = get_school_subscription_state($current_school_id);
$current_subscription = $subscription_state['record'];

$plans_stmt = $pdo->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY billing_cycle, amount");
$plans = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);

$requests_stmt = $pdo->prepare("
    SELECT sr.*, sp.name AS plan_name, sp.billing_cycle,
           (
               SELECT p.proof_file_path
               FROM school_subscription_payment_proofs p
               WHERE p.request_id = sr.id
               ORDER BY p.created_at DESC, p.id DESC
               LIMIT 1
           ) AS latest_proof_file,
           (
               SELECT COUNT(*)
               FROM school_subscription_payment_proofs p2
               WHERE p2.request_id = sr.id
           ) AS proof_count
    FROM school_subscription_requests sr
    JOIN subscription_plans sp ON sp.id = sr.plan_id
    WHERE sr.school_id = ?
    ORDER BY sr.created_at DESC
");
$requests_stmt->execute([$current_school_id]);
$requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Billing | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .content { padding: 24px; }
        .page-header { margin-bottom: 20px; }
        .page-header h2 { margin: 0; color: #1f2937; }
        .page-header p { color: #6b7280; margin-top: 6px; }
        .card { background: #fff; border-radius: 10px; border: 1px solid #e5e7eb; padding: 16px; margin-bottom: 16px; }
        .status-pill { display: inline-block; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .status-active, .status-lifetime_active { background: #dcfce7; color: #166534; }
        .status-grace_period { background: #fef9c3; color: #854d0e; }
        .status-expired, .status-suspended, .status-none { background: #fee2e2; color: #991b1b; }
        .status-pending_payment { background: #e0f2fe; color: #075985; }
        .status-under_review { background: #ede9fe; color: #5b21b6; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(250px,1fr)); gap: 12px; }
        .plan-title { margin: 0 0 6px; font-size: 18px; color: #111827; }
        .price { font-size: 24px; font-weight: 800; color: #2563eb; margin: 8px 0; }
        .small { font-size: 13px; color: #6b7280; }
        .alert { padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .form-row { margin-bottom: 10px; }
        input, textarea, select { width: 100%; padding: 9px; border: 1px solid #d1d5db; border-radius: 8px; }
        textarea { min-height: 70px; resize: vertical; }
        .btn { border: none; padding: 10px 14px; border-radius: 8px; cursor: pointer; font-weight: 700; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-secondary { background: #f3f4f6; color: #111827; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; vertical-align: top; }
        .table th { color: #374151; font-size: 13px; text-transform: uppercase; }
        .proof-upload-box { border: 1px dashed #cbd5e1; border-radius: 8px; padding: 12px; margin-top: 8px; }
    </style>
</head>
<body>
<?php include '../includes/mobile_navigation.php'; ?>

    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-left">
                <div class="school-logo-container">
                    <img src="../assets/images/nysc.jpg" alt="School Logo" class="school-logo">
                    <div class="school-info">
                        <h1 class="school-name">SahabFormMaster</h1>
                        <p class="school-tagline">Principal Portal</p>
                    </div>
                </div>
            </div>
            <div class="header-right">
                <div class="principal-info">
                    <p class="principal-label">Principal</p>
                    <span class="principal-name"><?php echo htmlspecialchars($principal_name); ?></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

<div class="dashboard-container">
    <?php include '../includes/admin_sidebar.php'; ?>
    <main class="main-content">
        <div class="content">
            <div class="page-header">
                <h2><i class="fas fa-credit-card"></i> Subscription Billing</h2>
                <p>Welcome <?php echo htmlspecialchars($principal_name); ?>. Select a plan, transfer to bank, and upload payment proof for approval.</p>
            </div>

            <?php if (!empty($_GET['notice']) && $_GET['notice'] === 'subscription_required'): ?>
                <div class="alert alert-error">Your school subscription is inactive. Submit or renew a plan to continue using the system.</div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>

            <div class="card">
                <h3>Current Subscription Status</h3>
                <p>
                    <span class="status-pill status-<?php echo htmlspecialchars($subscription_state['status']); ?>">
                        <?php echo htmlspecialchars(str_replace('_', ' ', $subscription_state['status'])); ?>
                    </span>
                </p>
                <?php if ($current_subscription): ?>
                    <p><strong>Plan:</strong> <?php echo htmlspecialchars($current_subscription['plan_name']); ?></p>
                    <p><strong>Start:</strong> <?php echo htmlspecialchars($current_subscription['start_date']); ?></p>
                    <p><strong>End:</strong> <?php echo htmlspecialchars($current_subscription['end_date'] ?? 'N/A'); ?></p>
                    <p><strong>Grace End:</strong> <?php echo htmlspecialchars($current_subscription['grace_end_date'] ?? 'N/A'); ?></p>
                <?php else: ?>
                    <p class="small">No approved subscription yet.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3>Select a Plan</h3>
                <div class="grid">
                    <?php foreach ($plans as $plan): ?>
                        <form method="POST" class="card">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="create_request">
                            <input type="hidden" name="plan_id" value="<?php echo (int)$plan['id']; ?>">
                            <input type="hidden" name="selected_plan_id" value="<?php echo (int)$plan['id']; ?>">
                            <h4 class="plan-title"><?php echo htmlspecialchars($plan['name']); ?></h4>
                            <div class="small"><?php echo htmlspecialchars(ucfirst($plan['billing_cycle'])); ?></div>
                            <div class="price">N<?php echo number_format((float)$plan['amount'], 2); ?></div>
                            <div class="small"><?php echo htmlspecialchars($plan['description'] ?? ''); ?></div>
                            <div class="form-row" style="margin-top:10px;">
                                <textarea name="request_note" placeholder="Optional note to super admin"></textarea>
                            </div>
                            <button class="btn btn-primary" type="submit" name="plan_submit_id" value="<?php echo (int)$plan['id']; ?>">Select Plan</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h3>My Subscription Requests</h3>
                <?php if (empty($requests)): ?>
                    <p class="small">No requests yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Plan</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Proof</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($request['plan_name']); ?><br>
                                        <span class="small"><?php echo htmlspecialchars(ucfirst($request['billing_cycle'])); ?></span>
                                    </td>
                                    <td>N<?php echo number_format((float)$request['expected_amount'], 2); ?></td>
                                    <td>
                                        <span class="status-pill status-<?php echo htmlspecialchars($request['status']); ?>">
                                            <?php echo htmlspecialchars(str_replace('_', ' ', $request['status'])); ?>
                                        </span>
                                        <?php if (!empty($request['review_note'])): ?>
                                            <div class="small" style="margin-top:6px;"><?php echo htmlspecialchars($request['review_note']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($request['latest_proof_file'])): ?>
                                            <a href="view_subscription_proof.php?request_id=<?php echo (int)$request['id']; ?>" target="_blank">View latest proof</a><br>
                                        <?php endif; ?>
                                        <span class="small"><?php echo (int)$request['proof_count']; ?> file(s)</span>
                                    </td>
                                    <td>
                                        <?php if (in_array($request['status'], ['pending_payment', 'rejected'], true)): ?>
                                            <form method="POST" enctype="multipart/form-data" class="proof-upload-box">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action" value="upload_proof">
                                                <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                                <div class="form-row">
                                                    <label>Transfer Date</label>
                                                    <input type="date" name="transfer_date" required>
                                                </div>
                                                <div class="form-row">
                                                    <label>Amount Paid</label>
                                                    <input type="number" name="amount_paid" min="0" step="0.01" required>
                                                </div>
                                                <div class="form-row">
                                                    <label>Transfer Reference</label>
                                                    <input type="text" name="transfer_reference">
                                                </div>
                                                <div class="form-row">
                                                    <label>Bank Name</label>
                                                    <input type="text" name="bank_name">
                                                </div>
                                                <div class="form-row">
                                                    <label>Account Name</label>
                                                    <input type="text" name="account_name">
                                                </div>
                                                <div class="form-row">
                                                    <label>Payment Proof (JPG, PNG, PDF, max 5MB)</label>
                                                    <input type="file" name="proof_file" accept=".jpg,.jpeg,.png,.pdf" required>
                                                </div>
                                                <div class="form-row">
                                                    <label>Note</label>
                                                    <textarea name="note"></textarea>
                                                </div>
                                                <button class="btn btn-primary" type="submit">Upload Proof</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="small">No action required.</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
</body>
</html>
