AR LIBRARY MANAGEMENT SYSTEM - LIVE DEPLOYMENT

This package is based on the original AR Library project and keeps the original PHP + MySQL structure.

IMPORTANT DATABASE NOTES
1. Existing database: DO NOT import database.sql over your live database. Take a backup first and keep your existing data.
2. Fresh database: import AR_Library_Private/database.sql. The fresh-install Admin is:
   Staff ID: STF-001
   Password: AR@Lib#2026!Baikunthpur
   Change this password immediately after first login.
3. Existing installations: the login code can upgrade a legacy plaintext staff password to a secure hash after a successful login.

DATABASE CONNECTION
- Local XAMPP defaults: host=localhost, user=root, password=empty, database=ar_library.
- On cPanel/shared hosting, set AR_LIBRARY_DB_HOST, AR_LIBRARY_DB_USER, AR_LIBRARY_DB_PASSWORD and AR_LIBRARY_DB_NAME as environment variables, OR edit db.php with the hosting credentials.
- Do not use a MySQL root account on production hosting.

LIVE FOLDER
- Put the application folder under public_html.
- Keep AR_Library_Private outside public_html whenever possible. Its .htaccess is an additional safeguard only.
- Enable HTTPS/SSL.

SECURITY
- No hard-coded Admin recovery/backdoor exists in login.php.
- New staff passwords require at least 8 characters.
- Admin/Staff permissions and state-changing requests are protected by the existing security helpers.
- Backup/restore is Admin-only.


PERMANENT ATTENDANCE QR
- Coordinates: 24.735323, 81.409182
- Allowed radius: 100 meters
- QR does not expire. Attendance requires member login + browser GPS and is verified server-side.
- Run AR_Library_Private/database.sql (or add the three attendance_* library_settings rows) if your existing database predates this feature.


QR UPDATE
- Admin and Staff dashboards now both show an "Attendance QR" menu item.
- QR opens qr_display.php and is permanent (no expiry timer).
- Print QR button is available.
- Location defaults: 24.735323, 81.409182, radius 100m.


BRANDING
- Official AR Library logo is included at assets/ar-library-logo.webp and is used by the dashboard, member pages, receipt and Attendance QR page.

SECURITY UPDATE
- Staff-side state-changing APIs now require a session CSRF token in addition to authentication/origin checks.
- Attendance server errors no longer expose PHP filenames or line numbers to the browser.

FRESH START / OLD MEMBER DATA RESET
-----------------------------------
If this is an existing installation and you want to start with completely new
members, run `AR_Library_Private/RESET_OLD_MEMBER_DATA.sql` once against the
existing `ar_library` database. See `AR_Library_Private/RESET_INSTRUCTIONS.txt`.
This clears members, fees/pending dues, payments, attendance, seats, lockers,
enquiries and activity logs while preserving staff/admin, settings, fee settings
and expenses.
