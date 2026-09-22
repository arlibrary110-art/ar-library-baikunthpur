<?php
// Backward-compatible permanent Attendance QR API.
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';

require_permission('attendance_qr');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$settings=[];
$res=$conn->query("SELECT setting_key, setting_value FROM library_settings WHERE setting_key IN ('library_name','attendance_latitude','attendance_longitude','attendance_radius_meters')");
if($res) while($row=$res->fetch_assoc()) $settings[$row['setting_key']]=$row['setting_value'];

$lat=(float)($settings['attendance_latitude'] ?? 24.735323);
$lng=(float)($settings['attendance_longitude'] ?? 81.409182);
$radius=max(1,(int)($settings['attendance_radius_meters'] ?? 100));
$scheme=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host=$_SERVER['HTTP_HOST'] ?? 'localhost';
$dir=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/qr_api.php')), '/');
$qrUrl=$scheme.'://'.$host.($dir ? $dir : '').'/member_app.php?attendance=1';

echo json_encode([
    'success'=>true, 'permanent'=>true, 'qr_url'=>$qrUrl,
    'token'=>'AR_LIBRARY_ATTENDANCE_V1',
    'library_name'=>$settings['library_name'] ?? 'AR LIBRARY',
    'latitude'=>$lat, 'longitude'=>$lng, 'radius_meters'=>$radius,
    'message'=>'Permanent attendance QR. Server-side GPS verification is required.'
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
