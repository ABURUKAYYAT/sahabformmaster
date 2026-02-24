<?php

function cbt_table_exists(PDO $pdo, $table)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') {
        return false;
    }

    $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
    return (bool)$stmt->fetchColumn();
}

function cbt_get_columns(PDO $pdo, $table, $refresh = false)
{
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $cacheKey = 'cbt_cols_' . $table;

    if ($refresh) {
        unset($cache[$cacheKey]);
    }

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $columns = [];
    if (!cbt_table_exists($pdo, $table)) {
        $cache[$cacheKey] = $columns;
        return $columns;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[$row['Field']] = $row;
    }

    $cache[$cacheKey] = $columns;
    return $columns;
}

function cbt_has_column(PDO $pdo, $table, $column)
{
    $columns = cbt_get_columns($pdo, $table);
    return isset($columns[$column]);
}

function cbt_index_exists(PDO $pdo, $table, $indexName)
{
    if (!cbt_table_exists($pdo, $table)) {
        return false;
    }
    $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
    $stmt->execute([$indexName]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function cbt_ensure_primary_auto_increment(PDO $pdo, $table)
{
    if (!cbt_table_exists($pdo, $table)) {
        return;
    }

    if (!cbt_has_column($pdo, $table, 'id')) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN id INT(11) NOT NULL");
        cbt_get_columns($pdo, $table, true);
    }

    $pkStmt = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = 'PRIMARY'");
    $hasPrimary = (bool)$pkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$hasPrimary) {
        $seqVar = '@' . $table . '_seq';
        $hasCreatedAt = cbt_has_column($pdo, $table, 'created_at');
        $orderBy = $hasCreatedAt ? " ORDER BY created_at ASC, id ASC" : "";

        $pdo->exec("SET $seqVar := 0");
        $pdo->exec("UPDATE `$table` SET id = ($seqVar := $seqVar + 1)$orderBy");
        $pdo->exec("ALTER TABLE `$table` MODIFY id INT(11) NOT NULL");
        $pdo->exec("ALTER TABLE `$table` ADD PRIMARY KEY (id)");
    }

    $idMetaStmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'id'");
    $idMeta = $idMetaStmt->fetch(PDO::FETCH_ASSOC);
    $idExtra = strtolower((string)($idMeta['Extra'] ?? ''));
    if (strpos($idExtra, 'auto_increment') === false) {
        $pdo->exec("ALTER TABLE `$table` MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
    }
}

function ensure_cbt_schema(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cbt_tests (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_id INT(10) UNSIGNED NOT NULL,
            teacher_id INT(10) UNSIGNED NOT NULL,
            class_id INT(10) UNSIGNED NOT NULL,
            subject_id INT(10) UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            duration_minutes INT(11) NOT NULL DEFAULT 30,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_cbt_tests_school (school_id),
            INDEX idx_cbt_tests_teacher (teacher_id),
            INDEX idx_cbt_tests_class (class_id),
            INDEX idx_cbt_tests_subject (subject_id),
            INDEX idx_cbt_tests_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $testAddColumns = [
        'school_id' => "ALTER TABLE cbt_tests ADD COLUMN school_id INT(10) UNSIGNED NOT NULL DEFAULT 0",
        'teacher_id' => "ALTER TABLE cbt_tests ADD COLUMN teacher_id INT(10) UNSIGNED NOT NULL DEFAULT 0",
        'class_id' => "ALTER TABLE cbt_tests ADD COLUMN class_id INT(10) UNSIGNED NOT NULL DEFAULT 0",
        'subject_id' => "ALTER TABLE cbt_tests ADD COLUMN subject_id INT(10) UNSIGNED NOT NULL DEFAULT 0",
        'title' => "ALTER TABLE cbt_tests ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT ''",
        'duration_minutes' => "ALTER TABLE cbt_tests ADD COLUMN duration_minutes INT(11) NOT NULL DEFAULT 30",
        'starts_at' => "ALTER TABLE cbt_tests ADD COLUMN starts_at DATETIME NULL",
        'ends_at' => "ALTER TABLE cbt_tests ADD COLUMN ends_at DATETIME NULL",
        'status' => "ALTER TABLE cbt_tests ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'draft'",
        'created_at' => "ALTER TABLE cbt_tests ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE cbt_tests ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($testAddColumns as $column => $sql) {
        if (!cbt_has_column($pdo, 'cbt_tests', $column)) {
            $pdo->exec($sql);
            cbt_get_columns($pdo, 'cbt_tests', true);
        }
    }

    $pdo->exec("ALTER TABLE cbt_tests MODIFY school_id INT(10) UNSIGNED NOT NULL");
    $pdo->exec("ALTER TABLE cbt_tests MODIFY teacher_id INT(10) UNSIGNED NOT NULL");
    $pdo->exec("ALTER TABLE cbt_tests MODIFY class_id INT(10) UNSIGNED NOT NULL");
    $pdo->exec("ALTER TABLE cbt_tests MODIFY subject_id INT(10) UNSIGNED NOT NULL");
    $pdo->exec("ALTER TABLE cbt_tests MODIFY title VARCHAR(255) NOT NULL");
    $pdo->exec("ALTER TABLE cbt_tests MODIFY duration_minutes INT(11) NOT NULL DEFAULT 30");
    $pdo->exec("ALTER TABLE cbt_tests MODIFY starts_at DATETIME NULL");
    $pdo->exec("ALTER TABLE cbt_tests MODIFY ends_at DATETIME NULL");
    $pdo->exec("ALTER TABLE cbt_tests MODIFY status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft'");
    $pdo->exec("ALTER TABLE cbt_tests MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $pdo->exec("ALTER TABLE cbt_tests MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    cbt_ensure_primary_auto_increment($pdo, 'cbt_tests');

    if (!cbt_index_exists($pdo, 'cbt_tests', 'idx_cbt_tests_school')) {
        $pdo->exec("ALTER TABLE cbt_tests ADD INDEX idx_cbt_tests_school (school_id)");
    }
    if (!cbt_index_exists($pdo, 'cbt_tests', 'idx_cbt_tests_teacher')) {
        $pdo->exec("ALTER TABLE cbt_tests ADD INDEX idx_cbt_tests_teacher (teacher_id)");
    }
    if (!cbt_index_exists($pdo, 'cbt_tests', 'idx_cbt_tests_class')) {
        $pdo->exec("ALTER TABLE cbt_tests ADD INDEX idx_cbt_tests_class (class_id)");
    }
    if (!cbt_index_exists($pdo, 'cbt_tests', 'idx_cbt_tests_subject')) {
        $pdo->exec("ALTER TABLE cbt_tests ADD INDEX idx_cbt_tests_subject (subject_id)");
    }
    if (!cbt_index_exists($pdo, 'cbt_tests', 'idx_cbt_tests_status')) {
        $pdo->exec("ALTER TABLE cbt_tests ADD INDEX idx_cbt_tests_status (status)");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cbt_questions (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            test_id INT(11) NOT NULL,
            question_text TEXT NOT NULL,
            option_a TEXT NOT NULL,
            option_b TEXT NOT NULL,
            option_c TEXT NOT NULL,
            option_d TEXT NOT NULL,
            correct_option ENUM('A','B','C','D') NOT NULL,
            question_order INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cbt_questions_test (test_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $questionAddColumns = [
        'test_id' => "ALTER TABLE cbt_questions ADD COLUMN test_id INT(11) NOT NULL DEFAULT 0",
        'question_text' => "ALTER TABLE cbt_questions ADD COLUMN question_text TEXT NOT NULL",
        'option_a' => "ALTER TABLE cbt_questions ADD COLUMN option_a TEXT NOT NULL",
        'option_b' => "ALTER TABLE cbt_questions ADD COLUMN option_b TEXT NOT NULL",
        'option_c' => "ALTER TABLE cbt_questions ADD COLUMN option_c TEXT NOT NULL",
        'option_d' => "ALTER TABLE cbt_questions ADD COLUMN option_d TEXT NOT NULL",
        'correct_option' => "ALTER TABLE cbt_questions ADD COLUMN correct_option VARCHAR(1) NOT NULL DEFAULT 'A'",
        'question_order' => "ALTER TABLE cbt_questions ADD COLUMN question_order INT(11) NOT NULL DEFAULT 0",
        'created_at' => "ALTER TABLE cbt_questions ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($questionAddColumns as $column => $sql) {
        if (!cbt_has_column($pdo, 'cbt_questions', $column)) {
            $pdo->exec($sql);
            cbt_get_columns($pdo, 'cbt_questions', true);
        }
    }

    $pdo->exec("ALTER TABLE cbt_questions MODIFY test_id INT(11) NOT NULL");
    $pdo->exec("ALTER TABLE cbt_questions MODIFY correct_option ENUM('A','B','C','D') NOT NULL");
    $pdo->exec("ALTER TABLE cbt_questions MODIFY question_order INT(11) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE cbt_questions MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    cbt_ensure_primary_auto_increment($pdo, 'cbt_questions');
    if (!cbt_index_exists($pdo, 'cbt_questions', 'idx_cbt_questions_test')) {
        $pdo->exec("ALTER TABLE cbt_questions ADD INDEX idx_cbt_questions_test (test_id)");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cbt_attempts (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            test_id INT(11) NOT NULL,
            student_id INT(11) NOT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            submitted_at DATETIME NULL,
            score INT(11) NOT NULL DEFAULT 0,
            total_questions INT(11) NOT NULL DEFAULT 0,
            status ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_cbt_attempts_test (test_id),
            INDEX idx_cbt_attempts_student (student_id),
            INDEX idx_cbt_attempts_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $attemptAddColumns = [
        'test_id' => "ALTER TABLE cbt_attempts ADD COLUMN test_id INT(11) NOT NULL DEFAULT 0",
        'student_id' => "ALTER TABLE cbt_attempts ADD COLUMN student_id INT(11) NOT NULL DEFAULT 0",
        'started_at' => "ALTER TABLE cbt_attempts ADD COLUMN started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'submitted_at' => "ALTER TABLE cbt_attempts ADD COLUMN submitted_at DATETIME NULL",
        'score' => "ALTER TABLE cbt_attempts ADD COLUMN score INT(11) NOT NULL DEFAULT 0",
        'total_questions' => "ALTER TABLE cbt_attempts ADD COLUMN total_questions INT(11) NOT NULL DEFAULT 0",
        'status' => "ALTER TABLE cbt_attempts ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'in_progress'",
        'created_at' => "ALTER TABLE cbt_attempts ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE cbt_attempts ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($attemptAddColumns as $column => $sql) {
        if (!cbt_has_column($pdo, 'cbt_attempts', $column)) {
            $pdo->exec($sql);
            cbt_get_columns($pdo, 'cbt_attempts', true);
        }
    }

    $pdo->exec("ALTER TABLE cbt_attempts MODIFY test_id INT(11) NOT NULL");
    $pdo->exec("ALTER TABLE cbt_attempts MODIFY student_id INT(11) NOT NULL");
    $pdo->exec("ALTER TABLE cbt_attempts MODIFY started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $pdo->exec("ALTER TABLE cbt_attempts MODIFY submitted_at DATETIME NULL");
    $pdo->exec("ALTER TABLE cbt_attempts MODIFY score INT(11) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE cbt_attempts MODIFY total_questions INT(11) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE cbt_attempts MODIFY status ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress'");
    $pdo->exec("ALTER TABLE cbt_attempts MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $pdo->exec("ALTER TABLE cbt_attempts MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    cbt_ensure_primary_auto_increment($pdo, 'cbt_attempts');

    // Keep only the latest attempt per test/student before unique index.
    $pdo->exec("
        DELETE a1 FROM cbt_attempts a1
        JOIN cbt_attempts a2
          ON a1.test_id = a2.test_id
         AND a1.student_id = a2.student_id
         AND a1.id < a2.id
    ");

    if (!cbt_index_exists($pdo, 'cbt_attempts', 'idx_cbt_attempts_test')) {
        $pdo->exec("ALTER TABLE cbt_attempts ADD INDEX idx_cbt_attempts_test (test_id)");
    }
    if (!cbt_index_exists($pdo, 'cbt_attempts', 'idx_cbt_attempts_student')) {
        $pdo->exec("ALTER TABLE cbt_attempts ADD INDEX idx_cbt_attempts_student (student_id)");
    }
    if (!cbt_index_exists($pdo, 'cbt_attempts', 'idx_cbt_attempts_status')) {
        $pdo->exec("ALTER TABLE cbt_attempts ADD INDEX idx_cbt_attempts_status (status)");
    }
    if (!cbt_index_exists($pdo, 'cbt_attempts', 'uq_cbt_attempts_test_student')) {
        $pdo->exec("ALTER TABLE cbt_attempts ADD UNIQUE KEY uq_cbt_attempts_test_student (test_id, student_id)");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cbt_answers (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            attempt_id INT(11) NOT NULL,
            question_id INT(11) NOT NULL,
            selected_option ENUM('A','B','C','D') NOT NULL,
            is_correct TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_cbt_answers_attempt (attempt_id),
            INDEX idx_cbt_answers_question (question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $answerAddColumns = [
        'attempt_id' => "ALTER TABLE cbt_answers ADD COLUMN attempt_id INT(11) NOT NULL DEFAULT 0",
        'question_id' => "ALTER TABLE cbt_answers ADD COLUMN question_id INT(11) NOT NULL DEFAULT 0",
        'selected_option' => "ALTER TABLE cbt_answers ADD COLUMN selected_option VARCHAR(1) NOT NULL DEFAULT 'A'",
        'is_correct' => "ALTER TABLE cbt_answers ADD COLUMN is_correct TINYINT(1) NOT NULL DEFAULT 0",
        'created_at' => "ALTER TABLE cbt_answers ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE cbt_answers ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($answerAddColumns as $column => $sql) {
        if (!cbt_has_column($pdo, 'cbt_answers', $column)) {
            $pdo->exec($sql);
            cbt_get_columns($pdo, 'cbt_answers', true);
        }
    }

    $pdo->exec("ALTER TABLE cbt_answers MODIFY attempt_id INT(11) NOT NULL");
    $pdo->exec("ALTER TABLE cbt_answers MODIFY question_id INT(11) NOT NULL");
    $pdo->exec("ALTER TABLE cbt_answers MODIFY selected_option ENUM('A','B','C','D') NOT NULL");
    $pdo->exec("ALTER TABLE cbt_answers MODIFY is_correct TINYINT(1) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE cbt_answers MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $pdo->exec("ALTER TABLE cbt_answers MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    cbt_ensure_primary_auto_increment($pdo, 'cbt_answers');

    // Keep only the latest answer per attempt/question before unique index.
    $pdo->exec("
        DELETE a1 FROM cbt_answers a1
        JOIN cbt_answers a2
          ON a1.attempt_id = a2.attempt_id
         AND a1.question_id = a2.question_id
         AND a1.id < a2.id
    ");

    if (!cbt_index_exists($pdo, 'cbt_answers', 'idx_cbt_answers_attempt')) {
        $pdo->exec("ALTER TABLE cbt_answers ADD INDEX idx_cbt_answers_attempt (attempt_id)");
    }
    if (!cbt_index_exists($pdo, 'cbt_answers', 'idx_cbt_answers_question')) {
        $pdo->exec("ALTER TABLE cbt_answers ADD INDEX idx_cbt_answers_question (question_id)");
    }
    if (!cbt_index_exists($pdo, 'cbt_answers', 'uq_cbt_answers_attempt_question')) {
        $pdo->exec("ALTER TABLE cbt_answers ADD UNIQUE KEY uq_cbt_answers_attempt_question (attempt_id, question_id)");
    }
}

function cbt_to_mysql_datetime_or_null($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $timestamp);
}
