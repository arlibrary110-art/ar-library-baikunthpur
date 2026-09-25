<?php
require_once __DIR__ . "/security.php";
if (isset($_SESSION["member_app_id"])) { header("Location: member_app.php"); exit; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="csrf-token" content="<?=htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8')?>"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>AR Library Member Login</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Arial,sans-serif;background:linear-gradient(180deg,#eef7ff,#fff 70%,#e9f4ff);display:flex;align-items:center;justify-content:center;color:#0d2d68}.login{width:min(420px,94%);min-height:690px;padding:28px 22px 24px;border-radius:30px;background:#fff;box-shadow:0 18px 50px rgba(0,71,160,.16);position:relative;overflow:hidden}.login:after{content:"";position:absolute;left:-10%;right:-10%;bottom:-90px;height:190px;background:#0668df;border-radius:50% 50% 0 0/55% 55% 0 0}.brand{text-align:center;position:relative;z-index:1}.logo{width:112px;height:112px;object-fit:contain;border-radius:24px}.brand h1{margin:8px 0 2px;font-size:30px;letter-spacing:.4px}.brand p{margin:0 0 34px;font-size:15px;color:#4b638b;font-weight:700}.field{margin:0 0 18px}.field label{display:block;font-size:13px;font-weight:800;margin:0 0 7px;color:#24416f}.input{height:52px;border:1px solid #cbd9eb;border-radius:13px;width:100%;padding:0 14px;font-size:16px;outline:none;background:#fbfdff}.input:focus{border-color:#0668df;box-shadow:0 0 0 3px rgba(6,104,223,.1)}.login-btn{width:100%;height:52px;border:0;border-radius:13px;background:#0868db;color:#fff;font-size:17px;font-weight:800;cursor:pointer}.forgot{text-align:center;margin:15px 0 0;font-size:13px;color:#0868db;font-weight:700}.tag{text-align:center;margin-top:35px;font-weight:800;line-height:1.55;color:#fff;position:relative;z-index:2;font-size:16px}#message{min-height:20px;text-align:center;color:#c03955;font-size:13px;font-weight:700;margin-top:10px}
</style></head>
<body><main class="login">
<div class="brand"><img class="logo" src="assets/ar-library-logo.webp" alt="AR Library logo"><h1>AR LIBRARY</h1><p>Baikunthpur<br>Silent Self Study Zone</p></div>
<div class="field"><label for="memberId">Member ID</label><input class="input" id="memberId" type="text" placeholder="MEM-001" autocomplete="username"></div>
<div class="field"><label for="phone">Registered Phone</label><input class="input" id="phone" type="tel" inputmode="numeric" placeholder="9876543210" autocomplete="tel"></div>
<button class="login-btn" type="button" onclick="memberLogin()">Login</button><div class="forgot">If you forgot your Member ID or registered phone, please contact AR Library.</div><div id="message"></div>
<div class="tag">“बैकुंठपुर की शान,<br>हर विद्यार्थी की पहचान।”</div>
</main>
<script>
async function memberLogin(){const memberId=document.getElementById('memberId').value.trim(),phone=document.getElementById('phone').value.trim(),message=document.getElementById('message');message.textContent='';if(!memberId||!phone){message.textContent='Member ID and phone are required';return;}const form=new FormData();form.append('member_id',memberId);form.append('phone',phone);const csrf=document.querySelector('meta[name=csrf-token]').content;form.append('csrf_token',csrf);try{const r=await fetch('member_api.php?action=login',{method:'POST',body:form,cache:'no-store'}),d=await r.json();if(d.success)location.href='member_app.php';else message.textContent=d.message||'Login failed';}catch(e){console.error(e);message.textContent='Server connection error';}}
document.addEventListener('keydown',e=>{if(e.key==='Enter')memberLogin()});
</script></body></html>
