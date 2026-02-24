<?php
require_once 'auth_check.php';
require_once '../config/db.php';
require_once '../includes/functions.php';

$errors = [];
$success = '';
$csrf_token = generate_csrf_token();

function add_subscription_audit($school_id, $request_id, $action, $message, $metadata = null) {
    global $pdo;
    try {
        $columns_stmt = $pdo->query("SHOW COLUMNS FROM school_subscription_audit_logs");
        $columns = array_map(static function ($row) {
            return $row['Field'];
        }, $columns_stmt->fetchAll(PDO::FETCH_ASSOC));

        $insert_columns = ['action'];
        $insert_values = [$action];
        if (in_array('school_id', $columns, true)) {
            $insert_columns[] = 'school_id';
            $insert_values[] = $school_id;
        }
        if (in_array('request_id', $columns, true)) {
            $insert_columns[] = 'request_id';
            $insert_values[] = $request_id;
        }
        if (in_array('actor_id', $columns, true)) {
            $insert_columns[] = 'actor_id';
            $insert_values[] = (int)($_SESSION['user_id'] ?? 0);
        }
        if (in_array('actor_role', $columns, true)) {
            $insert_columns[] = 'actor_role';
            $insert_values[] = 'super_admin';
        }
        if (in_array('message', $columns, true)) {
            $insert_columns[] = 'message';
            $insert_values[] = $message;
        }
        if (in_array('metadata_json', $columns, true)) {
            $insert_columns[] = 'metadata_json';
            $insert_values[] = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;
        }

        if (!empty($insert_columns)) {
            $placeholders = implode(', ', array_fill(0, count($insert_columns), '?'));
            $columns_sql = implode(', ', $insert_columns);
            $stmt = $pdo->prepare("INSERT INTO school_subscription_audit_logs ({$columns_sql}) VALUES ({$placeholders})");
            $stmt->execute($insert_values);
        }
    } catch (Exception $e) {
        error_log('Super subscription audit log error: ' . $e->getMessage());
    }
}

function get_table_columns($table_name)
{
    global $pdo;
    static $cache = [];

    if (isset($cache[$table_name])) {
        return $cache[$table_name];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table_name}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cache[$table_name] = array_map(static function ($row) {
            return $row['Field'];
        }, $rows);
    } catch (Exception $e) {
        $cache[$table_name] = [];
    }

    return $cache[$table_name];
}

function table_has_column($table_name, $column_name)
{
    return in_array($column_name, get_table_columns($table_name), true);
}

function table_exists($table_name)
{
    global $pdo;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table_name));
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function request_status_enum_values()
{
    global $pdo;
    static $values = null;

    if ($values !== null) {
        return $values;
    }

    $values = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM school_subscription_requests LIKE 'status'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $type = $row['Type'] ?? '';
        if (preg_match('/^enum\((.*)\)$/i', (string)$type, $matches)) {
            $parts = str_getcsv($matches[1], ',', "'");
            foreach ($parts as $part) {
                $values[] = trim((string)$part);
            }
        }
    } catch (Exception $e) {
        $values = [];
    }

    return $values;
}

function resolve_request_status($logical_status)
{
    $supported = request_status_enum_values();
    if (empty($supported)) {
        return $logical_status;
    }

    $candidates = [
        'pending_payment' => ['pending_payment', 'pending', 'open'],
        'under_review' => ['under_review', 'pending_payment', 'pending', 'open'],
        'approved' => ['approved', 'active'],
        'rejected' => ['rejected', 'declined']
    ];

    $candidate_list = $candidates[$logical_status] ?? [$logical_status];
    foreach ($candidate_list as $candidate) {
        if (in_array($candidate, $supported, true)) {
            return $candidate;
        }
    }
    return $supported[0] ?? $logical_status;
}

