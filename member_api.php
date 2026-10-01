<?php
require_once __DIR__ . "/security.php";
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

if ($_SERVER["REQUEST_METHOD"] === "POST") require_csrf();

if ($action === "login" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $memberId = trim($_POST["member_id"] ?? "");
    $phone = trim($_POST["phone"] ?? "");

    if ($memberId === "" || $phone === "") {
        json_response(false, "Member ID and phone number are required");
    }

    $rateKey = hash('sha256', strtolower($memberId) . '|' . preg_replace('/\D+/', '', $phone) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $rateFile = sys_get_temp_dir() . '/ar_member_login_' . $rateKey . '.json';
    $rateNow = time(); $attempts = [];
    if (is_file($rateFile)) { $raw = @json_decode((string)@file_get_contents($rateFile), true); if (is_array($raw)) $attempts = array_values(array_filter($raw, fn($t)=>(int)$t > $rateNow - 900)); }
    if (count($attempts) >= 5) json_response(false, "Too many login attempts. Please try again after a few minutes.", [], 429);
    $attempts[] = $rateNow; @file_put_contents($rateFile, json_encode($attempts), LOCK_EX);

    $stmt = $conn->prepare(
        "SELECT id, member_id, name, phone, email, course, address, membership_plan,
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

    @unlink($rateFile);
    session_regenerate_id(true);
    $_SESSION["member_app_id"] = (int)$member["id"];
    $_SESSION["member_app_member_id"] = $member["member_id"];

    unset($member["phone"]);
    json_response(true, "Login successful", ["member" => $member]);
}


if ($action === "notifications") {
    require_member();
    $id = (int)$_SESSION["member_app_id"];
    $notifications = [];
    $today = new DateTimeImmutable(date("Y-m-d"));

    // Library-wide notices posted by Admin/Staff remain visible for 24 hours.
    $msgStmt = $conn->query("SELECT title,message,created_at FROM member_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 10");
    if ($msgStmt) {
        while ($notice = $msgStmt->fetch_assoc()) {
            $notifications[] = [
                "type"=>"library_notice",
                "icon"=>"📢",
                "title"=>$notice["title"] ?: "Important Notice",
                "message"=>$notice["message"],
                "priority"=>0,
                "created_at"=>$notice["created_at"]
            ];
        }
        $msgStmt->free();
    }

    $stmt = $conn->prepare("SELECT name, validity_date FROM members WHERE id = ? LIMIT 1");
    if (!$stmt) json_response(false, "Database error");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$member) json_response(false, "Member not found");

    $validity = trim((string)($member["validity_date"] ?? ""));
    if ($validity !== "") {
        try {
            $expiry = new DateTimeImmutable($validity);
            $days = (int)$today->diff($expiry)->format("%r%a");
            if ($days < 0) {
                $notifications[] = ["type"=>"expired","icon"=>"🔴","title"=>"Membership Expired","message"=>"Your membership expired on " . $expiry->format("d M Y") . ". Please renew your membership.","priority"=>1];
            } elseif ($days === 0) {
                $notifications[] = ["type"=>"expiry","icon"=>"🔴","title"=>"Membership Expires Today","message"=>"Your membership expires today. Please renew it to continue using the library.","priority"=>1];
            } elseif ($days <= 3) {
                $notifications[] = ["type"=>"expiry","icon"=>"🟠","title"=>"Membership Expiring Soon","message"=>"Your membership expires in " . $days . " day" . ($days === 1 ? "" : "s") . " (" . $expiry->format("d M Y") . ").","priority"=>2];
            } elseif ($days <= 7) {
                $notifications[] = ["type"=>"expiry","icon"=>"🟡","title"=>"Membership Expiring Soon","message"=>"Your membership expires in " . $days . " days (" . $expiry->format("d M Y") . ").","priority"=>3];
            }
        } catch (Throwable $e) {}
    }

    $feeStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS due_amount FROM member_fees WHERE member_id = ? AND status = 'Pending'");
    if ($feeStmt) {
        $feeStmt->bind_param("i", $id);
        $feeStmt->execute();
        $due = (float)(($feeStmt->get_result()->fetch_assoc())["due_amount"] ?? 0);
        $feeStmt->close();
        if ($due > 0) {
            $notifications[] = ["type"=>"fee_due","icon"=>"🔴","title"=>"Payment Pending","message"=>"₹" . number_format($due, 0, ".", ",") . " payment is pending. Please clear your dues.","priority"=>1];
        }
    }

    $payStmt = $conn->prepare("SELECT receipt_no, amount, payment_date FROM payments WHERE member_id = ? ORDER BY id DESC LIMIT 1");
    if ($payStmt) {
        $payStmt->bind_param("i", $id);
        $payStmt->execute();
        $pay = $payStmt->get_result()->fetch_assoc();
        $payStmt->close();
        if ($pay) {
            try {
                $payDate = new DateTimeImmutable((string)$pay["payment_date"]);
                $age = (int)$today->diff($payDate)->format("%r%a");
                if ($age >= -30 && $age <= 30) {
                    $notifications[] = ["type"=>"payment_received","icon"=>"🟢","title"=>"Payment Received","message"=>"₹" . number_format((float)$pay["amount"], 0, ".", ",") . " received on " . $payDate->format("d M Y") . ". Receipt: " . ($pay["receipt_no"] ?: "—"),"priority"=>4];
                }
            } catch (Throwable $e) {}
        }
    }

    $seatStmt = $conn->prepare("SELECT seat_no, shift FROM member_seats WHERE member_id = ? AND status = 'Assigned' AND (end_date IS NULL OR end_date >= CURDATE()) ORDER BY id DESC LIMIT 1");
    if ($seatStmt) {
        $seatStmt->bind_param("i", $id);
        $seatStmt->execute();
        $seat = $seatStmt->get_result()->fetch_assoc();
        $seatStmt->close();
        if ($seat && !empty($seat["seat_no"])) {
            $notifications[] = ["type"=>"seat","icon"=>"🔵","title"=>"Your Seat","message"=>"Your assigned seat is " . $seat["seat_no"] . " (" . ($seat["shift"] ?: "Full Day") . ").","priority"=>5];
        }
    }

    usort($notifications, fn($a,$b)=>(int)$a["priority"] <=> (int)$b["priority"]);
    json_response(true, "", ["notifications"=>$notifications, "count"=>count($notifications)]);
}

if ($action === "logout") {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(false, "Invalid logout request", [], 405);
    unset($_SESSION["member_app_id"], $_SESSION["member_app_member_id"]);
    json_response(true, "Logged out");
}

if ($action === "me") {
    require_member();
    $id = (int)$_SESSION["member_app_id"];

    $stmt = $conn->prepare(
        "SELECT id, member_id, name, phone, email, course, address, membership_plan,
                shift, joining_date, validity_date, status
         FROM members WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    if (!$member) json_response(false, "Member not found");

    // Current assigned seat for this member. Full Day remains a single assignment.
    $seatNo = null;
    $seatStmt = $conn->prepare(
        "SELECT seat_no
         FROM member_seats
         WHERE member_id = ?
           AND status = 'Assigned'
           AND (end_date IS NULL OR end_date >= CURDATE())
         ORDER BY id DESC
         LIMIT 1"
    );
    if ($seatStmt) {
        $seatStmt->bind_param("i", $id);
        $seatStmt->execute();
        $seatRow = $seatStmt->get_result()->fetch_assoc();
        $seatNo = $seatRow['seat_no'] ?? null;
        $seatStmt->close();
    }

    // Current pending fee balance for the member.
    $feeDue = 0.0;
    $feeStmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS due_amount
         FROM member_fees
         WHERE member_id = ? AND status = 'Pending'"
    );
    if ($feeStmt) {
        $feeStmt->bind_param("i", $id);
        $feeStmt->execute();
        $feeRow = $feeStmt->get_result()->fetch_assoc();
        $feeDue = (float)($feeRow['due_amount'] ?? 0);
        $feeStmt->close();
    }
    $member['seat_no'] = $seatNo;
    $member['fee_due'] = $feeDue;
    $member['fee_status'] = $feeDue > 0 ? 'Due' : 'Paid';

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

    json_response(true, "", [
        "member" => $member,
        "attendance" => $attendance,
        "today" => $todayRecord
    ]);
}

json_response(false, "Invalid action");
