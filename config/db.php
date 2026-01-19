<?php
// config/db.php

// Load security framework for environment variables
define('SECURE_ACCESS', true);
require_once '../includes/security.php';

// Database configuration from environment variables
$host = Env::get('DB_HOST', 'localhost');
$db_name = Env::get('DB_NAME', 'sahabformmaster');
$username = Env::get('DB_USER', 'root');
$password = Env::get('DB_PASS', '');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    // Additional security settings
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    // Log security event without exposing details
    Security::logSecurityEvent('database_connection_failed', [
        'host' => $host,
        'database' => $db_name
    ]);

    // Generic error message for security
    die("Database connection error. Please contact administrator.");
}

?>
