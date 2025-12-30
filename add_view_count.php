<?php
$servername = 'localhost';
$username = 'root';
$password = '';
$dbname = 'sahabformmaster';

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "ALTER TABLE school_diary ADD COLUMN view_count INT DEFAULT 0";
    $conn->exec($sql);
    echo "view_count column added successfully";
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
