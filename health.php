<?php
/**
 * Health Check Endpoint
 * Used by Docker health checks and monitoring systems
 */

header('Content-Type: application/json');

// Basic health check
$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'checks' => []
];

// Check PHP
$health['checks']['php'] = [
    'status' => 'ok',
    'version' => PHP_VERSION
];

// Check database connection
try {
    require_once 'config/db.php';

    // Try a simple query
    $stmt = $pdo->query("SELECT 1");
    $stmt->fetch();

    $health['checks']['database'] = [
        'status' => 'ok',
        'message' => 'Database connection successful'
    ];
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['checks']['database'] = [
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ];
}

// Check file permissions for critical directories
$critical_dirs = ['uploads', 'exports', 'generated_papers', 'config'];
foreach ($critical_dirs as $dir) {
    $dir_path = __DIR__ . '/' . $dir;
    $writable = is_writable($dir_path);

    $health['checks']['directory_' . $dir] = [
        'status' => $writable ? 'ok' : 'warning',
        'writable' => $writable,
        'path' => $dir
    ];

    if (!$writable) {
        $health['status'] = 'warning';
    }
}

// Check required PHP extensions
$required_extensions = ['pdo_mysql', 'mysqli', 'gd', 'mbstring', 'xml', 'curl', 'zip'];
foreach ($required_extensions as $ext) {
    $loaded = extension_loaded($ext);

    $health['checks']['extension_' . $ext] = [
        'status' => $loaded ? 'ok' : 'error',
        'loaded' => $loaded
    ];

    if (!$loaded) {
        $health['status'] = 'unhealthy';
    }
}

// Check memory limit
$memory_limit = ini_get('memory_limit');
$memory_limit_bytes = convert_to_bytes($memory_limit);

$health['checks']['memory_limit'] = [
    'status' => ($memory_limit_bytes >= 134217728) ? 'ok' : 'warning', // 128MB minimum
    'limit' => $memory_limit,
    'bytes' => $memory_limit_bytes
];

// Check upload limits
$upload_max = ini_get('upload_max_filesize');
$post_max = ini_get('post_max_size');

$health['checks']['upload_limits'] = [
    'status' => (convert_to_bytes($upload_max) >= 52428800 && convert_to_bytes($post_max) >= 52428800) ? 'ok' : 'warning', // 50MB minimum
    'upload_max_filesize' => $upload_max,
    'post_max_size' => $post_max
];

// Return appropriate HTTP status code
if ($health['status'] === 'unhealthy') {
    http_response_code(503);
} elseif ($health['status'] === 'warning') {
    http_response_code(200); // Still return 200 for warnings
} else {
    http_response_code(200);
}

echo json_encode($health, JSON_PRETTY_PRINT);

function convert_to_bytes($value) {
    $value = trim($value);
    $last = strtolower($value[strlen($value)-1]);
    $value = (int)$value;

    switch($last) {
        case 'g':
            $value *= 1024*1024*1024;
            break;
        case 'm':
            $value *= 1024*1024;
            break;
        case 'k':
            $value *= 1024;
            break;
    }

    return $value;
}
?>
