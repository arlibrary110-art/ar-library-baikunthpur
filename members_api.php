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
$method=$_SERVER['REQUEST_METHOD'];
if($method!=='GET'){verify_state_change();require_csrf();}
$action=$_GET['action']??'';
if($action==='list'){
 require_permission('members');
 $rows=[];$r=$conn->query("SELECT id,member_id,name,phone,email,membership_plan,shift,joining_date,date_of_birth,validity_date,address,status,created_at FROM members ORDER BY id DESC");
 if($r)while($x=$r->fetch_assoc())$rows[]=$x;
 m_out(true,'',['members'=>$rows]);
}
if($action==='get'){
 require_permission('members');$id=(int)($_GET['id']??0);if($id<=0)m_out(false,'Invalid member ID.',[],422);
 $s=$conn->prepare('SELECT id,member_id,name,phone,email,membership_plan,shift,joining_date,date_of_birth,validity_date,address,status,created_at FROM members WHERE id=? LIMIT 1');$s->bind_param('i',$id);$s->execute();$m=$s->get_result()->fetch_assoc();$s->close();if(!$m)m_out(false,'Member not found.',[],404);m_out(true,'',['member'=>$m]);
}
if($action==='create'||$action==='update'){
 require_permission('members');
 $id=(int)($_POST['id']??0);$memberId=trim($_POST['member_id']??'');$name=trim($_POST['name']??'');$phone=trim($_POST['phone']??'');$email=trim($_POST['email']??'');$plan=trim($_POST['membership_plan']??'1 Month');$shift=trim($_POST['shift']??'Full Day');$join=trim($_POST['joining_date']??date('Y-m-d'));$dob=trim($_POST['date_of_birth']??'');$valid=trim($_POST['validity_date']??'');$address=trim($_POST['address']??'');$status=trim($_POST['status']??'Active');
 if($memberId===''||!preg_match('/^[A-Za-z0-9_-]{2,50}$/',$memberId))m_out(false,'Enter a valid Member ID.',[],422);if($name==='')m_out(false,'Name is required.',[],422);if(!in_array($shift,['Full Day','Morning Shift','Evening Shift'],true))$shift='Full Day';if(!in_array($status,['Active','Inactive','Expired'],true))$status='Active';
 if($id>0){$s=$conn->prepare('SELECT id FROM members WHERE member_id=? AND id<>? LIMIT 1');$s->bind_param('si',$memberId,$id);$s->execute();if($s->get_result()->num_rows){$s->close();m_out(false,'Member ID already exists.',[],409);}$s->close();$s=$conn->prepare('UPDATE members SET member_id=?,name=?,phone=?,email=?,membership_plan=?,shift=?,joining_date=?,date_of_birth=?,validity_date=?,address=?,status=? WHERE id=?');$s->bind_param('sssssssssssi',$memberId,$name,$phone,$email,$plan,$shift,$join,$dob,$valid,$address,$status,$id);$ok=$s->execute();$s->close();m_out($ok,$ok?'Member updated successfully.':'Could not update member.');}
 $s=$conn->prepare('SELECT id FROM members WHERE member_id=? LIMIT 1');$s->bind_param('s',$memberId);$s->execute();if($s->get_result()->num_rows){$s->close();m_out(false,'Member ID already exists.',[],409);}$s->close();$s=$conn->prepare('INSERT INTO members(member_id,name,phone,email,membership_plan,shift,joining_date,date_of_birth,validity_date,address,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$s->bind_param('sssssssssss',$memberId,$name,$phone,$email,$plan,$shift,$join,$dob,$valid,$address,$status);$ok=$s->execute();$new=$s->insert_id;$s->close();m_out($ok,$ok?'Member created successfully.':'Could not create member.',['id'=>$new]);
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
