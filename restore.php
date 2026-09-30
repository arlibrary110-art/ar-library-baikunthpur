<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_admin();
verify_state_change();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(false, 'POST request required.', [], 405);
}

$legacyChunkRequest = ((string)($_POST['legacy_action'] ?? '') === 'chunk');
if (!$legacyChunkRequest) {
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
} else {
    // Chunk requests use the private copy created by the first upload request.
    $original = 'legacy-json-chunk';
    $ext = 'json';
    $contents = '';
}

// Safety backups are stored outside the web root.
$privateDir = private_storage_dir();
$backupDir = $privateDir . DIRECTORY_SEPARATOR . 'backups';
if (!is_dir($backupDir) && !@mkdir($backupDir, 0700, true)) {
    json_out(false, 'Private backup storage could not be created. Restore was cancelled.', [], 500);
}

$safetyFile = $backupDir . DIRECTORY_SEPARATOR . 'pre_restore_' . date('Y-m-d_H-i-s') . '.sql';
if (!$legacyChunkRequest) {
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
}

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
    if ($legacyChunkRequest) {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_POST['token'] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            json_out(false, 'Invalid legacy restore session. Please start Restore again.', [], 400);
        }
        $chunkDataFile = $backupDir . DIRECTORY_SEPARATOR . 'legacy_restore_' . $token . '.json';
        if (!is_file($chunkDataFile)) {
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            json_out(false, 'Legacy restore session expired or was not found. Please start Restore again.', [], 400);
        }
        $contents = @file_get_contents($chunkDataFile);
        if ($contents === false || trim($contents) === '') {
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            json_out(false, 'Legacy restore session data could not be read.', [], 400);
        }
    }
    $data = json_decode($contents, true);
    if (!is_array($data)) {
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        @unlink($safetyFile);
        json_out(false, 'The selected JSON file is not valid JSON.', [], 400);
    }

    // Legacy AR Library backups (old app format) are restored in small, resumable
    // batches. The old backup can contain thousands of attendance rows; doing all
    // of them in one HTTP request can time out on Render. Each chunk is committed
    // separately, and the browser automatically asks for the next chunk.
    $data = json_decode($contents, true);
    if (!is_array($data)) {
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        @unlink($safetyFile);
        json_out(false, 'The selected JSON file is not valid JSON.', [], 400);
    }

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

        $countsDefault = [
            'Members imported'=>0,'Fee payments imported'=>0,'Pending dues imported'=>0,
            'Attendance imported'=>0,'Seats imported'=>0,'Lockers imported'=>0,
            'Expenses imported'=>0,'Enquiries imported'=>0,'Settings imported'=>0,
            'Other legacy data preserved'=>0
        ];
        $chunkSize = [
            'members'=>20,'fees'=>40,'attendance'=>500,'seats'=>40,'lockers'=>40,
            'expenses'=>40,'enquiries'=>40,'finalize'=>1
        ];
        $privateDir = private_storage_dir();
        $backupDir = $privateDir . DIRECTORY_SEPARATOR . 'backups';
        $action = (string)($_POST['legacy_action'] ?? 'start');

        if ($action === 'chunk') {
            $token = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_POST['token'] ?? '')));
            if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
                $conn->query('SET FOREIGN_KEY_CHECKS=1');
                json_out(false, 'Invalid legacy restore session. Please start Restore again.', [], 400);
            }
            $stateFile = $backupDir . DIRECTORY_SEPARATOR . 'legacy_restore_' . $token . '.state.json';
            $dataFile = $backupDir . DIRECTORY_SEPARATOR . 'legacy_restore_' . $token . '.json';
            if (!is_file($stateFile) || !is_file($dataFile)) {
                $conn->query('SET FOREIGN_KEY_CHECKS=1');
                json_out(false, 'Legacy restore session expired or was not found. Please start Restore again.', [], 400);
            }
            $state = json_decode((string)@file_get_contents($stateFile), true);
            $legacy = json_decode((string)@file_get_contents($dataFile), true);
            if (!is_array($state) || !is_array($legacy) || (($legacy['_meta']['app'] ?? '') !== 'AR Library')) {
                $conn->query('SET FOREIGN_KEY_CHECKS=1');
                json_out(false, 'Legacy restore session data is invalid. Please start Restore again.', [], 400);
            }
            $counts = array_merge($countsDefault, is_array($state['counts'] ?? null) ? $state['counts'] : []);
            $phase = (string)($state['phase'] ?? 'settings');
            $index = max(0, (int)($state['index'] ?? 0));
            $nextPhase = $phase;
            $nextIndex = $index;
            $done = false;

            $saveState = static function($file, $state) {
                @file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX);
                @chmod($file, 0600);
            };
            $memberMap = [];
            $mr = $conn->query('SELECT id,member_id,shift,membership_plan FROM members');
            if ($mr) while ($row = $mr->fetch_assoc()) $memberMap[(string)$row['member_id']] = $row;

            try {
                $conn->query('SET FOREIGN_KEY_CHECKS=0');
                $conn->begin_transaction();

                if ($phase === 'settings') {
                    foreach (($legacy['feeStructure'] ?? []) as $fee) {
                        $plan = strtolower(trim((string)($fee['plan'] ?? '')));
                        $amount = (float)($fee['amount'] ?? 0);
                        if ($amount <= 0) continue;
                        $full = strpos($plan, 'full day') !== false;
                        $reserved = strpos($plan, 'reserved') !== false;
                        $key = $full ? ($reserved ? 'full_reserved_one' : 'full_day_one') : ($reserved ? 'half_reserved_one' : 'half_day_one');
                        $st = $conn->prepare('INSERT OR REPLACE INTO fee_settings(setting_key,setting_value) VALUES(?,?)');
                        if (!$st) throw new RuntimeException('Could not prepare fee settings import.');
                        $st->bind_param('sd', $key, $amount);
                        if (!$st->execute()) { $e=$st->error; $st->close(); throw new RuntimeException('Fee settings import failed: '.$e); }
                        $st->close();
                    }
                    $settings = [];
                    $lib = $legacy['appSettings']['library'] ?? [];
                    if (isset($lib['name'])) $settings['library_name'] = (string)$lib['name'];
                    if (isset($lib['phone'])) $settings['phone'] = (string)$lib['phone'];
                    if (isset($lib['addr'])) $settings['address'] = (string)$lib['addr'];
                    $shifts = $legacy['appSettings']['shifts'] ?? [];
                    if (isset($shifts['morningStart'])) $settings['opening_time'] = (string)$shifts['morningStart'];
                    if (isset($shifts['eveningEnd'])) $settings['closing_time'] = (string)$shifts['eveningEnd'];
                    $seats = $legacy['appSettings']['seats']['total'] ?? null;
                    $lockers = $legacy['lockerCount']['total'] ?? ($legacy['appSettings']['lockers']['total'] ?? null);
                    if ($seats !== null) $settings['total_seats'] = (string)(int)$seats;
                    if ($lockers !== null) $settings['total_lockers'] = (string)(int)$lockers;
                    foreach ($settings as $key=>$value) {
                        $st=$conn->prepare('INSERT OR REPLACE INTO library_settings(setting_key,setting_value) VALUES(?,?)');
                        if(!$st) throw new RuntimeException('Could not prepare library settings import.');
                        $st->bind_param('ss',$key,$value); if(!$st->execute()){ $e=$st->error;$st->close();throw new RuntimeException('Library settings import failed: '.$e); } $st->close(); $counts['Settings imported']++;
                    }
                    // Preserve legacy-only sections that the current schema has no dedicated table for.
                    $preserve = ['notices'=>'legacy_notices','employees'=>'legacy_employees','salaryRecords'=>'legacy_salary_records','empCreds'=>'legacy_emp_creds','recycleBin'=>'legacy_recycle_bin','customShifts'=>'legacy_custom_shifts','chargeTypes'=>'legacy_charge_types','discounts'=>'legacy_discounts'];
                    foreach($preserve as $src=>$key){
                        if(array_key_exists($src, $legacy) || array_key_exists($src, ($legacy['appSettings']??[]))){
                            $value = $legacy[$src] ?? (($legacy['appSettings']??[])[$src] ?? []);
                            $json = json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                            $st=$conn->prepare('INSERT OR REPLACE INTO library_settings(setting_key,setting_value) VALUES(?,?)');
                            if($st){$st->bind_param('ss',$key,$json);$st->execute();$st->close();$counts['Other legacy data preserved']++;}
                        }
                    }
                    $nextPhase='members'; $nextIndex=0;
                } elseif ($phase === 'members') {
                    $items=$legacy['members']??[]; $end=min(count($items),$index+$chunkSize['members']);
                    for($i=$index;$i<$end;$i++){
                        $m=$items[$i]; $old=trim((string)($m['id']??'')); $name=trim((string)($m['name']??'Member')); if($old===''||$name==='')continue;
                        if(isset($memberMap[$old])) continue;
                        $join=$legacyDate($m['from']??'',date('Y-m-d')); $valid=$legacyDate($m['to']??'',null); $dob=$legacyDate($m['dob']??'',null); $duration=$legacyPlan($m['from']??'',$m['to']??'',$m['category']??''); $shift=$legacyShift($m['shift']??'',$m['plan']??''); $status=strtolower(trim((string)($m['feeStatus']??'')))==='expired'?'Expired':'Active'; $phone=trim((string)($m['phone']??'')); $address=trim((string)($m['addr']??'')); $created=$legacyDate($m['createdAt']??'',$join);
                        $course=trim((string)($m['cls']??'')); $st=$conn->prepare('INSERT INTO members(member_id,name,phone,email,course,membership_plan,shift,joining_date,date_of_birth,validity_date,address,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)'); if(!$st)throw new RuntimeException('Could not prepare member import.'); $email=''; $st->bind_param('sssssssssssss',$old,$name,$phone,$email,$course,$duration,$shift,$join,$dob,$valid,$address,$status,$created); if(!$st->execute()){ $e=$st->error;$st->close();throw new RuntimeException('Member '.$old.' failed: '.$e);} $id=(int)$conn->insert_id; $st->close(); $memberMap[$old]=['id'=>$id,'member_id'=>$old,'shift'=>$shift,'membership_plan'=>$duration];
                        $guardian=trim((string)($m['guardian']??'')); if($guardian!==''){ $key='member_father_'.$id; $st=$conn->prepare('INSERT OR REPLACE INTO library_settings(setting_key,setting_value) VALUES(?,?)'); if($st){$st->bind_param('ss',$key,$guardian);$st->execute();$st->close();} }
                        $counts['Members imported']++;
                    }
                    if($end<count($items)){$nextIndex=$end;}else{$nextPhase='fees';$nextIndex=0;}
                } elseif ($phase === 'fees') {
                    $items=$legacy['feeRecords']??[]; $end=min(count($items),$index+$chunkSize['fees']); $seen=[];
                    for($i=$index;$i<$end;$i++){
                        $fr=$items[$i]; $old=trim((string)($fr['memberId']??'')); if(!isset($memberMap[$old]))continue; $m=$memberMap[$old]; $date=$legacyDate($fr['date']??'',date('Y-m-d')); $created=$legacyDate($fr['createdAt']??'',$date); $amount=max(0,(float)($fr['amount']??0)); $paid=max(0,(float)($fr['paidAmount']??0)); $due=max(0,(float)($fr['dueAmount']??0)); if($paid<=0&&$due<=0&&$amount>0)$paid=$amount; $mode=$legacyMode($fr['mode']??'Cash'); $receipt=$legacyReceipt($fr['id']??'OLD-FEE',$seen); $plan=(string)($fr['plan']??''); $notes=trim((string)($fr['notes']??''));
                        if($paid>0){
                            $exists=$conn->prepare('SELECT id FROM payments WHERE receipt_no=? LIMIT 1'); if(!$exists)throw new RuntimeException('Could not check payment import.'); $exists->bind_param('s',$receipt);$exists->execute();$er=$exists->get_result();$already=$er&&$er->fetch_assoc();$exists->close();
                            if(!$already){$type=$due>0?'Split':'Full';$extra=0.0;$st=$conn->prepare('INSERT INTO payments(receipt_no,member_id,member_code,amount,fee_amount,additional_charges,payment_type,payment_date,payment_method,plan,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');if(!$st)throw new RuntimeException('Could not prepare payment import.');$st->bind_param('sisdddssssss',$receipt,$m['id'],$m['member_id'],$paid,$paid,$extra,$type,$date,$mode,$plan,$notes,$created);if(!$st->execute()){ $e=$st->error;$st->close();throw new RuntimeException('Payment '.$receipt.' failed: '.$e);} $st->close(); $counts['Fee payments imported']++;}
                            $exists=$conn->prepare('SELECT id FROM member_fees WHERE member_id=? AND receipt_no=? LIMIT 1'); if($exists){$exists->bind_param('is',$m['id'],$receipt);$exists->execute();$er=$exists->get_result();$already=$er&&$er->fetch_assoc();$exists->close();}else{$already=false;}
                            if(!$already){$status='Paid';$st=$conn->prepare('INSERT INTO member_fees(member_id,receipt_no,amount,payment_date,due_date,payment_method,status,remarks,created_at) VALUES(?,?,?,?,?,?,?,?,?)');if(!$st)throw new RuntimeException('Could not prepare paid fee history import.');$st->bind_param('isdssssss',$m['id'],$receipt,$paid,$date,$date,$mode,$status,$notes,$created);if(!$st->execute()){ $e=$st->error;$st->close();throw new RuntimeException('Paid fee history failed: '.$e);} $st->close();}
                        }
                        if($due>0){$dueReceipt=$receipt.'-D';$exists=$conn->prepare('SELECT id FROM member_fees WHERE member_id=? AND receipt_no=? LIMIT 1');if($exists){$exists->bind_param('is',$m['id'],$dueReceipt);$exists->execute();$er=$exists->get_result();$already=$er&&$er->fetch_assoc();$exists->close();}else{$already=false;}if(!$already){$status='Pending';$remarks='Imported legacy pending balance'.($notes!==''?': '.$notes:'');$st=$conn->prepare('INSERT INTO member_fees(member_id,receipt_no,amount,payment_date,due_date,payment_method,status,remarks,created_at) VALUES(?,?,?,?,?,?,?,?,?)');if(!$st)throw new RuntimeException('Could not prepare pending fee import.');$st->bind_param('isdssssss',$m['id'],$dueReceipt,$due,$date,$date,$mode,$status,$remarks,$created);if(!$st->execute()){ $e=$st->error;$st->close();throw new RuntimeException('Pending fee history failed: '.$e);} $st->close();$counts['Pending dues imported']++;}}
                    }
                    if($end<count($items)){$nextIndex=$end;}else{$nextPhase='attendance';$nextIndex=0;}
                } elseif ($phase === 'attendance') {
                    $items=$legacy['attendance']??[]; $end=min(count($items),$index+$chunkSize['attendance']);
                    for($i=$index;$i<$end;$i++){$a=$items[$i];$old=trim((string)($a['memberId']??''));if(!isset($memberMap[$old]))continue;$date=$legacyDate($a['date']??'',null);if(!$date)continue;$in=$legacyDateTime($a['date']??'',$a['in']??'');$out=$legacyDateTime($a['date']??'',$a['out']??'');$status=$out?'Closed':'Open';$remarks=trim((string)($a['shift']??''));$created=$legacyDate($a['createdAt']??'',$date);$st=$conn->prepare('SELECT id FROM attendance WHERE member_id=? AND attendance_date=? LIMIT 1');if(!$st)throw new RuntimeException('Could not check attendance import.');$st->bind_param('is',$memberMap[$old]['id'],$date);$st->execute();$rr=$st->get_result();$existing=$rr&&$rr->fetch_assoc();$st->close();if($existing){$st=$conn->prepare('UPDATE attendance SET check_in=?,check_out=?,status=?,remarks=?,created_at=?,updated_at=? WHERE id=?');$st->bind_param('ssssssi',$in,$out,$status,$remarks,$created,$created,$existing['id']);}else{$st=$conn->prepare('INSERT INTO attendance(member_id,attendance_date,check_in,check_out,status,remarks,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');$st->bind_param('isssssss',$memberMap[$old]['id'],$date,$in,$out,$status,$remarks,$created,$created);}if(!$st||!$st->execute()){ $e=$st?$st->error:'prepare failed';if($st)$st->close();throw new RuntimeException('Attendance import failed: '.$e);} $st->close();$counts['Attendance imported']++;}
                    if($end<count($items)){$nextIndex=$end;}else{$nextPhase='seats';$nextIndex=0;}
                } elseif ($phase === 'seats') {
                    $items=$legacy['members']??[]; $end=min(count($items),$index+$chunkSize['seats']);
                    for($i=$index;$i<$end;$i++){$m=$items[$i];$old=trim((string)($m['id']??''));$seat=trim((string)($m['seat']??''));if($seat===''||!isset($memberMap[$old]))continue;$start=$legacyDate($m['from']??'',date('Y-m-d'));$endDate=$legacyDate($m['to']??'',null);$created=$legacyDate($m['createdAt']??'',$start);$st=$conn->prepare('SELECT id FROM member_seats WHERE member_id=? AND seat_no=? AND start_date=? LIMIT 1');if(!$st)throw new RuntimeException('Could not check seat import.');$st->bind_param('iss',$memberMap[$old]['id'],$seat,$start);$st->execute();$rr=$st->get_result();$exists=$rr&&$rr->fetch_assoc();$st->close();if(!$exists){$status='Assigned';$remarks='Imported from legacy backup';$st=$conn->prepare('INSERT INTO member_seats(member_id,seat_no,shift,start_date,end_date,status,remarks,created_at) VALUES(?,?,?,?,?,?,?,?)');if(!$st)throw new RuntimeException('Could not prepare seat import.');$st->bind_param('isssssss',$memberMap[$old]['id'],$seat,$memberMap[$old]['shift'],$start,$endDate,$status,$remarks,$created);if(!$st->execute()){ $e=$st->error;$st->close();throw new RuntimeException('Seat import failed: '.$e);} $st->close();$counts['Seats imported']++;}}
                    if($end<count($items)){$nextIndex=$end;}else{$nextPhase='lockers';$nextIndex=0;}
                } elseif ($phase === 'lockers') {
                    $items=$legacy['lockerData']??[]; $end=min(count($items),$index+$chunkSize['lockers']);
                    for($i=$index;$i<$end;$i++){$l=$items[$i];$old=trim((string)($l['memberId']??''));$locker=trim((string)($l['no']??''));if($locker===''||!isset($memberMap[$old]))continue;$start=$legacyDate($l['from']??'',date('Y-m-d'));$endDate=$legacyDate($l['to']??'',null);$created=$legacyDate($l['assignedAt']??'',$start);$st=$conn->prepare('SELECT id FROM lockers WHERE locker_no=? AND member_id=? AND start_date=? LIMIT 1');if(!$st)throw new RuntimeException('Could not check locker import.');$st->bind_param('sis',$locker,$memberMap[$old]['id'],$start);$st->execute();$rr=$st->get_result();$exists=$rr&&$rr->fetch_assoc();$st->close();if(!$exists){$status='Assigned';$remarks=trim((string)($l['notes']??''));$st=$conn->prepare('INSERT INTO lockers(locker_no,member_id,start_date,end_date,status,remarks,created_at) VALUES(?,?,?,?,?,?,?)');if(!$st)throw new RuntimeException('Could not prepare locker import.');$st->bind_param('sisssss',$locker,$memberMap[$old]['id'],$start,$endDate,$status,$remarks,$created);if(!$st->execute()){ $e=$st->error;$st->close();throw new RuntimeException('Locker import failed: '.$e);} $st->close();$counts['Lockers imported']++;}}
                    if($end<count($items)){$nextIndex=$end;}else{$nextPhase='expenses';$nextIndex=0;}
                } elseif ($phase === 'expenses') {
                    $items=$legacy['expenses']??[]; $end=min(count($items),$index+$chunkSize['expenses']);
                    for($i=$index;$i<$end;$i++){$e2=$items[$i];$date=$legacyDate($e2['date']??'',date('Y-m-d'));$category=trim((string)($e2['cat']??'Other'))?:'Other';$description=trim((string)($e2['desc']??''));$amount=max(0,(float)($e2['amount']??0));if($amount<=0)continue;$mode=$legacyMode($e2['mode']??'Cash');$notes=trim((string)($e2['notes']??''));$created=$legacyDate($e2['createdAt']??'',$date);$vendor='';$st=$conn->prepare('SELECT id FROM expenses WHERE expense_date=? AND category=? AND description=? AND amount=? LIMIT 1');if($st){$st->bind_param('sssd',$date,$category,$description,$amount);$st->execute();$rr=$st->get_result();$exists=$rr&&$rr->fetch_assoc();$st->close();}else{$exists=false;}if(!$exists){$st=$conn->prepare('INSERT INTO expenses(expense_date,category,description,amount,payment_method,vendor,notes,created_at) VALUES(?,?,?,?,?,?,?,?)');if(!$st)throw new RuntimeException('Could not prepare expense import.');$st->bind_param('sssdssss',$date,$category,$description,$amount,$mode,$vendor,$notes,$created);if(!$st->execute()){ $e=$st->error;$st->close();throw new RuntimeException('Expense import failed: '.$e);} $st->close();$counts['Expenses imported']++;}}
                    if($end<count($items)){$nextIndex=$end;}else{$nextPhase='enquiries';$nextIndex=0;}
                } elseif ($phase === 'enquiries') {
                    $items=$legacy['enquiries']??[]; $end=min(count($items),$index+$chunkSize['enquiries']);
                    for($i=$index;$i<$end;$i++){$e2=$items[$i];$name=trim((string)($e2['name']??''));if($name==='')continue;$phone=trim((string)($e2['phone']??''));$requirement=trim((string)($e2['cls']??''));$shift=trim((string)($e2['shift']??''));if($shift!=='')$requirement=trim($requirement.($requirement!==''?' | ':'').'Shift: '.$shift);$follow=$legacyDate($e2['date']??'',null);$raw=strtolower(trim((string)($e2['status']??'Open')));$status=$raw==='converted'?'Converted':($raw==='closed'?'Closed':'Open');$notes=trim((string)($e2['notes']??''));$address=trim((string)($e2['address']??''));if($address!=='')$notes=trim($notes.($notes!==''?"\n":'').'Address: '.$address);$created=$legacyDate($e2['createdAt']??'', $follow?:date('Y-m-d'));$st=$conn->prepare('SELECT id FROM enquiries WHERE name=? AND phone=? AND created_at=? LIMIT 1');if($st){$st->bind_param('sss',$name,$phone,$created);$st->execute();$rr=$st->get_result();$exists=$rr&&$rr->fetch_assoc();$st->close();}else{$exists=false;}if(!$exists){$st=$conn->prepare('INSERT INTO enquiries(name,phone,requirement,follow_up,status,notes,created_at) VALUES(?,?,?,NULLIF(?,""),?,?,?)');if(!$st)throw new RuntimeException('Could not prepare enquiry import.');$st->bind_param('sssssss',$name,$phone,$requirement,$follow,$status,$notes,$created);if(!$st->execute()){ $e=$st->error;$st->close();throw new RuntimeException('Enquiry import failed: '.$e);} $st->close();$counts['Enquiries imported']++;}}
                    if($end<count($items)){$nextIndex=$end;}else{$nextPhase='done';$nextIndex=0;}
                } elseif ($phase === 'done') {
                    $done=true;
                } else { throw new RuntimeException('Unknown legacy restore phase.'); }

                $conn->commit();
                $conn->query('SET FOREIGN_KEY_CHECKS=1');
                $state['phase']=$nextPhase; $state['index']=$nextIndex; $state['counts']=$counts; $state['done']=$done || $nextPhase==='done'; $state['updated_at']=date('c');
                $saveState($stateFile,$state);
                if($nextPhase==='done'){
                    $done=true;
                    @unlink($dataFile); @unlink($stateFile);
                    app_log('legacy_json_restored','Legacy backup: '.basename((string)($state['original']??'legacy')).' | Completed in batches.');
                }
                $totalMembers=count($legacy['members']??[]);$totalFees=count($legacy['feeRecords']??[]);$totalAttendance=count($legacy['attendance']??[]);$totalSeats=count($legacy['members']??[]);$totalLockers=count($legacy['lockerData']??[]);$totalExpenses=count($legacy['expenses']??[]);$totalEnquiries=count($legacy['enquiries']??[]);
                $phaseTotals=['settings'=>1,'members'=>$totalMembers,'fees'=>$totalFees,'attendance'=>$totalAttendance,'seats'=>$totalSeats,'lockers'=>$totalLockers,'expenses'=>$totalExpenses,'enquiries'=>$totalEnquiries,'done'=>1];
                $phaseOrder=array_keys($phaseTotals);$currentPos=array_search($nextPhase,$phaseOrder,true);$progress=$done?100:round(($currentPos/max(1,count($phaseOrder)-1))*100,1);
                json_out(true,$done?'Old AR Library JSON backup restored successfully.':'Restore is continuing.',$counts+['legacy_restore'=>true,'token'=>$token,'done'=>$done,'phase'=>$nextPhase,'index'=>$nextIndex,'progress'=>$progress]);
            } catch(Throwable $e) {
                try{$conn->rollback();}catch(Throwable $ignored){}
                $conn->query('SET FOREIGN_KEY_CHECKS=1');
                app_log('legacy_json_restore_failed','Batch error: '.$e->getMessage());
                json_out(false,'Legacy JSON restore stopped at '.$phase.' #'.($index+1).': '.$e->getMessage().'. Please retry Restore; completed batches are kept.',[],500);
            }
        }

        // New legacy restore session: require an empty operational database so that
        // a repeated migration cannot silently duplicate member/payment data.
        $em=0;$ep=0;$r=$conn->query('SELECT COUNT(*) AS c FROM members');if($r)$em=(int)($r->fetch_assoc()['c']??0);$r=$conn->query('SELECT COUNT(*) AS c FROM payments');if($r)$ep=(int)($r->fetch_assoc()['c']??0);
        if($em>0||$ep>0){$conn->query('SET FOREIGN_KEY_CHECKS=1');json_out(false,'Old AR Library JSON detected, but current member/payment data already exists. Use Fresh Reset first, then restore this old JSON backup.',[],400);}
        if(!is_dir($backupDir)&&!@mkdir($backupDir,0700,true)){ $conn->query('SET FOREIGN_KEY_CHECKS=1');json_out(false,'Private backup storage could not be created.',[],500); }
        try{
            $token=bin2hex(random_bytes(16));
            $dataFile=$backupDir.DIRECTORY_SEPARATOR.'legacy_restore_'.$token.'.json';
            $stateFile=$backupDir.DIRECTORY_SEPARATOR.'legacy_restore_'.$token.'.state.json';
            if(@file_put_contents($dataFile,$contents,LOCK_EX)===false)throw new RuntimeException('Could not store the legacy backup for batch restore.');
            @chmod($dataFile,0600);
            $state=['version'=>1,'phase'=>'settings','index'=>0,'counts'=>$countsDefault,'original'=>$original,'safety_backup'=>basename($safetyFile),'created_at'=>date('c'),'updated_at'=>date('c'),'done'=>false];
            if(@file_put_contents($stateFile,json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new RuntimeException('Could not create the restore session.');
            @chmod($stateFile,0600);
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            json_out(true,'Legacy JSON accepted. Restore will continue in small batches.',[
                'legacy_restore'=>true,'token'=>$token,'done'=>false,'phase'=>'settings','index'=>0,'progress'=>0,
                'safety_backup'=>basename($safetyFile)
            ]);
        }catch(Throwable $e){$conn->query('SET FOREIGN_KEY_CHECKS=1');@unlink($safetyFile);json_out(false,'Could not start legacy restore: '.$e->getMessage(),[],500);}
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
