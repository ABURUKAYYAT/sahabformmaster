<?php
require_once 'auth_check.php';
require_once '../config/db.php';

$status_filter = trim($_GET['status'] ?? 'all');
$category_filter = trim($_GET['category'] ?? 'all');

$allowed_status = ['all', 'open', 'in_progress', 'resolved', 'closed'];
$allowed_category = ['all', 'query', 'observation', 'suggestion'];
if (!in_array($status_filter, $allowed_status, true)) $status_filter = 'all';
if (!in_array($category_filter, $allowed_category, true)) $category_filter = 'all';

$query = "
    SELECT st.*,
           s.school_name,
           u.full_name AS principal_name,
           (SELECT COUNT(*) FROM support_ticket_messages stm WHERE stm.ticket_id = st.id) AS message_count
    FROM support_tickets st
    JOIN schools s ON s.id = st.school_id
    LEFT JOIN users u ON u.id = st.created_by
    WHERE 1=1
";
$params = [];
if ($status_filter !== 'all') {
    $query .= " AND st.status = ?";
    $params[] = $status_filter;
}
if ($category_filter !== 'all') {
    $query .= " AND st.category = ?";
    $params[] = $category_filter;
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
    <title>Support Tickets | SahabFormMaster</title>
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
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { border-bottom: 1px solid #e2e8f0; padding: 10px; text-align: left; }
        .pill { display: inline-block; border-radius: 999px; padding: 4px 9px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .open { background: #dbeafe; color: #1e40af; }
        .in_progress { background: #ede9fe; color: #6d28d9; }
        .resolved { background: #dcfce7; color: #166534; }
        .closed { background: #e5e7eb; color: #374151; }
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
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="manage_schools.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-school"></i></span>
                        <span>Manage Schools</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="support_tickets.php" class="nav-link active">
                        <span class="nav-icon"><i class="fas fa-life-ring"></i></span>
                        <span>Support Tickets</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="subscription_plans.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-tags"></i></span>
                        <span>Subscription Plans</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="subscription_requests.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                        <span>Subscription Requests</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="manage_users.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-users"></i></span>
                        <span>Manage Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="system_settings.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-cogs"></i></span>
                        <span>System Settings</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="audit_logs.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-history"></i></span>
                        <span>Audit Logs</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="database_tools.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-database"></i></span>
                        <span>Database Tools</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="analytics.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                        <span>Analytics</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="reports.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                        <span>Reports</span>
                    </a>
                </li>
            </ul>
        </nav>
    </aside>

    <!-- Mobile Navigation -->
    <?php include '../includes/mobile_navigation.php'; ?>

    <main class="main-content">
        <div class="card">
            <h2><i class="fas fa-life-ring"></i> Support Tickets</h2>
            <p>
                Status:
                <a href="?status=all&category=<?php echo urlencode($category_filter); ?>">All</a> |
                <a href="?status=open&category=<?php echo urlencode($category_filter); ?>">Open</a> |
                <a href="?status=in_progress&category=<?php echo urlencode($category_filter); ?>">In Progress</a> |
                <a href="?status=resolved&category=<?php echo urlencode($category_filter); ?>">Resolved</a> |
                <a href="?status=closed&category=<?php echo urlencode($category_filter); ?>">Closed</a>
            </p>
            <p>
                Category:
                <a href="?status=<?php echo urlencode($status_filter); ?>&category=all">All</a> |
                <a href="?status=<?php echo urlencode($status_filter); ?>&category=query">Query</a> |
                <a href="?status=<?php echo urlencode($status_filter); ?>&category=observation">Observation</a> |
                <a href="?status=<?php echo urlencode($status_filter); ?>&category=suggestion">Suggestion</a>
            </p>
        </div>

        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>School</th>
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
                    <tr><td colspan="8">No tickets found.</td></tr>
                <?php endif; ?>
                <?php foreach ($tickets as $ticket): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($ticket['ticket_code'] ?: ('#' . $ticket['id'])); ?></strong><br>
                            <small><?php echo htmlspecialchars($ticket['subject']); ?></small><br>
                            <small>By: <?php echo htmlspecialchars($ticket['principal_name'] ?: 'Principal'); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($ticket['school_name']); ?></td>
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
    </main>
</div>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
