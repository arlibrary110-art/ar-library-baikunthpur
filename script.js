/*
=========================================================
AR LIBRARY MANAGEMENT SYSTEM
FINAL SCRIPT.JS
=========================================================
*/


const pages = {

    dashboard: {

        title: "Dashboard",

        html: () => `
            <div class="dash-hero">
                <div>
                    <span class="eyebrow">LIBRARY OVERVIEW</span>
                    <h2>Good day, <span id="dashStaffName">Team</span> 👋</h2>
                    <p>Here is your library performance at a glance.</p>
                </div>
                <div class="dash-actions">
                    <button class="dash-action" onclick="loadPage('members')">＋ Member</button>
                    <button class="dash-action" onclick="loadPage('fees')">₹ Collect Fee</button>
                    <button class="dash-action" onclick="loadPage('attendance')">✓ Attendance</button>
                    <button class="dash-action" onclick="loadPage('expenses')">＋ Expense</button>
                    <button class="dash-refresh" onclick="loadDashboard()">↻ Refresh</button>
                </div>
            </div>

            <div id="dashboardStats" class="dash-kpis"></div>

            <div class="dash-grid-2">
                <div class="dash-panel finance-card">
                    <div class="dash-panel-head"><div><span class="eyebrow">FINANCE</span><h3>Money Overview</h3></div><span class="live-pill">LIVE DATA</span></div>
                    <div id="dashboardFinance" class="finance-kpis"></div>
                </div>
                <div class="dash-panel capacity-card">
                    <div class="dash-panel-head"><div><span class="eyebrow">CAPACITY</span><h3>Seats & Lockers</h3></div></div>
                    <div id="dashboardCapacity" class="capacity-wrap"></div>
                </div>
            </div>

            <div class="dash-grid-2">
                <div class="dash-panel">
                    <div class="dash-panel-head"><div><span class="eyebrow">TREND</span><h3>Last 7 Days</h3></div><span class="muted">Collection vs Expenses</span></div>
                    <div id="dashboardTrend" class="trend-chart"></div>
                </div>
                <div class="dash-panel">
                    <div class="dash-panel-head"><div><span class="eyebrow">MEMBERSHIP</span><h3>Member Overview</h3></div></div>
                    <div id="dashboardMembership" class="membership-overview"></div>
                </div>
            </div>

            <div class="dash-panel birthday-panel">
                <div class="dash-panel-head"><div><span class="eyebrow">MEMBER CARE</span><h3>🎂 Birthday Reminders</h3></div><span class="live-pill">NEXT 7 DAYS</span></div>
                <div id="dashboardBirthdays" class="birthday-list"><div class="empty">Loading birthdays...</div></div>
            </div>

            <div class="dash-panel expiry-panel">
                <div class="dash-panel-head"><div><span class="eyebrow">MEMBERSHIP ALERTS</span><h3>⏰ Membership Expiry Reminders</h3></div><span class="live-pill">NEXT 7 DAYS</span></div>
                <div id="dashboardExpiry" class="birthday-list"><div class="empty">Loading expiry reminders...</div></div>
            </div>

            <div class="dash-panel member-message-panel">
                <div class="dash-panel-head"><div><span class="eyebrow">MEMBER NOTICE</span><h3>📢 Important Notice</h3><p class="muted" style="margin:4px 0 0">Post a message for all members. It will automatically disappear after 24 hours.</p></div></div>
                <form id="memberNoticeForm" onsubmit="postMemberNotice(event)" class="member-notice-form">
                    <input id="memberNoticeTitle" name="title" maxlength="150" placeholder="Title — e.g. Important Notice" value="Important Notice" required>
                    <textarea id="memberNoticeMessage" name="message" maxlength="2000" rows="3" placeholder="Type the message for all members..." required></textarea>
                    <div style="display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap"><small class="muted">Visible to all logged-in members for 24 hours.</small><button class="small-btn primary-btn" type="submit">📢 Post Message</button></div>
                </form>
                <div id="memberNoticeList" class="member-notice-list"><div class="empty">Loading active notices...</div></div>
            </div>

            <div class="dash-panel">
                <div class="dash-panel-head"><div><span class="eyebrow">RECENT</span><h3>Recent Activity</h3></div><button class="small-btn" type="button" onclick="loadDashboard()">↻ Refresh</button></div>
                <div class="table-wrap"><table class="table dash-activity-table">
                    <thead><tr><th>Staff</th><th>Activity</th><th>Details</th><th>Time</th></tr></thead>
                    <tbody><tr><td colspan="4" class="empty">Loading...</td></tr></tbody>
                </table></div>
            </div>
        `
    },

    seats: {

        title: "Seats & Lockers",

        html: () => `
            <div class="seat-management-pro">
                ${seatSectionPro("Morning Shift", "6:00 AM – 1:00 PM", "morning")}
                ${seatSectionPro("Evening Shift", "1:00 PM – 8:00 PM", "evening")}
                ${seatSectionPro("Full Day Shift", "6:00 AM – 8:00 PM", "full")}
                ${lockerSectionPro()}
            </div>
        `
    },

    members: {

        title: "Members",

        html: () => `
            <div class="members-page">
                <div class="members-hero">
                    <div>
                        <div class="eyebrow">MEMBER MANAGEMENT</div>
                        <h2>Members</h2>
                        <p>Manage memberships, validity, seats, fees and member profiles from one place.</p>
                    </div>
                    <div class="members-hero-actions">
                        <button class="member-action ghost" type="button" onclick="loadMembers()">↻ Refresh</button>
                        <button class="member-action primary" type="button" onclick="toggleMemberForm()">＋ Add Member</button>
                    </div>
                </div>

                <div class="member-summary-grid">
                    <div class="member-summary-card total"><div class="member-summary-icon">👥</div><div><span>Total Members</span><strong id="memberStatTotal">0</strong><small>All registered members</small></div></div>
                    <div class="member-summary-card active"><div class="member-summary-icon">✓</div><div><span>Active</span><strong id="memberStatActive">0</strong><small>Currently valid</small></div></div>
                    <div class="member-summary-card expiring"><div class="member-summary-icon">⏳</div><div><span>Expiring Soon</span><strong id="memberStatExpiring">0</strong><small>Within 7 days</small></div></div>
                    <div class="member-summary-card expired"><div class="member-summary-icon">!</div><div><span>Expired</span><strong id="memberStatExpired">0</strong><small>Validity ended</small></div></div>
                </div>

                <div class="panel member-add-card hidden" id="memberFormCard">
                    <div class="panel-head member-section-head">
                        <div><div class="eyebrow">NEW REGISTRATION</div><h3>Add New Member</h3><p class="muted">Create a member profile with plan, shift and validity details.</p></div>
                    </div>
                    <form id="memberForm" onsubmit="addMember(event)">
                        <div class="form-grid">
                            <div class="field"><label>Member ID *</label><input id="m_member_id" placeholder="MEM-001" required></div>
                            <div class="field"><label>Full Name *</label><input id="m_name" placeholder="Member name" required></div>
                            <div class="field"><label>Phone</label><input id="m_phone" placeholder="98765 43210"></div>
                            <div class="field"><label>Email</label><input id="m_email" type="email" placeholder="member@email.com"></div>
                            <div class="field"><label>Membership Plan *</label><select id="m_plan" onchange="updateValidityDate()"><option value="1 Month">1 Month</option><option value="3 Months">3 Months</option><option value="6 Months">6 Months</option><option value="12 Months">12 Months</option><option value="Custom Date">Custom Date</option></select></div>
                            <div class="field"><label>Shift *</label><select id="m_shift" required><option value="Morning Shift">Morning Shift</option><option value="Morning Shift Reserved">Morning Shift Reserved</option><option value="Evening Shift">Evening Shift</option><option value="Evening Shift Reserved">Evening Shift Reserved</option><option value="Full Day">Full Day</option><option value="Full Day Reserved">Full Day Reserved</option></select></div>
                            <div class="field"><label>Joining Date *</label><input id="m_date" type="date" onchange="updateValidityDate()" required></div>
                            <div class="field"><label>Date of Birth</label><input id="m_dob" type="date"></div>
                            <div class="field"><label>Last Validity Date *</label><input id="m_validity" type="date" required></div>
                            <div class="field" style="grid-column:1/-1"><label>Address</label><textarea id="m_address" placeholder="Address"></textarea></div>
                        </div>
                        <div class="member-form-footer"><span class="muted">Member ID should be unique.</span><button class="member-action primary" type="submit">＋ Create Member</button></div>
                    </form>
                </div>

                <div class="panel member-list-card">
                    <div class="member-list-toolbar">
                        <div><div class="eyebrow">DIRECTORY</div><h3>All Members</h3><span id="memberResultCount" class="muted">Loading...</span></div>
                        <div class="member-tools">
                            <div class="member-search"><span>⌕</span><input class="search" id="memberSearch" placeholder="Search name, ID or phone..." oninput="filterMembers(this.value)"></div>
                            <select id="memberStatusFilter" class="member-filter" onchange="filterMembers(document.getElementById('memberSearch')?.value || '')"><option value="all">All Status</option><option value="active">Active</option><option value="expiring">Expiring Soon</option><option value="expired">Expired</option></select>
                        </div>
                    </div>
                    <div class="members-cards" id="membersBody"><div class="empty member-card-empty">Loading members...</div></div>
                </div>
            </div>
        `
    },

    fees: {
        title: "Fees",
        html: () => `
            <div class="fees-module">
                <div class="fees-tabs">
                    <button class="fees-tab active" data-fee-tab="records" onclick="switchFeeTab('records')">💳 Fee Records</button>
                    <button class="fees-tab" data-fee-tab="income" onclick="switchFeeTab('income')">📅 Monthly Income</button>
                    <button class="fees-tab" data-fee-tab="settings" onclick="switchFeeTab('settings')">⚙️ Fee Setting</button>
                    <button class="fees-tab" data-fee-tab="dues" onclick="switchFeeTab('dues')">⚠️ Due Fee Members</button>
                </div>
                <div id="feeTabRecords" class="fee-tab-panel">
                    <div class="panel">
                        <div class="panel-head"><h3>All Fee Transactions</h3><div class="page-actions"><input class="search" placeholder="Search payments..." oninput="filterTable(this)"><button class="small-btn" onclick="openPaymentForm()">+ Record Payment</button></div></div>
                        <div id="feesSummary" class="summary-line">Loading...</div>
                        <div class="table-wrap"><table class="table data-table"><thead><tr><th>Receipt</th><th>Member</th><th>Plan</th><th>Fee Paid</th><th>Additional</th><th>Total</th><th>Balance Due</th><th>Type</th><th>Date</th><th>Method</th><th>Action</th></tr></thead><tbody id="feesBody"><tr><td colspan="10" class="empty">Loading payments...</td></tr></tbody></table></div>
                    </div>
                </div>
                <div id="feeTabIncome" class="fee-tab-panel hidden"><div class="panel">
                    <div class="panel-head"><h3>Monthly Income</h3><div class="page-actions"><input id="incomeMonth" type="month" class="search" onchange="loadMonthlyIncome()"></div></div>
                    <div id="incomeSummary" class="summary-line">Loading...</div>
                    <div id="incomeCalendar" class="income-calendar"></div>
                    <div id="incomeDayDetails" class="income-day-details"><div class="empty">Calendar में किसी तारीख पर क्लिक करें। उस दिन के सभी payment details यहाँ दिखेंगे।</div></div>
                </div></div>
                <div id="feeTabSettings" class="fee-tab-panel hidden"><div class="panel">
                    <div class="panel-head"><h3>Fee Structure</h3><button class="small-btn" onclick="saveFeeSettings()">Save Fee Settings</button></div>
                    <p class="summary-line">Monthly, 3 Month (5% off) and 6 Month (10% off) fee structure.</p>
                    <div id="feeSettingsBody" class="table-wrap"><div class="empty">Loading fee settings...</div></div>
                </div></div>
                <div id="feeTabDues" class="fee-tab-panel hidden"><div class="panel">
                    <div class="panel-head"><h3>⚠️ Due Fee Members</h3><button class="small-btn" onclick="loadDueFees()">↻ Refresh</button></div>
                    <div id="duesSummary" class="summary-line">Loading...</div>
                    <div class="table-wrap"><table class="table"><thead><tr><th>Member ID</th><th>Name</th><th>Phone</th><th>Plan</th><th>Validity Date</th><th>Due Amount</th><th>Action</th></tr></thead><tbody id="duesBody"><tr><td colspan="7" class="empty">Loading...</td></tr></tbody></table></div>
                </div></div>
            </div>`
    },
    attendance: {
        title: "Attendance",
        html: () => `
            <div class="attendance-page">
                <div class="grid attendance-stats">
                    <div class="stat"><div class="s-icon">👥</div><p>Total Records</p><h2 id="attTotal">0</h2></div>
                    <div class="stat"><div class="s-icon">🟢</div><p>Checked In</p><h2 id="attOpen">0</h2></div>
                    <div class="stat"><div class="s-icon">🔵</div><p>Completed</p><h2 id="attCompleted">0</h2></div>
                    <div class="stat"><div class="s-icon">🕐</div><p>Selected Date</p><h2 id="attDateLabel">Today</h2></div>
                </div>
                <div class="panel">
                    <div class="panel-head">
                        <div><h3>📋 Attendance</h3><div id="attendanceSummary" class="summary-line">Loading...</div></div>
                        <div class="page-actions attendance-toolbar">
                            <input id="attendanceDate" type="date" onchange="loadAttendance()">
                            <input class="search" placeholder="Search member..." oninput="filterTable(this)">
                            <button class="small-btn" onclick="openAttendanceForm()">+ Mark Attendance</button>
                            <button class="small-btn" onclick="loadAttendance()">↻ Refresh</button>
                        </div>
                    </div>
                    <div class="table-wrap"><table class="table data-table"><thead><tr><th>Member ID</th><th>Name</th><th>Phone</th><th>Shift</th><th>Check In</th><th>Check Out</th><th>Duration</th><th>Status</th><th>Action</th></tr></thead><tbody id="attendanceBody"><tr><td colspan="9" class="empty">Loading attendance...</td></tr></tbody></table></div>
                </div>
            </div>`
    },
    enquiry: {
        title: "Enquiry",
        html: () => `
            <div class="panel"><div class="panel-head"><h3>Enquiries</h3><div class="page-actions"><input class="search" placeholder="Search..." oninput="filterTable(this)"><button class="small-btn" onclick="openEnquiryForm()">+ New Enquiry</button></div></div><div class="table-wrap"><table class="table data-table"><thead><tr><th>Name</th><th>Phone</th><th>Requirement</th><th>Follow-up</th><th>Status</th><th>Action</th></tr></thead><tbody id="enquiryBody"><tr><td colspan="6" class="empty">Loading...</td></tr></tbody></table></div></div>`
    },
    expenses: {
        title: "Expenses",
        html: () => `
            <div class="panel"><div class="panel-head"><h3>Expenses</h3><div class="page-actions"><input class="search" placeholder="Search..." oninput="filterTable(this)"><button class="small-btn" onclick="openExpenseForm()">+ Add Expense</button></div></div><div id="expenseSummary" class="summary-line">Loading...</div><div class="table-wrap"><table class="table data-table"><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Payment</th><th>Action</th></tr></thead><tbody id="expenseBody"><tr><td colspan="6" class="empty">Loading...</td></tr></tbody></table></div></div>`
    },
    reports: {
        title: "Reports",
        html: () => `
            <div class="panel"><div class="panel-head"><h3>Reports</h3><div class="page-actions"><label>From <input id="reportFrom" type="date"></label><label>To <input id="reportTo" type="date"></label></div></div><div class="grid report-grid">${[['members','👥','Members Report'],['fees','💰','Fees Report'],['attendance','📋','Attendance Report'],['expenses','💸','Expenses Report'],['summary','📊','Management Summary']].map(r=>`<div class="stat" style="cursor:pointer" onclick="runReport('${r[0]}')"><div class="s-icon">${r[1]}</div><p>${r[2]}</p><h2>View →</h2></div>`).join('')}</div><div id="reportOutput" class="panel-inner"></div></div>`
    },
    backup: {
        title: "Backup & Restore",
        html: () => `<div class="grid two-col">
            <div class="panel">
                <div class="panel-head"><div><h3>💾 Database Backup</h3><p class="muted">Download a complete SQL backup of your current library database.</p></div><button class="small-btn" onclick="createBackup()">⬇ Create Backup</button></div>
                <div class="backup-card"><div class="s-icon">🛡️</div><h3>Keep a safe copy</h3><p class="muted">Create a backup before major changes, upgrades or server migration.</p></div>
            </div>
            <div class="panel">
                <div class="panel-head"><div><h3>♻️ Restore Database</h3><p class="muted">Restore the library database from a previously downloaded .sql backup.</p></div></div>
                <form id="restoreForm" onsubmit="restoreBackup(event)" class="restore-box" enctype="multipart/form-data">
                    <label class="upload-box"><span>📄</span><b>Select SQL backup</b><small>Only .sql files, maximum 20 MB</small><input id="restoreFile" name="backup_file" type="file" accept=".sql,application/sql" required onchange="showRestoreFile(this)"></label>
                    <div id="restoreFileName" class="muted">No backup selected</div>
                    <div class="warning-box">⚠️ Restore will replace the current database data. A safety backup will be created automatically before restore.</div>
                    <button id="restoreBtn" type="submit" class="danger-btn">♻ Restore Selected Backup</button>
                </form>
            </div>
        </div>`
    },
    staff: {
        title: "Staff Management",
        html: () => `
            <div class="staff-page">
                <div class="staff-hero">
                    <div><span class="eyebrow">ADMIN ONLY</span><h2>Staff Management</h2><p>Add staff accounts, set their login ID/password and upload a profile photo.</p></div>
                    <button class="member-action primary" type="button" onclick="openStaffForm()">＋ Add Staff</button>
                </div>
                <div class="panel staff-form-panel hidden" id="staffFormPanel">
                    <div class="panel-head"><div><h3 id="staffFormTitle">Add Staff</h3><p class="muted">Staff ID, name, password and profile photo.</p></div><button class="small-btn" type="button" onclick="closeStaffForm()">Close</button></div>
                    <form id="staffForm" class="form-grid staff-form-grid" enctype="multipart/form-data" onsubmit="saveStaff(event)">
                        <input type="hidden" name="id" id="staff_id_db" value="0">
                        <div class="field"><label>Staff ID *</label><input name="staff_id" id="staff_id_input" required maxlength="50" placeholder="e.g. STF-002"></div>
                        <div class="field"><label>Full Name *</label><input name="name" id="staff_name_input" required maxlength="150" placeholder="Staff name"></div>
                        <div class="field"><label>Password <span id="staffPasswordHint" class="muted">*</span></label><input name="password" id="staff_password_input" type="password" minlength="4" autocomplete="new-password" placeholder="Minimum 4 characters"></div>
                        <div class="field"><label>Role</label><select name="role" id="staff_role_input"><option value="staff">Staff</option><option value="admin">Admin</option></select></div>
                        <div class="field"><label>Status</label><select name="status" id="staff_status_input"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                        <div class="field staff-photo-field"><label>Profile Photo</label><input name="photo" id="staff_photo_input" type="file" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG or WEBP · Max 2 MB</small><div id="staffPhotoPreview" class="staff-photo-preview"></div></div>
                        <div class="field" style="grid-column:1/-1"><label>Module Access</label><p class="muted" style="margin:0 0 10px">Select which modules this staff member can open. Admin accounts always have full access.</p><div class="staff-permission-tools"><button type="button" class="small-btn" onclick="setAllStaffPermissions(true)">Select All</button><button type="button" class="small-btn" onclick="setAllStaffPermissions(false)">Clear All</button></div><div id="staffPermissions" class="staff-permission-grid"></div></div>
                        <div class="staff-form-actions"><button class="member-action primary" type="submit">Save Staff</button><button class="member-action" type="button" onclick="closeStaffForm()">Cancel</button></div>
                    </form>
                </div>
                <div class="panel"><div class="panel-head"><div><h3>Staff Accounts</h3><p class="muted">Admin can create and manage staff login accounts.</p></div><button class="small-btn" type="button" onclick="loadStaff()">↻ Refresh</button></div><div id="staffList" class="staff-list"><div class="empty">Loading...</div></div></div>
            </div>`
    },

    settings: {
        title: "Settings",
        html: () => `<div class="panel"><div class="panel-head"><h3>Library Settings</h3><button class="small-btn" onclick="saveSettings()">Save Settings</button><button class="small-btn danger-btn" onclick="performFreshReset()">🗑️ Fresh Start / Reset Old Data</button></div><form id="settingsForm" class="form-grid" style="margin-top:18px"><div class="field"><label>Library Name</label><input id="set_library_name" name="library_name"></div><div class="field"><label>Contact Number</label><input id="set_phone" name="phone"></div><div class="field"><label>Opening Time</label><input id="set_opening_time" name="opening_time" type="time"></div><div class="field"><label>Closing Time</label><input id="set_closing_time" name="closing_time" type="time"></div><div class="field"><label>Monthly Fee</label><input id="set_monthly_fee" name="monthly_fee" type="number" min="0" step="0.01"></div><div class="field"><label>Total Seats</label><input id="set_total_seats" name="total_seats" type="number" min="1"></div><div class="field"><label>Total Lockers</label><input id="set_total_lockers" name="total_lockers" type="number" min="0"></div><div class="field"><label>Morning Shift End</label><input id="set_morning_end" name="morning_end" type="time"></div><div class="field"><label>Evening Shift Start</label><input id="set_evening_start" name="evening_start" type="time"></div><div class="field"><label>Currency</label><select id="set_currency" name="currency"><option value="INR">INR (₹)</option><option value="USD">USD ($)</option></select></div><div class="field"><label>Address</label><textarea id="set_address" name="address"></textarea></div><div class="field"><label>Receipt Footer</label><input id="set_receipt_footer" name="receipt_footer"></div><div class="settings-section" style="grid-column:1/-1;margin-top:18px;padding:18px;border:1px solid var(--border,#ddd);border-radius:12px"><h3 style="margin:0 0 6px">📍 Attendance Location</h3><p class="muted" style="margin:0 0 14px">Attendance is allowed only within the configured radius of AR Library.</p><div class="form-grid"><div class="field"><label>Library Latitude</label><input id="set_attendance_latitude" name="attendance_latitude" type="number" step="0.000001"></div><div class="field"><label>Library Longitude</label><input id="set_attendance_longitude" name="attendance_longitude" type="number" step="0.000001"></div><div class="field"><label>Allowed Radius (meters)</label><input id="set_attendance_radius_meters" name="attendance_radius_meters" type="number" min="1" step="1"></div></div></div></form></div>`
    }


};


