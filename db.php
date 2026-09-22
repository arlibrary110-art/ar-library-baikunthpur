<?php
/**
 * AR Library - Google Sheets database adapter.
 *
 * This file keeps the existing PHP application API (mysqli-like prepare/query)
 * while using Google Sheets as the persistent datastore. Each HTTP request
 * loads the configured spreadsheet into a temporary SQLite database, runs the
 * existing SQL against SQLite, and writes changed tables back to Sheets.
 *
 * Required Render environment variables:
 *   GOOGLE_SHEET_ID
 *   GOOGLE_SERVICE_ACCOUNT_JSON
 */
@date_default_timezone_set('Asia/Kolkata');

if (!class_exists('SQLite3')) {
    http_response_code(500);
    die('SQLite3 PHP extension is required. Please redeploy the latest Dockerfile.');
}

function ar_env($key, $default = '') {
    $v = getenv($key);
    return ($v === false) ? $default : trim((string)$v);
}

final class ARSheetsResult {
    private array $rows;
    private int $index = 0;
    public int $num_rows = 0;
    private array $fields;

    public function __construct(array $rows = [], array $fields = []) {
        $this->rows = array_values($rows);
        $this->num_rows = count($this->rows);
        $this->fields = $fields ?: (isset($this->rows[0]) ? array_keys($this->rows[0]) : []);
    }
    public function fetch_assoc() {
        if ($this->index >= $this->num_rows) return null;
        return $this->rows[$this->index++];
    }
    public function fetch_row() {
        $row = $this->fetch_assoc();
        return $row === null ? null : array_values($row);
    }
    public function fetch_fields() {
        $out = [];
        foreach ($this->fields as $name) $out[] = (object)['name' => $name];
        return $out;
    }
    public function free() { $this->rows = []; $this->index = 0; }
}

final class ARSheetsStatement {
    private ARSheetsDB $db;
    private string $sql;
    private array $params = [];
    private array $types = [];
    private ?ARSheetsResult $result = null;
    public int $affected_rows = 0;
    public string $error = '';
    public int $errno = 0;

    public function __construct(ARSheetsDB $db, string $sql) {
        $this->db = $db;
        $this->sql = $sql;
    }
    public function bind_param(string $types, &...$vars): bool {
        $this->types = str_split($types);
        $this->params = [];
        foreach ($vars as &$v) $this->params[] =& $v;
        return true;
    }
    public function execute(): bool {
        $this->result = null;
        $ok = $this->db->executePrepared($this->sql, $this->params, $this->types, $this);
        return $ok;
    }
    public function get_result(): ?ARSheetsResult { return $this->result; }
    public function close(): void {}
    public function setResult(?ARSheetsResult $result): void { $this->result = $result; }
}

final class ARSheetsDB {
    private SQLite3 $sqlite;
    private string $sheetId;
    private array $serviceAccount;
    private string $accessToken = '';
    private array $tables = [];
    private array $dirty = [];
    private bool $inTransaction = false;
    private bool $loaded = false;
    public string $error = '';
    public int $errno = 0;
    public int $affected_rows = 0;
    public int $insert_id = 0;

