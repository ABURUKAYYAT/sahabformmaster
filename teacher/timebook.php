<?php
// teacher/timebook.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// Only allow teachers
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header("Location: ../index.php");
    exit;
}

// School authentication and context
$current_school_id = require_school_auth();
$user_id = $_SESSION['user_id'];

// Ensure system_settings table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    // Table might already exist, continue
}

// Ensure school_settings table exists (per-school settings)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS school_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        school_id INT NOT NULL,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY school_setting_unique (school_id, setting_key)
    )");
} catch (PDOException $e) {
    // Table might already exist, continue
}

// Ensure time_records table schema supports timebook features
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS time_records (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        school_id INT NULL,
        sign_in_time DATETIME NULL,
        sign_out_time DATETIME NULL,
        status VARCHAR(50) DEFAULT 'pending',
        notes TEXT,
        recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $columns = $pdo->query("SHOW COLUMNS FROM time_records")->fetchAll(PDO::FETCH_ASSOC);
    $columnMap = [];
    foreach ($columns as $col) {
        $columnMap[strtolower($col['Field'])] = $col;
    }

    if (!isset($columnMap['id'])) {
        $pdo->exec("ALTER TABLE time_records ADD COLUMN id INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
    } elseif (stripos($columnMap['id']['Extra'] ?? '', 'auto_increment') === false) {
        $pdo->exec("ALTER TABLE time_records MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT");
    }

    if (!isset($columnMap['school_id'])) {
        $pdo->exec("ALTER TABLE time_records ADD COLUMN school_id INT NULL AFTER user_id");
    }

    if (!isset($columnMap['notes'])) {
        $pdo->exec("ALTER TABLE time_records ADD COLUMN notes TEXT NULL");
    }

    if (!isset($columnMap['status'])) {
        $pdo->exec("ALTER TABLE time_records ADD COLUMN status VARCHAR(50) DEFAULT 'pending'");
    }

    if (!isset($columnMap['sign_in_time'])) {
        $pdo->exec("ALTER TABLE time_records ADD COLUMN sign_in_time DATETIME NULL");
    } elseif (stripos($columnMap['sign_in_time']['Type'] ?? '', 'datetime') === false) {
        $pdo->exec("ALTER TABLE time_records MODIFY COLUMN sign_in_time DATETIME NULL");
    }
} catch (PDOException $e) {
    // Schema migration failed; continue and let runtime errors surface
}

// Apply system timezone if set
$timezoneStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
$timezoneStmt->execute(['timezone']);
$appTimezone = $timezoneStmt->fetchColumn();
if (!$appTimezone) {
    $appTimezone = 'Africa/Lagos';
}
date_default_timezone_set($appTimezone);

// Check if teacher sign-in is enabled
$signinEnabledStmt = $pdo->prepare("SELECT setting_value FROM school_settings WHERE school_id = ? AND setting_key = ?");
$signinEnabledStmt->execute([$current_school_id, 'teacher_signin_enabled']);
$signin_enabled = $signinEnabledStmt->fetchColumn();
if ($signin_enabled === false) {
    $fallbackStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $fallbackStmt->execute(['teacher_signin_enabled']);
    $signin_enabled = $fallbackStmt->fetchColumn();
}
$signin_enabled = $signin_enabled === false ? true : ($signin_enabled === '1' || $signin_enabled === 1);

// Generate CSRF token
$csrf_token = generate_csrf_token();

// Handle sign in/out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $signin_enabled) {
    // Validate CSRF token
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = "Security validation failed. Please refresh the page.";
        Security::logSecurityEvent('csrf_violation', ['action' => 'timebook_signin', 'user_id' => $user_id]);
        header("Location: timebook.php");
        exit;
    }

    if (isset($_POST['sign_in'])) {
        $current_time = date('Y-m-d H:i:s');
        $notes = $_POST['notes'] ?? '';

        // Check if already signed in today
        $checkStmt = $pdo->prepare("SELECT id FROM time_records WHERE user_id = ? AND school_id = ? AND DATE(sign_in_time) = CURDATE()");
        $checkStmt->execute([$user_id, $current_school_id]);

        if ($checkStmt->rowCount() === 0) {
            $stmt = $pdo->prepare("INSERT INTO time_records (user_id, school_id, sign_in_time, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $current_school_id, $current_time, $notes]);
            $_SESSION['success'] = "Successfully signed in!";
        } else {
            $_SESSION['message'] = "You have already signed in today.";
        }

        header("Location: timebook.php");
        exit();
    }
}

// Get user info
$userStmt = $pdo->prepare("SELECT full_name, email, expected_arrival FROM users WHERE id = ? AND school_id = ?");
$userStmt->execute([$user_id, $current_school_id]);
$user = $userStmt->fetch();

// Get today's record
$todayStmt = $pdo->prepare("SELECT * FROM time_records WHERE user_id = ? AND school_id = ? AND DATE(sign_in_time) = CURDATE()");
$todayStmt->execute([$user_id, $current_school_id]);
$todayRecord = $todayStmt->fetch();

// Get this month's records
$monthStmt = $pdo->prepare("SELECT * FROM time_records WHERE user_id = ? AND school_id = ? AND MONTH(sign_in_time) = MONTH(CURDATE()) ORDER BY sign_in_time DESC");
$monthStmt->execute([$user_id, $current_school_id]);
$monthRecords = $monthStmt->fetchAll();

// Calculate statistics
$statsStmt = $pdo->prepare("SELECT
    COUNT(*) as total_days,
    SUM(CASE WHEN status = 'agreed' THEN 1 ELSE 0 END) as agreed_days,
    SUM(CASE WHEN status = 'not_agreed' THEN 1 ELSE 0 END) as not_agreed_days,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_days
    FROM time_records
    WHERE user_id = ? AND school_id = ? AND MONTH(sign_in_time) = MONTH(CURDATE())");
$statsStmt->execute([$user_id, $current_school_id]);
$stats = $statsStmt->fetch();

include '../includes/teacher_timebook_page.php';
