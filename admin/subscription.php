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

function log_subscription_action($school_id, $request_id, $action, $message, $metadata = null)
{
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

function status_badge_class($status)
{
    if (in_array($status, ['active', 'lifetime_active', 'approved'], true)) {
        return 'badge-success';
    }
    if (in_array($status, ['grace_period', 'under_review', 'pending_payment', 'pending'], true)) {
        return 'badge-warning';
    }
    if ($status === 'none') {
        return 'badge-default';
    }
    if ($status === '') {
        return 'badge-warning';
    }
    if ($status === 'open') {
        return 'badge-info';
    }
    return 'badge-danger';
}

function humanize_subscription_action($action)
{
    if ($action === 'request_approved') {
        return 'Approved';
    }
    if ($action === 'request_rejected') {
        return 'Rejected';
    }
    if ($action === 'request_marked_pending') {
        return 'Marked Pending';
    }
    if ($action === 'proof_uploaded') {
        return 'Proof Uploaded';
    }
    if ($action === 'request_created') {
        return 'Request Created';
    }
    return ucwords(str_replace('_', ' ', (string)$action));
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
        'rejected' => ['rejected', 'declined'],
        'cancelled' => ['cancelled']
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

function upload_error_message($error_code)
{
    $error_code = (int)$error_code;
    if ($error_code === UPLOAD_ERR_INI_SIZE) {
        return 'File is larger than the server upload limit. Try a smaller file.';
    }
    if ($error_code === UPLOAD_ERR_FORM_SIZE) {
        return 'File is larger than the allowed form upload size.';
    }
    if ($error_code === UPLOAD_ERR_PARTIAL) {
        return 'File upload was interrupted. Please try again.';
    }
    if ($error_code === UPLOAD_ERR_NO_FILE) {
        return 'Payment proof file is required.';
    }
    if ($error_code === UPLOAD_ERR_NO_TMP_DIR) {
        return 'Server temporary upload folder is missing.';
    }
    if ($error_code === UPLOAD_ERR_CANT_WRITE) {
        return 'Server failed to write the uploaded file.';
    }
    if ($error_code === UPLOAD_ERR_EXTENSION) {
        return 'File upload was blocked by a server extension.';
    }
    return 'File upload failed. Please try again.';
}

function upload_subscription_proof($request_id, $school_id, $principal_id, $allowed_statuses, &$errors)
{
    global $pdo;

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
    $request_stmt->execute([$request_id, $school_id]);
    $request = $request_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $errors[] = 'Subscription request not found.';
        return null;
    }
    if (!request_status_matches($request['status'], $allowed_statuses)) {
        $errors[] = 'Payment proof upload is not allowed for this request status.';
        return null;
    }
    if (empty($transfer_date)) {
        $errors[] = 'Transfer date is required.';
        return null;
    }
    if ($amount_paid <= 0) {
        $errors[] = 'Amount paid must be greater than zero.';
        return null;
    }
    if (!isset($_FILES['proof_file'])) {
        $errors[] = 'Payment proof file is required.';
        return null;
    }

    $upload_error = (int)($_FILES['proof_file']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($upload_error !== UPLOAD_ERR_OK) {
        $errors[] = upload_error_message($upload_error);
        return null;
    }

    $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
    $file_name = $_FILES['proof_file']['name'];
    $file_size = (int)$_FILES['proof_file']['size'];
    $tmp_name = $_FILES['proof_file']['tmp_name'];
    $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    if (!is_uploaded_file($tmp_name)) {
        $errors[] = 'Uploaded proof file is invalid. Please try again.';
        return null;
    }

    if (!in_array($extension, $allowed_extensions, true)) {
        $errors[] = 'Only JPG, JPEG, PNG, and PDF files are allowed.';
        return null;
    }
    if ($file_size > 5 * 1024 * 1024) {
        $errors[] = 'Payment proof file must be 5MB or smaller.';
        return null;
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)($finfo->file($tmp_name) ?: '');
    }

    $mime_allowlist = [
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png', 'image/x-png'],
        'pdf' => ['application/pdf', 'application/x-pdf', 'application/acrobat', 'applications/vnd.pdf', 'text/pdf', 'text/x-pdf', 'application/octet-stream']
    ];
    $allowed_for_extension = $mime_allowlist[$extension] ?? [];
    if ($mime !== '' && !in_array($mime, $allowed_for_extension, true)) {
        $errors[] = 'Invalid file type detected. Upload a valid JPG, PNG, or PDF.';
        return null;
    }

    $upload_dir = "../uploads/subscriptions/{$school_id}/";
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        $errors[] = 'Failed to prepare upload directory.';
        return null;
    }

    $stored_name = "proof_{$request_id}_" . time() . '_' . bin2hex(random_bytes(4)) . ".{$extension}";
    $stored_path = $upload_dir . $stored_name;
    $db_path = "uploads/subscriptions/{$school_id}/{$stored_name}";

    if (!move_uploaded_file($tmp_name, $stored_path)) {
        $errors[] = 'Failed to upload payment proof file.';
        return null;
    }

    $proof_columns = get_table_columns('school_subscription_payment_proofs');
    $insert_columns = [];
    $insert_values = [];

    $add_column = static function ($column, $value) use (&$insert_columns, &$insert_values, $proof_columns) {
        if (in_array($column, $proof_columns, true)) {
            $insert_columns[] = $column;
            $insert_values[] = $value;
        }
    };

    $add_column('request_id', $request_id);
    $add_column('uploaded_by', $principal_id);
    $add_column('transfer_date', $transfer_date);
    $add_column('amount_paid', $amount_paid);
    $add_column('transfer_reference', $transfer_reference !== '' ? $transfer_reference : '');
    $add_column('bank_name', $bank_name !== '' ? $bank_name : '');
    $add_column('account_name', $account_name !== '' ? $account_name : '');
    $add_column('note', $note !== '' ? $note : '');
    $add_column('proof_file_path', $db_path);
    $add_column('status', resolve_request_status('under_review'));

    // Legacy schema requires plan_id and points it to request table.
    if (in_array('plan_id', $proof_columns, true)) {
        $insert_columns[] = 'plan_id';
        $insert_values[] = $request_id;
    }

    if (!in_array('request_id', $insert_columns, true) || !in_array('proof_file_path', $insert_columns, true)) {
        $errors[] = 'Payment proof table structure is incomplete. Contact administrator.';
        return null;
    }

    $placeholders = implode(', ', array_fill(0, count($insert_columns), '?'));
    $columns_sql = implode(', ', $insert_columns);
    $proof_stmt = $pdo->prepare("INSERT INTO school_subscription_payment_proofs ({$columns_sql}) VALUES ({$placeholders})");
    $proof_stmt->execute($insert_values);

    $update_parts = ["status = ?", "review_note = NULL"];
    $update_params = [resolve_request_status('under_review')];
    if (table_has_column('school_subscription_requests', 'reviewed_by')) {
        $update_parts[] = "reviewed_by = NULL";
    }
    if (table_has_column('school_subscription_requests', 'updated_at')) {
        $update_parts[] = "updated_at = NOW()";
    }
    $update_params[] = $request_id;

    $update_sql = "UPDATE school_subscription_requests SET " . implode(', ', $update_parts) . " WHERE id = ?";
    $update_stmt = $pdo->prepare($update_sql);
    $update_stmt->execute($update_params);

    return [
        'amount_paid' => $amount_paid,
        'transfer_reference' => $transfer_reference
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    }

    $action = $_POST['action'] ?? '';

    if (empty($errors) && ($action === 'create_request' || $action === 'create_request_with_proof')) {
        $is_popup_submission = $action === 'create_request_with_proof';
        $plan_id = 0;
        $candidate_plan_fields = ['plan_id', 'selected_plan_id', 'plan_submit_id'];
        foreach ($candidate_plan_fields as $field_name) {
            if (!isset($_POST[$field_name])) {
                continue;
            }
            $candidate_id = (int)$_POST[$field_name];
            if ($candidate_id > 0) {
                $plan_id = $candidate_id;
                break;
            }
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
                $open_pending = resolve_request_status('pending_payment');
                $open_review = resolve_request_status('under_review');
                $open_statuses = array_values(array_unique([$open_pending, $open_review]));
                if (in_array('', request_status_enum_values(), true)) {
                    $open_statuses[] = '';
                    $open_statuses = array_values(array_unique($open_statuses));
                }
                $open_placeholders = implode(', ', array_fill(0, count($open_statuses), '?'));

                $open_stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM school_subscription_requests
                    WHERE school_id = ? AND status IN ({$open_placeholders})
                ");
                $open_stmt->execute(array_merge([$current_school_id], $open_statuses));
                $open_count = (int)$open_stmt->fetchColumn();

                if ($open_count > 0) {
                    $errors[] = 'You already have a pending subscription request. Complete or resolve it first.';
                } else {
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO school_subscription_requests
                        (school_id, plan_id, requested_by, expected_amount, status, request_note)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $insert_stmt->execute([
                        $current_school_id,
                        $plan_id,
                        $principal_id,
                        $plan['amount'],
                        resolve_request_status('pending_payment'),
                        $request_note !== '' ? $request_note : null
                    ]);
                    $request_id = (int)$pdo->lastInsertId();
                    if ($is_popup_submission) {
                        $upload_result = upload_subscription_proof(
                            $request_id,
                            $current_school_id,
                            $principal_id,
                            ['pending_payment', 'pending'],
                            $errors
                        );
                        if ($upload_result) {
                            $success = 'Subscription request and payment proof submitted successfully. Waiting for super admin verification.';
                            log_subscription_action($current_school_id, $request_id, 'request_created', 'Principal created subscription request', [
                                'plan_id' => $plan_id,
                                'plan_code' => $plan['plan_code'] ?? null,
                                'amount' => $plan['amount']
                            ]);
                            log_subscription_action($current_school_id, $request_id, 'proof_uploaded', 'Principal uploaded payment proof', [
                                'amount_paid' => $upload_result['amount_paid'],
                                'transfer_reference' => $upload_result['transfer_reference']
                            ]);
                        } else {
                            $cleanup_stmt = $pdo->prepare("DELETE FROM school_subscription_requests WHERE id = ? AND school_id = ?");
                            $cleanup_stmt->execute([$request_id, $current_school_id]);
                            if (empty($errors)) {
                                $errors[] = 'Payment proof upload failed. Request was not saved. Please try again.';
                            } else {
                                $errors[] = 'Payment proof upload failed. Request was not saved. Fix the errors above and try again.';
                            }
                        }
                    } else {
                        $success = 'Subscription request created. Upload your bank transfer proof for review.';
                        log_subscription_action($current_school_id, $request_id, 'request_created', 'Principal created subscription request', [
                            'plan_id' => $plan_id,
                            'plan_code' => $plan['plan_code'] ?? null,
                            'amount' => $plan['amount']
                        ]);
                    }
                }
            }
        }
    } elseif (empty($errors) && $action === 'upload_proof') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $upload_result = upload_subscription_proof(
            $request_id,
            $current_school_id,
            $principal_id,
            ['pending_payment', 'rejected'],
            $errors
        );
        if ($upload_result) {
            $success = 'Payment proof uploaded successfully. Waiting for super admin verification.';
            log_subscription_action($current_school_id, $request_id, 'proof_uploaded', 'Principal uploaded payment proof', [
                'amount_paid' => $upload_result['amount_paid'],
                'transfer_reference' => $upload_result['transfer_reference']
            ]);
        }
    }
}