    private const SCHEMA = [
        'staff' => [
            'columns'=>['id','staff_id','name','photo','password','role','status','permissions_configured','created_at'],
            'types'=>['id'=>'INTEGER','staff_id'=>'TEXT','name'=>'TEXT','photo'=>'TEXT','password'=>'TEXT','role'=>'TEXT','status'=>'TEXT','permissions_configured'=>'INTEGER','created_at'=>'TEXT'],
            'pk'=>['id'],'unique'=>[['staff_id']]
        ],
        'members' => [
            'columns'=>['id','member_id','name','phone','email','membership_plan','shift','joining_date','date_of_birth','validity_date','address','status','created_at'],
            'types'=>['id'=>'INTEGER','member_id'=>'TEXT','name'=>'TEXT','phone'=>'TEXT','email'=>'TEXT','membership_plan'=>'TEXT','shift'=>'TEXT','joining_date'=>'TEXT','date_of_birth'=>'TEXT','validity_date'=>'TEXT','address'=>'TEXT','status'=>'TEXT','created_at'=>'TEXT'],
            'pk'=>['id'],'unique'=>[['member_id']]
        ],
        'member_fees' => [
            'columns'=>['id','member_id','receipt_no','amount','payment_date','due_date','payment_method','status','remarks','created_at'],
            'types'=>['id'=>'INTEGER','member_id'=>'INTEGER','receipt_no'=>'TEXT','amount'=>'REAL','payment_date'=>'TEXT','due_date'=>'TEXT','payment_method'=>'TEXT','status'=>'TEXT','remarks'=>'TEXT','created_at'=>'TEXT'],
            'pk'=>['id']
        ],
        'payments' => [
            'columns'=>['id','receipt_no','member_id','member_code','amount','fee_amount','additional_charges','payment_type','payment_date','payment_method','plan','notes','created_at'],
            'types'=>['id'=>'INTEGER','receipt_no'=>'TEXT','member_id'=>'INTEGER','member_code'=>'TEXT','amount'=>'REAL','fee_amount'=>'REAL','additional_charges'=>'REAL','payment_type'=>'TEXT','payment_date'=>'TEXT','payment_method'=>'TEXT','plan'=>'TEXT','notes'=>'TEXT','created_at'=>'TEXT'],
            'pk'=>['id'],'unique'=>[['receipt_no']]
        ],
        'fee_settings' => [
            'columns'=>['setting_key','setting_value'],
            'types'=>['setting_key'=>'TEXT','setting_value'=>'REAL'],
            'pk'=>['setting_key']
        ],
        'member_seats' => [
            'columns'=>['id','member_id','seat_no','shift','start_date','end_date','status','remarks','created_at'],
            'types'=>['id'=>'INTEGER','member_id'=>'INTEGER','seat_no'=>'TEXT','shift'=>'TEXT','start_date'=>'TEXT','end_date'=>'TEXT','status'=>'TEXT','remarks'=>'TEXT','created_at'=>'TEXT'],
            'pk'=>['id']
        ],
        'lockers' => [
            'columns'=>['id','locker_no','member_id','start_date','end_date','status','remarks','created_at'],
            'types'=>['id'=>'INTEGER','locker_no'=>'TEXT','member_id'=>'INTEGER','start_date'=>'TEXT','end_date'=>'TEXT','status'=>'TEXT','remarks'=>'TEXT','created_at'=>'TEXT'],
            'pk'=>['id']
        ],
        'attendance' => [
            'columns'=>['id','member_id','attendance_date','check_in','check_out','status','remarks','created_at','updated_at'],
            'types'=>['id'=>'INTEGER','member_id'=>'INTEGER','attendance_date'=>'TEXT','check_in'=>'TEXT','check_out'=>'TEXT','status'=>'TEXT','remarks'=>'TEXT','created_at'=>'TEXT','updated_at'=>'TEXT'],
            'pk'=>['id'],'unique'=>[['member_id','attendance_date']]
        ],
        'enquiries' => [
            'columns'=>['id','name','phone','requirement','follow_up','status','notes','created_at'],
            'types'=>['id'=>'INTEGER','name'=>'TEXT','phone'=>'TEXT','requirement'=>'TEXT','follow_up'=>'TEXT','status'=>'TEXT','notes'=>'TEXT','created_at'=>'TEXT'],
            'pk'=>['id']
        ],
        'expenses' => [
            'columns'=>['id','expense_date','category','description','amount','payment_method','vendor','notes','created_at'],
            'types'=>['id'=>'INTEGER','expense_date'=>'TEXT','category'=>'TEXT','description'=>'TEXT','amount'=>'REAL','payment_method'=>'TEXT','vendor'=>'TEXT','notes'=>'TEXT','created_at'=>'TEXT'],
            'pk'=>['id']
        ],
        'library_settings' => [
            'columns'=>['setting_key','setting_value'],
            'types'=>['setting_key'=>'TEXT','setting_value'=>'TEXT'],
            'pk'=>['setting_key']
        ],
        'activity_logs' => [
            'columns'=>['id','staff_id','action','details','created_at'],
            'types'=>['id'=>'INTEGER','staff_id'=>'TEXT','action'=>'TEXT','details'=>'TEXT','created_at'=>'TEXT'],
            'pk'=>['id']
        ],
        'staff_permissions' => [
            'columns'=>['staff_id','permission_key','created_at'],
            'types'=>['staff_id'=>'TEXT','permission_key'=>'TEXT','created_at'=>'TEXT'],
            'pk'=>['staff_id','permission_key']
        ],
        'member_messages' => [
            'columns'=>['id','staff_id','title','message','created_at'],
            'types'=>['id'=>'INTEGER','staff_id'=>'TEXT','title'=>'TEXT','message'=>'TEXT','created_at'=>'TEXT'],
            'pk'=>['id']
        ],
    ];

