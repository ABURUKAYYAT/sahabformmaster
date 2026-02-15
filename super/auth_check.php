<?php
session_start();

// Include database configuration
require_once '../config/db.php';

// Check if user is logged in and is a super admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    // Log unauthorized access attempt
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("INSERT INTO access_logs (user_id, action, resource_type, resource_id, status, ip_address, user_agent, message) VALUES (?, 'unauthorized_access', 'super_admin_page', ?, 'denied', ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                $_SERVER['REQUEST_URI'] ?? 'unknown',
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'Non-super-admin user attempted to access super admin area'
            ]);
        } catch (Exception $e) {
            // Log to file if database logging fails
            error_log("Super admin access denied for user {$_SESSION['user_id']}: " . $e->getMessage());
        }
    }

    // Redirect to super admin login
    header('Location: login.php');
    exit;
}

// Regenerate session ID for security
if (!isset($_SESSION['regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = true;
}

// Update last activity
$_SESSION['last_activity'] = time();

// Session timeout check (30 minutes)
$timeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}

// Function to check if user has specific permission
function has_super_permission($permission) {
    // Super admin has all permissions
    return true;
}

// Function to log super admin actions
function log_super_action($action, $resource_type = null, $resource_id = null, $details = null) {
    global $pdo;
    if (!isset($_SESSION['user_id'])) return;

    try {
        $stmt = $pdo->prepare("INSERT INTO access_logs (user_id, action, resource_type, resource_id, status, ip_address, user_agent, message) VALUES (?, ?, ?, ?, 'success', ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            $action,
            $resource_type,
            $resource_id,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $details
        ]);
    } catch (Exception $e) {
        error_log("Failed to log super admin action: " . $e->getMessage());
    }
}

// Function to get current super admin info
function get_current_super_admin() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'role' => $_SESSION['role'] ?? null
    ];
}
?>
