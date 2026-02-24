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
$form_data = [
    'category' => 'query',
    'priority' => 'medium',
    'subject' => '',
    'message' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_ticket') {
    $category = trim($_POST['category'] ?? 'query');
    $priority = trim($_POST['priority'] ?? 'medium');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $has_attachment = isset($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    $form_data['category'] = $category;
    $form_data['priority'] = $priority;
    $form_data['subject'] = $subject;
    $form_data['message'] = $message;

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
            $form_data = [
                'category' => 'query',
                'priority' => 'medium',
                'subject' => '',
                'message' => ''
            ];
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

$stats = [
    'total' => 0,
    'open' => 0,
    'in_progress' => 0,
    'resolved' => 0,
    'closed' => 0
];
$stats_stmt = $pdo->prepare("
    SELECT status, COUNT(*) AS total_count
    FROM support_tickets
    WHERE school_id = ?
    GROUP BY status
");
$stats_stmt->execute([$school_id]);
foreach ($stats_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $status_key = $row['status'];
    if (isset($stats[$status_key])) {
        $stats[$status_key] = (int)$row['total_count'];
    }
}
$stats['total'] = $stats['open'] + $stats['in_progress'] + $stats['resolved'] + $stats['closed'];

function status_badge_class($status)
{
    if ($status === 'open') {
        return 'badge-info';
    }
    if ($status === 'in_progress') {
        return 'badge-warning';
    }
    if ($status === 'resolved') {
        return 'badge-success';
    }
    return 'badge';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .support-note {
            margin-top: -6px;
            margin-bottom: 20px;
            color: #6b7280;
            font-size: 0.9rem;
        }
        .ticket-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .ticket-filters a {
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            color: #374151;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            background: #fff;
        }
        .ticket-filters a.active,
        .ticket-filters a:hover {
            color: #fff;
            border-color: transparent;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .status-badge {
            text-transform: uppercase;
        }
        .badge-default {
            background: #e5e7eb;
            color: #374151;
        }
        .ticket-subject {
            color: #6b7280;
            font-size: 0.85rem;
        }
        .ticket-date {
            white-space: nowrap;
            font-size: 0.85rem;
        }
        .btn-link {
            text-decoration: none;
        }
        .form-file-hint {
            margin-top: 6px;
            color: #6b7280;
            font-size: 0.8rem;
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
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
        </div>
    </div>
</header>

<div class="dashboard-container">
    <?php include '../includes/admin_sidebar.php'; ?>
    <main class="main-content">
        <div class="content-header">
            <div class="welcome-section">
                <h2><i class="fas fa-life-ring"></i> Support Center</h2>
                <p>Send queries, observations, and suggestions to super admin.</p>
            </div>
        </div>

        <div class="stats-container">
            <div class="stat-card">
                <i class="fas fa-ticket-alt"></i>
                <h3>Total Tickets</h3>
                <div class="count"><?php echo (int)$stats['total']; ?></div>
                <p class="stat-description">All tickets submitted by your school</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-folder-open"></i>
                <h3>Open + In Progress</h3>
                <div class="count"><?php echo (int)$stats['open'] + (int)$stats['in_progress']; ?></div>
                <p class="stat-description">Tickets currently awaiting closure</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <h3>Resolved</h3>
                <div class="count"><?php echo (int)$stats['resolved']; ?></div>
                <p class="stat-description">Tickets marked as resolved</p>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endforeach; ?>

        <section class="panel">
            <div class="panel-header">
                <h2><i class="fas fa-plus-circle"></i> Create Support Ticket</h2>
            </div>
            <p class="support-note">Use this form for questions, observations, or platform suggestions.</p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_ticket">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="category"><i class="fas fa-layer-group"></i>Category</label>
                        <select id="category" name="category" class="form-control" required>
                            <option value="query" <?php echo $form_data['category'] === 'query' ? 'selected' : ''; ?>>Query</option>
                            <option value="observation" <?php echo $form_data['category'] === 'observation' ? 'selected' : ''; ?>>Observation</option>
                            <option value="suggestion" <?php echo $form_data['category'] === 'suggestion' ? 'selected' : ''; ?>>Suggestion</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="priority"><i class="fas fa-flag"></i>Priority</label>
                        <select id="priority" name="priority" class="form-control" required>
                            <option value="low" <?php echo $form_data['priority'] === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $form_data['priority'] === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $form_data['priority'] === 'high' ? 'selected' : ''; ?>>High</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="subject"><i class="fas fa-heading"></i>Subject</label>
                    <input
                        id="subject"
                        type="text"
                        name="subject"
                        class="form-control"
                        required
                        maxlength="255"
                        placeholder="Short summary of your issue or suggestion"
                        value="<?php echo htmlspecialchars($form_data['subject']); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="message"><i class="fas fa-comment-dots"></i>Message</label>
                    <textarea
                        id="message"
                        name="message"
                        class="form-control"
                        required
                        rows="5"
                        placeholder="Describe the issue or suggestion in detail"
                    ><?php echo htmlspecialchars($form_data['message']); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="attachment"><i class="fas fa-paperclip"></i>Attachment (optional)</label>
                    <input id="attachment" type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                    <div class="form-file-hint">Allowed: JPG, PNG, PDF, DOC, DOCX (max 5MB)</div>
                </div>

                <button class="btn success" type="submit">
                    <i class="fas fa-paper-plane"></i>
                    Submit Ticket
                </button>
            </form>
        </section>

        <section class="panel">
            <div class="panel-header">
                <h2><i class="fas fa-list"></i> My Tickets</h2>
            </div>
            <div class="ticket-filters">
                <a href="?status=all" class="<?php echo $status_filter === 'all' ? 'active' : ''; ?>">All</a>
                <a href="?status=open" class="<?php echo $status_filter === 'open' ? 'active' : ''; ?>">Open</a>
                <a href="?status=in_progress" class="<?php echo $status_filter === 'in_progress' ? 'active' : ''; ?>">In Progress</a>
                <a href="?status=resolved" class="<?php echo $status_filter === 'resolved' ? 'active' : ''; ?>">Resolved</a>
                <a href="?status=closed" class="<?php echo $status_filter === 'closed' ? 'active' : ''; ?>">Closed</a>
            </div>
            <br>

            <div class="table-wrap">
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
                        <tr>
                            <td colspan="7" class="text-center">
                                <i class="fas fa-inbox"></i> No tickets found for this filter.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($tickets as $ticket): ?>
                        <?php
                        $status_text = str_replace('_', ' ', $ticket['status']);
                        $status_class = status_badge_class($ticket['status']);
                        if ($ticket['status'] === 'closed') {
                            $status_class = 'badge-default';
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($ticket['ticket_code'] ?: ('#' . $ticket['id'])); ?></strong><br>
                                <span class="ticket-subject"><?php echo htmlspecialchars($ticket['subject']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars(ucfirst($ticket['category'])); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($ticket['priority'])); ?></td>
                            <td>
                                <span class="badge status-badge <?php echo htmlspecialchars($status_class); ?>">
                                    <?php echo htmlspecialchars($status_text); ?>
                                </span>
                            </td>
                            <td><?php echo (int)$ticket['message_count']; ?></td>
                            <td class="ticket-date"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($ticket['updated_at']))); ?></td>
                            <td>
                                <a href="support_ticket.php?id=<?php echo (int)$ticket['id']; ?>" class="btn small secondary btn-link">
                                    <i class="fas fa-external-link-alt"></i> Open
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
