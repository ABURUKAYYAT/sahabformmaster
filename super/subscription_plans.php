<?php
require_once 'auth_check.php';
require_once '../config/db.php';

$errors = [];
$success = '';
$edit_plan = null;
$edit_bank = null;

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subscription_bank_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bank_name VARCHAR(120) NOT NULL,
            account_name VARCHAR(120) NOT NULL,
            account_number VARCHAR(50) NOT NULL,
            payment_note TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_subscription_bank_active (is_active),
            INDEX idx_subscription_bank_primary (is_primary)
        )
    ");
} catch (Exception $e) {
    $errors[] = 'Failed to prepare subscription bank accounts table.';
}

function upsert_system_setting(PDO $pdo, $setting_key, $setting_value)
{
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    $stmt->execute([$setting_key, $setting_value]);
}

function sync_subscription_bank_settings(PDO $pdo)
{
    try {
        $stmt = $pdo->query("
            SELECT bank_name, account_name, account_number, payment_note
            FROM subscription_bank_accounts
            WHERE is_active = 1
            ORDER BY is_primary DESC, id ASC
            LIMIT 1
        ");
        $bank = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($bank) {
            upsert_system_setting($pdo, 'subscription_bank_name', $bank['bank_name']);
            upsert_system_setting($pdo, 'subscription_account_name', $bank['account_name']);
            upsert_system_setting($pdo, 'subscription_account_number', $bank['account_number']);
            upsert_system_setting($pdo, 'subscription_payment_note', $bank['payment_note'] ?? 'After transfer, upload your proof from the "My Subscription Requests" section below.');
        } else {
            upsert_system_setting($pdo, 'subscription_bank_name', '');
            upsert_system_setting($pdo, 'subscription_account_name', '');
            upsert_system_setting($pdo, 'subscription_account_number', '');
            upsert_system_setting($pdo, 'subscription_payment_note', 'After transfer, upload your proof from the "My Subscription Requests" section below.');
        }
    } catch (Exception $e) {
        // Best effort sync. Do not block plan/bank operations.
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_plan') {
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $plan_code = trim($_POST['plan_code'] ?? '');
        $billing_cycle = $_POST['billing_cycle'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        $duration_days = $_POST['duration_days'] !== '' ? (int)$_POST['duration_days'] : null;
        $grace_days = (int)($_POST['grace_days'] ?? 7);
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') $errors[] = 'Plan name is required.';
        if (!preg_match('/^[a-z0-9_]{3,40}$/', $plan_code)) $errors[] = 'Plan code must be 3-40 chars: lowercase letters, numbers, underscore.';
        if (!in_array($billing_cycle, ['monthly', 'termly', 'lifetime'], true)) $errors[] = 'Invalid billing cycle.';
        if ($amount < 0) $errors[] = 'Amount cannot be negative.';
        if ($billing_cycle !== 'lifetime' && (!$duration_days || $duration_days < 1)) $errors[] = 'Duration days is required for monthly/termly plans.';
        if ($grace_days < 0) $errors[] = 'Grace days cannot be negative.';

        $code_check_sql = $plan_id > 0
            ? "SELECT COUNT(*) FROM subscription_plans WHERE plan_code = ? AND id != ?"
            : "SELECT COUNT(*) FROM subscription_plans WHERE plan_code = ?";
        $code_check_stmt = $pdo->prepare($code_check_sql);
        $code_check_stmt->execute($plan_id > 0 ? [$plan_code, $plan_id] : [$plan_code]);
        if ((int)$code_check_stmt->fetchColumn() > 0) {
            $errors[] = 'Plan code already exists.';
        }

        if (empty($errors)) {
            if ($plan_id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE subscription_plans
                    SET name = ?, plan_code = ?, billing_cycle = ?, amount = ?, duration_days = ?, grace_days = ?, description = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $name,
                    $plan_code,
                    $billing_cycle,
                    $amount,
                    $billing_cycle === 'lifetime' ? null : $duration_days,
                    $grace_days,
                    $description !== '' ? $description : null,
                    $is_active,
                    $plan_id
                ]);
                $success = 'Plan updated successfully.';
                log_super_action('update_subscription_plan', 'subscription_plan', $plan_id, "Updated plan {$plan_code}");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO subscription_plans
                    (name, plan_code, billing_cycle, amount, duration_days, grace_days, description, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $name,
                    $plan_code,
                    $billing_cycle,
                    $amount,
                    $billing_cycle === 'lifetime' ? null : $duration_days,
                    $grace_days,
                    $description !== '' ? $description : null,
                    $is_active
                ]);
                $new_id = (int)$pdo->lastInsertId();
                $success = 'Plan created successfully.';
                log_super_action('create_subscription_plan', 'subscription_plan', $new_id, "Created plan {$plan_code}");
            }
        }
    } elseif ($action === 'toggle_plan') {
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        $new_state = (int)($_POST['new_state'] ?? 0);

        $stmt = $pdo->prepare("UPDATE subscription_plans SET is_active = ? WHERE id = ?");
        $stmt->execute([$new_state, $plan_id]);
        $success = $new_state ? 'Plan activated.' : 'Plan deactivated.';
        log_super_action('toggle_subscription_plan', 'subscription_plan', $plan_id, $success);
    } elseif ($action === 'save_bank_info') {
        $bank_id = (int)($_POST['bank_id'] ?? 0);
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_name = trim($_POST['account_name'] ?? '');
        $account_number = preg_replace('/\s+/', '', trim($_POST['account_number'] ?? ''));
        $payment_note = trim($_POST['payment_note'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $is_primary = isset($_POST['is_primary']) ? 1 : 0;

        if ($bank_name === '') $errors[] = 'Bank name is required.';
        if ($account_name === '') $errors[] = 'Account name is required.';
        if ($account_number === '') $errors[] = 'Account number is required.';
        if (strlen($account_number) > 50) $errors[] = 'Account number is too long.';
        if ($is_primary === 1 && $is_active === 0) $errors[] = 'Primary bank account must be active.';

        if (empty($errors)) {
            if ($bank_id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE subscription_bank_accounts
                    SET bank_name = ?, account_name = ?, account_number = ?, payment_note = ?, is_active = ?, is_primary = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $bank_name,
                    $account_name,
                    $account_number,
                    $payment_note !== '' ? $payment_note : null,
                    $is_active,
                    $is_primary,
                    $bank_id
                ]);
                $saved_bank_id = $bank_id;
                $success = 'Subscription bank information updated successfully.';
                log_super_action('update_subscription_bank_info', 'subscription_bank_account', $bank_id, "Updated subscription bank account {$bank_name}");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO subscription_bank_accounts
                    (bank_name, account_name, account_number, payment_note, is_active, is_primary)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $bank_name,
                    $account_name,
                    $account_number,
                    $payment_note !== '' ? $payment_note : null,
                    $is_active,
                    $is_primary
                ]);
                $saved_bank_id = (int)$pdo->lastInsertId();
                $success = 'Subscription bank information created successfully.';
                log_super_action('create_subscription_bank_info', 'subscription_bank_account', $saved_bank_id, "Created subscription bank account {$bank_name}");
            }

            if ($is_primary === 1) {
                $clear_stmt = $pdo->prepare("UPDATE subscription_bank_accounts SET is_primary = 0 WHERE id != ?");
                $clear_stmt->execute([$saved_bank_id]);
            } else {
                $primary_count_stmt = $pdo->query("SELECT COUNT(*) FROM subscription_bank_accounts WHERE is_active = 1 AND is_primary = 1");
                if ((int)$primary_count_stmt->fetchColumn() === 0) {
                    $set_primary_stmt = $pdo->prepare("UPDATE subscription_bank_accounts SET is_primary = 1 WHERE id = ?");
                    $set_primary_stmt->execute([$saved_bank_id]);
                }
            }

            sync_subscription_bank_settings($pdo);
        }
    } elseif ($action === 'toggle_bank') {
        $bank_id = (int)($_POST['bank_id'] ?? 0);
        $new_state = (int)($_POST['new_state'] ?? 0);

        if ($bank_id > 0) {
            if ($new_state === 1) {
                $stmt = $pdo->prepare("UPDATE subscription_bank_accounts SET is_active = 1 WHERE id = ?");
                $stmt->execute([$bank_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE subscription_bank_accounts SET is_active = 0, is_primary = 0 WHERE id = ?");
                $stmt->execute([$bank_id]);
            }

            $primary_count_stmt = $pdo->query("SELECT COUNT(*) FROM subscription_bank_accounts WHERE is_active = 1 AND is_primary = 1");
            if ((int)$primary_count_stmt->fetchColumn() === 0) {
                $fallback_stmt = $pdo->query("SELECT id FROM subscription_bank_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
                $fallback_id = (int)($fallback_stmt->fetchColumn() ?: 0);
                if ($fallback_id > 0) {
                    $set_primary_stmt = $pdo->prepare("UPDATE subscription_bank_accounts SET is_primary = 1 WHERE id = ?");
                    $set_primary_stmt->execute([$fallback_id]);
                }
            }

            sync_subscription_bank_settings($pdo);
            $success = $new_state ? 'Bank account activated.' : 'Bank account deactivated.';
            log_super_action('toggle_subscription_bank_info', 'subscription_bank_account', $bank_id, $success);
        }
    } elseif ($action === 'set_primary_bank') {
        $bank_id = (int)($_POST['bank_id'] ?? 0);

        if ($bank_id > 0) {
            $check_stmt = $pdo->prepare("SELECT is_active FROM subscription_bank_accounts WHERE id = ? LIMIT 1");
            $check_stmt->execute([$bank_id]);
            $bank_row = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$bank_row) {
                $errors[] = 'Bank account not found.';
            } elseif ((int)$bank_row['is_active'] !== 1) {
                $errors[] = 'Only active bank accounts can be set as primary.';
            } else {
                $pdo->exec("UPDATE subscription_bank_accounts SET is_primary = 0");
                $set_stmt = $pdo->prepare("UPDATE subscription_bank_accounts SET is_primary = 1 WHERE id = ?");
                $set_stmt->execute([$bank_id]);
                sync_subscription_bank_settings($pdo);
                $success = 'Primary subscription bank account updated.';
                log_super_action('set_primary_subscription_bank_info', 'subscription_bank_account', $bank_id, $success);
            }
        }
    } elseif ($action === 'delete_bank') {
        $bank_id = (int)($_POST['bank_id'] ?? 0);

        if ($bank_id > 0) {
            $delete_stmt = $pdo->prepare("DELETE FROM subscription_bank_accounts WHERE id = ?");
            $delete_stmt->execute([$bank_id]);

            $primary_count_stmt = $pdo->query("SELECT COUNT(*) FROM subscription_bank_accounts WHERE is_active = 1 AND is_primary = 1");
            if ((int)$primary_count_stmt->fetchColumn() === 0) {
                $fallback_stmt = $pdo->query("SELECT id FROM subscription_bank_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
                $fallback_id = (int)($fallback_stmt->fetchColumn() ?: 0);
                if ($fallback_id > 0) {
                    $set_primary_stmt = $pdo->prepare("UPDATE subscription_bank_accounts SET is_primary = 1 WHERE id = ?");
                    $set_primary_stmt->execute([$fallback_id]);
                }
            }

            sync_subscription_bank_settings($pdo);
            $success = 'Bank account deleted.';
            log_super_action('delete_subscription_bank_info', 'subscription_bank_account', $bank_id, $success);
        }
    }
}

if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM subscription_plans WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_plan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (isset($_GET['edit_bank'])) {
    $edit_bank_id = (int)$_GET['edit_bank'];
    $stmt = $pdo->prepare("SELECT * FROM subscription_bank_accounts WHERE id = ?");
    $stmt->execute([$edit_bank_id]);
    $edit_bank = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$plans = $pdo->query("SELECT * FROM subscription_plans ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$bank_accounts = $pdo->query("SELECT * FROM subscription_bank_accounts ORDER BY is_primary DESC, is_active DESC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Plans | SahabFormMaster</title>
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
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; }
        input, select, textarea { width: 100%; padding: 9px; border: 1px solid #cbd5e1; border-radius: 8px; }
        textarea { min-height: 70px; resize: vertical; }
        .btn { border: none; border-radius: 8px; padding: 9px 12px; cursor: pointer; font-weight: 700; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #0f172a; }
        .btn-danger { background: #dc2626; color: #fff; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { border-bottom: 1px solid #e2e8f0; padding: 10px; text-align: left; }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .badge-primary { background: #dbeafe; color: #1d4ed8; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-muted { background: #e2e8f0; color: #475569; }
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
                    <a href="subscription_plans.php" class="nav-link active">
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
    <main class="main-content">
        <h2><i class="fas fa-tags"></i> Subscription Plans</h2>

        <?php if ($success): ?><div class="alert ok"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="alert err"><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>

        <div class="card">
            <h3><?php echo $edit_plan ? 'Edit Plan' : 'Create Plan'; ?></h3>
            <form method="POST">
                <input type="hidden" name="action" value="save_plan">
                <input type="hidden" name="plan_id" value="<?php echo (int)($edit_plan['id'] ?? 0); ?>">
                <div class="grid">
                    <div><label>Name</label><input name="name" required value="<?php echo htmlspecialchars($edit_plan['name'] ?? ''); ?>"></div>
                    <div><label>Code</label><input name="plan_code" required value="<?php echo htmlspecialchars($edit_plan['plan_code'] ?? ''); ?>" placeholder="monthly_default"></div>
                    <div>
                        <label>Billing Cycle</label>
                        <select name="billing_cycle" required>
                            <?php $selected_cycle = $edit_plan['billing_cycle'] ?? 'monthly'; ?>
                            <option value="monthly" <?php echo $selected_cycle === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                            <option value="termly" <?php echo $selected_cycle === 'termly' ? 'selected' : ''; ?>>Termly</option>
                            <option value="lifetime" <?php echo $selected_cycle === 'lifetime' ? 'selected' : ''; ?>>Lifetime</option>
                        </select>
                    </div>
                    <div><label>Amount</label><input type="number" step="0.01" min="0" name="amount" required value="<?php echo htmlspecialchars((string)($edit_plan['amount'] ?? '0')); ?>"></div>
                    <div><label>Duration Days</label><input type="number" min="1" name="duration_days" value="<?php echo htmlspecialchars((string)($edit_plan['duration_days'] ?? '')); ?>"></div>
                    <div><label>Grace Days</label><input type="number" min="0" name="grace_days" value="<?php echo htmlspecialchars((string)($edit_plan['grace_days'] ?? 7)); ?>"></div>
                    <div style="grid-column: 1 / -1;"><label>Description</label><textarea name="description"><?php echo htmlspecialchars($edit_plan['description'] ?? ''); ?></textarea></div>
                    <div><label><input type="checkbox" name="is_active" <?php echo (($edit_plan['is_active'] ?? 1) == 1) ? 'checked' : ''; ?>> Active</label></div>
                </div>
                <p><button class="btn btn-primary" type="submit"><?php echo $edit_plan ? 'Update Plan' : 'Create Plan'; ?></button></p>
            </form>
        </div>

        <div class="card">
            <h3>All Plans</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Cycle</th>
                        <th>Amount</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plans as $plan): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($plan['name']); ?></td>
                            <td><?php echo htmlspecialchars($plan['plan_code']); ?></td>
                            <td><?php echo htmlspecialchars($plan['billing_cycle']); ?></td>
                            <td>N<?php echo number_format((float)$plan['amount'], 2); ?></td>
                            <td><?php echo $plan['duration_days'] ? (int)$plan['duration_days'] . ' days' : 'N/A'; ?></td>
                            <td><?php echo (int)$plan['is_active'] === 1 ? 'Active' : 'Inactive'; ?></td>
                            <td>
                                <a href="subscription_plans.php?edit=<?php echo (int)$plan['id']; ?>">Edit</a>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_plan">
                                    <input type="hidden" name="plan_id" value="<?php echo (int)$plan['id']; ?>">
                                    <input type="hidden" name="new_state" value="<?php echo (int)$plan['is_active'] === 1 ? 0 : 1; ?>">
                                    <button class="btn btn-gray" type="submit"><?php echo (int)$plan['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3><?php echo $edit_bank ? 'Edit Subscription Bank Information' : 'Add Subscription Bank Information'; ?></h3>
            <form method="POST">
                <input type="hidden" name="action" value="save_bank_info">
                <input type="hidden" name="bank_id" value="<?php echo (int)($edit_bank['id'] ?? 0); ?>">
                <div class="grid">
                    <div><label>Bank Name</label><input name="bank_name" required value="<?php echo htmlspecialchars($edit_bank['bank_name'] ?? ''); ?>" placeholder="First Bank"></div>
                    <div><label>Account Name</label><input name="account_name" required value="<?php echo htmlspecialchars($edit_bank['account_name'] ?? ''); ?>" placeholder="SahabFormMaster Limited"></div>
                    <div><label>Account Number</label><input name="account_number" required value="<?php echo htmlspecialchars($edit_bank['account_number'] ?? ''); ?>" placeholder="0123456789"></div>
                    <div style="grid-column: 1 / -1;"><label>Payment Note</label><textarea name="payment_note" placeholder='After transfer, upload proof from "My Subscription Requests".'><?php echo htmlspecialchars($edit_bank['payment_note'] ?? ''); ?></textarea></div>
                    <div><label><input type="checkbox" name="is_active" <?php echo (($edit_bank['is_active'] ?? 1) == 1) ? 'checked' : ''; ?>> Active</label></div>
                    <div><label><input type="checkbox" name="is_primary" <?php echo (($edit_bank['is_primary'] ?? 0) == 1) ? 'checked' : ''; ?>> Primary</label></div>
                </div>
                <p>
                    <button class="btn btn-primary" type="submit"><?php echo $edit_bank ? 'Update Bank Info' : 'Add Bank Info'; ?></button>
                    <?php if ($edit_bank): ?>
                        <a class="btn btn-gray" href="subscription_plans.php">Cancel Edit</a>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <div class="card">
            <h3>Subscription Bank Accounts</h3>
            <?php if (empty($bank_accounts)): ?>
                <p>No subscription bank account added yet.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Bank</th>
                            <th>Account Name</th>
                            <th>Account Number</th>
                            <th>Note</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bank_accounts as $bank): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($bank['bank_name']); ?></td>
                                <td><?php echo htmlspecialchars($bank['account_name']); ?></td>
                                <td><?php echo htmlspecialchars($bank['account_number']); ?></td>
                                <td><?php echo htmlspecialchars($bank['payment_note'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ((int)$bank['is_active'] === 1): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-muted">Inactive</span>
                                    <?php endif; ?>
                                    <?php if ((int)$bank['is_primary'] === 1): ?>
                                        <span class="badge badge-primary">Primary</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="subscription_plans.php?edit_bank=<?php echo (int)$bank['id']; ?>">Edit</a>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_bank">
                                        <input type="hidden" name="bank_id" value="<?php echo (int)$bank['id']; ?>">
                                        <input type="hidden" name="new_state" value="<?php echo (int)$bank['is_active'] === 1 ? 0 : 1; ?>">
                                        <button class="btn btn-gray" type="submit"><?php echo (int)$bank['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?></button>
                                    </form>
                                    <?php if ((int)$bank['is_primary'] !== 1 && (int)$bank['is_active'] === 1): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="set_primary_bank">
                                            <input type="hidden" name="bank_id" value="<?php echo (int)$bank['id']; ?>">
                                            <button class="btn btn-primary" type="submit">Set Primary</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this bank account information?');">
                                        <input type="hidden" name="action" value="delete_bank">
                                        <input type="hidden" name="bank_id" value="<?php echo (int)$bank['id']; ?>">
                                        <button class="btn btn-danger" type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
