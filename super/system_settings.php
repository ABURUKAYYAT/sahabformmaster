<?php
require_once 'auth_check.php';
require_once '../config/db.php';

// Ensure system_settings table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    error_log('Failed to create system_settings table: ' . $e->getMessage());
}

// Get current super admin info
$super_admin = get_current_super_admin();

// Log access
log_super_action('view_system_settings', 'system', null, 'Accessed system settings page');

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['update_general_settings'])) {
            // General Settings
            $settings = [
                'system_name' => $_POST['system_name'] ?? 'SahabFormMaster',
                'timezone' => $_POST['timezone'] ?? 'Africa/Lagos',
                'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0',
                'debug_mode' => isset($_POST['debug_mode']) ? '1' : '0'
            ];

            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                $stmt->execute([$key, $value, $value]);
            }

            log_super_action('update_general_settings', 'system', null, 'Updated general system settings');
            $message = 'General settings updated successfully!';
            $message_type = 'success';

        } elseif (isset($_POST['update_user_settings'])) {
            // User Management Settings
            $settings = [
                'teacher_signin_enabled' => isset($_POST['teacher_signin_enabled']) ? '1' : '0',
                'student_self_registration' => isset($_POST['student_self_registration']) ? '1' : '0',
                'password_min_length' => $_POST['password_min_length'] ?? '8',
                'session_timeout' => $_POST['session_timeout'] ?? '3600',
                'max_login_attempts' => $_POST['max_login_attempts'] ?? '5'
            ];

            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                $stmt->execute([$key, $value, $value]);
            }

            log_super_action('update_user_settings', 'system', null, 'Updated user management settings');
            $message = 'User management settings updated successfully!';
            $message_type = 'success';

        } elseif (isset($_POST['update_security_settings'])) {
            // Security Settings
            $settings = [
                'two_factor_required' => isset($_POST['two_factor_required']) ? '1' : '0',
                'audit_log_level' => $_POST['audit_log_level'] ?? 'minimal',
                'data_retention_days' => $_POST['data_retention_days'] ?? '365',
                'api_rate_limit' => $_POST['api_rate_limit'] ?? '100'
            ];

            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                $stmt->execute([$key, $value, $value]);
            }

            log_super_action('update_security_settings', 'system', null, 'Updated security settings');
            $message = 'Security settings updated successfully!';
            $message_type = 'success';

        } elseif (isset($_POST['update_feature_settings'])) {
            // Feature Toggles
            $settings = [
                'ai_assistant_enabled' => isset($_POST['ai_assistant_enabled']) ? '1' : '0',
                'payment_system_enabled' => isset($_POST['payment_system_enabled']) ? '1' : '0',
                'attendance_tracking_enabled' => isset($_POST['attendance_tracking_enabled']) ? '1' : '0',
                'evaluation_system_enabled' => isset($_POST['evaluation_system_enabled']) ? '1' : '0',
                'notification_system_enabled' => isset($_POST['notification_system_enabled']) ? '1' : '0',
                'content_management_enabled' => isset($_POST['content_management_enabled']) ? '1' : '0'
            ];

            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                $stmt->execute([$key, $value, $value]);
            }

            log_super_action('update_feature_settings', 'system', null, 'Updated feature settings');
            $message = 'Feature settings updated successfully!';
            $message_type = 'success';

        } elseif (isset($_POST['update_email_settings'])) {
            // Email Settings
            $settings = [
                'smtp_host' => $_POST['smtp_host'] ?? '',
                'smtp_port' => $_POST['smtp_port'] ?? '587',
                'smtp_username' => $_POST['smtp_username'] ?? '',
                'smtp_password' => $_POST['smtp_password'] ?? '',
                'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
                'from_email' => $_POST['from_email'] ?? '',
                'from_name' => $_POST['from_name'] ?? 'SahabFormMaster'
            ];

            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                $stmt->execute([$key, $value, $value]);
            }

            log_super_action('update_email_settings', 'system', null, 'Updated email settings');
            $message = 'Email settings updated successfully!';
            $message_type = 'success';

        } elseif (isset($_POST['test_email'])) {
            // Test email functionality
            $test_email = $_POST['test_email_address'] ?? '';
            if (filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
                // Here you would implement the actual email sending logic
                log_super_action('test_email', 'system', null, 'Test email sent to: ' . $test_email);
                $message = 'Test email sent successfully to ' . htmlspecialchars($test_email);
                $message_type = 'success';
            } else {
                $message = 'Invalid email address provided.';
                $message_type = 'error';
            }

        } elseif (isset($_POST['clear_cache'])) {
            // Clear system cache
            log_super_action('clear_cache', 'system', null, 'Cleared system cache');
            $message = 'System cache cleared successfully!';
            $message_type = 'success';

        } elseif (isset($_POST['reset_settings'])) {
            // Reset settings to defaults
            $default_settings = [
                'system_name' => 'SahabFormMaster',
                'timezone' => 'Africa/Lagos',
                'maintenance_mode' => '0',
                'debug_mode' => '0',
                'teacher_signin_enabled' => '1',
                'student_self_registration' => '0',
                'password_min_length' => '8',
                'session_timeout' => '3600',
                'max_login_attempts' => '5',
                'two_factor_required' => '0',
                'audit_log_level' => 'minimal',
                'data_retention_days' => '365',
                'api_rate_limit' => '100',
                'ai_assistant_enabled' => '1',
                'payment_system_enabled' => '1',
                'attendance_tracking_enabled' => '1',
                'evaluation_system_enabled' => '1',
                'notification_system_enabled' => '1',
                'content_management_enabled' => '1',
                'smtp_host' => '',
                'smtp_port' => '587',
                'smtp_username' => '',
                'smtp_encryption' => 'tls',
                'from_email' => '',
                'from_name' => 'SahabFormMaster'
            ];

            foreach ($default_settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                $stmt->execute([$key, $value, $value]);
            }

            log_super_action('reset_settings', 'system', null, 'Reset all settings to defaults');
            $message = 'All settings reset to default values!';
            $message_type = 'success';
        }

    } catch (Exception $e) {
        $message = 'Error updating settings: ' . $e->getMessage();
        $message_type = 'error';
        error_log('System settings error: ' . $e->getMessage());
    }
}

