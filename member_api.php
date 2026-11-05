<?php
require_once __DIR__ . "/security.php";
require_once "db.php";
header("Content-Type: application/json; charset=utf-8");

function json_response($success, $message = "", $extra = [], $status = 200) {
    // Keep API responses JSON-only and send the intended HTTP status code.
    http_response_code((int)$status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function member_login_rate_limited($memberId, $recordFailure = false) {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    // Key by IP + Member ID, not phone number, so changing phone input cannot
    // reset the counter for repeated attempts against the same account.
    $rateKey = hash('sha256', strtolower(trim($memberId)) . '|' . $ip);
    $rateFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ar_member_login_' . $rateKey . '.json';
    $handle = @fopen($rateFile, 'c+');
    if (!$handle) return false; // Do not lock out all members if temp storage is unavailable.
    $now = time();
    $limited = false;
    if (flock($handle, LOCK_EX)) {
        $raw = stream_get_contents($handle);
        $attempts = json_decode((string)$raw, true);
        if (!is_array($attempts)) $attempts = [];
        $attempts = array_values(array_filter($attempts, static function ($t) use ($now) {
            return is_numeric($t) && (int)$t > $now - 900;
        }));
        if (count($attempts) >= 5) {
            $limited = true;
        } elseif ($recordFailure) {
            $attempts[] = $now;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($attempts));
            fflush($handle);
        }
        flock($handle, LOCK_UN);
    }
    fclose($handle);
    return $limited;
}

function member_login_rate_reset($memberId) {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateKey = hash('sha256', strtolower(trim($memberId)) . '|' . $ip);
    $rateFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ar_member_login_' . $rateKey . '.json';
    $handle = @fopen($rateFile, 'c+');
    if (!$handle) return;
    if (flock($handle, LOCK_EX)) {
        ftruncate($handle, 0);
        fflush($handle);
        flock($handle, LOCK_UN);
    }
    fclose($handle);
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

    if (member_login_rate_limited($memberId)) {
        json_response(false, "Too many login attempts. Please try again after 15 minutes.", [], 429);
    }

    $stmt = $conn->prepare(
        "SELECT id, member_id, name, phone, email, course, address, date_of_birth, membership_plan,
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

    if (!$member) {
        member_login_rate_limited($memberId, true);
        json_response(false, "Member ID or phone number is incorrect");
    }
    if (strtolower((string)$member["status"]) !== "active") json_response(false, "Your membership is not active");
    if (!empty($member["validity_date"]) && $member["validity_date"] < date("Y-m-d")) json_response(false, "Your membership has expired");

    // A successful login clears earlier failed attempts for this account/IP.
    member_login_rate_reset($memberId);
    session_regenerate_id(true);
    $_SESSION["member_app_id"] = (int)$member["id"];
    $_SESSION["member_app_member_id"] = $member["member_id"];

    unset($member["phone"]);
    json_response(true, "Login successful", ["member" => $member]);
}


if ($action === "profile_update" && $_SERVER["REQUEST_METHOD"] === "POST") {
    require_member();
    $id = (int)$_SESSION["member_app_id"];
    $birthday = trim((string)($_POST["date_of_birth"] ?? ""));
    $address = trim((string)($_POST["address"] ?? ""));

    if ($birthday !== "") {
        $date = DateTime::createFromFormat('!Y-m-d', $birthday);
        if (!$date || $date->format('Y-m-d') !== $birthday) {
            http_response_code(422);
            json_response(false, "Please enter a valid birthday.");
        }
        if ($birthday > date('Y-m-d')) {
            http_response_code(422);
            json_response(false, "Birthday cannot be in the future.");
        }
    }
    if (strlen($address) > 500) {
        http_response_code(422);
        json_response(false, "Address must be 500 characters or fewer.");
    }

    $stmt = $conn->prepare("UPDATE members SET date_of_birth = NULLIF(?, ''), address = ? WHERE id = ?");
    if (!$stmt) json_response(false, "Could not prepare profile update.");
    $stmt->bind_param("ssi", $birthday, $address, $id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) json_response(false, "Could not update profile. Please try again.");
    json_response(true, "Profile updated successfully.");
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
        "SELECT id, member_id, name, phone, email, course, address, date_of_birth, membership_plan,
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
    // New members can appear in the admin Due list even before a Pending
    // member_fees row exists. Mirror that Due calculation here so Member App
    // shows the same pending amount instead of ₹0.
    if ($feeDue <= 0) {
        $feeCountStmt = $conn->prepare(
            "SELECT COUNT(*) AS fee_count
             FROM member_fees
             WHERE member_id = ?"
        );
        $feeCount = 0;
        if ($feeCountStmt) {
            $feeCountStmt->bind_param("i", $id);
            $feeCountStmt->execute();
            $feeCountRow = $feeCountStmt->get_result()->fetch_assoc();
            $feeCount = (int)($feeCountRow['fee_count'] ?? 0);
            $feeCountStmt->close();
        }

        // Only calculate the plan amount when this member has no fee history.
        if ($feeCount === 0) {
            $feeSettings = [];
            $settingsResult = $conn->query("SELECT setting_key, setting_value FROM fee_settings");
            if ($settingsResult) {
                while ($setting = $settingsResult->fetch_assoc()) {
                    $feeSettings[$setting['setting_key']] = (float)$setting['setting_value'];
                }
            }

            $shift = strtolower(trim((string)($member['shift'] ?? '')));
            $plan = strtolower(trim((string)($member['membership_plan'] ?? '')));
            $isFull = strpos($shift, 'full day') !== false;
            $isReserved = strpos($shift, 'reserved') !== false;

            $duration = 1;
            if (strpos($plan, '6 month') !== false || strpos($plan, '6 months') !== false) {
                $duration = 6;
            } elseif (strpos($plan, '3 month') !== false || strpos($plan, '3 months') !== false) {
                $duration = 3;
            }

            $term = $duration === 6 ? 'six' : ($duration === 3 ? 'three' : 'one');
            $settingKey = $isFull
                ? ($isReserved ? "full_reserved_{$term}" : "full_day_{$term}")
                : ($isReserved ? "half_reserved_{$term}" : "half_day_{$term}");

            $fallbacks = [
                'full_day_one' => 1100, 'full_day_three' => 3135, 'full_day_six' => 5940,
                'full_reserved_one' => 1300, 'full_reserved_three' => 3705, 'full_reserved_six' => 7020,
                'half_day_one' => 600, 'half_day_three' => 1710, 'half_day_six' => 3240,
                'half_reserved_one' => 800, 'half_reserved_three' => 2280, 'half_reserved_six' => 4320
            ];

            $feeDue = (float)($feeSettings[$settingKey] ?? ($fallbacks[$settingKey] ?? 0));
        }
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
         LIMIT 400"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $attendance = [];
    while ($row = $res->fetch_assoc()) $attendance[] = $row;

    // Calendar uses a compact date/status list without the 400-row history cap.
    // Keep the detailed attendance history query above limited for faster page loads.
    $calendarAttendance = $attendance; // Safe fallback if compact calendar query is unavailable.
    $calendarStmt = $conn->prepare(
        "SELECT attendance_date, status
         FROM attendance
         WHERE member_id = ?
         ORDER BY attendance_date ASC, id ASC"
    );
    if ($calendarStmt) {
        $calendarStmt->bind_param("i", $id);
        if ($calendarStmt->execute()) {
            $calendarResult = $calendarStmt->get_result();
            if ($calendarResult) {
                $calendarAttendance = [];
                while ($calendarRow = $calendarResult->fetch_assoc()) $calendarAttendance[] = $calendarRow;
            }
        }
        $calendarStmt->close();
    }

    $today = date("Y-m-d");
    $todayRecord = null;
    foreach ($attendance as $row) {
        if (($row["attendance_date"] ?? "") === $today) { $todayRecord = $row; break; }
    }

    $payments = [];
    $paymentStmt = $conn->prepare(
        "SELECT receipt_no, amount, payment_date, payment_method
         FROM payments WHERE member_id = ? ORDER BY id DESC LIMIT 30"
    );
    if ($paymentStmt) {
        $paymentStmt->bind_param("i", $id);
        $paymentStmt->execute();
        $paymentResult = $paymentStmt->get_result();
        while ($paymentRow = $paymentResult->fetch_assoc()) $payments[] = $paymentRow;
        $paymentStmt->close();
    }

    json_response(true, "", [
        "member" => $member,
        "attendance" => $attendance,
        "attendance_calendar" => $calendarAttendance,
        "payments" => $payments,
        "today" => $todayRecord
    ]);
}

json_response(false, "Invalid action");
