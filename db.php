<?php
// AR Library - MySQL connection for XAMPP + Render + Railway.
// Render should use DB_* environment variables. No password is hard-coded here.

mysqli_report(MYSQLI_REPORT_OFF);

function ar_env($name) {
    $v = getenv($name);
    return ($v === false) ? '' : trim((string)$v);
}

$host = ar_env('DB_HOST');
$portRaw = ar_env('DB_PORT');
$user = ar_env('DB_USER');
$password = ar_env('DB_PASSWORD');
$database = ar_env('DB_NAME');

// Some hosting panels expose AR_LIBRARY_* names instead of DB_*.
if ($host === '') $host = ar_env('AR_LIBRARY_DB_HOST');
if ($portRaw === '') $portRaw = ar_env('AR_LIBRARY_DB_PORT');
if ($user === '') $user = ar_env('AR_LIBRARY_DB_USER');
if ($password === '') $password = ar_env('AR_LIBRARY_DB_PASSWORD');
if ($database === '') $database = ar_env('AR_LIBRARY_DB_NAME');

// Railway may expose MYSQL_PUBLIC_URL. Prefer it when explicit DB_* values are incomplete.
$publicUrl = ar_env('MYSQL_PUBLIC_URL');
if ($publicUrl === '') $publicUrl = ar_env('MYSQL_PUBLIC_URL_TCP');

if ($publicUrl !== '' && ($host === '' || $portRaw === '' || $user === '' || $password === '' || $database === '')) {
    $parts = @parse_url($publicUrl);
    if (is_array($parts)) {
        if ($host === '' && !empty($parts['host'])) $host = $parts['host'];
        if ($portRaw === '' && !empty($parts['port'])) $portRaw = (string)$parts['port'];
        if ($user === '' && isset($parts['user'])) $user = urldecode($parts['user']);
        if ($password === '' && isset($parts['pass'])) $password = urldecode($parts['pass']);
        if ($database === '' && !empty($parts['path'])) $database = ltrim($parts['path'], '/');
    }
}

// Railway sometimes exposes the public endpoint as MYSQLPORT="host:port".
$mysqlPort = ar_env('MYSQLPORT');
if (($host === '' || $portRaw === '') && $mysqlPort !== '') {
    if (preg_match('/^(.+):(\\d+)$/', $mysqlPort, $m)) {
        if ($host === '') $host = $m[1];
        if ($portRaw === '') $portRaw = $m[2];
    } elseif (ctype_digit($mysqlPort)) {
        if ($portRaw === '') $portRaw = $mysqlPort;
    }
}

// Railway public hostname can also be supplied separately.
if ($host === '') {
    $host = ar_env('MYSQL_PUBLIC_HOST');
}
if ($host === '') {
    $host = ar_env('MYSQL_PUBLIC_DOMAIN');
}

// Railway credential/database fallbacks.
if ($user === '') $user = ar_env('MYSQLUSER');
if ($password === '') $password = ar_env('MYSQLPASSWORD');
if ($database === '') $database = ar_env('MYSQLDATABASE');
if ($database === '') $database = ar_env('MYSQL_DATABASE');

// If DB_HOST itself contains host:port, split it.
if ($host !== '' && preg_match('/^([^:\\/]+):(\\d+)$/', $host, $m)) {
    $host = $m[1];
    if ($portRaw === '') $portRaw = $m[2];
}

// If DB_PORT was entered as host:port, split it too.
if ($portRaw !== '' && preg_match('/^([^:\\/]+):(\\d+)$/', $portRaw, $m)) {
    if ($host === '' || $host === 'localhost') $host = $m[1];
    $portRaw = $m[2];
}

$port = (int)$portRaw;

// Local XAMPP fallback. On Render, DB_HOST/DB_PORT must be configured.
if ($host === '') $host = 'localhost';
if ($port <= 0) $port = 3306;
if ($user === '') $user = 'root';
if ($database === '') $database = 'ar_library';

$conn = @new mysqli($host, $user, $password, $database, $port);
if ($conn->connect_errno) {
    http_response_code(500);
    // Do not expose password. This message is intentionally diagnostic so the
    // Render log/browser can identify host/port/auth/database failures.
    die('Server connection error: MySQL errno ' . (int)$conn->connect_errno . ' - ' . htmlspecialchars($conn->connect_error, ENT_QUOTES, 'UTF-8') . ' | Host=' . htmlspecialchars($host, ENT_QUOTES, 'UTF-8') . ' | Port=' . (int)$port . ' | Database=' . htmlspecialchars($database, ENT_QUOTES, 'UTF-8') . ' | User=' . htmlspecialchars($user, ENT_QUOTES, 'UTF-8'));
}

$conn->set_charset('utf8mb4');

// Compatibility migration for existing installations.
$colCheck = $conn->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'remarks'");
if ($colCheck) {
    $row = $colCheck->fetch_assoc();
    if ((int)($row['c'] ?? 0) === 0) {
        @$conn->query("ALTER TABLE attendance ADD COLUMN remarks VARCHAR(255) NULL AFTER status");
    }
    $colCheck->free();
}
?>