    public function __construct() {
        $this->sheetId = ar_env('GOOGLE_SHEET_ID');
        $json = ar_env('GOOGLE_SERVICE_ACCOUNT_JSON');
        if ($this->sheetId === '' || $json === '') {
            $this->fail('Google Sheets is not configured. Set GOOGLE_SHEET_ID and GOOGLE_SERVICE_ACCOUNT_JSON in Render Environment.');
            return;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            $this->fail('GOOGLE_SERVICE_ACCOUNT_JSON is invalid.');
            return;
        }
        $this->serviceAccount = $decoded;

        $this->sqlite = new SQLite3(':memory:');
        $this->sqlite->busyTimeout(5000);
        $this->sqlite->exec('PRAGMA foreign_keys=OFF');
        $this->registerFunctions();
        $this->createSchema();
        $this->loadSheets();

        register_shutdown_function(function () {
            try { $this->flushDirty(); } catch (Throwable $e) { error_log('Google Sheets sync failed: '.$e->getMessage()); }
        });
    }

    private function fail(string $message): void {
        $this->error = $message;
        $this->errno = 1;
        $this->sqlite = new SQLite3(':memory:');
    }

    private function registerFunctions(): void {
        $this->sqlite->createFunction('CURDATE', fn() => date('Y-m-d'), 0);
        $this->sqlite->createFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        $this->sqlite->createFunction('YEAR', fn($value) => (int)date('Y', strtotime((string)$value)), 1);
        $this->sqlite->createFunction('MONTH', fn($value) => (int)date('m', strtotime((string)$value)), 1);
        $this->sqlite->createFunction('DAY', fn($value) => (int)date('d', strtotime((string)$value)), 1);
        $this->sqlite->createFunction('CONCAT', function(...$args) {
            $out = '';
            foreach ($args as $arg) { $out .= (string)$arg; }
            return $out;
        }, -1);
        $this->sqlite->createFunction('DATE_FORMAT', function($value, $format) {
            $ts = strtotime((string)$value);
            if (!$ts) return null;
            $map = ['%Y'=>'Y','%m'=>'m','%d'=>'d','%H'=>'H','%h'=>'h','%i'=>'i','%s'=>'s','%p'=>'A'];
            $php = strtr((string)$format, $map);
            return date($php, $ts);
        }, 2);
        $this->sqlite->createFunction('TIMESTAMPDIFF', function($unit, $start, $end) {
            $a = strtotime((string)$start); $b = strtotime((string)$end);
            if (!$a || !$b) return null;
            $seconds = $b - $a;
            return match(strtoupper((string)$unit)) {
                'SECOND' => $seconds,
                'MINUTE' => intdiv($seconds, 60),
                'HOUR' => intdiv($seconds, 3600),
                'DAY' => intdiv($seconds, 86400),
                default => $seconds
            };
        }, 3);
        $this->sqlite->createFunction('DATE_ADD', function($date, $modifier) {
            return date('Y-m-d', strtotime((string)$date.' + '.(int)$modifier.' day'));
        }, 2);
        $this->sqlite->createFunction('DATE_SUB', function($date, $modifier) {
            return date('Y-m-d', strtotime((string)$date.' - '.(int)$modifier.' day'));
        }, 2);
    }

