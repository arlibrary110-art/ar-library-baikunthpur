<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_admin();
verify_state_change();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(false, 'POST request required.', [], 405);
}

if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
    json_out(false, 'Please select a valid SQL or JSON backup file.', [], 400);
}

$file = $_FILES['backup_file'];
if ((int)$file['size'] > 20 * 1024 * 1024) {
    json_out(false, 'Backup file must be 20 MB or smaller.', [], 400);
}

$original = (string)($file['name'] ?? '');
$ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
if (!in_array($ext, ['sql', 'json'], true)) {
    json_out(false, 'Only .sql and .json backup files are allowed.', [], 400);
}

$tmp = $file['tmp_name'];
$contents = @file_get_contents($tmp);
if ($contents === false || trim($contents) === '') {
    json_out(false, 'The selected backup file is empty or unreadable.', [], 400);
}

// Safety backups are stored outside the web root.
$privateDir = private_storage_dir();
$backupDir = $privateDir . DIRECTORY_SEPARATOR . 'backups';
if (!is_dir($backupDir) && !@mkdir($backupDir, 0700, true)) {
    json_out(false, 'Private backup storage could not be created. Restore was cancelled.', [], 500);
}

$safetyFile = $backupDir . DIRECTORY_SEPARATOR . 'pre_restore_' . date('Y-m-d_H-i-s') . '.sql';

$currentTables = [];
$r = $conn->query('SHOW TABLES');
if ($r) {
    while ($row = $r->fetch_row()) $currentTables[] = $row[0];
}

$safety = "-- Automatic safety backup before restore\n-- Generated: " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n";
foreach ($currentTables as $table) {
    $qTable = str_replace('`', '``', $table);
    $cr = $conn->query("SHOW CREATE TABLE `{$qTable}`");
    if (!$cr) continue;
    $row = $cr->fetch_assoc();
    $create = $row['Create Table'] ?? (array_values($row)[1] ?? '');
    $safety .= "DROP TABLE IF EXISTS `{$qTable}`;\n{$create};\n\n";
    $rs = $conn->query("SELECT * FROM `{$qTable}`");
    if ($rs && $rs->num_rows) {
        $fields = [];
        foreach ($rs->fetch_fields() as $f) $fields[] = '`' . str_replace('`', '``', $f->name) . '`';
        $cols = implode(',', $fields);
        while ($data = $rs->fetch_assoc()) {
            $vals = [];
            foreach ($data as $v) $vals[] = is_null($v) ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
            $safety .= "INSERT INTO `{$qTable}` ({$cols}) VALUES (" . implode(',', $vals) . ");\n";
        }
        $safety .= "\n";
    }
}
$safety .= "SET FOREIGN_KEY_CHECKS=1;\n";

if (@file_put_contents($safetyFile, $safety, LOCK_EX) === false) {
    json_out(false, 'Could not create the automatic safety backup. Restore was cancelled.', [], 500);
}
@chmod($safetyFile, 0600);

$conn->query('SET FOREIGN_KEY_CHECKS=0');
$ok = false;
$error = '';

if ($ext === 'sql') {
    $sql = $contents;
    // Reject obvious executable/server-level directives.
    if (preg_match('/(^|\n)\s*(DELIMITER|CREATE\s+USER|GRANT|REVOKE|DROP\s+DATABASE)\b/i', $sql)) {
        @unlink($safetyFile);
        json_out(false, 'This SQL file contains unsupported database/server directives.', [], 400);
    }

    $ok = $conn->multi_query($sql);
    $error = $ok ? '' : $conn->error;
    while ($conn->more_results()) {
        $conn->next_result();
        if ($conn->errno && $error === '') $error = $conn->error;
    }
} else {
    $data = json_decode($contents, true);
    if (!is_array($data) || ($data['format'] ?? '') !== 'ar_library_json_backup' || (int)($data['version'] ?? 0) !== 1 || !is_array($data['tables'] ?? null)) {
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        @unlink($safetyFile);
        json_out(false, 'Invalid AR Library JSON backup file.', [], 400);
    }

    $ok = true;
    foreach ($data['tables'] as $tableData) {
        $table = (string)($tableData['name'] ?? '');
        $create = trim((string)($tableData['create_sql'] ?? ''));
        $columns = $tableData['columns'] ?? [];
        $rows = $tableData['rows'] ?? [];

        if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table) || $create === '' || !preg_match('/^CREATE\s+TABLE\b/i', $create) || !is_array($columns) || !is_array($rows)) {
            $ok = false;
            $error = 'Invalid table data in JSON backup.';
            break;
        }
        foreach ($columns as $column) {
            if (!is_string($column) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                $ok = false;
                $error = 'Invalid column name in JSON backup.';
                break 2;
            }
        }

        $qTable = '`' . str_replace('`', '``', $table) . '`';
        if (!$conn->query("DROP TABLE IF EXISTS {$qTable}")) {
            $ok = false; $error = $conn->error; break;
        }
        if (!$conn->query($create)) {
            $ok = false; $error = $conn->error; break;
        }

        if (count($columns) && count($rows)) {
            $quotedCols = array_map(function($c){ return '`' . str_replace('`', '``', $c) . '`'; }, $columns);
            $colsSql = implode(',', $quotedCols);
            foreach ($rows as $rowData) {
                if (!is_array($rowData)) { $ok = false; $error = 'Invalid row data in JSON backup.'; break 2; }
                $vals = [];
                foreach ($columns as $column) {
                    $v = $rowData[$column] ?? null;
                    $vals[] = is_null($v) ? 'NULL' : "'" . $conn->real_escape_string((string)$v) . "'";
                }
                if (!$conn->query("INSERT INTO {$qTable} ({$colsSql}) VALUES (" . implode(',', $vals) . ")")) {
                    $ok = false; $error = $conn->error; break 2;
                }
            }
        }
    }
}

$conn->query('SET FOREIGN_KEY_CHECKS=1');

if (!$ok || $error !== '') {
    app_log('database_restore_failed', 'Backup: ' . basename($original) . ' | Error: ' . $error);
    json_out(false, 'Restore failed: ' . $error . '. The automatic safety backup was kept in private storage.', [], 500);
}

app_log('database_restored', 'Backup: ' . basename($original) . ' | Safety backup: ' . basename($safetyFile));
json_out(true, 'Database restored successfully. A safety backup was created in private storage.', ['safety_backup' => basename($safetyFile)]);