$subscription_state = get_school_subscription_state($current_school_id);
$current_subscription = $subscription_state['record'];

$subscription_bank_info = [
    'bank_name' => 'First Bank',
    'account_name' => 'SahabFormMaster Limited',
    'account_number' => '0123456789',
    'note' => 'After transfer, upload your proof from the "My Subscription Requests" section below.'
];

try {
    $setting_stmt = $pdo->query("
        SELECT setting_key, setting_value
        FROM system_settings
        WHERE setting_key IN (
            'subscription_bank_name',
            'subscription_account_name',
            'subscription_account_number',
            'subscription_payment_note'
        )
    ");
    $setting_rows = $setting_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!empty($setting_rows['subscription_bank_name'])) {
        $subscription_bank_info['bank_name'] = $setting_rows['subscription_bank_name'];
    }
    if (!empty($setting_rows['subscription_account_name'])) {
        $subscription_bank_info['account_name'] = $setting_rows['subscription_account_name'];
    }
    if (!empty($setting_rows['subscription_account_number'])) {
        $subscription_bank_info['account_number'] = $setting_rows['subscription_account_number'];
    }
    if (!empty($setting_rows['subscription_payment_note'])) {
        $subscription_bank_info['note'] = $setting_rows['subscription_payment_note'];
    }
} catch (Exception $e) {
    // Keep defaults if settings table is unavailable.
}

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
           ) AS proof_count,
           (
               SELECT al.action
               FROM school_subscription_audit_logs al
               WHERE al.request_id = sr.id AND al.actor_role = 'super_admin'
               ORDER BY al.created_at DESC, al.id DESC
               LIMIT 1
           ) AS latest_super_action,
           (
               SELECT al.message
               FROM school_subscription_audit_logs al
               WHERE al.request_id = sr.id AND al.actor_role = 'super_admin'
               ORDER BY al.created_at DESC, al.id DESC
               LIMIT 1
           ) AS latest_super_message,
           (
               SELECT al.created_at
               FROM school_subscription_audit_logs al
               WHERE al.request_id = sr.id AND al.actor_role = 'super_admin'
               ORDER BY al.created_at DESC, al.id DESC
               LIMIT 1
           ) AS latest_super_action_at
    FROM school_subscription_requests sr
    JOIN subscription_plans sp ON sp.id = sr.plan_id
    WHERE sr.school_id = ?
    ORDER BY sr.created_at DESC
