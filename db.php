<?php
// AR Library - Render + Railway + XAMPP database connector.
// Priority: DB_* -> Railway public vars -> local XAMPP fallback.
mysqli_report(MYSQLI_REPORT_OFF);

function ar_env($key, $default = '') {
    $v = getenv($key);
    return ($v === false) ? $default : trim((string)$v);
}

function ar_split_host_port($host, $port) {
    $host = trim((string)$host);
    $port = trim((string)$port);
    if (strpos($port, ':') !== false && strpos($host, ':') === false) {
        $parts = explode(':', $port, 2);
        if ($parts[0] !== '' && !ctype_digit($parts[0])) $host = trim($parts[0]);
        if (isset($parts[1]) && ctype_digit($parts[1])) $port = $parts[1];
    }
    if ($host !== '' && preg_match('/^\[(.+)\]:(\d+)$/', $host, $m)) {
        $host = $m[1]; $port = $m[2];
    } elseif ($host !== '' && substr_count($host, ':') === 1) {
        [$h,$p] = explode(':', $host, 2);
        if ($h !== '' && ctype_digit($p)) { $host=$h; $port=$p; }
    }
    return [$host, (int)$port];
}

$host = ar_env('DB_HOST');
$port = ar_env('DB_PORT');
$user = ar_env('DB_USER');
$password = getenv('DB_PASSWORD');
$database = ar_env('DB_NAME');

// Accept Railway public TCP variables when DB_* are not fully supplied.
$publicUrl = ar_env('MYSQL_PUBLIC_URL') ?: ar_env('MYSQL_PUBLIC_URL_TCP');
if ($publicUrl !== '') {
    $parts = parse_url($publicUrl);
    if (is_array($parts)) {
        $host = $host ?: ($parts['host'] ?? '');
        $port = $port ?: ($parts['port'] ?? '');
        $user = $user ?: (isset($parts['user']) ? urldecode($parts['user']) : '');
        if ($password === false) $password = isset($parts['pass']) ? urldecode($parts['pass']) : '';
        $database = $database ?: (isset($parts['path']) ? ltrim($parts['path'], '/') : '');
    }
}

$railwayHost = ar_env('MYSQL_PUBLIC_HOST') ?: ar_env('MYSQL_PUBLIC_DOMAIN');
$railwayPort = ar_env('MYSQL_PUBLIC_PORT');
if ($railwayHost !== '') $host = $host ?: $railwayHost;
if ($railwayPort !== '') $port = $port ?: $railwayPort;

// Some Railway setups expose MYSQLPORT as host:port.
$mysqlPortVar = ar_env('MYSQLPORT');
if ($mysqlPortVar !== '') {
    if ($host === '' || $port === '') {
        [$h,$p] = ar_split_host_port($host, $mysqlPortVar);
        $host = $host ?: $h;
        $port = $port ?: $p;
    }
}

$user = $user ?: ar_env('MYSQLUSER', 'root');
if ($password === false) $password = ar_env('MYSQLPASSWORD', '');
$database = $database ?: ar_env('MYSQLDATABASE');
$database = $database ?: ar_env('MYSQL_DATABASE');
$database = $database ?: 'ar_library';

[$host,$port] = ar_split_host_port($host, $port ?: '3306');
if ($host === '') $host = 'localhost';
if (!$port) $port = 3306;

$databaseCandidates = [];
foreach ([$database, 'railway', 'ar_library'] as $candidate) {
    $candidate = trim((string)$candidate);
    if ($candidate !== '' && !in_array($candidate, $databaseCandidates, true)) {
        $databaseCandidates[] = $candidate;
    }
}

$conn = null;
$selectedDatabase = '';
foreach ($databaseCandidates as $candidateDb) {
    $test = @new mysqli($host, $user, $password, $candidateDb, $port);
    if (!$test->connect_error) {
        $conn = $test;
        $selectedDatabase = $candidateDb;
        break;
    }
    @$test->close();
}

if (!$conn || $conn->connect_error) {
    http_response_code(500);
    die('Server connection error. Check Render DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD and Railway public TCP settings.');
}
$database = $selectedDatabase;
$conn->set_charset('utf8mb4');

// Non-destructive schema bootstrap: if the database is empty, import the
// bundled schema once. This never drops or overwrites existing tables.
$schemaPath = '/opt/ar_library_private/database.sql';
$hasStaff = false;
$check = @$conn->query("SHOW TABLES LIKE 'staff'");
if ($check) { $hasStaff = $check->num_rows > 0; $check->free(); }
if (!$hasStaff && is_readable($schemaPath)) {
    $sql = @file_get_contents($schemaPath);
    if ($sql !== false && trim($sql) !== '') {
        // Remove CREATE DATABASE/USE so the already-selected database is used.
        $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS[^;]+;\s*/i', '', $sql);
        $sql = preg_replace('/\bUSE\s+`?ar_library`?\s*;\s*/i', '', $sql);
        @$conn->multi_query($sql);
        while (@$conn->more_results() && @$conn->next_result()) { }
    }
}

// Compatibility migration for older installations.
$colCheck = @$conn->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'remarks'");
if ($colCheck) {
    $row = $colCheck->fetch_assoc();
    if ((int)($row['c'] ?? 0) === 0) @ $conn->query("ALTER TABLE attendance ADD COLUMN remarks VARCHAR(255) NULL AFTER status");
    $colCheck->free();
}
