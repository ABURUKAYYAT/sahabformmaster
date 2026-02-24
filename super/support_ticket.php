<?php
require_once 'auth_check.php';
require_once '../config/db.php';

$super_id = (int)($_SESSION['user_id'] ?? 0);
$ticket_id = (int)($_GET['id'] ?? 0);
if ($ticket_id <= 0) {
    header("Location: support_tickets.php");
    exit;
}

$errors = [];
$success = '';

$ticket_stmt = $pdo->prepare("
    SELECT st.*, s.school_name, u.full_name AS principal_name, au.full_name AS assigned_name
    FROM support_tickets st
    JOIN schools s ON s.id = st.school_id
    LEFT JOIN users u ON u.id = st.created_by
    LEFT JOIN users au ON au.id = st.assigned_to
    WHERE st.id = ?
    LIMIT 1
");
$ticket_stmt->execute([$ticket_id]);
$ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);
if (!$ticket) {
    header("Location: support_tickets.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reply_ticket') {
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
                        $stored_name = 'super_reply_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
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
                    VALUES (?, ?, 'super_admin', ?, ?)
                ");
                $msg_stmt->execute([$ticket_id, $super_id, $message, $attachment_path]);

                $status = in_array($ticket['status'], ['open', 'resolved', 'closed'], true) ? 'in_progress' : $ticket['status'];
                $up_stmt = $pdo->prepare("
                    UPDATE support_tickets
                    SET status = ?, assigned_to = ?, last_message_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ");
                $up_stmt->execute([$status, $super_id, $ticket_id]);

                $read_stmt = $pdo->prepare("
                    INSERT INTO support_ticket_reads (ticket_id, user_id, last_read_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at)
                ");
                $read_stmt->execute([$ticket_id, $super_id]);

                $pdo->commit();
                $success = 'Reply sent.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Failed to send reply: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_status') {
        $new_status = trim($_POST['status'] ?? '');
        if (!in_array($new_status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
            $errors[] = 'Invalid status selected.';
        } else {
            $resolved_at = ($new_status === 'resolved' || $new_status === 'closed') ? date('Y-m-d H:i:s') : null;
            $up_stmt = $pdo->prepare("
                UPDATE support_tickets
                SET status = ?, assigned_to = ?, resolved_at = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $up_stmt->execute([$new_status, $super_id, $resolved_at, $ticket_id]);
            $success = 'Ticket status updated.';
        }
    }

    $ticket_stmt->execute([$ticket_id]);
    $ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);
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
$read_stmt->execute([$ticket_id, $super_id]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Ticket | SahabFormMaster</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f8fafc; color: #1f2937; }
        .dashboard-container { display: flex; min-height: 100vh; }
        .sidebar { width: 280px; background: linear-gradient(180deg, #1e293b 0%, #334155 100%); color: white; padding: 0; position: fixed; height: 100vh; overflow-y: auto; z-index: 1000; }
        .sidebar-header { padding: 24px; border-bottom: 1px solid #475569; background: rgba(255,255,255,0.05); }
        .sidebar-header h2 { font-size: 18px; color: #f8fafc; margin: 0; }
        .sidebar-header p { font-size: 14px; color: #cbd5e1; margin: 4px 0 0; }
        .sidebar-nav { padding: 16px 0; }
        .nav-item { margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 14px 24px; color: #cbd5e1; text-decoration: none; border-left: 4px solid transparent; }
        .nav-link:hover { background: rgba(255,255,255,0.1); color: #f8fafc; border-left-color: #3b82f6; }
        .nav-link.active { background: rgba(59,130,246,0.2); color: #f8fafc; border-left-color: #3b82f6; }
        .nav-icon { margin-right: 12px; width: 20px; text-align: center; }
        .main-content { flex: 1; margin-left: 280px; padding: 20px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        .msg { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin-bottom: 10px; }
        .msg-super { background: #eff6ff; border-color: #bfdbfe; }
        .msg-principal { background: #f9fafb; }
        .alert { padding: 10px 12px; border-radius: 8px; margin-bottom: 10px; }
        .ok { background: #dcfce7; color: #166534; }
        .err { background: #fee2e2; color: #991b1b; }
        textarea, select, input[type=file] { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 9px; }
        textarea { min-height: 110px; resize: vertical; }
        .btn { border: none; border-radius: 8px; padding: 9px 12px; cursor: pointer; font-weight: 700; background: #2563eb; color: #fff; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2><i class="fas fa-crown"></i> Super Admin</h2>
            <p>System Control Panel</p>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li class="nav-item"><a href="dashboard.php" class="nav-link"><span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span><span>Dashboard</span></a></li>
                <li class="nav-item"><a href="manage_schools.php" class="nav-link"><span class="nav-icon"><i class="fas fa-school"></i></span><span>Manage Schools</span></a></li>
                <li class="nav-item"><a href="support_tickets.php" class="nav-link active"><span class="nav-icon"><i class="fas fa-life-ring"></i></span><span>Support Tickets</span></a></li>
                <li class="nav-item"><a href="subscription_plans.php" class="nav-link"><span class="nav-icon"><i class="fas fa-tags"></i></span><span>Subscription Plans</span></a></li>
                <li class="nav-item"><a href="subscription_requests.php" class="nav-link"><span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span><span>Subscription Requests</span></a></li>
                <li class="nav-item"><a href="manage_users.php" class="nav-link"><span class="nav-icon"><i class="fas fa-users"></i></span><span>Manage Users</span></a></li>
                <li class="nav-item"><a href="reports.php" class="nav-link"><span class="nav-icon"><i class="fas fa-file-alt"></i></span><span>Reports</span></a></li>
                <li class="nav-item"><a href="logout.php" class="nav-link"><span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span><span>Logout</span></a></li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <div class="card">
            <a href="support_tickets.php"><i class="fas fa-arrow-left"></i> Back to Tickets</a>
            <h2><?php echo htmlspecialchars($ticket['ticket_code'] ?: ('Ticket #' . $ticket['id'])); ?></h2>
            <p><strong>School:</strong> <?php echo htmlspecialchars($ticket['school_name']); ?></p>
            <p><strong>Principal:</strong> <?php echo htmlspecialchars($ticket['principal_name'] ?: 'N/A'); ?></p>
            <p><strong>Subject:</strong> <?php echo htmlspecialchars($ticket['subject']); ?></p>
            <p><strong>Status:</strong> <?php echo htmlspecialchars(str_replace('_', ' ', $ticket['status'])); ?> | <strong>Assigned:</strong> <?php echo htmlspecialchars($ticket['assigned_name'] ?: 'Unassigned'); ?></p>
        </div>

        <?php if ($success): ?><div class="alert ok"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="alert err"><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>

        <div class="card">
            <h3>Update Status</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <select name="status" required>
                    <option value="open" <?php echo $ticket['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                    <option value="in_progress" <?php echo $ticket['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="resolved" <?php echo $ticket['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    <option value="closed" <?php echo $ticket['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
                <p><button class="btn" type="submit">Save Status</button></p>
            </form>
        </div>

        <div class="card">
            <h3>Conversation</h3>
            <?php if (empty($messages)): ?><p>No messages yet.</p><?php endif; ?>
            <?php foreach ($messages as $message): ?>
                <div class="msg <?php echo $message['sender_role'] === 'super_admin' ? 'msg-super' : 'msg-principal'; ?>">
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
                <p><textarea name="message" required placeholder="Write response to principal"></textarea></p>
                <p><input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"></p>
                <button class="btn" type="submit">Send Reply</button>
            </form>
        </div>
    </main>
</div>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
