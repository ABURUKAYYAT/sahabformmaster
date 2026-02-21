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

    // Student fallback: fetch from students table
    if (isset($_SESSION['student_id'])) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT school_id FROM students WHERE id = ?");
            $stmt->execute([$_SESSION['student_id']]);
            $school_id = $stmt->fetchColumn();

            if ($school_id !== false) {
                $_SESSION['school_id'] = $school_id;
                return $school_id;
            }
        } catch (Exception $e) {
            error_log("Error fetching school_id for student {$_SESSION['student_id']}: " . $e->getMessage());
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



/**
 * Data Isolation Helper Functions
 * Provides consistent school-based data filtering across the application
 */

// Validate record ownership by school
function validate_record_ownership($table, $id, $school_id = null, $id_column = 'id') {
    global $pdo;
    if ($school_id === null) {
        $school_id = get_current_school_id();
    }

    // Super admin can access all records
    if ($school_id === null) {
        return true;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$id_column` = ? AND `school_id` = ?");
    $stmt->execute([$id, $school_id]);
    return $stmt->fetchColumn() > 0;
}

// Get school-filtered options for dropdowns
function get_school_filtered_options($table, $school_id = null, $columns = 'id, name', $order_by = 'name', $where_clause = '') {
    global $pdo;
    if ($school_id === null) {
        $school_id = get_current_school_id();
    }

    $query = "SELECT $columns FROM `$table` WHERE 1=1";

    $params = [];
    if ($school_id !== null) {
        $query .= " AND school_id = ?";
        $params[] = $school_id;
    }

    if (!empty($where_clause)) {
        $query .= " AND $where_clause";
    }

    $query .= " ORDER BY $order_by";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Enhanced school-filtered functions with consistent interface
function get_school_students($pdo, $school_id = null, $limit = null, $class_id = null) {
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

    if ($class_id !== null) {
        $query .= " AND s.class_id = ?";
        $params[] = $class_id;
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

function get_school_classes($pdo, $school_id = null) {
    return get_school_filtered_options('classes', $school_id, 'id, class_name', 'class_name');
}

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

function get_school_subjects($pdo, $school_id = null) {
    return get_school_filtered_options('subjects', $school_id, 'id, subject_name', 'subject_name');
}

function get_school_users($pdo, $school_id = null, $role = null) {
    if ($school_id === null) {
        $school_id = get_current_school_id();
    }

    $query = "SELECT * FROM users WHERE 1=1";
    $params = [];

    if ($school_id !== null) {
        $query .= " AND school_id = ?";
        $params[] = $school_id;
    }

    if ($role !== null) {
        $query .= " AND role = ?";
        $params[] = $role;
    }

    $query .= " ORDER BY full_name ASC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}



// Validate bulk operations for school ownership
function validate_bulk_school_access($table, $ids, $school_id = null, $id_column = 'id') {
    if ($school_id === null) {
        $school_id = get_current_school_id();
    }

    // Super admin can access all records
    if ($school_id === null) {
        return true;
    }

    if (empty($ids)) {
        return true;
    }

    global $pdo;
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$id_column` IN ($placeholders) AND `school_id` = ?");
    $params = array_merge($ids, [$school_id]);
    $stmt->execute($params);

    return $stmt->fetchColumn() == count($ids);
}

// Get school statistics safely
function get_school_statistics($pdo, $school_id = null) {
    if ($school_id === null) {
        $school_id = get_current_school_id();
    }

    $stats = [];

    // Student count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $stats['total_students'] = $stmt->fetchColumn();

    // Teacher count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND school_id = ?");
    $stmt->execute([$school_id]);
    $stats['total_teachers'] = $stmt->fetchColumn();

    // Class count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $stats['total_classes'] = $stmt->fetchColumn();

    // Subject count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $stats['total_subjects'] = $stmt->fetchColumn();

    return $stats;
}



/**
 * Security Helper Functions
 * XSS and CSRF protection utilities
 */

// XSS prevention: sanitize output
function sanitize_output($data) {
    if (is_array($data)) {
        return array_map('sanitize_output', $data);
    }
    return htmlspecialchars($data ?? '', ENT_QUOTES, 'UTF-8');
}

// Safe echo function for user-controlled output
function safe_echo($data) {
    echo sanitize_output($data);
}

// Safe display function for session messages
function display_session_message($type) {
    if (isset($_SESSION[$type])) {
        safe_echo($_SESSION[$type]);
        unset($_SESSION[$type]);
    }
}

// Generate CSRF token
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) &&
           hash_equals($_SESSION['csrf_token'], $token);
}

// Sanitize user input before storing in session
function set_session_message($type, $message) {
    $_SESSION[$type] = sanitize_output($message);
}

// Regenerate session ID for security (call after login)
function regenerate_session() {
    if (!isset($_SESSION['regenerated'])) {
        session_regenerate_id(true);
        $_SESSION['regenerated'] = true;
    }
}
?>
