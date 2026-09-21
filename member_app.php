<?php
session_start();
if (!isset($_SESSION["member_app_id"])) {
    header("Location: member_login.php");
    exit;
}
$qr = trim($_GET["qr"] ?? "");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">
<title>AR Library Member App</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:#f4f6fb;color:#26365b}.wrap{max-width:680px;margin:auto;padding:16px}.top{background:#26365b;color:#fff;border-radius:20px;padding:20px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center}.top h1{margin:0;font-size:23px}.top small{opacity:.8}.logout{border:0;border-radius:9px;padding:9px 12px;background:#fff;color:#26365b;font-weight:700}.card{background:#fff;border-radius:18px;padding:18px;margin-bottom:14px;box-shadow:0 6px 22px rgba(0,0,0,.06)}.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.item{background:#f7f8fb;padding:12px;border-radius:12px}.item b{display:block;font-size:12px;color:#667085;margin-bottom:4px}.scan{width:100%;padding:16px;border:0;border-radius:14px;background:#26365b;color:#fff;font-size:17px;font-weight:800}.stop{background:#bf5268}.msg{margin-top:12px;padding:12px;border-radius:12px;background:#f1f5f9;text-align:center;font-weight:700}.hidden{display:none!important}#reader{width:100%;margin-top:14px}.history{width:100%;border-collapse:collapse;font-size:14px}.history th,.history td{padding:10px 6px;border-bottom:1px solid #e5e7eb;text-align:left}.badge{display:inline-block;padding:5px 8px;border-radius:8px;background:#e8eef8;font-size:12px;font-weight:700}.empty{text-align:center;color:#667085;padding:15px}.qr-note{font-size:13px;color:#667085;line-height:1.5}
@media(max-width:520px){.profile-grid{grid-template-columns:1fr}.top{align-items:flex-start}.top h1{font-size:20px}}
</style>
</head>
<body>
<div class="wrap">
<div class="top"><div style="display:flex;align-items:center;gap:10px"><img src="assets/ar-library-logo.webp" alt="AR Library logo" style="width:46px;height:46px;object-fit:contain;border-radius:10px"><div><h1>AR LIBRARY</h1><small>Member App</small></div></div><button class="logout" onclick="logout()">Logout</button></div>

<div class="card">
<h2 id="memberName">Loading...</h2>
<div class="profile-grid" id="profile"></div>
</div>

<div class="card">
<h3>Today's Attendance</h3>
<button id="scanBtn" class="scan" onclick="startScanner()">📷 Scan Office QR</button>
<div id="reader" class="hidden"></div>
<button id="stopBtn" class="scan stop hidden" onclick="stopScanner()">Stop Scanner</button>
<div id="msg" class="msg">Come to the library and scan the office QR.</div>
<p class="qr-note">AR Library permanent QR. Attendance is allowed only after member login and GPS verification within the library's allowed radius.</p>
</div>

<div class="card">
<h3>Attendance History</h3>
<div style="overflow-x:auto"><table class="history"><thead><tr><th>Date</th><th>Check In</th><th>Check Out</th><th>Status</th></tr></thead><tbody id="history"><tr><td colspan="4" class="empty">Loading...</td></tr></tbody></table></div>
</div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js" type="text/javascript"></script>
<script>window.__qrLibLoaded = typeof Html5Qrcode !== "undefined";</script>
<script>
const initialQR = <?php echo json_encode($qr, JSON_UNESCAPED_SLASHES); ?>;
let scanner=null;

