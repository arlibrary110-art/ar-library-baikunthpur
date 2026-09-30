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
    if (!is_array($data)) {
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        @unlink($safetyFile);
        json_out(false, 'The selected JSON file is not valid JSON.', [], 400);
    }

    // Legacy AR Library backups (old app format) are also accepted here.
    // They contain _meta.app = "AR Library" and top-level members/feeRecords.
    $isLegacy = (($data['_meta']['app'] ?? '') === 'AR Library')
        && is_array($data['members'] ?? null)
        && is_array($data['feeRecords'] ?? null);

    if ($isLegacy) {
        $legacyDate = static function ($v, $fallback = null) {
            $v = trim((string)$v);
            if ($v === '') return $fallback;
            try {
                $d = new DateTime($v);
                $d->setTimezone(new DateTimeZone('Asia/Kolkata'));
                return $d->format('Y-m-d');
            } catch (Throwable $e) {
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $fallback;
            }
        };
        $legacyDateTime = static function ($date, $time = '') use ($legacyDate) {
            $date = $legacyDate($date);
            $time = trim((string)$time);
            if (!$date || !preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $time)) return null;
            $p = explode(':', $time);
            return sprintf('%s %02d:%02d:%02d', $date, (int)$p[0], (int)$p[1], (int)($p[2] ?? 0));
        };
        $legacyShift = static function ($shift, $plan = '') {
            $shift = strtolower(trim((string)$shift));
            $plan = strtolower(trim((string)$plan));
            $reserved = strpos($plan, 'reserved') !== false;
            if (strpos($shift, 'morning') !== false) return $reserved ? 'Morning Shift Reserved' : 'Morning Shift';
            if (strpos($shift, 'evening') !== false) return $reserved ? 'Evening Shift Reserved' : 'Evening Shift';
            return $reserved ? 'Full Day Reserved' : 'Full Day';
        };
        $legacyPlan = static function ($from, $to, $category = '') use ($legacyDate) {
            $category = trim((string)$category);
            if (in_array($category, ['1', '1 Month'], true)) return '1 Month';
            if (in_array($category, ['3', '3 Months'], true)) return '3 Months';
            if (in_array($category, ['6', '6 Months'], true)) return '6 Months';
            $a = $legacyDate($from); $b = $legacyDate($to);
            if ($a && $b) {
                $days = (new DateTime($a))->diff(new DateTime($b))->days + 1;
                if ($days >= 175) return '6 Months';
                if ($days >= 80) return '3 Months';
                if ($days >= 20 && $days <= 45) return '1 Month';
            }
            return 'Custom';
        };
        $legacyMode = static function ($v) {
            $v = trim((string)$v);
            foreach (['Cash','UPI','Online','Bank','Card','Other'] as $x) {
                if (strcasecmp($x, $v) === 0) return $x;
            }
            return $v === '' ? 'Cash' : 'Other';
        };
        $legacyReceipt = static function ($base, &$seen) {
            $base = preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$base);
            $base = substr($base ?: 'OLD-FEE', 0, 45);
            $candidate = $base; $i = 2;
            while (isset($seen[$candidate])) {
                $suffix = '-' . $i++;
                $candidate = substr($base, 0, 50 - strlen($suffix)) . $suffix;
            }
            $seen[$candidate] = true;
            return $candidate;
        };

        $em = 0; $ep = 0;
        $r = $conn->query('SELECT COUNT(*) AS c FROM members');
        if ($r) $em = (int)($r->fetch_assoc()['c'] ?? 0);
        $r = $conn->query('SELECT COUNT(*) AS c FROM payments');
        if ($r) $ep = (int)($r->fetch_assoc()['c'] ?? 0);
        if ($em > 0 || $ep > 0) {
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            json_out(false, 'Old AR Library JSON detected, but current member/payment data already exists. Use Fresh Reset first, then restore this old JSON backup.', [], 400);
        }

        $counts = [
            'Members imported' => 0,
            'Fee payments imported' => 0,
            'Pending dues imported' => 0,
            'Attendance imported' => 0,
            'Seats imported' => 0,
            'Lockers imported' => 0,
            'Expenses imported' => 0,
            'Enquiries imported' => 0,
            'Settings imported' => 0,
        ];
        $map = []; $seen = [];
        $conn->query('SET FOREIGN_KEY_CHECKS=0');
        $conn->begin_transaction();
        try {
            foreach (($data['feeStructure'] ?? []) as $fee) {
                $plan = strtolower(trim((string)($fee['plan'] ?? '')));
                $amount = (float)($fee['amount'] ?? 0);
                if ($amount <= 0) continue;
                $full = strpos($plan, 'full day') !== false;
                $reserved = strpos($plan, 'reserved') !== false;
                $key = $full ? ($reserved ? 'full_reserved_one' : 'full_day_one') : ($reserved ? 'half_reserved_one' : 'half_day_one');
                $st = $conn->prepare('INSERT INTO fee_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value');
                if (!$st) throw new RuntimeException('Could not prepare fee settings import.');
                $st->bind_param('sd', $key, $amount);
                if (!$st->execute()) { $st->close(); throw new RuntimeException('Could not import fee settings.'); }
                $st->close();
            }

            $settings = [];
            $lib = $data['appSettings']['library'] ?? [];
            if (isset($lib['name'])) $settings['library_name'] = (string)$lib['name'];
            if (isset($lib['phone'])) $settings['phone'] = (string)$lib['phone'];
            if (isset($lib['addr'])) $settings['address'] = (string)$lib['addr'];
            $shifts = $data['appSettings']['shifts'] ?? [];
            if (isset($shifts['morningStart'])) $settings['opening_time'] = (string)$shifts['morningStart'];
            if (isset($shifts['eveningEnd'])) $settings['closing_time'] = (string)$shifts['eveningEnd'];
            $seats = $data['appSettings']['seats']['total'] ?? null;
            $lockers = $data['lockerCount']['total'] ?? ($data['appSettings']['lockers']['total'] ?? null);
            if ($seats !== null) $settings['total_seats'] = (string)(int)$seats;
            if ($lockers !== null) $settings['total_lockers'] = (string)(int)$lockers;
            foreach ($settings as $key => $value) {
                $st = $conn->prepare('INSERT INTO library_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value');
                if (!$st) throw new RuntimeException('Could not prepare library settings import.');
                $st->bind_param('ss', $key, $value);
                if (!$st->execute()) { $st->close(); throw new RuntimeException('Could not import library settings.'); }
                $st->close();
                $counts['Settings imported']++;
            }

            foreach ($data['members'] as $member) {
                $old = trim((string)($member['id'] ?? ''));
                $code = $old;
                $name = trim((string)($member['name'] ?? 'Member'));
                if ($code === '' || $name === '') continue;
                $join = $legacyDate($member['from'] ?? '', date('Y-m-d'));
                $valid = $legacyDate($member['to'] ?? '', null);
                $dob = $legacyDate($member['dob'] ?? '', null);
                $duration = $legacyPlan($member['from'] ?? '', $member['to'] ?? '', $member['category'] ?? '');
                $shift = $legacyShift($member['shift'] ?? '', $member['plan'] ?? '');
                $status = strtolower(trim((string)($member['feeStatus'] ?? ''))) === 'expired' ? 'Expired' : 'Active';
                $phone = trim((string)($member['phone'] ?? ''));
                $address = trim((string)($member['addr'] ?? ''));
                $created = $legacyDate($member['createdAt'] ?? '', $join);
                $email = '';
                $st = $conn->prepare('INSERT INTO members(member_id,name,phone,email,membership_plan,shift,joining_date,date_of_birth,validity_date,address,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
                if (!$st) throw new RuntimeException('Could not prepare member import.');
                $st->bind_param('ssssssssssss', $code, $name, $phone, $email, $duration, $shift, $join, $dob, $valid, $address, $status, $created);
                if (!$st->execute()) { $err = $st->error; $st->close(); throw new RuntimeException('Member ' . $code . ' failed: ' . $err); }
                $id = (int)$st->insert_id; $st->close();
                $map[$old] = ['id'=>$id, 'code'=>$code, 'shift'=>$shift, 'plan'=>(string)($member['plan'] ?? '')];
                $guardian = trim((string)($member['guardian'] ?? ''));
                if ($guardian !== '') {
                    $key = 'member_father_' . $id;
                    $st = $conn->prepare('INSERT INTO library_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value');
                    if ($st) { $st->bind_param('ss', $key, $guardian); $st->execute(); $st->close(); }
                }
                $counts['Members imported']++;
            }

            foreach ($data['feeRecords'] as $feeRecord) {
                $old = trim((string)($feeRecord['memberId'] ?? ''));
                if (!isset($map[$old])) continue;
                $member = $map[$old];
                $date = $legacyDate($feeRecord['date'] ?? '', date('Y-m-d'));
                $created = $legacyDate($feeRecord['createdAt'] ?? '', $date);
                $amount = max(0, (float)($feeRecord['amount'] ?? 0));
                $paid = max(0, (float)($feeRecord['paidAmount'] ?? 0));
                $due = max(0, (float)($feeRecord['dueAmount'] ?? 0));
                if ($paid <= 0 && $due <= 0 && $amount > 0) $paid = $amount;
                $mode = $legacyMode($feeRecord['mode'] ?? 'Cash');
                $receipt = $legacyReceipt($feeRecord['id'] ?? 'OLD-FEE', $seen);
                $plan = (string)($feeRecord['plan'] ?? '');
                $notes = trim((string)($feeRecord['notes'] ?? ''));

                if ($paid > 0) {
                    $type = $due > 0 ? 'Split' : 'Full'; $extra = 0.0;
                    $st = $conn->prepare('INSERT INTO payments(receipt_no,member_id,member_code,amount,fee_amount,additional_charges,payment_type,payment_date,payment_method,plan,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
                    if (!$st) throw new RuntimeException('Could not prepare payment import.');
                    $st->bind_param('sisdddssssss', $receipt, $member['id'], $member['code'], $paid, $paid, $extra, $type, $date, $mode, $plan, $notes, $created);
                    if (!$st->execute()) { $err = $st->error; $st->close(); throw new RuntimeException('Payment ' . $receipt . ' failed: ' . $err); }
                    $st->close();
                    $status = 'Paid';
                    $st = $conn->prepare('INSERT INTO member_fees(member_id,receipt_no,amount,payment_date,due_date,payment_method,status,remarks,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
                    if (!$st) throw new RuntimeException('Could not prepare paid fee import.');
                    $st->bind_param('isdssssss', $member['id'], $receipt, $paid, $date, $date, $mode, $status, $notes, $created);
                    if (!$st->execute()) { $err = $st->error; $st->close(); throw new RuntimeException('Paid fee history failed: ' . $err); }
                    $st->close();
                    $counts['Fee payments imported']++;
                }
                if ($due > 0) {
                    $dueReceipt = $receipt . '-D'; $status = 'Pending';
                    $remarks = 'Imported legacy pending balance' . ($notes !== '' ? ': ' . $notes : '');
                    $st = $conn->prepare('INSERT INTO member_fees(member_id,receipt_no,amount,payment_date,due_date,payment_method,status,remarks,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
                    if (!$st) throw new RuntimeException('Could not prepare pending fee import.');
                    $st->bind_param('isdssssss', $member['id'], $dueReceipt, $due, $date, $date, $mode, $status, $remarks, $created);
                    if (!$st->execute()) { $err = $st->error; $st->close(); throw new RuntimeException('Pending fee history failed: ' . $err); }
                    $st->close();
                    $counts['Pending dues imported']++;
                }
            }

            foreach (($data['attendance'] ?? []) as $attendance) {
                $old = trim((string)($attendance['memberId'] ?? ''));
                if (!isset($map[$old])) continue;
                $date = $legacyDate($attendance['date'] ?? '', null);
                if (!$date) continue;
                $in = $legacyDateTime($attendance['date'] ?? '', $attendance['in'] ?? '');
                $out = $legacyDateTime($attendance['date'] ?? '', $attendance['out'] ?? '');
                $status = $out ? 'Closed' : 'Open';
                $remarks = trim((string)($attendance['shift'] ?? ''));
                $created = $legacyDate($attendance['createdAt'] ?? '', $date);
                $st = $conn->prepare('INSERT INTO attendance(member_id,attendance_date,check_in,check_out,status,remarks,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(member_id,attendance_date) DO UPDATE SET check_in=excluded.check_in,check_out=excluded.check_out,status=excluded.status,remarks=excluded.remarks');
                if (!$st) throw new RuntimeException('Could not prepare attendance import.');
                $st->bind_param('isssssss', $map[$old]['id'], $date, $in, $out, $status, $remarks, $created, $created);
                if (!$st->execute()) { $err = $st->error; $st->close(); throw new RuntimeException('Attendance import failed: ' . $err); }
                $st->close();
                $counts['Attendance imported']++;
            }

            foreach ($data['members'] as $member) {
                $old = trim((string)($member['id'] ?? ''));
                $seat = trim((string)($member['seat'] ?? ''));
                if ($seat === '' || !isset($map[$old])) continue;
                $start = $legacyDate($member['from'] ?? '', date('Y-m-d'));
                $end = $legacyDate($member['to'] ?? '', null);
                $status = 'Assigned'; $remarks = 'Imported from legacy backup';
                $created = $legacyDate($member['createdAt'] ?? '', $start);
                $st = $conn->prepare('INSERT INTO member_seats(member_id,seat_no,shift,start_date,end_date,status,remarks,created_at) VALUES(?,?,?,?,?,?,?,?)');
                if (!$st) throw new RuntimeException('Could not prepare seat import.');
                $st->bind_param('isssssss', $map[$old]['id'], $seat, $map[$old]['shift'], $start, $end, $status, $remarks, $created);
                if (!$st->execute()) { $err = $st->error; $st->close(); throw new RuntimeException('Seat import failed: ' . $err); }
                $st->close();
                $counts['Seats imported']++;
            }

            foreach (($data['lockerData'] ?? []) as $locker) {
                $old = trim((string)($locker['memberId'] ?? ''));
                if (!isset($map[$old])) continue;
                $lockerNo = trim((string)($locker['no'] ?? ''));
                if ($lockerNo === '') continue;
                $start = $legacyDate($locker['from'] ?? '', date('Y-m-d'));
                $end = $legacyDate($locker['to'] ?? '', null);
                $status = 'Assigned'; $remarks = trim((string)($locker['notes'] ?? ''));
                $created = $legacyDate($locker['assignedAt'] ?? '', $start);
                $st = $conn->prepare('INSERT INTO lockers(locker_no,member_id,start_date,end_date,status,remarks,created_at) VALUES(?,?,?,?,?,?,?)');
                if (!$st) throw new RuntimeException('Could not prepare locker import.');
                $st->bind_param('sisssss', $lockerNo, $map[$old]['id'], $start, $end, $status, $remarks, $created);
                if (!$st->execute()) { $err = $st->error; $st->close(); throw new RuntimeException('Locker import failed: ' . $err); }
                $st->close();
                $counts['Lockers imported']++;
            }

            foreach (($data['expenses'] ?? []) as $expense) {
                $date = $legacyDate($expense['date'] ?? '', date('Y-m-d'));
                $category = trim((string)($expense['cat'] ?? 'Other')) ?: 'Other';
                $description = trim((string)($expense['desc'] ?? ''));
                $amount = max(0, (float)($expense['amount'] ?? 0));
                if ($amount <= 0) continue;
                $mode = $legacyMode($expense['mode'] ?? 'Cash');
                $notes = trim((string)($expense['notes'] ?? ''));
                $created = $legacyDate($expense['createdAt'] ?? '', $date);
                $vendor = '';
                $st = $conn->prepare('INSERT INTO expenses(expense_date,category,description,amount,payment_method,vendor,notes,created_at) VALUES(?,?,?,?,?,?,?,?)');
                if (!$st) throw new RuntimeException('Could not prepare expense import.');
                $st->bind_param('sssdssss', $date, $category, $description, $amount, $mode, $vendor, $notes, $created);
                if (!$st->execute()) { $err = $st->error; $st->close(); throw new RuntimeException('Expense import failed: ' . $err); }
                $st->close();
                $counts['Expenses imported']++;
            }

            foreach (($data['enquiries'] ?? []) as $enquiry) {
                $name = trim((string)($enquiry['name'] ?? ''));
                if ($name === '') continue;
                $phone = trim((string)($enquiry['phone'] ?? ''));
                $requirement = trim((string)($enquiry['cls'] ?? ''));
                $shift = trim((string)($enquiry['shift'] ?? ''));
                if ($shift !== '') $requirement = trim($requirement . ($requirement !== '' ? ' | ' : '') . 'Shift: ' . $shift);
                $follow = $legacyDate($enquiry['date'] ?? '', null);
                $rawStatus = strtolower(trim((string)($enquiry['status'] ?? 'Open')));
                $status = $rawStatus === 'converted' ? 'Converted' : ($rawStatus === 'closed' ? 'Closed' : 'Open');
                $notes = trim((string)($enquiry['notes'] ?? ''));
                $address = trim((string)($enquiry['address'] ?? ''));
                if ($address !== '') $notes = trim($notes . ($notes !== '' ? "\n" : '') . 'Address: ' . $address);
                $created = $legacyDate($enquiry['createdAt'] ?? '', $follow ?: date('Y-m-d'));
                $st = $conn->prepare('INSERT INTO enquiries(name,phone,requirement,follow_up,status,notes,created_at) VALUES(?,?,?,NULLIF(? ,""),?,?,?)');
                if (!$st) throw new RuntimeException('Could not prepare enquiry import.');
                $st->bind_param('sssssss', $name, $phone, $requirement, $follow, $status, $notes, $created);
                if (!$st->execute()) { $err = $st->error; $st->close(); throw new RuntimeException('Enquiry import failed: ' . $err); }
                $st->close();
                $counts['Enquiries imported']++;
            }

            $conn->commit();
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            app_log('legacy_json_restored', 'Legacy backup: ' . basename($original));
            json_out(true, 'Old AR Library JSON backup restored successfully. Current Admin account/password was kept unchanged.', array_merge($counts, ['safety_backup' => basename($safetyFile), 'legacy_format' => true]));
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            app_log('legacy_json_restore_failed', 'Backup: ' . basename($original) . ' | Error: ' . $e->getMessage());
            json_out(false, 'Old JSON restore failed and was rolled back: ' . $e->getMessage() . '. The automatic safety backup was kept in private storage.', [], 500);
        }
    }

    // Native AR Library JSON backup format.
    if (($data['format'] ?? '') !== 'ar_library_json_backup' || (int)($data['version'] ?? 0) !== 1 || !is_array($data['tables'] ?? null)) {
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
