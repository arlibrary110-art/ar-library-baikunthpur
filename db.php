<?php
// AR Library - database connection for XAMPP + Render/Railway.
// Never hard-code Railway credentials here. Render Environment Variables are preferred.

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('DB_HOST') ?: '';
$port = (int)(getenv('DB_PORT') ?: 0);
$user = getenv('DB_USER') ?: '';
$password = getenv('DB_PASSWORD');
$database = getenv('DB_NAME') ?: '';

// If DB_* variables are not present, support Railway's public MySQL URL.
$publicUrl = getenv('MYSQL_PUBLIC_URL') ?: getenv('MYSQL_PUBLIC_URL_TCP') ?: '';
if ($publicUrl && (!$host || !$port || !$user || $password === false || !$database)) {
    $parts = parse_url($publicUrl);
    if (is_array($parts)) {
        $host = $host ?: ($parts['host'] ?? '');
        $port = $port ?: (int)($parts['port'] ?? 3306);
        $user = $user ?: (isset($parts['user']) ? urldecode($parts['user']) : '');
        if ($password === false) {
            $password = isset($parts['pass']) ? urldecode($parts['pass']) : '';
        }
        $database = $database ?: (isset($parts['path']) ? ltrim($parts['path'], '/') : '');
    }
}

// Local XAMPP fallback only. On Render, DB_HOST should be explicitly set.
if (!$host) {
    $host = 'localhost';
}
if (!$port) {
    $port = 3306;
}
if (!$user) {
    $user = 'root';
}
if ($password === false) {
    $password = '';
}
if (!$database) {
    $database = 'ar_library';
}

$conn = @new mysqli($host, $user, $password, $database, $port);
if ($conn->connect_error) {
    http_response_code(500);
    die('Server connection error. Please check the database configuration.');
}
$conn->set_charset('utf8mb4');

// Lightweight compatibility migration for existing installations.
$colCheck = $conn->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'remarks'");
if ($colCheck) {
    $row = $colCheck->fetch_assoc();
    if ((int)($row['c'] ?? 0) === 0) {
        @$conn->query("ALTER TABLE attendance ADD COLUMN remarks VARCHAR(255) NULL AFTER status");
    }
    $colCheck->free();
}
