<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/security.php';
header('Content-Type: application/json; charset=utf-8');
// Keep API responses JSON-only even if a legacy include emits a warning/notice.
ob_start();
require_staff();
function m_out($ok,$message='',$extra=[],$status=200){
    if (ob_get_level() > 0) { ob_end_clean(); }
    json_out($ok,$message,$extra,$status);
}

function member_father_name($memberId){
    global $conn;
    $key='member_father_'.(int)$memberId;
    $s=$conn->prepare('SELECT setting_value FROM library_settings WHERE setting_key=? LIMIT 1');
    if(!$s)return '';
    $s->bind_param('s',$key);$s->execute();$v=(string)($s->get_result()->fetch_assoc()['setting_value']??'');$s->close();return $v;
}
function save_member_father_name($memberId,$value){
    global $conn;
    $key='member_father_'.(int)$memberId;$value=trim((string)$value);
    $s=$conn->prepare('INSERT INTO library_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value');
    if($s){$s->bind_param('ss',$key,$value);$s->execute();$s->close();}
}

$method=$_SERVER['REQUEST_METHOD'];
if($method!=='GET'){verify_state_change();require_csrf();}
$action=$_GET['action']??'';
if($action==='list'){
 require_permission('members');
 $rows=[];$archived=[];
 $r=$conn->query("SELECT id,member_id,name,phone,email,membership_plan,shift,joining_date,date_of_birth,validity_date,address,status,created_at FROM members WHERE status<>'Archived' ORDER BY id DESC");
 if($r)while($x=$r->fetch_assoc()){ $x['father_name']=member_father_name((int)$x['id']); $rows[]=$x; }
 $r2=$conn->query("SELECT id,member_id,name,phone,email,membership_plan,shift,joining_date,date_of_birth,validity_date,address,status,created_at FROM members WHERE status='Archived' ORDER BY id DESC");
 if($r2)while($x=$r2->fetch_assoc()){ $x['father_name']=member_father_name((int)$x['id']); $archived[]=$x; }
 m_out(true,'',['members'=>$rows,'archived_members'=>$archived]);
}
if($action==='recycle') {
 require_admin();
 $rows=[];
 $r=$conn->query("SELECT id,member_id,name,phone,email,membership_plan,shift,joining_date,date_of_birth,validity_date,address,status,created_at FROM members WHERE status='Archived' ORDER BY id DESC");
 if($r) while($x=$r->fetch_assoc()) $rows[]=$x;
 m_out(true,'',['members'=>$rows]);
}
if($action==='get'){
 require_permission('members');$id=(int)($_GET['id']??0);if($id<=0)m_out(false,'Invalid member ID.',[],422);
 $s=$conn->prepare('SELECT id,member_id,name,phone,email,membership_plan,shift,joining_date,date_of_birth,validity_date,address,status,created_at FROM members WHERE id=? LIMIT 1');$s->bind_param('i',$id);$s->execute();$m=$s->get_result()->fetch_assoc();$s->close();if(!$m)m_out(false,'Member not found.',[],404);$m['father_name']=member_father_name((int)$m['id']);m_out(true,'',['member'=>$m]);
}
if($action==='create'||$action==='update'){
 require_permission('members');
 $id=(int)($_POST['id']??0);$memberId=trim($_POST['member_id']??'');$name=trim($_POST['name']??'');$phone=trim($_POST['phone']??'');$email=trim($_POST['email']??'');$plan=trim($_POST['membership_plan']??'1 Month');$shift=trim($_POST['shift']??'Full Day');$join=trim($_POST['joining_date']??date('Y-m-d'));$dob=trim($_POST['date_of_birth']??'');$valid=trim($_POST['validity_date']??'');$address=trim($_POST['address']??'');$father=trim($_POST['father_name']??'');$status=trim($_POST['status']??'Active');
 if($memberId===''||!preg_match('/^[A-Za-z0-9_-]{2,50}$/',$memberId))m_out(false,'Enter a valid Member ID.',[],422);if($name==='')m_out(false,'Name is required.',[],422);if(!in_array($plan,['1 Month','3 Months','6 Months','Custom'],true))$plan='1 Month';if(!in_array($shift,['Full Day','Full Day Reserved','Morning Shift','Evening Shift','Morning Shift Reserved','Evening Shift Reserved'],true))$shift='Full Day';if($plan!=='Custom'){ $months=$plan==='3 Months'?3:($plan==='6 Months'?6:1); if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$join))$valid=date('Y-m-d',strtotime($join.' +'.$months.' month -1 day')); } if($plan==='Custom' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$valid))m_out(false,'Custom plan ke liye Validity Date select karein.',[],422);if($dob!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dob))m_out(false,'Invalid birthday/date of birth.',[],422);if(!in_array($status,['Active','Inactive','Expired'],true))$status='Active';
 if($id>0){$s=$conn->prepare('SELECT id FROM members WHERE member_id=? AND id<>? LIMIT 1');$s->bind_param('si',$memberId,$id);$s->execute();if($s->get_result()->num_rows){$s->close();m_out(false,'Member ID already exists.',[],409);}$s->close();$s=$conn->prepare('UPDATE members SET member_id=?,name=?,phone=?,email=?,membership_plan=?,shift=?,joining_date=?,date_of_birth=?,validity_date=?,address=?,status=? WHERE id=?');$s->bind_param('sssssssssssi',$memberId,$name,$phone,$email,$plan,$shift,$join,$dob,$valid,$address,$status,$id);$ok=$s->execute();$s->close();if($ok)save_member_father_name($id,$father);m_out($ok,$ok?'Member updated successfully.':'Could not update member.');}
 $s=$conn->prepare('SELECT id FROM members WHERE member_id=? LIMIT 1');$s->bind_param('s',$memberId);$s->execute();if($s->get_result()->num_rows){$s->close();m_out(false,'Member ID already exists.',[],409);}$s->close();$s=$conn->prepare('INSERT INTO members(member_id,name,phone,email,membership_plan,shift,joining_date,date_of_birth,validity_date,address,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$s->bind_param('sssssssssss',$memberId,$name,$phone,$email,$plan,$shift,$join,$dob,$valid,$address,$status);$ok=$s->execute();$new=$s->insert_id;$s->close();if($ok)save_member_father_name($new,$father);m_out($ok,$ok?'Member created successfully.':'Could not create member.',['id'=>$new]);
}
if($action==='renew'&&$method==='POST'){
 require_permission('members');
 $id=(int)($_POST['id']??0);$plan=trim((string)($_POST['membership_plan']??'1 Month'));$shift=trim((string)($_POST['shift']??'Full Day'));$renewDate=trim((string)($_POST['renewal_date']??date('Y-m-d')));
 if($id<=0)m_out(false,'Invalid member ID.',[],422);
 if(!in_array($plan,['1 Month','3 Months','6 Months'],true))m_out(false,'Invalid renewal plan.',[],422);
 if(!in_array($shift,['Morning Shift','Evening Shift','Evening Shift Reserved','Morning Shift Reserved','Full Day','Full Day Reserved'],true))m_out(false,'Select a valid renewal shift.',[],422);
 $months=$plan==='3 Months'?3:($plan==='6 Months'?6:1);
 if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$renewDate))m_out(false,'Invalid renewal date.',[],422);
 $s=$conn->prepare('SELECT id,member_id,name,membership_plan,shift,status,validity_date FROM members WHERE id=? LIMIT 1');
 if(!$s)m_out(false,'Could not prepare member query.',[],500);$s->bind_param('i',$id);$s->execute();$member=$s->get_result()->fetch_assoc();$s->close();
 if(!$member)m_out(false,'Member not found.',[],404);
 $planKey=(stripos($shift,'full day')!==false?'full_day':'half_day');if(stripos($shift,'reserved')!==false)$planKey.='_reserved';
 $suffix=$months===6?'_six':($months===3?'_three':($months===1?'_one':''));$amount=0.0;
 if($suffix!==''){$settingKey=$planKey.$suffix;$fs=$conn->prepare('SELECT setting_value FROM fee_settings WHERE setting_key=? LIMIT 1');if($fs){$fs->bind_param('s',$settingKey);$fs->execute();$fv=$fs->get_result()->fetch_assoc();$amount=(float)($fv['setting_value']??0);$fs->close();}}
 if($amount<=0){$fallbacks=['half_day_one'=>600,'half_day_three'=>1710,'half_day_six'=>3240,'half_day_reserved_one'=>800,'half_day_reserved_three'=>2280,'half_day_reserved_six'=>4320,'full_day_one'=>1100,'full_day_three'=>3135,'full_day_six'=>5940,'full_day_reserved_one'=>1300,'full_day_reserved_three'=>3705,'full_day_reserved_six'=>7020];$amount=(float)($fallbacks[$planKey.$suffix]??0);}
 if($amount<=0)m_out(false,'Fee amount for this plan/shift is not configured.',[],422);
 $start=new DateTime($renewDate);$end=clone $start;$end->modify('+'.$months.' month')->modify('-1 day');$validity=$end->format('Y-m-d');
 $conn->begin_transaction();try{
  $up=$conn->prepare("UPDATE members SET membership_plan=?,shift=?,validity_date=?,status='Active' WHERE id=?");if(!$up)throw new RuntimeException('Could not prepare member renewal.');$up->bind_param('sssi',$plan,$shift,$validity,$id);if(!$up->execute())throw new RuntimeException('Could not update member renewal.');$up->close();
  $remark='Renewal due — '.$plan.' / '.$shift.'; renewal date '.$renewDate;
  $ins=$conn->prepare("INSERT INTO member_fees (member_id,receipt_no,amount,payment_date,due_date,payment_method,status,remarks) VALUES (?,NULL,?,?,?,'Pending','Pending',?)");if(!$ins)throw new RuntimeException('Could not prepare renewal due.');$ins->bind_param('idsss',$id,$amount,$renewDate,$validity,$remark);if(!$ins->execute())throw new RuntimeException('Could not create renewal due: '.$ins->error);$feeId=(int)$ins->insert_id;$ins->close();
  if(!$conn->commit())throw new RuntimeException('Could not complete renewal.');m_out(true,'Member renewed successfully. Renewal fee is now due.',['member_id'=>$id,'fee_id'=>$feeId,'amount'=>$amount,'validity_date'=>$validity,'plan'=>$plan,'shift'=>$shift]);
 }catch(Throwable $e){try{$conn->rollback();}catch(Throwable $ignored){}m_out(false,'Renewal failed: '.$e->getMessage(),[],500);}
}
if($action==='reset_system'&&$method==='POST'){
 require_admin();
 $confirm=trim((string)($_POST['confirm']??''));
 if($confirm!=='RESET')m_out(false,'Reset confirm karne ke liye RESET type karein.',[],422);
 $currentStaffId=(string)($_SESSION['staff_id']??'');
 if($currentStaffId==='')m_out(false,'Admin session not found.',[],401);
 // Fresh start clears operational/library data but keeps the currently logged-in
 // admin account so the application remains accessible after the reset.
 $tables=[
   'member_fees','payments','member_seats','lockers','attendance','enquiries',
   'expenses','member_messages','activity_logs','staff_permissions','members',
   'library_settings','fee_settings'
 ];
 $conn->begin_transaction();
 try{
   foreach($tables as $table){
     if(!$conn->query('DELETE FROM `'.$table.'`'))throw new RuntimeException('Could not clear '.$table.'.');
   }
   // Remove all other staff accounts; retain only the admin who initiated reset.
   $s=$conn->prepare('DELETE FROM staff WHERE staff_id<>?');
   if(!$s)throw new RuntimeException('Could not reset staff accounts.');
   $s->bind_param('s',$currentStaffId);
   if(!$s->execute()){ $s->close(); throw new RuntimeException('Could not reset staff accounts.'); }
   $s->close();
   $conn->commit();
   // Restore application defaults through the adapter's normal bootstrap on next request.
   log_activity('Fresh system reset','All library/member/fee/attendance/expense data cleared; current admin retained.');
   m_out(true,'Fresh reset complete. All operational data has been cleared. Your current Admin account has been kept so you can start with new data.');
 }catch(Throwable $e){
   try{$conn->rollback();}catch(Throwable $ignored){}
   m_out(false,'Fresh reset failed: '.$e->getMessage(),[],500);
 }
}
if($action==='delete'&&$method==='POST'){
 require_admin();
 $id=(int)($_POST['id']??0);
 if($id<=0)m_out(false,'Invalid member ID.',[],422);
 $s=$conn->prepare("SELECT id,member_id,name,status FROM members WHERE id=? LIMIT 1");
 $s->bind_param('i',$id);$s->execute();$member=$s->get_result()->fetch_assoc();$s->close();
 if(!$member)m_out(false,'Member not found.',[],404);
 if((string)$member['status']==='Archived')m_out(true,'Member is already archived.');
 $s=$conn->prepare("UPDATE members SET status='Archived' WHERE id=?");
 if(!$s)m_out(false,'Could not prepare member archive.',[],500);
 $s->bind_param('i',$id);$ok=$s->execute();$s->close();
 m_out($ok,$ok?'Member archived successfully. Fee/payment history has been preserved.':'Could not archive member.',[], $ok?200:500);
}
if($action==='restore'&&$method==='POST'){
 require_admin();
 $id=(int)($_POST['id']??0);
 if($id<=0)m_out(false,'Invalid member ID.',[],422);
 $s=$conn->prepare("SELECT id,member_id,status FROM members WHERE id=? LIMIT 1");
 $s->bind_param('i',$id);$s->execute();$member=$s->get_result()->fetch_assoc();$s->close();
 if(!$member)m_out(false,'Member not found.',[],404);
 if((string)$member['status']!=='Archived')m_out(true,'Member is already active.');
 $s=$conn->prepare("UPDATE members SET status='Active' WHERE id=?");
 if(!$s)m_out(false,'Could not prepare member restore.',[],500);
 $s->bind_param('i',$id);$ok=$s->execute();$s->close();
 m_out($ok,$ok?'Member restored successfully.':'Could not restore member.',[], $ok?200:500);
}
if($action==='fees'){
 require_permission('members');$id=(int)($_GET['id']??0);if($id<=0)m_out(false,'Invalid member ID.',[],422);$rows=[];$s=$conn->prepare('SELECT id,receipt_no,amount,payment_date,due_date,payment_method,status,remarks,created_at FROM member_fees WHERE member_id=? ORDER BY id DESC');$s->bind_param('i',$id);$s->execute();$r=$s->get_result();$paid=0;$pending=0;while($x=$r->fetch_assoc()){$x['amount']=(float)$x['amount'];if(strtolower($x['status'])==='paid')$paid+=$x['amount'];else $pending+=$x['amount'];$rows[]=$x;}$s->close();m_out(true,'',['fees'=>$rows,'summary'=>['total_paid'=>$paid,'total_pending'=>$pending]]);
}
if($action==='add_fee'&&$method==='POST'){
 require_permission('members');$id=(int)($_POST['member_id']??0);$amount=(float)($_POST['amount']??0);$paymentDate=trim($_POST['payment_date']??date('Y-m-d'));$due=trim($_POST['due_date']??'');$pm=trim($_POST['payment_method']??'Cash');$status=trim($_POST['status']??'Paid');$remarks=trim($_POST['remarks']??'');if($id<=0||$amount<=0)m_out(false,'Valid member and amount are required.',[],422);$receipt='ARF-'.date('ymdHis').'-'.strtoupper(bin2hex(random_bytes(2)));$s=$conn->prepare('INSERT INTO member_fees(member_id,receipt_no,amount,payment_date,due_date,payment_method,status,remarks) VALUES(?,?,?,?,?,?,?,?)');$s->bind_param('isdsssss',$id,$receipt,$amount,$paymentDate,$due,$pm,$status,$remarks);$ok=$s->execute();$s->close();m_out($ok,$ok?'Fee record saved.':'Could not save fee.',['receipt_no'=>$receipt]);
}
if($action==='delete_fee'&&$method==='POST'){require_admin();$id=(int)($_POST['id']??0);$s=$conn->prepare('DELETE FROM member_fees WHERE id=?');$s->bind_param('i',$id);$ok=$s->execute();$s->close();m_out($ok,$ok?'Fee record deleted.':'Could not delete fee.');}
if($action==='attendance'){
 require_permission('members');$id=(int)($_GET['id']??0);$rows=[];$s=$conn->prepare("SELECT id,attendance_date,DATE_FORMAT(check_in,'%h:%i %p') check_in,DATE_FORMAT(check_out,'%h:%i %p') check_out,status,remarks FROM attendance WHERE member_id=? ORDER BY attendance_date DESC,id DESC LIMIT 100");$s->bind_param('i',$id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$rows[]=$x;$s->close();m_out(true,'',['attendance'=>$rows]);
}
if($action==='add_attendance'&&$method==='POST'){
 require_permission('members');$id=(int)($_POST['member_id']??0);$date=trim($_POST['attendance_date']??date('Y-m-d'));$in=trim($_POST['check_in']??'');$out=trim($_POST['check_out']??'');$status=trim($_POST['status']??'Open');$remarks=trim($_POST['remarks']??'');if($id<=0)m_out(false,'Invalid member.',[],422);$s=$conn->prepare('INSERT INTO attendance(member_id,attendance_date,check_in,check_out,status,remarks) VALUES(?,?,NULLIF(?,""),NULLIF(?,""),?,?) ON DUPLICATE KEY UPDATE check_in=VALUES(check_in),check_out=VALUES(check_out),status=VALUES(status),remarks=VALUES(remarks)');$s->bind_param('isssss',$id,$date,$in,$out,$status,$remarks);$ok=$s->execute();$s->close();m_out($ok,$ok?'Attendance saved.':'Could not save attendance.');
}
if($action==='seat'){
 require_permission('members');$id=(int)($_GET['id']??0);$s=$conn->prepare("SELECT id,seat_no,shift,start_date,end_date,status,remarks FROM member_seats WHERE member_id=? ORDER BY id DESC LIMIT 1");$s->bind_param('i',$id);$s->execute();$seat=$s->get_result()->fetch_assoc();$s->close();m_out(true,'',['seat'=>$seat]);
}
if($action==='save_seat'&&$method==='POST'){
 require_permission('members');$id=(int)($_POST['member_id']??0);$seat=trim($_POST['seat_no']??'');$shift=trim($_POST['shift']??'Full Day');$start=trim($_POST['start_date']??date('Y-m-d'));$end=trim($_POST['end_date']??'');$status=trim($_POST['status']??'Assigned');$remarks=trim($_POST['remarks']??'');if($id<=0||$seat==='')m_out(false,'Member and seat are required.',[],422);$s=$conn->prepare("UPDATE member_seats SET status='Inactive',end_date=CURDATE() WHERE member_id=? AND status='Assigned'");$s->bind_param('i',$id);$s->execute();$s->close();$s=$conn->prepare('INSERT INTO member_seats(member_id,seat_no,shift,start_date,end_date,status,remarks) VALUES(?,?,?,?,?,?,?)');$s->bind_param('issssss',$id,$seat,$shift,$start,$end,$status,$remarks);$ok=$s->execute();$s->close();m_out($ok,$ok?'Seat saved.':'Could not save seat.');
}
m_out(false,'Unknown action.',[],400);
