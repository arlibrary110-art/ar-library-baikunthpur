<?php
// AR Library - database connection for XAMPP + Render/Railway.
// Render: set DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD.
// XAMPP: local fallback remains available.

mysqli_report(MYSQLI_REPORT_OFF);

$host = trim((string)(getenv('DB_HOST') ?: ''));
$portRaw = trim((string)(getenv('DB_PORT') ?: ''));
$user = (string)(getenv('DB_USER') ?: '');
$password = getenv('DB_PASSWORD');
$database = trim((string)(getenv('DB_NAME') ?: ''));

/*
 * Railway may expose a public endpoint as:
 *   mainline.proxy.rlwy.net:58776
 * in MYSQLPORT, or as a full MYSQL_PUBLIC_URL.
 */

// 1) If DB_HOST itself contains host:port, split it.
if ($host && strpos($host, ':') !== false && substr_count($host, ':') === 1) {
    [$hostPart, $hostPort] = explode(':', $host, 2);
    if ($hostPart !== '' && ctype_digit($hostPort)) {
        $host = $hostPart;
        if ($portRaw === '') {
            $portRaw = $hostPort;
        }
    }
}

// 2) If DB_PORT contains host:port (Railway-style), extract both.
if ($portRaw && strpos($portRaw, ':') !== false) {
    [$portHost, $portNumber] = explode(':', $portRaw, 2);
    if ($host === '' && $portHost !== '') {
        $host = $portHost;
    }
    $portRaw = $portNumber;
}

// 3) Prefer Railway public URL if explicit DB_* config is incomplete.
$publicUrl = trim((string)(getenv('MYSQL_PUBLIC_URL') ?: getenv('MYSQL_PUBLIC_URL_TCP') ?: ''));
if ($publicUrl && (!$host || !$portRaw || !$user || $password === false || !$database)) {
    $parts = parse_url($publicUrl);
    if (is_array($parts)) {
        $host = $host ?: ($parts['host'] ?? '');
        $portRaw = $portRaw ?: (string)($parts['port'] ?? '');
        $user = $user ?: (isset($parts['user']) ? urldecode($parts['user']) : '');
        if ($password === false) {
            $password = isset($parts['pass']) ? urldecode($parts['pass']) : '';
        }
        $database = $database ?: (isset($parts['path']) ? ltrim($parts['path'], '/') : '');
    }
}

// 4) Railway sometimes exposes MYSQLPORT as host:port.
$railwayPort = trim((string)(getenv('MYSQLPORT') ?: ''));
if ((!$host || !$portRaw) && $railwayPort && strpos($railwayPort, ':') !== false) {
    [$railHost, $railPort] = explode(':', $railwayPort, 2);
    $host = $host ?: $railHost;
    $portRaw = $portRaw ?: $railPort;
}

// 5) Fill credentials/database from Railway variables when available.
$user = $user ?: trim((string)(getenv('MYSQLUSER') ?: ''));
if ($password === false) {
    $password = (string)(getenv('MYSQLPASSWORD') ?: '');
}
$database = $database ?: trim((string)(getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: ''));

$port = (int)$portRaw;

// Local XAMPP fallback only when no remote DB host was configured.
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
    die('Server connection error. Please check DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD.');
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
