AR LIBRARY MANAGEMENT SYSTEM — GOOGLE SHEETS EDITION
=====================================================

This release uses Google Sheets as the persistent datastore through the Google Sheets API.
The PHP application keeps its existing SQL-style API internally, but runtime data is stored
in a configured Google Spreadsheet. Railway/MySQL is NOT required for normal operation.

GOOGLE SHEETS CONFIGURATION
----------------------------
Set these Render environment variables:
  GOOGLE_SHEET_ID
  GOOGLE_SERVICE_ACCOUNT_JSON

Create a Google Cloud service account, enable Google Sheets API, create/download its JSON
key, and share the target Google Sheet with the service account client_email as Editor.
See GOOGLE_SHEETS_SETUP.md for the complete setup.

IMPORTANT: Never commit GOOGLE_SERVICE_ACCOUNT_JSON or any private key to GitHub.

FIRST ADMIN LOGIN (fresh Google Sheet)
---------------------------------------
The application seeds the existing Administrator account when the staff sheet is empty.
Staff ID: STF-001
Initial password: AR@Lib#2026!Baikunthpur
Change the password immediately after the first login.

EXISTING RAILWAY DATA
---------------------
Do NOT delete the Railway database until the Google Sheets deployment has been tested and
the old records have been migrated and verified.

The optional one-time migration utility is in:
  migration_tools/migrate_railway_to_sheets.php

It is CLI-only and requires ENABLE_RAILWAY_MIGRATION=1 plus OLD_DB_* environment variables.
It is deliberately denied by the web server. After migration, remove ENABLE_RAILWAY_MIGRATION
and all OLD_DB_* variables. The migration utility is not part of normal application runtime.

WHAT IS STORED IN THE SHEET
---------------------------
The application creates these tabs automatically:
  staff, members, member_fees, payments, fee_settings, member_seats, lockers,
  attendance, enquiries, expenses, library_settings, activity_logs,
  staff_permissions, member_messages

DEPLOYMENT
----------
1. Upload this project to GitHub.
2. Deploy the repository on Render using the included render.yaml/Dockerfile.
3. Add the two Google environment variables in Render.
4. Open the site and test admin login, members, fees/payments, attendance, seats/lockers,
   enquiries, expenses, reports, QR attendance, backup and restore.
5. Only after verification should Railway be retired.

SCALE / CONCURRENCY
-------------------
Google Sheets is suitable for a small/medium library workload. It is not a drop-in
replacement for a high-concurrency relational database. The adapter loads the sheet into
a per-request SQLite layer and synchronizes changed tables back to Sheets, so very heavy
simultaneous writes should remain on a proper database.
