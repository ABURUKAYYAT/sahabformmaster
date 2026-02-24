<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: ../index.php");
    exit;
}

$principal_name = $_SESSION['full_name'] ?? 'Principal';
$school_id = require_school_auth();
$request_id = (int)($_GET['request_id'] ?? 0);
$raw_mode = isset($_GET['raw']) && $_GET['raw'] === '1';

if ($request_id <= 0) {
    header('Location: subscription.php?proof_error=invalid_request');
    exit;
}

$stmt = $pdo->prepare("
    SELECT r.id AS request_id,
           r.status AS request_status,
           r.expected_amount,
           r.created_at AS request_created_at,
           sp.name AS plan_name,
           sp.billing_cycle,
           p.id AS proof_id,
           p.transfer_date,
           p.amount_paid,
           p.transfer_reference,
           p.bank_name,
           p.account_name,
           p.note,
           p.proof_file_path,
           p.created_at AS proof_uploaded_at
    FROM school_subscription_requests r
    JOIN subscription_plans sp ON sp.id = r.plan_id
    JOIN school_subscription_payment_proofs p ON p.request_id = r.id
    WHERE r.id = ? AND r.school_id = ?
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT 1
");
$stmt->execute([$request_id, $school_id]);
$proof = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$proof) {
    header('Location: subscription.php?proof_error=not_found');
    exit;
}

$full = realpath(__DIR__ . '/../' . ltrim($proof['proof_file_path'], '/\\'));
$base = realpath(__DIR__ . '/../uploads/subscriptions');
if (!$full || !$base || strpos($full, $base) !== 0 || !is_file($full)) {
    header('Location: subscription.php?proof_error=unavailable');
    exit;
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$mime_map = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'pdf' => 'application/pdf'
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';
$is_image = strpos($mime, 'image/') === 0;
$is_pdf = $mime === 'application/pdf';

if ($raw_mode) {
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename($full) . '"');
    header('Content-Length: ' . filesize($full));
    readfile($full);
    exit;
}

function request_status_badge_class($status)
{
    if ($status === 'approved') {
        return 'badge-success';
    }
    if (in_array($status, ['pending_payment', 'under_review'], true)) {
        return 'badge-warning';
    }
    if ($status === 'rejected') {
        return 'badge-danger';
    }
    return 'badge-default';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Subscription Proof | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .badge-default {
            background: #e5e7eb;
            color: #374151;
        }
        .badge-danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }
        .status-badge {
            text-transform: uppercase;
        }
        .proof-preview {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }
        .proof-preview iframe {
            width: 100%;
            min-height: 72vh;
            border: 0;
        }
        .proof-preview img {
            max-width: 100%;
            display: block;
            margin: 0 auto;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px;
        }
        .meta-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px;
        }
        .meta-label {
            font-size: 0.8rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .meta-value {
            color: #0f172a;
            font-weight: 600;
            word-break: break-word;
        }
        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
        <div class="content-header">
            <div class="welcome-section">
                <h2><i class="fas fa-file-invoice"></i> Subscription Proof</h2>
                <p>Review the most recent uploaded proof for this subscription request.</p>
            </div>
        </div>

        <section class="panel">
            <div class="panel-header">
                <h2><i class="fas fa-info-circle"></i> Request Details</h2>
            </div>
            <div class="meta-grid">
                <div class="meta-item">
                    <div class="meta-label">Request ID</div>
                    <div class="meta-value">#<?php echo (int)$proof['request_id']; ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Plan</div>
                    <div class="meta-value"><?php echo htmlspecialchars($proof['plan_name']); ?> (<?php echo htmlspecialchars(ucfirst($proof['billing_cycle'])); ?>)</div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Expected Amount</div>
                    <div class="meta-value">N<?php echo number_format((float)$proof['expected_amount'], 2); ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Request Status</div>
                    <div class="meta-value">
                        <span class="badge status-badge <?php echo htmlspecialchars(request_status_badge_class($proof['request_status'])); ?>">
                            <?php echo htmlspecialchars(str_replace('_', ' ', $proof['request_status'])); ?>
                        </span>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Transfer Date</div>
                    <div class="meta-value"><?php echo htmlspecialchars($proof['transfer_date'] ?: 'N/A'); ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Amount Paid</div>
                    <div class="meta-value">N<?php echo number_format((float)$proof['amount_paid'], 2); ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Transfer Reference</div>
                    <div class="meta-value"><?php echo htmlspecialchars($proof['transfer_reference'] ?: 'N/A'); ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Bank Name</div>
                    <div class="meta-value"><?php echo htmlspecialchars($proof['bank_name'] ?: 'N/A'); ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Account Name</div>
                    <div class="meta-value"><?php echo htmlspecialchars($proof['account_name'] ?: 'N/A'); ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Uploaded At</div>
                    <div class="meta-value"><?php echo htmlspecialchars($proof['proof_uploaded_at']); ?></div>
                </div>
            </div>
            <?php if (!empty($proof['note'])): ?>
                <div class="alert alert-success" style="margin-top: 14px;">
                    <i class="fas fa-sticky-note"></i>
                    <?php echo nl2br(htmlspecialchars($proof['note'])); ?>
                </div>
            <?php endif; ?>
            <div class="btn-row" style="margin-top: 14px;">
                <a href="subscription.php" class="btn secondary"><i class="fas fa-arrow-left"></i> Back to Subscription</a>
                <a href="view_subscription_proof.php?request_id=<?php echo (int)$proof['request_id']; ?>&raw=1" class="btn primary" target="_blank">
                    <i class="fas fa-external-link-alt"></i> Open Raw File
                </a>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <h2><i class="fas fa-eye"></i> Proof Preview</h2>
            </div>
            <div class="proof-preview">
                <?php if ($is_pdf): ?>
                    <iframe src="view_subscription_proof.php?request_id=<?php echo (int)$proof['request_id']; ?>&raw=1"></iframe>
                <?php elseif ($is_image): ?>
                    <img src="view_subscription_proof.php?request_id=<?php echo (int)$proof['request_id']; ?>&raw=1" alt="Payment proof image">
                <?php else: ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        Preview is not available for this file type.
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
