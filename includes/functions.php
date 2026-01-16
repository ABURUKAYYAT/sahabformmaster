<?php
/**
 * Multi-Tenancy Helper Functions
 * Ensures proper school data isolation across the application
 */

// Get current user's school_id with fallback
function get_current_school_id() {
    // Check if school_id is in session
    if (isset($_SESSION['school_id']) && $_SESSION['school_id'] !== null) {
        return $_SESSION['school_id'];
    }

    // For super admin, return null (they can see all schools)
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') {
        return null;
    }

    // Fallback: fetch from database if not in session
    if (isset($_SESSION['user_id'])) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $school_id = $stmt->fetchColumn();

            // Store in session for future use
            if ($school_id !== false) {
                $_SESSION['school_id'] = $school_id;
                return $school_id;
            }
        } catch (Exception $e) {
            error_log("Error fetching school_id for user {$_SESSION['user_id']}: " . $e->getMessage());
        }
    }

    // If we can't determine school_id, user shouldn't have access
    return false;
}

// Validate that user has access to specific school data
function validate_school_access($requested_school_id) {
    $user_school_id = get_current_school_id();

    // Super admin can access all schools
    if ($user_school_id === null && isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') {
        return true;
    }

    // Regular users can only access their own school
    return $user_school_id === $requested_school_id;
}

// Add school_id filter to SQL queries
function add_school_filter($query, &$params, $school_id = null) {
    if ($school_id === null) {
        $school_id = get_current_school_id();
    }

    // Super admin sees all data
    if ($school_id === null) {
        return $query;
    }

    // Add school filter to query
    if (strpos($query, 'WHERE') !== false) {
        $query .= " AND school_id = ?";
    } else {
        $query .= " WHERE school_id = ?";
    }

    $params[] = $school_id;
    return $query;
}

// Log access attempts (for security monitoring)
function log_access_attempt($action, $resource_type, $resource_id = null, $details = null) {
    if (!isset($_SESSION['user_id'])) return;

    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO access_logs (user_id, school_id, action, resource_type, resource_id, ip_address, user_agent, message, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'success')");
        $stmt->execute([
            $_SESSION['user_id'],
            get_current_school_id(),
            $action,
            $resource_type,
            $resource_id,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $details
        ]);
    } catch (Exception $e) {
        error_log("Failed to log access attempt: " . $e->getMessage());
    }
}

// Check if user is authenticated and has valid school association
function require_school_auth() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../index.php');
        exit;
    }

    $school_id = get_current_school_id();

    // Super admin doesn't need school association
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') {
        return true;
    }

    // Regular users must have school association
    if ($school_id === false || $school_id === null) {
        session_destroy();
        header('Location: ../index.php?error=no_school');
        exit;
    }

    return $school_id;
}

// Get school-filtered classes for current user
function get_school_classes($pdo, $school_id = null) {
    if ($school_id === null) {
        $school_id = get_current_school_id();
    }

    if ($school_id === null) {
        // Super admin - return all classes
        return $pdo->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare("SELECT * FROM classes WHERE school_id = ? ORDER BY class_name ASC");
    $stmt->execute([$school_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get school-filtered students for current user
function get_school_students($pdo, $school_id = null, $limit = null) {
    if ($school_id === null) {
        $school_id = get_current_school_id();
    }

    $query = "
        SELECT s.*, c.class_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE 1=1
    ";

    $params = [];
    if ($school_id !== null) {
        $query .= " AND s.school_id = ?";
        $params[] = $school_id;
    }

    $query .= " ORDER BY s.created_at DESC";

    if ($limit) {
        $query .= " LIMIT ?";
        $params[] = $limit;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get school-filtered teachers for current user
function get_school_teachers($pdo, $school_id = null) {
    if ($school_id === null) {
        $school_id = get_current_school_id();
    }

    if ($school_id === null) {
        // Super admin - return all teachers
        return $pdo->query("SELECT id, full_name, email FROM users WHERE role = 'teacher' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE role = 'teacher' AND school_id = ? ORDER BY full_name ASC");
    $stmt->execute([$school_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