    private function createSchema(): void {
        foreach (self::SCHEMA as $table=>$def) {
            $cols = [];
            foreach ($def['columns'] as $col) {
                $type = $def['types'][$col] ?? 'TEXT';
                if ($col === 'id') $cols[] = '"id" INTEGER PRIMARY KEY AUTOINCREMENT';
                else $cols[] = '"'.$col.'" '.$type;
            }
            if (!empty($def['pk']) && $def['pk'] !== ['id']) {
                $cols[] = 'PRIMARY KEY ('.implode(',', array_map(fn($x)=>'"'.$x.'"',$def['pk'])).')';
            }
            foreach (($def['unique'] ?? []) as $u) {
                $cols[] = 'UNIQUE ('.implode(',', array_map(fn($x)=>'"'.$x.'"',$u)).')';
            }
            $this->sqlite->exec('CREATE TABLE IF NOT EXISTS "'.$table.'" ('.implode(',', $cols).')');
        }
        $this->sqlite->exec("CREATE TRIGGER IF NOT EXISTS attendance_updated_at AFTER UPDATE ON attendance
            BEGIN UPDATE attendance SET updated_at = NOW() WHERE id = NEW.id; END;");
    }

    private function loadSheets(): void {
        if ($this->loaded || $this->error !== '') return;
        $this->ensureSheetTabs();
        foreach (self::SCHEMA as $table=>$def) {
            $rows = $this->readSheetTable($table);
            foreach ($rows as $row) {
                $cols=[]; $vals=[]; $marks=[];
                foreach ($def['columns'] as $col) {
                    if (!array_key_exists($col,$row)) continue;
                    $cols[]='"'.$col.'"'; $marks[]='?'; $vals[]=$this->normalizeCell($row[$col], $def['types'][$col] ?? 'TEXT');
                }
                if (!$cols) continue;
                $st=$this->sqlite->prepare('INSERT OR IGNORE INTO "'.$table.'" ('.implode(',',$cols).') VALUES ('.implode(',',$marks).')');
                foreach ($vals as $i=>$v) $st->bindValue($i+1,$v,$this->sqliteType($def['types'][$def['columns'][$i]] ?? 'TEXT'));
                @$st->execute();
            }
        }
        $this->seedDefaults();
        $this->loaded = true;
    }

    private function seedDefaults(): void {
        $count=(int)($this->sqlite->querySingle('SELECT COUNT(*) FROM staff') ?? 0);
        if ($count===0) {
            $this->sqlite->exec("INSERT INTO staff(staff_id,name,password,role,status,created_at) VALUES('STF-001','Administrator','$2y$12$3R2/WsLLy8JphNj6BD4id.lw.2.RI9HHlUi83RbQU2FrbUAZWj8H2','admin','active',NOW())");
            $this->dirty['staff']=true;
        }
        $settings=[
            'library_name'=>'AR LIBRARY','phone'=>'8349852152',
            'address'=>'Ward No. 15, Sarkari Hospital ke Samane, Baikunthpur, Rewa, Madhya Pradesh - 486441',
            'opening_time'=>'08:00','closing_time'=>'20:00','morning_end'=>'14:00','evening_start'=>'14:00',
            'total_seats'=>'79','total_lockers'=>'79','monthly_fee'=>'1100','currency'=>'INR',
            'receipt_footer'=>'Thank you for choosing AR Library.','attendance_latitude'=>'24.735323',
            'attendance_longitude'=>'81.409182','attendance_radius_meters'=>'100'
        ];
        foreach($settings as $k=>$v){
            $exists=(int)$this->sqlite->querySingle("SELECT COUNT(*) FROM library_settings WHERE setting_key=".$this->sqlite->escapeString($k));
            if(!$exists){$st=$this->sqlite->prepare("INSERT INTO library_settings(setting_key,setting_value) VALUES(?,?)");$st->bindValue(1,$k);$st->bindValue(2,$v);$st->execute();$this->dirty['library_settings']=true;}
        }
        $fees=['half_day_one'=>600,'half_day_three'=>1710,'half_day_six'=>3240,'half_reserved_one'=>800,'half_reserved_three'=>2280,'half_reserved_six'=>4320,'full_day_one'=>1100,'full_day_three'=>3135,'full_day_six'=>5940,'full_reserved_one'=>1300,'full_reserved_three'=>3705,'full_reserved_six'=>7020];
        foreach($fees as $k=>$v){
            $exists=(int)$this->sqlite->querySingle("SELECT COUNT(*) FROM fee_settings WHERE setting_key=".$this->sqlite->escapeString($k));
            if(!$exists){$st=$this->sqlite->prepare("INSERT INTO fee_settings(setting_key,setting_value) VALUES(?,?)");$st->bindValue(1,$k);$st->bindValue(2,$v);$st->execute();$this->dirty['fee_settings']=true;}
        }
    }

    private function ensureSheetTabs(): void {
        $existing=$this->apiGet('/spreadsheets/'.rawurlencode($this->sheetId).'?fields=sheets.properties.title');
        $titles=[];
        foreach (($existing['sheets'] ?? []) as $s) $titles[]=$s['properties']['title'] ?? '';
        $requests=[];
        foreach (array_keys(self::SCHEMA) as $table) {
            if (!in_array($table,$titles,true)) $requests[]=['addSheet'=>['properties'=>['title'=>$table]]];
        }
        if ($requests) $this->apiPost('/spreadsheets/'.rawurlencode($this->sheetId).':batchUpdate',['requests'=>$requests]);
        foreach (array_keys(self::SCHEMA) as $table) {
            $range=$this->quoteRange($table).'!1:1';
            $values=$this->apiGet('/spreadsheets/'.rawurlencode($this->sheetId).'/values/'.$range.'?majorDimension=ROWS');
            if (empty($values['values'][0])) {
                $this->apiPut('/spreadsheets/'.rawurlencode($this->sheetId).'/values/'.$range.'?valueInputOption=RAW',['range'=>$table.'!1:1','majorDimension'=>'ROWS','values'=>[self::SCHEMA[$table]['columns']]]);
            }
        }
    }

    private function readSheetTable(string $table): array {
        $range=$this->quoteRange($table).'!A:ZZ';
        $data=$this->apiGet('/spreadsheets/'.rawurlencode($this->sheetId).'/values/'.$range.'?majorDimension=ROWS');
        $values=$data['values'] ?? [];
        if (!$values) return [];
        $headers=$values[0];
        $rows=[];
        foreach (array_slice($values,1) as $r) {
            $row=[];
            foreach ($headers as $i=>$h) if ($h!=='') $row[$h]=$r[$i] ?? null;
            if (array_filter($row,fn($v)=>$v!==null && $v!=='') !== []) $rows[]=$row;
        }
        return $rows;
    }

    private function flushDirty(): void {
        if ($this->error !== '' || !$this->loaded || !$this->dirty) return;
        foreach (array_keys($this->dirty) as $table) $this->writeSheetTable($table);
        $this->dirty=[];
    }

    private function writeSheetTable(string $table): void {
        $def=self::SCHEMA[$table];
        $res=$this->sqlite->query('SELECT * FROM "'.$table.'"');
        $values=[$def['columns']];
        while($row=$res->fetchArray(SQLITE3_ASSOC)){
            $line=[];
            foreach($def['columns'] as $c){
                $v=$row[$c] ?? null;
                $line[]=$v===null?'':$v;
            }
            $values[]=$line;
        }
        $range=$this->quoteRange($table).'!A1:'.$this->colLetter(count($def['columns'])).(count($values));
        $this->apiPost('/spreadsheets/'.rawurlencode($this->sheetId).'/values/'.$range.':clear',[]);
        $this->apiPut('/spreadsheets/'.rawurlencode($this->sheetId).'/values/'.$range.'?valueInputOption=RAW',['range'=>$table.'!A1','majorDimension'=>'ROWS','values'=>$values]);
    }

    private function colLetter(int $n): string {
        $s=''; while($n>0){$n--; $s=chr(65+$n%26).$s; $n=intdiv($n,26);} return $s ?: 'A';
    }
    private function quoteRange(string $s): string { return "'" . str_replace("'","''",$s) . "'"; }

    public function prepare(string $sql) {
        if ($this->error !== '') return false;
        return new ARSheetsStatement($this,$sql);
    }

    public function query(string $sql) {
        $this->affected_rows=0; $this->error=''; $this->errno=0;
        return $this->executeSql($sql, [], [], null);
    }

    public function real_escape_string($value): string { return SQLite3::escapeString((string)$value); }
    public function set_charset($charset): bool { return true; }
    public function begin_transaction(): bool { $this->inTransaction=true; return $this->sqlite->exec('BEGIN'); }
    public function commit(): bool { $ok=$this->sqlite->exec('COMMIT'); $this->inTransaction=false; return $ok; }
    public function rollback(): bool { $ok=$this->sqlite->exec('ROLLBACK'); $this->inTransaction=false; return $ok; }
    public function close(): void { $this->flushDirty(); $this->sqlite->close(); }
    public function more_results(): bool { return false; }
    public function next_result(): bool { return false; }
    public function multi_query(string $sql): bool {
        $ok=true;
        foreach ($this->splitSql($sql) as $part) { if(!$this->query($part)){$ok=false;break;} }
        return $ok;
    }

    public function executePrepared(string $sql, array $refs, array $types, ARSheetsStatement $stmt): bool {
        $vals=[];
        foreach($refs as $i=>$v){
            $type=$types[$i] ?? 's';
            if($type==='i') $vals[]=(int)$v;
            elseif($type==='d') $vals[]=(float)$v;
            elseif($type==='b') $vals[]=(int)$v;
            else $vals[]=$v;
        }
        $converted=$this->rewriteSql($sql);
        $q=$this->sqlite->prepare($converted);
        if(!$q){$stmt->error=$this->sqlite->lastErrorMsg();$stmt->errno=1;return false;}
        foreach($vals as $i=>$v) $q->bindValue($i+1,$v,$this->sqliteTypeFromValue($v));
        $res=@$q->execute();
        if($res===false){$stmt->error=$this->sqlite->lastErrorMsg();$stmt->errno=1;return false;}
        if(preg_match('/^\s*(SELECT|PRAGMA|WITH)\b/i',$converted)){
            $rows=[]; while($r=$res->fetchArray(SQLITE3_ASSOC))$rows[]=$r;
            $result=new ARSheetsResult($rows);
            $stmt->setResult($result);
            $stmt->affected_rows=count($rows);
        }else{
            $stmt->affected_rows=$this->sqlite->changes();
            $this->affected_rows=$stmt->affected_rows;
            $this->insert_id=(int)$this->sqlite->lastInsertRowID();
            $this->markDirtyFromSql($converted);
        }
        return true;
    }

    private function executeSql(string $sql, array $vals, array $types, ?ARSheetsStatement $stmt) {
        $s=trim($this->rewriteSql($sql));
        if($s==='') return true;
        if(preg_match('/^\s*(CREATE|ALTER|DROP|SET)\b/i',$s)) {
            // DDL is controlled by this adapter. MySQL bootstrap/permission DDL
            // is intentionally ignored because Sheets already has fixed tabs.
            if(preg_match('/^\s*DROP\s+TABLE/i',$s)) {
                if(preg_match('/DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+`?(\w+)`?/i',$s,$m) && isset(self::SCHEMA[$m[1]])){
                    $this->sqlite->exec('DELETE FROM "'.$m[1].'"'); $this->dirty[$m[1]]=true;
                }
            }
            return true;
        }
        if(preg_match('/^\s*SHOW\s+TABLES/i',$s)){
            $rows=[]; foreach(array_keys(self::SCHEMA) as $t)$rows[]=['Tables_in_ar_library'=>$t];
            return new ARSheetsResult($rows,['Tables_in_ar_library']);
        }
        if(preg_match('/^\s*SHOW\s+COLUMNS\s+FROM\s+`?(\w+)`?(?:\s+LIKE\s+\'?([^\']+)\'?)?/i',$s,$m)){
            $table=$m[1]??''; $like=$m[2]??null; $def=self::SCHEMA[$table]??null;
            if(!$def) return new ARSheetsResult([],['Field','Type','Null','Key','Default','Extra']);
            $rows=[];
            foreach($def['columns'] as $col){
                if($like!==null && $col!==$like) continue;
                $type=$def['types'][$col]??'TEXT';
                $rows[]=['Field'=>$col,'Type'=>strtolower($type==='INTEGER'?'int':($type==='REAL'?'decimal':'text')),'Null'=>'YES','Key'=>in_array($col,$def['pk']??[],true)?'PRI':'','Default'=>null,'Extra'=>$col==='id'?'auto_increment':''];
            }
            return new ARSheetsResult($rows,['Field','Type','Null','Key','Default','Extra']);
        }
        if(preg_match('/^\s*SHOW\s+CREATE\s+TABLE\s+`?(\w+)`?/i',$s,$m)){
            $table=$m[1]??''; $create=$this->mysqlCreateStatement($table);
            return new ARSheetsResult([['Table'=>$table,'Create Table'=>$create]],['Table','Create Table']);
        }
        $q=$this->sqlite->query($s);
        if($q===false){$this->error=$this->sqlite->lastErrorMsg();$this->errno=1;return false;}
        if(preg_match('/^\s*(SELECT|PRAGMA|WITH)\b/i',$s)){
            $rows=[];while($r=$q->fetchArray(SQLITE3_ASSOC))$rows[]=$r;
            return new ARSheetsResult($rows);
        }
        $this->affected_rows=$this->sqlite->changes();
        $this->insert_id=(int)$this->sqlite->lastInsertRowID();
        $this->markDirtyFromSql($s);
        return true;
    }

    private function rewriteSql(string $sql): string {
        $s=trim($sql);
        $s=preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i','INSERT OR IGNORE INTO',$s);
        $s=preg_replace('/\s+FOR\s+UPDATE\b/i','',$s);
        $s=preg_replace('/TIMESTAMPDIFF\(\s*(SECOND|MINUTE|HOUR|DAY)\s*,/i', "TIMESTAMPDIFF('\\$1',", $s);
        // MySQL interval syntax -> SQLite datetime modifiers.
        $s=preg_replace_callback('/DATE_ADD\s*\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|HOUR|MINUTE|MONTH)\s*\)/i',
            fn($m)=>"datetime(CURDATE(), '+" . (int)$m[1] . " " . strtolower($m[2]) . "')",$s);
        $s=preg_replace_callback('/DATE_SUB\s*\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|HOUR|MINUTE|MONTH)\s*\)/i',
            fn($m)=>"datetime(CURDATE(), '-" . (int)$m[1] . " " . strtolower($m[2]) . "')",$s);
        $s=preg_replace_callback('/DATE_ADD\s*\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|HOUR|MINUTE|MONTH)\s*\)/i',
            fn($m)=>"datetime(NOW(), '+" . (int)$m[1] . " " . strtolower($m[2]) . "')",$s);
        $s=preg_replace_callback('/DATE_SUB\s*\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|HOUR|MINUTE|MONTH)\s*\)/i',
            fn($m)=>"datetime(NOW(), '-" . (int)$m[1] . " " . strtolower($m[2]) . "')",$s);
        $s=preg_replace_callback('/DATE_ADD\s*\(\s*DATE_FORMAT\(CURDATE\(\),\s*\'%Y-%m-01\'\)\s*,\s*INTERVAL\s+1\s+MONTH\s*\)/i',
            fn($m)=>"date(CURDATE(), '+1 month')",$s);

        // MySQL upsert forms used by settings.
        if(preg_match('/INSERT\s+INTO\s+([`\w]+)\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)\s*ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.+)$/is',$s,$m)){
            $table=trim($m[1],'`');
            if(in_array($table,['library_settings','fee_settings'],true)){
                $key=$table==='library_settings'?'setting_key':'setting_key';
                $update=preg_replace('/\bVALUES\s*\(\s*setting_value\s*\)/i','excluded.setting_value',$m[4]);
                $s='INSERT INTO '.$table.'('.$m[2].') VALUES('.$m[3].') ON CONFLICT('.$key.') DO UPDATE SET '.$update;
            }
        }
        return $s;
    }

    private function markDirtyFromSql(string $sql): void {
        if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i',$sql,$m)){
            if(preg_match('/\b(?:INTO|UPDATE|FROM|TABLE)\s+[`"]?([A-Za-z0-9_]+)/i',$sql,$tm)){
                $table=$tm[1]; if(isset(self::SCHEMA[$table])) $this->dirty[$table]=true;
            }
        }
    }