");
$requests_stmt->execute([$current_school_id]);
$requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);

$request_stats = [
    'total' => count($requests),
    'pending' => 0,
    'under_review' => 0,
    'approved' => 0,
    'rejected' => 0
];
foreach ($requests as $request_row) {
    if (request_status_matches($request_row['status'], ['pending_payment'])) {
        $request_stats['pending']++;
    } elseif (request_status_matches($request_row['status'], ['under_review'])) {
        $request_stats['under_review']++;
    } elseif ($request_row['status'] === 'approved') {
        $request_stats['approved']++;
    } elseif ($request_row['status'] === 'rejected') {
        $request_stats['rejected']++;
    }
}

$proof_error = $_GET['proof_error'] ?? '';
if ($proof_error === 'invalid_request') {
    $errors[] = 'Invalid proof request. Please try again from your subscription list.';
} elseif ($proof_error === 'not_found') {
    $errors[] = 'Payment proof not found for the selected request.';
} elseif ($proof_error === 'unavailable') {
    $errors[] = 'Payment proof file is unavailable.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Billing | SahabFormMaster</title>
    <link rel="stylesheet" href="../assets/css/teacher-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-students.css?v=1.1">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .status-badge {
            text-transform: uppercase;
        }
        .badge-default {
            background: #e5e7eb;
            color: #374151;
        }
        .badge-danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }
        .plan-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
        }
        .plan-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px;
            background: #fff;
            box-shadow: var(--shadow-sm);
        }
        .plan-card .price {
            font-size: 2rem;
            font-weight: 800;
            margin: 8px 0;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .plan-cycle {
            display: inline-block;
            font-size: 0.8rem;
            color: #475569;
            background: #e2e8f0;
            border-radius: 999px;
            padding: 4px 10px;
            text-transform: uppercase;
            font-weight: 700;
        }
        .muted {
            color: #6b7280;
            font-size: 0.9rem;
        }
        .proof-upload-box {
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 12px;
            background: #f8fafc;
            min-width: 280px;
        }
        .table td {
            vertical-align: top;
        }
        .table .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .status-hint {
            margin-top: 6px;
            color: #6b7280;
            font-size: 0.8rem;
        }
        .bank-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        .bank-info-item {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
            padding: 12px;
        }
        .bank-info-item .label {
            display: block;
            font-size: 0.78rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 4px;
            font-weight: 700;
        }
        .bank-info-item .value {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
            word-break: break-word;
        }
        .upload-proof-callout {
            margin-top: 14px;
            padding: 12px;
            border-radius: 10px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e3a8a;
        }
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 16px;
        }
        .modal-overlay.show {
            display: flex;
        }
        .modal-card {
            width: 100%;
            max-width: 760px;
            max-height: 90vh;
            overflow-y: auto;
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.2);
            padding: 18px;
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }
        .modal-plan {
            margin: 4px 0 16px;
            padding: 10px 12px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #0f172a;
            font-weight: 600;
        }
        .close-modal-btn {
            border: none;
            background: #e2e8f0;
            color: #0f172a;
            border-radius: 8px;
            padding: 8px 10px;
            cursor: pointer;
            font-weight: 700;
        }
        .super-action {
            margin-top: 8px;
            padding: 8px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #f8fafc;
            color: #334155;
            font-size: 0.82rem;
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
                <h2><i class="fas fa-credit-card"></i> Subscription Billing</h2>
                <p>Select a plan to open the request form, fill payment details, and upload proof for super admin review.</p>
            </div>
        </div>

        <div class="stats-container">
            <div class="stat-card">
                <i class="fas fa-signal"></i>
                <h3>Current Status</h3>
                <div class="count"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $subscription_state['status']))); ?></div>
                <p class="stat-description">School subscription state</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-file-invoice"></i>
                <h3>Total Requests</h3>
                <div class="count"><?php echo (int)$request_stats['total']; ?></div>
                <p class="stat-description">All subscription requests</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-hourglass-half"></i>
                <h3>Pending + Review</h3>
                <div class="count"><?php echo (int)$request_stats['pending'] + (int)$request_stats['under_review']; ?></div>
                <p class="stat-description">Requests not finalized yet</p>
            </div>
        </div>

        <?php if (!empty($_GET['notice']) && $_GET['notice'] === 'subscription_required'): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                Your school subscription is inactive. Submit or renew a plan to continue using the system.
            </div>
        <?php endif; ?>

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
                <h2><i class="fas fa-info-circle"></i> Current Subscription</h2>
            </div>
            <p>
                <span class="badge status-badge <?php echo htmlspecialchars(status_badge_class($subscription_state['status'])); ?>">
                    <?php echo htmlspecialchars(str_replace('_', ' ', $subscription_state['status'])); ?>
                </span>
            </p>

            <?php if ($current_subscription): ?>
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-layer-group"></i>Plan</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($current_subscription['plan_name']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-day"></i>Start Date</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($current_subscription['start_date']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-times"></i>End Date</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($current_subscription['end_date'] ?? 'N/A'); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-clock"></i>Grace End</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($current_subscription['grace_end_date'] ?? 'N/A'); ?>" readonly>
                    </div>
                </div>
            <?php else: ?>
                <p class="muted">No approved subscription yet.</p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-header">
                <h2><i class="fas fa-tags"></i> Select A Plan</h2>
            </div>
            <?php if (empty($plans)): ?>
                <p class="muted">No active plans are available right now.</p>
            <?php else: ?>
                <div class="plan-cards">
                    <?php foreach ($plans as $plan): ?>
                        <div class="plan-card">
                            <h3><?php echo htmlspecialchars($plan['name']); ?></h3>
                            <span class="plan-cycle"><?php echo htmlspecialchars(ucfirst($plan['billing_cycle'])); ?></span>
                            <div class="price">N<?php echo number_format((float)$plan['amount'], 2); ?></div>
                            <p class="muted"><?php echo htmlspecialchars($plan['description'] ?? ''); ?></p>
                            <button
                                class="btn primary open-plan-modal"
                                type="button"
                                data-plan-id="<?php echo (int)$plan['id']; ?>"
                                data-plan-name="<?php echo htmlspecialchars($plan['name'], ENT_QUOTES); ?>"
                                data-plan-cycle="<?php echo htmlspecialchars(ucfirst($plan['billing_cycle']), ENT_QUOTES); ?>"
                                data-plan-amount="<?php echo number_format((float)$plan['amount'], 2, '.', ''); ?>"
                            >
                                <i class="fas fa-check"></i> Select Plan
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-header">
                <h2><i class="fas fa-university"></i> Bank Information</h2>
            </div>
            <p class="muted">Use these account details for subscription transfer payment.</p>
            <div class="bank-info-grid">
                <div class="bank-info-item">
                    <span class="label">Bank Name</span>
                    <span class="value"><?php echo htmlspecialchars($subscription_bank_info['bank_name']); ?></span>
                </div>
                <div class="bank-info-item">
                    <span class="label">Account Name</span>
                    <span class="value"><?php echo htmlspecialchars($subscription_bank_info['account_name']); ?></span>
                </div>
                <div class="bank-info-item">
                    <span class="label">Account Number</span>
                    <span class="value"><?php echo htmlspecialchars($subscription_bank_info['account_number']); ?></span>
                </div>
            </div>
            <div class="upload-proof-callout">
                <i class="fas fa-upload"></i>
                <?php echo htmlspecialchars($subscription_bank_info['note']); ?>
                <a href="#subscription-requests" class="btn small primary" style="margin-left: 10px;">
                    Go to Upload Proof
                </a>
            </div>
        </section>

        <div id="planRequestModal" class="modal-overlay" aria-hidden="true">
            <div class="modal-card">
                <div class="modal-header">
                    <h3><i class="fas fa-file-invoice-dollar"></i> Complete Subscription Request</h3>
                    <button type="button" class="close-modal-btn" id="closePlanModal">Close</button>
                </div>
                <div id="selectedPlanInfo" class="modal-plan"></div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="create_request_with_proof">
                    <input type="hidden" name="plan_id" id="modal_plan_id" value="">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="modal_request_note"><i class="fas fa-sticky-note"></i>Optional Note</label>
                            <textarea id="modal_request_note" name="request_note" class="form-control" rows="3" placeholder="Optional note to super admin"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="modal_transfer_date"><i class="fas fa-calendar-day"></i>Transfer Date</label>
                            <input id="modal_transfer_date" type="date" name="transfer_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="modal_amount_paid"><i class="fas fa-money-bill-wave"></i>Amount Paid</label>
                            <input id="modal_amount_paid" type="number" name="amount_paid" min="0" step="0.01" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="modal_transfer_reference"><i class="fas fa-hashtag"></i>Transfer Reference</label>
                            <input id="modal_transfer_reference" type="text" name="transfer_reference" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="modal_bank_name"><i class="fas fa-university"></i>Your Bank Name</label>
                            <input id="modal_bank_name" type="text" name="bank_name" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="modal_account_name"><i class="fas fa-user"></i>Your Account Name</label>
                            <input id="modal_account_name" type="text" name="account_name" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="modal_proof_file"><i class="fas fa-paperclip"></i>Payment Proof</label>
                            <input id="modal_proof_file" type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
                            <div class="status-hint">JPG, PNG, PDF only (max 5MB)</div>
                        </div>
                        <div class="form-group">
                            <label for="modal_note"><i class="fas fa-comment-dots"></i>Payment Note</label>
                            <textarea id="modal_note" name="note" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <button class="btn primary" type="submit">
                        <i class="fas fa-upload"></i> Submit Request & Upload Proof
                    </button>
                </form>
            </div>
        </div>

        <section class="panel" id="subscription-requests">
            <div class="panel-header">
                <h2><i class="fas fa-list"></i> My Subscription Requests</h2>
            </div>
            <?php if (empty($requests)): ?>
                <p class="muted">No requests yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Plan</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Super Admin Update</th>
                                <th>Proof</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($request['plan_name']); ?></strong><br>
                                        <span class="muted"><?php echo htmlspecialchars(ucfirst($request['billing_cycle'])); ?></span>
                                    </td>
                                    <td>N<?php echo number_format((float)$request['expected_amount'], 2); ?></td>
                                    <td>
                                        <span class="badge status-badge <?php echo htmlspecialchars(status_badge_class($request['status'])); ?>">
                                            <?php echo htmlspecialchars(str_replace('_', ' ', $request['status'] !== '' ? $request['status'] : 'pending')); ?>
                                        </span>
                                        <?php if (!empty($request['review_note'])): ?>
                                            <div class="status-hint"><?php echo htmlspecialchars($request['review_note']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($request['latest_super_action'])): ?>
                                            <div class="super-action">
                                                <strong><?php echo htmlspecialchars(humanize_subscription_action($request['latest_super_action'])); ?></strong><br>
                                                <?php if (!empty($request['latest_super_message'])): ?>
                                                    <?php echo htmlspecialchars($request['latest_super_message']); ?><br>
                                                <?php endif; ?>
                                                <?php if (!empty($request['latest_super_action_at'])): ?>
                                                    <span class="status-hint">Updated: <?php echo htmlspecialchars((string)$request['latest_super_action_at']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="muted">No super admin action yet.</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($request['latest_proof_file'])): ?>
                                            <a href="view_subscription_proof.php?request_id=<?php echo (int)$request['id']; ?>" class="btn small secondary" target="_blank">
                                                <i class="fas fa-eye"></i> View Latest
                                            </a><br>
                                        <?php endif; ?>
                                        <span class="muted"><?php echo (int)$request['proof_count']; ?> file(s)</span>
                                    </td>
                                    <td class="actions">
                                        <?php if (request_status_matches($request['status'], ['pending_payment', 'rejected'])): ?>
                                            <form method="POST" enctype="multipart/form-data" class="proof-upload-box">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action" value="upload_proof">
                                                <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                                <div class="form-group">
                                                    <label for="transfer_date_<?php echo (int)$request['id']; ?>"><i class="fas fa-calendar-day"></i>Transfer Date</label>
                                                    <input id="transfer_date_<?php echo (int)$request['id']; ?>" type="date" name="transfer_date" class="form-control" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="amount_paid_<?php echo (int)$request['id']; ?>"><i class="fas fa-money-bill-wave"></i>Amount Paid</label>
                                                    <input id="amount_paid_<?php echo (int)$request['id']; ?>" type="number" name="amount_paid" min="0" step="0.01" class="form-control" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="transfer_reference_<?php echo (int)$request['id']; ?>"><i class="fas fa-hashtag"></i>Transfer Reference</label>
                                                    <input id="transfer_reference_<?php echo (int)$request['id']; ?>" type="text" name="transfer_reference" class="form-control">
                                                </div>
                                                <div class="form-group">
                                                    <label for="bank_name_<?php echo (int)$request['id']; ?>"><i class="fas fa-university"></i>Bank Name</label>
                                                    <input id="bank_name_<?php echo (int)$request['id']; ?>" type="text" name="bank_name" class="form-control">
                                                </div>
                                                <div class="form-group">
                                                    <label for="account_name_<?php echo (int)$request['id']; ?>"><i class="fas fa-user"></i>Account Name</label>
                                                    <input id="account_name_<?php echo (int)$request['id']; ?>" type="text" name="account_name" class="form-control">
                                                </div>
                                                <div class="form-group">
                                                    <label for="proof_file_<?php echo (int)$request['id']; ?>"><i class="fas fa-paperclip"></i>Payment Proof</label>
                                                    <input id="proof_file_<?php echo (int)$request['id']; ?>" type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
                                                    <div class="status-hint">JPG, PNG, PDF only (max 5MB)</div>
                                                </div>
                                                <div class="form-group">
                                                    <label for="note_<?php echo (int)$request['id']; ?>"><i class="fas fa-comment-dots"></i>Note</label>
                                                    <textarea id="note_<?php echo (int)$request['id']; ?>" name="note" class="form-control" rows="3"></textarea>
                                                </div>
                                                <button class="btn primary" type="submit">
                                                    <i class="fas fa-upload"></i> Upload Proof
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted">No action required.</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<script>
    (function () {
        const modal = document.getElementById('planRequestModal');
        const closeBtn = document.getElementById('closePlanModal');
        const planInfo = document.getElementById('selectedPlanInfo');
        const planIdInput = document.getElementById('modal_plan_id');
        const amountInput = document.getElementById('modal_amount_paid');
        const openButtons = document.querySelectorAll('.open-plan-modal');

        if (!modal || !closeBtn || !planInfo || !planIdInput || !amountInput || !openButtons.length) {
            return;
        }

        function openModal() {
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeModal() {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
        }

        openButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const planId = btn.getAttribute('data-plan-id') || '';
                const planName = btn.getAttribute('data-plan-name') || '';
                const planCycle = btn.getAttribute('data-plan-cycle') || '';
                const planAmount = btn.getAttribute('data-plan-amount') || '';

                planIdInput.value = planId;
                amountInput.value = planAmount;
                planInfo.textContent = `Plan: ${planName} (${planCycle}) | Amount: N${planAmount}`;
                openModal();
            });
        });

        closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal.classList.contains('show')) {
                closeModal();
            }
        });
    })();
</script>
</body>
</html>
