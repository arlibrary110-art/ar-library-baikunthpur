<?php
/**
 * AR Library - MongoDB database adapter.
 *
 * The application keeps its existing mysqli-like prepare/query API so the
 * existing PHP pages and APIs do not need to be rewritten into Mongo queries.
 * Each request loads the configured MongoDB collections into an in-memory
 * SQLite database, runs the existing SQL, and synchronizes changed tables back
 * to MongoDB at request shutdown.
 *
 * Required Render environment variables:
 *   MONGODB_URI
 *   MONGODB_DATABASE (optional; defaults to ar_library)
 */
@date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/vendor/autoload.php';

if (!extension_loaded('mongodb')) {
    http_response_code(500);
    die('MongoDB PHP extension is required. Please redeploy the MongoDB Dockerfile.');
}

function ar_env($key, $default = '') {
    $v = getenv($key);
    return ($v === false) ? $default : trim((string)$v);
}

final class ARMongoResult {
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

final class ARMongoStatement {
    private ARMongoDB $db;
    private string $sql;
    private array $params = [];
    private array $types = [];
    private ?ARMongoResult $result = null;
    public int $affected_rows = 0;
    public string $error = '';
    public int $errno = 0;

    public function __construct(ARMongoDB $db, string $sql) {
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
        return $this->db->executePrepared($this->sql, $this->params, $this->types, $this);
    }
    public function get_result(): ?ARMongoResult { return $this->result; }
    public function close(): void {}
    public function setResult(?ARMongoResult $result): void { $this->result = $result; }
}

final class ARMongoDB {
    private SQLite3 $sqlite;
    private string $databaseName;
    private $client;
    private $database;
    private array $tables = [];
    private array $dirty = [];
    private bool $loaded = false;
    private array $loadedTables = [];
    private bool $inTransaction = false;
    private ?string $syncLockOwner = null;
    private const SYNC_LOCK_ID = 'ar_library_global_sync';
    private const SYNC_LOCK_LEASE_MS = 300000; // five minutes; renewed immediately before every sync write
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
            'columns'=>['id','member_id','name','phone','email','course','membership_plan','shift','joining_date','date_of_birth','validity_date','address','status','created_at'],
            'types'=>['id'=>'INTEGER','member_id'=>'TEXT','name'=>'TEXT','phone'=>'TEXT','email'=>'TEXT','course'=>'TEXT','membership_plan'=>'TEXT','shift'=>'TEXT','joining_date'=>'TEXT','date_of_birth'=>'TEXT','validity_date'=>'TEXT','address'=>'TEXT','status'=>'TEXT','created_at'=>'TEXT'],
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
        $uri = ar_env('MONGODB_URI');
        $this->databaseName = ar_env('MONGODB_DATABASE', 'ar_library');
        if ($uri === '') {
            $this->fail('MongoDB is not configured. Set MONGODB_URI in Render Environment.');
            return;
        }

        try {
            $this->client = new \MongoDB\Client($uri, [], [
                'serverSelectionTimeoutMS' => 10000,
                'connectTimeoutMS' => 10000,
            ]);
            $this->database = $this->client->selectDatabase($this->databaseName);
            // A filesystem flock is local to one container and cannot serialize
            // requests running on separate Render instances. Use one atomic MongoDB
            // lease document so every instance shares the same load/modify/flush lock.
            $this->acquireDistributedSyncLock();
            $this->sqlite = new SQLite3(':memory:');
            $this->sqlite->busyTimeout(5000);
            $this->sqlite->exec('PRAGMA foreign_keys=OFF');
            // Keep temporary sort/grouping tables in memory to reduce temporary
            // storage overhead for this already in-memory request database.
            $this->sqlite->exec('PRAGMA temp_store=MEMORY');
            $this->registerFunctions();
            $this->createSchema();
            $this->ensureMongoIndexes();
            // Load only the small set needed for bootstrap. Other tables are loaded lazily per SQL request.
            $this->loadMongoTables(['staff','library_settings','fee_settings']);
            $this->seedDefaults();
            register_shutdown_function(function () {
                $previousHandler = set_error_handler(function ($severity, $message, $file, $line) {
                    throw new ErrorException($message, 0, $severity, $file, $line);
                });
                try {
                    $this->flushDirty();
                } catch (Throwable $e) {
                    error_log('MongoDB sync failed: ' . $e->getMessage());
                } finally {
                    if ($previousHandler !== null) {
                        restore_error_handler();
                    } else {
                        restore_error_handler();
                    }
                    $this->releaseDistributedSyncLock();
                }
            });
        } catch (Throwable $e) {
            $this->fail('MongoDB connection failed: '.$e->getMessage());
        }
    }

