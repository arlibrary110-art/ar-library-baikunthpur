AR Library Dockerfile - MongoDB/PIE build fix

Uses PHP 8.2.33 Apache Bookworm.
Uses PIE (PHP Installer for Extensions) instead of deprecated PECL.
Installs MongoDB PHP extension 2.5.3, SQLite3 and ZIP.
Permanent Attendance QR is not changed by this Dockerfile.
Replace only the repository Dockerfile with this file.
