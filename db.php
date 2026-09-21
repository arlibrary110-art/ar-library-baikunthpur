<?php
// AR Library - Render + Railway + XAMPP database connector.
// Uses Render DB_* variables first, then Railway public variables, then XAMPP fallback.
mysqli_report(MYSQLI_REPORT_OFF);

function ar_env($key, $default = '') {
    $v = getenv($key);
    return ($v === false) ? $default : trim((string)$v);
}

function ar_host_port($host, $port) {
    $host = trim((string)$host);
    $port = trim((string)$port);

    // Railway may expose MYSQLPORT as host:port.
    if ($port !== '' && substr_count($port, ':') === 1) {
        [$h, $p] = explode(':', $port, 2);
        if ($host === '' && $h !== '') $host = trim($h);
        if (ctype_digit($p)) $port = $p;
    }
    if ($host !== '' && preg_match('/^\[(.+)\]:(\d+)$/', $host, $m)) {
        $host = $m[1];
        $port = $m[2];
    } elseif ($host !== '' && substr_count($host, ':') === 1) {
        [$h, $p] = explode(':', $host, 2);
        if ($h !== '' && ctype_digit($p)) {
            $host = trim($h);
            $port = $p;
        }
    }
    return [$host, (int)$port];
}

$host = ar_env('DB_HOST');
$port = ar_env('DB_PORT');
$user = ar_env('DB_USER');
$password = getenv('DB_PASSWORD');
$database = ar_env('DB_NAME');

// Railway public URL fallback.
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

// Railway public host/port variables, if exposed.
$host = $host ?: ar_env('MYSQL_PUBLIC_HOST') ?: ar_env('MYSQL_PUBLIC_DOMAIN');
$port = $port ?: ar_env('MYSQL_PUBLIC_PORT');

// Some Railway configurations expose MYSQLPORT as host:port.
if ($host === '' || $port === '') {
    [$h, $p] = ar_host_port($host, ar_env('MYSQLPORT'));
    $host = $host ?: $h;
    $port = $port ?: ($p ?: '');
}

$user = $user ?: ar_env('MYSQLUSER', 'root');
if ($password === false) $password = ar_env('MYSQLPASSWORD', '');
$database = $database ?: ar_env('MYSQLDATABASE');
$database = $database ?: ar_env('MYSQL_DATABASE');
$database = $database ?: 'ar_library';

[$host, $port] = ar_host_port($host, $port ?: '3306');
if ($host === '') $host = 'localhost';
if (!$port) $port = 3306;

// Try configured DB first, then Railway's default DB, then the legacy XAMPP DB.
$databaseCandidates = [];
foreach ([$database, 'railway', 'ar_library'] as $candidate) {
    $candidate = trim((string)$candidate);
    if ($candidate !== '' && !in_array($candidate, $databaseCandidates, true)) {
        $databaseCandidates[] = $candidate;
    }
}

$conn = null;
$lastErrno = 0;
$lastError = '';
$selectedDatabase = '';
foreach ($databaseCandidates as $candidateDb) {
    $test = @new mysqli($host, $user, $password, $candidateDb, $port);
    if (!$test->connect_error) {
        $conn = $test;
        $selectedDatabase = $candidateDb;
        break;
    }
    $lastErrno = (int)$test->connect_errno;
    $lastError = (string)$test->connect_error;
    @$test->close();
}

if (!$conn || $conn->connect_error) {
    error_log('AR Library DB connection failed: errno=' . $lastErrno . ' error=' . $lastError . ' host=' . $host . ' port=' . $port . ' user=' . $user . ' db_candidates=' . implode(',', $databaseCandidates));
    $acceptsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'login.php';
    http_response_code(500);
    if ($acceptsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed. Check Render DB_HOST/DB_PORT/DB_USER/DB_PASSWORD and Railway Public TCP settings.',
            'db_errno' => $lastErrno
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    die('Server connection error. Please check the database configuration.');
}

$database = $selectedDatabase;
$conn->set_charset('utf8mb4');

// Schema/bootstrap work is intentionally one-time per deployment. The production
// database is already initialized; repeating SHOW/ALTER/CREATE on every request
// caused avoidable Render -> Railway latency.
$schemaPath = '/opt/ar_library_private/database.sql';
if (!is_readable($schemaPath)) {
    $schemaPath = __DIR__ . '/AR_Library_Private/database.sql';
}
$schemaVersion = 'ar-library-schema-v3';
$schemaMarker = sys_get_temp_dir() . '/ar_library_schema_' . md5($host . '|' . $port . '|' . $database) . '.ready';
$schemaReady = is_readable($schemaMarker)
    && trim((string)@file_get_contents($schemaMarker)) === $schemaVersion;

if (!$schemaReady || ar_env('AR_FORCE_SCHEMA_CHECK') === '1') {
    $requiredTables = ['staff','members','member_fees','payments','attendance','member_seats','lockers','enquiries','expenses','library_settings','activity_logs','fee_settings'];
    $missingTable = false;
    foreach ($requiredTables as $table) {
        $safeTable = $conn->real_escape_string($table);
        $q = @$conn->query("SHOW TABLES LIKE '{$safeTable}'");
        if (!$q || $q->num_rows === 0) {
            $missingTable = true;
            if ($q) $q->free();
            break;
        }
        $q->free();
    }

    if ($missingTable && is_readable($schemaPath)) {
        $sql = @file_get_contents($schemaPath);
        if ($sql !== false && trim($sql) !== '') {
            $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS[^;]+;\s*/i', '', $sql);
            $sql = preg_replace('/\bUSE\s+`?ar_library`?\s*;\s*/i', '', $sql);
            if (@$conn->multi_query($sql)) {
                while (@$conn->more_results()) { @$conn->next_result(); }
            }
        }
    }

    // Compatibility migrations now run only once per deployment.
    @$conn->query("ALTER TABLE staff ADD COLUMN photo VARCHAR(255) NULL AFTER name");
    @$conn->query("ALTER TABLE staff ADD COLUMN permissions_configured TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    @$conn->query("ALTER TABLE attendance ADD COLUMN remarks VARCHAR(255) NULL AFTER status");
    @$conn->query("CREATE TABLE IF NOT EXISTS staff_permissions (
        staff_id VARCHAR(50) NOT NULL,
        permission_key VARCHAR(60) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (staff_id, permission_key),
        INDEX idx_staff_perm(staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @file_put_contents($schemaMarker, $schemaVersion, LOCK_EX);
}
