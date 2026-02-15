<?php
// Script to add system_settings table and teacher signin toggle
require_once 'config/db.php';

try {
    // Create system_settings table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Insert default value for teacher signin toggle (enabled by default)
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute(['teacher_signin_enabled', '1', '1']);

    echo "System settings table created and teacher signin toggle initialized successfully!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
