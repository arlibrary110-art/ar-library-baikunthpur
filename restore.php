<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_admin(); // Restore is restricted to Admin; Staff can never restore.
verify_state_change();
require_csrf();

/** Split a plain SQL backup into statements without breaking quoted text or comments. */
function ar_restore_split_sql(string $sql): array {
    $statements = [];
    $buffer = '';
    $quote = '';
    $lineComment = false;
    $blockComment = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i];
        $next = ($i + 1 < $length) ? $sql[$i + 1] : '';

        if ($lineComment) {
            if ($ch === "\n" || $ch === "\r") {
                $lineComment = false;
                $buffer .= "\n";
            }
            continue;
        }
        if ($blockComment) {
            if ($ch === '*' && $next === '/') { $blockComment = false; $i++; }
            continue;
        }
        if ($quote !== '') {
            $buffer .= $ch;
            if ($ch === '\\' && $quote !== '`' && $i + 1 < $length) {
                $buffer .= $sql[++$i];
                continue;
            }
            if ($ch === $quote) {
                if ($next === $quote) { $buffer .= $sql[++$i]; }
                else { $quote = ''; }
            }
            continue;
        }

        if ($ch === '/' && $next === '*') { $blockComment = true; $i++; continue; }
        if ($ch === '#' || ($ch === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2])))) {
            $lineComment = true;
            if ($ch === '-') $i++;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $buffer .= $ch; continue; }
        if ($ch === ';') {
            $statement = trim($buffer);
            if ($statement !== '') $statements[] = $statement;
            $buffer = '';
            continue;
        }
        $buffer .= $ch;
    }

    if ($quote !== '' || $blockComment) {
        throw new RuntimeException('SQL backup contains an unfinished quoted value or comment.');
    }
    $statement = trim($buffer);
    if ($statement !== '') $statements[] = $statement;
    return $statements;
}


/** Build a complete SQL snapshot. Any failed table/row read aborts the download. */
function ar_restore_build_sql_backup($conn, string $heading): string {
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


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(false, 'POST request required.', [], 405);
}

$legacyChunkRequest = false;
if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
    json_out(false, 'Please select a valid SQL backup file.', [], 400);
}
$file = $_FILES['backup_file'];
if ((int)$file['size'] > 20 * 1024 * 1024) {
    json_out(false, 'Backup file must be 20 MB or smaller.', [], 400);
}
$original = (string)($file['name'] ?? '');
$ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
if ($ext !== 'sql') {
    json_out(false, 'Only .sql backup files are allowed. JSON restore is disabled.', [], 400);
}
$tmp = $file['tmp_name'];
$contents = @file_get_contents($tmp);
if ($contents === false || trim($contents) === '') {
    json_out(false, 'The selected SQL backup file is empty or unreadable.', [], 400);
}

// Safety backups are stored outside the web root.
$privateDir = private_storage_dir();
$backupDir = $privateDir . DIRECTORY_SEPARATOR . 'backups';
if (!is_dir($backupDir) && !@mkdir($backupDir, 0700, true)) {
    json_out(false, 'Private backup storage could not be created. Restore was cancelled.', [], 500);
}

