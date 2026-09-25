ONE-TIME RAILWAY -> MONGODB MIGRATION
======================================
This utility is for migration only. It is CLI-only and denied by the web server.

Required environment variables:
  MONGODB_URI
  MONGODB_DATABASE
  ENABLE_RAILWAY_MIGRATION=1
  OLD_DB_HOST
  OLD_DB_PORT
  OLD_DB_NAME
  OLD_DB_USER
  OLD_DB_PASSWORD

Run from the project container/shell with PHP CLI. After a successful migration, remove
ENABLE_RAILWAY_MIGRATION and every OLD_DB_* variable. Do not expose this utility as a web endpoint.
