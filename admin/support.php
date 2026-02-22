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

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_ticket') {
    $category = trim($_POST['category'] ?? 'query');
    $priority = trim($_POST['priority'] ?? 'medium');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $has_attachment = isset($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (!in_array($category, ['query', 'observation', 'suggestion'], true)) {
        $errors[] = 'Invalid category selected.';
    }
    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $errors[] = 'Invalid priority selected.';
    }
    if ($subject === '') {
        $errors[] = 'Subject is required.';
    }
    if ($message === '') {
        $errors[] = 'Message is required.';
    }

    if ($has_attachment) {
        if (($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
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
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $ticket_stmt = $pdo->prepare("
                INSERT INTO support_tickets (school_id, created_by, category, priority, subject, status, last_message_at)
                VALUES (?, ?, ?, ?, ?, 'open', NOW())
            ");
            $ticket_stmt->execute([$school_id, $principal_id, $category, $priority, $subject]);
            $ticket_id = (int)$pdo->lastInsertId();

            $ticket_code = 'SUP-' . date('Y') . '-' . str_pad((string)$ticket_id, 6, '0', STR_PAD_LEFT);
            $code_stmt = $pdo->prepare("UPDATE support_tickets SET ticket_code = ? WHERE id = ?");
            $code_stmt->execute([$ticket_code, $ticket_id]);

            $attachment_path = null;
            if ($has_attachment) {
                $extension = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
                $upload_dir = "../uploads/support/{$ticket_id}/";
                if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
                    throw new RuntimeException('Failed to prepare attachment directory.');
                }

                $stored_name = 'ticket_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                $stored_path = $upload_dir . $stored_name;
                if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $stored_path)) {
                    throw new RuntimeException('Failed to save attachment file.');
                }
                $attachment_path = "uploads/support/{$ticket_id}/{$stored_name}";
            }

            $msg_stmt = $pdo->prepare("
                INSERT INTO support_ticket_messages (ticket_id, sender_id, sender_role, message, attachment_path)
                VALUES (?, ?, 'principal', ?, ?)
            ");
            $msg_stmt->execute([$ticket_id, $principal_id, $message, $attachment_path]);

            $read_stmt = $pdo->prepare("
                INSERT INTO support_ticket_reads (ticket_id, user_id, last_read_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at)
            ");
            $read_stmt->execute([$ticket_id, $principal_id]);

            $pdo->commit();
            $success = 'Support ticket created successfully.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Failed to create ticket: ' . $e->getMessage();
        }
    }
}

$status_filter = trim($_GET['status'] ?? 'all');
$allowed_statuses = ['all', 'open', 'in_progress', 'resolved', 'closed'];
if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = 'all';
}

$query = "
    SELECT st.*,
           (SELECT COUNT(*) FROM support_ticket_messages stm WHERE stm.ticket_id = st.id) AS message_count
    FROM support_tickets st
    WHERE st.school_id = ?
";
$params = [$school_id];
if ($status_filter !== 'all') {
    $query .= " AND st.status = ?";
    $params[] = $status_filter;
}
$query .= " ORDER BY st.updated_at DESC, st.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .content-wrap { padding: 24px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        .alert { padding: 10px 12px; border-radius: 8px; margin-bottom: 10px; }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
        .form-row { margin-bottom: 10px; }
        input, select, textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 9px; }
        textarea { min-height: 100px; resize: vertical; }
        .btn { border: none; border-radius: 8px; padding: 10px 14px; cursor: pointer; font-weight: 700; }
        .btn-primary { background: #2563eb; color: #fff; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; }
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
                <h2><i class="fas fa-life-ring"></i> Support Center</h2>
                <p>Send queries, observations, or suggestions to super admin.</p>
            </div>

            <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php foreach ($errors as $error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>

            <div class="card">
                <h3>Create Support Ticket</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create_ticket">
                    <div class="grid">
                        <div class="form-row">
                            <label>Category</label>
                            <select name="category" required>
                                <option value="query">Query</option>
                                <option value="observation">Observation</option>
                                <option value="suggestion">Suggestion</option>
                            </select>
                        </div>
                        <div class="form-row">
                            <label>Priority</label>
                            <select name="priority" required>
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Subject</label>
                        <input type="text" name="subject" required maxlength="255" placeholder="Short summary of your issue or suggestion">
                    </div>
                    <div class="form-row">
                        <label>Message</label>
                        <textarea name="message" required placeholder="Describe the issue/suggestion in detail"></textarea>
                    </div>
                    <div class="form-row">
                        <label>Attachment (optional)</label>
                        <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                        <small>Allowed: JPG, PNG, PDF, DOC, DOCX (max 5MB)</small>
                    </div>
                    <button class="btn btn-primary" type="submit">Submit Ticket</button>
                </form>
            </div>

            <div class="card">
                <h3>My Tickets</h3>
                <p>
                    Filter:
                    <a href="?status=all">All</a> |
                    <a href="?status=open">Open</a> |
                    <a href="?status=in_progress">In Progress</a> |
                    <a href="?status=resolved">Resolved</a> |
                    <a href="?status=closed">Closed</a>
                </p>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Messages</th>
                            <th>Updated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr><td colspan="7">No tickets found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tickets as $ticket): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($ticket['ticket_code'] ?: ('#' . $ticket['id'])); ?></strong><br>
                                <small><?php echo htmlspecialchars($ticket['subject']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars(ucfirst($ticket['category'])); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($ticket['priority'])); ?></td>
                            <td><span class="pill <?php echo htmlspecialchars($ticket['status']); ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $ticket['status'])); ?></span></td>
                            <td><?php echo (int)$ticket['message_count']; ?></td>
                            <td><?php echo htmlspecialchars($ticket['updated_at']); ?></td>
                            <td><a href="support_ticket.php?id=<?php echo (int)$ticket['id']; ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