function msg(text){document.getElementById('msg').textContent=text;}
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
function render(data){
 const m=data.member||{};
 document.getElementById('memberName').textContent=m.name||'Member';
 document.getElementById('profile').innerHTML=`
  <div class="item"><b>Member ID</b>${esc(m.member_id)}</div>
  <div class="item"><b>Membership</b>${esc(m.membership_plan||'—')}</div>
  <div class="item"><b>Shift</b>${esc(m.shift||'Full Day')}</div>
  <div class="item"><b>Seat No.</b>${esc(m.seat_no||'Not Assigned')}</div>
  <div class="item"><b>Joining Date</b>${esc(m.joining_date||'—')}</div>
  <div class="item"><b>Validity</b>${esc(m.validity_date||'—')}</div>
  <div class="item"><b>Fee Status</b><span class="badge">${esc(m.fee_status||'Paid')}${Number(m.fee_due||0)>0?' — ₹'+Number(m.fee_due).toLocaleString('en-IN',{minimumFractionDigits:0,maximumFractionDigits:2})+' Due':''}</span></div>
  <div class="item"><b>Membership Status</b><span class="badge">${esc(m.status||'Active')}</span></div>`;
 const rows=data.attendance||[];
 document.getElementById('history').innerHTML=rows.length?rows.map(r=>`<tr><td>${esc(r.attendance_date)}</td><td>${esc(r.check_in_time||'—')}</td><td>${esc(r.check_out_time||'—')}</td><td><span class="badge">${esc(r.status||'')}</span></td></tr>`).join(''):'<tr><td colspan="4" class="empty">No attendance records yet.</td></tr>';
 if(data.today){
   if(data.today.check_out_time) msg('Today: Check In '+data.today.check_in_time+' | Check Out '+data.today.check_out_time);
   else if(data.today.check_in_time) msg('Today: Checked In at '+data.today.check_in_time+'. Scan again when leaving.');
 }
}
async function loadMe(){
 try{const r=await fetch('member_api.php?action=me',{cache:'no-store'});const d=await r.json();if(!d.success){location.href='member_login.php';return;}render(d);}catch(e){console.error(e);msg('Could not load profile.');}
}
async function sendScan(token){
 if(token !== 'AR_LIBRARY_ATTENDANCE_V1'){msg('Invalid AR Library attendance QR.');return;}
 msg('QR verified. Getting your current location...');
 if(!navigator.geolocation){msg('This phone/browser does not support location.');return;}
 navigator.geolocation.getCurrentPosition(async (pos)=>{
   const fd=new FormData();
   fd.append('token',token);
   fd.append('latitude',String(pos.coords.latitude));
   fd.append('longitude',String(pos.coords.longitude));
   if(Number.isFinite(pos.coords.accuracy)) fd.append('accuracy',String(pos.coords.accuracy));
   try{
     const r=await fetch('attendance_api.php?action=scan_attendance',{method:'POST',body:fd,cache:'no-store'});
     const d=await r.json();
     msg(d.message||'Attendance updated');
     if(d.success){await loadMe();}
   }catch(e){console.error(e);msg('Could not connect to attendance server.');}
 },(err)=>{
   console.error(err);
   if(err.code===1) msg('Location permission denied. Please allow location permission and try again.');
   else if(err.code===2) msg('Current location is unavailable. Turn on GPS/location and try again.');
   else msg('Could not get your location. Please try again.');
 },{enableHighAccuracy:true,timeout:15000,maximumAge:0});
}
function extractToken(text){
 try{const u=new URL(text);const t=u.searchParams.get('qr')||u.searchParams.get('token');if(t)return t;if(u.searchParams.get('attendance')==='1')return 'AR_LIBRARY_ATTENDANCE_V1';}catch(e){}
 return text.trim();
}
async function onScanSuccess(decodedText){
 const token=extractToken(decodedText);
 if(!token){msg('Invalid QR code');return;}
 await stopScanner();
 await sendScan(token);
}
async function startScanner(){
 document.getElementById('scanBtn').classList.add('hidden');
 document.getElementById('reader').classList.remove('hidden');
 document.getElementById('stopBtn').classList.remove('hidden');
 msg('Camera starting...');
 try{
  if(!window.isSecureContext && location.hostname !== 'localhost'){
   throw new Error('Camera requires HTTPS');
  }
  if(typeof Html5Qrcode === 'undefined'){
   throw new Error('QR camera library did not load');
  }
  scanner=new Html5Qrcode('reader');
  const config={fps:10,qrbox:{width:240,height:240}};
  try{
   await scanner.start({facingMode:{exact:'environment'}},config,onScanSuccess,()=>{});
  }catch(firstError){
   console.warn('Environment camera failed, trying available camera:', firstError);
   const cameras=await Html5Qrcode.getCameras();
   if(!cameras || !cameras.length) throw firstError;
   const preferred=cameras.find(c=>/back|rear|environment|world/i.test(c.label)) || cameras[0];
   await scanner.start(preferred.id,config,onScanSuccess,()=>{});
  }
  msg('Point your camera at the office QR.');
 }catch(e){
  console.error('Camera start failed:',e);
  const reason=String(e && e.message || e || '');
  if(/HTTPS/i.test(reason)) msg('Camera needs HTTPS. Please open the AR Library website with https://.');
  else if(/permission|notallowed/i.test(reason)) msg('Camera permission denied. Please allow camera permission in browser settings and try again.');
  else if(/notfound|no camera/i.test(reason)) msg('No camera found on this device.');
  else msg('Camera could not start. Please allow camera permission and try again.');
  document.getElementById('scanBtn').classList.remove('hidden');
  document.getElementById('reader').classList.add('hidden');
  document.getElementById('stopBtn').classList.add('hidden');
  if(scanner){try{await scanner.clear();}catch(_){} }
  scanner=null;
 }
}
async function stopScanner(){
 if(scanner){try{await scanner.stop();}catch(e){}try{scanner.clear();}catch(e){}scanner=null;}
 document.getElementById('reader').classList.add('hidden');document.getElementById('stopBtn').classList.add('hidden');document.getElementById('scanBtn').classList.remove('hidden');
}
async function logout(){try{await fetch('member_api.php?action=logout');}catch(e){}location.href='member_login.php';}
loadMe();
if(initialQR){
 setTimeout(()=>sendScan(initialQR),400);
}else{
 // Try to start the camera automatically after member login; if the browser blocks
 // it, the visible Scan Office QR button remains available for a user tap.
 setTimeout(()=>startScanner(),700);
}
</script>
</body>
</html>
