<?php
require_once '../auth_check.php';
require_once '../../config/db.php';

// Get filters from GET parameters
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$school_filter = $_GET['school'] ?? '';
$status_filter = $_GET['status'] ?? '';
$format = $_GET['format'] ?? 'csv';

// Build query with filters
$query = "
    SELECT u.*, s.school_name, s.school_code,
           0 as recent_activity
    FROM users u
    LEFT JOIN schools s ON u.school_id = s.id
    WHERE 1=1
";

$params = [];
if ($search) {
    $query .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($role_filter) {
    $query .= " AND u.role = ?";
    $params[] = $role_filter;
}
if ($school_filter) {
    $query .= " AND u.school_id = ?";
    $params[] = $school_filter;
}
if ($status_filter !== '') {
    $query .= " AND u.is_active = ?";
    $params[] = (int)$status_filter;
}

$query .= " ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Log export action
log_super_action('export_users', 'system', null, "Exported " . count($users) . " users in $format format");

if ($format === 'csv') {
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="users_export_' . date('Y-m-d_H-i-s') . '.csv"');
    header('Cache-Control: max-age=0');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Write CSV headers
    fputcsv($output, [
        'User ID',
        'Username',
        'Full Name',
        'Email',
        'Role',
        'School',
        'School Code',
        'Phone',
        'Designation',
        'Department',
        'Status',
        'Recent Activity (30 days)',
        'Created Date',
        'Last Updated'
    ]);

    // Write user data
    foreach ($users as $user) {
        fputcsv($output, [
            $user['id'],
            $user['username'],
            $user['full_name'],
            $user['email'],
            ucfirst($user['role']),
            $user['school_name'] ?? 'No School',
            $user['school_code'] ?? '',
            $user['phone'] ?? '',
            $user['designation'] ?? '',
            $user['department'] ?? '',
            $user['is_active'] ? 'Active' : 'Inactive',
            $user['recent_activity'],
            date('Y-m-d H:i:s', strtotime($user['created_at'])),
            $user['updated_at'] ? date('Y-m-d H:i:s', strtotime($user['updated_at'])) : 'Never'
        ]);
    }

    fclose($output);
    exit;
} elseif ($format === 'json') {
    // JSON export
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="users_export_' . date('Y-m-d_H-i-s') . '.json"');

    // Remove sensitive data
    foreach ($users as &$user) {
        unset($user['password']);
    }

    echo json_encode([
        'export_date' => date('Y-m-d H:i:s'),
        'total_users' => count($users),
        'filters' => [
            'search' => $search,
            'role' => $role_filter,
            'school' => $school_filter,
            'status' => $status_filter
        ],
        'users' => $users
    ], JSON_PRETTY_PRINT);
    exit;
} else {
    // Invalid format
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid export format. Supported formats: csv, json']);
    exit;
}
?>