function request_status_matches($actual_status, $logical_statuses)
{
    $actual_status = (string)$actual_status;
    if ($actual_status === '' && count(array_intersect((array)$logical_statuses, ['pending_payment', 'under_review'])) > 0) {
        return true;
    }
    foreach ((array)$logical_statuses as $logical_status) {
        if ($actual_status === resolve_request_status($logical_status)) {
            return true;
        }
    }
    return false;
}

function update_request_review($request_id, $status_logical, $review_note, $reviewer_id)
{
    global $pdo;

    $set_parts = ["status = ?", "review_note = ?"];
    $params = [resolve_request_status($status_logical), $review_note];

    if (table_has_column('school_subscription_requests', 'reviewed_by')) {
        $set_parts[] = "reviewed_by = ?";
        $params[] = $reviewer_id;
    }
    if (table_has_column('school_subscription_requests', 'reviewed_at')) {
        // Works with DATE and DATETIME columns.
        $set_parts[] = "reviewed_at = ?";
        $params[] = date('Y-m-d H:i:s');
    }
    if (table_has_column('school_subscription_requests', 'updated_at')) {
        $set_parts[] = "updated_at = NOW()";
    }

    $params[] = $request_id;
    $sql = "UPDATE school_subscription_requests SET " . implode(', ', $set_parts) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function update_proof_status_if_supported($request_id, $status_value)
{
    global $pdo;
    if (!table_has_column('school_subscription_payment_proofs', 'status')) {
        return;
    }
    $stmt = $pdo->prepare("UPDATE school_subscription_payment_proofs SET status = ? WHERE request_id = ?");
    $stmt->execute([$status_value, $request_id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    }

    $action = $_POST['action'] ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);

    $request_stmt = $pdo->prepare("
        SELECT sr.*, sp.name AS plan_name, sp.billing_cycle, sp.duration_days, sp.grace_days, sp.amount AS plan_amount, sc.school_name
        FROM school_subscription_requests sr
        JOIN subscription_plans sp ON sp.id = sr.plan_id
        JOIN schools sc ON sc.id = sr.school_id
        WHERE sr.id = ?
        LIMIT 1
    ");
    $request_stmt->execute([$request_id]);
    $request = $request_stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($errors) && !$request) {
        $errors[] = 'Request not found.';
    } elseif (empty($errors) && !request_status_matches($request['status'], ['under_review', 'pending_payment'])) {
        $errors[] = 'Only pending/under-review requests can be processed.';
    } elseif (empty($errors) && $action === 'approve_request') {
        $review_note = trim($_POST['review_note'] ?? '');

        if (empty($errors)) {
            $pdo->beginTransaction();
            try {
                // Lock request row to prevent concurrent approval races.
                $locked_stmt = $pdo->prepare("
                    SELECT sr.*, sp.name AS plan_name, sp.billing_cycle, sp.duration_days, sp.grace_days, sp.amount AS plan_amount, sc.school_name
                    FROM school_subscription_requests sr
                    JOIN subscription_plans sp ON sp.id = sr.plan_id
                    JOIN schools sc ON sc.id = sr.school_id
                    WHERE sr.id = ?
                    FOR UPDATE
                ");
                $locked_stmt->execute([$request_id]);
                $request = $locked_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$request) {
                    throw new RuntimeException('Request not found.');
                }
                if (!request_status_matches($request['status'], ['under_review', 'pending_payment'])) {
                    throw new RuntimeException('This request has already been processed.');
                }

                $proof_stmt = $pdo->prepare("SELECT * FROM school_subscription_payment_proofs WHERE request_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
                $proof_stmt->execute([$request_id]);
                $latest_proof = $proof_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$latest_proof) {
                    throw new RuntimeException('Cannot approve request without payment proof.');
                }

                $today = date('Y-m-d');
                $status = 'active';
                $start_date = $today;
                $end_date = null;
                $grace_end_date = null;

                if (table_exists('school_subscriptions')) {
                    if ($request['billing_cycle'] === 'lifetime') {
                        $status = 'lifetime_active';
                        $start_date = $today;
                    } else {
                        $duration = (int)$request['duration_days'];
                        if ($duration < 1) {
                            $duration = $request['billing_cycle'] === 'monthly' ? 30 : 120;
                        }

                        $existing_stmt = $pdo->prepare("
                            SELECT * FROM school_subscriptions
                            WHERE school_id = ?
                            ORDER BY approved_at DESC, id DESC
                            LIMIT 1
                        ");
                        $existing_stmt->execute([$request['school_id']]);
                        $existing = $existing_stmt->fetch(PDO::FETCH_ASSOC);

                        if ($existing && !empty($existing['end_date']) && $existing['end_date'] >= $today) {
                            $start_date = date('Y-m-d', strtotime($existing['end_date'] . ' +1 day'));
                        }

                        $end_date = date('Y-m-d', strtotime($start_date . ' +' . ($duration - 1) . ' day'));
                        $grace_days = max(0, (int)$request['grace_days']);
                        $grace_end_date = date('Y-m-d', strtotime($end_date . ' +' . $grace_days . ' day'));
                        $status = 'active';
                    }

                    $sub_stmt = $pdo->prepare("
                        INSERT INTO school_subscriptions
                        (school_id, plan_id, source_request_id, status, start_date, end_date, grace_end_date, approved_by, approved_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $sub_stmt->execute([
                        $request['school_id'],
                        $request['plan_id'],
                        $request_id,
                        $status,
                        $start_date,
                        $end_date,
                        $grace_end_date,
                        $_SESSION['user_id'],
                        date('Y-m-d H:i:s')
                    ]);
                }

                update_request_review(
                    $request_id,
                    'approved',
                    $review_note !== '' ? $review_note : null,
                    (int)($_SESSION['user_id'] ?? 0)
                );

                update_proof_status_if_supported($request_id, 'approved');

                add_subscription_audit($request['school_id'], $request_id, 'request_approved', 'Super admin approved subscription request', [
                    'plan_id' => $request['plan_id'],
                    'billing_cycle' => $request['billing_cycle'],
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'status' => $status
                ]);
                log_super_action('approve_subscription_request', 'subscription_request', $request_id, "Approved request #{$request_id} for school {$request['school_name']}");

                $pdo->commit();
                $success = 'Subscription request approved successfully.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Approval failed: ' . $e->getMessage();
            }
        }
    } elseif (empty($errors) && $action === 'mark_pending') {
        $review_note = trim($_POST['review_note'] ?? '');
        if ($review_note === '') {
            $errors[] = 'Pending reason is required.';
        } else {
            $pdo->beginTransaction();
            try {
                $locked_stmt = $pdo->prepare("
                    SELECT id, status, school_id
                    FROM school_subscription_requests
                    WHERE id = ?
                    FOR UPDATE
                ");
                $locked_stmt->execute([$request_id]);
                $locked_request = $locked_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$locked_request) {
                    throw new RuntimeException('Request not found.');
                }
                if (!request_status_matches($locked_request['status'], ['under_review', 'pending_payment'])) {
                    throw new RuntimeException('This request has already been processed.');
                }

                update_request_review(
                    $request_id,
                    'pending_payment',
                    $review_note,
                    (int)($_SESSION['user_id'] ?? 0)
                );
                update_proof_status_if_supported($request_id, 'rejected');

                add_subscription_audit($request['school_id'], $request_id, 'request_marked_pending', 'Super admin marked request as pending', [
                    'reason' => $review_note
                ]);
                log_super_action('mark_pending_subscription_request', 'subscription_request', $request_id, "Marked request #{$request_id} as pending for school {$request['school_name']}");

                $pdo->commit();
                $success = 'Subscription request marked as pending.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Pending update failed: ' . $e->getMessage();
            }
        }
    } elseif (empty($errors) && $action === 'reject_request') {
        $review_note = trim($_POST['review_note'] ?? '');
        if ($review_note === '') {
            $errors[] = 'Rejection reason is required.';
        } else {
            $pdo->beginTransaction();
            try {
                $locked_stmt = $pdo->prepare("
                    SELECT id, status, school_id
                    FROM school_subscription_requests
                    WHERE id = ?
                    FOR UPDATE
                ");
                $locked_stmt->execute([$request_id]);
                $locked_request = $locked_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$locked_request) {
                    throw new RuntimeException('Request not found.');
                }
                if (!request_status_matches($locked_request['status'], ['under_review', 'pending_payment'])) {
                    throw new RuntimeException('This request has already been processed.');
                }

                update_request_review(
                    $request_id,
                    'rejected',
                    $review_note,
                    (int)($_SESSION['user_id'] ?? 0)
                );
                update_proof_status_if_supported($request_id, 'rejected');

                add_subscription_audit($request['school_id'], $request_id, 'request_rejected', 'Super admin rejected subscription request', [
                    'reason' => $review_note
                ]);
                log_super_action('reject_subscription_request', 'subscription_request', $request_id, "Rejected request #{$request_id} for school {$request['school_name']}");

                $pdo->commit();
                $success = 'Subscription request rejected.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Rejection failed: ' . $e->getMessage();
            }
        }
    }
}

$status_filter = $_GET['status'] ?? 'all';
$sql = "
    SELECT sr.*, sp.name AS plan_name, sp.billing_cycle, sc.school_name, u.full_name AS principal_name,
           p.proof_file_path, p.amount_paid, p.transfer_date, p.transfer_reference, p.bank_name, p.account_name
    FROM school_subscription_requests sr
    JOIN subscription_plans sp ON sr.plan_id = sp.id
    JOIN schools sc ON sr.school_id = sc.id
    LEFT JOIN users u ON sr.requested_by = u.id
    LEFT JOIN school_subscription_payment_proofs p ON p.id = (
        SELECT p2.id FROM school_subscription_payment_proofs p2
        WHERE p2.request_id = sr.id
        ORDER BY p2.created_at DESC, p2.id DESC
        LIMIT 1
    )
";
$params = [];
if ($status_filter !== 'all') {
    $mapped_filter = resolve_request_status($status_filter);
    $sql .= " WHERE sr.status = ?";
    $params[] = $mapped_filter;
}
$sql .= " ORDER BY sr.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Requests | SahabFormMaster</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f8fafc; color: #1f2937; }
        .dashboard-container { display: flex; min-height: 100vh; }
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e293b 0%, #334155 100%);
            color: white;
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
        }
        .sidebar-header {
            padding: 24px;
            border-bottom: 1px solid #475569;
            background: rgba(255, 255, 255, 0.05);
        }
        .sidebar-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: #f8fafc;
        }
        .sidebar-header p {
            font-size: 14px;
            color: #cbd5e1;
            margin-top: 4px;
        }
        .sidebar-nav { padding: 16px 0; }
        .nav-item { margin: 0; }
        .nav-link {
            display: flex;
            align-items: center;
            padding: 14px 24px;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            border-left-color: #3b82f6;
        }
        .nav-link.active {
            background: rgba(59, 130, 246, 0.2);
            color: #f8fafc;
            border-left-color: #3b82f6;
        }
        .nav-icon { margin-right: 12px; width: 20px; text-align: center; }
        .main-content { flex: 1; margin-left: 280px; padding: 20px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        .alert { padding: 10px 12px; border-radius: 8px; margin-bottom: 8px; }
        .ok { background: #dcfce7; color: #166534; }
        .err { background: #fee2e2; color: #991b1b; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { border-bottom: 1px solid #e2e8f0; padding: 10px; text-align: left; vertical-align: top; }
        .pill { display: inline-block; border-radius: 999px; padding: 4px 8px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .pending_payment { background: #e0f2fe; color: #075985; }
        .under_review { background: #ede9fe; color: #5b21b6; }
        .pending { background: #e0f2fe; color: #075985; }
        .approved { background: #dcfce7; color: #166534; }
        .rejected { background: #fee2e2; color: #991b1b; }
        .btn { border: none; border-radius: 8px; padding: 8px 10px; cursor: pointer; font-weight: 700; }
        .approve { background: #16a34a; color: #fff; }
        .pending { background: #d97706; color: #fff; }
        .reject { background: #dc2626; color: #fff; }
        textarea { width: 100%; min-height: 60px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px; }
        .filters a { margin-right: 8px; }
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
                    <a href="subscription_plans.php" class="nav-link">
                        <span class="nav-icon"><i class="fas fa-tags"></i></span>
                        <span>Subscription Plans</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="subscription_requests.php" class="nav-link active">
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
    <main class="main-content">
        <h2>Subscription Requests</h2>
        <div class="filters">
            <a href="?status=all">All</a>
            <a href="?status=pending_payment">Pending Payment</a>
            <a href="?status=under_review">Under Review</a>
            <a href="?status=approved">Approved</a>
            <a href="?status=rejected">Rejected</a>
        </div>
        <?php if ($success): ?><div class="alert ok"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="alert err"><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>

        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>School</th>
                        <th>Plan</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Proof</th>
                        <th>Review</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="6">No requests found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($requests as $request): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($request['school_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($request['principal_name'] ?? 'N/A'); ?></small>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($request['plan_name']); ?><br>
                                <small><?php echo htmlspecialchars(ucfirst($request['billing_cycle'])); ?></small>
                            </td>
                            <td>N<?php echo number_format((float)$request['expected_amount'], 2); ?></td>
                            <td>
                                <?php $display_status = $request['status'] !== '' ? $request['status'] : 'pending'; ?>
                                <span class="pill <?php echo htmlspecialchars($display_status); ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $display_status)); ?></span>
                            </td>
                            <td>
                                <?php if (!empty($request['proof_file_path'])): ?>
                                    <a href="view_subscription_proof.php?request_id=<?php echo (int)$request['id']; ?>" target="_blank">View Proof</a><br>
                                    <small>Date: <?php echo htmlspecialchars($request['transfer_date'] ?? ''); ?></small><br>
                                    <small>Paid: N<?php echo number_format((float)($request['amount_paid'] ?? 0), 2); ?></small><br>
                                    <small>Ref: <?php echo htmlspecialchars($request['transfer_reference'] ?? 'N/A'); ?></small><br>
                                    <small>Bank: <?php echo htmlspecialchars($request['bank_name'] ?? 'N/A'); ?></small>
                                <?php else: ?>
                                    <small>No proof uploaded</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (request_status_matches($request['status'], ['pending_payment', 'under_review'])): ?>
                                    <form method="POST" style="margin-bottom:8px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="approve_request">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                        <textarea name="review_note" placeholder="Optional approval note"></textarea>
                                        <p><button class="btn approve" type="submit">Approve</button></p>
                                    </form>
                                    <form method="POST" style="margin-bottom:8px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="mark_pending">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                        <textarea name="review_note" placeholder="Pending reason (required)" required></textarea>
                                        <p><button class="btn pending" type="submit">Mark as Pending</button></p>
                                    </form>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="reject_request">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                        <textarea name="review_note" placeholder="Rejection reason (required)" required></textarea>
                                        <p><button class="btn reject" type="submit">Reject</button></p>
                                    </form>
                                <?php else: ?>
                                    <small>Reviewed at <?php echo htmlspecialchars((string)$request['reviewed_at']); ?></small><br>
                                    <small><?php echo htmlspecialchars((string)$request['review_note']); ?></small>
                                <?php endif; ?>
                            </td>
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
