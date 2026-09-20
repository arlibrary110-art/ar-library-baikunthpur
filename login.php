<?php

require_once __DIR__ . '/security.php';
require_once "db.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode([
        "success" => false,
        "message" => "Invalid request"
    ]);
    exit;
}

$staff_id = trim($_POST["staff_id"] ?? "");
$password = $_POST["password"] ?? "";
$requested_role = strtolower(trim($_POST["role"] ?? "staff"));
if (!in_array($requested_role, ["admin", "staff"], true)) {
    $requested_role = "staff";
}

if ($staff_id === "" || $password === "") {
    echo json_encode([
        "success" => false,
        "message" => "Staff ID and password are required"
    ]);
    exit;
}

/*
   No hard-coded Admin recovery/backdoor is used. Existing legacy
   plaintext passwords may still be upgraded automatically after a
   successful login; new/changed passwords are always hashed.
*/

$stmt = $conn->prepare(
    "SELECT id, staff_id, name, password, role, status, photo
     FROM staff
     WHERE staff_id = ?
     LIMIT 1"
);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Staff table is not available. Please check the database setup."
    ]);
    exit;
}

$stmt->bind_param("s", $staff_id);
if (!$stmt->execute()) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to read staff account from database."
    ]);
    $stmt->close();
    exit;
}

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid Staff ID or password"
    ]);
    exit;
}

$staff = $result->fetch_assoc();

if ($staff["status"] !== "active") {
    echo json_encode([
        "success" => false,
        "message" => "This staff account is inactive"
    ]);
    exit;
}

if (strtolower((string)$staff["role"]) !== $requested_role) {
    echo json_encode([
        "success" => false,
        "message" => $requested_role === "admin"
            ? "Invalid Admin ID or password"
            : "Invalid Staff ID or password"
    ]);
    exit;
}

$password_ok = password_verify($password, $staff["password"]);

if (!$password_ok && hash_equals((string)$staff["password"], (string)$password)) {

    $new_hash = password_hash($password, PASSWORD_DEFAULT);

    $update = $conn->prepare(
        "UPDATE staff SET password = ? WHERE id = ?"
    );

    $update->bind_param("si", $new_hash, $staff["id"]);
    $update->execute();

    $password_ok = true;
}

if (!$password_ok) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid Staff ID or password"
    ]);
    exit;
}

session_regenerate_id(true);
$_SESSION["staff_id"] = $staff["staff_id"];
$_SESSION["staff_name"] = $staff["name"];
$_SESSION["staff_role"] = $staff["role"];
$_SESSION["staff_photo"] = $staff["photo"] ?? "";

echo json_encode([
    "success" => true,
    "message" => "Login successful",
    "name" => $staff["name"],
    "role" => $staff["role"],
    "photo" => $staff["photo"] ?? ""
]);

?>