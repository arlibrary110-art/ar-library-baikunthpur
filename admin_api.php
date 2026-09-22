<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
header('Content-Type: application/json; charset=utf-8');

function out($ok,$message='',$extra=[],$status=200){ json_out($ok,$message,$extra,$status); }
require_staff();
verify_state_change();
require_csrf();

$method=$_SERVER['REQUEST_METHOD']; $action=$_GET['action']??'';

// Schema maintenance is intentionally performed only once per login session.
// The production database is already initialized, so running CREATE/ALTER on
// every API request added unnecessary Render -> Railway round trips.
if (empty($_SESSION['_admin_schema_ready'])) {
    @$conn->query("ALTER TABLE staff ADD COLUMN photo VARCHAR(255) NULL AFTER name");
    $conn->query("CREATE TABLE IF NOT EXISTS enquiries (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(150) NOT NULL,phone VARCHAR(30),requirement VARCHAR(255),follow_up DATE NULL,status VARCHAR(30) NOT NULL DEFAULT 'Open',notes TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_enq_status(status),INDEX idx_enq_follow(follow_up)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS expenses (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,expense_date DATE NOT NULL,category VARCHAR(100) NOT NULL,description VARCHAR(255),amount DECIMAL(12,2) NOT NULL DEFAULT 0,payment_method VARCHAR(30) DEFAULT 'Cash',vendor VARCHAR(150),notes TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_exp_date(expense_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS library_settings (setting_key VARCHAR(80) PRIMARY KEY,setting_value TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS activity_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,staff_id VARCHAR(50),action VARCHAR(100) NOT NULL,details VARCHAR(255),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_activity(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS member_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,staff_id VARCHAR(50) NOT NULL,title VARCHAR(150) NOT NULL,message TEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_msg_created(created_at),INDEX idx_msg_staff(staff_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS attendance (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,member_id INT UNSIGNED NOT NULL,attendance_date DATE NOT NULL,check_in DATETIME NULL,check_out DATETIME NULL,status VARCHAR(20) NOT NULL DEFAULT 'Open',remarks VARCHAR(255) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_member_date(member_id,attendance_date),INDEX idx_att_date(attendance_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $_SESSION['_admin_schema_ready']=1;
}
function log_activity($action,$details=''){ global $conn; $sid=$_SESSION['staff_id']??''; $s=$conn->prepare('INSERT INTO activity_logs(staff_id,action,details) VALUES(?,?,?)'); if($s){$s->bind_param('sss',$sid,$action,$details);$s->execute();$s->close();}}

if($action==='member_messages'){
    // Admin and any logged-in staff can post a library-wide notice.
    $method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET'){
        $rows=[];
        $r=$conn->query("SELECT id,staff_id,title,message,created_at FROM member_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 20");
        if($r) while($x=$r->fetch_assoc()) $rows[]=$x;
        out(true,'',['messages'=>$rows]);
    }
    if($method==='POST'){
        $title=trim((string)($_POST['title']??'Important Notice'));
        $message=trim((string)($_POST['message']??''));
        if($title==='') $title='Important Notice';
        if($message==='') out(false,'Please enter a message.',[],422);
        if(mb_strlen($title)>150) out(false,'Title is too long.',[],422);
        if(mb_strlen($message)>2000) out(false,'Message is too long. Maximum 2000 characters.',[],422);
        $sid=(string)($_SESSION['staff_id']??'');
        $st=$conn->prepare('INSERT INTO member_messages(staff_id,title,message) VALUES(?,?,?)');
        if(!$st) out(false,'Could not prepare message.',[],500);
        $st->bind_param('sss',$sid,$title,$message);
        $ok=$st->execute(); $st->close();
        if(!$ok) out(false,'Could not post message.',[],500);
        log_activity('Posted member notice',$title);
        out(true,'Message posted. It will be visible to members for 24 hours.');
    }
    if($method==='DELETE'){
        $id=(int)($_GET['id']??0);
        if($id<=0) out(false,'Invalid message.',[],422);
        $st=$conn->prepare('DELETE FROM member_messages WHERE id=?');
        if(!$st) out(false,'Could not delete message.',[],500);
        $st->bind_param('i',$id); $st->execute(); $st->close();
        out(true,'Message removed.');
    }
    out(false,'Invalid message request.',[],405);
}
if($action==='fresh_start_reset') {
    require_admin();
    if($method!=='POST') out(false,'Invalid reset request.',[],405);
    $confirm=trim((string)($_POST['confirm']??''));
    if($confirm!=='RESET') out(false,'Type RESET to confirm this permanent operation.',[],422);
    try {
        $conn->begin_transaction();
        $conn->query('SET FOREIGN_KEY_CHECKS=0');
        foreach(['attendance','member_seats','lockers','payments','member_fees','members','enquiries','member_messages','activity_logs'] as $table){
            if(!$conn->query('DELETE FROM `'.$table.'`')) throw new Exception('Could not reset '.$table.': '.$conn->error);
        }
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        $conn->commit();
        foreach(['attendance','member_seats','lockers','payments','member_fees','members','enquiries','member_messages','activity_logs'] as $table){
            @$conn->query('ALTER TABLE `'.$table.'` AUTO_INCREMENT=1');
        }
        out(true,'Fresh start completed. All old member, fee, pending, attendance and enquiry records were removed.');
    } catch(Throwable $e) {
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        $conn->rollback();
        out(false,'Fresh start failed: '.$e->getMessage(),[],500);
    }
}
if($action==='staff'){
    require_admin();
    $method=$_SERVER['REQUEST_METHOD'];

    if($method==='GET'){
        $rows=[];
        $r=$conn->query("SELECT id,staff_id,name,role,status,photo,created_at FROM staff ORDER BY id ASC");
        if($r) while($x=$r->fetch_assoc()){ $x['permissions']=get_staff_permissions($x['staff_id']); $rows[]=$x; }
        out(true,'',['staff'=>$rows]);
    }

    if($method==='POST'){
        $id=(int)($_POST['id']??0);
        $staffId=trim($_POST['staff_id']??'');
        $name=trim($_POST['name']??'');
        $password=(string)($_POST['password']??'');
        $role=strtolower(trim($_POST['role']??'staff'));
        $permissions=[]; $rawPermissions=$_POST['permissions']??''; if(is_string($rawPermissions)){ $decoded=json_decode($rawPermissions,true); if(is_array($decoded)) $permissions=$decoded; } elseif(is_array($rawPermissions)) { $permissions=$rawPermissions; }
        $status=strtolower(trim($_POST['status']??'active'));

        if($staffId==='' || !preg_match('/^[A-Za-z0-9_-]{2,50}$/',$staffId)) out(false,'Enter a valid Staff ID.',[],422);
        if($name==='' || mb_strlen($name)>150) out(false,'Enter a valid staff name.',[],422);
        if(!in_array($role,['admin','staff'],true)) $role='staff';
        if(!in_array($status,['active','inactive'],true)) $status='active';
        if($id<=0 && strlen($password)<8) out(false,'Password must be at least 8 characters.',[],422);
        if($id>0 && $staffId==='STF-001' && $role!=='admin') out(false,'The main Admin account must remain Admin.',[],422);

        $photoPath=null;
        if(isset($_FILES['photo']) && $_FILES['photo']['error']!==UPLOAD_ERR_NO_FILE){
            if($_FILES['photo']['error']!==UPLOAD_ERR_OK) out(false,'Photo upload failed.',[],422);
            if($_FILES['photo']['size']>2*1024*1024) out(false,'Photo must be 2 MB or smaller.',[],422);
            $finfo=new finfo(FILEINFO_MIME_TYPE);
            $mime=$finfo->file($_FILES['photo']['tmp_name']);
            $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if(!isset($allowed[$mime])) out(false,'Only JPG, PNG or WEBP photos are allowed.',[],422);
            $dir=__DIR__.'/uploads/staff';
            if(!is_dir($dir)) @mkdir($dir,0755,true);
            $filename=bin2hex(random_bytes(16)).'.'.$allowed[$mime];
            if(!move_uploaded_file($_FILES['photo']['tmp_name'],$dir.'/'.$filename)) out(false,'Could not save the photo.',[],500);
            $photoPath='uploads/staff/'.$filename;
        }

        if($id>0){
            $s=$conn->prepare('SELECT id,staff_id,photo FROM staff WHERE id=? LIMIT 1');
            $s->bind_param('i',$id); $s->execute(); $existing=$s->get_result()->fetch_assoc(); $s->close();
            if(!$existing) out(false,'Staff account not found.',[],404);
            if($existing['staff_id']==='STF-001' && $role!=='admin') out(false,'STF-001 must remain Admin.',[],422);
            $s=$conn->prepare('SELECT id FROM staff WHERE staff_id=? AND id<>? LIMIT 1');
            $s->bind_param('si',$staffId,$id); $s->execute(); if($s->get_result()->num_rows) { $s->close(); out(false,'Staff ID already exists.',[],409); } $s->close();
            if($photoPath){
                if($password!==''){
                    $hash=password_hash($password,PASSWORD_DEFAULT);
                    $s=$conn->prepare('UPDATE staff SET staff_id=?,name=?,password=?,role=?,status=?,photo=? WHERE id=?');
                    $s->bind_param('ssssssi',$staffId,$name,$hash,$role,$status,$photoPath,$id);
                } else {
                    $s=$conn->prepare('UPDATE staff SET staff_id=?,name=?,role=?,status=?,photo=? WHERE id=?');
                    $s->bind_param('sssssi',$staffId,$name,$role,$status,$photoPath,$id);
                }
            } else if($password!==''){
                $hash=password_hash($password,PASSWORD_DEFAULT);
                $s=$conn->prepare('UPDATE staff SET staff_id=?,name=?,password=?,role=?,status=? WHERE id=?');
                $s->bind_param('sssssi',$staffId,$name,$hash,$role,$status,$id);
            } else {
                $s=$conn->prepare('UPDATE staff SET staff_id=?,name=?,role=?,status=? WHERE id=?');
                $s->bind_param('ssssi',$staffId,$name,$role,$status,$id);
            }
            $oldStaffId=$existing['staff_id']; $ok=$s->execute(); $s->close();
            if($ok && $oldStaffId!==$staffId){ $mp=$conn->prepare('UPDATE staff_permissions SET staff_id=? WHERE staff_id=?'); if($mp){$mp->bind_param('ss',$staffId,$oldStaffId);$mp->execute();$mp->close();} }
            if($ok && $photoPath && !empty($existing['photo']) && strpos($existing['photo'],'uploads/staff/')===0){ @unlink(__DIR__.'/'.$existing['photo']); }
            if($ok){ if($role==='admin') $permissions=legacy_staff_permissions(); if(!save_staff_permissions($staffId,$permissions)) out(false,'Staff updated, but permissions could not be saved.',[],500); log_activity('Updated staff',$staffId.' - '.$name); }
            out($ok,$ok?'Staff account updated.':'Could not update staff.');
        }

        $s=$conn->prepare('SELECT id FROM staff WHERE staff_id=? LIMIT 1');
        $s->bind_param('s',$staffId); $s->execute(); if($s->get_result()->num_rows) { $s->close(); out(false,'Staff ID already exists.',[],409); } $s->close();
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $s=$conn->prepare('INSERT INTO staff(staff_id,name,password,role,status,photo) VALUES(?,?,?,?,?,?)');
        $s->bind_param('ssssss',$staffId,$name,$hash,$role,$status,$photoPath); $ok=$s->execute(); $newId=$s->insert_id; $s->close();
        if($ok){ if($role==='admin') $permissions=legacy_staff_permissions(); if(!save_staff_permissions($staffId,$permissions)) out(false,'Staff created, but permissions could not be saved.',[],500); log_activity('Added staff',$staffId.' - '.$name); }
        out($ok,$ok?'Staff account created.':'Could not create staff.',['id'=>$newId]);
    }

    if($method==='DELETE'){
        $id=(int)($_GET['id']??0);
        if($id<=0) out(false,'Invalid staff account.',[],422);
        $s=$conn->prepare('SELECT staff_id,photo FROM staff WHERE id=? LIMIT 1'); $s->bind_param('i',$id); $s->execute(); $existing=$s->get_result()->fetch_assoc(); $s->close();
        if(!$existing) out(false,'Staff account not found.',[],404);
        if($existing['staff_id']==='STF-001') out(false,'The main Admin account cannot be deleted.',[],422);
        $s=$conn->prepare('DELETE FROM staff WHERE id=?'); $s->bind_param('i',$id); $ok=$s->execute(); $s->close(); if($ok){$dp=$conn->prepare('DELETE FROM staff_permissions WHERE staff_id=?'); if($dp){$dp->bind_param('s',$existing['staff_id']);$dp->execute();$dp->close();}}
        if($ok && !empty($existing['photo']) && strpos($existing['photo'],'uploads/staff/')===0) @unlink(__DIR__.'/'.$existing['photo']);
        if($ok) log_activity('Deleted staff',$existing['staff_id']);
        out($ok,$ok?'Staff account deleted.':'Could not delete staff.');
    }
}

