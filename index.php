<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
$loggedIn = isset($_SESSION["staff_id"]);
$role = strtolower((string)($_SESSION['staff_role'] ?? ''));
$isAdmin = $role === 'admin';
$permissions = $loggedIn ? get_staff_permissions() : [];
$can = function($key) use ($isAdmin, $permissions) { return $isAdmin || in_array($key, $permissions, true); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AR Library Management System</title>
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&family=Oswald:wght@500;600&display=swap" rel="stylesheet">

<link rel="stylesheet" href="style.css?v=20260901-branded-dashboard-v14">
</head>

<body data-role="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" data-permissions="<?php echo htmlspecialchars(json_encode(array_values($permissions), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">

<div id="loginScreen" class="login-screen hidden">
    <div class="login-card">

        <div class="brand brand-with-logo"><img src="assets/ar-library-logo.webp" alt="AR Library logo"><span>AR LIBRARY</span></div>
        <div class="subtitle">MANAGEMENT SYSTEM</div>

        <div class="login-heading">
            <span id="loginRoleIcon">👤</span> <span id="loginRoleTitle">STAFF LOGIN</span>
        </div>

        <div class="line"></div>

        <label for="staffId">STAFF ID</label>
        <input id="staffId" type="text" placeholder="e.g. STF-001">

        <label for="password">PASSWORD</label>
        <input id="password" type="password" placeholder="Enter password...">
        <input id="selectedRole" type="hidden" value="staff">

        <div class="login-actions">
            <button type="button" class="btn secondary" onclick="showRole()">← Back</button>
            <button type="button" class="btn primary" onclick="login()">✓ Login</button>
        </div>

        <p class="demo" id="loginDemo">Staff login — use your Staff ID and password.</p>
    </div>
</div>


<div id="roleScreen" class="login-screen <?php echo $loggedIn ? 'hidden' : ''; ?>">
    <div class="role-card">

        <div class="brand brand-with-logo"><img src="assets/ar-library-logo.webp" alt="AR Library logo"><span>AR LIBRARY</span></div>
        <div class="subtitle">MANAGEMENT SYSTEM</div>

        <h2>SELECT YOUR ROLE</h2>

        <div class="roles">

            <button type="button" class="role" onclick="openLogin('admin')">
                <div class="icon">🔐</div>
                <strong>Admin</strong>
            </button>

            <button type="button" class="role" onclick="openLogin('staff')">
                <div class="icon">👤</div>
                <strong>Staff</strong>
            </button>

        </div>
    </div>
</div>


<div id="app" class="app <?php echo $loggedIn ? '' : 'hidden'; ?>">

    <aside class="sidebar">

        <div class="side-brand">
            <div class="side-logo-wrap">
                <img src="assets/ar-library-logo.webp" alt="AR Library logo" class="side-logo">
            </div>
            <span>
                AR LIBRARY
                <small>Management System</small>
            </span>
        </div>

        <nav id="nav">

            <?php if ($can('dashboard')): ?><button type="button" class="nav-item active" data-page="dashboard" onclick="loadPage('dashboard')">
                <span>🏠</span> Dashboard
            </button><?php endif; ?>

            <?php if ($can('seats')): ?><a class="nav-item" data-page="seats" href="seats.php" onclick="window.location.href='seats.php'; return false;" style="text-decoration:none">
                <span>💺</span> Seats
            </a><?php endif; ?>

            <?php if ($can('members')): ?>            <button type="button" class="nav-item" data-page="members" onclick="loadPage('members')">
                <span>👥</span> Members
            </button><?php endif; ?>

            <?php if ($can('fees')): ?>            <button type="button" class="nav-item" data-page="fees" onclick="loadPage('fees')">
                <span>💰</span> Fees
            </button><?php endif; ?>

            <?php if ($can('attendance')): ?>            <button type="button" class="nav-item" data-page="attendance" onclick="loadPage('attendance')">
                <span>📋</span> Attendance
            </button><?php endif; ?>

            <?php if ($can('attendance_qr')): ?>            <a class="nav-item" data-page="attendance_qr" href="qr_display.php" style="text-decoration:none">
                <span>📱</span> Attendance QR
            </a><?php endif; ?>

            <?php if ($can('enquiry')): ?>            <button type="button" class="nav-item" data-page="enquiry" onclick="loadPage('enquiry')">
                <span>🔎</span> Enquiry
            </button><?php endif; ?>

            <?php if ($can('expenses')): ?>            <button type="button" class="nav-item" data-page="expenses" onclick="loadPage('expenses')">
                <span>💸</span> Expenses
            </button><?php endif; ?>

            <?php if ($can('reports')): ?>            <button type="button" class="nav-item" data-page="reports" onclick="loadPage('reports')">
                <span>📊</span> Reports
            </button><?php endif; ?>

            <?php if ($isAdmin): ?>
            <button type="button" class="nav-item" data-page="staff" onclick="loadPage('staff')">
                <span>👨‍💼</span> Staff Management
            </button>
            <?php endif; ?>

            <?php if ($can('backup')): ?>            <button type="button" class="nav-item" data-page="backup" onclick="loadPage('backup')">
                <span>💾</span> Backup
            </button><?php endif; ?>

            <?php if ($can('settings')): ?>            <button type="button" class="nav-item" data-page="settings" onclick="loadPage('settings')">
                <span>⚙️</span> Settings
            </button><?php endif; ?>

        </nav>

        <button type="button" class="logout" onclick="logout()">↪ Logout</button>

    </aside>


    <main class="main">

        <header class="topbar">

            <div class="topbar-title">
                <div class="mobile-brand">AR LIBRARY</div>
                <h1 id="pageTitle">Dashboard</h1>
                <p id="dateText"></p>
            </div>

            <div class="topbar-right">
                <div class="datetime-display" aria-label="Current date and time">
                    <span class="datetime-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3" y="4.5" width="18" height="17" rx="2.5"></rect><path d="M7 2.5v4M17 2.5v4M3 9h18"></path></svg>
                    </span>
                    <span id="headerDateTime">Loading...</span>
                </div>

                <div class="profile">
                <?php $profilePhoto = (string)($_SESSION["staff_photo"] ?? ""); ?>
                <div class="avatar">
                    <?php if ($profilePhoto !== ""): ?>
                        <img src="<?php echo htmlspecialchars($profilePhoto, ENT_QUOTES, "UTF-8"); ?>" alt="Profile photo">
                    <?php else: ?>
                        <?php echo htmlspecialchars(strtoupper(substr((string)($_SESSION["staff_name"] ?? "S"), 0, 1)), ENT_QUOTES, "UTF-8"); ?>
                    <?php endif; ?>
                </div>

                <div>
                    <b><?php echo htmlspecialchars($_SESSION["staff_name"] ?? "Staff", ENT_QUOTES, "UTF-8"); ?></b>
                    <small><?php echo htmlspecialchars($_SESSION["staff_id"] ?? "", ENT_QUOTES, "UTF-8"); ?> · <?php echo htmlspecialchars(strtoupper((string)($_SESSION["staff_role"] ?? "staff")), ENT_QUOTES, "UTF-8"); ?></small>
                </div>
                </div>
            </div>

        </header>

        <section id="content"></section>

    </main>

</div>

<script src="script.js?v=20260901-branded-dashboard-v14" defer></script>

</body>
</html>