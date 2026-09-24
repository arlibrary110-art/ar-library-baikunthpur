<?php
/**
 * ONE-TIME Railway/MySQL -> MongoDB migration.
 *
 * Safety: this endpoint is disabled unless ENABLE_RAILWAY_MIGRATION=1.
 * After a successful migration, remove that environment variable and all OLD_DB_* variables.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}
require_once __DIR__ . '/../db.php';
if (ar_env('ENABLE_RAILWAY_MIGRATION') !== '1') {
    fwrite(STDERR, "Migration disabled. Set ENABLE_RAILWAY_MIGRATION=1 temporarily.\n");
    exit(2);
}

$host=ar_env('OLD_DB_HOST');
$port=(int)ar_env('OLD_DB_PORT','3306');
$user=ar_env('OLD_DB_USER');
$pass=getenv('OLD_DB_PASSWORD');
$name=ar_env('OLD_DB_NAME','ar_library');
if($host===''||$user===''||$pass===false){fwrite(STDERR,"Set OLD_DB_HOST, OLD_DB_PORT, OLD_DB_NAME, OLD_DB_USER and OLD_DB_PASSWORD.\n");exit(3);}

$old=@new mysqli($host,$user,$pass,$name,$port);
if($old->connect_error){fwrite(STDERR,'Old Railway database connection failed: '.$old->connect_error."\n");exit(4);}
$old->set_charset('utf8mb4');

$tables=[
 'staff'=>['id','staff_id','name','photo','password','role','status','permissions_configured','created_at'],
 'members'=>['id','member_id','name','phone','email','membership_plan','shift','joining_date','date_of_birth','validity_date','address','status','created_at'],
 'member_fees'=>['id','member_id','receipt_no','amount','payment_date','due_date','payment_method','status','remarks','created_at'],
 'payments'=>['id','receipt_no','member_id','member_code','amount','fee_amount','additional_charges','payment_type','payment_date','payment_method','plan','notes','created_at'],
 'fee_settings'=>['setting_key','setting_value'],
 'member_seats'=>['id','member_id','seat_no','shift','start_date','end_date','status','remarks','created_at'],
 'lockers'=>['id','locker_no','member_id','start_date','end_date','status','remarks','created_at'],
 'attendance'=>['id','member_id','attendance_date','check_in','check_out','status','remarks','created_at','updated_at'],
 'enquiries'=>['id','name','phone','requirement','follow_up','status','notes','created_at'],
 'expenses'=>['id','expense_date','category','description','amount','payment_method','vendor','notes','created_at'],
 'library_settings'=>['setting_key','setting_value'],
 'activity_logs'=>['id','staff_id','action','details','created_at'],
 'staff_permissions'=>['staff_id','permission_key','created_at'],
 'member_messages'=>['id','staff_id','title','message','created_at'],
];

$counts=[];
foreach($tables as $table=>$columns){
    $check=$old->query("SHOW TABLES LIKE '".$old->real_escape_string($table)."'");
    if(!$check||$check->num_rows===0)continue;
    $conn->query("DELETE FROM `".$table."`");
    $cols=implode(',',array_map(fn($c)=>'`'.$c.'`',$columns));
    $rs=$old->query("SELECT ".$cols." FROM `".$table."`");
    if(!$rs)throw new Exception('Could not read '.$table.': '.$old->error);
    $marks=implode(',',array_fill(0,count($columns),'?'));
    $stmt=$conn->prepare("INSERT INTO `".$table."` (".$cols.") VALUES (".$marks.")");
    if(!$stmt)throw new Exception('Could not prepare '.$table.': '.$conn->error);
    $n=0;
    while($row=$rs->fetch_assoc()){
        $types='';$vals=[];
        foreach($columns as $c){$v=$row[$c]??null;if(in_array($c,['id','member_id'],true))$types.='i';elseif(in_array($c,['amount','fee_amount','additional_charges','setting_value'],true)&&is_numeric($v))$types.='d';else$types.='s';$vals[]=$v;}
        $stmt->bind_param($types,...$vals);
        if(!$stmt->execute())throw new Exception('Insert failed for '.$table.': '.$stmt->error);
        $n++;
    }
    $stmt->close();$counts[$table]=$n;
}
$old->close();
echo json_encode(['success'=>true,'message'=>'Railway data copied to MongoDB. Remove ENABLE_RAILWAY_MIGRATION and OLD_DB_* variables now.','tables'=>$counts],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
