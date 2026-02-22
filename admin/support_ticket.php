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
$school_id = require_school_auth();
$ticket_id = (int)($_GET['id'] ?? 0);

if ($ticket_id <= 0) {
    header("Location: support.php");
    exit;
}

$errors = [];
$success = '';

$ticket_stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ? AND school_id = ? LIMIT 1");
$ticket_stmt->execute([$ticket_id, $school_id]);
$ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);
if (!$ticket) {
    header("Location: support.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply_ticket') {
    $message = trim($_POST['message'] ?? '');
    $attachment_path = null;

    if ($message === '') {
        $errors[] = 'Reply message is required.';
    }

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Attachment upload failed.';
        } else {
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
            $extension = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $allowed_extensions, true)) {
                $errors[] = 'Unsupported attachment format.';
            }
            if ((int)$_FILES['attachment']['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Attachment size must be 5MB or less.';
            }

            if (empty($errors)) {
                $upload_dir = "../uploads/support/{$ticket_id}/";
                if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
                    $errors[] = 'Failed to prepare attachment directory.';
                } else {
                    $stored_name = 'reply_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                    $stored_path = $upload_dir . $stored_name;
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $stored_path)) {
                        $attachment_path = "uploads/support/{$ticket_id}/{$stored_name}";
                    } else {
                        $errors[] = 'Failed to save attachment.';
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $msg_stmt = $pdo->prepare("
                INSERT INTO support_ticket_messages (ticket_id, sender_id, sender_role, message, attachment_path)
                VALUES (?, ?, 'principal', ?, ?)
            ");
            $msg_stmt->execute([$ticket_id, $principal_id, $message, $attachment_path]);

            $new_status = in_array($ticket['status'], ['resolved', 'closed'], true) ? 'open' : $ticket['status'];
            $update_stmt = $pdo->prepare("UPDATE support_tickets SET status = ?, last_message_at = NOW(), updated_at = NOW() WHERE id = ?");
            $update_stmt->execute([$new_status, $ticket_id]);

            $read_stmt = $pdo->prepare("
                INSERT INTO support_ticket_reads (ticket_id, user_id, last_read_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at)
            ");
            $read_stmt->execute([$ticket_id, $principal_id]);

            $pdo->commit();
            $success = 'Reply sent successfully.';

            $ticket_stmt->execute([$ticket_id, $school_id]);
            $ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Failed to send reply: ' . $e->getMessage();
        }
    }
}

$msgs_stmt = $pdo->prepare("
    SELECT stm.*, u.full_name
    FROM support_ticket_messages stm
    LEFT JOIN users u ON u.id = stm.sender_id
    WHERE stm.ticket_id = ?
    ORDER BY stm.created_at ASC, stm.id ASC
");
$msgs_stmt->execute([$ticket_id]);
$messages = $msgs_stmt->fetchAll(PDO::FETCH_ASSOC);

$read_stmt = $pdo->prepare("
    INSERT INTO support_ticket_reads (ticket_id, user_id, last_read_at)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at)
");
$read_stmt->execute([$ticket_id, $principal_id]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Ticket | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .content-wrap { padding: 24px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        .alert { padding: 10px 12px; border-radius: 8px; margin-bottom: 10px; }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .msg { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin-bottom: 10px; }
        .msg-principal { background: #eff6ff; border-color: #bfdbfe; }
        .msg-super { background: #f9fafb; }
        textarea, input[type=file] { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 9px; }
        textarea { min-height: 120px; resize: vertical; }
        .btn { border: none; border-radius: 8px; padding: 10px 14px; cursor: pointer; font-weight: 700; background: #2563eb; color: #fff; }
        .pill { display: inline-block; border-radius: 999px; padding: 4px 9px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .open { background: #dbeafe; color: #1e40af; }
        .in_progress { background: #ede9fe; color: #6d28d9; }
        .resolved { background: #dcfce7; color: #166534; }
        .closed { background: #e5e7eb; color: #374151; }
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
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <?php include '../includes/admin_sidebar.php'; ?>
    <main class="main-content">
        <div class="content-wrap">
            <div class="card">
                <a href="support.php"><i class="fas fa-arrow-left"></i> Back to Support</a>
                <h2><?php echo htmlspecialchars($ticket['ticket_code'] ?: ('Ticket #' . $ticket['id'])); ?></h2>
                <p><strong>Subject:</strong> <?php echo htmlspecialchars($ticket['subject']); ?></p>
                <p>
                    <strong>Status:</strong>
                    <span class="pill <?php echo htmlspecialchars($ticket['status']); ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $ticket['status'])); ?></span>
                    &nbsp; <strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($ticket['category'])); ?>
                    &nbsp; <strong>Priority:</strong> <?php echo htmlspecialchars(ucfirst($ticket['priority'])); ?>
                </p>
            </div>

            <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php foreach ($errors as $error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>

            <div class="card">
                <h3>Conversation</h3>
                <?php if (empty($messages)): ?>
                    <p>No messages yet.</p>
                <?php endif; ?>
                <?php foreach ($messages as $message): ?>
                    <div class="msg <?php echo $message['sender_role'] === 'principal' ? 'msg-principal' : 'msg-super'; ?>">
                        <div><strong><?php echo htmlspecialchars($message['full_name'] ?: ucfirst($message['sender_role'])); ?></strong> (<?php echo htmlspecialchars($message['sender_role']); ?>)</div>
                        <div><?php echo nl2br(htmlspecialchars($message['message'])); ?></div>
                        <?php if (!empty($message['attachment_path'])): ?>
                            <div><a href="../<?php echo htmlspecialchars($message['attachment_path']); ?>" target="_blank">View attachment</a></div>
                        <?php endif; ?>
                        <small><?php echo htmlspecialchars($message['created_at']); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card">
                <h3>Reply</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="reply_ticket">
                    <p><textarea name="message" required placeholder="Write your reply"></textarea></p>
                    <p><input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"></p>
                    <button class="btn" type="submit">Send Reply</button>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>

