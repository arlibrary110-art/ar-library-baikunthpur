<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_staff();
verify_state_change();

/** Build a complete SQL snapshot. Any failed table/row read aborts the download. */
function ar_build_sql_backup($conn, string $heading): string {
    $tables = [];
    $result = $conn->query('SHOW TABLES');
    if ($result === false) {
        throw new RuntimeException('Could not list database tables: ' . ($conn->error ?? 'database error'));
    }
    while ($row = $result->fetch_row()) {
        $table = (string)($row[0] ?? '');
        if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException('Database returned an invalid table name.');
        }
        $tables[] = $table;
    }
    if (!$tables) throw new RuntimeException('No database tables were found; backup was not created.');

    $out = '-- ' . $heading . "\n-- Generated: " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $tableCount = 0;
    $rowCount = 0;
    foreach ($tables as $table) {
        $qTable = str_replace('`', '``', $table);
        $createResult = $conn->query("SHOW CREATE TABLE `{$qTable}`");
        if ($createResult === false) throw new RuntimeException("Could not read table structure for {$table}: " . ($conn->error ?? 'database error'));
        $createRow = $createResult->fetch_assoc();
        $create = $createRow['Create Table'] ?? (is_array($createRow) ? (array_values($createRow)[1] ?? '') : '');
        if (!is_string($create) || trim($create) === '') throw new RuntimeException("Table structure is missing for {$table}.");

        $dataResult = $conn->query("SELECT * FROM `{$qTable}`");
        if ($dataResult === false) throw new RuntimeException("Could not read records from {$table}: " . ($conn->error ?? 'database error'));
        $fields = [];
        foreach ($dataResult->fetch_fields() as $field) {
            $fields[] = '`' . str_replace('`', '``', (string)$field->name) . '`';
        }
        if ($dataResult->num_rows > 0 && !$fields) throw new RuntimeException("Column information is missing for {$table}.");

        $out .= "DROP TABLE IF EXISTS `{$qTable}`;\n{$create};\n\n";
        $cols = implode(',', $fields);
        while ($data = $dataResult->fetch_assoc()) {
            if (!is_array($data) || count($data) !== count($fields)) throw new RuntimeException("A record could not be read completely from {$table}.");
            $values = [];
            foreach ($data as $value) {
                $values[] = $value === null ? 'NULL' : "'" . $conn->real_escape_string((string)$value) . "'";
            }
            $out .= "INSERT INTO `{$qTable}` ({$cols}) VALUES (" . implode(',', $values) . ");\n";
            $rowCount++;
        }
        $out .= "\n";
        $tableCount++;
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n-- Tables: {$tableCount}; Records: {$rowCount}\n";
    if ($tableCount !== count($tables) || trim($out) === '') throw new RuntimeException('Backup validation failed; incomplete backup was cancelled.');
    return $out;
}

try {
    $out = ar_build_sql_backup($conn, 'AR Library Management System SQL backup');
} catch (Throwable $e) {
    app_log('database_backup_failed', $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Backup cancelled: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: application/sql; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename=ar_library_backup_' . date('Y-m-d_H-i-s') . '.sql');
header('Content-Length: ' . strlen($out));
echo $out;
