<?php
require_once 'auth_check.php';
require_once '../config/db.php';

// Get current super admin info
$super_admin = get_current_super_admin();

// Log access
log_super_action('view_manage_schools', 'system', null, 'Accessed school management interface');

// Handle form submissions
$errors = [];
$success = '';
$edit_school = null;

// Handle different actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_school') {
        // Add new school
        $school_name = trim($_POST['school_name'] ?? '');
        $school_code = trim($_POST['school_code'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $established_date = $_POST['established_date'] ?? '';
        $logo = trim($_POST['logo'] ?? '');
        $motto = trim($_POST['motto'] ?? '');
        $principal_name = trim($_POST['principal_name'] ?? '');

        // Validation
        if (empty($school_name)) {
            $errors[] = 'School name is required.';
        }
        if (empty($school_code)) {
            $errors[] = 'School code is required.';
        } elseif (!preg_match('/^[A-Z0-9]{3,10}$/', $school_code)) {
            $errors[] = 'School code must be 3-10 uppercase letters and numbers only.';
        } else {
            // Check if code already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM schools WHERE school_code = ?");
            $stmt->execute([$school_code]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'School code already exists.';
            }
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }

        // Handle logo upload
        $logo_path = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            if (!in_array($_FILES['logo']['type'], $allowed_types)) {
                $errors[] = 'Only JPG, PNG, and WebP images are allowed for logo.';
            } elseif ($_FILES['logo']['size'] > 5242880) { // 5MB
                $errors[] = 'Logo size must not exceed 5MB.';
            } else {
                $upload_dir = '../uploads/school_logos/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $file_name = $school_code . '_logo.' . $file_ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $file_name)) {
                    $logo_path = 'uploads/school_logos/' . $file_name;
                } else {
                    $errors[] = 'Failed to upload logo.';
                }
            }
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO schools (school_name, school_code, address, phone, email, logo, established_date, motto, principal_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->execute([$school_name, $school_code, $address, $phone, $email, $logo_path, $established_date ?: null, $motto, $principal_name]);

                log_super_action('add_school', 'school', $pdo->lastInsertId(), "Added new school: $school_name ($school_code)");
                $success = 'School added successfully.';

                // Redirect to avoid form resubmission
                header("Location: manage_schools.php?success=added");
                exit;
            } catch (Exception $e) {
                $errors[] = 'Failed to add school: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'edit_school') {
        // Edit existing school
        $school_id = (int)($_POST['school_id'] ?? 0);
        $school_name = trim($_POST['school_name'] ?? '');
        $school_code = trim($_POST['school_code'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $established_date = $_POST['established_date'] ?? '';
        $motto = trim($_POST['motto'] ?? '');
        
        $principal_name = trim($_POST['principal_name'] ?? '');

        // Validation
        if (empty($school_name)) {
            $errors[] = 'School name is required.';
        }
        if (empty($school_code)) {
            $errors[] = 'School code is required.';
        } elseif (!preg_match('/^[A-Z0-9]{3,10}$/', $school_code)) {
            $errors[] = 'School code must be 3-10 uppercase letters and numbers only.';
        } else {
            // Check if code already exists (excluding current school)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM schools WHERE school_code = ? AND id != ?");
            $stmt->execute([$school_code, $school_id]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'School code already exists.';
            }
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }

        // Handle logo upload
        $logo_path = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            if (!in_array($_FILES['logo']['type'], $allowed_types)) {
                $errors[] = 'Only JPG, PNG, and WebP images are allowed for logo.';
            } elseif ($_FILES['logo']['size'] > 5242880) { // 5MB
                $errors[] = 'Logo size must not exceed 5MB.';
            } else {
                $upload_dir = '../uploads/school_logos/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $file_name = $school_code . '_logo.' . $file_ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $file_name)) {
                    $logo_path = 'uploads/school_logos/' . $file_name;
                } else {
                    $errors[] = 'Failed to upload logo.';
                }
            }
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("UPDATE schools SET school_name = ?, school_code = ?, address = ?, phone = ?, email = ?, established_date = ?, motto = ?, principal_name = ?" .
                    ($logo_path ? ", logo = ?" : "") . " WHERE id = ?");
                $params = [$school_name, $school_code, $address, $phone, $email, $established_date ?: null, $motto, $principal_name];
                if ($logo_path) {
                    $params[] = $logo_path;
                }
                $params[] = $school_id;
                $stmt->execute($params);

                log_super_action('edit_school', 'school', $school_id, "Updated school: $school_name ($school_code)");
                $success = 'School updated successfully.';

                // Redirect to avoid form resubmission
                header("Location: manage_schools.php?success=updated");
                exit;
            } catch (Exception $e) {
                $errors[] = 'Failed to update school: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'change_status') {
        // Change school status
        $school_id = (int)($_POST['school_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';

        if (!in_array($new_status, ['active', 'inactive', 'suspended'])) {
            $errors[] = 'Invalid status.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE schools SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $school_id]);

                // Get school info for logging
                $stmt = $pdo->prepare("SELECT school_name, school_code FROM schools WHERE id = ?");
                $stmt->execute([$school_id]);
                $school = $stmt->fetch();

                log_super_action('change_school_status', 'school', $school_id, "Changed status to $new_status for: {$school['school_name']} ({$school['school_code']})");
                $success = 'School status updated successfully.';
            } catch (Exception $e) {
                $errors[] = 'Failed to update school status: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'bulk_status_change') {
        // Bulk status change
        $school_ids = $_POST['school_ids'] ?? [];
        $new_status = $_POST['bulk_status'] ?? '';

        if (empty($school_ids) || !is_array($school_ids)) {
            $errors[] = 'No schools selected.';
        } elseif (!in_array($new_status, ['active', 'inactive', 'suspended'])) {
            $errors[] = 'Invalid status.';
        } else {
            try {
                $placeholders = str_repeat('?,', count($school_ids) - 1) . '?';
                $stmt = $pdo->prepare("UPDATE schools SET status = ? WHERE id IN ($placeholders)");
                $params = array_merge([$new_status], $school_ids);
                $stmt->execute($params);

                log_super_action('bulk_change_school_status', 'system', null, "Bulk updated " . count($school_ids) . " schools to $new_status");
                $success = count($school_ids) . ' schools updated successfully.';
            } catch (Exception $e) {
                $errors[] = 'Failed to update schools: ' . $e->getMessage();
            }
        }
    }
}

// Handle GET parameters for edit mode
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_school = $stmt->fetch();
}

// Handle success messages from redirects
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'added') {
        $success = 'School added successfully.';
    } elseif ($_GET['success'] === 'updated') {
        $success = 'School updated successfully.';
    }
}

// Get schools with statistics
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$per_page = 10;
$offset = ($page - 1) * $per_page;

$query = "
    SELECT s.*,
           (SELECT COUNT(*) FROM users WHERE school_id = s.id AND is_active = 1) as user_count,
           (SELECT COUNT(*) FROM students WHERE school_id = s.id AND is_active = 1) as student_count,
           (SELECT COUNT(*) FROM classes WHERE school_id = s.id) as class_count,
           (SELECT COUNT(*) FROM subjects WHERE school_id = s.id) as subject_count
    FROM schools s
    WHERE 1=1
";

$params = [];
if ($search) {
    $query .= " AND (s.school_name LIKE ? OR s.school_code LIKE ? OR s.principal_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter) {
    $query .= " AND s.status = ?";
    $params[] = $status_filter;
}

$query .= " ORDER BY s.created_at DESC LIMIT " . (int)$offset . ", " . (int)$per_page;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$schools = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_query = "SELECT COUNT(*) FROM schools WHERE 1=1";
$count_params = [];
if ($search) {
    $count_query .= " AND (school_name LIKE ? OR school_code LIKE ? OR principal_name LIKE ?)";
    $count_params = array_merge($count_params, ["%$search%", "%$search%", "%$search%"]);
}
if ($status_filter) {
    $count_query .= " AND status = ?";
    $count_params[] = $status_filter;
}

$stmt = $pdo->prepare($count_query);
$stmt->execute($count_params);
$total_schools = $stmt->fetchColumn();
$total_pages = ceil($total_schools / $per_page);

// Get status counts for filter tabs
$stmt = $pdo->query("SELECT status, COUNT(*) as count FROM schools GROUP BY status");
$status_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Schools | SahabFormMaster</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #334155;
            overflow-x: hidden;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
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

        .sidebar-nav {
            padding: 16px 0;
        }

        .nav-item {
            margin: 0;
        }

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

        .nav-icon {
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 32px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .header h1 {
            font-size: 32px;
            font-weight: 800;
            color: #1e293b;
        }

        .header-actions {
            display: flex;
            gap: 16px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #475569;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        /* Alerts */
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        /* Filters and Search */
        .filters-section {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: white;
            color: #64748b;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .filter-tab.active,
        .filter-tab:hover {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        .search-form {
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .search-input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }

        .search-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* Schools Table */
        .schools-table {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .table-header {
            padding: 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
        }

        .bulk-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .data-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            font-size: 14px;
        }

        .data-table tbody tr:hover {
            background: #f8fafc;
        }

        .school-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .school-logo {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: cover;
        }

        .school-details h4 {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .school-meta {
            font-size: 12px;
            color: #64748b;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-suspended {
            background: #fef3c7;
            color: #92400e;
        }

        .stats-grid {
            display: flex;
            gap: 16px;
        }

        .stat-item {
            font-size: 14px;
            color: #64748b;
        }

        .stat-number {
            font-weight: 700;
            color: #1e293b;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .action-btn-edit {
            background: #3b82f6;
            color: white;
        }

        .action-btn-edit:hover {
            background: #2563eb;
        }

        .action-btn-view {
            background: #6b7280;
            color: white;
        }

        .action-btn-view:hover {
            background: #4b5563;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
        }

        .page-link {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            text-decoration: none;
            color: #64748b;
            transition: all 0.3s ease;
        }

        .page-link:hover,
        .page-link.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #64748b;
        }

        .modal-body {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .modal-footer {
            padding: 24px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 240px;
            }
            .main-content {
                margin-left: 240px;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            .search-form {
                flex-direction: column;
                width: 100%;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .table-container {
                font-size: 14px;
            }
            .data-table th,
            .data-table td {
                padding: 12px 8px;
            }
            .action-buttons {
                flex-direction: column;
                gap: 4px;
            }
        }

        /* Loading states */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 50%;
            border-top-color: #3b82f6;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
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
                        <a href="manage_schools.php" class="nav-link active">
                            <span class="nav-icon"><i class="fas fa-school"></i></span>
                            <span>Manage Schools</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="support_tickets.php" class="nav-link">
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

        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1>🏫 Manage Schools</h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i>
                        Add New School
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Dashboard
                    </a>
                </div>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Filters and Search -->
            <div class="filters-section">
                <div class="filter-tabs">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => ''])); ?>" class="filter-tab <?php echo !$status_filter ? 'active' : ''; ?>">
                        All (<?php echo array_sum($status_counts); ?>)
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'active'])); ?>" class="filter-tab <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
                        Active (<?php echo $status_counts['active'] ?? 0; ?>)
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'inactive'])); ?>" class="filter-tab <?php echo $status_filter === 'inactive' ? 'active' : ''; ?>">
                        Inactive (<?php echo $status_counts['inactive'] ?? 0; ?>)
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'suspended'])); ?>" class="filter-tab <?php echo $status_filter === 'suspended' ? 'active' : ''; ?>">
                        Suspended (<?php echo $status_counts['suspended'] ?? 0; ?>)
                    </a>
                </div>

                <form method="GET" class="search-form">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search schools by name, code, or principal..." class="search-input">
                    <?php if ($status_filter): ?>
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        Search
                    </button>
                    <?php if ($search || $status_filter): ?>
                        <a href="manage_schools.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Schools Table -->
            <div class="schools-table">
                <div class="table-header">
                    <div class="table-title">
                        Schools (<?php echo $total_schools; ?> total)
                    </div>
                    <?php if (!empty($schools)): ?>
                        <div class="bulk-actions">
                            <select id="bulk-status" class="form-control" style="width: auto;">
                                <option value="">Bulk Actions</option>
                                <option value="active">Set Active</option>
                                <option value="inactive">Set Inactive</option>
                                <option value="suspended">Set Suspended</option>
                            </select>
                            <button class="btn btn-success" onclick="applyBulkAction()" id="bulk-apply-btn" disabled>
                                <i class="fas fa-check"></i>
                                Apply
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="select-all" onchange="toggleAllCheckboxes()">
                                </th>
                                <th>School</th>
                                <th>Code</th>
                                <th>Status</th>
                                <th>Users</th>
                                <th>Students</th>
                                <th>Classes</th>
                                <th>Principal</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($schools)): ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; padding: 48px; color: #64748b;">
                                        <i class="fas fa-school" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                                        No schools found.
                                        <?php if ($search || $status_filter): ?>
                                            Try adjusting your search or filter criteria.
                                        <?php else: ?>
                                            <br><button class="btn btn-primary" onclick="openAddModal()">Add your first school</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($schools as $school): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="school-checkbox" value="<?php echo $school['id']; ?>" onchange="updateBulkButton()">
                                        </td>
                                        <td>
                                            <div class="school-info">
                                                <?php if ($school['logo']): ?>
                                                    <img src="../<?php echo htmlspecialchars($school['logo']); ?>" alt="Logo" class="school-logo">
                                                <?php else: ?>
                                                    <div class="school-logo" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                                                        <?php echo strtoupper(substr($school['school_name'], 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="school-details">
                                                    <h4><?php echo htmlspecialchars($school['school_name']); ?></h4>
                                                    <div class="school-meta">
                                                        <?php echo htmlspecialchars($school['address'] ?? 'No address'); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <code style="background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-weight: 600;">
                                                <?php echo htmlspecialchars($school['school_code']); ?>
                                            </code>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $school['status']; ?>">
                                                <?php echo ucfirst($school['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="stats-grid">
                                                <span class="stat-item">
                                                    <span class="stat-number"><?php echo number_format($school['user_count']); ?></span> users
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="stats-grid">
                                                <span class="stat-item">
                                                    <span class="stat-number"><?php echo number_format($school['student_count']); ?></span> students
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="stats-grid">
                                                <span class="stat-item">
                                                    <span class="stat-number"><?php echo number_format($school['class_count']); ?></span> classes
                                                </span>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($school['principal_name'] ?: 'Not assigned'); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($school['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn action-btn-view" onclick="viewSchoolDetails(<?php echo $school['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                    View
                                                </button>
                                                <button class="action-btn action-btn-edit" onclick="window.open('edit_school.php?id=<?php echo $school['id']; ?>', '_blank')">
                                                    <i class="fas fa-edit"></i>
                                                    Edit
                                                </button>
                                                <div class="dropdown" style="position: relative;">
                                                    <button class="action-btn action-btn-view" onclick="toggleStatusDropdown(<?php echo $school['id']; ?>)">
                                                        <i class="fas fa-cog"></i>
                                                        Status
                                                    </button>
                                                    <div id="status-dropdown-<?php echo $school['id']; ?>" class="dropdown-menu" style="display: none; position: absolute; top: 100%; right: 0; background: white; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 100; min-width: 120px;">
                                                        <button class="dropdown-item" onclick="changeStatus(<?php echo $school['id']; ?>, 'active')" style="width: 100%; padding: 8px 16px; border: none; background: none; text-align: left; cursor: pointer;">Set Active</button>
                                                        <button class="dropdown-item" onclick="changeStatus(<?php echo $school['id']; ?>, 'inactive')" style="width: 100%; padding: 8px 16px; border: none; background: none; text-align: left; cursor: pointer;">Set Inactive</button>
                                                        <button class="dropdown-item" onclick="changeStatus(<?php echo $school['id']; ?>, 'suspended')" style="width: 100%; padding: 8px 16px; border: none; background: none; text-align: left; cursor: pointer;">Set Suspended</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Add/Edit School Modal -->
    <div id="schoolModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Add New School</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="schoolForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="add_school">
                <input type="hidden" name="school_id" id="schoolId" value="">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="school_name">School Name *</label>
                            <input type="text" id="school_name" name="school_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="school_code">School Code *</label>
                            <input type="text" id="school_code" name="school_code" class="form-control" placeholder="e.g., ABC001" required>
                            <small style="color: #64748b; font-size: 12px;">3-10 uppercase letters and numbers only</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="address">Address</label>
                        <textarea id="address" name="address" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="phone">Phone</label>
                            <input type="text" id="phone" name="phone" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="established_date">Established Date</label>
                            <input type="date" id="established_date" name="established_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="principal_name">Principal Name</label>
                            <input type="text" id="principal_name" name="principal_name" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="motto">School Motto</label>
                        <textarea id="motto" name="motto" class="form-control" rows="2"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="logo">School Logo</label>
                        <input type="file" id="logo" name="logo" class="form-control" accept="image/jpeg,image/png,image/webp">
                        <small style="color: #64748b; font-size: 12px;">JPG, PNG, or WebP. Max 5MB.</small>
                        <div id="currentLogo" style="margin-top: 8px;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-save"></i>
                        <span id="submitText">Add School</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New School';
            document.getElementById('formAction').value = 'add_school';
            document.getElementById('schoolId').value = '';
            document.getElementById('submitText').textContent = 'Add School';
            document.getElementById('schoolForm').reset();
            document.getElementById('currentLogo').innerHTML = '';
            document.getElementById('schoolModal').classList.add('show');
        }



        function closeModal() {
            document.getElementById('schoolModal').classList.remove('show');
        }

        function viewSchoolDetails(id) {
            window.open(`school_details.php?id=${id}`, '_blank');
        }

        function changeStatus(id, status) {
            if (confirm(`Are you sure you want to change this school's status to "${status}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const actionInput = document.createElement('input');
                actionInput.name = 'action';
                actionInput.value = 'change_status';
                form.appendChild(actionInput);

                const idInput = document.createElement('input');
                idInput.name = 'school_id';
                idInput.value = id;
                form.appendChild(idInput);

                const statusInput = document.createElement('input');
                statusInput.name = 'new_status';
                statusInput.value = status;
                form.appendChild(statusInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        function toggleStatusDropdown(id) {
            const dropdown = document.getElementById(`status-dropdown-${id}`);
            // Close all other dropdowns
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== `status-dropdown-${id}`) {
                    menu.style.display = 'none';
                }
            });
            dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.style.display = 'none';
                });
            }
        });

        // Bulk actions
        function toggleAllCheckboxes() {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.school-checkbox');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
            updateBulkButton();
        }

        function updateBulkButton() {
            const checkboxes = document.querySelectorAll('.school-checkbox:checked');
            const bulkBtn = document.getElementById('bulk-apply-btn');
            bulkBtn.disabled = checkboxes.length === 0;
        }

        function applyBulkAction() {
            const status = document.getElementById('bulk-status').value;
            if (!status) {
                alert('Please select an action.');
                return;
            }

            const checkboxes = document.querySelectorAll('.school-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Please select schools to update.');
                return;
            }

            if (confirm(`Are you sure you want to set ${checkboxes.length} school(s) to "${status}" status?`)) {
                const schoolIds = Array.from(checkboxes).map(cb => cb.value);

                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const actionInput = document.createElement('input');
                actionInput.name = 'action';
                actionInput.value = 'bulk_status_change';
                form.appendChild(actionInput);

                schoolIds.forEach(id => {
                    const input = document.createElement('input');
                    input.name = 'school_ids[]';
                    input.value = id;
                    form.appendChild(input);
                });

                const statusInput = document.createElement('input');
                statusInput.name = 'bulk_status';
                statusInput.value = status;
                form.appendChild(statusInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Auto-generate school code suggestion
        document.getElementById('school_name').addEventListener('input', function() {
            const name = this.value;
            if (name.length >= 3 && !document.getElementById('school_code').value) {
                const code = name.substring(0, 3).toUpperCase().replace(/[^A-Z]/g, '') + '001';
                document.getElementById('school_code').value = code;
            }
        });

        // Form validation
        document.getElementById('schoolForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');

            submitBtn.disabled = true;
            submitText.textContent = 'Processing...';
            submitBtn.innerHTML = '<span class="spinner"></span> ' + submitText.textContent;
        });

        // Mobile sidebar toggle
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
        }
    </script>
</body>
</html>
