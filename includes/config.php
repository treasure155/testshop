<?php
// includes/config.php
$DB_HOST = 'srv2057.hstgr.io';
$DB_NAME = 'u848848112_shop';
$DB_USER = 'u848848112_shop';
$DB_PASS = '@@Uyioobong155@@';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    exit('DB connection failed: ' . $e->getMessage());
}
