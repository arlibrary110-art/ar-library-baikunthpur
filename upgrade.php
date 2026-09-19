<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_admin();
verify_state_change();

$privateDir = function_exists('private_storage_dir') ? private_storage_dir() : (dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . DIRECTORY_SEPARATOR . 'AR_Library_Private');
$schemaFile = $privateDir . DIRECTORY_SEPARATOR . 'database.sql';

if (!is_file($schemaFile) || !is_readable($schemaFile)) {
    json_out(false, 'Private database schema not found. Please place database.sql inside AR_Library_Private.', [], 500);
}

$schema = file_get_contents($schemaFile);
if ($schema === false || trim($schema) === '') {
    json_out(false, 'Private database schema is empty or unreadable.', [], 500);
}

// Split on semicolons while keeping this installer intentionally simple for the project schema.
$statements = preg_split('/;\s*(?=(?:CREATE|INSERT|USE|ALTER)\b)/i', $schema);
$done = 0;
$errors = [];

foreach ($statements as $sql) {
    $sql = trim($sql);
    if ($sql === '' || preg_match('/^(?:CREATE\s+DATABASE|USE\s+)/i', $sql)) continue;
    if ($conn->query($sql)) $done++;
    else $errors[] = $conn->error;
}

if (!$errors) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Upgrade completed. Statements applied: {$done}\n";
} else {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Upgrade completed with errors. Applied: {$done}\n" . implode("\n", $errors);
}
