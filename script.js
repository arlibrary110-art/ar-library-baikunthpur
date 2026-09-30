(function(){
'use strict';
const $=id=>document.getElementById(id);
const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const money=v=>'₹'+Number(v||0).toLocaleString('en-IN',{maximumFractionDigits:2});
const today=()=>new Date().toISOString().slice(0,10);
const form=(o={})=>{const f=new FormData();Object.entries(o).forEach(([k,v])=>f.append(k,v??''));return f};
async function req(url,opts={}){const method=(opts.method||'GET').toUpperCase();const headers=new Headers(opts.headers||{});if(method!=='GET')headers.set('X-CSRF-Token',csrf());const r=await fetch(url,{...opts,method,headers,credentials:'same-origin',cache:'no-store'});const ct=r.headers.get('content-type')||'';const raw=await r.text();if(ct.includes('application/json')){let d;try{d=JSON.parse(raw)}catch(e){throw Error('Server returned invalid JSON (HTTP '+r.status+'). Response: '+raw.slice(0,800))}if(r.status===401){location.href='index.php';throw Error(d.message||'Please login again.')}if(!r.ok||d.success===false)throw Error(d.message||('Request failed (HTTP '+r.status+').'));return d;}if(!r.ok){const clean=raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();throw Error('Request failed (HTTP '+r.status+'). '+(clean||'No response body.').slice(0,1200));}const clean=raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();throw Error('Server returned an unexpected response (HTTP '+r.status+'). '+(clean||'No response body.').slice(0,1200));}
const api=(a,o={})=>req('admin_api.php?action='+a,o);
const payApi=(a,o={})=>req('payments_api.php?action='+encodeURIComponent(a),o);
function card(html,cls='card'){return '<div class="'+cls+'" style="background:#fff;border-radius:14px;padding:20px;box-shadow:0 5px 22px rgba(20,40,80,.06)">'+html+'</div>'}
function table(headers,rows){return '<div style="overflow:auto"><table style="width:100%;border-collapse:collapse"><thead><tr>'+headers.map(h=>'<th style="text-align:left;padding:10px;border-bottom:1px solid #e8edf5;white-space:nowrap">'+h+'</th>').join('')+'</tr></thead><tbody>'+rows+'</tbody></table></div>'}
function input(label,name,value='',type='text'){return '<label style="display:block;font-weight:700;font-size:13px;margin-bottom:12px">'+label+'<input id="'+esc(name)+'" name="'+esc(name)+'" type="'+esc(type)+'" value="'+esc(value)+'" style="width:100%;margin-top:6px;padding:10px 12px;border:1px solid #d8dfeb;border-radius:9px;box-sizing:border-box"></label>'}
function select(label,name,opts,value=''){return '<label style="display:block;font-weight:700;font-size:13px;margin-bottom:12px">'+label+'<select name="'+name+'" style="width:100%;margin-top:6px;padding:10px 12px;border:1px solid #d8dfeb;border-radius:9px;background:#fff">'+opts.map(x=>'<option value="'+esc(x[0])+'" '+(x[0]===value?'selected':'')+'>'+esc(x[1])+'</option>').join('')+'</select></label>'}
function buttons(){return '<button type="submit" class="btn primary" style="padding:10px 16px">Save</button>'}
function setTitle(t){$('pageTitle').textContent=t;$('dateText').textContent=new Date().toLocaleDateString('en-IN',{weekday:'long',day:'numeric',month:'long',year:'numeric'});}
function active(p){document.querySelectorAll('.nav-item[data-page]').forEach(x=>x.classList.toggle('active',x.dataset.page===p));}
function loading(){ $('content').innerHTML=card('<div style="font-weight:800">Loading…</div>'); }
function fail(e){const msg=e&&e.message?e.message:String(e||'Unknown error');$('content').innerHTML=card('<h2>Unable to load</h2><p>'+esc(msg)+'</p><button class="btn primary" onclick="loadPage(\'dashboard\')">Back to Dashboard</button>');}

window.openLogin=function(role){role=String(role||'staff').toLowerCase()==='admin'?'admin':'staff';$('selectedRole').value=role;$('loginRoleTitle').textContent=role==='admin'?'ADMIN LOGIN':'STAFF LOGIN';$('loginRoleIcon').textContent=role==='admin'?'🔐':'👤';$('loginDemo').textContent=role==='admin'?'Admin login — use your Admin Staff ID and password.':'Staff login — use your Staff ID and password.';$('roleScreen').classList.add('hidden');$('loginScreen').classList.remove('hidden');setTimeout(()=>$('staffId').focus(),30)};
window.showRole=function(){$('loginScreen').classList.add('hidden');$('roleScreen').classList.remove('hidden');$('staffId').value='';$('password').value='';};
function loginMsg(t,ok=false){let e=$('loginMessage');if(!e){e=document.createElement('p');e.id='loginMessage';e.style.cssText='margin:12px 0;text-align:center;font-weight:800';$('loginScreen').querySelector('.login-card').appendChild(e)}e.textContent=t;e.style.color=ok?'#198754':'#c03955'}
window.login=async function(){const staffId=$('staffId').value.trim(),password=$('password').value,role=$('selectedRole').value;if(!staffId||!password)return loginMsg('Staff ID and password are required.');const bs=$('loginScreen').querySelectorAll('button');bs.forEach(b=>b.disabled=true);loginMsg('Signing in…',true);try{const d=await req('login.php',{method:'POST',body:form({staff_id:staffId,password,role})});if(String(d.role).toLowerCase()!==role)throw Error('Selected role does not match this account.');$('loginScreen').classList.add('hidden');$('roleScreen').classList.add('hidden');$('app').classList.remove('hidden');location.reload();}catch(e){loginMsg(e.message)}finally{bs.forEach(b=>b.disabled=false)}};
window.logout=()=>location.href='logout.php';

function dashboard(d){
 const s=d&&d.stats?d.stats:{};
 const trend=Array.isArray(d&&d.trend)?d.trend:[];
 const activities=Array.isArray(d&&d.activities)?d.activities:[];
 const birthdays=Array.isArray(d&&d.birthdays)?d.birthdays:[];
 const expiry=Array.isArray(d&&d.expiry)?d.expiry:[];
 const role=(document.body.dataset.role||'staff').toLowerCase();
 const name=(document.getElementById('userName')?.textContent||'').trim() || (role==='admin'?'Administrator':'Team Member');
 const occ=s.total_seats?Math.round((Number(s.occupied||0)/Number(s.total_seats||1))*100):0;
 const lockerOcc=s.total_lockers?Math.round((Number(s.occupied_lockers||0)/Number(s.total_lockers||1))*100):0;
 const maxTrend=Math.max(1,...trend.map(x=>Math.max(Number(x.collection||0),Number(x.expenses||0))));
 const shortDay=x=>{const dt=new Date(String(x)+'T00:00:00');return isNaN(dt)?esc(x):dt.toLocaleDateString('en-IN',{day:'2-digit',month:'short'});};
 const stat=(icon,label,value,note,cls)=>'<div class="dash-kpi '+cls+'"><div class="kpi-icon">'+icon+'</div><div class="kpi-copy"><span>'+label+'</span><strong>'+esc(value)+'</strong><small>'+esc(note)+'</small></div></div>';
 const quick=(icon,label,page)=>'<button class="dash-action" onclick="loadPage(\''+page+'\')">'+icon+' '+label+'</button>';
 const trendBars=trend.length?trend.map(x=>{const c=Math.max(0,Number(x.collection||0)),e=Math.max(0,Number(x.expenses||0));return '<div class="bar-day" title="'+esc(shortDay(x.day))+' · Collection '+esc(money(c))+' · Expense '+esc(money(e))+'"><div class="bars"><i class="bar collection" style="height:'+Math.max(4,Math.round(c/maxTrend*150))+'px"></i><i class="bar expense" style="height:'+Math.max(4,Math.round(e/maxTrend*150))+'px"></i></div><small>'+esc(shortDay(x.day))+'</small></div>'}).join(''):'<div class="muted" style="padding:50px 0;text-align:center;width:100%">No financial activity for the last 7 days.</div>';
 const activityRows=activities.slice(0,8).map(x=>'<tr><td style="padding:10px 8px;font-weight:800">'+esc(x.action)+'</td><td style="padding:10px 8px;color:#66728e">'+esc(x.details||'—')+'</td><td style="padding:10px 8px;color:#8d97aa;white-space:nowrap">'+esc(x.created_at||'')+'</td></tr>').join('');
 const birthdayRows=birthdays.slice(0,5).map(x=>'<div class="dash-list-row"><div class="dash-list-avatar">🎂</div><div><b>'+esc(x.name)+'</b><small>'+esc(x.phone||x.member_id||'')+'</small></div><strong>'+esc(shortDay(x.date_of_birth))+'</strong></div>').join('');
 const expiryRows=expiry.slice(0,6).map(x=>{const d=String(x.validity_date||'');return '<div class="dash-list-row"><div class="dash-list-avatar warning">⏳</div><div><b>'+esc(x.name)+'</b><small>'+esc(x.membership_plan||x.member_id||'')+'</small></div><strong>'+esc(d)+'</strong></div>'}).join('');
 return '<div class="dashboard-page">'
 +' <section class="dash-hero"><div><div class="eyebrow">AR LIBRARY · MANAGEMENT OVERVIEW</div><h2>Good day, '+esc(name)+' 👋</h2><p>Here is today\'s library activity, collections and member status at a glance.</p></div><div class="dash-actions">'+quick('＋','Add Member','members')+quick('₹','Collect Fee','fees')+quick('✓','Attendance','attendance')+'<button class="dash-refresh" onclick="loadPage(\'dashboard\')">↻ Refresh</button></div></section>'
 +' <section class="dash-kpis">'
 +stat('👥','Total Members',s.members||0,(s.active||0)+' active members','blue')
 +stat('✓','Present Today',s.present||0,(s.checked_in||0)+' currently checked in','green')
 +stat('₹','Today Collection',money(s.today_paid),(s.month_paid?money(s.month_paid):'₹0')+' this month','amber')
 +stat('⚠','Pending Fees',money(s.pending_fees),'Outstanding member fees','violet')
 +' </section>'
 +' <section class="dash-grid-2"><div>'
 +'  <div class="dash-panel"><div class="dash-panel-head"><div><div class="eyebrow">LAST 7 DAYS</div><h3>Collection vs Expenses</h3></div><span class="live-pill">● Live</span></div><div class="chart-legend"><span><i class="legend-dot collection"></i>Collection</span><span><i class="legend-dot expense"></i>Expenses</span></div><div class="bar-chart">'+trendBars+'</div></div>'
 +'  <div class="dash-panel"><div class="dash-panel-head"><div><div class="eyebrow">RECENT ACTIVITY</div><h3>Latest updates</h3></div><button class="btn" onclick="loadPage(\'reports\')">View reports</button></div>'+(activityRows?table(['Action','Details','Time'],activityRows):'<div class="muted" style="padding:20px 0">No recent activity.</div>')+'</div>'
 +' </div><div>'
 +'  <div class="dash-panel"><div class="dash-panel-head"><div><div class="eyebrow">FINANCE</div><h3>This month</h3></div></div><div class="finance-kpis"><div class="finance-item"><span>Collection</span><strong>'+money(s.month_paid)+'</strong></div><div class="finance-item expense"><span>Expenses</span><strong>'+money(s.month_expenses)+'</strong></div><div class="finance-item net"><span>Net income</span><strong>'+money(s.month_net)+'</strong></div><div class="finance-item"><span>Open enquiries</span><strong>'+esc(s.open_enquiries||0)+'</strong></div></div></div>'
 +'  <div class="dash-panel"><div class="dash-panel-head"><div><div class="eyebrow">CAPACITY</div><h3>Seats & Lockers</h3></div></div><div class="capacity-wrap"><div class="capacity-line"><div><b>Seats</b><span>'+esc(s.occupied||0)+' occupied</span></div><strong>'+esc(s.available_seats||0)+' available</strong></div><div class="progress"><i style="width:'+Math.min(100,occ)+'%"></i></div><div class="capacity-line"><div><b>Lockers</b><span>'+esc(s.occupied_lockers||0)+' occupied</span></div><strong>'+esc(s.available_lockers||0)+' available</strong></div><div class="progress"><i style="width:'+Math.min(100,lockerOcc)+'%"></i></div></div></div>'
 +'  <div class="dash-panel"><div class="dash-panel-head"><div><div class="eyebrow">MEMBERSHIP HEALTH</div><h3>Status overview</h3></div></div><div class="membership-overview"><div class="membership-stat"><i class="dot active"></i><div><b>'+esc(s.active||0)+'</b><small>Active</small></div></div><div class="membership-stat"><i class="dot warning"></i><div><b>'+esc(s.expiring||0)+'</b><small>Expiring in 7 days</small></div></div><div class="membership-stat"><i class="dot danger"></i><div><b>'+esc(s.expired||0)+'</b><small>Expired</small></div></div><div class="membership-stat"><i class="dot info"></i><div><b>'+esc(s.converted_enquiries||0)+'</b><small>Converted enquiries</small></div></div></div></div>'
 +' </div></section>'
 +' <section class="dash-grid-2"><div class="dash-panel"><div class="dash-panel-head"><div><div class="eyebrow">UPCOMING</div><h3>Birthdays</h3></div></div>'+(birthdayRows||'<div class="muted">No birthdays in the next 7 days.</div>')+'</div><div class="dash-panel"><div class="dash-panel-head"><div><div class="eyebrow">ATTENTION NEEDED</div><h3>Membership expiry</h3></div><button class="btn" onclick="loadPage(\'members\')">View members</button></div>'+(expiryRows||'<div class="muted">No memberships expiring in the next 7 days.</div>')+'</div></section>'
 +'</div>';
}
async function loadDashboard(){const d=await api('dashboard');if(!d||!d.stats)throw Error((d&&d.message)||'Dashboard data was not returned by the server.');$('content').innerHTML=dashboard(d)}

async function members(){
 const d=await req('members_api.php?action=list');
 const rows=Array.isArray(d.members)?d.members:[];
 const archived=Array.isArray(d.archived_members)?d.archived_members:[];
 const activeCount=rows.filter(m=>String(m.status||'').toLowerCase()==='active').length;
 const expiredCount=rows.filter(m=>String(m.status||'').toLowerCase()==='expired').length;
 const cards=rows.map(m=>{
   const name=String(m.name||'Member');
   const initials=name.trim().split(/\s+/).slice(0,2).map(x=>x[0]||'').join('').toUpperCase()||'M';
   const status=String(m.status||'Active');
   const statusClass=status.toLowerCase()==='active'?'active':(status.toLowerCase()==='expired'?'expired':'expiring');
   return '<article class=\"member-profile-card member-item\" data-search=\"'+esc([m.member_id,name,m.phone,m.email,m.membership_plan,m.shift,m.validity_date,status].join(' '))+'\">'
    +'<div class=\"member-card-top\"><div class=\"member-person\"><div class=\"member-avatar\">'+esc(initials)+'</div><div class=\"member-card-person\"><strong>'+esc(name)+'</strong><small>'+esc(m.member_id||'—')+'</small></div></div>'
    +'<span class=\"member-status '+statusClass+'\"><i></i>'+esc(status)+'</span></div>'
    +'<div class=\"member-card-contact\"><span>📞 '+esc(m.phone||'No phone')+'</span><span>✉️ '+esc(m.email||'No email')+'</span></div>'
    +'<div class=\"member-card-details\"><div><small>PLAN</small><b>'+esc(m.membership_plan||'—')+'</b></div><div><small>SHIFT</small><b>'+esc(m.shift||'—')+'</b></div><div><small>VALIDITY</small><b>'+esc(m.validity_date||'—')+'</b></div></div>'
    +'<div class=\"member-card-footer\"><div class=\"member-card-meta\">Joined: '+esc(m.joining_date||'—')+'</div><div class=\"member-row-actions\"><a class=\"member-row-btn view\" href=\"member_profile.php?id='+encodeURIComponent(m.id)+'\">View</a><button type=\"button\" class=\"member-row-btn edit\" onclick=\"editMember('+Number(m.id)+')\">Edit</button><button type=\"button\" class=\"member-row-btn delete\" onclick=\"deleteMember('+Number(m.id)+')\">Delete</button></div></div>'
    +'</article>';
 }).join('');
 $('content').innerHTML='<div class=\"members-page\">'
  +'<section class=\"member-summary-grid\">'
  +'<div class=\"member-summary-card total\"><div class=\"member-summary-icon\">👥</div><div><span>Total Members</span><strong>'+esc(rows.length)+'</strong><small>All registered members</small></div></div>'
  +'<div class=\"member-summary-card active\"><div class=\"member-summary-icon\">✓</div><div><span>Active</span><strong>'+esc(activeCount)+'</strong><small>Currently active</small></div></div>'
  +'<div class=\"member-summary-card expired\"><div class=\"member-summary-icon\">!</div><div><span>Expired</span><strong>'+esc(expiredCount)+'</strong><small>Need renewal</small></div></div>'
  +'<div class=\"member-summary-card expiring\"><div class=\"member-summary-icon\">⏳</div><div><span>Other</span><strong>'+esc(Math.max(0,rows.length-activeCount-expiredCount))+'</strong><small>Other statuses</small></div></div>'
  +'</section>'
  +'<section class=\"dash-panel member-list-card\">'
  +'<div class=\"member-list-toolbar\"><div><div class=\"eyebrow\">MEMBER DIRECTORY</div><h3>All Members</h3><p class=\"muted\">Each member is shown in a separate card.</p></div><div class=\"member-tools\"><label class=\"member-search\"><span>⌕</span><input id=\"memberSearch\" type=\"search\" placeholder=\"Search member...\" autocomplete=\"off\"></label><button type=\"button\" class=\"btn primary\" onclick=\"newMember()\">+ Add Member</button></div></div>'
  +'<div id=\"memberCardGrid\" style=\"display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:15px;padding:20px;\">'+(cards||'<div class=\"member-card-empty\"><h3>No active members found</h3><p class=\"muted\">Add your first member to get started.</p><button type=\"button\" class=\"btn primary\" onclick=\"newMember()\">+ Add Member</button></div>')+'</div>'
  +'</section>'
  +(archived.length?'<section class="dash-panel member-list-card" style="margin-top:18px"><div class="member-list-toolbar"><div><div class="eyebrow">ARCHIVED MEMBERS</div><h3>Archived Members</h3><p class="muted">Archived members are hidden from active lists. Fee/payment history is preserved.</p></div></div><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:15px;padding:20px;">'+archived.map(m=>{const name=String(m.name||'Member');const initials=name.trim().split(/\s+/).slice(0,2).map(x=>x[0]||'').join('').toUpperCase()||'M';return '<article class="member-profile-card member-item" style="opacity:.92"><div class="member-card-top"><div class="member-person"><div class="member-avatar">'+esc(initials)+'</div><div class="member-card-person"><strong>'+esc(name)+'</strong><small>'+esc(m.member_id||'—')+'</small></div></div><span class="member-status"><i></i>Archived</span></div><div class="member-card-contact"><span>📞 '+esc(m.phone||'No phone')+'</span><span>✉️ '+esc(m.email||'No email')+'</span></div><div class="member-card-details"><div><small>PLAN</small><b>'+esc(m.membership_plan||'—')+'</b></div><div><small>SHIFT</small><b>'+esc(m.shift||'—')+'</b></div><div><small>VALIDITY</small><b>'+esc(m.validity_date||'—')+'</b></div></div><div class="member-card-footer"><div class="member-card-meta">Fee history preserved</div><div class="member-row-actions"><a class="member-row-btn view" href="member_profile.php?id='+encodeURIComponent(m.id)+'">View</a><button type="button" class="member-row-btn edit" onclick="restoreMember('+Number(m.id)+')">Restore</button></div></div></article>';}).join('')+'</div></section>':'')
  +'</div>';
 const search=$('memberSearch');
 if(search) search.addEventListener('input',()=>{const q=search.value.trim().toLowerCase();document.querySelectorAll('.member-item').forEach(r=>r.style.display=(r.dataset.search||'').toLowerCase().includes(q)?'':'none');});
}
function modal(title,body){let m=$('globalModal');if(!m){m=document.createElement('div');m.id='globalModal';m.style.cssText='position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';document.body.appendChild(m)}m.innerHTML='<div style="background:#fff;width:min(760px,100%);max-height:90vh;overflow:auto;border-radius:16px;padding:22px"><div style="display:flex;justify-content:space-between;align-items:center"><h2 style="margin:0">'+esc(title)+'</h2><button onclick="closeModal()" style="border:0;background:none;font-size:24px">×</button></div><div style="margin-top:18px">'+body+'</div></div>';}
window.deleteMember=async function(id){
 const ok=confirm('Archive this member?\n\nThe member will be removed from active lists, but fee/payment history, attendance and other records will NOT be deleted. You can restore the member later.');
 if(!ok)return;
 try{const d=await req('members_api.php?action=delete',{method:'POST',body:form({id})});alert(d.message||'Member archived successfully.');await members();}
 catch(e){alert(e.message||'Could not archive member.');}
};
window.restoreMember=async function(id){
 const ok=confirm('Restore this member to the active member list?');
 if(!ok)return;
 try{const d=await req('members_api.php?action=restore',{method:'POST',body:form({id})});alert(d.message||'Member restored successfully.');await members();}
 catch(e){alert(e.message||'Could not restore member.');}
};
window.closeModal=()=>{$('globalModal')?.remove()};
function memberPlanOptions(value='1 Month'){return [['1 Month','1 Month'],['3 Months','3 Month'],['6 Months','6 Month'],['Custom','Custom']].map(x=>[x[0],x[1]]);}
function memberShiftOptions(value='Full Day'){return [['Morning Shift','Half Day — Morning'],['Evening Shift','Half Day — Evening'],['Morning Shift Reserved','Half Day Reserved — Morning'],['Evening Shift Reserved','Half Day Reserved — Evening'],['Full Day','Full Day'],['Full Day Reserved','Full Day Reserved']];}
function setMemberValidity(formEl){const plan=formEl.querySelector('[name=membership_plan]')?.value;const join=formEl.querySelector('[name=joining_date]')?.value;const valid=formEl.querySelector('[name=validity_date]');if(!valid||!join)return;if(plan==='Custom'){valid.readOnly=false;valid.style.background='#fff';return;}const months=plan==='3 Months'?3:(plan==='6 Months'?6:1);const d=new Date(join+'T00:00:00');if(Number.isNaN(d.getTime()))return;d.setMonth(d.getMonth()+months);d.setDate(d.getDate()-1);valid.value=d.toISOString().slice(0,10);valid.readOnly=true;valid.style.background='#f4f7fb';}
window.newMember=function(){
 const html='<form id=\"memberForm\">'+input('Member ID','member_id','AR-'+Date.now().toString().slice(-6))+input('Name','name')+input('Father Name','father_name')+'<div style=\"display:grid;grid-template-columns:1fr 1fr;gap:12px\">'+input('Phone','phone')+input('Email','email','', 'email')+'</div><div style=\"display:grid;grid-template-columns:1fr 1fr;gap:12px\">'+select('Plan','membership_plan',memberPlanOptions())+select('Shift','shift',memberShiftOptions())+'</div><div style=\"display:grid;grid-template-columns:1fr 1fr;gap:12px\">'+input('Joining Date','joining_date',today(),'date')+input('Birthday / Date of Birth','date_of_birth','','date')+'</div>'+input('Validity Date','validity_date',today(),'date')+input('Address','address')+'<div style=\"display:flex;gap:10px;justify-content:flex-end;margin-top:8px\"><button type=\"button\" class=\"btn\" onclick=\"closeModal()\">Cancel</button><button id=\"saveMemberBtn\" type=\"submit\" class=\"btn primary\">Save Member</button></div></form>';
 modal('Add New Member',html);
 const formEl=$('memberForm');
 if(!formEl)return;
 const planEl=formEl.querySelector('[name=membership_plan]'),joinEl=formEl.querySelector('[name=joining_date]');
 planEl.addEventListener('change',()=>setMemberValidity(formEl));joinEl.addEventListener('change',()=>setMemberValidity(formEl));setMemberValidity(formEl);
 formEl.onsubmit=async e=>{e.preventDefault();const btn=$('saveMemberBtn');if(btn?.disabled)return;btn.disabled=true;const old=btn.textContent;btn.textContent='Saving...';try{const d=await req('members_api.php?action=create',{method:'POST',body:new FormData(formEl)});if(!d||d.success!==true)throw Error(d?.message||'Member could not be saved.');closeModal();await members();}catch(x){alert(x.message||'Unable to save member.');if(btn){btn.disabled=false;btn.textContent=old;}}};
};
window.editMember=async function(id){const d=await req('members_api.php?action=get&id='+id),m=d.member;modal('Edit Member','<form id="memberForm">'+input('Member ID','member_id',m.member_id)+input('Name','name',m.name)+input('Father Name','father_name',m.father_name||'')+input('Phone','phone',m.phone||'')+input('Email','email',m.email||'','email')+select('Plan','membership_plan',memberPlanOptions(),m.membership_plan==='Custom Date'?'Custom':m.membership_plan)+select('Shift','shift',memberShiftOptions(),m.shift)+input('Joining Date','joining_date',m.joining_date||'','date')+input('Birthday / Date of Birth','date_of_birth',m.date_of_birth||'','date')+input('Validity Date','validity_date',m.validity_date||'','date')+select('Status','status',[['Active','Active'],['Inactive','Inactive'],['Expired','Expired']],m.status)+input('Address','address',m.address||'')+buttons()+'</form>');const formEl=$('memberForm');const planEl=formEl.querySelector('[name=membership_plan]'),joinEl=formEl.querySelector('[name=joining_date]');planEl.addEventListener('change',()=>setMemberValidity(formEl));joinEl.addEventListener('change',()=>setMemberValidity(formEl));if(planEl.value!=='Custom'){$('memberForm').querySelector('[name=validity_date]').readOnly=true;$('memberForm').querySelector('[name=validity_date]').style.background='#f4f7fb';}formEl.onsubmit=async e=>{e.preventDefault();const f=new FormData(e.target);f.append('id',id);try{await req('members_api.php?action=update',{method:'POST',body:f});closeModal();members()}catch(x){alert(x.message)}}};

function feeNav(active){
 const items=[
  ['record','Fee Records','window.feesRecord()'],
  ['income','Monthly Income','window.feesIncome()'],
  ['settings','Fee Settings','window.feesSettings()'],
  ['due','Dues','window.feesDue()']
 ];
 return '<div class="fee-nav" style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 18px">'+
   items.map(x=>'<button class="btn '+(x[0]===active?'primary':'')+'" onclick="'+x[2]+'">'+x[1]+'</button>').join('')+
   '</div>';
}
function feeDate(v){
 if(!v)return '—';
 const d=new Date(String(v).length===10?String(v)+'T00:00:00':v);
 if(Number.isNaN(d.getTime()))return esc(v);
 return d.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
}
function feeMode(v){
 const m=String(v||'').toLowerCase();
 if(m==='upi'||m==='online')return '<span style="display:inline-block;padding:4px 9px;border-radius:999px;background:#e8f7ef;color:#18794e;font-weight:800">'+esc(v)+'</span>';
 if(m==='cash')return '<span style="display:inline-block;padding:4px 9px;border-radius:999px;background:#fff3df;color:#9a5b00;font-weight:800">'+esc(v)+'</span>';
 return '<span style="display:inline-block;padding:4px 9px;border-radius:999px;background:#eef2f8;color:#53627a;font-weight:800">'+esc(v||'—')+'</span>';
}
function feePlanBadge(v){
 return '<span style="display:inline-block;padding:4px 9px;border-radius:999px;background:#eef3ff;color:#4669c9;font-weight:800">'+esc(v||'—')+'</span>';
}
function feeKpi(label,value,sub,cls){
 return '<div style="background:#fff;border:1px solid #e7ebf2;border-radius:14px;padding:15px;min-width:150px;flex:1;box-shadow:0 3px 14px rgba(20,40,80,.04)">'+
   '<div style="font-size:12px;color:#68748d;font-weight:800;text-transform:uppercase;letter-spacing:.04em">'+esc(label)+'</div>'+
   '<div class="'+(cls||'')+'" style="font-size:23px;font-weight:900;margin-top:5px">'+esc(value)+'</div>'+
   (sub?'<div style="font-size:12px;color:#7d8799;margin-top:3px">'+esc(sub)+'</div>':'')+
   '</div>';
}
async function fees(){return window.feesRecord()}

window.openWhatsApp=function(phone,message){
 const raw=String(phone||'').replace(/\D/g,'');
 if(!raw){alert('This member does not have a phone number.');return;}
 const normalized=raw.length===10?'91'+raw:raw;
 window.open('https://wa.me/'+normalized+'?text='+encodeURIComponent(message||''),'_blank','noopener');
};
window.whatsappFee=function(x){
 const name=x.name||x.member_name||x.member_code||'Member';
 const amount=money(Number(x.amount||0)+Number(x.additional_charges||0));
 const receipt=x.receipt_no||'—';
 const date=x.payment_date||today();
 const msg='AR Library - Fee Payment\n\nDear '+name+',\nYour fee payment of '+amount+' has been received.\nReceipt: '+receipt+'\nDate: '+date+'\nPayment Mode: '+(x.payment_method||'—')+'\n\nThank you.';
 openWhatsApp(x.phone,msg);
};
window.whatsappDue=function(x){
 const name=x.name||x.member_name||x.member_id||'Member';
 const due=money(Number(x.due_amount||0));
 const valid=x.due_date||x.validity_date||'—';
 const msg='AR Library - Fee Due Reminder\n\nDear '+name+',\nYour pending library fee is '+due+'.\nValid Till: '+valid+'\n\nPlease clear the pending fee at your earliest convenience.\nThank you.';
 openWhatsApp(x.phone,msg);
};
window.feesRecord=async function feesRecord(){
 try{
  const d=await payApi('list');
  const rows=d.payments||d.data||[];
  const totalCollected=rows.reduce((a,x)=>a+Number(x.amount||0),0);
  const cash=rows.filter(x=>String(x.payment_method||'').toLowerCase()==='cash').reduce((a,x)=>a+Number(x.amount||0),0);
  const online=rows.filter(x=>['upi','online'].includes(String(x.payment_method||'').toLowerCase())).reduce((a,x)=>a+Number(x.amount||0),0);
  const extra=rows.reduce((a,x)=>a+Number(x.additional_charges||0),0);
  const nowMonth=new Date().toISOString().slice(0,7);

  $('content').innerHTML=card(
   feeNav('record')+
   '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">'+
    '<div><h2 style="margin:0">Fee Records</h2><p style="color:#68748d;margin:5px 0 0">Daily, monthly and shift-wise fee records.</p></div>'+
    '<button class="btn primary" onclick="addPayment()">+ Record Fee</button>'+
   '</div>'+
   '<div style="display:flex;gap:10px;flex-wrap:wrap;margin:18px 0">'+
    '<div style="flex:2;min-width:220px">'+input('Search by member name...','feeSearch','','text')+'</div>'+
    '<div style="min-width:150px">'+input('Month','feeMonth',nowMonth,'month')+'</div>'+
    '<div style="min-width:150px">'+select('Payment Mode','feeMode',[['','All Modes'],['Cash','Cash'],['UPI','UPI'],['Online','Online'],['Bank','Bank'],['Card','Card'],['Other','Other']])+'</div>'+
    '<button class="btn" style="align-self:end" onclick="renderFeeRecords()">Apply</button>'+
   '</div>'+
   '<div id="feeRecordStats" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px">'+
    feeKpi('Records',rows.length)+feeKpi('Total Collected',money(totalCollected),'All payments')+
    feeKpi('Cash',money(cash))+feeKpi('UPI / Online',money(online))+feeKpi('Extra Charges',money(extra))+
   '</div>'+
   '<div id="feeRecordTable"></div>'
  );
  window._feeRows=rows;
  renderFeeRecords();
 }catch(e){fail(e)}
};

window.whatsappFeeById=function(id){
 const x=(window._feeRows||[]).find(a=>Number(a.id)===Number(id));
 if(!x){alert('Payment record not found.');return;}
 whatsappFee(x);
};
window.deleteFeeRecord=async function(id){
 const x=(window._feeRows||[]).find(a=>Number(a.id)===Number(id));
 if(!x){alert('Payment record not found.');return;}
 const receipt=x.receipt_no||'this payment';
 if(!confirm('Delete fee record '+receipt+'?\n\nThis will remove the payment and restore the applicable due amount.')) return;
 try{
   const d=await payApi('delete',{method:'POST',body:form({id:Number(id)})});
   alert(d.message||'Fee record deleted successfully.');
   await feesRecord();
 }catch(e){alert(e.message||'Could not delete fee record.');}
};
window.renderFeeRecords=function(){
 const rows=window._feeRows||[];
 const q=String($('feeSearch')?.value||'').trim().toLowerCase();
 const month=String($('feeMonth')?.value||'');
 const mode=String($('feeMode')?.value||'').toLowerCase();
 const filtered=rows.filter(x=>{
   const text=[x.name,x.member_name,x.member_code,x.member_id].map(v=>String(v||'').toLowerCase()).join(' ');
   const date=String(x.payment_date||'');
   return (!q||text.includes(q))&&(!month||date.slice(0,7)===month)&&(!mode||String(x.payment_method||'').toLowerCase()===mode);
 });
 const total=filtered.reduce((a,x)=>a+Number(x.amount||0),0);
 const cash=filtered.filter(x=>String(x.payment_method||'').toLowerCase()==='cash').reduce((a,x)=>a+Number(x.amount||0),0);
 const online=filtered.filter(x=>['upi','online'].includes(String(x.payment_method||'').toLowerCase())).reduce((a,x)=>a+Number(x.amount||0),0);
 const extra=filtered.reduce((a,x)=>a+Number(x.additional_charges||0),0);
 if($('feeRecordStats'))$('feeRecordStats').innerHTML=
   feeKpi('Records',filtered.length)+feeKpi('Total Collected',money(total))+feeKpi('Cash',money(cash))+feeKpi('UPI / Online',money(online))+feeKpi('Extra Charges',money(extra));
 const rowsHtml=filtered.map(x=>{
   const due=Number(x.balance_due||0);
   const status=due>0?'<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#fff0f0;color:#b42318;font-weight:800">Due</span>':'<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#e8f7ef;color:#18794e;font-weight:800">Paid</span>';
   return '<tr>'+
    '<td style="padding:12px 9px"><b>'+esc(x.receipt_no||'—')+'</b></td>'+
    '<td style="padding:12px 9px"><b>'+esc(x.name||x.member_name||x.member_code||'—')+'</b><br><small style="color:#7d8799">'+esc(x.member_code||x.member_id||'')+'</small></td>'+
    '<td style="padding:12px 9px">'+feePlanBadge(x.plan||x.membership_plan)+'</td>'+
    '<td style="padding:12px 9px">'+esc(x.shift||'—')+'</td>'+
    '<td style="padding:12px 9px"><b>'+money(x.amount)+'</b><br><small style="color:#7d8799">'+(Number(x.additional_charges||0)>0?'Extra '+money(x.additional_charges):'—')+'</small></td>'+
    '<td style="padding:12px 9px">'+feeDate(x.payment_date)+'</td>'+
    '<td style="padding:12px 9px">'+feeMode(x.payment_method)+'</td>'+
    '<td style="padding:12px 9px">'+status+'</td>'+
    '<td style="padding:12px 9px;white-space:nowrap"><a class="btn" target="_blank" href="receipt.php?id='+Number(x.id)+'&download=1" title="Download Receipt">🧾 Receipt</a> <button type="button" class="btn" onclick="whatsappFeeById('+Number(x.id)+')">💬 WhatsApp</button> <button type="button" class="btn" style="color:#b42318;border-color:#f2b8b5" onclick="deleteFeeRecord('+Number(x.id)+')" title="Delete Fee Record">🗑 Delete</button></td>'+
   '</tr>';
 }).join('');
 $('feeRecordTable').innerHTML='<div style="overflow:auto">'+
   '<table style="width:100%;border-collapse:collapse;min-width:1050px">'+
   '<thead><tr>'+['Receipt','Member','Plan','Shift','Amount','Date / Month','Mode','Status','Action'].map(h=>'<th style="text-align:left;padding:11px 9px;background:#f5f7fb;border-bottom:1px solid #e3e8f1;white-space:nowrap">'+h+'</th>').join('')+
   '</tr></thead><tbody>'+ (rowsHtml||'<tr><td colspan="9" style="padding:22px;text-align:center;color:#68748d">No fee records found for the selected filters.</td></tr>')+'</tbody></table></div>';
};

window.feesIncome=async function feesIncome(){
 const monthDefault=new Date().toISOString().slice(0,7);
 $('content').innerHTML=card(
  feeNav('income')+
  '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">'+
   '<div><h2 style="margin:0">Monthly Income</h2><p style="color:#68748d;margin:5px 0 0">Select a date to view every transaction collected on that day.</p></div>'+
   '<div>'+input('Select Month','incomeMonth',monthDefault,'month')+'</div>'+
  '</div>'+
  '<div id="incomeResult" style="margin-top:18px">Loading…</div>'
 );
 await loadFeesIncome();
};

window.loadFeesIncome=async function(){
 const month=$('incomeMonth')?.value||new Date().toISOString().slice(0,7);
 try{
  const d=await req('payments_api.php?action=monthly_income&month='+encodeURIComponent(month));
  const days=d.days||[];
  window._incomeDays=days;
  const dayMap={};days.forEach(x=>dayMap[x.payment_date]=x);
  const total=Number(d.total_income||0), tx=Number(d.total_transactions||0);
  const monthDate=new Date(month+'-01T00:00:00');
  const year=monthDate.getFullYear(), mon=monthDate.getMonth();
  const first=new Date(year,mon,1).getDay();
  const count=new Date(year,mon+1,0).getDate();
  let calendar='';
  for(let i=0;i<first;i++)calendar+='<div></div>';
  for(let day=1;day<=count;day++){
    const ds=year+'-'+String(mon+1).padStart(2,'0')+'-'+String(day).padStart(2,'0');
    const x=dayMap[ds], amount=Number(x?.total_income||0), n=Number(x?.transactions||0);
    calendar+='<button type="button" onclick="showIncomeDate(\''+ds+'\')" style="min-height:82px;text-align:left;padding:9px;border:1px solid '+(x?'#cbd7f6':'#e7ebf2')+';border-radius:10px;background:'+(x?'#f5f8ff':'#fff')+';cursor:pointer">'+
      '<div style="font-weight:900">'+day+'</div>'+
      (x?'<div style="color:#18794e;font-weight:900;margin-top:6px">'+money(amount)+'</div><small style="color:#68748d">'+n+' transaction'+(n===1?'':'s')+'</small>':'<small style="color:#a0a8b5;display:block;margin-top:18px">No collection</small>')+
     '</button>';
  }
  const weekdays=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(x=>'<div style="font-size:12px;font-weight:900;color:#68748d;text-align:center">'+x+'</div>').join('');
  $('incomeResult').innerHTML=
    '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px">'+feeKpi('Monthly Income',money(total))+feeKpi('Transactions',tx)+'</div>'+
    '<div style="background:#fff;border:1px solid #e7ebf2;border-radius:14px;padding:14px">'+
      '<div style="display:grid;grid-template-columns:repeat(7,minmax(70px,1fr));gap:7px">'+weekdays+calendar+'</div>'+
    '</div>'+
    '<div id="incomeDayResult" style="margin-top:18px"><div style="padding:16px;background:#f7f9fc;border-radius:12px;color:#68748d">Click any date above to view all transactions for that date.</div></div>';
  if($('incomeMonth'))$('incomeMonth').onchange=()=>loadFeesIncome();
 }catch(e){$('incomeResult').innerHTML=card('<b>Unable to load monthly income</b><p>'+esc(e.message)+'</p>')}
};

window.showIncomeDate=function(date){
 const days=window._incomeDays||null;
 if(!days){loadIncomeMonthDataForDate(date);return}
 renderIncomeDate(date,days);
};
async function loadIncomeMonthDataForDate(date){
 try{
  const month=String(date).slice(0,7),d=await req('payments_api.php?action=monthly_income&month='+encodeURIComponent(month));
  window._incomeDays=d.days||[];
  renderIncomeDate(date,window._incomeDays);
 }catch(e){if($('incomeDayResult'))$('incomeDayResult').innerHTML=card('<b>Unable to load transactions</b><p>'+esc(e.message)+'</p>')}
}
function renderIncomeDate(date,days){
 const x=(days||[]).find(a=>a.payment_date===date);
 const payments=x?.payments||[];
 const rows=payments.map(p=>'<tr>'+
   '<td style="padding:11px 9px"><b>'+esc(p.receipt_no||'—')+'</b></td>'+
   '<td style="padding:11px 9px"><b>'+esc(p.member_name||p.member_code||'—')+'</b><br><small style="color:#7d8799">'+esc(p.member_code||'')+'</small></td>'+
   '<td style="padding:11px 9px">'+money(p.amount)+'</td>'+
   '<td style="padding:11px 9px">'+feeMode(p.payment_method)+'</td>'+
   '<td style="padding:11px 9px">'+esc(p.payment_type||'Full')+'</td>'+
   '<td style="padding:11px 9px"><a class="btn" target="_blank" href="receipt.php?id='+Number(p.id)+'">🧾 Receipt</a></td>'+
  '</tr>').join('');
 $('incomeDayResult').innerHTML=
   '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">'+
    '<div><h3 style="margin:0">Transactions — '+feeDate(date)+'</h3><p style="color:#68748d;margin:4px 0 0">'+payments.length+' transaction'+(payments.length===1?'':'s')+'</p></div>'+
    '<div style="font-size:24px;font-weight:900">'+money(x?.total_income||0)+'</div>'+
   '</div>'+
   '<div style="overflow:auto"><table style="width:100%;border-collapse:collapse;min-width:720px"><thead><tr>'+
    ['Receipt','Member','Amount','Mode','Type','Action'].map(h=>'<th style="text-align:left;padding:10px;background:#f5f7fb;white-space:nowrap">'+h+'</th>').join('')+
   '</tr></thead><tbody>'+(rows||'<tr><td colspan="6" style="padding:18px;text-align:center;color:#68748d">No transactions on this date.</td></tr>')+'</tbody></table></div>';
}

window.collectDue=async function(id){try{const m=await req('members_api.php?action=list');const x=(m.members||[]).find(a=>Number(a.id)===Number(id));if(!x){alert('Member not found.');return}await addPaymentForMember(x)}catch(e){alert(e.message)}};
window.feesSettings=async function feesSettings(){
 try{
  const d=await payApi('fee_settings');
  const s=d.settings||{};
  const val=(g,k)=>s[g]?.[k]??'';
  $('content').innerHTML=card(
   feeNav('settings')+
   '<h2 style="margin:0">Fee Settings</h2><p style="color:#68748d;margin:5px 0 18px">Set fees for each plan and shift type.</p>'+
   '<form id="feeSettingsForm">'+
   '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px">'+
   ['half_day','half_reserved','full_day','full_reserved'].map(g=>
    '<div style="border:1px solid #e4e9f2;border-radius:12px;padding:14px">'+
    '<h3 style="margin:0 0 12px">'+esc(g.replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase()))+'</h3>'+
    input('1 Month',g+'_one',val(g,'one_month'),'number')+
    input('3 Month',g+'_three',val(g,'three_month'),'number')+
    input('6 Month',g+'_six',val(g,'six_month'),'number')+
    '</div>').join('')+
   '</div><div style="margin-top:16px">'+buttons()+'</div></form>'
  );
  $('feeSettingsForm').onsubmit=async e=>{
   e.preventDefault();
   const f=new FormData(e.target),settings={};
   ['half_day','half_reserved','full_day','full_reserved'].forEach(g=>settings[g]={one_month:f.get(g+'_one'),three_month:f.get(g+'_three'),six_month:f.get(g+'_six')});
   try{
    const r=await payApi('save_fee_settings',{method:'POST',body:form({settings:JSON.stringify(settings)})});
    alert(r.message||'Fee settings saved successfully.');
    await feesSettings();
   }catch(x){alert(x.message)}
  };
 }catch(e){fail(e)}
};
window.feesDue=async function feesDue(){
 try{
  const d=await payApi('dues');
  const rows=d.members||[];
  window._dueRows=rows;
  $('content').innerHTML=card(
   feeNav('due')+
   '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">'+
    '<div><h2 style="margin:0">Due Fee Members</h2><p style="color:#68748d;margin:5px 0 0">Pending and expired member fees.</p></div>'+
    '<div style="font-size:22px;font-weight:900">Total Due: '+money(d.total_due)+'</div>'+
   '</div>'+ 
   '<div style="margin-top:18px">'+
   table(['Member','Phone','Plan','Shift','Valid Till','Amount Due','Action'],rows.map(x=>
    '<tr>'+
     '<td style="padding:11px 9px"><b>'+esc(x.name)+'</b><br><small style="color:#68748d">'+esc(x.member_id)+'</small></td>'+
     '<td style="padding:11px 9px">'+esc(x.phone||'—')+'</td>'+ 
     '<td style="padding:11px 9px">'+feePlanBadge(x.membership_plan)+'</td>'+ 
     '<td style="padding:11px 9px">'+esc(x.shift||'—')+'</td>'+ 
     '<td style="padding:11px 9px">'+feeDate(x.due_date||x.validity_date)+'</td>'+ 
     '<td style="padding:11px 9px"><b style="color:#b42318">'+money(x.due_amount)+'</b></td>'+ 
     '<td style="padding:11px 9px;white-space:nowrap"><button class="btn primary" onclick="collectDue('+Number(x.id)+')">Collect</button> <button class="btn" onclick="editDue('+Number(x.due_fee_id||0)+')">Edit Due</button> <button type="button" class="btn" onclick="whatsappDueById('+Number(x.id)+')">💬 WhatsApp</button></td>'+ 
    '</tr>'
   ).join('')||'<tr><td colspan="7" style="padding:22px;text-align:center;color:#68748d">No due fees found.</td></tr>')+
   '</div>'
  );
 }catch(e){fail(e)}
};
window.whatsappDueById=function(id){
 const x=(window._dueRows||[]).find(a=>Number(a.id)===Number(id));
 if(!x){alert('Due record not found.');return;}
 whatsappDue(x);
};
window.editDue=async function(id){
 if(!id){alert('No pending due record is available to edit.');return;}
 const amount=prompt('Enter new due amount:');
 if(amount===null)return;
 const n=Number(amount);
 if(!Number.isFinite(n)||n<0){alert('Please enter a valid amount.');return;}
 try{
  const d=await payApi('edit_due',{method:'POST',body:form({id,amount:n})});
  alert(d.message||'Due updated successfully.');
  await feesDue();
 }catch(e){alert(e.message)}
};
window.collectDue=async function(id){
 try{
  const m=await req('members_api.php?action=list');
  const x=(m.members||[]).find(a=>Number(a.id)===Number(id));
  if(!x){alert('Member not found.');return;}
  await addPaymentForMember(x);
 }catch(e){alert(e.message)}
};
window.addPaymentForMember=async function(x){modal('Record Fee','<form id="payForm">'+input('Member ID','member_code',x.member_id||'')+input('Member DB ID','member_id',x.id||'','hidden')+input('Fee Amount','fee_amount','','number')+input('Additional Charges','additional_charges','0','number')+input('Payment Date','payment_date',today(),'date')+select('Payment Method','payment_method',[['Cash','Cash'],['UPI','UPI'],['Online','Online'],['Bank','Bank'],['Card','Card'],['Other','Other']],'Cash')+select('Payment Type','payment_type',[['Full','Full'],['Split','Split']],'Full')+input('Plan','plan',x.membership_plan||'')+input('Notes','notes','')+buttons()+'</form>');$('payForm').onsubmit=async e=>{e.preventDefault();try{const d=await payApi('add',{method:'POST',body:new FormData(e.target)});alert(d.message+(d.receipt_no?'\nReceipt: '+d.receipt_no:''));closeModal();feesDue()}catch(z){alert(z.message)}}};

window.addPayment=async function(){const m=await req('members_api.php?action=list');modal('Record Payment','<form id="payForm">'+select('Member','member_id',m.members.map(x=>[String(x.id),x.member_id+' — '+x.name]))+input('Fee Amount','fee_amount','','number')+input('Additional Charges','additional_charges','0','number')+input('Payment Date','payment_date',today(),'date')+select('Payment Method','payment_method',[['Cash','Cash'],['UPI','UPI'],['Online','Online'],['Bank','Bank'],['Card','Card'],['Other','Other']])+select('Payment Type','payment_type',[['Full','Full'],['Split','Split']])+input('Plan','plan','')+input('Notes','notes','')+buttons()+'</form>');$('payForm').onsubmit=async e=>{e.preventDefault();try{const d=await payApi('add',{method:'POST',body:new FormData(e.target)});alert(d.message+(d.receipt_no?'\nReceipt: '+d.receipt_no:''));closeModal();fees()}catch(x){alert(x.message)}}};

async function attendance(){const d=await api('attendance&date='+today());const rows=d.attendance||[];$('content').innerHTML=card('<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap"><div><h2 style="margin:0">Attendance</h2><p style="color:#68748d">Today: '+today()+'</p></div><button class="btn primary" onclick="markAttendance()">+ Check In / Out</button></div>'+table(['Member','Phone','Check In','Check Out','Status','Action'],rows.map(x=>'<tr><td style="padding:9px">'+esc(x.name)+' ('+esc(x.member_id)+')</td><td style="padding:9px">'+esc(x.phone||'—')+'</td><td style="padding:9px">'+esc(x.check_in_time||'—')+'</td><td style="padding:9px">'+esc(x.check_out_time||'—')+'</td><td style="padding:9px">'+esc(x.status)+'</td><td style="padding:9px">'+(x.check_out?'—':'<button class="btn" onclick="checkout('+Number(x.id)+')">Check Out</button>')+'</td></tr>').join('')||'<tr><td colspan="6" style="padding:12px">No attendance today.</td></tr>')+'</div>')}
window.markAttendance=async function(){const m=await req('members_api.php?action=list');modal('Attendance','<form id="attForm">'+select('Member','member_id',m.members.filter(x=>String(x.status).toLowerCase()==='active').map(x=>[String(x.id),x.member_id+' — '+x.name]))+select('Action','action',[['in','Check In'],['out','Check Out']])+input('Remarks','remarks','')+buttons()+'</form>');$('attForm').onsubmit=async e=>{e.preventDefault();try{alert((await api('attendance',{method:'POST',body:new FormData(e.target)})).message);closeModal();attendance()}catch(x){alert(x.message)}}};window.checkout=async id=>{try{alert((await api('checkout',{method:'POST',body:form({attendance_id:id})})).message);attendance()}catch(e){alert(e.message)}};

async function enquiry(){const d=await api('enquiries');const rows=d.enquiries||[];$('content').innerHTML=card('<div style="display:flex;justify-content:space-between;align-items:center"><div><h2 style="margin:0">Enquiries</h2><p style="color:#68748d">Track prospective members and follow-ups.</p></div><button class="btn primary" onclick="newEnquiry()">+ Add Enquiry</button></div>'+table(['Name','Phone','Requirement','Follow Up','Status','Action'],rows.map(x=>'<tr><td style="padding:9px">'+esc(x.name)+'</td><td style="padding:9px">'+esc(x.phone||'—')+'</td><td style="padding:9px">'+esc(x.requirement||'—')+'</td><td style="padding:9px">'+esc(x.follow_up||'—')+'</td><td style="padding:9px">'+esc(x.status)+'</td><td style="padding:9px">'+(x.status!=='Converted'?'<button class="btn" onclick="convertEnquiry('+Number(x.id)+')">Convert</button>':'')+' <button class="btn" onclick="editEnquiry('+Number(x.id)+')">Edit</button></td></tr>').join('')||'<tr><td colspan="6" style="padding:12px">No enquiries.</td></tr>')+'</div>')}
window.newEnquiry=function(x={}){modal(x.id?'Edit Enquiry':'Add Enquiry','<form id="enqForm">'+input('Name','name',x.name||'')+input('Phone','phone',x.phone||'')+input('Requirement','requirement',x.requirement||'')+input('Follow Up','follow_up',x.follow_up||'','date')+select('Status','status',[['Open','Open'],['Contacted','Contacted'],['Converted','Converted'],['Closed','Closed']],x.status||'Open')+input('Notes','notes',x.notes||'')+buttons()+'</form>');$('enqForm').onsubmit=async e=>{e.preventDefault();const f=new FormData(e.target);if(x.id)f.append('id',x.id);try{alert((await api('enquiries',{method:'POST',body:f})).message);closeModal();enquiry()}catch(z){alert(z.message)}}};window.editEnquiry=async id=>{const d=await api('enquiries');const x=(d.enquiries||[]).find(a=>Number(a.id)===Number(id));if(x)newEnquiry(x)};window.convertEnquiry=async id=>{if(!confirm('Convert this enquiry to a member?'))return;try{alert((await req('admin_api.php?action=enquiries&convert=1',{method:'POST',body:form({id, membership_plan:'1 Month',shift:'Full Day',joining_date:today(),validity_date:today()})})).message);enquiry()}catch(e){alert(e.message)}};

async function expenses(){const d=await api('expenses');const rows=d.expenses||[];$('content').innerHTML=card('<div style="display:flex;justify-content:space-between;align-items:center"><div><h2 style="margin:0">Expenses</h2><p style="color:#68748d">This month: <b>'+money(d.month_total)+'</b></p></div><button class="btn primary" onclick="newExpense()">+ Add Expense</button></div>'+table(['Date','Category','Description','Amount','Method','Vendor','Action'],rows.map(x=>'<tr><td style="padding:9px">'+esc(x.expense_date)+'</td><td style="padding:9px">'+esc(x.category)+'</td><td style="padding:9px">'+esc(x.description||'—')+'</td><td style="padding:9px">'+money(x.amount)+'</td><td style="padding:9px">'+esc(x.payment_method)+'</td><td style="padding:9px">'+esc(x.vendor||'—')+'</td><td style="padding:9px"><button class="btn" onclick="editExpense('+Number(x.id)+')">Edit</button></td></tr>').join('')||'<tr><td colspan="7" style="padding:12px">No expenses.</td></tr>')+'</div>')};
window.newExpense=function(x={}){modal(x.id?'Edit Expense':'Add Expense','<form id="expForm">'+input('Date','expense_date',x.expense_date||today(),'date')+input('Category','category',x.category||'')+input('Description','description',x.description||'')+input('Amount','amount',x.amount||'','number')+select('Payment Method','payment_method',[['Cash','Cash'],['UPI','UPI'],['Online','Online'],['Bank','Bank'],['Card','Card'],['Other','Other']],x.payment_method||'Cash')+input('Vendor','vendor',x.vendor||'')+input('Notes','notes',x.notes||'')+buttons()+'</form>');$('expForm').onsubmit=async e=>{e.preventDefault();const f=new FormData(e.target);if(x.id)f.append('id',x.id);try{alert((await api('expenses',{method:'POST',body:f})).message);closeModal();expenses()}catch(z){alert(z.message)}}};window.editExpense=async id=>{const d=await api('expenses');const x=(d.expenses||[]).find(a=>Number(a.id)===Number(id));if(x)newExpense(x)};

async function reports(){const from=new Date(new Date().getFullYear(),new Date().getMonth(),1).toISOString().slice(0,10),to=today();const d=await api('report&type=summary&from='+from+'&to='+to);const s=d.data||{};$('content').innerHTML=card('<h2 style="margin-top:0">Reports</h2><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px">'+[['Members',s.members],['Attendance',s.attendance],['Fees',money(s.fees)],['Expenses',money(s.expenses)],['Net Income',money(s.net_income)],['Enquiries',s.enquiries]].map(x=>card('<small>'+x[0]+'</small><div style="font-size:24px;font-weight:900">'+esc(x[1])+'</div>')).join('')+'</div><div style="margin-top:18px">'+['members','fees','attendance','expenses'].map(t=>'<button class="btn" style="margin:4px" onclick="reportTable(\''+t+'\')">'+t[0].toUpperCase()+t.slice(1)+' Report</button>').join('')+'</div><div id="reportResult" style="margin-top:18px"></div></div>')};window.reportTable=async type=>{try{const from=new Date(new Date().getFullYear(),new Date().getMonth(),1).toISOString().slice(0,10),to=today();const d=await api('report&type='+type+'&from='+from+'&to='+to);const data=Array.isArray(d.data)?d.data:[];if(!data.length){$('reportResult').innerHTML=card('No records found.');return}const heads=Object.keys(data[0]);$('reportResult').innerHTML=table(heads,data.map(r=>'<tr>'+heads.map(h=>'<td style="padding:8px">'+esc(r[h])+'</td>').join('')+'</tr>').join(''))}catch(e){alert(e.message)}};

async function staff(){const d=await api('staff');const rows=d.staff||[];$('content').innerHTML=card('<div style="display:flex;justify-content:space-between;align-items:center"><div><h2 style="margin:0">Staff Management</h2><p style="color:#68748d">Manage admin and staff accounts.</p></div><button class="btn primary" onclick="newStaff()">+ Add Staff</button></div>'+table(['Staff ID','Name','Role','Status','Permissions','Action'],rows.map(x=>'<tr><td style="padding:9px">'+esc(x.staff_id)+'</td><td style="padding:9px">'+esc(x.name)+'</td><td style="padding:9px">'+esc(x.role)+'</td><td style="padding:9px">'+esc(x.status)+'</td><td style="padding:9px">'+esc((x.permissions||[]).join(', '))+'</td><td style="padding:9px"><button class="btn" onclick="editStaff('+Number(x.id)+')">Edit</button> <button class="btn" onclick="deleteStaff('+Number(x.id)+')">Delete</button></td></tr>').join(''))+'</div>')};
const permList=['dashboard','seats','members','fees','attendance','attendance_qr','enquiry','expenses','reports','backup','settings'];
window.newStaff=function(x={}){modal(x.id?'Edit Staff':'Add Staff','<form id="staffForm">'+input('Staff ID','staff_id',x.staff_id||'STF-')+input('Name','name',x.name||'')+input('Password','password','', 'password')+select('Role','role',[['staff','Staff'],['admin','Admin']],x.role||'staff')+select('Status','status',[['active','Active'],['inactive','Inactive']],x.status||'active')+'<label style="font-weight:800">Permissions</label><div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;margin:8px 0 16px">'+permList.map(p=>'<label style="font-weight:600"><input type="checkbox" name="perm" value="'+p+'" '+((x.permissions||[]).includes(p)?'checked':'')+'> '+p+'</label>').join('')+'</div>'+buttons()+'</form>');$('staffForm').onsubmit=async e=>{e.preventDefault();const f=new FormData(e.target);if(x.id)f.append('id',x.id);f.append('permissions',JSON.stringify([...e.target.querySelectorAll('input[name=perm]:checked')].map(a=>a.value)));try{alert((await api('staff',{method:'POST',body:f})).message);closeModal();staff()}catch(z){alert(z.message)}}};window.editStaff=async id=>{const d=await api('staff');const x=(d.staff||[]).find(a=>Number(a.id)===Number(id));if(x)newStaff(x)};window.deleteStaff=async id=>{if(!confirm('Delete this staff account?'))return;try{alert((await api('staff&id='+id,{method:'DELETE'})).message);staff()}catch(e){alert(e.message)}};

async function settings(){const d=await api('settings');const s=d.settings||{};$('content').innerHTML=card('<h2 style="margin-top:0">Settings</h2><form id="settingsForm"><div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'+input('Library Name','library_name',s.library_name||'AR Library')+input('Phone','phone',s.phone||'')+input('Opening Time','opening_time',s.opening_time||'06:00','time')+input('Closing Time','closing_time',s.closing_time||'20:00','time')+input('Monthly Fee','monthly_fee',s.monthly_fee||'','number')+input('Total Seats','total_seats',s.total_seats||79,'number')+input('Total Lockers','total_lockers',s.total_lockers||79,'number')+input('Morning End','morning_end',s.morning_end||'14:00','time')+input('Evening Start','evening_start',s.evening_start||'14:00','time')+input('Attendance Latitude','attendance_latitude',s.attendance_latitude||'')+input('Attendance Longitude','attendance_longitude',s.attendance_longitude||'')+input('Attendance Radius (m)','attendance_radius_meters',s.attendance_radius_meters||100,'number')+'</div>'+input('Address','address',s.address||'')+input('Receipt Footer','receipt_footer',s.receipt_footer||'')+buttons()+'</form><div style="margin-top:24px;border:1px solid #f1b5b5;background:#fff7f7;border-radius:12px;padding:16px"><h3 style="margin:0 0 6px;color:#b42318">⚠ Fresh Start — Reset All Data</h3><p style="margin:0 0 12px;color:#7a3b36">This permanently clears members, fee/payment records, attendance, seats/lockers, enquiries, expenses, messages, logs and library settings. Your current Admin account will be kept so you can log in again.</p><button type="button" class="btn" style="background:#b42318;color:#fff;border-color:#b42318" onclick="freshSystemReset()">Reset Software & Start Fresh</button></div>');$('settingsForm').onsubmit=async e=>{e.preventDefault();try{alert((await api('settings',{method:'POST',body:new FormData(e.target)})).message)}catch(x){alert(x.message)}}} 
window.freshSystemReset=async function(){
 const first=confirm('⚠ WARNING: Software ka operational data permanently clear ho jayega. Members, fees, payments, attendance, seats/lockers, enquiries, expenses aur settings reset ho jayenge. Current Admin account bachaya jayega. Kya aap continue karna chahte hain?');
 if(!first)return;
 const typed=prompt('Final confirmation ke liye exactly RESET type karein:');
 if(typed!=='RESET'){alert('Reset cancelled.');return;}
 try{
  const d=await req('members_api.php?action=reset_system',{method:'POST',body:form({confirm:'RESET'})});
  alert(d.message||'Fresh reset complete.');
  location.reload();
 }catch(e){alert(e.message)}
};
function backup(){$('content').innerHTML=card('<h2 style="margin-top:0">Backup & Restore</h2><p>Create a complete SQL backup or restore a previous backup.</p><p><a class="btn primary" href="backup.php">⬇ Download Database Backup</a></p><hr><form id="restoreForm" enctype="multipart/form-data"><label style="font-weight:800">SQL / JSON Backup File<input name="backup_file" type="file" accept=".sql,.json,application/sql,application/json" required style="display:block;margin:8px 0"></label><div id="restoreProgress" style="display:none;margin:10px 0;font-weight:800"></div>'+buttons()+'</form>');$('restoreForm').onsubmit=async e=>{e.preventDefault();if(!confirm('Restore database from this backup? A safety backup will be created first.'))return;const formEl=e.target;const progress=$('restoreProgress');const btn=formEl.querySelector('button[type=submit]');if(btn)btn.disabled=true;if(progress){progress.style.display='block';progress.textContent='Restore शुरू हो रहा है…';}try{let d=await req('restore.php',{method:'POST',body:new FormData(formEl)});if(!d.legacy_restore){alert(d.message||'Database restored successfully.');location.reload();return;}let token=d.token;let guard=0;while(!d.done){guard++;if(guard>100)throw Error('Restore progress limit reached. Please try again.');if(progress)progress.textContent='Restore चल रहा है… '+(Number(d.progress||0))+'%';await new Promise(r=>setTimeout(r,120));d=await req('restore.php',{method:'POST',body:form({legacy_action:'chunk',token})});if(d.token)token=d.token;}if(progress)progress.textContent='Restore पूरा हो गया।';alert(d.message||'Old AR Library JSON backup restored successfully.');location.reload();}catch(x){if(progress)progress.textContent='Restore रुक गया।';alert(x.message||'Restore failed.');}finally{if(btn)btn.disabled=false;}}}
async function seats(){location.href='seats.php'}
async function qr(){location.href='qr_display.php'}
window.loadPage=async function(p){p=String(p||'dashboard');active(p);const titles={dashboard:'Dashboard',seats:'Seats & Lockers',members:'Members',fees:'Fees & Payments',attendance:'Attendance',attendance_qr:'Attendance QR',enquiry:'Enquiry',expenses:'Expenses',reports:'Reports',staff:'Staff Management',backup:'Backup & Restore',settings:'Settings'};setTitle(titles[p]||'Dashboard');loading();try{if(p==='dashboard')return await loadDashboard();if(p==='members')return await members();if(p==='fees')return await window.feesRecord();if(p==='attendance')return await attendance();if(p==='enquiry')return await enquiry();if(p==='expenses')return await expenses();if(p==='reports')return await reports();if(p==='staff')return await staff();if(p==='backup')return backup();if(p==='settings')return await settings();if(p==='seats')return seats();if(p==='attendance_qr')return qr();await loadDashboard()}catch(e){console.error(e);fail(e)}};
function clock(){const e=$('headerDateTime');if(e)e.textContent=new Date().toLocaleString('en-IN',{dateStyle:'medium',timeStyle:'short'})}
document.addEventListener('DOMContentLoaded',()=>{clock();setInterval(clock,1000);if($('app')&&!$('app').classList.contains('hidden'))loadPage('dashboard')});
})();