/*
=========================================================
STAT
=========================================================
*/

function stat(icon, title, value, sub, cls = "") {

    return `

        <div class="stat">

            <div class="s-icon">
                ${icon}
            </div>

            <p>
                ${title}
            </p>

            <h2>
                ${value}
            </h2>

            <span class="${cls}">
                ${sub}
            </span>

        </div>

    `;

}


/*
=========================================================
GENERIC TABLE
=========================================================
*/

function listPage(title, action, headers, rows) {

    return `

        <div class="panel">

            <div class="panel-head">

                <h3>${escapeHtml(title)}</h3>

                <div class="page-actions">

                    <input
                        class="search"
                        placeholder="Search..."
                        oninput="filterTable(this)"
                    >

                    <button
                        class="small-btn"
                        type="button"
                        onclick="toast('${escapeJs(action)} form opened')"
                    >
                        + ${escapeHtml(action)}
                    </button>

                </div>

            </div>


            <table class="table data-table">

                <thead>

                    <tr>

                        ${headers
                            .map(h => `<th>${escapeHtml(h)}</th>`)
                            .join("")}

                    </tr>

                </thead>


                <tbody>

                    ${rows.map(r => `

                        <tr>

                            ${r.map((v, i) => `

                                <td>

                                    ${
                                        i === r.length - 1
                                            ? `
                                                <span class="badge ${
                                                    v === "Pending" ||
                                                    v === "Absent"
                                                        ? "warn"
                                                        : ""
                                                }">
                                                    ${escapeHtml(v)}
                                                </span>
                                            `
                                            : escapeHtml(v)
                                    }

                                </td>

                            `).join("")}

                        </tr>

                    `).join("")}

                </tbody>

            </table>

        </div>

    `;

}


/*
=========================================================
LOGIN
=========================================================
*/

let selectedLoginRole = "staff";

function openLogin(role = "staff") {
    selectedLoginRole = String(role).toLowerCase() === "admin" ? "admin" : "staff";

    const roleScreen = document.getElementById("roleScreen");
    const loginScreen = document.getElementById("loginScreen");
    const selectedRole = document.getElementById("selectedRole");
    const title = document.getElementById("loginRoleTitle");
    const icon = document.getElementById("loginRoleIcon");
    const idInput = document.getElementById("staffId");
    const demo = document.getElementById("loginDemo");

    if (selectedRole) selectedRole.value = selectedLoginRole;
    if (title) title.textContent = selectedLoginRole === "admin" ? "ADMIN LOGIN" : "STAFF LOGIN";
    if (icon) icon.textContent = selectedLoginRole === "admin" ? "🔐" : "👤";
    if (idInput) idInput.placeholder = selectedLoginRole === "admin" ? "e.g. STF-001" : "e.g. STF-002";
    if (demo) demo.textContent = selectedLoginRole === "admin"
        ? "Admin login — Administrator account."
        : "Staff login — use your Staff ID and password.";

    if (roleScreen) roleScreen.classList.add("hidden");
    if (loginScreen) loginScreen.classList.remove("hidden");

    setTimeout(() => idInput?.focus(), 50);
}

function showRole() {
    const roleScreen = document.getElementById("roleScreen");
    const loginScreen = document.getElementById("loginScreen");
    if (loginScreen) loginScreen.classList.add("hidden");
    if (roleScreen) roleScreen.classList.remove("hidden");
}


async function login() {

    const staffInput =
        document.getElementById("staffId");

    const passwordInput =
        document.getElementById("password");

    if (!staffInput || !passwordInput) {
        return;
    }

    const id =
        staffInput.value.trim();

    const pw =
        passwordInput.value;

    if (!id || !pw) {

        toast(
            "Staff ID and password required"
        );

        return;
    }


    try {

        const formData =
            new FormData();

        formData.append(
            "staff_id",
            id
        );

        formData.append(
            "password",
            pw
        );

        formData.append(
            "role",
            selectedLoginRole
        );


        const response =
            await fetch(
                "login.php",
                {
                    method: "POST",
                    body: formData
                }
            );


        const data =
            await response.json();


        if (data.success) {

            document
                .getElementById("loginScreen")
                ?.classList.add("hidden");

            document
                .getElementById("roleScreen")
                ?.classList.add("hidden");

            document
                .getElementById("app")
                ?.classList.remove("hidden");


            loadPage("dashboard");

            toast("Login successful");

        } else {

            toast(
                data.message ||
                "Login failed"
            );

        }

    } catch (error) {

        console.error(error);

        toast(
            "Server connection error"
        );

    }

}


/*
=========================================================
LOGOUT
=========================================================
*/

function logout() {

    window.location.href =
        "logout.php";

}


/*
=========================================================
LOAD PAGE
=========================================================
*/

function loadPage(name) {

    const role=(document.body.dataset.role||'').toLowerCase();
    if(role!=='admin'){ let allowed=[]; try{allowed=JSON.parse(document.body.dataset.permissions||'[]');}catch(e){} if(!allowed.includes(name)){ toast('Admin has not granted access to this module.'); return; } }

    const page =
        pages[name];

    if (!page) {

        console.error(
            "Page not found:",
            name
        );

        return;
    }


    const pageTitle =
        document.getElementById("pageTitle");

    const content =
        document.getElementById("content");


    if (pageTitle) {
        pageTitle.textContent =
            page.title;
    }


    if (content) {
        content.innerHTML =
            page.html();
    }

    if (name === "dashboard") loadDashboard();
    if (name === "seats") loadSeats();
    if (name === "fees") loadPayments();
    if (name === "attendance") loadAttendance();
    if (name === "enquiry") loadEnquiries();
    if (name === "expenses") loadExpenses();
    if (name === "reports") initReports();
    if (name === "settings") loadSettings();
    if (name === "staff") loadStaff();


    document
        .querySelectorAll(".nav-item")
        .forEach(item => {

            item.classList.toggle(
                "active",
                item.dataset.page === name
            );

        });


    if (name === "members") {

        setTodayAsJoiningDate();

        updateValidityDate();

        loadMembers();

    }

}


/*
=========================================================
TODAY
=========================================================
*/

function setTodayAsJoiningDate() {

    const dateInput =
        document.getElementById("m_date");

    if (!dateInput) {
        return;
    }


    if (!dateInput.value) {

        const today =
            new Date();

        const year =
            today.getFullYear();

        const month =
            String(
                today.getMonth() + 1
            ).padStart(2, "0");

        const day =
            String(
                today.getDate()
            ).padStart(2, "0");


        dateInput.value =
            `${year}-${month}-${day}`;

    }

}


/*
=========================================================
VALIDITY
=========================================================
*/

function updateValidityDate() {

    const plan =
        document.getElementById("m_plan");

    const joining =
        document.getElementById("m_date");

    const validity =
        document.getElementById("m_validity");


    if (!plan || !joining || !validity) {
        return;
    }


    if (!joining.value) {

        validity.value = "";

        return;
    }


    if (plan.value === "Custom Date") {

        validity.readOnly = false;

        return;
    }


    const months = {

        "1 Month": 1,

        "2 Months": 2,

        "3 Months": 3,

        "6 Months": 6,

        "12 Months": 12

    };


    const monthCount =
        months[plan.value];


    if (!monthCount) {

        validity.value = "";

        validity.readOnly = false;

        return;
    }


    const date =
        new Date(
            joining.value + "T00:00:00"
        );


    date.setMonth(
        date.getMonth() + monthCount
    );


    const year =
        date.getFullYear();

    const month =
        String(
            date.getMonth() + 1
        ).padStart(2, "0");

    const day =
        String(
            date.getDate()
        ).padStart(2, "0");


    validity.value =
        `${year}-${month}-${day}`;

    validity.readOnly = true;

}


