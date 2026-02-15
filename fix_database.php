<?php
// Script to check and fix database schema issues
try {
    // Database connection
    $pdo = new PDO('mysql:host=localhost;dbname=sahabformmaster;charset=utf8', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if 'featured' column exists in school_news table
    $stmt = $pdo->query("DESCRIBE school_news");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('featured', $columns)) {
        echo "Adding 'featured' column to school_news table...\n";

        // Add the featured column
        $pdo->exec("ALTER TABLE school_news ADD COLUMN featured TINYINT(1) DEFAULT 0 NOT NULL COMMENT 'Whether this news is featured on homepage' AFTER allow_comments");

        // Add indexes
        $pdo->exec("ALTER TABLE school_news ADD INDEX idx_featured (featured)");
        $pdo->exec("ALTER TABLE school_news ADD INDEX idx_featured_published (featured, published_date)");

        // Set default values
        $pdo->exec("UPDATE school_news SET featured = 0 WHERE featured IS NULL");

        echo "✅ Successfully added 'featured' column and indexes!\n";
    } else {
        echo "✅ 'featured' column already exists in school_news table.\n";
    }

    // Check if 'view_count' column exists
    if (!in_array('view_count', $columns)) {
        echo "Adding 'view_count' column to school_news table...\n";
        $pdo->exec("ALTER TABLE school_news ADD COLUMN view_count INT(11) DEFAULT 0 NOT NULL COMMENT 'Number of views for this news item' AFTER scheduled_date");
        echo "✅ Successfully added 'view_count' column!\n";
    } else {
        echo "✅ 'view_count' column already exists in school_news table.\n";
    }

    // Check if 'comment_count' column exists
    if (!in_array('comment_count', $columns)) {
        echo "Adding 'comment_count' column to school_news table...\n";
        $pdo->exec("ALTER TABLE school_news ADD COLUMN comment_count INT(11) DEFAULT 0 NOT NULL COMMENT 'Number of comments for this news item' AFTER view_count");
        echo "✅ Successfully added 'comment_count' column!\n";
    } else {
        echo "✅ 'comment_count' column already exists in school_news table.\n";
    }

    // Check if student_reminders table exists
    try {
        $pdo->query("SELECT 1 FROM student_reminders LIMIT 1");
        echo "✅ student_reminders table already exists.\n";
    } catch (PDOException $e) {
        echo "Creating student_reminders table...\n";
        $pdo->exec("
            CREATE TABLE student_reminders (
                id INT(11) NOT NULL AUTO_INCREMENT,
                student_id INT(11) NOT NULL,
                activity_id INT(11) NOT NULL,
                reminder_time ENUM('15_min','30_min','1_hour','2_hours','1_day','2_days','1_week') NOT NULL DEFAULT '1_hour',
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_student_activity (student_id, activity_id),
                KEY idx_student_id (student_id),
                KEY idx_activity_id (activity_id),
                KEY idx_reminder_time (reminder_time),
                KEY idx_is_active (is_active),
                CONSTRAINT fk_student_reminders_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
                CONSTRAINT fk_student_reminders_activity FOREIGN KEY (activity_id) REFERENCES school_diary(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Student activity reminders'
        ");
        echo "✅ Successfully created student_reminders table!\n";
    }

    echo "\n🎉 Database schema check and fix completed successfully!\n";

} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