$safetyFile = $backupDir . DIRECTORY_SEPARATOR . 'pre_restore_' . date('Y-m-d_H-i-s') . '.sql';
if (!$legacyChunkRequest) {
    try {
        $safety = ar_restore_build_sql_backup($conn, 'Automatic safety backup before restore');
        if (@file_put_contents($safetyFile, $safety, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the automatic safety backup.');
        }
        @chmod($safetyFile, 0600);
    } catch (Throwable $e) {
        json_out(false, 'Could not create a complete automatic safety backup. Restore was cancelled: ' . $e->getMessage(), [], 500);
    }
}

$ok = false;
$error = '';
$transactionStarted = false;
$transactionCommitted = false;
$rollbackRecovered = false;

try {
    if ($ext !== 'sql') throw new RuntimeException('Only SQL backups are supported.');
    // Reject executable/server-level directives before opening the transaction.
    if (preg_match('/(^|\n)\s*(DELIMITER|CREATE\s+USER|GRANT|REVOKE|DROP\s+DATABASE)\b/i', $contents)) {
        throw new RuntimeException('This SQL file contains unsupported database/server directives.');
    }
    $statements = ar_restore_split_sql($contents);
    if (!$statements) throw new RuntimeException('The SQL backup does not contain any SQL statements.');

    // A complete application backup must include every currently known table.
    $currentTables = [];
    $tableResult = $conn->query('SHOW TABLES');
    if ($tableResult === false) throw new RuntimeException('Could not verify current database tables.');
    while ($tableRow = $tableResult->fetch_row()) $currentTables[] = (string)($tableRow[0] ?? '');
    $backupTables = [];
    $createdTables = [];
    foreach ($statements as $statement) {
        if (preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`?([A-Za-z0-9_]+)`?$/i', trim($statement), $match)) $backupTables[] = $match[1];
        if (preg_match('/^CREATE\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i', trim($statement), $match)) $createdTables[] = $match[1];
    }
    $missingTables = array_values(array_diff($currentTables, $backupTables));
    $missingCreate = array_values(array_diff($currentTables, $createdTables));
    if ($missingTables || $missingCreate) {
        $missing = array_values(array_unique(array_merge($missingTables, $missingCreate)));
        throw new RuntimeException('Incomplete SQL backup. Missing table structure/data sections: ' . implode(', ', $missing));
    }

    if (!$conn->begin_transaction()) throw new RuntimeException('Could not start a protected restore transaction.');
    $transactionStarted = true;
    if (!$conn->query('SET FOREIGN_KEY_CHECKS=0')) throw new RuntimeException('Could not disable foreign-key checks.');
    foreach ($statements as $statement) {
        // Accept only statements emitted by AR Library SQL backups.
        if (!preg_match('/^(?:SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]|DROP\s+TABLE\s+IF\s+EXISTS\s+`?[A-Za-z0-9_]+`?|CREATE\s+TABLE\b|INSERT\s+INTO\b)/i', $statement)) {
            throw new RuntimeException('Unsupported SQL statement found in backup.');
        }
        if (!$conn->query($statement)) {
            throw new RuntimeException($conn->error !== '' ? $conn->error : 'A SQL statement could not be executed.');
        }
    }
    if (!$conn->query('SET FOREIGN_KEY_CHECKS=1')) throw new RuntimeException('Could not restore foreign-key settings.');
    if (!$conn->commit()) throw new RuntimeException('Could not commit the restore transaction.');
    $transactionStarted = false;
    $transactionCommitted = true;

    // Flush the committed snapshot now, so storage errors are caught in this request.
    if (method_exists($conn, 'syncNow')) $conn->syncNow();
    $ok = true;
} catch (Throwable $e) {
    $error = $e->getMessage();
    if ($transactionStarted) {
        try { $conn->rollback(); } catch (Throwable $rollbackError) { $error .= ' | Database rollback error: ' . $rollbackError->getMessage(); }
        $transactionStarted = false;
    }
    if ($transactionCommitted) {
        // A storage flush can fail after the local transaction committed. Replay
        // the pre-restore SQL snapshot as a compensating rollback and flush it.
        try {
            $safetySql = @file_get_contents($safetyFile);
            if ($safetySql === false || trim($safetySql) === '') throw new RuntimeException('Safety SQL file is unavailable.');
            $safetyStatements = ar_restore_split_sql($safetySql);
            if (!$conn->begin_transaction()) throw new RuntimeException('Could not start safety rollback transaction.');
            foreach ($safetyStatements as $safetyStatement) {
                if (!preg_match('/^(?:SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]|DROP\s+TABLE\s+IF\s+EXISTS\s+`?[A-Za-z0-9_]+`?|CREATE\s+TABLE\b|INSERT\s+INTO\b)/i', $safetyStatement)) {
                    throw new RuntimeException('Safety backup contains an unsupported statement.');
                }
                if (!$conn->query($safetyStatement)) throw new RuntimeException($conn->error !== '' ? $conn->error : 'Safety rollback statement failed.');
            }
            if (!$conn->commit()) throw new RuntimeException('Could not commit safety rollback.');
            if (method_exists($conn, 'syncNow')) $conn->syncNow();
            $rollbackRecovered = true;
        } catch (Throwable $rollbackError) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            $error .= ' | Automatic rollback could not be confirmed: ' . $rollbackError->getMessage();
        }
    } else {
        try {
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            // A failed SQL transaction leaves the local data unchanged; persist it.
            if (method_exists($conn, 'syncNow')) $conn->syncNow();
            $rollbackRecovered = true;
        } catch (Throwable $persistError) {
            $error .= ' | Original data could not be fully synchronized after rollback: ' . $persistError->getMessage();
        }
    }
}

if (!$ok || $error !== '') {
    app_log('database_restore_failed', 'Backup: ' . basename($original) . ' | Error: ' . $error);
    json_out(false, 'Restore failed: ' . $error . ($rollbackRecovered ? '. The previous database state was restored.' : '. The automatic safety backup was kept in private storage; rollback could not be confirmed.'), ['safety_backup' => basename($safetyFile), 'rollback_confirmed' => $rollbackRecovered], 500);
}

app_log('database_restored', 'Backup: ' . basename($original) . ' | Safety backup: ' . basename($safetyFile));
json_out(true, 'Database restored successfully. A safety backup was created in private storage.', ['safety_backup' => basename($safetyFile)]);