/*
=========================================================
LOAD MEMBERS
=========================================================
*/

async function loadMembers() {
    const body = document.getElementById("membersBody");
    if (!body) return;
    body.innerHTML = `<div class="empty member-card-empty">Loading members...</div>`;
    try {
        const response = await fetch("members_api.php?action=list", {cache:"no-store"});
        const data = await response.json();
        if (!data.success) throw new Error(data.message || "Could not load members.");
        window.__membersData = data.members || [];
        updateMemberSummary(window.__membersData);
        renderMembersTable(window.__membersData);
    } catch (error) {
        console.error("Load members error:", error);
        body.innerHTML = `<div class="empty member-card-empty">${escapeHtml(error.message || "Could not connect to database.")}</div>`;
        const count = document.getElementById("memberResultCount"); if (count) count.textContent = "Unable to load members";
    }
}

function updateMemberSummary(members) {
    const today = new Date(); today.setHours(0,0,0,0);
    const expLimit = new Date(today); expLimit.setDate(expLimit.getDate()+7);
    let active=0, expired=0, expiring=0;
    members.forEach(m => {
        const d = m.validity_date ? new Date(String(m.validity_date)+"T00:00:00") : null;
        if (d && d < today) expired++;
        else if (d && d <= expLimit) expiring++;
        else active++;
    });
    const set=(id,v)=>{const el=document.getElementById(id);if(el)el.textContent=v;};
    set("memberStatTotal", members.length); set("memberStatActive", active); set("memberStatExpiring", expiring); set("memberStatExpired", expired);
}

function memberStatusKey(member) {
    const today = new Date(); today.setHours(0,0,0,0);
    const limit = new Date(today); limit.setDate(limit.getDate()+7);
    const d = member.validity_date ? new Date(String(member.validity_date)+"T00:00:00") : null;
    if (d && d < today) return "expired";
    if (d && d <= limit) return "expiring";
    return "active";
}

function renderMembersTable(members) {
    const body=document.getElementById("membersBody"); if(!body)return;
    const q=(document.getElementById("memberSearch")?.value||"").toLowerCase().trim();
    const status=(document.getElementById("memberStatusFilter")?.value||"all").toLowerCase();
    const rows=(members||[]).filter(m=>{
        const hay=[m.member_id,m.name,m.phone,m.email,m.membership_plan,m.shift].join(" ").toLowerCase();
        return (!q || hay.includes(q)) && (status==="all" || memberStatusKey(m)===status);
    });
    const count=document.getElementById("memberResultCount"); if(count) count.textContent=`Showing ${rows.length} of ${(members||[]).length} members`;
    if(!rows.length){body.innerHTML=`<div class="empty member-card-empty">No members match your filters.</div>`;return;}
    body.innerHTML=rows.map(member=>{
        const statusKey=memberStatusKey(member);
        const statusLabel=statusKey==='expiring'?'Expiring Soon':statusKey.charAt(0).toUpperCase()+statusKey.slice(1);
        const initial=escapeHtml((member.name||"M").trim().charAt(0).toUpperCase());
        return `<article class="member-profile-card">
            <div class="member-card-top">
                <div class="member-person member-card-person"><div class="member-avatar">${initial}</div><div><strong>${escapeHtml(member.name||"—")}</strong><small>${escapeHtml(member.member_id||"—")}</small></div></div>
                <span class="member-status ${statusKey}"><i></i>${statusLabel}</span>
            </div>
            <div class="member-card-contact"><span>📱 ${escapeHtml(member.phone||"No phone")}</span>${member.email?`<span>✉️ ${escapeHtml(member.email)}</span>`:''}</div>
            <div class="member-card-details">
                <div><small>PLAN</small><b>${escapeHtml(member.membership_plan||"—")}</b></div>
                <div><small>SHIFT</small><b>${escapeHtml(member.shift||"Full Day")}</b></div>
                <div><small>VALID TILL</small><b>${escapeHtml(member.validity_date||"—")}</b></div>
            </div>
            <div class="member-card-footer">
                <div class="member-card-meta">${member.seat_number?`💺 Seat ${escapeHtml(member.seat_number)}`:'💺 No seat'} ${member.locker_number?` · 🔐 Locker ${escapeHtml(member.locker_number)}`:' · 🔐 No locker'}</div>
                <div class="member-row-actions"><button class="member-row-btn view" type="button" onclick="viewMember(${Number(member.id)})">View</button><button class="member-row-btn edit" type="button" onclick="editMember(${Number(member.id)})">Edit</button>${String(document.body.dataset.role||'').toLowerCase()==='admin'?`<button class="member-row-btn" type="button" onclick="repairInitialDue(${Number(member.id)})">💰 Repair Due</button><button class="member-row-btn delete" type="button" onclick="deleteMember(${Number(member.id)}, '${escapeJs(member.name)}')">Delete</button>`:''}</div>
            </div>
        </article>`;
    }).join("");
}

function toggleMemberForm(force) {
    const card=document.getElementById('memberFormCard');
    if(!card) return;
    const shouldOpen = typeof force === 'boolean' ? force : card.classList.contains('hidden');
    card.classList.toggle('hidden', !shouldOpen);
    const btn=document.querySelector('.members-hero-actions .member-action.primary');
    if(btn) btn.textContent = shouldOpen ? '✕ Close Registration' : '＋ Add Member';
    if(shouldOpen) card.scrollIntoView({behavior:'smooth',block:'start'});
}


/*
=========================================================
VIEW MEMBER
=========================================================
*/

function viewMember(id) {

    if (!id || Number(id) <= 0) {

        toast(
            "Invalid member ID"
        );

        return;
    }


    window.location.href =
        "member_profile.php?id=" +
        encodeURIComponent(id);

}


/*
=========================================================
EDIT MEMBER
=========================================================
*/

function editMember(id) {
    if (!id || Number(id) <= 0) {
        toast("Invalid member ID");
        return;
    }

    window.location.href =
        "member_profile.php?id=" +
        encodeURIComponent(id) +
        "#edit";
}


/*
=========================================================
ADD MEMBER
=========================================================
*/

async function addMember(event) {

    event.preventDefault();


    const memberId =
        document
            .getElementById("m_member_id")
            .value
            .trim();


    const name =
        document
            .getElementById("m_name")
            .value
            .trim();


    const phone =
        document
            .getElementById("m_phone")
            .value
            .trim();


    const email =
        document
            .getElementById("m_email")
            .value
            .trim();


    const plan =
        document
            .getElementById("m_plan")
            .value;


    const shift =
        document
            .getElementById("m_shift")
            .value;


    const joiningDate =
        document
            .getElementById("m_date")
            .value;


    const validityDate =
        document
            .getElementById("m_validity")
            .value;


    const address =
        document
            .getElementById("m_address")
            .value
            .trim();


    if (!memberId) {

        toast(
            "Member ID is required"
        );

        return;
    }


    if (!name) {

        toast(
            "Member name is required"
        );

        return;
    }


    if (!shift) {

        toast(
            "Please select shift"
        );

        return;
    }


    if (!joiningDate) {

        toast(
            "Please select joining date"
        );

        return;
    }


    if (!validityDate) {

        toast(
            "Please select validity date"
        );

        return;
    }


    const form =
        new FormData();


    form.append(
        "member_id",
        memberId
    );

    form.append(
        "name",
        name
    );

    form.append(
        "phone",
        phone
    );

    form.append(
        "email",
        email
    );

    form.append(
        "membership_plan",
        plan
    );

    form.append(
        "shift",
        shift
    );

    form.append(
        "joining_date",
        joiningDate
    );

    form.append(
        "validity_date",
        validityDate
    );

    form.append(
        "date_of_birth",
        document.getElementById("m_dob")?.value || ""
    );

    form.append(
        "address",
        address
    );


    try {

        const response =
            await fetch(
                "members_api.php?action=add",
                {
                    method: "POST",
                    body: form
                }
            );


        const raw = await response.text();
        let data;

        try {
            data = JSON.parse(raw);
        } catch (parseError) {
            console.error("Add member invalid JSON:", raw);
            toast("Server response error. Member save cancelled.");
            return;
        }


        if (data.success) {

            toast(
                data.due_amount
                    ? "Member added + Fee Due created"
                    : "Member added successfully"
            );


            const memberForm =
                document.getElementById(
                    "memberForm"
                );


            if (memberForm) {
                memberForm.reset();
            }


            setTodayAsJoiningDate();

            updateValidityDate();

            loadMembers();


        } else {

            toast(
                data.message ||
                "Could not add member"
            );

        }


    } catch (error) {

        console.error(
            "Add member error:",
            error
        );


        toast(
            "Could not save member"
        );

    }

}


/*
=========================================================
EDIT MEMBER
=========================================================
*/
/*
=========================================================
DELETE MEMBER
=========================================================
*/

async function repairInitialDue(id) {
    if (!id) { toast("Invalid member ID"); return; }
    if (!confirm("Is member ke liye missing Fee Due create karein?")) return;
    try {
        const f = new FormData();
        f.append("id", id);
        const response = await fetch("members_api.php?action=repair_initial_due", {method:"POST", body:f});
        const raw = await response.text();
        let data;
        try { data = JSON.parse(raw); } catch (e) { console.error(raw); toast("Server response error"); return; }
        toast(data.message || (data.success ? "Fee Due created" : "Could not create Fee Due"));
        if (data.success) loadMembers();
    } catch (e) {
        console.error(e);
        toast("Could not create Fee Due");
    }
}


async function deleteMember(id, name) {

    if (
        !confirm(
            "Delete member: " +
            name +
            " ?"
        )
    ) {
        return;
    }


    const form =
        new FormData();


    form.append(
        "id",
        id
    );


    try {

        const response =
            await fetch(
                "members_api.php?action=delete",
                {
                    method: "POST",
                    body: form
                }
            );


        const data =
            await response.json();


        if (data.success) {

            toast(
                "Member deleted"
            );

            loadMembers();

        } else {

            toast(
                data.message ||
                "Could not delete member"
            );

        }


    } catch (error) {

        console.error(error);

        toast(
            "Could not delete member"
        );

    }

}


/*
=========================================================
SEARCH MEMBERS
=========================================================
*/

function filterMembers(query) {
    if (document.getElementById("membersBody")) renderMembersTable(window.__membersData || []);
}


/*
=========================================================
HTML ESCAPE
=========================================================
*/

