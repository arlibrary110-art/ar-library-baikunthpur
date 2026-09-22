AR LIBRARY — MONGODB ATLAS SETUP
================================

This release stores persistent application data in MongoDB. Google Sheets is no longer
used by the runtime application.

RENDER ENVIRONMENT VARIABLES
----------------------------
MONGODB_URI
  Your MongoDB Atlas connection string. Keep it private and do not commit it to GitHub.

MONGODB_DATABASE
  ar_library

MONGODB ATLAS
-------------
1. Create a MongoDB Atlas cluster.
2. Create a database user with a strong password.
3. Add Render's outbound access in Atlas Network Access. For a quick test, 0.0.0.0/0
   can be used, but a restricted allow-list is preferable when your deployment setup
   supports it.
4. Copy the application's Driver connection string and put it in Render as MONGODB_URI.
5. The application creates these collections automatically on first successful request:
   staff, members, member_fees, payments, fee_settings, member_seats, lockers,
   attendance, enquiries, expenses, library_settings, activity_logs,
   staff_permissions, member_messages.

FIRST ADMIN LOGIN (fresh MongoDB database)
-------------------------------------------
Staff ID: STF-001
Initial password: AR@Lib#2026!Baikunthpur
Change the password immediately after the first login.

EXISTING RAILWAY DATA
---------------------
Do NOT delete the Railway database until the MongoDB deployment has been tested and the
old records have been migrated and verified.

The optional one-time migration utility is:
  migration_tools/migrate_railway_to_mongodb.php

It is CLI-only and requires ENABLE_RAILWAY_MIGRATION=1 plus OLD_DB_* environment variables.
After migration, remove ENABLE_RAILWAY_MIGRATION and all OLD_DB_* variables.

IMPORTANT ARCHITECTURE NOTE
---------------------------
The application contains many existing SQL queries. To avoid rewriting and risking every
API, db.php provides a compatibility layer: it loads MongoDB collections into a temporary
SQLite database for the current request, executes the existing SQL, and writes only changed
collections back to MongoDB. MongoDB is the persistent datastore; SQLite is not persistent.

This keeps the existing application features and SQL logic intact while removing the
Google Sheets dependency.