if($action==='dashboard'){
 require_permission('dashboard');

 // Dashboard stats: one SQL round-trip instead of many sequential queries.
 $statsSql = "SELECT
   (SELECT COUNT(*) FROM members) AS members,
   (SELECT COUNT(*) FROM members WHERE status='Active' AND (validity_date IS NULL OR validity_date>=CURDATE())) AS active,
   (SELECT COUNT(*) FROM members WHERE status='Active' AND validity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)) AS expiring,
   (SELECT COUNT(*) FROM members WHERE status='Active' AND validity_date<CURDATE()) AS expired,
   (SELECT COUNT(*) FROM attendance WHERE attendance_date=CURDATE() AND check_in IS NOT NULL) AS present,
   (SELECT COUNT(*) FROM attendance WHERE attendance_date=CURDATE() AND check_in IS NOT NULL AND check_out IS NULL) AS checked_in,
   (SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date=CURDATE()) AS today_paid,
   (SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND payment_date<DATE_ADD(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL 1 MONTH)) AS month_paid,
   (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND expense_date<DATE_ADD(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL 1 MONTH)) AS month_expenses,
   (SELECT COUNT(*) FROM member_seats WHERE status='Assigned' AND (end_date IS NULL OR end_date>=CURDATE())) AS occupied,
   (SELECT COUNT(*) FROM lockers WHERE status='Assigned' AND (end_date IS NULL OR end_date>=CURDATE())) AS occupied_lockers,
   (SELECT COUNT(*) FROM enquiries WHERE status IN ('Open','Follow-up')) AS open_enquiries,
   (SELECT COUNT(*) FROM enquiries WHERE status='Converted' AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND created_at<DATE_ADD(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL 1 MONTH)) AS converted_enquiries,
   (SELECT COALESCE(SUM(amount),0) FROM member_fees WHERE status='Pending') AS pending_fees";
 $sr=$conn->query($statsSql);
 if(!$sr) out(false,'Could not load dashboard statistics.',[],500);
 $st=$sr->fetch_assoc(); $sr->free();
 $members=(int)$st['members']; $active=(int)$st['active']; $expiring=(int)$st['expiring']; $expired=(int)$st['expired'];
 $present=(int)$st['present']; $checkedIn=(int)$st['checked_in']; $todayPaid=(float)$st['today_paid']; $monthPaid=(float)$st['month_paid']; $monthExp=(float)$st['month_expenses'];
 $occupied=(int)$st['occupied']; $occupiedLockers=(int)$st['occupied_lockers']; $openEnq=(int)$st['open_enquiries']; $convertedEnq=(int)$st['converted_enquiries']; $pendingFees=(float)$st['pending_fees'];
 $totalSeats=(int)get_setting('total_seats',79); if($totalSeats<1)$totalSeats=79; $availableSeats=max(0,$totalSeats-$occupied);
 $totalLockers=(int)get_setting('total_lockers',$totalSeats); if($totalLockers<0)$totalLockers=0; $availableLockers=max(0,$totalLockers-$occupiedLockers);
 $monthNet=$monthPaid-$monthExp;
 $activities=[]; $r=$conn->query("SELECT action,details,created_at FROM activity_logs ORDER BY id DESC LIMIT 10"); if($r) while($x=$r->fetch_assoc()) $activities[]=$x;
 $trend=[]; $r=$conn->query("SELECT d.day,COALESCE(p.total,0) collection,COALESCE(e.total,0) expenses FROM (SELECT CURDATE() day UNION ALL SELECT DATE_SUB(CURDATE(),INTERVAL 1 DAY) UNION ALL SELECT DATE_SUB(CURDATE(),INTERVAL 2 DAY) UNION ALL SELECT DATE_SUB(CURDATE(),INTERVAL 3 DAY) UNION ALL SELECT DATE_SUB(CURDATE(),INTERVAL 4 DAY) UNION ALL SELECT DATE_SUB(CURDATE(),INTERVAL 5 DAY) UNION ALL SELECT DATE_SUB(CURDATE(),INTERVAL 6 DAY)) d LEFT JOIN (SELECT payment_date day,SUM(amount) total FROM payments WHERE payment_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY payment_date) p ON p.day=d.day LEFT JOIN (SELECT expense_date day,SUM(amount) total FROM expenses WHERE expense_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY expense_date) e ON e.day=d.day ORDER BY d.day"); if($r) while($x=$r->fetch_assoc()) $trend[]=$x;
 $birthdays=[];
 $todayMd=date('m-d'); $futureMd=date('m-d',strtotime('+7 days'));
 if($todayMd <= $futureMd){
   $br=$conn->query("SELECT id,member_id,name,phone,date_of_birth FROM members WHERE date_of_birth IS NOT NULL AND status='Active' AND DATE_FORMAT(date_of_birth,'%m-%d') BETWEEN '$todayMd' AND '$futureMd' ORDER BY DATE_FORMAT(date_of_birth,'%m-%d'), name");
 } else {
   $br=$conn->query("SELECT id,member_id,name,phone,date_of_birth FROM members WHERE date_of_birth IS NOT NULL AND status='Active' AND (DATE_FORMAT(date_of_birth,'%m-%d') >= '$todayMd' OR DATE_FORMAT(date_of_birth,'%m-%d') <= '$futureMd') ORDER BY CASE WHEN DATE_FORMAT(date_of_birth,'%m-%d') >= '$todayMd' THEN 0 ELSE 1 END, DATE_FORMAT(date_of_birth,'%m-%d'), name");
 }
 if($br) while($b=$br->fetch_assoc()){ $birthdays[]=$b; }
 $expiry=[];
 $er=$conn->query("SELECT id,member_id,name,phone,membership_plan,shift,validity_date FROM members WHERE validity_date IS NOT NULL AND validity_date <= DATE_ADD(CURDATE(),INTERVAL 7 DAY) ORDER BY validity_date ASC, name ASC");
 if($er) while($e=$er->fetch_assoc()){ $expiry[]=$e; }
 out(true,'',['stats'=>['members'=>$members,'active'=>$active,'expiring'=>$expiring,'expired'=>$expired,'present'=>$present,'checked_in'=>$checkedIn,'today_paid'=>$todayPaid,'month_paid'=>$monthPaid,'month_expenses'=>$monthExp,'month_net'=>$monthNet,'occupied'=>$occupied,'available_seats'=>$availableSeats,'total_seats'=>$totalSeats,'occupied_lockers'=>$occupiedLockers,'available_lockers'=>$availableLockers,'total_lockers'=>$totalLockers,'open_enquiries'=>$openEnq,'converted_enquiries'=>$convertedEnq,'pending_fees'=>$pendingFees],'trend'=>$trend,'activities'=>$activities,'birthdays'=>$birthdays,'expiry'=>$expiry]);
}
if($action==='seats'){
 require_permission('seats');
 $totalSeats=(int)get_setting('total_seats',79);
 $totalLockers=(int)get_setting('total_lockers',$totalSeats);
 if($totalSeats<1)$totalSeats=79; if($totalLockers<1)$totalLockers=$totalSeats;
 $rows=[]; $r=$conn->query("SELECT ms.id,ms.member_id,ms.seat_no,CASE WHEN LOWER(TRIM(ms.shift)) IN ('full','full day','full day shift') THEN 'Full Day' WHEN LOWER(TRIM(ms.shift)) IN ('morning','morning shift') THEN 'Morning Shift' WHEN LOWER(TRIM(ms.shift)) IN ('evening','evening shift') THEN 'Evening Shift' ELSE ms.shift END AS shift,ms.start_date,ms.end_date,ms.status,m.member_id member_code,m.name FROM member_seats ms LEFT JOIN members m ON m.id=ms.member_id ORDER BY ms.shift,ms.seat_no+0,ms.seat_no"); if($r) while($x=$r->fetch_assoc()) $rows[]=$x;
 $lockers=[]; $r=$conn->query("SELECT l.id,l.locker_no,l.member_id,l.start_date,l.end_date,CASE WHEN l.end_date IS NOT NULL AND l.end_date<CURDATE() THEN 'Expired' ELSE l.status END status,m.member_id member_code,m.name FROM lockers l LEFT JOIN members m ON m.id=l.member_id WHERE l.status='Assigned' OR (l.end_date IS NOT NULL AND l.end_date<CURDATE()) ORDER BY l.locker_no+0,l.locker_no"); if($r) while($x=$r->fetch_assoc()) $lockers[]=$x;
 $settings=[]; $sr=$conn->query("SELECT setting_key,setting_value FROM library_settings"); if($sr) while($sv=$sr->fetch_assoc()) $settings[$sv['setting_key']]=$sv['setting_value']; out(true,'',['seats'=>$rows,'lockers'=>$lockers,'total_seats'=>$totalSeats,'total_lockers'=>$totalLockers,'settings'=>$settings]);
}
if($action==='assign_seat' && $method==='POST'){
 require_permission('seats');
 $id=(int)($_POST['id']??0); $seat=trim($_POST['seat_no']??''); $shift=trim($_POST['shift']??''); $member=trim($_POST['member_id']??'');
 $totalSeats=(int)get_setting('total_seats',79); if($totalSeats<1)$totalSeats=79; if(!ctype_digit($seat) || (int)$seat<1 || (int)$seat>$totalSeats) out(false,'Invalid seat number. Choose a seat from 1 to '.$totalSeats.'.',[],422);
 if($shift==='Full Day Shift') $shift='Full Day';
 if($seat==='' || !in_array($shift,['Morning Shift','Evening Shift','Full Day'],true) || $member==='') out(false,'Seat, shift and member are required.',[],422);
 $mid=0;
 // Resolve the user-entered public Member ID/code first; fall back to numeric DB id.
 $s=$conn->prepare('SELECT id FROM members WHERE member_id=? LIMIT 1');
 $s->bind_param('s',$member); $s->execute(); $r=$s->get_result();
 if($x=$r->fetch_assoc()) $mid=(int)$x['id'];
 $s->close();
 if($mid<=0 && ctype_digit($member)) {
   $candidate=(int)$member;
   $s=$conn->prepare('SELECT id FROM members WHERE id=? LIMIT 1');
   $s->bind_param('i',$candidate); $s->execute(); $r=$s->get_result();
   if($x=$r->fetch_assoc()) $mid=(int)$x['id'];
   $s->close();
 }
 if($mid<=0) out(false,'Member not found. Please enter the registered Member ID/code.',[],404);
 $mv=$conn->prepare('SELECT validity_date FROM members WHERE id=? LIMIT 1'); $mv->bind_param('i',$mid); $mv->execute(); $memberValidity=(string)($mv->get_result()->fetch_assoc()['validity_date'] ?? ''); $mv->close();
 if($memberValidity!=='' && $memberValidity<date('Y-m-d')) out(false,'This membership has expired. Renew the member before assigning a seat.',[],422);
 // Seat overlap rules: Morning <-> Full Day conflict, Evening <-> Full Day conflict,
 // and Full Day conflicts with every active shift. Morning and Evening may coexist.
 $conflictShifts = $shift==='Full Day' ? ['Morning Shift','Evening Shift','Full Day'] : ($shift==='Morning Shift' ? ['Morning Shift','Full Day'] : ['Evening Shift','Full Day']);
 $ph=implode(',',array_fill(0,count($conflictShifts),'?'));
 $types='s'.str_repeat('s',count($conflictShifts)).'i';
 $params=array_merge([$seat],$conflictShifts,[$id]);
 $s=$conn->prepare("SELECT id,shift FROM member_seats WHERE seat_no=? AND CASE WHEN LOWER(TRIM(shift)) IN ('full','full day','full day shift') THEN 'Full Day' WHEN LOWER(TRIM(shift)) IN ('morning','morning shift') THEN 'Morning Shift' WHEN LOWER(TRIM(shift)) IN ('evening','evening shift') THEN 'Evening Shift' ELSE shift END IN ($ph) AND status='Assigned' AND (end_date IS NULL OR end_date>=CURDATE()) AND id<>? LIMIT 1");
 $s->bind_param($types,...$params);$s->execute();$takenRow=$s->get_result()->fetch_assoc();$s->close();
 if($takenRow) out(false,'This seat is already occupied for the selected shift/time.',[],409);
 $types='i'.str_repeat('s',count($conflictShifts)).'i';
 $params=array_merge([$mid],$conflictShifts,[$id]);
 $s=$conn->prepare("SELECT id,shift FROM member_seats WHERE member_id=? AND CASE WHEN LOWER(TRIM(shift)) IN ('full','full day','full day shift') THEN 'Full Day' WHEN LOWER(TRIM(shift)) IN ('morning','morning shift') THEN 'Morning Shift' WHEN LOWER(TRIM(shift)) IN ('evening','evening shift') THEN 'Evening Shift' ELSE shift END IN ($ph) AND status='Assigned' AND (end_date IS NULL OR end_date>=CURDATE()) AND id<>? LIMIT 1");
 $s->bind_param($types,...$params);$s->execute();$alreadyRow=$s->get_result()->fetch_assoc();$s->close();
 if($alreadyRow) out(false,'This member already has a conflicting seat assignment.',[],409);
 if($id){$s=$conn->prepare("UPDATE member_seats SET member_id=?,seat_no=?,shift=?,status='Assigned',start_date=COALESCE(start_date,CURDATE()),end_date=NULLIF(?, '') WHERE id=?");$s->bind_param('isssi',$mid,$seat,$shift,$memberValidity,$id);}else{$s=$conn->prepare("INSERT INTO member_seats(member_id,seat_no,shift,start_date,end_date,status) VALUES(?,?,?,CURDATE(),NULLIF(?, ''),'Assigned')");$s->bind_param('isss',$mid,$seat,$shift,$memberValidity);}
 $ok=$s->execute();$s->close();if(!$ok)out(false,'Could not assign seat.');log_activity($id?'Updated seat assignment':'Assigned seat','Seat '.$seat.' · '.$shift.' · member '.$mid);out(true,$id?'Seat assignment updated.':'Seat assigned successfully.');
}
if($action==='release_seat' && $method==='POST'){
 require_permission('seats');
 $id=(int)($_POST['id']??0);if($id<=0)out(false,'Invalid seat assignment.',[],422);$s=$conn->prepare("UPDATE member_seats SET status='Inactive',end_date=CURDATE() WHERE id=? AND status='Assigned'");$s->bind_param('i',$id);$ok=$s->execute();$s->close();out($ok,$ok?'Seat released successfully.':'Seat assignment not found.');
}
if($action==='assign_locker' && $method==='POST'){
 require_permission('seats');
 $id=(int)($_POST['id']??0);$locker=trim($_POST['locker_no']??'');$member=trim($_POST['member_id']??'');$start=trim($_POST['start_date']??date('Y-m-d'));$end=trim($_POST['end_date']??'');
 $totalLockers=(int)get_setting('total_lockers',get_setting('total_seats',79)); if($totalLockers<1)$totalLockers=79; if($locker===''||$member==='')out(false,'Locker number and member are required.',[],422); if(!ctype_digit($locker) || (int)$locker<1 || (int)$locker>$totalLockers) out(false,'Invalid locker number. Choose a locker from 1 to '.$totalLockers.'.',[],422);$mid=0;
 // Resolve public Member ID/code first, then numeric internal DB id.
 $s=$conn->prepare('SELECT id FROM members WHERE member_id=? LIMIT 1');
 $s->bind_param('s',$member); $s->execute(); $r=$s->get_result();
 if($x=$r->fetch_assoc()) $mid=(int)$x['id']; $s->close();
 if($mid<=0 && ctype_digit($member)) { $candidate=(int)$member; $s=$conn->prepare('SELECT id FROM members WHERE id=? LIMIT 1'); $s->bind_param('i',$candidate); $s->execute(); $r=$s->get_result(); if($x=$r->fetch_assoc()) $mid=(int)$x['id']; $s->close(); }
 if($mid<=0)out(false,'Member not found. Please enter the registered Member ID/code.',[],404);
 $mv=$conn->prepare('SELECT validity_date FROM members WHERE id=? LIMIT 1'); $mv->bind_param('i',$mid); $mv->execute(); $memberValidity=(string)($mv->get_result()->fetch_assoc()['validity_date'] ?? ''); $mv->close();
 if($memberValidity!=='' && $memberValidity<date('Y-m-d')) out(false,'This membership has expired. Renew the member before assigning a locker.',[],422);
 if($end==='' && $memberValidity!=='') $end=$memberValidity;
 $s=$conn->prepare("SELECT id FROM lockers WHERE locker_no=? AND status='Assigned' AND id<>? AND (end_date IS NULL OR end_date>=CURDATE()) LIMIT 1");$s->bind_param('si',$locker,$id);$s->execute();$taken=$s->get_result()->num_rows>0;$s->close();if($taken)out(false,'This locker is already assigned.',[],409);
 if($id){$s=$conn->prepare("UPDATE lockers SET locker_no=?,member_id=?,start_date=?,end_date=NULLIF(?,''),status='Assigned' WHERE id=?");$s->bind_param('sissi',$locker,$mid,$start,$end,$id);}else{$s=$conn->prepare("INSERT INTO lockers(locker_no,member_id,start_date,end_date,status) VALUES(?,?,?,NULLIF(?,''),'Assigned')");$s->bind_param('siss',$locker,$mid,$start,$end);}
 $ok=$s->execute();$s->close();if(!$ok)out(false,'Could not save locker assignment.');log_activity($id?'Updated locker assignment':'Assigned locker','Locker '.$locker.' · member '.$mid);out(true,$id?'Locker assignment updated.':'Locker assigned successfully.');
}
if($action==='release_locker' && $method==='POST'){
 require_permission('seats');
 $id=(int)($_POST['id']??0);if($id<=0)out(false,'Invalid locker assignment.',[],422);$s=$conn->prepare("UPDATE lockers SET status='Released',end_date=COALESCE(end_date,CURDATE()) WHERE id=? AND status='Assigned'");$s->bind_param('i',$id);$ok=$s->execute();$s->close();out($ok,$ok?'Locker released successfully.':'Locker assignment not found.');
}
if($action==='enquiries'){
 require_permission('enquiry');
 if($method==='GET'){ $rows=[]; $r=$conn->query("SELECT * FROM enquiries ORDER BY id DESC"); while($x=$r->fetch_assoc())$rows[]=$x; out(true,'',['enquiries'=>$rows]); }
 if($method==='POST' && !isset($_GET['convert'])){
  $id=(int)($_POST['id']??0); $name=trim($_POST['name']??''); $phone=trim($_POST['phone']??''); $req=trim($_POST['requirement']??''); $follow=trim($_POST['follow_up']??''); $status=trim($_POST['status']??'Open'); $notes=trim($_POST['notes']??'');
  if($name==='') out(false,'Name is required.',[],422);
  if($phone!=='' && !preg_match('/^[0-9+()\-\s]{7,20}$/',$phone)) out(false,'Please enter a valid phone number.',[],422);
  if($follow!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$follow)) out(false,'Invalid follow-up date.',[],422);
  if(!in_array($status,['Open','Follow-up','Converted','Closed'],true))$status='Open';
  if($id){$s=$conn->prepare('UPDATE enquiries SET name=?,phone=?,requirement=?,follow_up=NULLIF(?,""),status=?,notes=? WHERE id=?');$s->bind_param('ssssssi',$name,$phone,$req,$follow,$status,$notes,$id);$ok=$s->execute();$s->close();log_activity('Updated enquiry',$name);}else{$s=$conn->prepare('INSERT INTO enquiries(name,phone,requirement,follow_up,status,notes) VALUES(?,?,?,NULLIF(?,""),?,?)');$s->bind_param('ssssss',$name,$phone,$req,$follow,$status,$notes);$ok=$s->execute();$s->close();log_activity('Added enquiry',$name);} out($ok,$ok?'Saved successfully.':'Could not save enquiry.');
 }
 if($method==='POST' && isset($_GET['convert'])){
  $eid=(int)($_GET['convert']??0); if($eid<=0) out(false,'Invalid enquiry.',[],422);
  $s=$conn->prepare('SELECT id,name,phone,requirement,status FROM enquiries WHERE id=? LIMIT 1');$s->bind_param('i',$eid);$s->execute();$enq=$s->get_result()->fetch_assoc();$s->close();
  if(!$enq) out(false,'Enquiry not found.',[],404);
  if($enq['status']==='Converted') out(false,'This enquiry is already converted.',[],409);
  $plan=trim($_POST['membership_plan']??'1 Month');$shift=trim($_POST['shift']??'Full Day');$join=trim($_POST['joining_date']??date('Y-m-d'));$valid=trim($_POST['validity_date']??'');
  if(!in_array($plan,['1 Month','3 Months','6 Months','Custom Date'],true)) $plan='1 Month';
  if(!in_array($shift,['Morning Shift','Morning Shift Reserved','Evening Shift','Evening Shift Reserved','Full Day','Full Day Reserved'],true)) $shift='Full Day';
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$join)) out(false,'Invalid joining date.',[],422);
  if($valid!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$valid)) out(false,'Invalid validity date.',[],422);
  if($valid==='') { $months=$plan==='3 Months'?3:($plan==='6 Months'?6:1); $valid=date('Y-m-d',strtotime($join.' +'.$months.' month -1 day')); }
  if($enq['phone']!==''){ $s=$conn->prepare('SELECT id,member_id FROM members WHERE phone=? LIMIT 1');$s->bind_param('s',$enq['phone']);$s->execute();$existing=$s->get_result()->fetch_assoc();$s->close();if($existing)out(false,'A member with this phone already exists ('.$existing['member_id'].').',[],409); }
  $memberId='AR-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
  $conn->begin_transaction();
  try{
   $status=$valid<date('Y-m-d')?'Expired':'Active';
   $s=$conn->prepare('INSERT INTO members(member_id,name,phone,email,membership_plan,shift,joining_date,validity_date,address,status) VALUES(?,?,?,?,?,?,?,?,?,?)');
   $email='';$address='';$s->bind_param('ssssssssss',$memberId,$enq['name'],$enq['phone'],$email,$plan,$shift,$join,$valid,$address,$status);if(!$s->execute())throw new Exception('Could not create member.');$newId=$s->insert_id;$s->close();
   $s=$conn->prepare("UPDATE enquiries SET status='Converted' WHERE id=?");$s->bind_param('i',$eid);if(!$s->execute())throw new Exception('Could not update enquiry.');$s->close();
   log_activity('Converted enquiry',$enq['name'].' → '.$memberId);$conn->commit();out(true,'Enquiry converted to member.',['member_id'=>$memberId,'member_db_id'=>$newId]);
  }catch(Throwable $e){$conn->rollback();out(false,$e->getMessage(),[],500);}
 }
 if($method==='DELETE'){ require_admin(); $id=(int)($_GET['id']??0); $s=$conn->prepare('DELETE FROM enquiries WHERE id=?');$s->bind_param('i',$id);$ok=$s->execute();$s->close();out($ok,$ok?'Deleted.':'Could not delete.'); }
}
if($action==='expenses'){
 require_permission('expenses');
 if($method==='GET'){ $rows=[];$r=$conn->query("SELECT * FROM expenses ORDER BY expense_date DESC,id DESC");while($x=$r->fetch_assoc())$rows[]=$x; $r=$conn->query("SELECT COALESCE(SUM(amount),0) t FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");$month=(float)$r->fetch_assoc()['t'];out(true,'',['expenses'=>$rows,'month_total'=>$month]); }
 if($method==='POST'){ $id=(int)($_POST['id']??0);$date=trim($_POST['expense_date']??date('Y-m-d'));$cat=trim($_POST['category']??'');$desc=trim($_POST['description']??'');$amt=(float)($_POST['amount']??0);$pm=trim($_POST['payment_method']??'Cash');$vendor=trim($_POST['vendor']??'');$notes=trim($_POST['notes']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))out(false,'Invalid expense date.',[],422);if($cat===''||mb_strlen($cat)>100)out(false,'Valid expense category is required.',[],422);if($amt<=0||$amt>999999999)out(false,'Enter a valid positive expense amount.',[],422);if(!in_array($pm,['Cash','UPI','Online','Bank','Card','Other'],true))out(false,'Invalid payment method.',[],422);if($id){$s=$conn->prepare('UPDATE expenses SET expense_date=?,category=?,description=?,amount=?,payment_method=?,vendor=?,notes=? WHERE id=?');$s->bind_param('sssdsssi',$date,$cat,$desc,$amt,$pm,$vendor,$notes,$id);}else{$s=$conn->prepare('INSERT INTO expenses(expense_date,category,description,amount,payment_method,vendor,notes) VALUES(?,?,?,?,?,?,?)');$s->bind_param('sssdsss',$date,$cat,$desc,$amt,$pm,$vendor,$notes);} $ok=$s->execute();$s->close();log_activity($id?'Updated expense':'Added expense',$cat.' ₹'.$amt);out($ok,$ok?'Saved successfully.':'Could not save expense.'); }
 if($method==='DELETE'){ require_admin(); $id=(int)($_GET['id']??0);$s=$conn->prepare('DELETE FROM expenses WHERE id=?');$s->bind_param('i',$id);$ok=$s->execute();$s->close();out($ok,$ok?'Deleted.':'Could not delete.'); }
}
// Seed attendance location defaults once so existing installations have a valid configuration.
foreach(['attendance_latitude'=>'24.735323','attendance_longitude'=>'81.409182','attendance_radius_meters'=>'100'] as $k=>$v){ $q=$conn->prepare('INSERT IGNORE INTO library_settings(setting_key,setting_value) VALUES(?,?)'); if($q){$q->bind_param('ss',$k,$v);$q->execute();$q->close();} }
if($action==='settings'){
 require_admin();
 if($method==='GET'){
  if(!isset($_SESSION['staff_id'])) out(false,'Please login first.',[],401);
  $data=[];$r=$conn->query('SELECT setting_key,setting_value FROM library_settings');while($x=$r->fetch_assoc())$data[$x['setting_key']]=$x['setting_value']; $data['address']='Ward No. 15, Sarkari Hospital ke Samane, Baikunthpur, Rewa, Madhya Pradesh - 486441'; out(true,'',['settings'=>$data]); }
 if($method==='POST'){ require_admin(); $allowed=['library_name','phone','address','opening_time','closing_time','monthly_fee','currency','total_seats','total_lockers','morning_end','evening_start','receipt_footer','attendance_latitude','attendance_longitude','attendance_radius_meters'];$s=$conn->prepare('INSERT INTO library_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');foreach($allowed as $k){$v=trim($_POST[$k]??'');$s->bind_param('ss',$k,$v);$s->execute();}$s->close();log_activity('Updated settings');out(true,'Settings saved.'); }
}
if($action==='attendance' && $method==='GET'){
 require_permission('attendance');
 $date=$_GET['date']??date('Y-m-d');
 if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))out(false,'Invalid attendance date.',[],422);
 $s=$conn->prepare("SELECT a.id,a.member_id AS member_db_id,m.member_id,m.name,m.phone,m.shift,a.attendance_date,a.check_in,a.check_out,DATE_FORMAT(a.check_in,'%h:%i %p') check_in_time,DATE_FORMAT(a.check_out,'%h:%i %p') check_out_time,a.status,a.remarks FROM attendance a JOIN members m ON m.id=a.member_id WHERE a.attendance_date=? ORDER BY a.check_in DESC,a.id DESC");
 $s->bind_param('s',$date);$s->execute();$r=$s->get_result();$rows=[];while($x=$r->fetch_assoc())$rows[]=$x;$s->close();
 out(true,'',['attendance'=>$rows,'date'=>$date]);
}
if($action==='attendance' && $method==='POST'){
 require_permission('attendance');
 $mid=(int)($_POST['member_id']??0);$remarks=trim($_POST['remarks']??'');$act=$_POST['action']??'in';if($mid<=0)out(false,'Please select a member.',[],422);
 $s=$conn->prepare("SELECT id,name FROM members WHERE id=? AND status='Active' LIMIT 1");$s->bind_param('i',$mid);$s->execute();$member=$s->get_result()->fetch_assoc();$s->close();if(!$member)out(false,'Member not found or inactive.',[],404);
 if($act==='out'){ $s=$conn->prepare("UPDATE attendance SET check_out=NOW(),status='Closed',remarks=? WHERE member_id=? AND attendance_date=CURDATE() AND check_in IS NOT NULL AND check_out IS NULL ORDER BY id DESC LIMIT 1");$s->bind_param('si',$remarks,$mid);$s->execute();$ok=$s->affected_rows>0;$s->close();if(!$ok)out(false,'No open attendance found for this member.',[],422);log_activity('Checked out member',(string)$mid);out(true,'Check-out recorded.'); }
 $s=$conn->prepare("SELECT id FROM attendance WHERE member_id=? AND attendance_date=CURDATE() AND check_in IS NOT NULL AND check_out IS NULL LIMIT 1");$s->bind_param('i',$mid);$s->execute();$open=$s->get_result()->num_rows>0;$s->close();if($open)out(false,'Member is already checked in.',[],422);
 $s=$conn->prepare("INSERT INTO attendance(member_id,attendance_date,check_in,status,remarks) VALUES(?,CURDATE(),NOW(),'Open',?)");$s->bind_param('is',$mid,$remarks);$ok=$s->execute();$s->close();log_activity('Checked in member',(string)$mid);out($ok,$ok?'Check-in recorded.':'Could not record attendance.');
}
if($action==='checkout' && $method==='POST'){ require_permission('attendance'); $id=(int)($_POST['attendance_id']??0);$s=$conn->prepare("UPDATE attendance SET check_out=NOW(),status='Closed' WHERE id=? AND check_out IS NULL");$s->bind_param('i',$id);$s->execute();$ok=$s->affected_rows>0;$s->close();out($ok,$ok?'Check-out recorded.':'Attendance record not found or already closed.');}
if($action==='report'){
 require_permission('reports');
 $type=$_GET['type']??'summary';$from=$_GET['from']??date('Y-m-01');$to=$_GET['to']??date('Y-m-d');
 $data=[];
 if($type==='members'){$r=$conn->query("SELECT member_id,name,phone,membership_plan,shift,joining_date,validity_date,status FROM members ORDER BY id DESC");while($x=$r->fetch_assoc())$data[]=$x;}
 elseif($type==='fees'){$s=$conn->prepare("SELECT receipt_no,member_code,amount,payment_date,payment_method,plan FROM payments WHERE payment_date BETWEEN ? AND ? ORDER BY payment_date DESC,id DESC");$s->bind_param('ss',$from,$to);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$data[]=$x;$s->close();}
 elseif($type==='attendance'){$s=$conn->prepare("SELECT a.attendance_date,m.member_id,m.name,a.check_in,a.check_out,a.status FROM attendance a JOIN members m ON m.id=a.member_id WHERE a.attendance_date BETWEEN ? AND ? ORDER BY a.attendance_date DESC,a.id DESC");$s->bind_param('ss',$from,$to);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$data[]=$x;$s->close();}
 elseif($type==='expenses'){$s=$conn->prepare("SELECT expense_date,category,description,amount,payment_method,vendor FROM expenses WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date DESC,id DESC");$s->bind_param('ss',$from,$to);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$data[]=$x;$s->close();}
 else { $s=$conn->prepare("SELECT (SELECT COUNT(*) FROM members) members,(SELECT COUNT(*) FROM members WHERE status='Active' AND (validity_date IS NULL OR validity_date>=CURDATE())) active,(SELECT COUNT(*) FROM attendance WHERE attendance_date BETWEEN ? AND ? AND check_in IS NOT NULL) attendance,(SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date BETWEEN ? AND ?) fees,(SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?) expenses,(SELECT COUNT(*) FROM enquiries WHERE created_at>=CONCAT(?, ' 00:00:00') AND created_at<=CONCAT(?, ' 23:59:59')) enquiries,(SELECT COUNT(*) FROM enquiries WHERE status='Converted' AND created_at>=CONCAT(?, ' 00:00:00') AND created_at<=CONCAT(?, ' 23:59:59')) converted_enquiries");$s->bind_param('ssssssssss',$from,$to,$from,$to,$from,$to,$from,$to,$from,$to);$s->execute();$data=$s->get_result()->fetch_assoc();$s->close();$data['net_income']=(float)$data['fees']-(float)$data['expenses']; }
 out(true,'',['type'=>$type,'from'=>$from,'to'=>$to,'data'=>$data]);
}
out(false,'Unknown action.',[],400);
