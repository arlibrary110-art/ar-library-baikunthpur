<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_admin();
verify_state_change();

$tables = [];
$r = $conn->query('SHOW TABLES');
if ($r) {
    while ($x = $r->fetch_row()) $tables[] = $x[0];
}

$out = "-- AR Library Management System backup\n-- Generated: " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    $qTable = str_replace('`', '``', $table);
    $cr = $conn->query("SHOW CREATE TABLE `{$qTable}`");
    if (!$cr) continue;
    $row = $cr->fetch_assoc();
    $create = $row['Create Table'] ?? (array_values($row)[1] ?? '');
    $out .= "DROP TABLE IF EXISTS `{$qTable}`;\n{$create};\n\n";

    $rs = $conn->query("SELECT * FROM `{$qTable}`");
    if ($rs && $rs->num_rows) {
        $fields = [];
        foreach ($rs->fetch_fields() as $f) {
            $fields[] = '`' . str_replace('`', '``', $f->name) . '`';
        }
        $cols = implode(',', $fields);
        while ($data = $rs->fetch_assoc()) {
            $vals = [];
            foreach ($data as $v) {
                $vals[] = is_null($v) ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
            }
            $out .= "INSERT INTO `{$qTable}` ({$cols}) VALUES (" . implode(',', $vals) . ");\n";
        }
        $out .= "\n";
    }
}
$out .= "SET FOREIGN_KEY_CHECKS=1;\n";

header('Content-Type: application/sql; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename=ar_library_backup_' . date('Y-m-d_H-i-s') . '.sql');
header('Content-Length: ' . strlen($out));
echo $out;