    private function acquireDistributedSyncLock(): void {
        $locks = $this->database->selectCollection('_ar_library_locks');
        $this->syncLockOwner = bin2hex(random_bytes(16));
        $epoch = new \MongoDB\BSON\UTCDateTime(0);
        try {
            $locks->insertOne(['_id' => self::SYNC_LOCK_ID, 'owner' => '', 'expiresAt' => $epoch]);
        } catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
            // The shared lock record already exists; that is the normal case.
            if (stripos($e->getMessage(), 'duplicate key') === false && stripos($e->getMessage(), 'E11000') === false) throw $e;
        }
        $deadline = microtime(true) + 45.0;
        do {
            $nowDate = new \MongoDB\BSON\UTCDateTime((int) floor(microtime(true) * 1000));
            $expires = new \MongoDB\BSON\UTCDateTime((int) floor(microtime(true) * 1000) + self::SYNC_LOCK_LEASE_MS);
            $doc = $locks->findOneAndUpdate(
                ['_id' => self::SYNC_LOCK_ID, '$or' => [
                    ['owner' => $this->syncLockOwner],
                    ['expiresAt' => ['$lte' => $nowDate]],
                    ['expiresAt' => ['$exists' => false]],
                ]],
                ['$set' => ['owner' => $this->syncLockOwner, 'expiresAt' => $expires, 'acquiredAt' => $nowDate]],
                ['returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
            if ($doc && ($doc['owner'] ?? null) === $this->syncLockOwner) return;
            usleep(200000);
        } while (microtime(true) < $deadline);
        $this->syncLockOwner = null;
        throw new RuntimeException('Could not acquire shared database synchronization lock within 45 seconds. Please retry.');
    }

    private function renewDistributedSyncLock(): void {
        if ($this->syncLockOwner === null) throw new RuntimeException('Database synchronization lock is not held.');
        $now = new \MongoDB\BSON\UTCDateTime((int) floor(microtime(true) * 1000));
        $expires = new \MongoDB\BSON\UTCDateTime((int) floor(microtime(true) * 1000) + self::SYNC_LOCK_LEASE_MS);
        $result = $this->database->selectCollection('_ar_library_locks')->updateOne(
            ['_id' => self::SYNC_LOCK_ID, 'owner' => $this->syncLockOwner, 'expiresAt' => ['$gt' => $now]],
            ['$set' => ['expiresAt' => $expires, 'renewedAt' => $now]]
        );
        if ($result->getMatchedCount() !== 1) {
            throw new RuntimeException('Database synchronization lock expired or was lost; refusing to overwrite MongoDB data.');
        }
    }

    private function releaseDistributedSyncLock(): void {
        if ($this->syncLockOwner === null || $this->database === null) return;
        try {
            $this->database->selectCollection('_ar_library_locks')->updateOne(
                ['_id' => self::SYNC_LOCK_ID, 'owner' => $this->syncLockOwner],
                ['$set' => ['owner' => '', 'expiresAt' => new \MongoDB\BSON\UTCDateTime(0)]]
            );
        } catch (Throwable $e) {
            error_log('Could not release MongoDB synchronization lock: ' . $e->getMessage());
        } finally {
            $this->syncLockOwner = null;
        }
    }

    private function fail(string $message): void {
        $this->releaseDistributedSyncLock();
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
            foreach ($args as $arg) $out .= (string)$arg;
            return $out;
        }, -1);
        $this->sqlite->createFunction('DATE_FORMAT', function($value, $format) {
            $ts = strtotime((string)$value);
            if (!$ts) return null;
            $map = ['%Y'=>'Y','%m'=>'m','%d'=>'d','%H'=>'H','%h'=>'h','%i'=>'i','%s'=>'s','%p'=>'A'];
            return date(strtr((string)$format, $map), $ts);
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
        $this->sqlite->createFunction('DATE_ADD', fn($date,$modifier) => date('Y-m-d', strtotime((string)$date.' + '.(int)$modifier.' day')), 2);
        $this->sqlite->createFunction('DATE_SUB', fn($date,$modifier) => date('Y-m-d', strtotime((string)$date.' - '.(int)$modifier.' day')), 2);
    }

    private function createSchema(): void {
        foreach (self::SCHEMA as $table=>$def) {
            $cols=[];
            foreach($def['columns'] as $col){
                $type=$def['types'][$col]??'TEXT';
                if($col==='id') $cols[]='"id" INTEGER PRIMARY KEY AUTOINCREMENT';
                else $cols[]='"'.$col.'" '.$type;
            }
            if(!empty($def['pk']) && $def['pk']!==['id']) $cols[]='PRIMARY KEY ('.implode(',',array_map(fn($x)=>'"'.$x.'"',$def['pk'])).')';
            foreach(($def['unique']??[]) as $u) $cols[]='UNIQUE ('.implode(',',array_map(fn($x)=>'"'.$x.'"',$u)).')';
            $this->sqlite->exec('CREATE TABLE IF NOT EXISTS "'.$table.'" ('.implode(',',$cols).')');
        }
        $indexes = [
            'members' => [
                ['member_id'], ['phone'], ['status'], ['validity_date'], ['joining_date']
            ],
            'member_fees' => [
                ['member_id'], ['status'], ['payment_date'], ['due_date'], ['receipt_no']
            ],
            'payments' => [
                ['member_id'], ['member_code'], ['payment_date'], ['receipt_no']
            ],
            'member_seats' => [
                ['member_id'], ['status'], ['end_date'], ['seat_no']
            ],
            'attendance' => [
                ['member_id'], ['attendance_date'], ['status']
            ],
            'enquiries' => [
                ['status'], ['created_at']
            ],
            'expenses' => [
                ['expense_date'], ['category']
            ],
            'activity_logs' => [
                ['staff_id'], ['created_at'], ['action']
            ],
            'staff_permissions' => [
                ['staff_id'], ['permission_key']
            ],
            'member_messages' => [
                ['created_at'], ['staff_id']
            ],
        ];
        foreach ($indexes as $table => $colsList) {
            foreach ($colsList as $cols) {
                $safe = implode('_', $cols);
                $name = 'idx_' . $table . '_' . $safe;
                $quoted = implode(',', array_map(fn($c) => '"' . $c . '"', $cols));
                $this->sqlite->exec('CREATE INDEX IF NOT EXISTS "' . $name . '" ON "' . $table . '" (' . $quoted . ')');
            }
        }
        $this->sqlite->exec("CREATE TRIGGER IF NOT EXISTS attendance_updated_at AFTER UPDATE ON attendance BEGIN UPDATE attendance SET updated_at = NOW() WHERE id = NEW.id; END;");
    }

    private function ensureMongoIndexes(): void {
        // Keep index setup idempotent but avoid reissuing every createIndex call
        // on every PHP request. A versioned marker lets new index definitions run
        // once per container after deployment; MongoDB itself remains authoritative.
        $marker = '/tmp/ar_library_mongo_indexes_ready_v3';
        if (is_file($marker)) return;
        $indexes = [
            'members' => [['member_id'=>1],['phone'=>1],['status'=>1],['validity_date'=>1],['joining_date'=>1]],
            'member_fees' => [['member_id'=>1],['status'=>1],['payment_date'=>1],['due_date'=>1],['receipt_no'=>1]],
            'payments' => [['member_id'=>1],['member_code'=>1],['payment_date'=>1],['receipt_no'=>1]],
            'member_seats' => [['member_id'=>1],['status'=>1],['end_date'=>1],['seat_no'=>1]],
            'attendance' => [['member_id'=>1],['attendance_date'=>1],['status'=>1]],
            'enquiries' => [['status'=>1],['created_at'=>1]],
            'expenses' => [['expense_date'=>1],['category'=>1]],
            'activity_logs' => [['staff_id'=>1],['created_at'=>1],['action'=>1]],
            'staff_permissions' => [['staff_id'=>1],['permission_key'=>1]],
            'member_messages' => [['created_at'=>1],['staff_id'=>1]],
        ];
        foreach (self::SCHEMA as $table=>$def) {
            $collection=$this->database->selectCollection($table);
            $uniquePatterns = [];
            foreach (($def['unique'] ?? []) as $fields) {
                $keys=[]; foreach($fields as $field) $keys[$field]=1;
                $collection->createIndex($keys, ['unique'=>true]);
                $uniquePatterns[implode(',', $fields)] = true;
            }
            // Enforce composite primary keys (for example staff_permissions) in
            // MongoDB too, not only in the per-request SQLite compatibility DB.
            $primary = $def['pk'] ?? [];
            if ($primary && $primary !== ['id'] && !isset($uniquePatterns[implode(',', $primary)])) {
                $keys=[]; foreach($primary as $field) $keys[$field]=1;
                $collection->createIndex($keys, ['unique'=>true]);
            }
            foreach (($indexes[$table] ?? []) as $keys) {
                // Do not request a second index with the same key pattern as a
                // schema-enforced unique index; MongoDB rejects same-name indexes
                // when the options differ (for example member_id_1 unique vs non-unique).
                $duplicatesUnique = false;
                foreach (($def['unique'] ?? []) as $uniqueFields) {
                    $uniqueKeys = [];
                    foreach ($uniqueFields as $field) $uniqueKeys[$field] = 1;
                    if ($uniqueKeys === $keys) {
                        $duplicatesUnique = true;
                        break;
                    }
                }
                if (!$duplicatesUnique) $collection->createIndex($keys);
            }
        }
        @file_put_contents($marker, (string)time(), LOCK_EX);
    }

    private function ensureTablesForSql(string $sql): void {
        if ($this->error !== '') return;
        $needed = [];
        foreach (array_keys(self::SCHEMA) as $table) {
            if (preg_match('/(?<![A-Za-z0-9_])`?' . preg_quote($table, '/') . '`?(?![A-Za-z0-9_])/i', $sql)) {
                $needed[] = $table;
            }
        }
        if ($needed) $this->loadMongoTables($needed);
    }

    private function loadMongoTables(array $tables): void {
        if ($this->error !== '') return;
        foreach (array_values(array_unique($tables)) as $table) {
            if (!isset(self::SCHEMA[$table]) || isset($this->loadedTables[$table])) continue;
            $def = self::SCHEMA[$table];
            $collection = $this->database->selectCollection($table);
            // Import only fields understood by the SQLite compatibility schema.
            // A larger cursor batch reduces MongoDB round-trips when a table is
            // loaded in full, while projection avoids transferring unrelated fields.
            $projection = array_fill_keys($def['columns'], 1);
            $cursor = $collection->find([], [
                'sort' => ['id' => 1],
                'batchSize' => 500,
                'projection' => $projection,
            ]);
            // Batch SQLite imports in a transaction to avoid an implicit disk-style
            // commit for every row. Reuse an application transaction when one exists.
            $ownsTransaction = !$this->inTransaction;
            if ($ownsTransaction && !$this->sqlite->exec('BEGIN')) {
                throw new RuntimeException('Could not start SQLite import transaction for '.$table);
            }
            try {
                // Reuse prepared INSERT statements for rows with the same available columns.
                // This avoids preparing a new SQLite statement for every MongoDB document.
                $insertStatements = [];
                foreach ($cursor as $doc) {
                    $row = $doc instanceof \MongoDB\Model\BSONDocument ? $doc->getArrayCopy() : (array)$doc;
                    unset($row['_id']);
                    $cols=[]; $marks=[]; $vals=[]; $selectedColumns=[];
                    foreach ($def['columns'] as $col) {
                        if (!array_key_exists($col, $row)) continue;
                        $cols[]='"'.$col.'"'; $marks[]='?';
                        $selectedColumns[]=$col;
                        $vals[]=$this->normalizeMongoValue($row[$col], $def['types'][$col]??'TEXT');
                    }
                    if (!$cols) continue;
                    $statementKey = implode("\0", $selectedColumns);
                    if (!isset($insertStatements[$statementKey])) {
                        $insertStatements[$statementKey] = $this->sqlite->prepare('INSERT OR IGNORE INTO "'.$table.'" ('.implode(',',$cols).') VALUES ('.implode(',',$marks).')');
                    }
                    $st = $insertStatements[$statementKey];
                    foreach ($vals as $i=>$v) {
                        $selectedCol=$selectedColumns[$i];
                        $st->bindValue($i+1,$v,$this->sqliteType($def['types'][$selectedCol]??'TEXT'));
                    }
                    $result = @$st->execute();
                    if ($result instanceof SQLite3Result) $result->finalize();
                    @$st->reset();
                }
                foreach ($insertStatements as $st) { @$st->close(); }
                if ($ownsTransaction && !$this->sqlite->exec('COMMIT')) {
                    throw new RuntimeException('Could not commit SQLite import transaction for '.$table);
                }
            } catch (Throwable $e) {
                if ($ownsTransaction) @$this->sqlite->exec('ROLLBACK');
                throw $e;
            }
            $this->loadedTables[$table] = true;
        }
        $this->loaded = count($this->loadedTables) === count(self::SCHEMA);
    }

    private function normalizeMongoValue($v,string $type) {
        if($v===null) return null;
        if($v instanceof \MongoDB\BSON\Int64 || $v instanceof \MongoDB\BSON\Int32) $v=(int)$v;
        elseif($v instanceof \MongoDB\BSON\Decimal128) $v=(float)(string)$v;
        if($type==='INTEGER') return (int)$v;
        if($type==='REAL') return (float)$v;
        return (string)$v;
    }

    private function seedDefaults(): void {
        $count=(int)($this->sqlite->querySingle('SELECT COUNT(*) FROM staff')??0);
        if($count===0){
            $this->sqlite->exec("INSERT INTO staff(staff_id,name,password,role,status,created_at) VALUES('STF-001','Administrator','$2y$12$3R2/WsLLy8JphNj6BD4id.lw.2.RI9HHlUi83RbQU2FrbUAZWj8H2','admin','active',NOW())");
            $this->dirty['staff']=true;
        }
        $settings=['library_name'=>'AR LIBRARY','phone'=>'8349852152','address'=>'Ward No. 15, Sarkari Hospital ke Samane, Baikunthpur, Rewa, Madhya Pradesh - 486441','opening_time'=>'08:00','closing_time'=>'20:00','morning_end'=>'14:00','evening_start'=>'14:00','total_seats'=>'79','total_lockers'=>'79','monthly_fee'=>'1100','currency'=>'INR','receipt_footer'=>'Thank you for choosing AR Library.','attendance_latitude'=>'24.735323','attendance_longitude'=>'81.409182','attendance_radius_meters'=>'100'];
        foreach($settings as $k=>$v){$exists=(int)$this->sqlite->querySingle("SELECT COUNT(*) FROM library_settings WHERE setting_key='".$this->sqlite->escapeString($k)."'");if(!$exists){$st=$this->sqlite->prepare('INSERT INTO library_settings(setting_key,setting_value) VALUES(?,?)');$st->bindValue(1,$k);$st->bindValue(2,$v);$st->execute();$this->dirty['library_settings']=true;}}
        $fees=['half_day_one'=>600,'half_day_three'=>1710,'half_day_six'=>3240,'half_reserved_one'=>800,'half_reserved_three'=>2280,'half_reserved_six'=>4320,'full_day_one'=>1100,'full_day_three'=>3135,'full_day_six'=>5940,'full_reserved_one'=>1300,'full_reserved_three'=>3705,'full_reserved_six'=>7020];
        foreach($fees as $k=>$v){$exists=(int)$this->sqlite->querySingle("SELECT COUNT(*) FROM fee_settings WHERE setting_key='".$this->sqlite->escapeString($k)."'");if(!$exists){$st=$this->sqlite->prepare('INSERT INTO fee_settings(setting_key,setting_value) VALUES(?,?)');$st->bindValue(1,$k);$st->bindValue(2,$v);$st->execute();$this->dirty['fee_settings']=true;}}
    }

    private function flushDirty(): void {
        if($this->error!==''||!$this->dirty) return;
        foreach(array_keys($this->dirty) as $table) $this->writeMongoTable($table);
        $this->dirty=[];
    }

    private function writeMongoTable(string $table): void {
        // Never perform the destructive collection replacement unless this request
        // still owns the shared MongoDB lock. This prevents stale snapshots from
        // overwriting writes made by another Render instance.
        $this->renewDistributedSyncLock();
        $def=self::SCHEMA[$table];
        $res=$this->sqlite->query('SELECT * FROM "'.$table.'"');
        $docs=[];
        while($row=$res->fetchArray(SQLITE3_ASSOC)){
            $doc=[];
            foreach($def['columns'] as $c){
                $v=$row[$c]??null;
                if($v!==null) $doc[$c]=$this->mongoValue($v,$def['types'][$c]??'TEXT');
            }
            $docs[]=$doc;
        }
        $collection=$this->database->selectCollection($table);
        $session=null;
        try {
            $session=$this->client->startSession();
            $session->startTransaction();
            $collection->deleteMany([], ['session'=>$session]);
            if($docs) $collection->insertMany($docs, ['session'=>$session,'ordered'=>true]);
            $session->commitTransaction();
        } catch(Throwable $e) {
            if($session){try{$session->abortTransaction();}catch(Throwable $ignored){}}
            throw $e;
        } finally {
            if($session) $session->endSession();
        }
    }

    private function mongoValue($v,string $type) {
        if($v===null) return null;
        if($type==='INTEGER') return (int)$v;
        if($type==='REAL') return (float)$v;
        return (string)$v;
    }

    public function prepare(string $sql) { if($this->error!=='') return false; return new ARMongoStatement($this,$sql); }
    public function query(string $sql) { $this->affected_rows=0;$this->error='';$this->errno=0;return $this->executeSql($sql,[],[],null); }
    public function real_escape_string($value): string { return SQLite3::escapeString((string)$value); }
    public function set_charset($charset): bool { return true; }
    public function begin_transaction(): bool { $this->inTransaction=true; return $this->sqlite->exec('BEGIN'); }
    public function commit(): bool { $ok=$this->sqlite->exec('COMMIT');$this->inTransaction=false;return $ok; }
    public function rollback(): bool { $ok=$this->sqlite->exec('ROLLBACK');$this->inTransaction=false;return $ok; }
    public function syncNow(): void {
        if ($this->error !== '') throw new RuntimeException($this->error);
        $this->flushDirty();
    }

    public function close(): void { try { $this->flushDirty(); } finally { $this->sqlite->close(); $this->releaseDistributedSyncLock(); } }
    public function more_results(): bool { return false; }
    public function next_result(): bool { return false; }
    public function multi_query(string $sql): bool { $ok=true;foreach($this->splitSql($sql) as $part){if(!$this->query($part)){$ok=false;break;}}return $ok; }

    public function executePrepared(string $sql,array $refs,array $types,ARMongoStatement $stmt): bool {
        $this->ensureTablesForSql($sql);
        $vals=[];foreach($refs as $i=>$v){$type=$types[$i]??'s';if($type==='i')$vals[]=(int)$v;elseif($type==='d')$vals[]=(float)$v;elseif($type==='b')$vals[]=(int)$v;else$vals[]=$v;}
        $converted=$this->rewriteSql($sql);
        $q=$this->sqlite->prepare($converted);if(!$q){$stmt->error=$this->sqlite->lastErrorMsg();$stmt->errno=1;return false;}
        foreach($vals as $i=>$v)$q->bindValue($i+1,$v,$this->sqliteTypeFromValue($v));
        $res=@$q->execute();if($res===false){$stmt->error=$this->sqlite->lastErrorMsg();$stmt->errno=1;return false;}
        if(preg_match('/^\s*(SELECT|PRAGMA|WITH)\b/i',$converted)){
            $rows=[];while($r=$res->fetchArray(SQLITE3_ASSOC))$rows[]=$r;$stmt->setResult(new ARMongoResult($rows));$stmt->affected_rows=count($rows);
        }else{$stmt->affected_rows=$this->sqlite->changes();$this->affected_rows=$stmt->affected_rows;$this->insert_id=(int)$this->sqlite->lastInsertRowID();$this->markDirtyFromSql($converted);}
        return true;
    }

    private function executeSql(string $sql,array $vals,array $types,?ARMongoStatement $stmt){
        $this->ensureTablesForSql($sql);
        $s=trim($this->rewriteSql($sql));if($s==='')return true;
        if(preg_match('/^\s*(CREATE|ALTER|DROP|SET)\b/i',$s)){
            if(preg_match('/^\s*DROP\s+TABLE/i',$s)&&preg_match('/DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+`?(\w+)`?/i',$s,$m)&&isset(self::SCHEMA[$m[1]])){$this->sqlite->exec('DELETE FROM "'.$m[1].'"');$this->dirty[$m[1]]=true;}
            return true;
        }
        if(preg_match('/^\s*SHOW\s+TABLES/i',$s)){ $rows=[];foreach(array_keys(self::SCHEMA) as $t)$rows[]=['Tables_in_ar_library'=>$t];return new ARMongoResult($rows,['Tables_in_ar_library']); }
        if(preg_match('/^\s*SHOW\s+COLUMNS\s+FROM\s+`?(\w+)`?(?:\s+LIKE\s+\'?([^\']+)\'?)?/i',$s,$m)){
            $table=$m[1]??'';$like=$m[2]??null;$def=self::SCHEMA[$table]??null;if(!$def)return new ARMongoResult([],['Field','Type','Null','Key','Default','Extra']);$rows=[];foreach($def['columns'] as $col){if($like!==null&&$col!==$like)continue;$type=$def['types'][$col]??'TEXT';$rows[]=['Field'=>$col,'Type'=>strtolower($type==='INTEGER'?'int':($type==='REAL'?'decimal':'text')),'Null'=>'YES','Key'=>in_array($col,$def['pk']??[],true)?'PRI':'','Default'=>null,'Extra'=>$col==='id'?'auto_increment':''];}return new ARMongoResult($rows,['Field','Type','Null','Key','Default','Extra']);
        }
        if(preg_match('/^\s*SHOW\s+CREATE\s+TABLE\s+`?(\w+)`?/i',$s,$m)){ $table=$m[1]??'';return new ARMongoResult([['Table'=>$table,'Create Table'=>$this->mysqlCreateStatement($table)]],['Table','Create Table']); }
        $q=$this->sqlite->query($s);if($q===false){$this->error=$this->sqlite->lastErrorMsg();$this->errno=1;return false;}
        if(preg_match('/^\s*(SELECT|PRAGMA|WITH)\b/i',$s)){ $rows=[];while($r=$q->fetchArray(SQLITE3_ASSOC))$rows[]=$r;return new ARMongoResult($rows); }
        $this->affected_rows=$this->sqlite->changes();$this->insert_id=(int)$this->sqlite->lastInsertRowID();$this->markDirtyFromSql($s);return true;
    }

    private function rewriteSql(string $sql): string {
        $s=trim($sql);
        $s=preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i','INSERT OR IGNORE INTO',$s);
        $s=preg_replace('/\s+FOR\s+UPDATE\b/i','',$s);
        $s=preg_replace('/TIMESTAMPDIFF\(\s*(SECOND|MINUTE|HOUR|DAY)\s*,/i',"TIMESTAMPDIFF('\\$1',",$s);
        $s=preg_replace_callback('/DATE_ADD\s*\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|HOUR|MINUTE|MONTH)\s*\)/i',fn($m)=>"datetime(CURDATE(), '+".(int)$m[1]." ".strtolower($m[2])."')",$s);
        $s=preg_replace_callback('/DATE_SUB\s*\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|HOUR|MINUTE|MONTH)\s*\)/i',fn($m)=>"datetime(CURDATE(), '-".(int)$m[1]." ".strtolower($m[2])."')",$s);
        $s=preg_replace_callback('/DATE_ADD\s*\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|HOUR|MINUTE|MONTH)\s*\)/i',fn($m)=>"datetime(NOW(), '+".(int)$m[1]." ".strtolower($m[2])."')",$s);
        $s=preg_replace_callback('/DATE_SUB\s*\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|HOUR|MINUTE|MONTH)\s*\)/i',fn($m)=>"datetime(NOW(), '-".(int)$m[1]." ".strtolower($m[2])."')",$s);
        $s=preg_replace_callback('/DATE_ADD\s*\(\s*DATE_FORMAT\(CURDATE\(\),\s*\'%Y-%m-01\'\)\s*,\s*INTERVAL\s+1\s+MONTH\s*\)/i',fn($m)=>"date(CURDATE(), '+1 month')",$s);
        if(preg_match('/INSERT\s+INTO\s+([`\w]+)\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)\s*ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.+)$/is',$s,$m)){
            $table=trim($m[1],'`');if(in_array($table,['library_settings','fee_settings'],true)){$update=preg_replace('/\bVALUES\s*\(\s*setting_value\s*\)/i','excluded.setting_value',$m[4]);$s='INSERT INTO '.$table.'('.$m[2].') VALUES('.$m[3].') ON CONFLICT(setting_key) DO UPDATE SET '.$update;}
        }
        return $s;
    }

    private function markDirtyFromSql(string $sql): void {
        if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i',$sql)&&preg_match('/\b(?:INTO|UPDATE|FROM|TABLE)\s+[`"]?([A-Za-z0-9_]+)/i',$sql,$tm)){$table=$tm[1];if(isset(self::SCHEMA[$table]))$this->dirty[$table]=true;}
    }
    private function sqliteType(string $type): int { return $type==='INTEGER'?SQLITE3_INTEGER:($type==='REAL'?SQLITE3_FLOAT:SQLITE3_TEXT); }
    private function sqliteTypeFromValue($v): int { return is_int($v)?SQLITE3_INTEGER:(is_float($v)?SQLITE3_FLOAT:SQLITE3_TEXT); }
    private function splitSql(string $sql): array { return array_values(array_filter(array_map('trim',preg_split('/;\s*(?=(?:DROP|CREATE|INSERT|UPDATE|DELETE|SET)\b)/i',$sql)))); }
    private function mysqlCreateStatement(string $table): string {
        $def = self::SCHEMA[$table] ?? null;
        if (!$def) return '';
        $parts = [];
        $primary = $def['pk'] ?? [];
        $unique = $def['unique'] ?? [];
        $indexes = [
            'members' => [['member_id'], ['phone'], ['status'], ['validity_date'], ['joining_date']],
            'member_fees' => [['member_id'], ['status'], ['payment_date'], ['due_date'], ['receipt_no']],
            'payments' => [['member_id'], ['member_code'], ['payment_date'], ['receipt_no']],
            'member_seats' => [['member_id'], ['status'], ['end_date'], ['seat_no']],
            'attendance' => [['member_id'], ['attendance_date'], ['status']],
            'enquiries' => [['status'], ['created_at']],
            'expenses' => [['expense_date'], ['category']],
            'activity_logs' => [['staff_id'], ['created_at'], ['action']],
            'staff_permissions' => [['staff_id'], ['permission_key']],
            'member_messages' => [['created_at'], ['staff_id']],
        ];
        $indexedColumns = array_fill_keys($primary, true);
        foreach ($unique as $fields) foreach ($fields as $field) $indexedColumns[$field] = true;
        foreach (($indexes[$table] ?? []) as $fields) foreach ($fields as $field) $indexedColumns[$field] = true;
        foreach ($def['columns'] as $column) {
            $type = $def['types'][$column] ?? 'TEXT';
            // MySQL cannot index an unbounded TEXT column without a prefix. Use
            // VARCHAR(191) for indexed text fields so SQL backups restore cleanly
            // and unique keys retain full-value semantics for normal library IDs.
            $mysqlType = $type === 'INTEGER' ? 'INT' : ($type === 'REAL' ? 'DECIMAL(12,2)' : (isset($indexedColumns[$column]) ? 'VARCHAR(191)' : 'TEXT'));
            $notNull = ($column === 'id' || in_array($column, $primary, true) || array_reduce($unique, fn($found, $fields) => $found || in_array($column, $fields, true), false)) ? ' NOT NULL' : '';
            $parts[] = '`' . $column . '` ' . $mysqlType . $notNull . ($column === 'id' ? ' AUTO_INCREMENT' : '');
        }
        if ($primary) $parts[] = 'PRIMARY KEY (' . implode(',', array_map(fn($c) => '`' . $c . '`', $primary)) . ')';
        foreach ($unique as $fields) {
            $name = 'uq_' . $table . '_' . implode('_', $fields);
            $parts[] = 'UNIQUE KEY `' . $name . '` (' . implode(',', array_map(fn($c) => '`' . $c . '`', $fields)) . ')';
        }
        foreach (($indexes[$table] ?? []) as $fields) {
            // Do not emit a redundant non-unique index for a primary/unique key.
            if ($fields === $primary || in_array($fields, $unique, true)) continue;
            $name = 'idx_' . $table . '_' . implode('_', $fields);
            $parts[] = 'KEY `' . $name . '` (' . implode(',', array_map(fn($c) => '`' . $c . '`', $fields)) . ')';
        }
        return 'CREATE TABLE `' . $table . '` (' . implode(', ', $parts) . ')';
    }
}

$conn = new ARMongoDB();
if ($conn->error !== '') {
    $acceptsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false || basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'login.php';
    http_response_code(500);
    if ($acceptsJson) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>$conn->error],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
    die($conn->error);
}
