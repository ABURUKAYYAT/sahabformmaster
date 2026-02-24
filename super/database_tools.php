<?php
require_once 'auth_check.php';
require_once '../config/db.php';

// Get current super admin info
$super_admin = get_current_super_admin();

// Log access
log_super_action('view_database_tools', 'system', null, 'Accessed database tools page');

// Ensure migrations table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        migration_file VARCHAR(255) UNIQUE NOT NULL,
        description TEXT,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('success', 'failed', 'partial') DEFAULT 'success',
        error_message TEXT,
        executed_by INT,
        FOREIGN KEY (executed_by) REFERENCES users(id)
    )");
} catch (Exception $e) {
    error_log('Failed to create migrations table: ' . $e->getMessage());
}

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['run_migration']) && isset($_POST['migration_file'])) {
            $migration_file = $_POST['migration_file'];

            // Check if migration already executed
            $stmt = $pdo->prepare("SELECT id FROM migrations WHERE migration_file = ?");
            $stmt->execute([$migration_file]);
            if ($stmt->fetch()) {
                $message = 'Migration already executed.';
                $message_type = 'error';
            } else {
                // Execute migration
                $migration_path = "../database/migrations/$migration_file";
                if (file_exists($migration_path)) {
                    $sql = file_get_contents($migration_path);
                    $pdo->exec($sql);

                    // Record migration
                    $stmt = $pdo->prepare("INSERT INTO migrations (migration_file, description, executed_by) VALUES (?, ?, ?)");
                    $stmt->execute([$migration_file, "Migration $migration_file", $super_admin['id']]);

                    log_super_action('run_migration', 'system', null, "Executed migration: $migration_file");
                    $message = "Migration $migration_file executed successfully!";
                    $message_type = 'success';
                } else {
                    $message = 'Migration file not found.';
                    $message_type = 'error';
                }
            }

        } elseif (isset($_POST['run_fix']) && isset($_POST['fix_script'])) {
            $fix_script = $_POST['fix_script'];

            // Include and run fix script
            $fix_path = "../$fix_script";
            if (file_exists($fix_path)) {
                ob_start();
                include $fix_path;
                $output = ob_get_clean();

                log_super_action('run_database_fix', 'system', null, "Executed fix script: $fix_script");
                $message = "Fix script executed. Check output below.";
                $message_type = 'success';
            } else {
                $message = 'Fix script not found.';
                $message_type = 'error';
            }

        } elseif (isset($_POST['clear_temp_data'])) {
            // Clear temporary data
            $tables_to_clear = ['temp_sessions', 'temp_uploads', 'cache_data'];
            $cleared_count = 0;

            foreach ($tables_to_clear as $table) {
                try {
                    $pdo->exec("TRUNCATE TABLE `$table`");
                    $cleared_count++;
                } catch (Exception $e) {
                    // Table might not exist, continue
                }
            }

            log_super_action('clear_temp_data', 'system', null, "Cleared $cleared_count temporary tables");
            $message = "Cleared $cleared_count temporary tables.";
            $message_type = 'success';
        }

    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'error';
        error_log('Database tools error: ' . $e->getMessage());
    }
}

// Get available migrations
$migrations = [];
$migration_files = glob('../database/migrations/*.sql');
$applied_migrations = [];

try {
    $stmt = $pdo->query("SELECT migration_file FROM migrations ORDER BY executed_at DESC");
    $applied_migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $applied_migrations = [];
}

foreach ($migration_files as $file) {
    $filename = basename($file);
    $migrations[] = [
        'file' => $filename,
        'applied' => in_array($filename, $applied_migrations),
        'path' => $file
    ];
}

