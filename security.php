<?php
// Shared security helpers for AR Library Management System.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secure,
        'path' => '/'
    ]);
    session_start();
}


function private_storage_dir() {
    static $dir = null;
    if ($dir !== null) return $dir;

    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $base = $docRoot ? dirname($docRoot) : dirname(__DIR__, 2);
    $dir = $base . DIRECTORY_SEPARATOR . 'AR_Library_Private';

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function json_out($ok, $message = '', $extra = [], $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') return;
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        json_out(false, 'Security token missing or invalid. Please refresh the page and try again.', [], 403);
    }
}

function require_staff() {
    if (empty($_SESSION['staff_id'])) json_out(false, 'Please login first.', [], 401);
}
function require_admin() {
    require_staff();
    if (strtolower((string)($_SESSION['staff_role'] ?? '')) !== 'admin') {
        json_out(false, 'Admin permission required for this action.', [], 403);
    }
}
function verify_state_change() {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') return;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin !== '') {
        $o = parse_url($origin);
        if (!$o || (($o['host'] ?? '') !== preg_replace('/:\\d+$/', '', $host))) {
            json_out(false, 'Invalid request origin.', [], 403);
        }
        return;
    }
    if ($referer !== '') {
        $r = parse_url($referer);
        if (!$r || (($r['host'] ?? '') !== preg_replace('/:\\d+$/', '', $host))) {
            json_out(false, 'Invalid request source.', [], 403);
        }
    }
}
function app_log($action, $details='') {
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli) || empty($_SESSION['staff_id'])) return;
    $s = $conn->prepare('INSERT INTO activity_logs(staff_id,action,details) VALUES(?,?,?)');
    if ($s) { $sid=(string)$_SESSION['staff_id']; $s->bind_param('sss',$sid,$action,$details); $s->execute(); $s->close(); }
}


/* -------------------------------------------------------------------------
   Staff module permissions
   Admin can grant/revoke module access for each staff account. Admin users
   always have full access. Existing staff accounts keep the legacy access
   until an admin explicitly saves a custom permission set.
------------------------------------------------------------------------- */
function ensure_staff_permissions_table() {
    global $conn;
    static $done = false;
    if ($done || !isset($conn) || !($conn instanceof mysqli)) return;
    @$conn->query("CREATE TABLE IF NOT EXISTS staff_permissions (
        staff_id VARCHAR(50) NOT NULL,
        permission_key VARCHAR(60) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (staff_id, permission_key),
        INDEX idx_staff_perm(staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Distinguish a staff member with no custom permissions from one whose
    // custom permissions were intentionally cleared (Select All/Clear All).
    @$conn->query("ALTER TABLE staff ADD COLUMN permissions_configured TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    $done = true;
}

function staff_permission_catalog() {
    return [
        'dashboard' => 'Dashboard',
        'seats' => 'Seats & Lockers',
        'members' => 'Members',
        'fees' => 'Fees & Payments',
        'attendance' => 'Attendance',
        'attendance_qr' => 'Attendance QR',
        'enquiry' => 'Enquiry',
        'expenses' => 'Expenses',
        'reports' => 'Reports',
        'backup' => 'Backup & Restore (Admin Only)',
        'settings' => 'Library Settings (Admin Only)',
    ];
}

function legacy_staff_permissions() {
    return array_keys(staff_permission_catalog());
}

function get_staff_permissions($staffId = null) {
    global $conn;
    ensure_staff_permissions_table();
    $isCurrent = ($staffId === null);
    $staffId = (string)($staffId ?? ($_SESSION['staff_id'] ?? ''));
    if ($staffId === '') return [];
    $targetRole = '';
    if ($isCurrent) {
        $targetRole = strtolower((string)($_SESSION['staff_role'] ?? ''));
    } else {
        $rs=$conn->prepare('SELECT role FROM staff WHERE staff_id=? LIMIT 1');
        if($rs){$rs->bind_param('s',$staffId);$rs->execute();$targetRole=strtolower((string)($rs->get_result()->fetch_assoc()['role'] ?? ''));$rs->close();}
    }
    if ($targetRole === 'admin') return array_keys(staff_permission_catalog());
    $configured = false;
    $cs = $conn->prepare('SELECT permissions_configured FROM staff WHERE staff_id=? LIMIT 1');
    if ($cs) { $cs->bind_param('s',$staffId); $cs->execute(); $configured = ((int)($cs->get_result()->fetch_assoc()['permissions_configured'] ?? 0) === 1); $cs->close(); }
    $rows = [];
    $s = $conn->prepare('SELECT permission_key FROM staff_permissions WHERE staff_id=? ORDER BY permission_key');
    if ($s) {
        $s->bind_param('s', $staffId); $s->execute(); $r=$s->get_result();
        while ($x=$r->fetch_assoc()) $rows[]=(string)$x['permission_key'];
        $s->close();
    }
    // Backward compatibility: only staff accounts that have never had a
    // custom permission set keep legacy full module access. Once an admin
    // saves permissions, an empty set must remain empty.
    if ($configured) return array_values(array_intersect($rows, array_keys(staff_permission_catalog())));
    return legacy_staff_permissions();
}

function has_permission($permission, $staffId = null) {
    if (in_array((string)$permission, ['backup','settings'], true)) {
        return strtolower((string)($_SESSION['staff_role'] ?? '')) === 'admin';
    }
    if (strtolower((string)($_SESSION['staff_role'] ?? '')) === 'admin') return true;
    return in_array((string)$permission, get_staff_permissions($staffId), true);
}

function require_permission($permission) {
    require_staff();
    if (!has_permission($permission)) {
        json_out(false, 'Access denied. Admin has not granted this module to your staff account.', [], 403);
    }
}

function save_staff_permissions($staffId, $permissions) {
    global $conn;
    ensure_staff_permissions_table();
    $staffId = trim((string)$staffId);
    $allowed = array_keys(staff_permission_catalog());
    $permissions = is_array($permissions) ? array_values(array_intersect(array_map('strval',$permissions), $allowed)) : [];
    $conn->begin_transaction();
    try {
        $d=$conn->prepare('DELETE FROM staff_permissions WHERE staff_id=?');
        if (!$d) throw new Exception('Could not prepare permissions.');
        $d->bind_param('s',$staffId); $d->execute(); $d->close();
        if ($permissions) {
            $i=$conn->prepare('INSERT INTO staff_permissions(staff_id,permission_key) VALUES(?,?)');
            if (!$i) throw new Exception('Could not prepare permission insert.');
            foreach($permissions as $p){ $i->bind_param('ss',$staffId,$p); $i->execute(); }
            $i->close();
        }
        $u=$conn->prepare('UPDATE staff SET permissions_configured=1 WHERE staff_id=?');
        if (!$u) throw new Exception('Could not mark permissions as configured.');
        $u->bind_param('s',$staffId); $u->execute(); $u->close();
        $conn->commit();
        return true;
    } catch(Throwable $e) { $conn->rollback(); return false; }
}

function get_setting($key, $default='') {
    global $conn;
    $s=$conn->prepare('SELECT setting_value FROM library_settings WHERE setting_key=? LIMIT 1');
    if (!$s) return $default;
    $s->bind_param('s',$key); $s->execute(); $r=$s->get_result(); $v=$r->fetch_assoc()['setting_value'] ?? null; $s->close();
    return ($v === null || $v === '') ? $default : $v;
}
