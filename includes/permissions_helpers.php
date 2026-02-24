<?php

function permissions_table_exists(PDO $pdo)
{
    $stmt = $pdo->query("SHOW TABLES LIKE 'permissions'");
    return (bool)$stmt->fetchColumn();
}

function permissions_get_columns(PDO $pdo, $refresh = false)
{
    static $cache = [];
    $cacheKey = 'permissions_columns';

    if ($refresh) {
        unset($cache[$cacheKey]);
    }

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $columns = [];
    if (!permissions_table_exists($pdo)) {
        $cache[$cacheKey] = $columns;
        return $columns;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM permissions");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[$row['Field']] = $row;
    }
    $cache[$cacheKey] = $columns;
    return $columns;
}

function permissions_has_column(PDO $pdo, $column)
{
    $columns = permissions_get_columns($pdo);
    return isset($columns[$column]);
}

function ensure_permissions_schema(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS permissions (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_id INT(10) UNSIGNED NOT NULL,
            staff_id INT(11) NOT NULL,
            approved_by VARCHAR(50) NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'medium',
            request_type VARCHAR(100) DEFAULT NULL,
            title VARCHAR(500) DEFAULT NULL,
            description VARCHAR(1000) DEFAULT NULL,
            start_date DATETIME DEFAULT NULL,
            end_date DATETIME NULL,
            duration_hours DOUBLE NULL,
            attachment_path VARCHAR(5000) DEFAULT NULL,
            status ENUM('pending','approved','rejected','cancelled','') NOT NULL DEFAULT 'pending',
            rejection_reason TEXT NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_permissions_school (school_id),
            INDEX idx_permissions_staff (staff_id),
            INDEX idx_permissions_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    permissions_get_columns($pdo, true);

    $addColumns = [
        'school_id' => "ALTER TABLE permissions ADD COLUMN school_id INT(10) UNSIGNED NOT NULL DEFAULT 0",
        'staff_id' => "ALTER TABLE permissions ADD COLUMN staff_id INT(11) NOT NULL DEFAULT 0",
        'approved_by' => "ALTER TABLE permissions ADD COLUMN approved_by VARCHAR(50) NULL",
        'priority' => "ALTER TABLE permissions ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'medium'",
        'request_type' => "ALTER TABLE permissions ADD COLUMN request_type VARCHAR(100) DEFAULT NULL",
        'title' => "ALTER TABLE permissions ADD COLUMN title VARCHAR(500) DEFAULT NULL",
        'description' => "ALTER TABLE permissions ADD COLUMN description VARCHAR(1000) DEFAULT NULL",
        'start_date' => "ALTER TABLE permissions ADD COLUMN start_date DATETIME DEFAULT NULL",
        'end_date' => "ALTER TABLE permissions ADD COLUMN end_date DATETIME NULL",
        'duration_hours' => "ALTER TABLE permissions ADD COLUMN duration_hours DOUBLE NULL",
        'attachment_path' => "ALTER TABLE permissions ADD COLUMN attachment_path VARCHAR(5000) DEFAULT NULL",
        'status' => "ALTER TABLE permissions ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'pending'",
        'rejection_reason' => "ALTER TABLE permissions ADD COLUMN rejection_reason TEXT NULL",
        'approved_at' => "ALTER TABLE permissions ADD COLUMN approved_at DATETIME NULL",
        'created_at' => "ALTER TABLE permissions ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE permissions ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    ];

    foreach ($addColumns as $column => $sql) {
        if (!permissions_has_column($pdo, $column)) {
            $pdo->exec($sql);
            permissions_get_columns($pdo, true);
        }
    }

    // Normalize legacy types to current behavior expectations.
    $pdo->exec("ALTER TABLE permissions MODIFY status ENUM('pending','approved','rejected','cancelled','') NOT NULL DEFAULT 'pending'");
    $pdo->exec("ALTER TABLE permissions MODIFY approved_by VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE permissions MODIFY end_date DATETIME NULL");
    $pdo->exec("ALTER TABLE permissions MODIFY duration_hours DOUBLE NULL");
    $pdo->exec("ALTER TABLE permissions MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $pdo->exec("ALTER TABLE permissions MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

    // Ensure id exists and is safe for action-targeting.
    $hasId = permissions_has_column($pdo, 'id');
    if (!$hasId) {
        $pdo->exec("ALTER TABLE permissions ADD COLUMN id INT(11) NOT NULL");
        permissions_get_columns($pdo, true);
    }

    $pkStmt = $pdo->query("SHOW INDEX FROM permissions WHERE Key_name = 'PRIMARY'");
    $hasPrimary = (bool)$pkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$hasPrimary) {
        $pdo->exec("SET @perm_seq := 0");
        $pdo->exec("UPDATE permissions SET id = (@perm_seq := @perm_seq + 1) ORDER BY created_at ASC, staff_id ASC");
        $pdo->exec("ALTER TABLE permissions MODIFY id INT(11) NOT NULL");
        $pdo->exec("ALTER TABLE permissions ADD PRIMARY KEY (id)");
    }

    $idMetaStmt = $pdo->query("SHOW COLUMNS FROM permissions LIKE 'id'");
    $idMeta = $idMetaStmt->fetch(PDO::FETCH_ASSOC);
    $idExtra = strtolower($idMeta['Extra'] ?? '');
    if (strpos($idExtra, 'auto_increment') === false) {
        $pdo->exec("ALTER TABLE permissions MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
    }
}

function to_mysql_datetime_or_null($value)
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