// Get database statistics
$db_stats = [];
try {
    // Database size
    $stmt = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb FROM information_schema.tables WHERE table_schema = DATABASE()");
    $db_stats['size'] = $stmt->fetchColumn();

    // Table count
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $db_stats['tables'] = $stmt->fetchColumn();

    // Total rows across all tables
    $stmt = $pdo->query("SELECT SUM(table_rows) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $db_stats['rows'] = $stmt->fetchColumn();

    // MySQL version
    $stmt = $pdo->query("SELECT VERSION()");
    $db_stats['version'] = $stmt->fetchColumn();

} catch (Exception $e) {
    $db_stats = ['size' => 0, 'tables' => 0, 'rows' => 0, 'version' => 'Unknown'];
}

// Get recent migrations
$recent_migrations = [];
try {
    $stmt = $pdo->prepare("SELECT m.*, u.full_name FROM migrations m LEFT JOIN users u ON m.executed_by = u.id ORDER BY m.executed_at DESC LIMIT 5");
    $stmt->execute();
    $recent_migrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_migrations = [];
}

// Get available fix scripts
$fix_scripts = [
    'fix_database.php',
    'fix_students_school_id.php',
    'fix_students_school_id_simple.php'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Tools | SahabFormMaster</title>
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

        .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .admin-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
        }

        .logout-btn {
            padding: 8px 16px;
            background: #dc2626;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s ease;
        }

        .logout-btn:hover {
            background: #b91c1c;
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

        /* Tools Container */
        .tools-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        /* Tabs */
        .tools-tabs {
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

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .data-table th {
            font-weight: 600;
            color: #475569;
            font-size: 14px;
        }

        .data-table td {
            color: #334155;
        }

        /* Status badges */
        .status-badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-success {
            background: #dcfce7;
            color: #166534;
        }

        .status-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        /* Tools sections */
        .tools-section {
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .tools-section:last-child {
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

        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #f8fafc;
            padding: 16px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 800;
            color: #1e293b;
        }

        .stat-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
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
            .tools-tabs {
                flex-direction: column;
            }
            .tab-content {
                padding: 20px;
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
                        <a href="database_tools.php" class="nav-link active">
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
                <h1><i class="fas fa-database"></i> Database Tools</h1>
                <div class="header-actions">
                    <div class="admin-info">
                        <div class="admin-avatar">
                            <?php echo strtoupper(substr($super_admin['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($super_admin['full_name']); ?></div>
                            <div style="font-size: 12px; color: #64748b;">Super Administrator</div>
                        </div>
                    </div>
                    <a href="logout.php" class="logout-btn">
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

            <!-- Tools Container -->
            <div class="tools-container">
                <!-- Tabs -->
                <div class="tools-tabs">
                    <button class="tab-button active" onclick="showTab('migrations')">
                        <i class="fas fa-code-branch"></i> Migrations
                    </button>
                    <button class="tab-button" onclick="showTab('fixes')">
                        <i class="fas fa-tools"></i> Database Fixes
                    </button>
                    <button class="tab-button" onclick="showTab('status')">
                        <i class="fas fa-chart-bar"></i> Database Status
                    </button>
                    <button class="tab-button" onclick="showTab('maintenance')">
                        <i class="fas fa-wrench"></i> Maintenance
                    </button>
                </div>

                <!-- Migrations Tab -->
                <div id="migrations" class="tab-content active">
                    <div class="tools-section">
                        <h3 class="section-title">
                            <i class="fas fa-code-branch"></i> Database Migrations
                        </h3>
                        <p class="section-description">
                            Execute database migrations to update the schema. Each migration should only be run once.
                        </p>

                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Migration File</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($migrations as $migration): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($migration['file']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $migration['applied'] ? 'status-success' : 'status-pending'; ?>">
                                                <?php echo $migration['applied'] ? 'Applied' : 'Pending'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!$migration['applied']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="migration_file" value="<?php echo htmlspecialchars($migration['file']); ?>">
                                                    <button type="submit" name="run_migration" class="btn btn-success btn-sm"
                                                            onclick="return confirm('Are you sure you want to run this migration?')">
                                                        <i class="fas fa-play"></i> Run
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #64748b; font-size: 12px;">Already applied</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="tools-section">
                        <h3 class="section-title">
                            <i class="fas fa-history"></i> Recent Migrations
                        </h3>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Migration File</th>
                                    <th>Executed By</th>
                                    <th>Executed At</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_migrations)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: #64748b;">
                                            No migrations executed yet
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_migrations as $migration): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($migration['migration_file']); ?></td>
                                            <td><?php echo htmlspecialchars($migration['full_name'] ?? 'System'); ?></td>
                                            <td><?php echo date('M j, Y H:i', strtotime($migration['executed_at'])); ?></td>
                                            <td>
                                                <span class="status-badge status-success">
                                                    <?php echo ucfirst($migration['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Database Fixes Tab -->
                <div id="fixes" class="tab-content">
                    <div class="tools-section">
                        <h3 class="section-title">
                            <i class="fas fa-tools"></i> Database Fix Scripts
                        </h3>
                        <p class="section-description">
                            Run database fix scripts to repair data inconsistencies and missing schema elements.
                        </p>

                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fix Script</th>
                                    <th>Description</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fix_scripts as $script): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($script); ?></td>
                                        <td>
                                            <?php
                                            $descriptions = [
                                                'fix_database.php' => 'Add missing columns and indexes to tables',
                                                'fix_students_school_id.php' => 'Fix student school_id assignments',
                                                'fix_students_school_id_simple.php' => 'Simple student school_id fix'
                                            ];
                                            echo $descriptions[$script] ?? 'Database fix script';
                                            ?>
                                        </td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="fix_script" value="<?php echo htmlspecialchars($script); ?>">
                                                <button type="submit" name="run_fix" class="btn btn-primary btn-sm"
                                                        onclick="return confirm('Are you sure you want to run this fix script?')">
                                                    <i class="fas fa-play"></i> Run Fix
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Database Status Tab -->
                <div id="status" class="tab-content">
                    <div class="tools-section">
                        <h3 class="section-title">
                            <i class="fas fa-chart-bar"></i> Database Overview
                        </h3>
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value"><?php echo number_format($db_stats['size'], 1); ?> MB</div>
                                <div class="stat-label">Database Size</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo number_format($db_stats['tables']); ?></div>
                                <div class="stat-label">Total Tables</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo number_format($db_stats['rows']); ?></div>
                                <div class="stat-label">Total Rows</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo htmlspecialchars($db_stats['version']); ?></div>
                                <div class="stat-label">MySQL Version</div>
                            </div>
                        </div>
                    </div>

                    <div class="tools-section">
                        <h3 class="section-title">
                            <i class="fas fa-table"></i> Table Information
                        </h3>
                        <p class="section-description">
                            Overview of all tables in the database.
                        </p>

                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Table Name</th>
                                    <th>Rows</th>
                                    <th>Data Size</th>
                                    <th>Index Size</th>
                                    <th>Total Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                try {
                                    $stmt = $pdo->query("
                                        SELECT
                                            table_name,
                                            table_rows,
                                            ROUND(data_length / 1024 / 1024, 2) as data_size,
                                            ROUND(index_length / 1024 / 1024, 2) as index_size,
                                            ROUND((data_length + index_length) / 1024 / 1024, 2) as total_size
                                        FROM information_schema.tables
                                        WHERE table_schema = DATABASE()
                                        ORDER BY total_size DESC
                                        LIMIT 20
                                    ");
                                    while ($table = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($table['table_name']); ?></td>
                                            <td><?php echo number_format($table['table_rows']); ?></td>
                                            <td><?php echo number_format($table['data_size'], 2); ?> MB</td>
                                            <td><?php echo number_format($table['index_size'], 2); ?> MB</td>
                                            <td><?php echo number_format($table['total_size'], 2); ?> MB</td>
                                        </tr>
                                    <?php endwhile;
                                } catch (Exception $e) {
                                    echo '<tr><td colspan="5" style="text-align: center; color: #991b1b;">Error loading table information</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Maintenance Tab -->
                <div id="maintenance" class="tab-content">
                    <div class="tools-section">
                        <h3 class="section-title">
                            <i class="fas fa-broom"></i> System Maintenance
                        </h3>
                        <p class="section-description">
                            Perform maintenance tasks and cleanup operations.
                        </p>

                        <form method="POST" style="display: inline;">
                            <button type="submit" name="clear_temp_data" class="btn btn-secondary"
                                    onclick="return confirm('Are you sure you want to clear temporary data?')">
                                <i class="fas fa-trash-alt"></i> Clear Temporary Data
                            </button>
                        </form>
                    </div>

                    <div class="tools-section">
                        <h3 class="section-title">
                            <i class="fas fa-info-circle"></i> System Information
                        </h3>
                        <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <p><strong>System Status:</strong> <span style="color: #10b981;">Online</span></p>
                            <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
                            <p><strong>Database:</strong> MySQL <?php echo htmlspecialchars($db_stats['version']); ?></p>
                            <p><strong>Database Size:</strong> <?php echo number_format($db_stats['size'], 1); ?> MB</p>
                            <p><strong>Total Tables:</strong> <?php echo number_format($db_stats['tables']); ?></p>
                            <p><strong>Last Migration:</strong> <?php echo !empty($recent_migrations) ? date('M j, Y H:i', strtotime($recent_migrations[0]['executed_at'])) : 'None'; ?></p>
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

        // Mobile sidebar toggle
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('show');
        }
    </script>
<?php include '../includes/floating-button.php'; ?>
</body>
</html>
