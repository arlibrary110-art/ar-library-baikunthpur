<?php
// AR Library - database connection.
// For local XAMPP the defaults below work as-is. On hosting, set these
// environment variables in the hosting panel or edit only these values.
$host = getenv("AR_LIBRARY_DB_HOST") ?: "localhost";
$user = getenv("AR_LIBRARY_DB_USER") ?: "root";
$password = getenv("AR_LIBRARY_DB_PASSWORD");
if ($password === false) $password = "";
$database = getenv("AR_LIBRARY_DB_NAME") ?: "ar_library";

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    http_response_code(500);
    die("Server connection error. Please check the database configuration.");
}
$conn->set_charset("utf8mb4");

// Lightweight compatibility migration for existing installations.
// Older attendance tables may not have the remarks column required by
// GPS check-in/check-out. Add it automatically without deleting data.
$colCheck = $conn->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'remarks'");
if ($colCheck) {
    $row = $colCheck->fetch_assoc();
    if ((int)($row['c'] ?? 0) === 0) {
        @$conn->query("ALTER TABLE attendance ADD COLUMN remarks VARCHAR(255) NULL AFTER status");
    }
    $colCheck->free();
}
