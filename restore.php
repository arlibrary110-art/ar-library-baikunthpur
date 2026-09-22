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
    json_out(false, 'Please select a valid SQL backup file.', [], 400);
}

$file = $_FILES['backup_file'];
if ((int)$file['size'] > 20 * 1024 * 1024) {
    json_out(false, 'Backup file must be 20 MB or smaller.', [], 400);
}

$original = (string)($file['name'] ?? '');
if (!preg_match('/\.sql$/i', $original)) {
    json_out(false, 'Only .sql backup files are allowed.', [], 400);
}

$tmp = $file['tmp_name'];
$sql = @file_get_contents($tmp);
if ($sql === false || trim($sql) === '') {
    json_out(false, 'The selected backup file is empty or unreadable.', [], 400);
}

// Accept backups produced by this application. Reject obvious executable SQL directives.
if (preg_match('/(^|\n)\s*(DELIMITER|CREATE\s+USER|GRANT|REVOKE|DROP\s+DATABASE)\b/i', $sql)) {
    json_out(false, 'This SQL file contains unsupported database/server directives.', [], 400);
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
$ok = $conn->multi_query($sql);
$error = $ok ? '' : $conn->error;
while ($conn->more_results()) {
    $conn->next_result();
    if ($conn->errno && $error === '') $error = $conn->error;
}
$conn->query('SET FOREIGN_KEY_CHECKS=1');

if (!$ok || $error !== '') {
    app_log('database_restore_failed', 'Backup: ' . basename($original) . ' | Error: ' . $error);
    json_out(false, 'Restore failed: ' . $error . '. The automatic safety backup was kept in private storage.', [], 500);
}

app_log('database_restored', 'Backup: ' . basename($original) . ' | Safety backup: ' . basename($safetyFile));
json_out(true, 'Database restored successfully. A safety backup was created in private storage.', ['safety_backup' => basename($safetyFile)]);
