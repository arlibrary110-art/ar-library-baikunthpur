AR LIBRARY MANAGEMENT SYSTEM — MONGODB EDITION
================================================

This release uses MongoDB as the persistent datastore. Google Sheets and Railway/MySQL are
NOT required for normal runtime operation.

The PHP application keeps its existing SQL-style API internally. db.php uses a temporary
SQLite compatibility layer per request and synchronizes changed tables to MongoDB. This
approach minimizes changes to the existing application APIs and preserves the existing
member, attendance, payment, seat, locker, enquiry, expense, QR, backup and security logic.

MONGODB CONFIGURATION
---------------------
Set these Render environment variables:
  MONGODB_URI
  MONGODB_DATABASE=ar_library

See MONGODB_SETUP.md for the complete Atlas and Render setup.

IMPORTANT: Never commit MONGODB_URI or a database password to GitHub.

FIRST ADMIN LOGIN (fresh MongoDB database)
-------------------------------------------
Staff ID: STF-001
Initial password: AR@Lib#2026!Baikunthpur
Change the password immediately after the first login.

EXISTING RAILWAY DATA
---------------------
Do NOT delete the Railway database until MongoDB deployment has been tested and the old
records have been migrated and verified.

The optional one-time migration utility is:
  migration_tools/migrate_railway_to_mongodb.php

It is CLI-only and requires ENABLE_RAILWAY_MIGRATION=1 plus OLD_DB_* environment variables.
After migration, remove ENABLE_RAILWAY_MIGRATION and all OLD_DB_* variables.

MONGODB COLLECTIONS
-------------------
staff, members, member_fees, payments, fee_settings, member_seats, lockers,
attendance, enquiries, expenses, library_settings, activity_logs,
staff_permissions, member_messages

DEPLOYMENT
----------
1. Upload the project to GitHub.
2. Deploy the repository on Render using Dockerfile/render.yaml.
3. Add MONGODB_URI in Render and keep MONGODB_DATABASE as ar_library.
4. Open the site and test admin login, members, fees/payments, attendance, seats/lockers,
   enquiries, expenses, reports, QR attendance, backup and restore.
5. Only after verification should Railway be retired.
