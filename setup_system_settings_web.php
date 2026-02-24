<?php
// Web-accessible setup script for system_settings table
require_once 'config/db.php';

$message = '';
$message_type = '';

try {
    // Create system_settings table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Insert default settings
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
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$key, $value, $value]);
    }

    $message = "✅ System settings table created successfully with " . count($default_settings) . " default settings!";
    $message_type = 'success';

} catch (PDOException $e) {
    $message = "❌ Error: " . $e->getMessage();
    $message_type = 'error';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings Setup | SahabFormMaster</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 100%;
        }
        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-weight: bold;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .btn {
            background: #007bff;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 10px;
        }
        .btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 System Settings Setup</h1>
        <p>This script creates the system_settings database table and populates it with default values.</p>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <p><strong>Next steps:</strong></p>
        <ol>
            <li>The system_settings table has been created</li>
            <li>Default settings have been inserted</li>
            <li>You can now access <a href="super/system_settings.php">System Settings</a></li>
        </ol>

        <a href="super/system_settings.php" class="btn">Go to System Settings</a>
        <a href="super/dashboard.php" class="btn" style="margin-left: 10px; background: #6c757d;">Back to Dashboard</a>
    </div>
<?php include 'includes/floating-button.php'; ?>
</body>
</html>
