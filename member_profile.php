<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_permission('members');

if (!isset($_SESSION["staff_id"])) {
    header("Location: index.php");
    exit;
}

$memberDbId = intval($_GET["id"] ?? 0);

if ($memberDbId <= 0) {
    die("Invalid member ID.");
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

<title>Member Profile - AR Library</title>

<link
    rel="preconnect"
    href="https://fonts.googleapis.com"
>

<link
    rel="preconnect"
    href="https://fonts.gstatic.com"
>

<link
    href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap"
    rel="stylesheet"
>

<style>

* {
    box-sizing: border-box;
}

body {

    margin: 0;

    background: #f4f6fb;

    color: #17264f;

    font-family: Nunito, sans-serif;
}

.container {

    width: min(1200px, calc(100% - 28px));

    margin: 24px auto 50px;
}

.top {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    margin-bottom: 18px;
}

.title h1 {

    margin: 0;

    font-size: 30px;
}

.title p {

    margin: 5px 0 0;

    color: #68748d;
}

.actions {

    display: flex;

    gap: 10px;

    flex-wrap: wrap;
}

button {

    border: 0;

    cursor: pointer;

    font-family: inherit;

    font-weight: 800;
}

.btn {

    padding: 12px 18px;

    border-radius: 12px;

    background: #e9edf6;

    color: #17264f;
}

.btn.primary {

    background: #30345f;

    color: white;
}

.profile-card {

    background: white;

    border-radius: 20px;

    overflow: hidden;

    box-shadow: 0 10px 35px rgba(35, 45, 85, .08);
}

.profile-header {

    background: #30345f;

    color: white;

    padding: 28px;

    display: flex;

    align-items: center;

    gap: 20px;
}

.avatar {

    width: 76px;

    height: 76px;

    border-radius: 50%;

    background: white;

    color: #30345f;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 32px;

    font-weight: 900;
}

.profile-header h2 {

    margin: 0 0 4px;

    font-size: 25px;
}

.profile-header p {

    margin: 0;

    opacity: .85;
}

.status {

    margin-left: auto;

    background: white;

    color: #267443;

    padding: 9px 16px;

    border-radius: 30px;

    font-weight: 900;
}

.tabs {

    display: flex;

    gap: 8px;

    padding: 12px;

    background: #f4f6fb;

    overflow-x: auto;
}

.tab {

    padding: 12px 20px;

    border-radius: 12px;

    background: transparent;

    white-space: nowrap;

    font-size: 15px;
}

.tab.active {

    background: #30345f;

    color: white;
}

.content {

    padding: 28px;
}

.section-title {

    margin: 0 0 18px;

    font-size: 22px;
}

.grid {

    display: grid;

    grid-template-columns: repeat(2, 1fr);

    gap: 16px;
}

.box {

    border: 1px solid #e1e5ee;

    border-radius: 14px;

    padding: 17px;

    background: #fbfcfe;
}

.box.full {

    grid-column: 1 / -1;
}

.box label {

    display: block;

    font-size: 12px;

    color: #73809a;

    font-weight: 800;

    text-transform: uppercase;

    margin-bottom: 7px;
}

.box strong {

    font-size: 16px;
}

.form-grid {

    display: grid;

    grid-template-columns: repeat(2, 1fr);

    gap: 16px;
}

.field label {

    display: block;

    margin-bottom: 7px;

    font-weight: 800;
}

.field input,
.field select,
.field textarea {

    width: 100%;

    padding: 12px;

    border: 1px solid #dce1ea;

    border-radius: 10px;

    font-family: inherit;

    font-size: 14px;

    outline: none;
}

.field textarea {

    min-height: 90px;

    resize: vertical;
}

.field.full {

    grid-column: 1 / -1;
}

.save-row {

    margin-top: 18px;

    display: flex;

    gap: 10px;
}

.summary {

    display: grid;

    grid-template-columns: repeat(3, 1fr);

    gap: 15px;

    margin-bottom: 20px;
}

.summary-box {

    background: #f5f7fb;

    padding: 18px;

    border-radius: 14px;
}

.summary-box small {

    color: #73809a;
}

.summary-box strong {

    display: block;

    margin-top: 5px;

    font-size: 21px;
}

.table-wrap {

    overflow-x: auto;
}

table {

    width: 100%;

    border-collapse: collapse;
}

th,
td {

    padding: 13px;

    border-bottom: 1px solid #e8ebf1;

    text-align: left;

    white-space: nowrap;
}

th {

    color: #68748d;

    font-size: 13px;
}

.badge {

    display: inline-block;

    padding: 6px 11px;

    border-radius: 20px;

    background: #e9edff;

    font-size: 13px;

    font-weight: 800;
}

.badge.green {

    background: #e3f5e9;

    color: #267443;
}

.badge.red {

    background: #fde7ea;

    color: #a83248;
}

.empty {

    padding: 30px;

    text-align: center;

    color: #748099;
}

.modal {

    position: fixed;

    inset: 0;

    background: rgba(20, 25, 50, .45);

    display: none;

    align-items: center;

    justify-content: center;

    padding: 20px;

    z-index: 1000;
}

.modal.show {

    display: flex;
}

.modal-card {

    background: white;

    width: min(600px, 100%);

    max-height: 90vh;

    overflow-y: auto;

    border-radius: 18px;

    padding: 25px;
}

.modal-head {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 20px;
}

.modal-head h3 {

    margin: 0;
}

.close {

    background: #eef1f7;

    width: 35px;

    height: 35px;

    border-radius: 50%;
}

@media (max-width: 700px) {

    .top {

        align-items: flex-start;

        flex-direction: column;
    }

    .profile-header {

        flex-wrap: wrap;
    }

    .status {

        margin-left: 0;
    }

    .grid,
    .form-grid,
    .summary {

        grid-template-columns: 1fr;
    }

    .box.full,
    .field.full {

        grid-column: auto;
    }

    .content {

        padding: 18px;
    }
}

</style>

</head>

<body>

<div class="container">

    <div class="top">

        <div class="title">

            <h1>Member Profile</h1>

            <p>AR Library Management System</p>

        </div>

        <div class="actions">

            <button
                class="btn"
                onclick="goBack()"
            >
                ← Back
            </button>

            <button
                class="btn primary"
                onclick="window.print()"
            >
                🖨 Print
            </button>

        </div>

    </div>


    <div class="profile-card">

        <div class="profile-header">

            <div
                class="avatar"
                id="avatar"
            >
                M
            </div>

            <div>

                <h2 id="memberName">
                    Loading...
                </h2>

                <p>
                    Member ID:
                    <span id="memberId">
                        —
                    </span>
                </p>

            </div>

            <span
                class="status"
                id="memberStatus"
            >
                Active
            </span>

        </div>


        <div class="tabs">

            <button
                class="tab active"
                data-tab="overview"
                onclick="openTab('overview')"
            >
                👤 Overview
            </button>

            <button
                class="tab"
                data-tab="edit"
                onclick="openTab('edit')"
            >
                ✏️ Edit
            </button>

            <button
                class="tab"
                data-tab="fee"
                onclick="openTab('fee')"
            >
                💰 Fee
            </button>

            <button
                class="tab"
                data-tab="attendance"
                onclick="openTab('attendance')"
            >
                📋 Attendance
            </button>

            <button
                class="tab"
                data-tab="seat"
                onclick="openTab('seat')"
            >
                💺 Seat
            </button>

        </div>


        <div class="content">


            <!-- =================================================
                 OVERVIEW
            ================================================== -->

            <section
                id="tab-overview"
                class="tab-content"
            >

                <h2 class="section-title">
                    Member Information
                </h2>

                <div class="grid">

                    <div class="box">
                        <label>Member ID</label>
                        <strong id="vMemberId">—</strong>
                    </div>

                    <div class="box">
                        <label>Full Name</label>
                        <strong id="vName">—</strong>
                    </div>

                    <div class="box">
                        <label>Phone</label>
                        <strong id="vPhone">—</strong>
                    </div>

                    <div class="box">
                        <label>Email</label>
                        <strong id="vEmail">—</strong>
                    </div>

                    <div class="box">
                        <label>Membership Plan</label>
                        <strong id="vPlan">—</strong>
                    </div>

                    <div class="box">
                        <label>Shift</label>
                        <strong id="vShift">—</strong>
                    </div>

                    <div class="box">
                        <label>Joining Date</label>
                        <strong id="vJoining">—</strong>
                    </div>

                    <div class="box">
                        <label>Validity Date</label>
                        <strong id="vValidity">—</strong>
                    </div>

                    <div class="box full">
                        <label>Address</label>
                        <strong id="vAddress">—</strong>
                    </div>

                    <div class="box full">
                        <label>Record Created</label>
                        <strong id="vCreated">—</strong>
                    </div>

                </div>

            </section>


            <!-- =================================================
                 EDIT
            ================================================== -->

            <section
                id="tab-edit"
                class="tab-content"
                style="display:none"
            >

                <h2 class="section-title">
                    Edit Member
                </h2>

                <form id="editForm">

                    <div class="form-grid">

                        <div class="field">

                            <label>Member ID</label>

                            <input
                                id="eMemberId"
                                required
                            >

                        </div>

                        <div class="field">

                            <label>Full Name</label>

                            <input
                                id="eName"
                                required
                            >

                        </div>

                        <div class="field">

                            <label>Date of Birth</label>
                            <input id="eDob" type="date">

                        </div>

                        <div class="field">

                            <label>Phone</label>

                            <input id="ePhone">

                        </div>

                        <div class="field">

                            <label>Email</label>

                            <input
                                id="eEmail"
                                type="email"
                            >

                        </div>

                        <div class="field">

                            <label>Membership Plan</label>

                            <select id="ePlan">

                                <option>1 Month</option>
                                <option>3 Months</option>
                                <option>6 Months</option>
                                <option>Custom Date</option>

                            </select>

                        </div>

                        <div class="field">

                            <label>Shift</label>

                            <select id="eShift">

                                <option>Morning Shift</option>
                                <option>Morning Shift Reserved</option>
                                <option>Evening Shift</option>
                                <option>Evening Shift Reserved</option>
                                <option>Full Day</option>
                                <option>Full Day Reserved</option>

                            </select>

                        </div>

                        <div class="field">

                            <label>Joining Date</label>

                            <input
                                id="eJoining"
                                type="date"
                                required
                            >

                        </div>

                        <div class="field">

                            <label>Validity Date</label>

                            <input
                                id="eValidity"
                                type="date"
                                required
                            >

                        </div>

                        <div class="field full">

                            <label>Address</label>

                            <textarea id="eAddress"></textarea>

                        </div>

                    </div>

                    <div class="save-row">

                        <button
                            class="btn primary"
                            type="submit"
                        >
                            ✓ Save Changes
                        </button>

                    </div>

                </form>

            </section>


            <!-- =================================================
                 FEE
            ================================================== -->

            <section
                id="tab-fee"
                class="tab-content"
                style="display:none"
            >

                <h2 class="section-title">
                    Fee Information
                </h2>

                <div class="summary">

                    <div class="summary-box">

                        <small>Total Paid</small>

                        <strong id="totalPaid">
                            ₹0
                        </strong>

                    </div>

                    <div class="summary-box">

                        <small>Total Pending</small>

                        <strong id="totalPending">
                            ₹0
                        </strong>

                    </div>

                    <div class="summary-box">

                        <small>Membership Plan</small>

                        <strong id="feePlan">
                            —
                        </strong>

                    </div>

                </div>


                <button
                    class="btn primary"
                    onclick="openFeeModal()"
                >
                    + Add Fee Payment
                </button>


                <div
                    class="table-wrap"
                    style="margin-top:20px"
                >

                    <table>

                        <thead>

                            <tr>

                                <th>Receipt</th>
                                <th>Amount</th>
                                <th>Payment Date</th>
                                <th>Due Date</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Action</th>

                            </tr>

                        </thead>

                        <tbody id="feeBody">

                            <tr>
                                <td
                                    colspan="7"
                                    class="empty"
                                >
                                    Loading...
                                </td>
                            </tr>

                        </tbody>

                    </table>

                </div>

            </section>


            <!-- =================================================
                 ATTENDANCE
            ================================================== -->

            <section
                id="tab-attendance"
                class="tab-content"
                style="display:none"
            >

                <h2 class="section-title">
                    Attendance
                </h2>

                <button
                    class="btn primary"
                    onclick="openAttendanceModal()"
                >
                    + Mark Attendance
                </button>

                <div
                    class="table-wrap"
                    style="margin-top:20px"
                >

                    <table>

                        <thead>

                            <tr>
                                <th>Date</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Status</th>
                                <th>Remarks</th>
                            </tr>

                        </thead>

                        <tbody id="attendanceBody">

                            <tr>
                                <td
                                    colspan="5"
                                    class="empty"
                                >
                                    Loading...
                                </td>
                            </tr>

                        </tbody>

                    </table>

                </div>

            </section>


            <!-- =================================================
                 SEAT
            ================================================== -->

            <section
                id="tab-seat"
                class="tab-content"
                style="display:none"
            >

                <h2 class="section-title">
                    Seat Information
                </h2>

                <form id="seatForm">

                    <div class="form-grid">

                        <div class="field">

                            <label>Seat Number</label>

                            <input
                                id="sSeat"
                                placeholder="A-12"
                                required
                            >

                        </div>

                        <div class="field">

                            <label>Shift</label>

                            <select id="sShift">

                                <option>
                                    Morning Shift
                                </option>

                                <option>
                                    Evening Shift
                                </option>

                                <option selected>
                                    Full Day
                                </option>

                            </select>

                        </div>

                        <div class="field">

                            <label>Start Date</label>

                            <input
                                id="sStart"
                                type="date"
                            >

                        </div>

                        <div class="field">

                            <label>End Date</label>

                            <input
                                id="sEnd"
                                type="date"
                            >

                        </div>

                        <div class="field">

                            <label>Status</label>

                            <select id="sStatus">

                                <option>
                                    Assigned
                                </option>

                                <option>
                                    Reserved
                                </option>

                                <option>
                                    Inactive
                                </option>

                            </select>

                        </div>

                        <div class="field">

                            <label>Remarks</label>

                            <input id="sRemarks">

                        </div>

                    </div>

                    <div class="save-row">

                        <button
                            class="btn primary"
                            type="submit"
                        >
                            ✓ Save Seat
                        </button>

                    </div>

                </form>

            </section>

        </div>

    </div>

</div>


<!-- =====================================================
     FEE MODAL
===================================================== -->

<div
    class="modal"
    id="feeModal"
>

    <div class="modal-card">

        <div class="modal-head">

            <h3>Add Fee Payment</h3>

            <button
                class="close"
                onclick="closeModal('feeModal')"
            >
                ×
            </button>

        </div>

        <form id="feeForm">

            <div class="form-grid">

                <div class="field">

                    <label>Amount</label>

                    <input
                        id="feeAmount"
                        type="number"
                        min="1"
                        step="0.01"
                        placeholder="1200"
                        required
                    >

                </div>

                <div class="field">

                    <label>Payment Date</label>

                    <input
                        id="feePaymentDate"
                        type="date"
                        required
                    >

                </div>

                <div class="field">

                    <label>Due Date</label>

                    <input
                        id="feeDueDate"
                        type="date"
                    >

                </div>

                <div class="field">

                    <label>Payment Method</label>

                    <select id="feeMethod">

                        <option>Cash</option>
                        <option>UPI</option>
                        <option>Card</option>
                        <option>Bank Transfer</option>
                        <option>Online</option>

                    </select>

                </div>

                <div class="field">

                    <label>Status</label>

                    <select id="feeStatus">

                        <option>Paid</option>
                        <option>Pending</option>

                    </select>

                </div>

                <div class="field">

                    <label>Remarks</label>

                    <input
                        id="feeRemarks"
                        placeholder="Optional"
                    >

                </div>

            </div>

            <div class="save-row">

                <button
                    class="btn primary"
                    type="submit"
                >
                    ✓ Save Payment
                </button>

            </div>

        </form>

    </div>

</div>


<!-- =====================================================
     ATTENDANCE MODAL
===================================================== -->

<div
    class="modal"
    id="attendanceModal"
>

    <div class="modal-card">

        <div class="modal-head">

            <h3>Mark Attendance</h3>

            <button
                class="close"
                onclick="closeModal('attendanceModal')"
            >
                ×
            </button>

        </div>

        <form id="attendanceForm">

            <div class="form-grid">

                <div class="field">

                    <label>Date</label>

                    <input
                        id="aDate"
                        type="date"
                        required
                    >

                </div>

                <div class="field">

                    <label>Status</label>

                    <select id="aStatus">

                        <option>Present</option>
                        <option>Absent</option>
                        <option>Late</option>
                        <option>Leave</option>

                    </select>

                </div>

                <div class="field">

                    <label>Check In</label>

                    <input
                        id="aIn"
                        type="time"
                    >

                </div>

                <div class="field">

                    <label>Check Out</label>

                    <input
                        id="aOut"
                        type="time"
                    >

                </div>

                <div class="field full">

                    <label>Remarks</label>

                    <input id="aRemarks">

                </div>

            </div>

            <div class="save-row">

                <button
                    class="btn primary"
                    type="submit"
                >
                    ✓ Save Attendance
                </button>

            </div>

        </form>

    </div>

</div>


<script>

const memberDbId =
    <?php echo $memberDbId; ?>;


/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
*/

async function api(url, options = {}) {

    const method = String(options.method || "GET").toUpperCase();
    const headers = new Headers(options.headers || {});
    if (method !== "GET" && method !== "HEAD") {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || "";
        if (token) headers.set("X-CSRF-Token", token);
    }

    const response = await fetch(
        url,
        {
            cache: "no-store",
            credentials: "same-origin",
            ...options,
            headers
        }
    );

    const data =
        await response.json();

    if (!data.success) {

        throw new Error(
            data.message ||
            "Request failed."
        );
    }

    return data;
}


/*
|--------------------------------------------------------------------------
| LOAD MEMBER
|--------------------------------------------------------------------------
*/

async function loadMember() {

    try {

        const data =
            await api(
                "members_api.php?action=get&id=" +
                encodeURIComponent(String(memberDbId))
            );

        const m =
            data.member;

        document.getElementById(
            "memberName"
        ).textContent =
            m.name || "Member";

        document.getElementById(
            "memberId"
        ).textContent =
            m.member_id || "—";

        document.getElementById(
            "avatar"
        ).textContent =
            (m.name || "M")
                .charAt(0)
                .toUpperCase();

        document.getElementById(
            "memberStatus"
        ).textContent =
            m.status || "Active";


        setText(
            "vMemberId",
            m.member_id
        );

        setText(
            "vName",
            m.name
        );

        setText(
            "vPhone",
            m.phone || "—"
        );

        setText(
            "vEmail",
            m.email || "—"
        );

        setText(
            "vPlan",
            m.membership_plan || "—"
        );

        setText(
            "vShift",
            m.shift || "Full Day"
        );

        setText(
            "vJoining",
            formatDate(m.joining_date)
        );

        setText(
            "vValidity",
            formatDate(m.validity_date)
        );

        setText(
            "vAddress",
            m.address || "—"
        );

        setText(
            "vCreated",
            formatDateTime(m.created_at)
        );


        /*
        | Edit fields
        */

        setValue(
            "eMemberId",
            m.member_id
        );

        setValue(
            "eName",
            m.name
        );

        setValue(
            "eDob",
            m.date_of_birth
        );

        setValue(
            "ePhone",
            m.phone
        );

        setValue(
            "eEmail",
            m.email
        );

        setValue(
            "ePlan",
            m.membership_plan
        );

        setValue(
            "eShift",
            m.shift || "Full Day"
        );

        setValue(
            "eJoining",
            m.joining_date
        );

        setValue(
            "eValidity",
            m.validity_date
        );

        setValue(
            "eAddress",
            m.address
        );

        setText(
            "feePlan",
            m.membership_plan || "—"
        );

        loadFees();

    } catch (error) {

        const message = error?.message || "Could not load member profile.";
        const status = document.getElementById("memberStatus");
        if (status) status.textContent = "Error";
        const name = document.getElementById("memberName");
        if (name) name.textContent = message;
        console.error("Member profile load failed:", error);
        alert(message);
    }
}


/*
|--------------------------------------------------------------------------
| FEE
|--------------------------------------------------------------------------
*/

async function loadFees() {

    const body =
        document.getElementById(
            "feeBody"
        );

    try {

        const data =
            await api(
                "members_api.php?action=fees&id=" +
                memberDbId
            );

        const fees =
            data.fees || [];

        document.getElementById(
            "totalPaid"
        ).textContent =
            "₹" +
            money(
                data.summary.total_paid
            );

        document.getElementById(
            "totalPending"
        ).textContent =
            "₹" +
            money(
                data.summary.total_pending
            );


        if (fees.length === 0) {

            body.innerHTML = `
                <tr>
                    <td
                        colspan="7"
                        class="empty"
                    >
                        No fee records found.
                    </td>
                </tr>
            `;

            return;
        }


        body.innerHTML =
            fees.map(f => `

                <tr>

                    <td>
                        ${escapeHtml(
                            f.receipt_no || "—"
                        )}
                    </td>

                    <td>
                        ₹${money(f.amount)}
                    </td>

                    <td>
                        ${formatDate(
                            f.payment_date
                        )}
                    </td>

                    <td>
                        ${formatDate(
                            f.due_date
                        )}
                    </td>

                    <td>
                        ${escapeHtml(
                            f.payment_method || "—"
                        )}
                    </td>

                    <td>

                        <span class="badge ${
                            f.status === "Paid"
                                ? "green"
                                : "red"
                        }">

                            ${escapeHtml(
                                f.status
                            )}

                        </span>

                    </td>

                    <td>

                        <button
                            class="btn"
                            onclick="deleteFee(${Number(f.id)})"
                        >
                            Delete
                        </button>

                    </td>

                </tr>

            `).join("");

    } catch (error) {

        body.innerHTML = `
            <tr>
                <td
                    colspan="7"
                    class="empty"
                >
                    ${escapeHtml(
                        error.message
                    )}
                </td>
            </tr>
        `;
    }
}


function openFeeModal() {

    document.getElementById(
        "feePaymentDate"
    ).value =
        today();

    document.getElementById(
        "feeModal"
    ).classList.add("show");
}


document.getElementById(
    "feeForm"
).addEventListener(
    "submit",
    async function(event) {

        event.preventDefault();

        const form =
            new FormData();

        form.append(
            "member_id",
            memberDbId
        );

        form.append(
            "amount",
            document.getElementById(
                "feeAmount"
            ).value
        );

        form.append(
            "payment_date",
            document.getElementById(
                "feePaymentDate"
            ).value
        );

        form.append(
            "due_date",
            document.getElementById(
                "feeDueDate"
            ).value
        );

        form.append(
            "payment_method",
            document.getElementById(
                "feeMethod"
            ).value
        );

        form.append(
            "status",
            document.getElementById(
                "feeStatus"
            ).value
        );

        form.append(
            "remarks",
            document.getElementById(
                "feeRemarks"
            ).value
        );


        try {

            const data =
                await api(
                    "members_api.php?action=add_fee",
                    {
                        method: "POST",
                        body: form
                    }
                );

            alert(
                data.message +
                "\nReceipt: " +
                data.receipt_no
            );

            this.reset();

            closeModal("feeModal");

            loadFees();

        } catch (error) {

            alert(
                error.message
            );
        }

    }
);


async function deleteFee(id) {

    if (
        !confirm(
            "Delete this fee record?"
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

        await api(
            "members_api.php?action=delete_fee",
            {
                method: "POST",
                body: form
            }
        );

        loadFees();

    } catch (error) {

        alert(
            error.message
        );
    }
}


/*
|--------------------------------------------------------------------------
| ATTENDANCE
|--------------------------------------------------------------------------
*/

async function loadAttendance() {

    const body =
        document.getElementById(
            "attendanceBody"
        );

    try {

        const data =
            await api(
                "members_api.php?action=attendance&id=" +
                memberDbId
            );

        const rows =
            data.attendance || [];


        if (rows.length === 0) {

            body.innerHTML = `
                <tr>
                    <td
                        colspan="5"
                        class="empty"
                    >
                        No attendance records found.
                    </td>
                </tr>
            `;

            return;
        }


        body.innerHTML =
            rows.map(a => `

                <tr>

                    <td>
                        ${formatDate(
                            a.attendance_date
                        )}
                    </td>

                    <td>
                        ${escapeHtml(
                            a.check_in || "—"
                        )}
                    </td>

                    <td>
                        ${escapeHtml(
                            a.check_out || "—"
                        )}
                    </td>

                    <td>

                        <span class="badge">

                            ${escapeHtml(
                                a.status
                            )}

                        </span>

                    </td>

                    <td>
                        ${escapeHtml(
                            a.remarks || "—"
                        )}
                    </td>

                </tr>

            `).join("");

    } catch (error) {

        body.innerHTML = `
            <tr>
                <td
                    colspan="5"
                    class="empty"
                >
                    ${escapeHtml(
                        error.message
                    )}
                </td>
            </tr>
        `;
    }
}


function openAttendanceModal() {

    document.getElementById(
        "aDate"
    ).value =
        today();

    document.getElementById(
        "attendanceModal"
    ).classList.add("show");
}


document.getElementById(
    "attendanceForm"
).addEventListener(
    "submit",
    async function(event) {

        event.preventDefault();

        const form =
            new FormData();

        form.append(
            "member_id",
            memberDbId
        );

        form.append(
            "attendance_date",
            document.getElementById(
                "aDate"
            ).value
        );

        form.append(
            "check_in",
            document.getElementById(
                "aIn"
            ).value
        );

        form.append(
            "check_out",
            document.getElementById(
                "aOut"
            ).value
        );

        form.append(
            "status",
            document.getElementById(
                "aStatus"
            ).value
        );

        form.append(
            "remarks",
            document.getElementById(
                "aRemarks"
            ).value
        );


        try {

            const data =
                await api(
                    "members_api.php?action=add_attendance",
                    {
                        method: "POST",
                        body: form
                    }
                );

            alert(
                data.message
            );

            this.reset();

            closeModal(
                "attendanceModal"
            );

            loadAttendance();

        } catch (error) {

            alert(
                error.message
            );
        }

    }
);


/*
|--------------------------------------------------------------------------
| SEAT
|--------------------------------------------------------------------------
*/

async function loadSeat() {

    try {

        const data =
            await api(
                "members_api.php?action=seat&id=" +
                memberDbId
            );

        const s =
            data.seat;


        if (!s) {

            setValue(
                "sSeat",
                ""
            );

            setValue(
                "sShift",
                "Full Day"
            );

            setValue(
                "sStart",
                ""
            );

            setValue(
                "sEnd",
                ""
            );

            setValue(
                "sStatus",
                "Assigned"
            );

            setValue(
                "sRemarks",
                ""
            );

            return;
        }


        setValue(
            "sSeat",
            s.seat_no
        );

        setValue(
            "sShift",
            s.shift || "Full Day"
        );

        setValue(
            "sStart",
            s.start_date
        );

        setValue(
            "sEnd",
            s.end_date
        );

        setValue(
            "sStatus",
            s.status || "Assigned"
        );

        setValue(
            "sRemarks",
            s.remarks
        );

    } catch (error) {

        alert(
            error.message
        );
    }
}


document.getElementById(
    "seatForm"
).addEventListener(
    "submit",
    async function(event) {

        event.preventDefault();

        const form =
            new FormData();

        form.append(
            "member_id",
            memberDbId
        );

        form.append(
            "seat_no",
            document.getElementById(
                "sSeat"
            ).value
        );

        form.append(
            "shift",
            document.getElementById(
                "sShift"
            ).value
        );

        form.append(
            "start_date",
            document.getElementById(
                "sStart"
            ).value
        );

        form.append(
            "end_date",
            document.getElementById(
                "sEnd"
            ).value
        );

        form.append(
            "status",
            document.getElementById(
                "sStatus"
            ).value
        );

        form.append(
            "remarks",
            document.getElementById(
                "sRemarks"
            ).value
        );


        try {

            const data =
                await api(
                    "members_api.php?action=save_seat",
                    {
                        method: "POST",
                        body: form
                    }
                );

            alert(
                data.message
            );

            loadSeat();

        } catch (error) {

            alert(
                error.message
            );
        }

    }
);


/*
|--------------------------------------------------------------------------
| EDIT MEMBER
|--------------------------------------------------------------------------
*/

document.getElementById(
    "editForm"
).addEventListener(
    "submit",
    async function(event) {

        event.preventDefault();

        const form =
            new FormData();

        form.append(
            "id",
            memberDbId
        );

        form.append(
            "member_id",
            document.getElementById(
                "eMemberId"
            ).value
        );

        form.append(
            "name",
            document.getElementById(
                "eName"
            ).value
        );

        form.append(
            "date_of_birth",
            document.getElementById("eDob")?.value || ""
        );

        form.append(
            "phone",
            document.getElementById(
                "ePhone"
            ).value
        );

        form.append(
            "email",
            document.getElementById(
                "eEmail"
            ).value
        );

        form.append(
            "membership_plan",
            document.getElementById(
                "ePlan"
            ).value
        );

        form.append(
            "shift",
            document.getElementById(
                "eShift"
            ).value
        );

        form.append(
            "joining_date",
            document.getElementById(
                "eJoining"
            ).value
        );

        form.append(
            "validity_date",
            document.getElementById(
                "eValidity"
            ).value
        );

        form.append(
            "address",
            document.getElementById(
                "eAddress"
            ).value
        );


        try {

            const data =
                await api(
                    "members_api.php?action=update",
                    {
                        method: "POST",
                        body: form
                    }
                );

            alert(
                data.message
            );

            await loadMember();

            openTab(
                "overview"
            );

        } catch (error) {

            alert(
                error.message
            );
        }

    }
);


/*
|--------------------------------------------------------------------------
| TABS
|--------------------------------------------------------------------------
*/

function openTab(tab) {

    document
        .querySelectorAll(
            ".tab-content"
        )
        .forEach(section => {

            section.style.display =
                "none";
        });


    document
        .querySelectorAll(
            ".tab"
        )
        .forEach(button => {

            button.classList.toggle(
                "active",
                button.dataset.tab === tab
            );
        });


    const selected =
        document.getElementById(
            "tab-" + tab
        );

    if (selected) {

        selected.style.display =
            "block";
    }


    if (tab === "fee") {

        loadFees();
    }

    if (tab === "attendance") {

        loadAttendance();
    }

    if (tab === "seat") {

        loadSeat();
    }

}


/*
|--------------------------------------------------------------------------
| MODAL
|--------------------------------------------------------------------------
*/

function closeModal(id) {

    document.getElementById(
        id
    ).classList.remove("show");
}


/*
|--------------------------------------------------------------------------
| BACK
|--------------------------------------------------------------------------
*/

function goBack() {

    window.location.href =
        "index.php";
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function setText(id, value) {

    const element =
        document.getElementById(id);

    if (element) {

        element.textContent =
            value ?? "—";
    }
}


function setValue(id, value) {

    const element =
        document.getElementById(id);

    if (element) {

        element.value =
            value ?? "";
    }
}


function today() {

    const d =
        new Date();

    const y =
        d.getFullYear();

    const m =
        String(
            d.getMonth() + 1
        ).padStart(2, "0");

    const day =
        String(
            d.getDate()
        ).padStart(2, "0");

    return `${y}-${m}-${day}`;
}


function formatDate(value) {

    if (!value) {

        return "—";
    }

    const parts =
        String(value)
            .split("-");

    if (parts.length !== 3) {

        return value;
    }

    return (
        parts[2] +
        " " +
        new Date(
            Number(parts[0]),
            Number(parts[1]) - 1,
            Number(parts[2])
        ).toLocaleString(
            "en-IN",
            {
                month: "short"
            }
        ) +
        " " +
        parts[0]
    );
}


function formatDateTime(value) {

    if (!value) {

        return "—";
    }

    const date =
        new Date(
            value.replace(" ", "T")
        );

    if (isNaN(date.getTime())) {

        return value;
    }

    return date.toLocaleDateString(
        "en-IN",
        {
            day: "numeric",
            month: "short",
            year: "numeric"
        }
    );
}


function money(value) {

    return Number(
        value || 0
    ).toLocaleString(
        "en-IN",
        {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }
    );
}


function escapeHtml(value) {

    return String(
        value ?? ""
    ).replace(
        /[&<>"']/g,
        char => ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;"
        }[char])
    );
}


/*
|--------------------------------------------------------------------------
| START
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "DOMContentLoaded",
    function() {

        loadMember();

    }
);

</script>

</body>
</html>