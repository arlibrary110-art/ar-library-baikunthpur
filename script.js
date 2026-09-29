(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const csrf = () => ($('csrf-token') ? $('csrf-token').content : '');
  const roleScreen = () => $('roleScreen');
  const loginScreen = () => $('loginScreen');
  const app = () => $('app');

  window.openLogin = function (role) {
    role = String(role || 'staff').toLowerCase() === 'admin' ? 'admin' : 'staff';
    const selected = $('selectedRole');
    const title = $('loginRoleTitle');
    const icon = $('loginRoleIcon');
    const demo = $('loginDemo');
    if (selected) selected.value = role;
    if (title) title.textContent = role === 'admin' ? 'ADMIN LOGIN' : 'STAFF LOGIN';
    if (icon) icon.textContent = role === 'admin' ? '🔐' : '👤';
    if (demo) demo.textContent = role === 'admin'
      ? 'Admin login — use your Admin Staff ID and password.'
      : 'Staff login — use your Staff ID and password.';
    if (roleScreen()) roleScreen().classList.add('hidden');
    if (loginScreen()) loginScreen().classList.remove('hidden');
    setTimeout(() => ($('staffId') || {}).focus?.(), 40);
  };

  window.showRole = function () {
    if (loginScreen()) loginScreen().classList.add('hidden');
    if (roleScreen()) roleScreen().classList.remove('hidden');
    if ($('staffId')) $('staffId').value = '';
    if ($('password')) $('password').value = '';
    const msg = $('loginMessage');
    if (msg) msg.textContent = '';
  };

  function loginMessage(text, ok) {
    let el = $('loginMessage');
    if (!el) {
      el = document.createElement('p');
      el.id = 'loginMessage';
      el.style.cssText = 'margin:12px 0 0;text-align:center;font-weight:700;font-size:13px;';
      const card = loginScreen()?.querySelector('.login-card');
      if (card) card.appendChild(el);
    }
    el.textContent = text || '';
    el.style.color = ok ? '#18804a' : '#c03955';
  }

  window.login = async function () {
    const staffId = ($('staffId')?.value || '').trim();
    const password = $('password')?.value || '';
    const role = ($('selectedRole')?.value || 'staff').toLowerCase();
    if (!staffId || !password) {
      loginMessage('Staff ID and password are required.');
      return;
    }
    const buttons = loginScreen()?.querySelectorAll('button');
    buttons?.forEach(b => b.disabled = true);
    loginMessage('Signing in…', true);
    try {
      const fd = new FormData();
      fd.append('staff_id', staffId);
      fd.append('password', password);
      fd.append('role', role);
      const r = await fetch('login.php', { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' });
      const d = await r.json();
      if (!r.ok || !d.success) throw new Error(d.message || 'Login failed.');
      if (role !== String(d.role || '').toLowerCase()) throw new Error('Selected role does not match this account.');
      if (loginScreen()) loginScreen().classList.add('hidden');
      if (roleScreen()) roleScreen().classList.add('hidden');
      if (app()) app().classList.remove('hidden');
      loginMessage('', true);
      await loadPage('dashboard');
    } catch (e) {
      console.error(e);
      loginMessage(e.message || 'Server connection error.');
    } finally {
      buttons?.forEach(b => b.disabled = false);
    }
  };

  window.logout = function () {
    window.location.href = 'logout.php';
  };

  async function api(action, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const headers = new Headers(options.headers || {});
    if (method !== 'GET' && csrf()) headers.set('X-CSRF-Token', csrf());
    const r = await fetch('admin_api.php?action=' + encodeURIComponent(action), {
      ...options, method, headers, credentials: 'same-origin', cache: 'no-store'
    });
    const d = await r.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (r.status === 401) { window.location.href = 'index.php'; throw new Error(d.message || 'Please login again.'); }
    if (!r.ok || d.success === false) throw new Error(d.message || 'Request failed.');
    return d;
  }

  function money(v) {
    return '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  }
  function esc(v) {
    return String(v ?? '').replace(/[&<>'"]/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[c]));
  }
  function setActive(page) {
    document.querySelectorAll('.nav-item[data-page]').forEach(x => x.classList.toggle('active', x.dataset.page === page));
  }
  function setTitle(title) {
    if ($('pageTitle')) $('pageTitle').textContent = title;
    if ($('dateText')) $('dateText').textContent = new Date().toLocaleDateString('en-IN', { weekday:'long', day:'numeric', month:'long', year:'numeric' });
  }
  function showError(err) {
    const content = $('content');
    if (content) content.innerHTML = '<div class="card" style="padding:24px"><h2>Unable to load this page</h2><p>' + esc(err.message || err) + '</p><button class="btn primary" type="button" onclick="loadPage(\'dashboard\')">Back to Dashboard</button></div>';
  }

  function dashboardView(d) {
    const s = d.stats || {};
    const activity = (d.activities || []).map(x => '<tr><td>' + esc(x.action) + '</td><td>' + esc(x.details) + '</td><td>' + esc(x.created_at) + '</td></tr>').join('');
    return '<div class="dashboard-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px">' +
      [['Members',s.members],['Active',s.active],['Present Today',s.present],['Checked In',s.checked_in],['Today Collection',money(s.today_paid)],['Monthly Collection',money(s.month_paid)],['Monthly Expenses',money(s.month_expenses)],['Pending Fees',money(s.pending_fees)],['Seats Available',s.available_seats + '/' + s.total_seats],['Open Enquiries',s.open_enquiries]].map(x => '<div class="card" style="padding:18px"><small style="color:#7b879d;font-weight:800">'+esc(x[0])+'</small><div style="font-size:26px;font-weight:900;margin-top:6px">'+esc(x[1])+'</div></div>').join('') +
      '</div><div class="card" style="margin-top:18px;padding:20px"><h3 style="margin-top:0">Recent Activity</h3><div style="overflow:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:8px">Action</th><th style="text-align:left;padding:8px">Details</th><th style="text-align:left;padding:8px">Time</th></tr></thead><tbody>' + (activity || '<tr><td colspan="3" style="padding:8px">No recent activity.</td></tr>') + '</tbody></table></div></div>';
  }

  async function loadDashboard() {
    const d = await api('dashboard');
    $('content').innerHTML = dashboardView(d);
  }

  async function loadStaff() {
    const d = await api('staff');
    const rows = d.staff || [];
    $('content').innerHTML = '<div class="staff-page"><div class="staff-hero"><div><div class="eyebrow">AR LIBRARY · ADMIN</div><h2>Staff Management</h2><p>Manage staff accounts and access permissions.</p></div></div><div class="card" style="padding:20px"><h3>Staff Accounts</h3><div class="staff-list">' + rows.map(x => '<div class="staff-card"><div class="staff-card-person"><div class="staff-card-avatar">'+(x.photo?'<img class="staff-avatar-img" src="'+esc(x.photo)+'" alt="">':esc(String(x.name||'S').charAt(0).toUpperCase()))+'</div><div><strong>'+esc(x.name)+'</strong><small>'+esc(x.staff_id)+'</small></div></div><div class="staff-card-meta"><span class="role-pill '+esc(x.role)+'">'+esc(x.role)+'</span><span class="status-pill '+esc(x.status)+'">'+esc(x.status)+'</span></div></div>').join('') + '</div></div></div>';
  }

  window.loadPage = async function (page) {
    page = String(page || 'dashboard');
    setActive(page);
    const titles = { dashboard:'Dashboard', staff:'Staff Management', seats:'Seats & Lockers', members:'Members', fees:'Fees & Payments', attendance:'Attendance', enquiry:'Enquiry', expenses:'Expenses', reports:'Reports', backup:'Backup & Restore', settings:'Settings' };
    setTitle(titles[page] || 'Dashboard');
    if (!$('content')) return;
    $('content').innerHTML = '<div class="card" style="padding:24px">Loading…</div>';
    try {
      if (page === 'dashboard') return await loadDashboard();
      if (page === 'staff') return await loadStaff();
      if (page === 'seats') return window.location.href = 'seats.php';
      if (page === 'attendance_qr') return window.location.href = 'qr_display.php';
      $('content').innerHTML = '<div class="card" style="padding:24px"><h2>'+esc(titles[page] || page)+'</h2><p>This module is available in the application. Its server endpoint is ready, but the client module script was missing from the uploaded project.</p></div>';
    } catch (e) {
      console.error(e);
      showError(e);
    }
  };

  function clock() {
    const el = $('headerDateTime');
    if (el) el.textContent = new Date().toLocaleString('en-IN', { dateStyle:'medium', timeStyle:'short' });
  }
  document.addEventListener('DOMContentLoaded', function () {
    clock(); setInterval(clock, 1000);
    if (app() && !app().classList.contains('hidden')) loadPage('dashboard');
  });
})();