function escapeHtml(value) {

    return String(
        value ?? ""
    ).replace(
        /[&<>"']/g,
        character => ({

            "&": "&amp;",

            "<": "&lt;",

            ">": "&gt;",

            '"': "&quot;",

            "'": "&#039;"

        }[character])
    );

}


/*
=========================================================
JS ESCAPE
=========================================================
*/

function escapeJs(value) {

    return String(
        value ?? ""
    )
    .replace(/\\/g, "\\\\")
    .replace(/'/g, "\\'");

}


/*
=========================================================
TABLE SEARCH
=========================================================
*/

function filterTable(input) {

    if (!input) {
        return;
    }


    const q =
        input.value
            .toLowerCase();


    const panel =
        input.closest(".panel");


    if (!panel) {
        return;
    }


    panel
        .querySelectorAll(
            "tbody tr"
        )
        .forEach(row => {

            row.style.display =
                row.innerText
                    .toLowerCase()
                    .includes(q)
                        ? ""
                        : "none";

        });

}


/*
=========================================================
TOAST
=========================================================
*/

function toast(msg) {

    let t =
        document.getElementById(
            "toast"
        );


    if (!t) {

        t =
            document.createElement(
                "div"
            );


        t.id =
            "toast";


        t.style.cssText = `

            position:fixed;
            right:25px;
            bottom:25px;
            background:#26365b;
            color:white;
            padding:13px 18px;
            border-radius:12px;
            z-index:9999;
            font-weight:800;
            box-shadow:0 8px 25px rgba(0,0,0,.2);

        `;


        document.body.appendChild(t);

    }


    t.textContent =
        msg;


    t.style.display =
        "block";


    clearTimeout(
        window.libraryToastTimer
    );


    window.libraryToastTimer =
        setTimeout(
            () => {

                t.style.display =
                    "none";

            },
            2200
        );

}


/*
=========================================================
BACKUP
=========================================================
*/

function createBackup() { if(String(document.body.dataset.role||'').toLowerCase()!=='admin'){toast('Admin permission required.');return;} window.location.href = "backup.php"; toast("Backup download started"); }

function showRestoreFile(input) {
    const out=document.getElementById('restoreFileName');
    if(out) out.textContent=input.files && input.files[0] ? input.files[0].name : 'No backup selected';
}

async function restoreBackup(event) { if(String(document.body.dataset.role||'').toLowerCase()!=='admin'){toast('Admin permission required.');return;} 
    event.preventDefault();
    const form=document.getElementById('restoreForm');
    const file=document.getElementById('restoreFile');
    const btn=document.getElementById('restoreBtn');
    if(!file || !file.files || !file.files[0]) { toast('Please select an SQL backup'); return; }
    const f=file.files[0];
    if(!/\.sql$/i.test(f.name)) { toast('Only .sql backup files are allowed'); return; }
    if(f.size > 20*1024*1024) { toast('Backup file must be 20 MB or smaller'); return; }
    if(!confirm('Restore this database backup? Current data will be replaced. A safety backup will be created first.')) return;
    btn.disabled=true; btn.textContent='Restoring...';
    try {
        const fd=new FormData(form);
        const response=await fetch('restore.php',{method:'POST',body:fd,credentials:'same-origin'});
        const text=await response.text();
        let data; try { data=JSON.parse(text.replace(/^\uFEFF/,'').trim()); } catch(e) { throw new Error('Server returned invalid response: '+String(text).replace(/\s+/g,' ').slice(0,220)); }
        if(!response.ok || !data.success) throw new Error(data.message || 'Restore failed');
        toast(data.message || 'Database restored successfully');
        setTimeout(()=>window.location.reload(),900);
    } catch(err) {
        toast(err.message || 'Restore failed');
        btn.disabled=false; btn.textContent='♻ Restore Selected Backup';
    }
}

/*
=========================================================
DATE
=========================================================
*/

function updateDateText() {
    const now = new Date();
    const dateText = document.getElementById("dateText");
    const headerDateTime = document.getElementById("headerDateTime");

    const dateLabel = now.toLocaleDateString("en-IN", {
        weekday: "long",
        day: "numeric",
        month: "long",
        year: "numeric"
    });

    const timeLabel = now.toLocaleTimeString("en-IN", {
        hour: "2-digit",
        minute: "2-digit",
        hour12: true
    });

    if (dateText) dateText.textContent = dateLabel;
    if (headerDateTime) headerDateTime.textContent = `${dateLabel}  |  ${timeLabel}`;
}


/*
=========================================================
NAVIGATION
=========================================================
*/

function setupNavigation() {

    document
        .querySelectorAll(".nav-item")
        .forEach(item => {

            item.addEventListener(
                "click",
                function(event) {

                    event.preventDefault();

                    const page =
                        this.dataset.page;

                    // Dedicated pages must navigate normally instead of
                    // being intercepted by the SPA loader.
                    if (page === "seats") {
                        window.location.href = "seats.php";
                        return;
                    }

                    // Attendance QR is a dedicated PHP page (it renders the
                    // permanent QR and print controls).
                    if (page === "attendance_qr") {
                        window.location.href = "qr_display.php";
                        return;
                    }

                    if (page) {
                        loadPage(page);
                    }

                }
            );

        });

}


// Attach the session CSRF token to every state-changing dashboard request.
(function(){
    const nativeFetch = window.fetch.bind(window);
    window.fetch = function(input, init = {}) {
        const method = String(init.method || 'GET').toUpperCase();
        if (method !== 'GET' && method !== 'HEAD') {
            const headers = new Headers(init.headers || {});
            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            if (token) headers.set('X-CSRF-Token', token);
            init.headers = headers;
        }
        return nativeFetch(input, init);
    };
})();

async function apiRequest(url, options = {}) {
    const response = await fetch(url, {
        cache: 'no-store',
        credentials: 'same-origin',
        ...options
    });
    const text = await response.text();
    let data;
    try {
        data = JSON.parse(text.replace(/^\uFEFF/, '').trim());
    } catch (err) {
        const clean = String(text || '').replace(/\s+/g,' ').trim();
        const preview = clean ? clean.slice(0,220) : '(empty response)';
        throw new Error('Server returned invalid JSON (' + response.status + '): ' + preview);
    }
    if (!response.ok || data.success === false) {
        throw new Error(data.message || 'Request failed');
    }
    return data;
}
function money(n) { return '₹' + Number(n || 0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); }

/*
=========================================================
INITIALIZE
=========================================================
*/


function enforceRoleUI(){
    const role=(document.body.dataset.role||'').toLowerCase();
    if(role==='admin') return;
    let permissions=[]; try{permissions=JSON.parse(document.body.dataset.permissions||'[]');}catch(e){}
    document.querySelectorAll('.nav-item[data-page]').forEach(e=>{
        const page=e.dataset.page;
        if(page==='staff' || !permissions.includes(page)) e.remove();
    });
}
function initializeApp() {

    updateDateText();
    setInterval(updateDateText, 30000);

    setupNavigation();
    enforceRoleUI();


    const app =
        document.getElementById(
            "app"
        );


    if (
        app &&
        !app.classList.contains("hidden")
    ) {

        loadPage("dashboard");

    }

}


/*
=========================================================
START
=========================================================
*/

if (
    document.readyState === "loading"
) {

    document.addEventListener(
        "DOMContentLoaded",
        initializeApp
    );

} else {

    initializeApp();

}

/* =========================================================
   LIVE ADMIN MODULES
========================================================= */
function esc(v){ return escapeHtml(v==null?'':String(v)); }
function modal(title, html, onSave){ const old=document.getElementById('appModal'); if(old) old.remove(); const d=document.createElement('div'); d.id='appModal'; d.className='modal-backdrop'; d.innerHTML=`<div class="modal-card"><div class="panel-head"><h3>${esc(title)}</h3><button class="small-btn" onclick="document.getElementById('appModal').remove()">✕</button></div>${html}<div class="modal-actions"><button class="small-btn" onclick="document.getElementById('appModal').remove()">Cancel</button><button class="small-btn primary" id="modalSave">Save</button></div></div>`; document.body.appendChild(d); document.getElementById('modalSave').onclick=onSave; }
function formField(id,label,type='text',value='',extra=''){return `<div class="field"><label>${esc(label)}</label><input id="${id}" type="${type}" value="${esc(value)}" ${extra}></div>`;}
function displayDate(v){
    const s=String(v||'').slice(0,10); if(!/^\d{4}-\d{2}-\d{2}$/.test(s)) return v||'—';
    const [y,m,d]=s.split('-'); return `${d}-${m}-${y}`;
}
function parseLocalDate(s){const v=String(s||'').slice(0,10); return /^\d{4}-\d{2}-\d{2}$/.test(v)?new Date(v+'T00:00:00'):null;}

async function renewMember(id,name){
    if(!id) return;
    modal('Renew Membership', `<div class="renew-member-box"><p><b>${esc(name||'Member')}</b> की membership renew करें।</p><div class="form-grid"><div class="field"><label>Renewal Duration</label><select id="renew_months"><option value="1">1 Month</option><option value="3">3 Months</option><option value="6">6 Months</option><option value="12">12 Months</option></select></div><div class="field"><label>Shift</label><select id="renew_shift"><option value="Morning Shift">Morning Shift</option><option value="Morning Shift Reserved">Morning Shift Reserved</option><option value="Evening Shift">Evening Shift</option><option value="Evening Shift Reserved">Evening Shift Reserved</option><option value="Full Day">Full Day</option><option value="Full Day Reserved">Full Day Reserved</option></select></div></div><div class="note">Renew करने पर membership तुरंत active होगी और renewal fee उसी समय <b>Due Fees</b> में चली जाएगी। Payment मिलने पर Due Fees से collect करें। Shift बदलने पर नई selected shift member profile में save होगी।</div></div>`, async()=>{
        const months=Number(document.getElementById('renew_months')?.value||1);
        const shift=document.getElementById('renew_shift')?.value||'Full Day';
        const fd=new FormData(); fd.append('id',String(id)); fd.append('months',String(months)); fd.append('shift',shift);
        try{const d=await apiRequest('members_api.php?action=renew',{method:'POST',body:fd});document.getElementById('appModal')?.remove();toast(d.message||'Membership renewed.');loadDashboard();if(typeof loadMembers==='function')loadMembers();if(typeof loadDueFees==='function')loadDueFees();}catch(e){toast(e.message);}
    });
}

async function loadMemberNotices(){
    const box=document.getElementById('memberNoticeList'); if(!box)return;
    try{
        const d=await apiRequest('admin_api.php?action=member_messages');
        const rows=d.messages||[];
        box.innerHTML=rows.length?rows.map(m=>`<div class="member-notice-row"><div><b>📢 ${esc(m.title)}</b><div class="muted" style="margin-top:3px">${esc(m.message)}</div><small class="muted">${esc(m.created_at)} · ${esc(m.staff_id)}</small></div><button class="small-btn danger-btn" type="button" onclick="deleteMemberNotice(${Number(m.id)})">Delete</button></div>`).join(''):'<div class="empty">No active notices.</div>';
    }catch(e){box.innerHTML=`<div class="empty">${esc(e.message||'Could not load notices.')}</div>`;}
}
async function postMemberNotice(event){
    event.preventDefault();
    const form=document.getElementById('memberNoticeForm'); if(!form)return;
    try{
        const d=await apiRequest('admin_api.php?action=member_messages',{method:'POST',body:new FormData(form)});
        toast(d.message||'Message posted.');
        document.getElementById('memberNoticeMessage').value='';
        loadMemberNotices();
    }catch(e){toast(e.message||'Could not post message.');}
}
async function deleteMemberNotice(id){
    if(!confirm('Remove this notice for members?'))return;
    try{const d=await apiRequest('admin_api.php?action=member_messages&id='+encodeURIComponent(id),{method:'DELETE'});toast(d.message||'Notice removed.');loadMemberNotices();}catch(e){toast(e.message||'Could not remove notice.');}
}

async function loadDashboard(){
    loadMemberNotices();
    try{
        const d=await apiRequest('admin_api.php?action=dashboard');
        const s=d.stats||{};
        const moneySafe=v=>money(Number(v||0));
        const c=document.getElementById('content'); if(!c)return;
        const nameEl=document.getElementById('dashStaffName');
        if(nameEl){ const staff=document.querySelector('.profile b'); nameEl.textContent=staff?staff.textContent:'Team'; }
        const grid=document.getElementById('dashboardStats');
        if(grid){
            grid.innerHTML=[
                dashKpi('👥','Total Members',s.members||0,`${s.active||0} active members`,'blue'),
                dashKpi('💰','Pending Fees',moneySafe(s.pending_fees),`${s.expired||0} expired members`,'amber'),
                dashKpi('✓',"Today's Attendance",s.present||0,`${s.checked_in||0} currently checked in`,'green'),
                dashKpi('💺','Available Seats',s.available_seats||0,`${s.occupied||0} / ${s.total_seats||0} occupied`,'violet')
            ].join('');
        }
        const fin=document.getElementById('dashboardFinance');
        if(fin) fin.innerHTML=`
            <div class="finance-item"><span>Today's Collection</span><strong>${moneySafe(s.today_paid)}</strong></div>
            <div class="finance-item"><span>This Month</span><strong>${moneySafe(s.month_paid)}</strong></div>
            <div class="finance-item expense"><span>Monthly Expenses</span><strong>${moneySafe(s.month_expenses)}</strong></div>
            <div class="finance-item net"><span>Net Income</span><strong>${moneySafe(s.month_net)}</strong></div>`;
        const cap=document.getElementById('dashboardCapacity');
        if(cap){
            const seatPct=s.total_seats?Math.min(100,Math.round((Number(s.occupied||0)/Number(s.total_seats))*100)):0;
            const lockerPct=s.total_lockers?Math.min(100,Math.round((Number(s.occupied_lockers||0)/Number(s.total_lockers))*100)):0;
            cap.innerHTML=`
              <div class="capacity-line"><div><b>Seats</b><span>${s.occupied||0} occupied · ${s.available_seats||0} free</span></div><strong>${seatPct}%</strong></div>
              <div class="progress"><i style="width:${seatPct}%"></i></div>
              <div class="capacity-line"><div><b>Lockers</b><span>${s.occupied_lockers||0} occupied · ${s.available_lockers||0} free</span></div><strong>${lockerPct}%</strong></div>
              <div class="progress"><i style="width:${lockerPct}%"></i></div>`;
        }
        const mem=document.getElementById('dashboardMembership');
        if(mem) mem.innerHTML=`
          <div class="membership-stat"><span class="dot active"></span><div><b>${s.active||0}</b><small>Active Members</small></div></div>
          <div class="membership-stat"><span class="dot warning"></span><div><b>${s.expiring||0}</b><small>Expiring in 7 Days</small></div></div>
          <div class="membership-stat"><span class="dot danger"></span><div><b>${s.expired||0}</b><small>Expired</small></div></div>
          <div class="membership-stat"><span class="dot info"></span><div><b>${s.open_enquiries||0}</b><small>Open Enquiries</small></div></div>
          <div class="converted-note">${s.converted_enquiries||0} enquiries converted this month</div>`;
        const trend=document.getElementById('dashboardTrend');
        if(trend){
            const rows=d.trend||[];
            const max=Math.max(1,...rows.flatMap(x=>[Number(x.collection||0),Number(x.expenses||0)]));
            trend.innerHTML=rows.length?`<div class="chart-legend"><span><i class="legend-dot collection"></i> Collection</span><span><i class="legend-dot expense"></i> Expenses</span></div><div class="bar-chart">${rows.map(x=>{const c=Number(x.collection||0),e=Number(x.expenses||0);return `<div class="bar-day"><div class="bars"><span class="bar collection" style="height:${Math.max(4,Math.round(c/max*100))}%" title="${moneySafe(c)}"></span><span class="bar expense" style="height:${Math.max(4,Math.round(e/max*100))}%" title="${moneySafe(e)}"></span></div><small>${esc(String(x.day).slice(5))}</small></div>`}).join('')}</div>`:'<div class="empty">No financial data for the last 7 days.</div>';
        }
        const birthdayBox=document.getElementById('dashboardBirthdays');
        if(birthdayBox){
            const birthdays=d.birthdays||[];
            birthdayBox.innerHTML=birthdays.length?birthdays.map(b=>{
                const dob=String(b.date_of_birth||''); const md=dob.slice(5);
                const now=new Date(); const todayMd=String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0');
                const isToday=md===todayMd;
                const birthdayDate=displayDate(b.date_of_birth); return `<div class="birthday-row"><div class="birthday-avatar">🎂</div><div class="birthday-info"><strong>${esc(b.name||'Member')}</strong><span>${esc(b.member_id||'')} · 🎂 Birthday: ${esc(birthdayDate)} · ${isToday?'Today':'Upcoming'}</span></div><button class="small-btn primary" type="button" onclick="sendBirthdayGreeting(${Number(b.id)}, '${escapeJs(b.name||'')}', '${escapeJs(b.phone||'')}')">📱 ${isToday?'Send Wishes':'WhatsApp'}</button></div>`;
            }).join(''):'<div class="empty">No birthdays in the next 7 days.</div>';
        }

        const expiryBox=document.getElementById('dashboardExpiry');
        if(expiryBox){
            const expiry=d.expiry||[];
            const today=new Date(); today.setHours(0,0,0,0);
            expiryBox.innerHTML=expiry.length?expiry.map(m=>{
                const vd=new Date(String(m.validity_date)+'T00:00:00');
                const diff=Math.round((vd-today)/86400000);
                const status=diff<0?'Expired':(diff===0?'Expires Today':`Expires in ${diff} day${diff===1?'':'s'}`);
                return `<div class="birthday-row expiry-row"><div class="birthday-avatar">${diff<0?'🔴':diff===0?'⚠️':'⏳'}</div><div class="birthday-info"><strong>${esc(m.name||'Member')}</strong><span>${esc(m.member_id||'')} · Valid till ${esc(displayDate(m.validity_date))} · ${esc(status)}</span></div><div class="due-actions"><button class="small-btn" type="button" onclick="viewMember(${Number(m.id)})">👤 View</button><button class="small-btn primary" type="button" onclick="renewMember(${Number(m.id)}, '${escapeJs(m.name||'')}')">🔄 Renew</button></div></div>`;
            }).join(''):'<div class="empty">No memberships expiring in the next 7 days.</div>';
        }

        const body=c.querySelector('.dash-activity-table tbody');
        if(body) body.innerHTML=(d.activities||[]).map(x=>`<tr><td>${esc(x.staff_id||'System')}</td><td><b>${esc(x.action||'Activity')}</b></td><td>${esc(x.details||'')}</td><td>${esc(x.created_at||'')}</td></tr>`).join('')||'<tr><td colspan="4" class="empty">No recent activity.</td></tr>';
    }catch(e){toast(e.message)}
}
function sendBirthdayGreeting(id,name,phone){
    const clean=String(phone||'').replace(/\D/g,'');
    if(!clean){toast('This member has no phone number saved.');return;}
    const msg=`🎉 *Happy Birthday, ${name}! 🎂*\n\nAR Library की ओर से आपको जन्मदिन की हार्दिक शुभकामनाएँ। 🌟\n\nईश्वर से प्रार्थना है कि आपका आने वाला वर्ष खुशियों, सफलता और अच्छे स्वास्थ्य से भरा रहे। 📚✨\n\nआपका दिन शानदार हो और आपके सभी सपने पूरे हों। 💐\n\n*Best Wishes*\n*AR Library*\nWard No. 15, Sarkari Hospital ke Samane,\nBaikunthpur, Rewa, Madhya Pradesh - 486441`;
    window.open(`https://wa.me/${clean}?text=${encodeURIComponent(msg)}`,'_blank','noopener');
}

function dashKpi(icon,label,value,sub,kind){return `<div class="dash-kpi ${kind}"><div class="kpi-icon">${icon}</div><div class="kpi-copy"><span>${label}</span><strong>${value}</strong><small>${sub}</small></div></div>`}

async function loadSeats(){
    try{
        const d=await apiRequest('admin_api.php?action=seats');
        const total=Math.max(1,Number(d.total_seats||76));
        const shifts={morning:'Morning Shift',evening:'Evening Shift',full:'Full Day'}; const settings=d.settings||{}; const fmt=t=>{const [h,m]=String(t||'').split(':').map(Number); if(!Number.isFinite(h)||!Number.isFinite(m))return String(t||''); const ap=h>=12?'PM':'AM', hh=(h%12)||12; return `${hh}:${String(m).padStart(2,'0')} ${ap}`;}; document.querySelectorAll('[data-seat-section]').forEach(el=>{const k=el.dataset.seatSection; const sm=el.querySelector('small'); if(sm) sm.textContent=k==='morning'?`${fmt(settings.opening_time||'08:00')} – ${fmt(settings.morning_end||'14:00')}`:k==='evening'?`${fmt(settings.evening_start||'14:00')} – ${fmt(settings.closing_time||'20:00')}`:`${fmt(settings.opening_time||'08:00')} – ${fmt(settings.closing_time||'20:00')}`;});
        Object.entries(shifts).forEach(([key,shift])=>renderSeatBlockPro(key,shift,total,d.seats||[]));
        renderLockersPro(d.lockers||[],Math.max(1,Number(d.total_lockers||76)));
    }catch(e){toast(e.message)}
}
function renderSeatBlockPro(key,shift,total,seats){
    const layout=document.getElementById(key+'_seat_layout');
    if(!layout)return;

    // Normalize values coming from older database records as well as the current UI.
    const normalizeShift = value => {
        const v=String(value??'').trim().toLowerCase().replace(/\s+/g,' ');
        if(v==='morning' || v==='morning shift') return 'Morning Shift';
        if(v==='evening' || v==='evening shift') return 'Evening Shift';
        if(v==='full' || v==='full day' || v==='full day shift') return 'Full Day';
        return String(value??'').trim();
    };

    const requested=normalizeShift(shift);
    const conflicts=(requested,existing)=>{
        if(!existing || String(existing.status??'').toLowerCase()!=='assigned')return false;
        const existingShift=normalizeShift(existing.shift);
        if(requested==='Full Day')return ['Morning Shift','Evening Shift','Full Day'].includes(existingShift);
        if(requested==='Morning Shift')return ['Morning Shift','Full Day'].includes(existingShift);
        if(requested==='Evening Shift')return ['Evening Shift','Full Day'].includes(existingShift);
        return false;
    };

    const visible=seats.filter(x=>conflicts(requested,x));
    const assigned=visible.filter(x=>String(x.status??'').toLowerCase()==='assigned');
    const reserved=visible.filter(x=>String(x.status??'').toLowerCase()==='reserved');
    const bySeat={};

    [...assigned,...reserved].forEach(x=>{
        const n=String(x.seat_no).replace(/^S-0*/i,'');
        const xs=normalizeShift(x.shift);
        // If multiple records can appear in a section, Full Day has priority.
        if(!bySeat[n] || xs==='Full Day' || xs===requested)bySeat[n]=x;
    });

    const palette={
        'Morning Shift': {bg:'#39a96b',border:'#278653',reservedBg:'#bfe8d0',reservedText:'#17633b'},
        'Evening Shift': {bg:'#f39a2f',border:'#d97e16',reservedBg:'#ffd9ad',reservedText:'#8a4b00'},
        'Full Day': {bg:'#d84b4b',border:'#bd3838',reservedBg:'#ffd0d0',reservedText:'#8f2020'}
    };

    let html='';
    for(let start=1;start<=total;start+=20){
        const end=Math.min(total,start+19);
        html+=`<div class="seat-pro-row"><span class="seat-row-label">ROW ${String.fromCharCode(65+Math.floor((start-1)/20))}</span><div class="seat-pro-row-grid">`;
        for(let n=start;n<=end;n++){
            const x=bySeat[String(n)];
            const rawStatus=String(x?.status??'').trim().toLowerCase();
            const state=x?(rawStatus==='reserved'?'reserved':'occupied'):'available';
            const label=x?(x.name||x.member_code||'Occupied'):'Free';
            const bookingShift=normalizeShift(x?.shift || requested);
            const colors=palette[bookingShift] || palette[requested] || palette['Full Day'];

            let inlineStyle='';
            if(state==='available'){
                inlineStyle='background:#ffffff !important;color:#26365b !important;border-color:#cfd6e6 !important;';
            }else if(state==='reserved'){
                inlineStyle=`background:${colors.reservedBg} !important;color:${colors.reservedText} !important;border-color:${colors.border} !important;`;
            }else{
                inlineStyle=`background:${colors.bg} !important;color:#ffffff !important;border-color:${colors.border} !important;`;
            }

            const colorClass=bookingShift==='Morning Shift'?'morning-seat':bookingShift==='Evening Shift'?'evening-seat':'full-seat';
            html+=`<button type="button" class="seat-pro-tile ${colorClass} ${state}" style="${inlineStyle}" title="${esc('Seat '+n+' · '+label+' · '+bookingShift)}" onclick="openSeatAssignPro('${escapeJs(requested)}','${n}',${x?Number(x.id):0})"><span class="seat-icon">💺</span><b>${n}</b><small>${esc(label)}</small></button>`;
        }
        html+='</div></div>';
        if(end<total)html+='<div class="seat-pro-aisle">AISLE</div>';
    }
    layout.innerHTML=html;

    const occupiedSeatNos=new Set(assigned.map(x=>String(x.seat_no).replace(/^S-0*/i,'')));
    const free=Math.max(0,total-occupiedSeatNos.size);
    const fill=Math.round((occupiedSeatNos.size/total)*100);
    const sum=document.getElementById(key+'_summary');
    if(sum)sum.textContent=`${free} Free · ${occupiedSeatNos.size} Occupied`;
    const stats=document.getElementById(key+'_stats');
    if(stats)stats.innerHTML=`<b>Total: ${total}</b> · <span class="red-text">Occupied: ${occupiedSeatNos.size}</span> · <span class="green-text">Free: ${free}</span> · <span class="warn-text">Fill: ${fill}%</span>`;
    const holders=document.getElementById(key+'_holders');
    if(holders){
        const unique=[];const seen=new Set();
        assigned.forEach(x=>{const k=String(x.id);if(!seen.has(k)){seen.add(k);unique.push(x);}});
        holders.innerHTML=unique.length?unique.map(x=>`<div class="holder-row"><span class="avatar">${esc((x.name||'?').charAt(0).toUpperCase())}</span><div class="holder-main"><b>${esc(x.name||'Unknown')}</b><small>Seat ${esc(x.seat_no)} · ${esc(x.member_code||'')} · ${esc(normalizeShift(x.shift))}</small></div><button class="danger-icon" onclick="releaseSeatPro(${Number(x.id)})">×</button></div>`).join(''):'<div class="muted">No seat holders.</div>';
    }
}
function openSeatAssignPro(shift,seatNo,id=0){
    const old=document.getElementById('seatAssignModal');if(old)old.remove();
    const modal=document.createElement('div');modal.id='seatAssignModal';modal.className='modal-backdrop';
    modal.innerHTML=`<div class="modal-card"><div class="panel-head"><h3>${id?'Edit':'Assign'} Seat ${esc(seatNo)} · ${esc(shift)}</h3><button class="small-btn" onclick="document.getElementById('seatAssignModal').remove()">✕</button></div><div class="form-grid"><div class="field"><label>Shift</label><input id="seatShift" value="${esc(shift)}" readonly></div><div class="field"><label>Seat No.</label><input id="seatNo" value="${esc(seatNo)}" readonly></div><div class="field" style="grid-column:1/-1"><label>Member ID / Member Code</label><input id="seatMember" placeholder="Example: MEM-001 or database ID" required></div></div><div class="modal-actions"><button class="small-btn" onclick="document.getElementById('seatAssignModal').remove()">Cancel</button><button class="small-btn primary" onclick="saveSeatAssignmentPro(${Number(id)})">Save</button></div></div>`;
    document.body.appendChild(modal);
}
async function saveSeatAssignmentPro(existingId){
    const member=document.getElementById('seatMember').value.trim(); if(!member)return toast('Member ID / code is required.');
    const fd=new FormData();fd.append('seat_no',document.getElementById('seatNo').value);fd.append('shift',document.getElementById('seatShift').value);fd.append('member_id',member);if(existingId)fd.append('id',existingId);
    try{const d=await apiRequest('admin_api.php?action=assign_seat',{method:'POST',body:fd});document.getElementById('seatAssignModal')?.remove();toast(d.message||'Seat assigned successfully');loadSeats();}catch(e){toast(e.message)}
}
async function releaseSeatPro(id){if(!confirm('Release this seat?'))return;try{const d=await apiRequest('admin_api.php?action=release_seat',{method:'POST',body:new URLSearchParams({id:String(id)})});toast(d.message||'Seat released');loadSeats();}catch(e){toast(e.message)}}
function renderLockersPro(lockers,total){
    const box=document.getElementById('locker_layout');if(!box)return;
    const assigned={};lockers.forEach(x=>assigned[String(x.locker_no)]=x);
    let html='';for(let n=1;n<=total;n++){const x=assigned[String(n)];const state=x?(x.status==='Expired'?'reserved':'occupied'):'available';html+=`<button type="button" class="locker-pro-tile ${state}" onclick="openLockerAssign('${n}',${x?Number(x.id):0})"><span>🔐</span><b>${n}</b></button>`;}
    box.innerHTML=html;
    const active=lockers.filter(x=>x.status==='Assigned').length,expired=lockers.filter(x=>x.status==='Expired').length,free=Math.max(0,total-active-expired);
    const sum=document.getElementById('locker_summary');if(sum)sum.textContent=`${free} Free · ${active} Assigned`;
    const holders=document.getElementById('locker_holders');if(holders)holders.innerHTML=lockers.length?lockers.map(x=>`<div class="holder-row"><span class="avatar">🔐</span><div class="holder-main"><b>Locker ${esc(x.locker_no)} — ${esc(x.name||'Unknown')}</b><small>${esc(x.member_code||'')} · ${esc(x.status)} · ${esc(x.end_date||'No end date')}</small></div><button class="danger-icon" onclick="releaseLockerPro(${Number(x.id)})">×</button></div>`).join(''):'<div class="muted">No locker holders.</div>';
}
function openLockerAssign(no='',id=0){
    const old=document.getElementById('lockerAssignModal');if(old)old.remove();
    const modal=document.createElement('div');modal.id='lockerAssignModal';modal.className='modal-backdrop';
    const today=new Date().toISOString().slice(0,10);
    modal.innerHTML=`<div class="modal-card"><div class="panel-head"><h3>${id?'Edit':'Assign'} Locker</h3><button class="small-btn" onclick="document.getElementById('lockerAssignModal').remove()">✕</button></div><div class="form-grid"><div class="field"><label>Locker No.</label><input id="lockerNo" type="number" min="1" max="999" value="${esc(no)}" required></div><div class="field"><label>Member ID / Code</label><input id="lockerMember" placeholder="MEM-001 or database ID" required></div><div class="field"><label>Start Date</label><input id="lockerStart" type="date" value="${today}"></div><div class="field"><label>End Date</label><input id="lockerEnd" type="date"></div></div><div class="modal-actions"><button class="small-btn" onclick="document.getElementById('lockerAssignModal').remove()">Cancel</button><button class="small-btn primary" onclick="saveLockerPro(${Number(id)})">Save</button></div></div>`;
    document.body.appendChild(modal);
}
async function saveLockerPro(id){
    const locker=document.getElementById('lockerNo').value.trim(),member=document.getElementById('lockerMember').value.trim();if(!locker||!member)return toast('Locker number and member are required.');
    const fd=new FormData();fd.append('locker_no',locker);fd.append('member_id',member);fd.append('start_date',document.getElementById('lockerStart').value);fd.append('end_date',document.getElementById('lockerEnd').value);if(id)fd.append('id',id);
    try{const d=await apiRequest('admin_api.php?action=assign_locker',{method:'POST',body:fd});document.getElementById('lockerAssignModal')?.remove();toast(d.message||'Locker assigned successfully');loadSeats();}catch(e){toast(e.message)}
}
async function releaseLockerPro(id){if(!confirm('Release this locker?'))return;try{const d=await apiRequest('admin_api.php?action=release_locker',{method:'POST',body:new URLSearchParams({id:String(id)})});toast(d.message||'Locker released');loadSeats();}catch(e){toast(e.message)}}
function switchFeeTab(tab){
    document.querySelectorAll('.fees-tab').forEach(b=>b.classList.toggle('active',b.dataset.feeTab===tab));
    ['records','income','settings','dues'].forEach(x=>document.getElementById('feeTab'+x.charAt(0).toUpperCase()+x.slice(1))?.classList.toggle('hidden',x!==tab));
    if(tab==='records') loadPayments();
    if(tab==='income') loadMonthlyIncome();
    if(tab==='settings') loadFeeSettings();
    if(tab==='dues') loadDueFees();
}
function feeMonthDefault(){
    const d=new Date();
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
}
let __incomeDaysByDate={};
function renderIncomeCalendar(month,days){
    const box=document.getElementById('incomeCalendar'); if(!box)return;
    __incomeDaysByDate={}; (days||[]).forEach(x=>{__incomeDaysByDate[x.payment_date]=x;});
    const [yy,mm]=String(month).split('-').map(Number);
    const first=new Date(yy,mm-1,1), last=new Date(yy,mm,0);
    const start=(first.getDay()+6)%7; // Monday first
    const total=last.getDate();
    const names=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    let html=`<div class="income-calendar-head">${names.map(n=>`<div>${n}</div>`).join('')}</div><div class="income-calendar-grid">`;
    for(let i=0;i<start;i++) html+='<div class="income-day empty-day"></div>';
    for(let day=1;day<=total;day++){
        const date=`${yy}-${String(mm).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
        const d=__incomeDaysByDate[date];
        const amount=d?.total_income||0, count=d?.transactions||0;
        html+=`<button type="button" class="income-day ${count?'has-income':''}" onclick="showIncomeDay('${date}')"><span class="income-day-no">${day}</span>${count?`<span class="income-day-count">${count} payment${count>1?'s':''}</span><span class="income-day-amount">${money(amount)}</span>`:'<span class="income-day-zero">No payment</span>'}</button>`;
    }
    html+='</div>';
    box.innerHTML=html;
    const firstPaid=(days||[])[0]?.payment_date;
    showIncomeDay(firstPaid||`${yy}-${String(mm).padStart(2,'0')}-01`, !firstPaid);
}
function showIncomeDay(date,emptyOnly=false){
    const box=document.getElementById('incomeDayDetails'); if(!box)return;
    const d=__incomeDaysByDate[date];
    if(!d || !d.payments?.length){
        box.innerHTML=`<div class="panel-inner"><h3>📅 ${esc(date)}</h3><p class="empty">इस तारीख को कोई payment नहीं हुआ।</p></div>`;
        return;
    }
    box.innerHTML=`<div class="panel-inner"><div class="panel-head"><h3>📅 ${esc(date)} की Collection</h3><strong>${money(d.total_income)}</strong></div><div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Member Name</th><th>Member ID</th><th>Amount</th><th>Receipt</th><th>Method</th><th>Type</th></tr></thead><tbody>${d.payments.map((p,i)=>`<tr><td>${i+1}</td><td><b>${esc(p.member_name)}</b></td><td>${esc(p.member_code)}</td><td><b>${money(p.amount)}</b></td><td>${esc(p.receipt_no)}</td><td>${esc(p.payment_method||'—')}</td><td>${esc(p.payment_type||'—')}</td></tr>`).join('')}</tbody></table></div></div>`;
}
async function loadMonthlyIncome(){
    const calendar=document.getElementById('incomeCalendar'); if(!calendar)return;
    const month=document.getElementById('incomeMonth'); if(month && !month.value) month.value=feeMonthDefault();
    try{
        const selected=month?.value||feeMonthDefault();
        const d=await apiRequest('payments_api.php?action=monthly_income&month='+encodeURIComponent(selected));
        renderIncomeCalendar(d.month||selected,d.days||[]);
        const el=document.getElementById('incomeSummary'); if(el) el.textContent=`Total income: ${money(d.total_income||0)} · ${d.total_transactions||0} transactions · किसी भी तारीख पर क्लिक करके उस दिन की member-wise collection देखें`;
    }catch(e){calendar.innerHTML=`<div class="empty">${esc(e.message)}</div>`;const el=document.getElementById('incomeDayDetails');if(el)el.innerHTML='';}
}

async function loadFeeSettings(){
    const box=document.getElementById('feeSettingsBody'); if(!box)return;
    try{const d=await apiRequest('payments_api.php?action=fee_settings');
        const r=d.settings||{};
        const rows=[['half_day','Half Day (Morning / Evening)'],['half_reserved','Half Day + Reserved Seat'],['full_day','Full Day'],['full_reserved','Full Day + Reserved Seat']];
        box.innerHTML=`<table class="table fee-settings-table"><thead><tr><th>Plan</th><th>1 Month</th><th>3 Months (5% Off)</th><th>6 Months (10% Off)</th><th>12 Months</th></tr></thead><tbody>${rows.map(([key,label])=>`<tr><td><b>${label}</b></td><td><input class="fee-setting-input" data-key="${key}" data-term="1" type="number" min="0" step="1" value="${Number(r[key]?.one_month||0)}"></td><td><input class="fee-setting-input" data-key="${key}" data-term="3" type="number" min="0" step="1" value="${Number(r[key]?.three_month||0)}"></td><td><input class="fee-setting-input" data-key="${key}" data-term="6" type="number" min="0" step="1" value="${Number(r[key]?.six_month||0)}"></td><td><input class="fee-setting-input" data-key="${key}" data-term="12" type="number" min="0" step="1" value="${Number(r[key]?.twelve_month||0)}"></td></tr>`).join('')}</tbody></table><div class="note">Current structure: ₹600 / ₹800 / ₹1100 / ₹1300 monthly; 3 months at 5% off; 6 months at 10% off; 12 months configurable.</div>`;
    }catch(e){box.innerHTML=`<div class="empty">${esc(e.message)}</div>`}
}
async function saveFeeSettings(){
    const inputs=[...document.querySelectorAll('.fee-setting-input')]; const settings={};
    inputs.forEach(i=>{settings[i.dataset.key]??={}; const n=Number(i.value); settings[i.dataset.key][i.dataset.term=== '1'?'one_month':i.dataset.term==='3'?'three_month':i.dataset.term==='6'?'six_month':'twelve_month']=isFinite(n)?n:0});
    try{const fd=new FormData();fd.append('settings',JSON.stringify(settings));const d=await apiRequest('payments_api.php?action=save_fee_settings',{method:'POST',body:fd});toast(d.message||'Fee settings saved');loadFeeSettings();}catch(e){toast(e.message)}
}
async function loadDueFees(){
    const body=document.getElementById('duesBody'); if(!body)return;
    try{const d=await apiRequest('payments_api.php?action=dues');
        window.__dueMembers = d.members || [];
        body.innerHTML=(d.members||[]).map(m=>`<tr><td>${esc(m.member_id)}</td><td>${esc(m.name)}</td><td>${esc(m.phone||'—')}</td><td>${esc(m.membership_plan||'—')}</td><td>${esc(m.due_date||m.validity_date||'—')}</td><td>${money(m.due_amount||0)}</td><td><div class="due-actions"><button class="small-btn primary" onclick="editDueFee(${Number(m.id)}, ${Number(m.due_fee_id||0)})">✏️ Edit Due</button><button class="small-btn" onclick="openPaymentFormForMember(${Number(m.id)})">Collect</button><button class="small-btn reminder-btn" onclick="sendFeeReminder(${Number(m.id)})">🔔 Reminder</button></div></td></tr>`).join('')||'<tr><td colspan="7" class="empty">No due fee members.</td></tr>';
        const el=document.getElementById('duesSummary'); if(el) el.textContent=`${(d.members||[]).length} member(s) have due fees · Estimated due: ${money(d.total_due||0)}`;
    }catch(e){body.innerHTML=`<tr><td colspan="7" class="empty">${esc(e.message)}</td></tr>`}
}
async function editDueFee(memberId, dueFeeId){
    if(String(document.body.dataset.role||'').toLowerCase()!=='admin'){
        toast('Admin permission required.');
        return;
    }
    const rowData = window.__dueMembers?.find(x => Number(x.id) === Number(memberId));
    if(!rowData){ toast('Member details not available. Please refresh Due Fees.'); return; }
    const currentAmount = Number(rowData.due_amount || 0);
    const currentDate = String(rowData.due_date || rowData.fee_due_date || rowData.validity_date || '').slice(0,10);
    modal('Edit Due Fee', `
        <div class="form-grid">
            <div class="field"><label>Member</label><input type="text" value="${esc(rowData.member_id||'')} - ${esc(rowData.name||'')}" disabled></div>
            <div class="field"><label>Due Amount (₹)</label><input id="edit_due_amount" type="number" min="0" step="0.01" value="${currentAmount.toFixed(2)}"></div>
            <div class="field"><label>Due Date</label><input id="edit_due_date" type="date" value="${esc(currentDate)}"></div>
            <div class="field"><label>Remarks (optional)</label><input id="edit_due_remarks" type="text" maxlength="255" placeholder="Reason / note"></div>
        </div>
        <div class="note">Amount ₹0 करने पर यह pending due record clear हो जाएगा।</div>`, async()=>{
        const amountEl=document.getElementById('edit_due_amount');
        const dateEl=document.getElementById('edit_due_date');
        const remarksEl=document.getElementById('edit_due_remarks');
        const amount=Number(amountEl?.value);
        if(!Number.isFinite(amount) || amount<0){toast('Valid due amount enter करें.');return;}
        try{
            const fd=new FormData();
            fd.append('member_id',String(memberId));
            fd.append('due_fee_id',String(dueFeeId||0));
            fd.append('amount',amount.toFixed(2));
            fd.append('due_date',String(dateEl?.value||''));
            fd.append('remarks',String(remarksEl?.value||''));
            const d=await apiRequest('payments_api.php?action=edit_due',{method:'POST',body:fd});
            document.getElementById('appModal')?.remove();
            toast(d.message||'Due fee updated successfully.');
            await loadDueFees();
            if(typeof loadDashboard==='function')loadDashboard();
            if(typeof loadPayments==='function')loadPayments();
        }catch(e){toast(e.message||'Could not update due fee.');}
    });
}

async function sendFeeReminder(id){
    const rowData = window.__dueMembers?.find(x => Number(x.id) === Number(id));
    if(!rowData){
        toast('Member details not available. Please refresh Due Fees.');
        return;
    }

    let phone = String(rowData.phone || '').replace(/\D/g, '');
    if(!phone){
        toast('This member does not have a valid phone number.');
        return;
    }

    // Indian 10-digit mobile numbers are converted to international format.
    if(phone.length === 10){
        phone = '91' + phone;
    }

    const shiftMap = {
        'morning': 'Morning Shift',
        'morning shift': 'Morning Shift',
        'morning reserved': 'Morning Shift Reserved',
        'morning shift reserved': 'Morning Shift Reserved',
        'evening': 'Evening Shift',
        'evening shift': 'Evening Shift',
        'evening reserved': 'Evening Shift Reserved',
        'evening shift reserved': 'Evening Shift Reserved',
        'full': 'Full Day',
        'full day': 'Full Day',
        'full day shift': 'Full Day',
        'full reserved': 'Full Day Reserved',
        'full day reserved': 'Full Day Reserved',
        'full day shift reserved': 'Full Day Reserved'
    };

    const rawShift = String(rowData.shift || '').trim().toLowerCase();
    const shift = shiftMap[rawShift] || rowData.shift || '—';
    const plan = rowData.membership_plan || rowData.plan || '—';
    const due = Number(rowData.due_amount || 0);
    const dueText = Number.isFinite(due) ? due.toFixed(0) : '0';

    let libraryAddress = 'सरकारी हॉस्पिटल के सामने, बैकुण्ठपुर (म.प्र.)';
    try {
        const sd = await apiRequest('admin_api.php?action=settings');
        libraryAddress = String((sd.settings||{}).address || libraryAddress).trim();
    } catch(e) {}

    const msg = `💼 *AR Library — Fee Reminder*

Dear *${rowData.name || 'Student'}*, 💼

Library fee is pending.

📋 *Details:*
• Member ID: ${rowData.member_id || '—'}
• Shift: ${shift}
• Plan: ${plan}
• *Amount Due: ₹${dueText}*

Please clear dues at the earliest.

📍 AR Library — ${libraryAddress}`;

    window.open(`https://wa.me/${phone}?text=${encodeURIComponent(msg)}`, '_blank', 'noopener,noreferrer');
}

async function openPaymentFormForMember(id){
    try{
        const d=await apiRequest('payments_api.php?action=dues');
        const m=(d.members||[]).find(x=>Number(x.id)===Number(id));
        await openPaymentForm({member_id:id,fee_amount:m?Number(m.due_amount||0):0});
        const sel=document.getElementById('pay_member'); if(sel&&id)sel.value=String(id);
        const fee=document.getElementById('pay_fee_amount'); if(fee&&m)fee.value=Number(m.due_amount||0);
        const total=document.getElementById('pay_total'); if(total)total.value='₹'+(Number(fee?.value||0)+Number(document.getElementById('pay_additional')?.value||0)).toFixed(2);
    }catch(e){toast(e.message)}
}

async function loadPayments(){const b=document.getElementById('feesBody');if(!b)return;try{const d=await apiRequest('payments_api.php?action=list');window.__payments=d.payments||[];const isAdmin=String(document.body.dataset.role||'').toLowerCase()==='admin';b.innerHTML=(d.payments||[]).map(p=>`<tr><td>${esc(p.receipt_no)}</td><td>${esc(p.member_code)}</td><td>${esc(p.plan||'—')}</td><td>${money(p.fee_amount??p.amount)}</td><td>${money(p.additional_charges||0)}</td><td>${money(p.amount)}</td><td>${money(p.balance_due||0)}</td><td>${esc(p.payment_type||'Full')}</td><td>${esc(p.payment_date)}</td><td>${esc(p.payment_method)}</td><td><div class="fee-record-actions"><button class="small-btn" onclick="window.location.href='receipt.php?id=${Number(p.id)}&download=1'">Receipt</button><button class="small-btn whatsapp-btn" onclick="sendReceiptWhatsApp(${Number(p.id)})">📱 WhatsApp</button></div></td></tr>`).join('')||'<tr><td colspan="11" class="empty">No payments found.</td></tr>';document.getElementById('feesSummary').textContent='Total collected: '+money(d.total_paid);}catch(e){b.innerHTML=`<tr><td colspan="11" class="empty">${esc(e.message)}</td></tr>`}}

async function deletePayment(id, receiptNo){
    if(String(document.body.dataset.role||'').toLowerCase()!=='admin'){
        toast('Admin permission required.');
        return;
    }
    const payment=(window.__payments||[]).find(x=>Number(x.id)===Number(id));
    const amount=payment ? money(payment.amount||0) : '';
    const label=receiptNo ? `Receipt ${receiptNo}` : `payment #${id}`;
    const confirmed=confirm(`Delete ${label}${amount?` (${amount})`:''}?\n\nThis will permanently remove the fee record. If this payment had reduced or cleared a previous Due Fee, that due amount will be restored automatically. This action cannot be undone.`);
    if(!confirmed)return;
    try{
        const fd=new FormData();
        fd.append('id',String(id));
        const d=await apiRequest('payments_api.php?action=delete',{method:'POST',body:fd});
        toast(d.message||'Payment deleted successfully.');
        await loadPayments();
        if(typeof loadDueFees==='function')loadDueFees();
        if(typeof loadDashboard==='function')loadDashboard();
    }catch(e){toast(e.message||'Could not delete payment.');}
}
async function sendReceiptWhatsApp(id){
    const p=(window.__payments||[]).find(x=>Number(x.id)===Number(id));
    if(!p){toast('Receipt details not available. Please refresh Fee Records.');return;}

    // Normalize Indian mobile number for WhatsApp.
    // Accepts: 9876543210, 09876543210, +91 9876543210, 919876543210.
    const rawPhone=String(p.phone||'').trim();
    let phone=rawPhone.replace(/\D/g,'');
    if(phone.startsWith('0091')) phone=phone.slice(4);
    else if(phone.startsWith('091')) phone=phone.slice(1);
    else if(phone.startsWith('0') && phone.length===11) phone=phone.slice(1);
    if(phone.length===10) phone='91'+phone;

    if(!/^91[6-9]\d{9}$/.test(phone)){
        toast('Invalid WhatsApp number for this member: '+(rawPhone||'not saved'));
        return;
    }

    const amount=Number(p.amount||0);
    let libraryAddress = 'सरकारी हॉस्पिटल के सामने, बैकुण्ठपुर (म.प्र.)';
    try {
        const sd = await apiRequest('admin_api.php?action=settings');
        libraryAddress = String((sd.settings||{}).address || libraryAddress).trim();
    } catch(e) {}

    const msg=`💼 *AR Library — Fee Receipt*

Dear *${p.name||'Student'}*, 💼

Receipt ID: ${p.receipt_no||'—'}
Name: ${p.name||'—'}
Seat No.: ${p.seat_no||'—'}
Plan: ${p.plan||p.membership_plan||'—'}
Amount: ₹${amount.toFixed(2)}
Date: ${p.payment_date||'—'}

💰 Fee paid. Thank you!

📍 AR Library — ${libraryAddress}`;

    const waUrl=`https://api.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(msg)}`;
    window.open(waUrl,'_blank','noopener,noreferrer');
}

async function openPaymentForm(prefill={}){
    try {
        const [data,feeData] = await Promise.all([
            apiRequest('members_api.php?action=list'),
            apiRequest('payments_api.php?action=fee_settings')
        ]);
        const members=data.members||[], r=feeData.settings||{};
        if(!members.length){toast('Please add a member first.');return;}

        const options=members.map(m=>`<option value="${Number(m.id)||0}" ${Number(prefill.member_id)===Number(m.id)?'selected':''}>${esc(m.member_id)} — ${esc(m.name)}${m.phone?' — '+esc(m.phone):''}</option>`).join('');
        const planOptions=[['half_day','Half Day (Morning / Evening)'],['half_reserved','Half Day + Reserved Seat'],['full_day','Full Day'],['full_reserved','Full Day + Reserved Seat']];
        const planSelect=planOptions.map(([k,label])=>`<option value="${k}" data-amount="${Number(r[k]?.one_month||0)}">${label} — ₹${Number(r[k]?.one_month||0)}</option>`).join('');

        modal('Collect / Record Payment', `<div class="form-grid">
            <div class="field"><label>Member</label><select id="pay_member" required><option value="">Select member</option>${options}</select></div>
            <div class="field"><label>Fee Plan</label><select id="pay_plan" required>${planSelect}</select></div>
            <div class="field"><label>Duration</label><select id="pay_duration"><option value="1">1 Month</option><option value="3">3 Months — 5% Off</option><option value="6">6 Months — 10% Off</option><option value="12">12 Months</option></select></div>
            <div class="field"><label>Payment Type</label><select id="pay_type"><option value="Full">Full Payment</option><option value="Split">Split / Partial Payment</option></select></div>
            ${formField('pay_fee_amount','Fee Amount Paid','number',prefill.fee_amount||'','min="0.01" step="0.01" required')}
            ${formField('pay_additional','Additional Charges','number','0','min="0" step="0.01"')}
            <div class="field"><label>Total Received</label><input id="pay_total" type="text" value="₹0.00" readonly></div>
            ${formField('pay_date','Payment Date','date',new Date().toISOString().slice(0,10))}
            <div class="field"><label>Payment Method</label><select id="pay_method"><option>Cash</option><option>UPI</option><option>Online</option><option>Bank</option><option>Card</option><option>Other</option></select></div>
            ${formField('pay_notes','Notes')}
        </div>
        <div class="note" id="pay_split_note">Split payment me sirf jitni fee abhi receive hui hai utni amount enter karein. Baaki amount Due me rahega.</div>`,async()=>{
            const memberEl=document.getElementById('pay_member'), planEl=document.getElementById('pay_plan');
            const feeEl=document.getElementById('pay_fee_amount'), addEl=document.getElementById('pay_additional'), typeEl=document.getElementById('pay_type');
            if(!memberEl?.value){toast('Please select a member.');return;}
            if(!feeEl?.value || Number(feeEl.value)<=0){toast('Please enter a valid fee payment amount.');return;}
            if(Number(addEl?.value||0)<0){toast('Additional charges cannot be negative.');return;}
            const fd=new FormData();
            fd.append('member_id',memberEl.value);
            fd.append('fee_amount',feeEl.value);
            fd.append('additional_charges',addEl?.value||'0');
            fd.append('payment_type',typeEl?.value||'Full');
            fd.append('duration',document.getElementById('pay_duration')?.value||'1');
            fd.append('plan_key',planEl?.value||'');
            fd.append('amount',(Number(feeEl.value||0)+Number(addEl?.value||0)).toFixed(2));
            fd.append('payment_date',document.getElementById('pay_date')?.value||'');
            fd.append('payment_method',document.getElementById('pay_method')?.value||'Cash');
            fd.append('plan',planEl?.options[planEl.selectedIndex]?.textContent||'');
            fd.append('notes',document.getElementById('pay_notes')?.value||'');
            try{
                const d=await apiRequest('payments_api.php?action=add',{method:'POST',body:fd});
                document.getElementById('appModal')?.remove();
                toast(d.message||'Payment saved');
                loadPayments();
                if(typeof loadDueFees==='function')loadDueFees();
                if(typeof loadDashboard==='function')loadDashboard();
            }catch(e){toast(e.message)}
        });

        const updateTotal=()=>{
            const fee=Number(document.getElementById('pay_fee_amount')?.value||0), add=Number(document.getElementById('pay_additional')?.value||0);
            const total=document.getElementById('pay_total'); if(total)total.value='₹'+(fee+add).toFixed(2);
        };
        const updateAmount=()=>{
            const key=document.getElementById('pay_plan')?.value,dur=Number(document.getElementById('pay_duration')?.value||1);
            const row=r[key]||{};
            const amount=dur===3?row.three_month:dur===6?row.six_month:row.one_month;
            const fee=document.getElementById('pay_fee_amount');
            if(fee && !prefill.fee_amount)fee.value=Number(amount||0);
            updateTotal();
        };
        document.getElementById('pay_plan')?.addEventListener('change',updateAmount);
        document.getElementById('pay_duration')?.addEventListener('change',updateAmount);
        document.getElementById('pay_fee_amount')?.addEventListener('input',updateTotal);
        document.getElementById('pay_additional')?.addEventListener('input',updateTotal);
        document.getElementById('pay_type')?.addEventListener('change',()=>{
            const note=document.getElementById('pay_split_note');
            if(note)note.textContent=document.getElementById('pay_type').value==='Split'
                ?'Split payment: abhi jitni fee receive hui hai utni amount enter karein. Baaki fee Due me rahegi.'
                :'Full payment: poori fee amount enter karein. Additional Charges alag se add kiye ja sakte hain.';
        });
        updateAmount();
        updateTotal();
    }catch(e){toast(e.message)}
}
function formatDuration(minutes){
    const n=Number(minutes);
    if(!Number.isFinite(n)||n<0)return '—';
    const h=Math.floor(n/60), m=n%60;
    return h?`${h}h ${m}m`:`${m}m`;
}

async function loadAttendance(){
    const b=document.getElementById('attendanceBody');
    if(!b)return;
    const dateEl=document.getElementById('attendanceDate');
    const date=dateEl?.value || new Date().toISOString().slice(0,10);
    if(dateEl && !dateEl.value) dateEl.value=date;
    b.innerHTML='<tr><td colspan="9" class="empty">Loading attendance...</td></tr>';
    try{
        const d=await apiRequest('attendance_api.php?action=attendance&date='+encodeURIComponent(date));
        const rows=Array.isArray(d.attendance)?d.attendance:[];
        const total=rows.length;
        const open=rows.filter(a=>a.check_in&&!a.check_out).length;
        const completed=rows.filter(a=>a.check_out).length;
        const dateLabel=document.getElementById('attDateLabel');
        if(dateLabel) dateLabel.textContent=date===new Date().toISOString().slice(0,10)?'Today':date;
        const set=(id,v)=>{const e=document.getElementById(id);if(e)e.textContent=v;};
        set('attTotal',total);set('attOpen',open);set('attCompleted',completed);
        const summary=document.getElementById('attendanceSummary');
        if(summary) summary.textContent=`${total} record${total===1?'':'s'} on ${date} • ${open} checked in • ${completed} completed`;
        b.innerHTML=rows.map(a=>`<tr>
            <td><b>${esc(a.member_id||'—')}</b></td>
            <td>${esc(a.name||'—')}</td>
            <td>${esc(a.phone||'—')}</td>
            <td>${esc(a.shift||'—')}</td>
            <td>${esc(a.check_in_time||'—')}</td>
            <td>${esc(a.check_out_time||'—')}</td>
            <td>${a.duration_minutes!=null ? formatDuration(a.duration_minutes) : '—'}</td>
            <td><span class="badge ${a.check_out?'':'warn'}">${esc(a.status||'Open')}</span></td>
            <td><div class="attendance-row-actions">${a.check_in&&!a.check_out?`<button type="button" class="small-btn" onclick="checkOut(${Number(a.attendance_id || a.id)})">Check Out</button>`:''}<button type="button" class="small-btn" onclick="viewAttendanceHistory(${Number(a.member_db_id)})">History</button></div></td>
        </tr>`).join('')||'<tr><td colspan="9" class="empty">No attendance records for this date.</td></tr>';
    }catch(e){b.innerHTML=`<tr><td colspan="9" class="empty">${esc(e.message||'Could not load attendance.')}</td></tr>`;}
}
async function openAttendanceForm(){
    let members=[], attendanceRows=[];
    try{
        const [md, ad]=await Promise.all([
            apiRequest('members_api.php?action=list'),
            apiRequest('attendance_api.php?action=attendance&date='+encodeURIComponent(new Date().toISOString().slice(0,10)))
        ]);
        members=md.members||[];
        attendanceRows=ad.attendance||[];
    }catch(e){toast(e.message);return;}

    const activeMembers=members.filter(m=>String(m.status||'Active').toLowerCase()==='active');
    const checkedIn=new Set(attendanceRows.filter(a=>a.check_in).map(a=>String(a.member_db_id)));
    const openCheckedIn=new Set(attendanceRows.filter(a=>a.check_in&&!a.check_out).map(a=>String(a.member_db_id)));

    const buildOptions=(action)=>{
        const list=action==='out'
            ? activeMembers.filter(m=>openCheckedIn.has(String(m.id)))
            : activeMembers.filter(m=>!checkedIn.has(String(m.id)));
        return '<option value="">Select member...</option>'+list.map(m=>`<option value="${Number(m.id)}">${esc(m.member_id)} — ${esc(m.name)} — ${esc(m.shift||'')}</option>`).join('');
    };

    modal('Mark Attendance',`<div class="form-grid">
        <div class="field"><label>Member</label><select id="att_member" required>${buildOptions('in')}</select></div>
        <div class="field"><label>Action</label><select id="att_action"><option value="in">Check In</option><option value="out">Check Out</option></select></div>
        ${formField('att_remarks','Remarks')}
        <div id="att_list_note" class="muted" style="grid-column:1/-1;margin-top:4px">Members already marked present today are hidden from Check In.</div>
    </div>`,async()=>{
        const member=document.getElementById('att_member').value;
        const action=document.getElementById('att_action').value;
        if(!member){toast('Please select member.');return;}
        const fd=new FormData();
        fd.append('member_id',member);
        fd.append('remarks',document.getElementById('att_remarks').value);
        fd.append('action',action);
        try{const d=await apiRequest('attendance_api.php?action=attendance',{method:'POST',body:fd});document.getElementById('appModal')?.remove();toast(d.message);loadAttendance();}catch(e){toast(e.message);}
    });

    const actionEl=document.getElementById('att_action');
    const memberEl=document.getElementById('att_member');
    const noteEl=document.getElementById('att_list_note');
    if(actionEl&&memberEl){
        actionEl.addEventListener('change',()=>{
            const action=actionEl.value;
            memberEl.innerHTML=buildOptions(action);
            if(noteEl) noteEl.textContent=action==='in'
                ? 'Members already marked present today are hidden from Check In.'
                : 'Only members currently checked in today are available for Check Out.';
        });
    }
}
async function checkOut(id){id=Number(id);if(!Number.isInteger(id)||id<=0){toast('Invalid attendance record.');return;}const fd=new FormData();fd.append('attendance_id',String(id));try{const d=await apiRequest('attendance_api.php?action=checkout',{method:'POST',body:fd});toast(d.message);loadAttendance();}catch(e){toast(e.message);}}
async function viewAttendanceHistory(memberId){
    if(!memberId)return;
    try{
        const d=await apiRequest('attendance_api.php?action=member_history&member_id='+Number(memberId));
        const rows=d.attendance||[];
        const member=rows[0]||{};
        const body=rows.map(a=>`<tr><td>${esc(a.attendance_date_display||a.attendance_date)}</td><td>${esc(a.check_in_time||'—')}</td><td>${esc(a.check_out_time||'—')}</td><td><span class="badge">${esc(a.status||'')}</span></td></tr>`).join('')||'<tr><td colspan="4" class="empty">No attendance history.</td></tr>';
        modal('Attendance History',`<p class="summary-line">${member.member_id?esc(member.member_id):'Member'} ${member.name?'— '+esc(member.name):''}</p><div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Check In</th><th>Check Out</th><th>Status</th></tr></thead><tbody>${body}</tbody></table></div>`,()=>document.getElementById('appModal')?.remove());
        document.getElementById('modalSave').textContent='Close';
    }catch(e){toast(e.message);}
}
async function loadEnquiries(){const b=document.getElementById('enquiryBody');if(!b)return;try{const d=await apiRequest('admin_api.php?action=enquiries');b.innerHTML=(d.enquiries||[]).map(x=>{const data=JSON.stringify(x).replace(/'/g,"&#39;");const convert=x.status!=='Converted'&&x.status!=='Closed'?` <button class="small-btn" onclick='convertEnquiry(${data})'>Convert</button>`:'';return `<tr><td>${esc(x.name)}</td><td>${esc(x.phone)}</td><td>${esc(x.requirement)}</td><td>${esc(x.follow_up||'—')}</td><td><span class="badge">${esc(x.status)}</span></td><td><button class="small-btn" onclick='openEnquiryForm(${data})'>Edit</button>${convert} ${String(document.body.dataset.role||'').toLowerCase()==='admin'?`<button class="small-btn" onclick="deleteEnquiry(${Number(x.id)})">Delete</button>`:''}</td></tr>`}).join('')||'<tr><td colspan="6" class="empty">No enquiries.</td></tr>';}catch(e){b.innerHTML=`<tr><td colspan="6" class="empty">${esc(e.message)}</td></tr>`}}
async function convertEnquiry(x){modal('Convert Enquiry to Member',`<div class="form-grid"><div class="field"><label>Member Name</label><input id="conv_name" value="${esc(x.name||'')}" disabled></div><div class="field"><label>Phone</label><input id="conv_phone" value="${esc(x.phone||'')}" disabled></div><div class="field"><label>Membership Plan</label><select id="conv_plan"><option>1 Month</option><option>3 Months</option><option>6 Months</option><option>12 Months</option><option>Custom Date</option></select></div><div class="field"><label>Shift</label><select id="conv_shift"><option>Morning Shift</option><option>Morning Shift Reserved</option><option>Evening Shift</option><option>Evening Shift Reserved</option><option selected>Full Day</option><option>Full Day Reserved</option></select></div>${formField('conv_join','Joining Date','date',new Date().toISOString().slice(0,10))}${formField('conv_valid','Validity Date','date','')}</div>`,async()=>{const fd=new FormData();fd.append('membership_plan',document.getElementById('conv_plan').value);fd.append('shift',document.getElementById('conv_shift').value);fd.append('joining_date',document.getElementById('conv_join').value);fd.append('validity_date',document.getElementById('conv_valid').value);try{const d=await apiRequest('admin_api.php?action=enquiries&convert='+Number(x.id),{method:'POST',body:fd});document.getElementById('appModal')?.remove();toast(d.message);loadEnquiries();loadDashboard();}catch(e){toast(e.message)}})}
function openEnquiryForm(x={}){modal(x.id?'Edit Enquiry':'New Enquiry',`<div class="form-grid">${formField('enq_name','Name','text',x.name||'','required')}${formField('enq_phone','Phone','text',x.phone||'')}${formField('enq_req','Requirement','text',x.requirement||'')}${formField('enq_follow','Follow-up','date',x.follow_up||'')}<div class="field"><label>Status</label><select id="enq_status"><option ${x.status==='Open'?'selected':''}>Open</option><option ${x.status==='Follow-up'?'selected':''}>Follow-up</option><option ${x.status==='Converted'?'selected':''}>Converted</option><option ${x.status==='Closed'?'selected':''}>Closed</option></select></div>${formField('enq_notes','Notes')}</div>`,async()=>{const fd=new FormData();if(x.id)fd.append('id',x.id);fd.append('name',document.getElementById('enq_name').value);fd.append('phone',document.getElementById('enq_phone').value);fd.append('requirement',document.getElementById('enq_req').value);fd.append('follow_up',document.getElementById('enq_follow').value);fd.append('status',document.getElementById('enq_status').value);fd.append('notes',document.getElementById('enq_notes').value);try{const d=await apiRequest('admin_api.php?action=enquiries',{method:'POST',body:fd});document.getElementById('appModal').remove();toast(d.message);loadEnquiries();}catch(e){toast(e.message)}})}
async function deleteEnquiry(id){if(!confirm('Delete this enquiry?'))return;try{const d=await apiRequest('admin_api.php?action=enquiries&id='+id,{method:'DELETE'});toast(d.message);loadEnquiries()}catch(e){toast(e.message)}}
async function loadExpenses(){const b=document.getElementById('expenseBody');if(!b)return;try{const d=await apiRequest('admin_api.php?action=expenses');b.innerHTML=(d.expenses||[]).map(x=>`<tr><td>${esc(x.expense_date)}</td><td>${esc(x.category)}</td><td>${esc(x.description)}</td><td>${money(x.amount)}</td><td>${esc(x.payment_method)}</td><td><button class="small-btn" onclick='openExpenseForm(${JSON.stringify(x).replace(/'/g,"&#39;")})'>Edit</button> ${String(document.body.dataset.role||'').toLowerCase()==='admin'?`<button class="small-btn" onclick="deleteExpense(${Number(x.id)})">Delete</button>`:''}</td></tr>`).join('')||'<tr><td colspan="6" class="empty">No expenses.</td></tr>';document.getElementById('expenseSummary').textContent='This month: '+money(d.month_total);}catch(e){b.innerHTML=`<tr><td colspan="6" class="empty">${esc(e.message)}</td></tr>`}}
function openExpenseForm(x={}){modal(x.id?'Edit Expense':'Add Expense',`<div class="form-grid">${formField('exp_date','Date','date',x.expense_date||new Date().toISOString().slice(0,10))}${formField('exp_cat','Category','text',x.category||'','required')}${formField('exp_desc','Description','text',x.description||'')}${formField('exp_amt','Amount','number',x.amount||'','min="0.01" step="0.01" required')}<div class="field"><label>Payment Method</label><select id="exp_pm"><option ${x.payment_method==='Cash'?'selected':''}>Cash</option><option ${x.payment_method==='UPI'?'selected':''}>UPI</option><option ${x.payment_method==='Online'?'selected':''}>Online</option><option ${x.payment_method==='Bank'?'selected':''}>Bank</option><option ${x.payment_method==='Card'?'selected':''}>Card</option><option ${x.payment_method==='Other'?'selected':''}>Other</option></select></div>${formField('exp_vendor','Vendor','text',x.vendor||'')}${formField('exp_notes','Notes','text',x.notes||'')}</div>`,async()=>{const fd=new FormData();if(x.id)fd.append('id',x.id);fd.append('expense_date',document.getElementById('exp_date').value);fd.append('category',document.getElementById('exp_cat').value);fd.append('description',document.getElementById('exp_desc').value);fd.append('amount',document.getElementById('exp_amt').value);fd.append('payment_method',document.getElementById('exp_pm').value);fd.append('vendor',document.getElementById('exp_vendor').value);fd.append('notes',document.getElementById('exp_notes').value);try{const d=await apiRequest('admin_api.php?action=expenses',{method:'POST',body:fd});document.getElementById('appModal').remove();toast(d.message);loadExpenses();}catch(e){toast(e.message)}})}
async function deleteExpense(id){if(!confirm('Delete this expense?'))return;try{const d=await apiRequest('admin_api.php?action=expenses&id='+id,{method:'DELETE'});toast(d.message);loadExpenses()}catch(e){toast(e.message)}}
async function initReports(){const t=new Date().toISOString().slice(0,10);const first=new Date();first.setDate(1);document.getElementById('reportTo').value=t;document.getElementById('reportFrom').value=first.toISOString().slice(0,10);}
async function runReport(type){const from=document.getElementById('reportFrom').value,to=document.getElementById('reportTo').value;if(!from||!to||from>to){toast('Please select a valid date range.');return;}try{const d=await apiRequest(`admin_api.php?action=report&type=${encodeURIComponent(type)}&from=${from}&to=${to}`);const out=document.getElementById('reportOutput');if(type==='summary'){const x=d.data||{};out.innerHTML=`<div class="grid"><div class="stat"><p>Total Members</p><h2>${x.members||0}</h2></div><div class="stat"><p>Active Members</p><h2>${x.active||0}</h2></div><div class="stat"><p>Attendance</p><h2>${x.attendance||0}</h2></div><div class="stat"><p>Fees Collected</p><h2>${money(x.fees||0)}</h2></div><div class="stat"><p>Expenses</p><h2>${money(x.expenses||0)}</h2></div><div class="stat"><p>Net Income</p><h2>${money(x.net_income||0)}</h2></div><div class="stat"><p>Enquiries</p><h2>${x.enquiries||0}</h2></div><div class="stat"><p>Converted</p><h2>${x.converted_enquiries||0}</h2></div></div>`;return;}const rows=d.data||[];if(!rows.length){out.innerHTML='<p class="empty">No data for selected range.</p>';return;}const keys=Object.keys(rows[0]);out.innerHTML=`<div class="table-wrap"><table class="table"><thead><tr>${keys.map(k=>`<th>${esc(k)}</th>`).join('')}</tr></thead><tbody>${rows.map(r=>`<tr>${keys.map(k=>`<td>${esc(r[k])}</td>`).join('')}</tr>`).join('')}</tbody></table></div><button class="small-btn" onclick='downloadCSV(${JSON.stringify(rows)})'>Export CSV</button>`;}catch(e){toast(e.message)}}

function downloadCSV(rows){if(!rows.length)return;const keys=Object.keys(rows[0]);const csv=[keys.join(','),...rows.map(r=>keys.map(k=>`"${String(r[k]??'').replace(/"/g,'""')}"`).join(','))].join('\n');const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='ar_library_report_'+new Date().toISOString().slice(0,10)+'.csv';a.click();URL.revokeObjectURL(a.href)}
function staffPhotoUrl(photo){ return photo ? String(photo) : ''; }
function staffAvatar(photo,name){ const p=staffPhotoUrl(photo); const initial=esc(String(name||'Staff').trim().charAt(0).toUpperCase()||'S'); return p ? `<img src="${esc(p)}" alt="${esc(name||'Staff')}" class="staff-avatar-img">` : initial; }
const STAFF_PERMISSION_LABELS={dashboard:'Dashboard',seats:'Seats & Lockers',members:'Members',fees:'Fees & Payments',attendance:'Attendance',attendance_qr:'Attendance QR',enquiry:'Enquiry',expenses:'Expenses',reports:'Reports',backup:'Backup & Restore (Admin Only)',settings:'Library Settings (Admin Only)'};
function renderStaffPermissions(selected=[], role='staff'){
    const box=document.getElementById('staffPermissions'); if(!box)return;
    const all=Object.keys(STAFF_PERMISSION_LABELS), set=new Set(Array.isArray(selected)?selected:[]), admin=String(role).toLowerCase()==='admin';
    box.innerHTML=all.map(k=>{const adminOnly=['backup','settings'].includes(k); return `<label class="permission-item"><input type="checkbox" name="staff_permission" value="${k}" ${admin||set.has(k)?'checked':''} ${(admin||adminOnly)?'disabled':''}><span>${STAFF_PERMISSION_LABELS[k]}${adminOnly?' 🔒':''}</span></label>`;}).join('');
}
function setAllStaffPermissions(checked){ document.querySelectorAll('#staffPermissions input[name="staff_permission"]').forEach(x=>{if(!x.disabled)x.checked=checked;}); }
function getSelectedStaffPermissions(){ return Array.from(document.querySelectorAll('#staffPermissions input[name="staff_permission"]:checked')).map(x=>x.value); }
function openStaffForm(staff=null){
    const panel=document.getElementById('staffFormPanel'), form=document.getElementById('staffForm'); if(!panel||!form)return;
    panel.classList.remove('hidden');
    form.reset(); document.getElementById('staff_id_db').value=staff?.id||0;
    document.getElementById('staff_id_input').value=staff?.staff_id||'';
    document.getElementById('staff_name_input').value=staff?.name||'';
    document.getElementById('staff_role_input').value=staff?.role||'staff';
    document.getElementById('staff_status_input').value=staff?.status||'active';
    renderStaffPermissions(staff?.permissions||Object.keys(STAFF_PERMISSION_LABELS), staff?.role||'staff');
    const roleSelect=document.getElementById('staff_role_input');
    if(roleSelect && !roleSelect.dataset.bound){
        roleSelect.addEventListener('change',()=>renderStaffPermissions(getSelectedStaffPermissions(), roleSelect.value));
        roleSelect.dataset.bound='1';
    }
    document.getElementById('staff_password_input').value='';
    document.getElementById('staffPasswordHint').textContent=staff?'(leave blank to keep current)':'*';
    const preview=document.getElementById('staffPhotoPreview'); preview.innerHTML=staff?.photo?`<img src="${esc(staff.photo)}" alt="Current photo">`:'No photo selected';
    document.getElementById('staffFormTitle').textContent=staff?'Edit Staff':'Add Staff';
    panel.scrollIntoView({behavior:'smooth',block:'start'});
}
function closeStaffForm(){ document.getElementById('staffFormPanel')?.classList.add('hidden'); }
async function loadStaff(){
    const box=document.getElementById('staffList'); if(!box)return;
    try{
        const d=await apiRequest('admin_api.php?action=staff'); const rows=d.staff||[];
        if(!rows.length){box.innerHTML='<div class="empty">No staff accounts found.</div>';return;}
        box.innerHTML=rows.map(x=>`<div class="staff-card">
            <div class="staff-card-person"><div class="staff-card-avatar">${staffAvatar(x.photo,x.name)}</div><div><strong>${esc(x.name)}</strong><small>${esc(x.staff_id)}</small></div></div>
            <div class="staff-card-meta"><span class="role-pill ${String(x.role).toLowerCase()==='admin'?'admin':'staff'}">${esc(String(x.role||'staff').toUpperCase())}</span><span class="status-pill ${String(x.status).toLowerCase()==='active'?'active':'inactive'}">${esc(String(x.status||'active').toUpperCase())}</span></div>
            <div class="staff-card-actions"><button class="member-row-btn edit" type="button" onclick='editStaff(${JSON.stringify(x).replace(/</g,'\\u003c')})'>Edit</button>${x.staff_id!=='STF-001'?`<button class="member-row-btn delete" type="button" onclick="deleteStaff(${Number(x.id)})">Delete</button>`:''}</div>
        </div>`).join('');
    }catch(e){box.innerHTML=`<div class="empty">${esc(e.message)}</div>`;}
}
function editStaff(staff){ openStaffForm(staff); }
async function saveStaff(event){
    event.preventDefault(); const form=document.getElementById('staffForm'); if(!form)return;
    try{ const fd=new FormData(form); fd.set('permissions',JSON.stringify(getSelectedStaffPermissions())); const d=await apiRequest('admin_api.php?action=staff',{method:'POST',body:fd}); toast(d.message||'Staff saved'); closeStaffForm(); loadStaff(); }
    catch(e){ toast(e.message); }
}
async function deleteStaff(id){
    if(!confirm('Delete this staff account? This cannot be undone.'))return;
    try{const d=await apiRequest('admin_api.php?action=staff&id='+encodeURIComponent(id),{method:'DELETE'});toast(d.message||'Staff deleted');loadStaff();}catch(e){toast(e.message);}
}

async function performFreshReset(){
    if(String(document.body.dataset.role||'').toLowerCase()!=='admin'){toast('Admin permission required.');return;}
    if(!confirm('WARNING: This permanently removes ALL old Members, Fee Records, Pending Fees, Payments, Attendance, Seats, Lockers, Enquiries and Activity Logs. Staff/Admin accounts, Settings and Expenses remain. Continue?')) return;
    const typed=prompt('Type RESET to permanently confirm the Fresh Start:');
    if(typed!=='RESET'){toast('Reset cancelled. You must type RESET exactly.');return;}
    try{
        const fd=new FormData(); fd.append('confirm','RESET');
        const d=await apiRequest('admin_api.php?action=fresh_start_reset',{method:'POST',body:fd});
        toast(d.message||'Fresh start completed.');
        loadDashboard();
        loadSettings();
    }catch(e){toast(e.message||'Fresh start failed.');}
}
async function loadSettings(){try{const d=await apiRequest('admin_api.php?action=settings');const s=d.settings||{};for(const k of ['library_name','phone','opening_time','closing_time','morning_end','evening_start','monthly_fee','total_seats','total_lockers','currency','address','receipt_footer','attendance_latitude','attendance_longitude','attendance_radius_meters']){const el=document.getElementById('set_'+k);if(el)el.value=s[k]||({library_name:'AR Library',opening_time:'08:00',closing_time:'20:00',morning_end:'14:00',evening_start:'14:00',monthly_fee:'1100',total_seats:'76',total_lockers:'76',currency:'INR'}[k]||'')}}catch(e){toast(e.message)}}
async function saveSettings(){const form=document.getElementById('settingsForm');if(!form)return;try{const d=await apiRequest('admin_api.php?action=settings',{method:'POST',body:new FormData(form)});toast(d.message)}catch(e){toast(e.message)}}


document.addEventListener('change', function(e){
    if(e.target && e.target.id==='staff_role_input') renderStaffPermissions(getSelectedStaffPermissions(),e.target.value);
    if(e.target && e.target.id==='staff_photo_input'){
        const file=e.target.files?.[0], box=document.getElementById('staffPhotoPreview');
        if(!box)return;
        if(!file){box.textContent='No photo selected';return;}
        if(!file.type.startsWith('image/')){box.textContent='Invalid photo';return;}
        const url=URL.createObjectURL(file); box.innerHTML=`<img src="${url}" alt="Preview">`;
    }
});