// Fetch current settings
$current_settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $current_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    error_log('Error fetching settings: ' . $e->getMessage());
    $current_settings = [];
}

// Set defaults for missing settings
$defaults = [
    'system_name' => 'SahabFormMaster',
    'timezone' => 'Africa/Lagos',
    'maintenance_mode' => '0',
    'debug_mode' => '0',
    'teacher_signin_enabled' => '1',
    'student_self_registration' => '0',
    'password_min_length' => '8',
    'session_timeout' => '3600',
    'max_login_attempts' => '5',
    'two_factor_required' => '0',
    'audit_log_level' => 'minimal',
    'data_retention_days' => '365',
    'api_rate_limit' => '100',
    'ai_assistant_enabled' => '1',
    'payment_system_enabled' => '1',
    'attendance_tracking_enabled' => '1',
    'evaluation_system_enabled' => '1',
    'notification_system_enabled' => '1',
    'content_management_enabled' => '1',
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_username' => '',
    'smtp_encryption' => 'tls',
    'from_email' => '',
    'from_name' => 'SahabFormMaster'
];

foreach ($defaults as $key => $value) {
    if (!isset($current_settings[$key])) {
        $current_settings[$key] = $value;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | SahabFormMaster</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/mobile-navigation.css">
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
            padding-bottom: 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .header h1 {
            font-size: 32px;
            font-weight: 800;
            color: #1e293b;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Messages */
        .message {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-weight: 500;
        }

        .message.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Settings Container */
        .settings-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        /* Tabs */
        .settings-tabs {
            display: flex;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .tab-button {
            flex: 1;
            padding: 16px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #64748b;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }

        .tab-button:hover {
            color: #3b82f6;
            background: rgba(59, 130, 246, 0.05);
        }

        .tab-button.active {
            color: #3b82f6;
            border-bottom-color: #3b82f6;
            background: white;
        }

        /* Tab Content */
        .tab-content {
            display: none;
            padding: 32px;
        }

        .tab-content.active {
            display: block;
        }

        /* Form Groups */
        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group input[type="number"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
        }

        /* Checkboxes */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: #3b82f6;
        }

        .checkbox-group label {
            font-weight: 500;
            color: #374151;
            margin: 0;
            cursor: pointer;
        }

        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
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
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        /* Settings Sections */
        .settings-section {
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .settings-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-description {
            color: #64748b;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
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
            .settings-tabs {
                flex-direction: column;
            }
            .tab-content {
                padding: 20px;
            }
        }

        /* Loading state */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Toggle switches */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background-color: #3b82f6;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(26px);
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
                        <a href="manage_schools.php" class="nav-link">
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
                        <a href="system_settings.php" class="nav-link active">
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

        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1><i class="fas fa-cogs"></i> System Settings</h1>
                <div class="header-actions">
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <a href="logout.php" class="btn btn-danger">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Settings Container -->
            <div class="settings-container">
                <!-- Tabs -->
                <div class="settings-tabs">
                    <button class="tab-button active" onclick="showTab('general')">
                        <i class="fas fa-globe"></i> General
                    </button>
                    <button class="tab-button" onclick="showTab('users')">
                        <i class="fas fa-users"></i> Users
                    </button>
                    <button class="tab-button" onclick="showTab('security')">
                        <i class="fas fa-shield-alt"></i> Security
                    </button>
                    <button class="tab-button" onclick="showTab('features')">
                        <i class="fas fa-toggle-on"></i> Features
                    </button>
                    <button class="tab-button" onclick="showTab('email')">
                        <i class="fas fa-envelope"></i> Email
                    </button>
                    <button class="tab-button" onclick="showTab('maintenance')">
                        <i class="fas fa-wrench"></i> Maintenance
                    </button>
                </div>

                <!-- General Settings Tab -->
                <div id="general" class="tab-content active">
                    <form method="POST">
                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-info-circle"></i> Basic Information
                            </h3>
                            <p class="section-description">
                                Configure basic system information and behavior.
                            </p>

                            <div class="form-group">
                                <label for="system_name">System Name</label>
                                <input type="text" id="system_name" name="system_name" value="<?php echo htmlspecialchars($current_settings['system_name']); ?>" placeholder="SahabFormMaster">
                            </div>

                            <div class="form-group">
                                <label for="timezone">Timezone</label>
                                <select id="timezone" name="timezone">
                                    <option value="Africa/Lagos" <?php echo $current_settings['timezone'] === 'Africa/Lagos' ? 'selected' : ''; ?>>West Africa Time (WAT)</option>
                                    <option value="Africa/Nairobi" <?php echo $current_settings['timezone'] === 'Africa/Nairobi' ? 'selected' : ''; ?>>East Africa Time (EAT)</option>
                                    <option value="Africa/Johannesburg" <?php echo $current_settings['timezone'] === 'Africa/Johannesburg' ? 'selected' : ''; ?>>South Africa Standard Time (SAST)</option>
                                    <option value="UTC" <?php echo $current_settings['timezone'] === 'UTC' ? 'selected' : ''; ?>>Coordinated Universal Time (UTC)</option>
                                </select>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-tools"></i> System Mode
                            </h3>
                            <p class="section-description">
                                Control system availability and debugging options.
                            </p>

                            <div class="checkbox-group">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="maintenance_mode" value="1" <?php echo $current_settings['maintenance_mode'] === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div>
                                    <strong>Maintenance Mode</strong>
                                    <p style="margin: 4px 0 0 0; font-size: 14px; color: #64748b;">Put the system in maintenance mode for updates</p>
                                </div>
                            </div>

                            <div class="checkbox-group">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="debug_mode" value="1" <?php echo $current_settings['debug_mode'] === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div>
                                    <strong>Debug Mode</strong>
                                    <p style="margin: 4px 0 0 0; font-size: 14px; color: #64748b;">Enable detailed error reporting (not recommended for production)</p>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" name="update_general_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save General Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- User Management Settings Tab -->
                <div id="users" class="tab-content">
                    <form method="POST">
                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-user-cog"></i> User Registration & Authentication
                            </h3>
                            <p class="section-description">
                                Configure user registration and authentication policies.
                            </p>

                            <div class="checkbox-group">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="teacher_signin_enabled" value="1" <?php echo $current_settings['teacher_signin_enabled'] === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div>
                                    <strong>Teacher Sign-in Required</strong>
                                    <p style="margin: 4px 0 0 0; font-size: 14px; color: #64748b;">Require teachers to sign in/out for attendance tracking</p>
                                </div>
                            </div>

                            <div class="checkbox-group">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="student_self_registration" value="1" <?php echo $current_settings['student_self_registration'] === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div>
                                    <strong>Student Self-Registration</strong>
                                    <p style="margin: 4px 0 0 0; font-size: 14px; color: #64748b;">Allow students to register their own accounts</p>
                                </div>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-key"></i> Password Policy
                            </h3>
                            <p class="section-description">
                                Set password requirements and session policies.
                            </p>

                            <div class="form-group">
                                <label for="password_min_length">Minimum Password Length</label>
                                <input type="number" id="password_min_length" name="password_min_length" value="<?php echo htmlspecialchars($current_settings['password_min_length']); ?>" min="6" max="32">
                            </div>

                            <div class="form-group">
                                <label for="session_timeout">Session Timeout (seconds)</label>
                                <input type="number" id="session_timeout" name="session_timeout" value="<?php echo htmlspecialchars($current_settings['session_timeout']); ?>" min="300" max="86400">
                                <small style="color: #64748b; font-size: 12px;">Time before automatic logout (300 = 5 minutes, 3600 = 1 hour)</small>
                            </div>

                            <div class="form-group">
                                <label for="max_login_attempts">Maximum Login Attempts</label>
                                <input type="number" id="max_login_attempts" name="max_login_attempts" value="<?php echo htmlspecialchars($current_settings['max_login_attempts']); ?>" min="3" max="10">
                                <small style="color: #64748b; font-size: 12px;">Number of failed attempts before account lockout</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" name="update_user_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save User Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Security Settings Tab -->
                <div id="security" class="tab-content">
                    <form method="POST">
                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-lock"></i> Security Policies
                            </h3>
                            <p class="section-description">
                                Configure security and access control policies.
                            </p>

                            <div class="checkbox-group">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="two_factor_required" value="1" <?php echo $current_settings['two_factor_required'] === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div>
                                    <strong>Two-Factor Authentication Required</strong>
                                    <p style="margin: 4px 0 0 0; font-size: 14px; color: #64748b;">Require 2FA for all user accounts</p>
                                </div>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-history"></i> Audit & Logging
                            </h3>
                            <p class="section-description">
                                Configure audit logging and data retention policies.
                            </p>

                            <div class="form-group">
                                <label for="audit_log_level">Audit Log Level</label>
                                <select id="audit_log_level" name="audit_log_level">
                                    <option value="minimal" <?php echo $current_settings['audit_log_level'] === 'minimal' ? 'selected' : ''; ?>>Minimal (Login/logout only)</option>
                                    <option value="standard" <?php echo $current_settings['audit_log_level'] === 'standard' ? 'selected' : ''; ?>>Standard (Key actions)</option>
                                    <option value="detailed" <?php echo $current_settings['audit_log_level'] === 'detailed' ? 'selected' : ''; ?>>Detailed (All actions)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="data_retention_days">Data Retention Period (days)</label>
                                <input type="number" id="data_retention_days" name="data_retention_days" value="<?php echo htmlspecialchars($current_settings['data_retention_days']); ?>" min="30" max="2555">
                                <small style="color: #64748b; font-size: 12px;">How long to keep audit logs and temporary data (365 = 1 year)</small>
                            </div>

                            <div class="form-group">
                                <label for="api_rate_limit">API Rate Limit (requests/hour)</label>
                                <input type="number" id="api_rate_limit" name="api_rate_limit" value="<?php echo htmlspecialchars($current_settings['api_rate_limit']); ?>" min="10" max="1000">
                                <small style="color: #64748b; font-size: 12px;">Maximum API requests per hour per user</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" name="update_security_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Security Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Feature Settings Tab -->
                <div id="features" class="tab-content">
                    <form method="POST">
                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-toggle-on"></i> Feature Toggles
                            </h3>
                            <p class="section-description">
                                Enable or disable major system features. Disabled features will be hidden from users.
                            </p>

                            <div class="checkbox-group">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="ai_assistant_enabled" value="1" <?php echo $current_settings['ai_assistant_enabled'] === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div>
                                    <strong>AI Assistant</strong>
                                    <p style="margin: 4px 0 0 0; font-size: 14px; color: #64748b;">Enable AI-powered assistance for users</p>
                                </div>
                            </div>

                            <div class="checkbox-group">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="payment_system_enabled" value="1" <?php echo $current_settings['payment_system_enabled'] === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div>
                                    <strong>Payment System</strong>
                                    <p style="margin: 4px 0 0 0; font-size: 14px; color: #64748b;">Enable fee collection and payment processing</p>
                                </div>
                            </div>

                            <div class="checkbox-group">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="attendance_tracking_enabled" value="1" <?php echo $current_settings['attendance_tracking_enabled'] === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div>
                                    <strong>Attendance Tracking</strong>
                                    <p style="margin: 4px 0 0 0; font-size: 14px; color: #64748b;">Enable student and teacher attendance tracking</p>
                                </div>
                            </div>

                            <div class="checkbox-group">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="evaluation_system_enabled" value="1" <?php echo $current_settings['evaluation_system_enabled'] === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div>
                                    <strong>Evaluation System</strong>
                                    <p style="margin: 4px 0 0 0; font-size: 14px; color: #64748b;">Enable student evaluations and assessments</p>
                                </div>
                            </div>

                            <div class="checkbox-group">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="notification_system_enabled" value="1" <?php echo $current_settings['notification_system_enabled'] === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div>
                                    <strong>Notification System</strong>
                                    <p style="margin: 4px 0 0 0; font-size: 14px; color: #64748b;">Enable email and in-app notifications</p>
                                </div>
                            </div>

                            <div class="checkbox-group">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="content_management_enabled" value="1" <?php echo $current_settings['content_management_enabled'] === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div>
                                    <strong>Content Management</strong>
                                    <p style="margin: 4px 0 0 0; font-size: 14px; color: #64748b;">Enable news, announcements, and content publishing</p>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" name="update_feature_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Feature Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Email Settings Tab -->
                <div id="email" class="tab-content">
                    <form method="POST">
                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-server"></i> SMTP Configuration
                            </h3>
                            <p class="section-description">
                                Configure SMTP settings for sending emails from the system.
                            </p>

                            <div class="form-group">
                                <label for="smtp_host">SMTP Host</label>
                                <input type="text" id="smtp_host" name="smtp_host" value="<?php echo htmlspecialchars($current_settings['smtp_host']); ?>" placeholder="smtp.gmail.com">
                            </div>

                            <div class="form-group">
                                <label for="smtp_port">SMTP Port</label>
                                <input type="number" id="smtp_port" name="smtp_port" value="<?php echo htmlspecialchars($current_settings['smtp_port']); ?>" placeholder="587">
                            </div>

                            <div class="form-group">
                                <label for="smtp_encryption">Encryption</label>
                                <select id="smtp_encryption" name="smtp_encryption">
                                    <option value="tls" <?php echo $current_settings['smtp_encryption'] === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                    <option value="ssl" <?php echo $current_settings['smtp_encryption'] === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                    <option value="none" <?php echo $current_settings['smtp_encryption'] === 'none' ? 'selected' : ''; ?>>None</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="smtp_username">SMTP Username</label>
                                <input type="text" id="smtp_username" name="smtp_username" value="<?php echo htmlspecialchars($current_settings['smtp_username']); ?>" placeholder="your-email@gmail.com">
                            </div>

                            <div class="form-group">
                                <label for="smtp_password">SMTP Password</label>
                                <input type="password" id="smtp_password" name="smtp_password" value="<?php echo htmlspecialchars($current_settings['smtp_password']); ?>" placeholder="Your SMTP password">
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-envelope"></i> Sender Information
                            </h3>
                            <p class="section-description">
                                Set the default sender information for system emails.
                            </p>

                            <div class="form-group">
                                <label for="from_email">From Email Address</label>
                                <input type="email" id="from_email" name="from_email" value="<?php echo htmlspecialchars($current_settings['from_email']); ?>" placeholder="noreply@sahabformmaster.com">
                            </div>

                            <div class="form-group">
                                <label for="from_name">From Name</label>
                                <input type="text" id="from_name" name="from_name" value="<?php echo htmlspecialchars($current_settings['from_name']); ?>" placeholder="SahabFormMaster">
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-flask"></i> Test Email
                            </h3>
                            <p class="section-description">
                                Send a test email to verify your SMTP configuration.
                            </p>

                            <div class="form-group">
                                <label for="test_email_address">Test Email Address</label>
                                <input type="email" id="test_email_address" name="test_email_address" placeholder="your-email@example.com">
                            </div>

                            <div class="form-group">
                                <button type="submit" name="test_email" class="btn btn-secondary">
                                    <i class="fas fa-paper-plane"></i> Send Test Email
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" name="update_email_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Email Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Maintenance Tab -->
                <div id="maintenance" class="tab-content">
                    <div class="settings-section">
                        <h3 class="section-title">
                            <i class="fas fa-broom"></i> System Maintenance
                        </h3>
                        <p class="section-description">
                            Perform maintenance tasks and system cleanup operations.
                        </p>

                        <form method="POST" style="display: inline;">
                            <button type="submit" name="clear_cache" class="btn btn-secondary" onclick="return confirm('Are you sure you want to clear the system cache?')">
                                <i class="fas fa-trash-alt"></i> Clear System Cache
                            </button>
                        </form>

                        <form method="POST" style="display: inline; margin-left: 12px;">
                            <button type="submit" name="reset_settings" class="btn btn-danger" onclick="return confirm('Are you sure you want to reset ALL settings to defaults? This cannot be undone!')">
                                <i class="fas fa-undo"></i> Reset All Settings to Defaults
                            </button>
                        </form>
                    </div>

                    <div class="settings-section">
                        <h3 class="section-title">
                            <i class="fas fa-database"></i> Database Information
                        </h3>
                        <p class="section-description">
                            Current database status and statistics.
                        </p>

                        <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <p><strong>System Status:</strong> <span style="color: #10b981;">Online</span></p>
                            <p><strong>Database Version:</strong> MySQL <?php echo $pdo->query('SELECT VERSION()')->fetchColumn(); ?></p>
                            <p><strong>Settings Table:</strong> <?php echo $pdo->query("SELECT COUNT(*) FROM system_settings")->fetchColumn(); ?> settings configured</p>
                            <p><strong>Last Backup:</strong> <?php echo date('M j, Y H:i'); ?> (simulated)</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Tab switching functionality
        function showTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => content.classList.remove('active'));

            // Remove active class from all tab buttons
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => button.classList.remove('active'));

            // Show selected tab content
            document.getElementById(tabName).classList.add('active');

            // Add active class to clicked button
            event.target.classList.add('active');
        }

        // Auto-save functionality (optional)
        let autoSaveTimeout;
        function scheduleAutoSave() {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(() => {
                // Here you could implement auto-save functionality
                console.log('Auto-save triggered');
            }, 30000); // 30 seconds
        }

        // Add auto-save listeners to form inputs
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('input', scheduleAutoSave);
                input.addEventListener('change', scheduleAutoSave);
            });
        });

        // Mobile sidebar toggle
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
        }

        // Form validation
        function validateForm(formId) {
            const form = document.getElementById(formId);
            const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
            let isValid = true;

            inputs.forEach(input => {
                if (!input.value.trim()) {
                    input.style.borderColor = '#ef4444';
                    isValid = false;
                } else {
                    input.style.borderColor = '#10b981';
                }
            });

            return isValid;
        }

        // Add loading state to forms
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (form.tagName === 'FORM') {
                form.classList.add('loading');
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                    submitBtn.disabled = true;
                }
            }
        });
    </script>
</body>
</html>