    private function sqliteType(string $type): int { return $type==='INTEGER'?SQLITE3_INTEGER:($type==='REAL'?SQLITE3_FLOAT:SQLITE3_TEXT); }
    private function sqliteTypeFromValue($v): int { return is_int($v)?SQLITE3_INTEGER:(is_float($v)?SQLITE3_FLOAT:SQLITE3_TEXT); }
    private function normalizeCell($v,string $type) {
        if($v===null || $v==='') return null;
        if($type==='INTEGER') return (int)$v;
        if($type==='REAL') return (float)$v;
        return (string)$v;
    }

    private function splitSql(string $sql): array {
        $parts=preg_split('/;\s*(?=(?:DROP|CREATE|INSERT|UPDATE|DELETE|SET)\b)/i',$sql);
        return array_values(array_filter(array_map('trim',$parts)));
    }

    private function mysqlCreateStatement(string $table): string {
        $def=self::SCHEMA[$table]??null;
        if(!$def)return '';
        $cols=[];
        foreach($def['columns'] as $c){$t=$def['types'][$c]??'TEXT';$mysql=$t==='INTEGER'?'INT':($t==='REAL'?'DECIMAL(12,2)':'TEXT');$cols[]='`'.$c.'` '.$mysql.($c==='id'?' AUTO_INCREMENT':'');}
        return 'CREATE TABLE `'.$table.'` ('.implode(',',$cols).')';
    }

