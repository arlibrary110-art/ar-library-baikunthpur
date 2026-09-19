<?php
session_start();
require_once "db.php";
header("Content-Type: application/json; charset=utf-8");

function json_response($success, $message = "", $extra = []) {
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_member() {
    if (!isset($_SESSION["member_app_id"])) {
        http_response_code(401);
        json_response(false, "Member login required");
    }
}

$action = $_GET["action"] ?? "";

if ($action === "login" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $memberId = trim($_POST["member_id"] ?? "");
    $phone = trim($_POST["phone"] ?? "");

    if ($memberId === "" || $phone === "") {
        json_response(false, "Member ID and phone number are required");
    }

    $stmt = $conn->prepare(
        "SELECT id, member_id, name, phone, email, address, membership_plan,
                shift, joining_date, validity_date, status
         FROM members
         WHERE member_id = ?
           AND phone = ?
         LIMIT 1"
    );

    if (!$stmt) json_response(false, "Database error");
    $stmt->bind_param("ss", $memberId, $phone);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();

    if (!$member) json_response(false, "Member ID or phone number is incorrect");
    if (strtolower((string)$member["status"]) !== "active") json_response(false, "Your membership is not active");
    if (!empty($member["validity_date"]) && $member["validity_date"] < date("Y-m-d")) json_response(false, "Your membership has expired");

    session_regenerate_id(true);
    $_SESSION["member_app_id"] = (int)$member["id"];
    $_SESSION["member_app_member_id"] = $member["member_id"];

    unset($member["phone"]);
    json_response(true, "Login successful", ["member" => $member]);
}

if ($action === "logout") {
    unset($_SESSION["member_app_id"], $_SESSION["member_app_member_id"]);
    json_response(true, "Logged out");
}

if ($action === "me") {
    require_member();
    $id = (int)$_SESSION["member_app_id"];

    $stmt = $conn->prepare(
        "SELECT id, member_id, name, phone, email, address, membership_plan,
                shift, joining_date, validity_date, status
         FROM members WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    if (!$member) json_response(false, "Member not found");

    $stmt = $conn->prepare(
        "SELECT id, attendance_date,
                DATE_FORMAT(check_in,'%h:%i %p') AS check_in_time,
                DATE_FORMAT(check_out,'%h:%i %p') AS check_out_time,
                status
         FROM attendance
         WHERE member_id = ?
         ORDER BY attendance_date DESC, id DESC
         LIMIT 30"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $attendance = [];
    while ($row = $res->fetch_assoc()) $attendance[] = $row;

    $today = date("Y-m-d");
    $todayRecord = null;
    foreach ($attendance as $row) {
        if (($row["attendance_date"] ?? "") === $today) { $todayRecord = $row; break; }
    }

    unset($member["phone"]);
    json_response(true, "", [
        "member" => $member,
        "attendance" => $attendance,
        "today" => $todayRecord
    ]);
}

json_response(false, "Invalid action");
