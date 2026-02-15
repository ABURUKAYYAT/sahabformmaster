<?php
require_once 'auth_check.php';
require_once '../config/db.php';

// Get current super admin info
$super_admin = get_current_super_admin();

// Log access
log_super_action('view_edit_school', 'system', null, 'Accessed school edit page');

// Get school ID from URL
$school_id = (int)($_GET['id'] ?? 0);

if ($school_id <= 0) {
    header('Location: manage_schools.php?error=invalid_id');
    exit;
}

// Load school data
$stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$school) {
    header('Location: manage_schools.php?error=school_not_found');
    exit;
}

// Handle form submission
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_school') {
        // Update existing school
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
        $logo_path = $school['logo']; // Keep existing logo by default
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
                    ($logo_path !== $school['logo'] ? ", logo = ?" : "") . " WHERE id = ?");
                $params = [$school_name, $school_code, $address, $phone, $email, $established_date ?: null, $motto, $principal_name];
                if ($logo_path !== $school['logo']) {
                    $params[] = $logo_path;
                }
                $params[] = $school_id;
                $stmt->execute($params);

                log_super_action('edit_school', 'school', $school_id, "Updated school: $school_name ($school_code)");
                $success = 'School updated successfully.';

                // Reload school data
                $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
                $stmt->execute([$school_id]);
                $school = $stmt->fetch(PDO::FETCH_ASSOC);

            } catch (Exception $e) {
                $errors[] = 'Failed to update school: ' . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit School | SahabFormMaster</title>
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

        /* Edit Form */
        .edit-form {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 32px;
        }

        .form-section {
            margin-bottom: 32px;
        }

        .form-section h3 {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
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

        .current-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 12px;
        }

        .logo-preview {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
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
            .form-row {
                grid-template-columns: 1fr;
            }
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
                <h1>✏️ Edit School</h1>
                <div class="header-actions">
                    <a href="manage_schools.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Schools
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

            <form class="edit-form" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_school">

                <div class="form-section">
                    <h3>Basic Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="school_name">School Name *</label>
                            <input type="text" id="school_name" name="school_name" class="form-control" value="<?php echo htmlspecialchars($school['school_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="school_code">School Code *</label>
                            <input type="text" id="school_code" name="school_code" class="form-control" value="<?php echo htmlspecialchars($school['school_code']); ?>" placeholder="e.g., ABC001" required>
                            <small style="color: #64748b; font-size: 12px;">3-10 uppercase letters and numbers only</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="address">Address</label>
                        <textarea id="address" name="address" class="form-control" rows="3"><?php echo htmlspecialchars($school['address'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Contact Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="phone">Phone</label>
                            <input type="text" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($school['phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($school['email'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Additional Details</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="established_date">Established Date</label>
                            <input type="date" id="established_date" name="established_date" class="form-control" value="<?php echo htmlspecialchars($school['established_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="principal_name">Principal Name</label>
                            <input type="text" id="principal_name" name="principal_name" class="form-control" value="<?php echo htmlspecialchars($school['principal_name'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="motto">School Motto</label>
                        <textarea id="motto" name="motto" class="form-control" rows="2"><?php echo htmlspecialchars($school['motto'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="form-section">
                    <h3>School Logo</h3>
                    <?php if ($school['logo']): ?>
                        <div class="current-logo">
                            <img src="../<?php echo htmlspecialchars($school['logo']); ?>" alt="Current logo" class="logo-preview">
                            <div>
                                <strong>Current Logo</strong><br>
                                <small style="color: #64748b;">Upload a new logo to replace the current one</small>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label" for="logo"><?php echo $school['logo'] ? 'Replace Logo' : 'Upload Logo'; ?></label>
                        <input type="file" id="logo" name="logo" class="form-control" accept="image/jpeg,image/png,image/webp">
                        <small style="color: #64748b; font-size: 12px;">JPG, PNG, or WebP. Max 5MB. <?php echo $school['logo'] ? 'Leave empty to keep current logo.' : ''; ?></small>
                    </div>
                </div>

                <div class="form-section">
                    <div style="display: flex; gap: 16px; justify-content: flex-end;">
                        <a href="manage_schools.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Update School
                        </button>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <script>
        // Auto-generate school code suggestion
        document.getElementById('school_name').addEventListener('input', function() {
            const name = this.value;
            if (name.length >= 3 && !document.getElementById('school_code').value) {
                const code = name.substring(0, 3).toUpperCase().replace(/[^A-Z]/g, '') + '001';
                document.getElementById('school_code').value = code;
            }
        });
    </script>
</body>
</html>
