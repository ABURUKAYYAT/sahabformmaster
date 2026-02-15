<?php
require_once 'config/db.php';

try {
    // Add school_id column to school_news table
    $sql = "ALTER TABLE school_news ADD COLUMN school_id INT(11) UNSIGNED NULL AFTER id,
             ADD INDEX idx_school_news_school_id (school_id),
             ADD CONSTRAINT fk_school_news_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE";

    $pdo->exec($sql);
    echo "Migration completed successfully: Added school_id column to school_news table\n";

    // Update existing data to assign to first school
    $updateSql = "UPDATE school_news SET school_id = (SELECT id FROM schools ORDER BY id LIMIT 1) WHERE school_id IS NULL";
    $pdo->exec($updateSql);
    echo "Assigned existing news to first school.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
