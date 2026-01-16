<?php
require_once '../auth_check.php';
require_once '../../config/db.php';

// Get current super admin info
$super_admin = get_current_super_admin();

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get school ID from query parameter
$school_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$school_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'School ID is required']);
    exit;
}

try {
    // Fetch school details
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
    $stmt->execute([$school_id]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'School not found']);
        exit;
    }

    // Log the access
    log_super_action('view_school_details', 'school', $school_id, 'Accessed school details for editing');

    // Return school data
    echo json_encode([
        'success' => true,
        'school' => $school
    ]);

} catch (Exception $e) {
    error_log("Error fetching school details: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch school details']);
}
?>