    private function googleToken(): string {
        if($this->accessToken!=='')return $this->accessToken;
        $now=time();
        $header=$this->b64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));
        $claim=$this->b64url(json_encode(['iss'=>$this->serviceAccount['client_email'],'scope'=>'https://www.googleapis.com/auth/spreadsheets','aud'=>'https://oauth2.googleapis.com/token','exp'=>$now+3600,'iat'=>$now]));
        $input=$header.'.'.$claim;
        $signature='';
        if(!openssl_sign($input,$signature,$this->serviceAccount['private_key'],'sha256WithRSAEncryption')) throw new Exception('Could not sign Google service-account JWT.');
        $jwt=$input.'.'.$this->b64url($signature);
        $ch=curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt])]);
        $raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
        if($raw===false||$code>=300)throw new Exception('Google OAuth failed (HTTP '.$code.'): '.$err.' '.$raw);
        $data=json_decode($raw,true); if(empty($data['access_token']))throw new Exception('Google OAuth token missing.');
        return $this->accessToken=$data['access_token'];
    }

    private function b64url($data): string { return rtrim(strtr(base64_encode($data),'+/','-_'),'='); }

    private function apiRequest(string $method,string $path,?array $body=null): array {
        $url='https://sheets.googleapis.com/v4'.$path;
        $ch=curl_init($url);
        $headers=['Authorization: Bearer '.$this->googleToken(),'Content-Type: application/json'];
        $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method];
        if($body!==null){$opts[CURLOPT_POSTFIELDS]=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        curl_setopt_array($ch,$opts);
        $raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
        if($raw===false||$code>=300)throw new Exception('Google Sheets API error HTTP '.$code.': '.$err.' '.$raw);
        return $raw===''?[]:(json_decode($raw,true)?:[]);
    }
    private function apiGet($path): array { return $this->apiRequest('GET',$path); }
    private function apiPost($path,$body): array { return $this->apiRequest('POST',$path,$body); }
    private function apiPut($path,$body): array { return $this->apiRequest('PUT',$path,$body); }
}

$conn = new ARSheetsDB();
if ($conn->error !== '') {
    $acceptsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false || basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'login.php';
    http_response_code(500);
    if ($acceptsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>$conn->error],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    die($conn->error);
}
