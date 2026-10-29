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

<link rel="stylesheet" href="style.css?v=20261029-mobile-dashboard-fix">
</head>

<body data-role="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" data-permissions="<?php echo htmlspecialchars(json_encode(array_values($permissions), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">

<div id="publicHome" class="public-home <?php echo $loggedIn ? 'hidden' : ''; ?>">
  <header class="public-nav">
    <a class="public-brand" href="#home" aria-label="AR Library home">
      <img src="assets/ar-library-logo.webp" alt="">
      <span><b>AR LIBRARY</b><small>LIBRARY MANAGEMENT SYSTEM</small></span>
    </a>
    <nav class="public-links" aria-label="Main navigation">
      <a href="#home">Home</a><a href="#about">About</a><a href="#services">Services</a><a href="#membership">Membership</a><a href="#timing">Rules &amp; Timing</a><a href="#contact">Contact</a>
    </nav>
    <div class="public-login-actions"><button type="button" class="public-login admin" onclick="openLogin('admin')">♙ Admin Login</button><button type="button" class="public-login staff" onclick="openLogin('staff')">♟ Staff Login</button></div>
    <button type="button" class="public-menu-toggle" aria-label="Open menu" onclick="document.getElementById('publicNavMobile').classList.toggle('open')">☰</button>
  </header>
  <nav id="publicNavMobile" class="public-mobile-links" aria-label="Mobile navigation">
    <a href="#home">Home</a><a href="#about">About</a><a href="#services">Services</a><a href="#membership">Membership</a><a href="#timing">Rules &amp; Timing</a><a href="#contact">Contact</a>
    <button type="button" onclick="openLogin('admin')">Admin Login</button><button type="button" onclick="openLogin('staff')">Staff Login</button>
  </nav>
  <main>
    <section id="home" class="public-hero">
      <div class="public-hero-copy"><span class="public-eyebrow">WELCOME TO</span><h1>AR Library</h1><h2>सपनों को सच करने की जगह</h2><p class="public-motto">PREMIUM SELF STUDY ZONE</p><p class="public-values">FOCUS&nbsp;&nbsp; • &nbsp;&nbsp;DISCIPLINE&nbsp;&nbsp; • &nbsp;&nbsp;SUCCESS</p><p>AR Library आपके उज्ज्वल भविष्य के लिए पढ़ाई का शांत माहौल और बेहतर अध्ययन अनुभव देने का प्रयास करती है।</p><div class="public-hero-buttons"><a class="public-btn primary" href="#services">हमारी सेवाएँ देखें →</a><a class="public-btn outline" href="#membership">सदस्य बनें</a></div></div>
      <div class="public-hero-art" role="img" aria-label="पढ़ाई और ज्ञान के लिए शांत लाइब्रेरी वातावरण"><div class="hero-art-badge"><span>📚</span><b>Better Books,<br>Brighter Future</b></div><div class="hero-art-note">A PLACE TO FOCUS<br><strong>LEARN · GROW · SUCCEED</strong></div></div>
    </section>
    <section id="services" class="public-section public-services"><div class="public-section-heading"><span></span><h2>हमारी प्रमुख सेवाएँ</h2><span></span></div><div class="public-service-grid">
      <article class="public-service-card"><div class="service-icon blue">▤</div><h3>Library Services</h3><p>पुस्तकें, अध्ययन सामग्री और लाइब्रेरी सुविधाओं की जानकारी।</p></article>
      <article id="membership" class="public-service-card"><div class="service-icon green">♟</div><h3>Membership Information</h3><p>सदस्यता योजना, शुल्क और सदस्य बनने की जानकारी।</p><a href="#contact">संपर्क करें →</a></article>
      <article id="timing" class="public-service-card"><div class="service-icon orange">◷</div><h3>Library Timing &amp; Rules</h3><p>लाइब्रेरी के समय, नियम और आवश्यक निर्देश।</p></article>
      <article class="public-service-card"><div class="service-icon purple">▦</div><h3>Renewal &amp; Due</h3><p>सदस्यता नवीनीकरण और शुल्क संबंधी जानकारी।</p></article>
      <article class="public-service-card"><div class="service-icon cyan">♧</div><h3>Member Updates</h3><p>सदस्यता और लाइब्रेरी से जुड़े महत्वपूर्ण अपडेट।</p></article>
    </div></section>
    <section id="about" class="public-about"><div class="about-symbol">📖</div><div class="about-copy"><h2>हमारे बारे में</h2><p>AR Library एक आधुनिक लाइब्रेरी मैनेजमेंट सिस्टम के साथ अध्ययन और सदस्यता प्रबंधन को सरल बनाने का प्रयास करती है। हमारा उद्देश्य सदस्यों को सुविधाजनक और व्यवस्थित लाइब्रेरी अनुभव देना है।</p><a href="#contact">और जानकारी पाएँ →</a></div><div class="about-features"><div><span>▣</span><b>Study<br>Environment</b></div><div><span>♧</span><b>Easy<br>Membership</b></div><div><span>◷</span><b>Organized<br>Management</b></div><div><span>✓</span><b>Helpful<br>Service</b></div></div></section>
    <section id="contact" class="public-contact"><div class="public-contact-heading"><span>GET IN TOUCH</span><h2>हमसे संपर्क करें</h2><p>लाइब्रेरी से जुड़ी जानकारी के लिए हमसे संपर्क करें।</p></div><div class="contact-cards"><article class="contact-card"><div class="contact-icon">⌖</div><div><h3>Library Address</h3><p>Ward No. 15, सरकारी अस्पताल के सामने,<br>बैकुंठपुर, रीवा, मध्य प्रदेश – 486441</p><a target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&amp;destination=24.7340139%2C81.4105508">Google Maps पर रास्ता देखें →</a></div></article><article class="contact-card"><div class="contact-icon">☎</div><div><h3>Mobile Number</h3><p>8349852152</p><a href="tel:8349852152">कॉल करने के लिए यहाँ टैप करें →</a></div></article></div></section>
  </main>
  <footer class="public-footer"><div class="footer-main"><a class="public-brand footer-brand" href="#home"><img src="assets/ar-library-logo.webp" alt=""><span><b>AR LIBRARY</b><small>LIBRARY MANAGEMENT SYSTEM</small></span></a><nav><a href="#home">Home</a><a href="#about">About</a><a href="#services">Services</a><a href="#membership">Membership</a><a href="#timing">Rules &amp; Timing</a><a href="#contact">Contact</a></nav><div class="footer-contact"><a href="tel:8349852152">☎ 8349852152</a><span>Baikunthpur, Rewa, MP</span></div></div><div class="footer-bottom">© <?php echo date('Y'); ?> AR Library. All Rights Reserved.</div></footer>
</div>

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


<div id="roleScreen" class="login-screen hidden">
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

            <?php if ($isAdmin): ?>            <button type="button" class="nav-item" data-page="recycle" onclick="loadPage('recycle')">
                <span>♻️</span> Recycle
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

<script src="script.js?v=20261029-public-home-v1" defer></script>

</body>
</html>