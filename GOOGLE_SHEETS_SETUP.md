# AR Library — Google Sheets Database Deployment

## 1. Create a Google Sheet
Create one Google Spreadsheet. You can leave it completely blank; the application creates the required tabs automatically.

Required tabs are created automatically:
staff, members, member_fees, payments, fee_settings, member_seats, lockers,
attendance, enquiries, expenses, library_settings, activity_logs,
staff_permissions, member_messages.

## 2. Create a Google Cloud service account
In Google Cloud:
1. Create/select a project.
2. Enable **Google Sheets API**.
3. Create a Service Account.
4. Create a JSON key and download it.
5. Copy the service account's `client_email`.
6. Open the Google Sheet and share it with that `client_email` as **Editor**.

Do not commit the JSON key to GitHub.

## 3. Render environment variables
In Render → Service → Environment add:

`GOOGLE_SHEET_ID`
- The ID from the Google Sheet URL.

`GOOGLE_SERVICE_ACCOUNT_JSON`
- Paste the entire downloaded service-account JSON on one line (Render accepts JSON text as an environment variable).

The old `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` and Railway MySQL variables are no longer required by the application.

## 4. Existing Railway data
This ZIP changes the application datastore to Google Sheets. It does not have access to your private Railway database, so your existing Railway records are not automatically inside the new Sheet.

Before deleting Railway, export/migrate these tables:
staff
members
member_fees
payments
fee_settings
member_seats
lockers
attendance
enquiries
expenses
library_settings
activity_logs
staff_permissions
member_messages

The Google Sheet must keep the first row of each tab as the column headers created by the application.

## 5. Optional one-time Railway migration
If you need to keep existing Railway data, do not delete Railway until the new Google Sheets data is verified.

The migration utility is at:
`migration_tools/migrate_railway_to_sheets.php`

It is **CLI-only** and denied by the web server. Run it from the Render/container shell with these environment variables temporarily available:
- `ENABLE_RAILWAY_MIGRATION=1`
- `OLD_DB_HOST`
- `OLD_DB_PORT`
- `OLD_DB_NAME`
- `OLD_DB_USER`
- `OLD_DB_PASSWORD`

Example:
`php migration_tools/migrate_railway_to_sheets.php`

After a successful migration, remove **all** `OLD_DB_*` variables and `ENABLE_RAILWAY_MIGRATION`. The migration utility is not used during normal application requests.

## 6. Important
Google Sheets is suitable for a small/medium library-management workload. It is not equivalent to MySQL for high concurrency. The app keeps the existing SQL API and uses a per-request SQLite compatibility layer before synchronizing changed tables to Sheets.

Never publish `GOOGLE_SERVICE_ACCOUNT_JSON` in GitHub or frontend JavaScript.
