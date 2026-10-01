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

@$conn->query("CREATE TABLE IF NOT EXISTS member_renewals (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,member_id INT UNSIGNED NOT NULL,renewal_date DATE NOT NULL,old_validity_date DATE NULL,new_validity_date DATE NULL,membership_plan VARCHAR(100) NOT NULL,shift VARCHAR(100) NOT NULL,amount DECIMAL(12,2) NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_member_renewals(member_id,renewal_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
@$conn->query("CREATE TABLE IF NOT EXISTS member_renewal_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,member_id INT UNSIGNED NOT NULL,requested_plan VARCHAR(100) NULL,requested_shift VARCHAR(100) NULL,message VARCHAR(500) NULL,status VARCHAR(30) NOT NULL DEFAULT 'Pending',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_renew_req_member(member_id,status),INDEX idx_renew_req_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
                shift, joining_date, date_of_birth, validity_date, status
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

    $dobStmt=$conn->prepare("SELECT date_of_birth FROM members WHERE id=? LIMIT 1");
    if($dobStmt){$dobStmt->bind_param('i',$id);$dobStmt->execute();$dob=(string)($dobStmt->get_result()->fetch_assoc()['date_of_birth']??'');$dobStmt->close();if($dob!==''){try{$bday=new DateTimeImmutable($dob);$md=$bday->format('m-d');$todayMd=$today->format('m-d');$tomorrowMd=$today->modify('+1 day')->format('m-d');if($md===$todayMd||$md===$tomorrowMd)$notifications[]=["type"=>"birthday","icon"=>"🎂","title"=>"Birthday Reminder","message"=>($md===$todayMd?"Happy Birthday! 🎉":"Your birthday is tomorrow! 🎂"),"priority"=>2];}catch(Throwable $e){}}
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

    $rq=$conn->prepare("SELECT created_at FROM member_renewal_requests WHERE member_id=? AND status='Pending' ORDER BY id DESC LIMIT 1");
    if($rq){$rq->bind_param('i',$id);$rq->execute();$rr=$rq->get_result()->fetch_assoc();$rq->close();if($rr)$notifications[]=["type"=>"renewal_request","icon"=>"🔄","title"=>"Renewal Request Pending","message"=>"Your renewal request has been sent to the library.","priority"=>2,"created_at"=>$rr['created_at']];}
    usort($notifications, fn($a,$b)=>(int)$a["priority"] <=> (int)$b["priority"]);
    json_response(true, "", ["notifications"=>$notifications, "count"=>count($notifications)]);
}

if ($action === "renewal_request" && $_SERVER["REQUEST_METHOD"] === "POST") {
    require_member();
    $id=(int)$_SESSION["member_app_id"];
    $plan=trim((string)($_POST["plan"]??"1 Month"));
    $shift=trim((string)($_POST["shift"]??"Full Day"));
    $message=trim((string)($_POST["message"]??""));
    if(!in_array($plan,["1 Month","3 Months","6 Months"],true))$plan="1 Month";
    if(!in_array($shift,["Morning Shift","Evening Shift","Morning Shift Reserved","Evening Shift Reserved","Full Day","Full Day Reserved"],true))$shift="Full Day";
    if(mb_strlen($message)>500)$message=mb_substr($message,0,500);
    $check=$conn->prepare("SELECT id FROM member_renewal_requests WHERE member_id=? AND status='Pending' ORDER BY id DESC LIMIT 1");
    if($check){$check->bind_param("i",$id);$check->execute();$exists=$check->get_result()->fetch_assoc();$check->close();if($exists)json_response(false,"A renewal request is already pending.");}
    $st=$conn->prepare("INSERT INTO member_renewal_requests(member_id,requested_plan,requested_shift,message) VALUES(?,?,?,?)");
    if(!$st)json_response(false,"Could not create renewal request.");
    $st->bind_param("isss",$id,$plan,$shift,$message);$ok=$st->execute();$st->close();
    json_response($ok,$ok?"Renewal request sent to library.":"Could not send renewal request.");
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
                shift, joining_date, date_of_birth, validity_date, status
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

    $feeHistory=[];$fh=$conn->prepare("SELECT id,receipt_no,amount,payment_date,payment_method,status,remarks FROM member_fees WHERE member_id=? ORDER BY id DESC LIMIT 50");
    if($fh){$fh->bind_param("i",$id);$fh->execute();$rr=$fh->get_result();while($x=$rr->fetch_assoc()){$x["amount"]=(float)$x["amount"];$feeHistory[]=$x;}$fh->close();}
    $renewHistory=[];$rh=$conn->prepare("SELECT renewal_date,old_validity_date,new_validity_date,membership_plan,shift,amount FROM member_renewals WHERE member_id=? ORDER BY id DESC LIMIT 30");
    if($rh){$rh->bind_param("i",$id);$rh->execute();$rr=$rh->get_result();while($x=$rr->fetch_assoc()){$x["amount"]=(float)$x["amount"];$renewHistory[]=$x;}$rh->close();}
    $pendingRequest=false;$rq=$conn->prepare("SELECT id FROM member_renewal_requests WHERE member_id=? AND status='Pending' ORDER BY id DESC LIMIT 1");if($rq){$rq->bind_param("i",$id);$rq->execute();$pendingRequest=(bool)$rq->get_result()->fetch_assoc();$rq->close();}

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
        "today" => $todayRecord,
        "fee_history" => $feeHistory,
        "renewal_history" => $renewHistory,
        "renewal_request_pending" => $pendingRequest
    ]);
}

json_response(false, "Invalid action");
