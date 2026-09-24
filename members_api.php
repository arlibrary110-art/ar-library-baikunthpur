<?php

ob_start();

require_once __DIR__ . "/security.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=utf-8");


/* =========================================================
   AUTH
========================================================= */

require_staff();
verify_state_change();
require_csrf();
/* =========================================================
   HELPER
========================================================= */

function jsonResponse($data, $status = 200)
{
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* =========================================================
   CREATE / UPDATE TABLES
========================================================= */
if (empty($_SESSION['_members_schema_ready'])) {


/* MEMBERS */

$sql = "
CREATE TABLE IF NOT EXISTS members (

    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    member_id VARCHAR(50) NOT NULL,

    name VARCHAR(150) NOT NULL,

    phone VARCHAR(30) DEFAULT NULL,

    email VARCHAR(150) DEFAULT NULL,

    membership_plan VARCHAR(50) DEFAULT '1 Month',

    shift VARCHAR(30) DEFAULT 'Full Day',

    joining_date DATE DEFAULT NULL,

    validity_date DATE DEFAULT NULL,

    address TEXT DEFAULT NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'Active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY unique_member_id (member_id)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
";

if (!$conn->query($sql)) {

    jsonResponse([
        "success" => false,
        "message" => "Members table error: " . $conn->error
    ], 500);
}


/* =========================================================
   ADD MISSING MEMBER COLUMNS
========================================================= */

$columns = [

    "phone" =>
        "VARCHAR(30) DEFAULT NULL",

    "email" =>
        "VARCHAR(150) DEFAULT NULL",

    "membership_plan" =>
        "VARCHAR(50) DEFAULT '1 Month'",

    "shift" =>
        "VARCHAR(30) DEFAULT 'Full Day'",

    "joining_date" =>
        "DATE DEFAULT NULL",

    "validity_date" =>
        "DATE DEFAULT NULL",

    "address" =>
        "TEXT DEFAULT NULL",

    "status" =>
        "VARCHAR(20) NOT NULL DEFAULT 'Active'"

];


foreach ($columns as $column => $definition) {

    $check = $conn->query(
        "SHOW COLUMNS FROM members LIKE '" .
        $conn->real_escape_string($column) .
        "'"
    );

    if ($check && $check->num_rows === 0) {

        if (!$conn->query(
            "ALTER TABLE members ADD COLUMN $column $definition"
        )) {

            jsonResponse([
                "success" => false,
                "message" =>
                    "Could not add member column '$column': " .
                    $conn->error
            ], 500);
        }
    }
}


/* =========================================================
   FEES TABLE
========================================================= */

$sql = "
CREATE TABLE IF NOT EXISTS member_fees (

    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    member_id INT UNSIGNED NOT NULL,

    receipt_no VARCHAR(50) DEFAULT NULL,

    amount DECIMAL(10,2) NOT NULL DEFAULT 0,

    payment_date DATE NOT NULL,

    due_date DATE DEFAULT NULL,

    payment_method VARCHAR(30) DEFAULT 'Cash',

    status VARCHAR(20) NOT NULL DEFAULT 'Paid',

    remarks VARCHAR(255) DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    INDEX idx_member_fee (member_id)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
";

if (!$conn->query($sql)) {

    jsonResponse([
        "success" => false,
        "message" => "Fees table error: " . $conn->error
    ], 500);
}


/* =========================================================
   ATTENDANCE TABLE
========================================================= */

$sql = "
CREATE TABLE IF NOT EXISTS attendance (

    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    member_id INT UNSIGNED NOT NULL,

    attendance_date DATE NOT NULL,

    check_in DATETIME DEFAULT NULL,

    check_out DATETIME DEFAULT NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'Open',

    remarks VARCHAR(255) DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    INDEX idx_member_date (
        member_id,
        attendance_date
    )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
";

if (!$conn->query($sql)) {

    jsonResponse([
        "success" => false,
        "message" =>
            "Attendance table error: " .
            $conn->error
    ], 500);
}


/* =========================================================
   SEATS TABLE
========================================================= */

$sql = "
CREATE TABLE IF NOT EXISTS member_seats (

    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    member_id INT UNSIGNED NOT NULL,

    seat_no VARCHAR(50) DEFAULT NULL,

    shift VARCHAR(30) DEFAULT 'Full Day',

    start_date DATE DEFAULT NULL,

    end_date DATE DEFAULT NULL,

    status VARCHAR(20) DEFAULT 'Assigned',

    remarks VARCHAR(255) DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    INDEX idx_member_seat (member_id)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
";

if (!$conn->query($sql)) {

    jsonResponse([
        "success" => false,
        "message" =>
            "Seat table error: " .
            $conn->error
    ], 500);
}



    $_SESSION['_members_schema_ready']=1;
}

/* =========================================================
   ACTION
========================================================= */

$action = $_GET["action"] ?? "";

if (in_array($action, ['list','get','add','renew','update','delete'], true)) require_permission('members');
if (in_array($action, ['fees','add_fee','delete_fee'], true)) require_permission('fees');
if (in_array($action, ['attendance','add_attendance'], true)) require_permission('attendance');
if (in_array($action, ['seat','save_seat'], true)) require_permission('seats');


/* =========================================================
   LIST MEMBERS
========================================================= */

if ($action === "list") {

    // Update only rows whose status actually needs changing; the old code
    // rewrote the entire members table on every list request.
    $conn->query("UPDATE members SET status='Expired' WHERE validity_date IS NOT NULL AND validity_date<CURDATE() AND status<>'Expired'");
    $conn->query("UPDATE members SET status='Active' WHERE (validity_date IS NULL OR validity_date>=CURDATE()) AND status='Expired'");

    $result = $conn->query("
        SELECT
            id,
            member_id,
            name,
            phone,
            email,
            membership_plan,
            shift,
            joining_date,
            validity_date,
            date_of_birth,
            address,
            status,
            created_at
        FROM members
        ORDER BY id DESC
    ");

    if (!$result) {

        jsonResponse([
            "success" => false,
            "message" =>
                "Could not load members: " .
                $conn->error
        ], 500);
    }

    $members = [];

    while ($row = $result->fetch_assoc()) {

        $members[] = $row;
    }

    jsonResponse([
        "success" => true,
        "members" => $members
    ]);
}


/* =========================================================
   GET MEMBER
========================================================= */

if ($action === "get") {

    $id = intval($_GET["id"] ?? 0);

    if ($id <= 0) {

        jsonResponse([
            "success" => false,
            "message" => "Invalid member ID."
        ], 400);
    }

    $stmt = $conn->prepare("
        SELECT
            id,
            member_id,
            name,
            phone,
            email,
            membership_plan,
            shift,
            joining_date,
            validity_date,
            date_of_birth,
            address,
            status,
            created_at
        FROM members
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $id);

    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {

        $stmt->close();

        jsonResponse([
            "success" => false,
            "message" => "Member not found."
        ], 404);
    }

    $member = $result->fetch_assoc();

    $stmt->close();

    jsonResponse([
        "success" => true,
        "member" => $member
    ]);
}


/* =========================================================
   ADD MEMBER
========================================================= */

if ($action === "add") {

    $member_id = trim($_POST["member_id"] ?? "");

    $name = trim($_POST["name"] ?? "");

    $phone = trim($_POST["phone"] ?? "");

    $email = trim($_POST["email"] ?? "");

    $membership_plan =
        trim($_POST["membership_plan"] ?? "1 Month");

    $shift =
        trim($_POST["shift"] ?? "Full Day");

    $joining_date =
        trim($_POST["joining_date"] ?? "");

    $validity_date =
        trim($_POST["validity_date"] ?? "");

    $date_of_birth = trim($_POST["date_of_birth"] ?? "");
    if ($date_of_birth !== "" && strtotime($date_of_birth) === false) $date_of_birth = "";

    $address =
        trim($_POST["address"] ?? "");


    if ($member_id === "" || $name === "") {

        jsonResponse([
            "success" => false,
            "message" =>
                "Member ID and name are required."
        ], 400);
    }


    if (!in_array(
        $shift,
        [
            "Morning Shift",
            "Morning Shift Reserved",
            "Evening Shift",
            "Evening Shift Reserved",
            "Full Day",
            "Full Day Reserved"
        ],
        true
    )) {

        $shift = "Full Day";
    }


    if ($joining_date === "") {

        $joining_date = date("Y-m-d");
    }


    if ($validity_date === "") {

        jsonResponse([
            "success" => false,
            "message" =>
                "Validity date is required."
        ], 400);
    }


    if (
        strtotime($joining_date) === false ||
        strtotime($validity_date) === false
    ) {

        jsonResponse([
            "success" => false,
            "message" => "Invalid date."
        ], 400);
    }


    if (
        strtotime($validity_date) <
        strtotime($joining_date)
    ) {

        jsonResponse([
            "success" => false,
            "message" =>
                "Validity date cannot be before joining date."
        ], 400);
    }


    $check = $conn->prepare("
        SELECT id
        FROM members
        WHERE member_id = ?
        LIMIT 1
    ");

    $check->bind_param(
        "s",
        $member_id
    );

    $check->execute();

    $result = $check->get_result();

    if ($result->num_rows > 0) {

        $check->close();

        jsonResponse([
            "success" => false,
            "message" =>
                "This Member ID already exists."
        ], 409);
    }

    $check->close();


    $status =
        $validity_date < date("Y-m-d")
            ? "Expired"
            : "Active";

    // Member + initial fee must be one atomic operation.
    if (!$conn->begin_transaction()) {
        jsonResponse([
            "success" => false,
            "message" => "Could not start member transaction: " . $conn->error
        ], 500);
    }


    $stmt = $conn->prepare("
        INSERT INTO members
        (
            member_id,
            name,
            phone,
            email,
            membership_plan,
            shift,
            joining_date,
            validity_date,
            date_of_birth,
            address,
            status
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "sssssssssss",
        $member_id,
        $name,
        $phone,
        $email,
        $membership_plan,
        $shift,
        $joining_date,
        $validity_date,
        $date_of_birth,
        $address,
        $status
    );

    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();
        try { $conn->rollback(); } catch (Throwable $ignored) {}

        jsonResponse([
            "success" => false,
            "message" =>
                "Could not add member: " . $error
        ], 500);
    }

    $newId = $stmt->insert_id;

    $stmt->close();

    /*
    |---------------------------------------------------------
    | CREATE INITIAL FEE DUE - REQUIRED
    |---------------------------------------------------------
    | A new member must always have a Pending fee record. If
    | the due record cannot be created, the member insert is
    | rolled back so the UI can never report a false success.
    */
    try {
        $shiftKey = strtolower($shift);
        if (strpos($shiftKey, "full day") !== false) {
            $feePlanKey = strpos($shiftKey, "reserved") !== false ? "full_reserved" : "full_day";
        } else {
            $feePlanKey = strpos($shiftKey, "reserved") !== false ? "half_reserved" : "half_day";
        }

        $planKey = strtolower($membership_plan);
        if (strpos($planKey, "6 month") !== false) {
            $termKey = "six";
        } elseif (strpos($planKey, "3 month") !== false) {
            $termKey = "three";
        } elseif (strpos($planKey, "12 month") !== false) {
            // Existing fee settings use the six-month rate as the
            // closest configured long-term rate.
            $termKey = "six";
        } else {
            $termKey = "one";
        }

        $feeKey = $feePlanKey . "_" . $termKey;
        $feeAmount = 0.0;
        $feeStmt = $conn->prepare(
            "SELECT setting_value FROM fee_settings WHERE setting_key = ? LIMIT 1"
        );
        if ($feeStmt) {
            $feeStmt->bind_param("s", $feeKey);
            if ($feeStmt->execute()) {
                $feeRow = $feeStmt->get_result()->fetch_assoc();
                $feeAmount = (float)($feeRow["setting_value"] ?? 0);
            }
            $feeStmt->close();
        }

        // Safety fallback: never create a zero-amount initial due.
        if ($feeAmount <= 0) {
            $fallback = [
                "half_day" => 600,
                "half_reserved" => 800,
                "full_day" => 1100,
                "full_reserved" => 1300
            ];
            $feeAmount = (float)($fallback[$feePlanKey] ?? 1100);
        }

        $dueReceipt = "DUE-" . $member_id . "-" . date("YmdHis") . "-" . random_int(100, 999);
        $remark = "Initial registration fee due";
        $dueStmt = $conn->prepare("
            INSERT INTO member_fees
            (member_id, receipt_no, amount, payment_date, due_date, payment_method, status, remarks)
            VALUES (?, ?, ?, ?, ?, 'Pending', 'Pending', ?)
        ");

        if (!$dueStmt) {
            throw new Exception("Could not prepare initial fee due: " . $conn->error);
        }

        $dueStmt->bind_param(
            "isdsss",
            $newId,
            $dueReceipt,
            $feeAmount,
            $joining_date,
            $joining_date,
            $remark
        );

        if (!$dueStmt->execute()) {
            $err = $dueStmt->error;
            $dueStmt->close();
            throw new Exception("Could not create initial fee due: " . $err);
        }
        $dueStmt->close();

        // Verify the exact Pending row before committing.
        $verify = $conn->prepare(
            "SELECT id, amount FROM member_fees WHERE member_id = ? AND status = 'Pending' ORDER BY id DESC LIMIT 1"
        );
        if (!$verify) {
            throw new Exception("Could not verify initial fee due: " . $conn->error);
        }
        $verify->bind_param("i", $newId);
        if (!$verify->execute()) {
            $err = $verify->error;
            $verify->close();
            throw new Exception("Could not verify initial fee due: " . $err);
        }
        $vr = $verify->get_result()->fetch_assoc();
        $verify->close();
        if (!$vr || (float)$vr["amount"] <= 0) {
            throw new Exception("Initial fee due was not created correctly.");
        }

        if (!$conn->commit()) {
            throw new Exception("Could not commit member and fee due: " . $conn->error);
        }

        // MongoDB adapter writes dirty SQLite tables to MongoDB here so a
        // success response is only returned after the data is persisted.
        $conn->sync();

        jsonResponse([
            "success" => true,
            "message" => "Member added successfully and Fee Due created.",
            "id" => $newId,
            "due_amount" => $feeAmount,
            "fee_status" => "Pending"
        ]);
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        jsonResponse([
            "success" => false,
            "message" => $e->getMessage()
        ], 500);
    }
    jsonResponse([
        "success" => true,
        "message" =>
            "Member added successfully.",
        "id" => $newId
    ]);
}



/* =========================================================
   REPAIR MISSING INITIAL DUE FOR ONE MEMBER
========================================================= */
if ($action === "repair_initial_due") {
    require_admin();

    $id = intval($_POST["id"] ?? 0);
    if ($id <= 0) jsonResponse(["success"=>false,"message"=>"Invalid member ID."],400);

    $memberStmt = $conn->prepare("SELECT id, member_id, membership_plan, shift, joining_date FROM members WHERE id = ? LIMIT 1");
    if (!$memberStmt) jsonResponse(["success"=>false,"message"=>"Could not prepare member lookup: ".$conn->error],500);
    $memberStmt->bind_param("i", $id);
    if (!$memberStmt->execute()) jsonResponse(["success"=>false,"message"=>"Could not load member: ".$memberStmt->error],500);
    $member = $memberStmt->get_result()->fetch_assoc();
    $memberStmt->close();
    if (!$member) jsonResponse(["success"=>false,"message"=>"Member not found."],404);

    $existing = $conn->prepare("SELECT id, amount FROM member_fees WHERE member_id = ? AND status = 'Pending' ORDER BY id DESC LIMIT 1");
    if (!$existing) jsonResponse(["success"=>false,"message"=>"Could not check existing due: ".$conn->error],500);
    $existing->bind_param("i", $id);
    $existing->execute();
    $already = $existing->get_result()->fetch_assoc();
    $existing->close();
    if ($already) {
        jsonResponse(["success"=>true,"message"=>"Pending Fee Due already exists.","due_amount"=>(float)$already["amount"]]);
    }

    $shiftKey = strtolower((string)$member["shift"]);
    $feePlanKey = strpos($shiftKey,"full day") !== false
        ? (strpos($shiftKey,"reserved") !== false ? "full_reserved" : "full_day")
        : (strpos($shiftKey,"reserved") !== false ? "half_reserved" : "half_day");
    $planKey = strtolower((string)$member["membership_plan"]);
    $termKey = strpos($planKey,"6 month") !== false ? "six" : (strpos($planKey,"3 month") !== false ? "three" : "one");
    $feeKey = $feePlanKey."_".$termKey;

    $feeAmount = 0.0;
    $feeStmt = $conn->prepare("SELECT setting_value FROM fee_settings WHERE setting_key = ? LIMIT 1");
    if ($feeStmt) {
        $feeStmt->bind_param("s", $feeKey);
        $feeStmt->execute();
        $row = $feeStmt->get_result()->fetch_assoc();
        $feeAmount = (float)($row["setting_value"] ?? 0);
        $feeStmt->close();
    }
    if ($feeAmount <= 0) $feeAmount = ["half_day"=>600,"half_reserved"=>800,"full_day"=>1100,"full_reserved"=>1300][$feePlanKey] ?? 1100;

    $dueDate = !empty($member["joining_date"]) ? $member["joining_date"] : date("Y-m-d");
    $remark = "Initial registration fee due (repaired)";
    $ins = $conn->prepare("INSERT INTO member_fees (member_id, receipt_no, amount, payment_date, due_date, payment_method, status, remarks) VALUES (?, NULL, ?, ?, ?, 'Pending', 'Pending', ?)");
    if (!$ins) jsonResponse(["success"=>false,"message"=>"Could not prepare due repair: ".$conn->error],500);
    $ins->bind_param("idsss", $id, $feeAmount, $dueDate, $dueDate, $remark);
    if (!$ins->execute()) {
        $err=$ins->error; $ins->close(); jsonResponse(["success"=>false,"message"=>"Could not create due: ".$err],500);
    }
    $ins->close();
    $conn->sync();
    jsonResponse(["success"=>true,"message"=>"Fee Due created successfully.","due_amount"=>(float)$feeAmount]);
}


/* =========================================================
   RENEW MEMBER
========================================================= */
if ($action === "renew") {
    $id = intval($_POST["id"] ?? 0);
    $months = intval($_POST["months"] ?? 1);
    $requestedShift = trim($_POST["shift"] ?? "");
    $allowedShifts = ["Morning Shift", "Morning Shift Reserved", "Evening Shift", "Evening Shift Reserved", "Full Day", "Full Day Reserved"];
    if ($id <= 0) jsonResponse(["success"=>false,"message"=>"Invalid member ID."],400);
    if (!in_array($months,[1,3,6],true)) $months=1;
    if (!in_array($requestedShift,$allowedShifts,true)) $requestedShift="";

    $stmt=$conn->prepare("SELECT id,member_id,name,phone,membership_plan,shift,validity_date,status FROM members WHERE id=? LIMIT 1");
    $stmt->bind_param("i",$id); $stmt->execute(); $member=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$member) jsonResponse(["success"=>false,"message"=>"Member not found."],404);

    $today=date('Y-m-d');
    $oldValidity=!empty($member['validity_date']) ? $member['validity_date'] : $today;
    $base=(strtotime($oldValidity) >= strtotime($today)) ? $oldValidity : $today;
    $start=date('Y-m-d',strtotime($base.' +1 day'));
    if (strtotime($oldValidity) < strtotime($today)) $start=$today;
    $newValidity=date('Y-m-d',strtotime($start.' +'.$months.' month -1 day'));

    $selectedShift = $requestedShift !== "" ? $requestedShift : ($member['shift'] ?? 'Full Day');
    $shiftKey=strtolower($selectedShift);
    $feePlanKey=(strpos($shiftKey,'full day')!==false ? 'full_day' : 'half_day');
    if (strpos($shiftKey,'reserved')!==false) $feePlanKey .= '_reserved';
    $termKey=$months===6?'six':($months===3?'three':'one');
    $feeKey=$feePlanKey.'_'.$termKey;
    $feeAmount=0;
    $fs=$conn->prepare("SELECT setting_value FROM fee_settings WHERE setting_key=? LIMIT 1");
    if($fs){$fs->bind_param('s',$feeKey);$fs->execute();$fr=$fs->get_result()->fetch_assoc();$feeAmount=(float)($fr['setting_value']??0);$fs->close();}

    $conn->begin_transaction();
    try {
        $up=$conn->prepare("UPDATE members SET validity_date=?, status='Active', membership_plan=?, shift=? WHERE id=?");
        $plan=$months===1?'1 Month':($months===3?'3 Months':'6 Months');
        $up->bind_param('sssi',$newValidity,$plan,$selectedShift,$id);
        if(!$up->execute()) throw new Exception($up->error); $up->close();

        $receipt='DUE-REN-'.$member['member_id'].'-'.date('YmdHis').'-'.random_int(100,999);
        $remark='Renewal fee due - '.$plan.' renewal';
        $due=$conn->prepare("INSERT INTO member_fees (member_id,receipt_no,amount,payment_date,due_date,payment_method,status,remarks) VALUES (?,?,?,?,?,'Pending','Pending',?)");
        $due->bind_param('isdsss',$id,$receipt,$feeAmount,$today,$today,$remark);
        if(!$due->execute()) throw new Exception($due->error); $due->close();
        $conn->commit();
        jsonResponse(["success"=>true,"message"=>"Membership renewed successfully. Renewal fee has been added to Due Fees.","validity_date"=>$newValidity,"due_amount"=>$feeAmount,"plan"=>$plan,"shift"=>$selectedShift]);
    } catch(Throwable $e){$conn->rollback();jsonResponse(["success"=>false,"message"=>"Renewal failed: ".$e->getMessage()],500);}
}

/* =========================================================
   UPDATE MEMBER
========================================================= */

if ($action === "update") {

    $id = intval($_POST["id"] ?? 0);

    if ($id <= 0) {

        jsonResponse([
            "success" => false,
            "message" => "Invalid member ID."
        ], 400);
    }


    $member_id =
        trim($_POST["member_id"] ?? "");

    $name =
        trim($_POST["name"] ?? "");

    $phone =
        trim($_POST["phone"] ?? "");

    $email =
        trim($_POST["email"] ?? "");

    $membership_plan =
        trim($_POST["membership_plan"] ?? "1 Month");

    $shift =
        trim($_POST["shift"] ?? "Full Day");

    $joining_date =
        trim($_POST["joining_date"] ?? "");

    $validity_date =
        trim($_POST["validity_date"] ?? "");

    $date_of_birth = trim($_POST["date_of_birth"] ?? "");
    if ($date_of_birth !== "" && strtotime($date_of_birth) === false) $date_of_birth = "";

    $address =
        trim($_POST["address"] ?? "");


    if ($member_id === "" || $name === "") {

        jsonResponse([
            "success" => false,
            "message" =>
                "Member ID and name are required."
        ], 400);
    }


    if (!in_array(
        $shift,
        [
            "Morning Shift",
            "Morning Shift Reserved",
            "Evening Shift",
            "Evening Shift Reserved",
            "Full Day",
            "Full Day Reserved"
        ],
        true
    )) {

        $shift = "Full Day";
    }

    if ($joining_date === "") {
        $joining_date = date("Y-m-d");
    }

    if ($validity_date === "") {
        jsonResponse([
            "success" => false,
            "message" => "Validity date is required."
        ], 400);
    }

    if (
        strtotime($joining_date) === false ||
        strtotime($validity_date) === false
    ) {
        jsonResponse([
            "success" => false,
            "message" => "Invalid date."
        ], 400);
    }

    if (strtotime($validity_date) < strtotime($joining_date)) {
        jsonResponse([
            "success" => false,
            "message" => "Validity date cannot be before joining date."
        ], 400);
    }

    $check = $conn->prepare("
        SELECT id
        FROM members
        WHERE member_id = ?
          AND id <> ?
        LIMIT 1
    ");

    $check->bind_param("si", $member_id, $id);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $check->close();
        jsonResponse([
            "success" => false,
            "message" => "This Member ID already exists."
        ], 409);
    }

    $check->close();

    $status =
        $validity_date < date("Y-m-d")
            ? "Expired"
            : "Active";


    $stmt = $conn->prepare("
        UPDATE members
        SET
            member_id = ?,
            name = ?,
            phone = ?,
            email = ?,
            membership_plan = ?,
            shift = ?,
            joining_date = ?,
            validity_date = ?,
            date_of_birth = ?,
            address = ?,
            status = ?
        WHERE id = ?
    ");

    $stmt->bind_param(
        "sssssssssssi",
        $member_id,
        $name,
        $phone,
        $email,
        $membership_plan,
        $shift,
        $joining_date,
        $validity_date,
        $date_of_birth,
        $address,
        $status,
        $id
    );


    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        jsonResponse([
            "success" => false,
            "message" =>
                "Could not update member: " . $error
        ], 500);
    }

    $stmt->close();

    jsonResponse([
        "success" => true,
        "message" =>
            "Member updated successfully."
    ]);
}


/* =========================================================
   DELETE MEMBER
========================================================= */

if ($action === "delete") { require_admin();

    $id = intval($_POST["id"] ?? 0);
    if ($id <= 0) jsonResponse(["success" => false, "message" => "Invalid member ID."], 400);

    $conn->begin_transaction();
    try {
        $check = $conn->prepare("SELECT member_id, name FROM members WHERE id=? LIMIT 1");
        if (!$check) throw new Exception("Could not prepare member lookup.");
        $check->bind_param("i", $id); $check->execute();
        $member = $check->get_result()->fetch_assoc(); $check->close();
        if (!$member) { $conn->rollback(); jsonResponse(["success" => false, "message" => "Member not found."], 404); }

        foreach ([
            'attendance',
            'member_seats',
            'lockers',
            'payments',
            'member_fees'
        ] as $table) {
            $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE member_id=?");
            if (!$stmt) throw new Exception("Could not prepare {$table} cleanup.");
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) { $err=$stmt->error; $stmt->close(); throw new Exception("Could not clean {$table}: {$err}"); }
            $stmt->close();
        }

        $stmt = $conn->prepare("DELETE FROM members WHERE id=?");
        if (!$stmt) throw new Exception("Could not prepare member delete.");
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) { $err=$stmt->error; $stmt->close(); throw new Exception("Could not delete member: {$err}"); }
        $stmt->close();

        $conn->commit();
        jsonResponse(["success" => true, "message" => "Member and all related seat, locker, attendance and fee records were deleted successfully."]);
    } catch (Throwable $e) {
        $conn->rollback();
        jsonResponse(["success" => false, "message" => "Could not delete member: " . $e->getMessage()], 500);
    }
}

/* =========================================================
   FEES
========================================================= */

if ($action === "fees") {

    $id = intval($_GET["id"] ?? 0);

    if ($id <= 0) {

        jsonResponse([
            "success" => false,
            "message" => "Invalid member ID."
        ], 400);
    }


    $stmt = $conn->prepare("
        SELECT
            id,
            receipt_no,
            amount,
            payment_date,
            due_date,
            payment_method,
            status,
            remarks,
            created_at
        FROM member_fees
        WHERE member_id = ?
        ORDER BY payment_date DESC, id DESC
    ");

    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $fees = [];

    while ($row = $result->fetch_assoc()) {

        $fees[] = $row;
    }

    $stmt->close();


    $summaryStmt = $conn->prepare("
        SELECT
            COALESCE(
                SUM(
                    CASE
                        WHEN status = 'Paid'
                        THEN amount
                        ELSE 0
                    END
                ), 0
            ) AS total_paid,

            COALESCE(
                SUM(
                    CASE
                        WHEN status = 'Pending'
                        THEN amount
                        ELSE 0
                    END
                ), 0
            ) AS total_pending

        FROM member_fees

        WHERE member_id = ?
    ");

    $summaryStmt->bind_param(
        "i",
        $id
    );

    $summaryStmt->execute();

    $summary =
        $summaryStmt
        ->get_result()
        ->fetch_assoc();

    $summaryStmt->close();


    jsonResponse([
        "success" => true,
        "fees" => $fees,
        "summary" => [
            "total_paid" =>
                $summary["total_paid"] ?? 0,

            "total_pending" =>
                $summary["total_pending"] ?? 0
        ]
    ]);
}


/* =========================================================
   ADD FEE
========================================================= */

if ($action === "add_fee") {

    $memberId =
        intval($_POST["member_id"] ?? 0);

    $amount =
        floatval($_POST["amount"] ?? 0);

    $paymentDate =
        trim(
            $_POST["payment_date"] ??
            date("Y-m-d")
        );

    $dueDate =
        trim($_POST["due_date"] ?? "");

    $paymentMethod =
        trim(
            $_POST["payment_method"] ??
            "Cash"
        );

    $status =
        trim(
            $_POST["status"] ??
            "Paid"
        );

    $remarks =
        trim($_POST["remarks"] ?? "");


    if ($memberId <= 0) {

        jsonResponse([
            "success" => false,
            "message" => "Invalid member."
        ], 400);
    }


    if ($amount <= 0) {

        jsonResponse([
            "success" => false,
            "message" =>
                "Amount must be greater than 0."
        ], 400);
    }


    if (!in_array(
        $status,
        ["Paid", "Pending"],
        true
    )) {

        $status = "Paid";
    }


    $dueDate =
        $dueDate === ""
            ? null
            : $dueDate;


    $receiptNo =
        "REC-" .
        date("YmdHis") .
        "-" .
        random_int(100, 999);


    $stmt = $conn->prepare("
        INSERT INTO member_fees
        (
            member_id,
            receipt_no,
            amount,
            payment_date,
            due_date,
            payment_method,
            status,
            remarks
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "isdsssss",
        $memberId,
        $receiptNo,
        $amount,
        $paymentDate,
        $dueDate,
        $paymentMethod,
        $status,
        $remarks
    );


    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        jsonResponse([
            "success" => false,
            "message" =>
                "Could not save fee: " . $error
        ], 500);
    }


    $feeId = $stmt->insert_id;

    $stmt->close();

    /* Keep the main Fees/Receipt module in sync with member fee records. */
    $memberStmt = $conn->prepare("SELECT member_id, membership_plan FROM members WHERE id = ? LIMIT 1");
    if ($memberStmt) {
        $memberStmt->bind_param("i", $memberId);
        $memberStmt->execute();
        $memberRow = $memberStmt->get_result()->fetch_assoc();
        $memberStmt->close();

        if ($memberRow) {
            $sync = $conn->prepare("
                INSERT INTO payments
                (receipt_no, member_id, member_code, amount, payment_date, payment_method, plan, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if ($sync) {
                $sync->bind_param(
                    "sisdssss",
                    $receiptNo,
                    $memberId,
                    $memberRow["member_id"],
                    $amount,
                    $paymentDate,
                    $paymentMethod,
                    $memberRow["membership_plan"],
                    $remarks
                );
                $sync->execute();
                $sync->close();
            }
        }
    }


    jsonResponse([
        "success" => true,
        "message" =>
            "Fee saved successfully.",
        "id" => $feeId,
        "receipt_no" => $receiptNo
    ]);
}


/* =========================================================
   DELETE FEE
========================================================= */

if ($action === "delete_fee") { require_admin();

    $id = intval($_POST["id"] ?? 0);

    if ($id <= 0) {

        jsonResponse([
            "success" => false,
            "message" => "Invalid fee ID."
        ], 400);
    }


    $stmt = $conn->prepare("
        DELETE FROM member_fees
        WHERE id = ?
    ");

    $stmt->bind_param(
        "i",
        $id
    );


    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        jsonResponse([
            "success" => false,
            "message" =>
                "Could not delete fee: " . $error
        ], 500);
    }


    $stmt->close();

    jsonResponse([
        "success" => true,
        "message" =>
            "Fee deleted successfully."
    ]);
}


/* =========================================================
   ATTENDANCE HISTORY
   IMPORTANT:
   Uses attendance table, NOT member_attendance
========================================================= */

if ($action === "attendance") {

    $memberId = intval($_GET["id"] ?? $_GET["member_id"] ?? 0);

    if ($memberId <= 0) {
        jsonResponse([
            "success" => false,
            "message" => "Invalid member ID."
        ], 400);
    }

    $stmt = $conn->prepare("
        SELECT
            id,
            member_id,
            attendance_date,
            check_in,
            check_out,
            status,
            remarks
        FROM attendance
        WHERE member_id = ?
        ORDER BY attendance_date DESC, id DESC
    ");

    if (!$stmt) {
        jsonResponse([
            "success" => false,
            "message" => "Could not prepare attendance query: " . $conn->error
        ], 500);
    }

    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    jsonResponse([
        "success" => true,
        "attendance" => $rows
    ]);
}

if ($action === "add_attendance") {

    $memberId =
        intval($_POST["member_id"] ?? 0);

    $attendanceDate =
        trim(
            $_POST["attendance_date"] ??
            date("Y-m-d")
        );

    $checkIn =
        trim($_POST["check_in"] ?? "");

    $checkOut =
        trim($_POST["check_out"] ?? "");

    $status =
        trim(
            $_POST["status"] ??
            "Present"
        );

    $remarks =
        trim($_POST["remarks"] ?? "");


    if ($memberId <= 0) {

        jsonResponse([
            "success" => false,
            "message" => "Invalid member."
        ], 400);
    }


    if (!in_array(
        $status,
        [
            "Present",
            "Absent",
            "Late",
            "Leave",
            "Open",
            "Completed"
        ],
        true
    )) {

        $status = "Present";
    }


    $checkIn =
        $checkIn === ""
            ? null
            : $checkIn;

    $checkOut =
        $checkOut === ""
            ? null
            : $checkOut;


    $stmt = $conn->prepare("
        INSERT INTO attendance
        (
            member_id,
            attendance_date,
            check_in,
            check_out,
            status,
            remarks
        )
        VALUES
        (?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "isssss",
        $memberId,
        $attendanceDate,
        $checkIn,
        $checkOut,
        $status,
        $remarks
    );


    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        jsonResponse([
            "success" => false,
            "message" =>
                "Could not save attendance: " .
                $error
        ], 500);
    }


    $stmt->close();


    jsonResponse([
        "success" => true,
        "message" =>
            "Attendance saved successfully."
    ]);
}


/* =========================================================
   SEAT
========================================================= */

if ($action === "seat") {

    $id =
        intval($_GET["id"] ?? 0);

    if ($id <= 0) {

        jsonResponse([
            "success" => false,
            "message" => "Invalid member ID."
        ], 400);
    }


    $stmt = $conn->prepare("
        SELECT
            id,
            seat_no,
            shift,
            start_date,
            end_date,
            status,
            remarks
        FROM member_seats
        WHERE member_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $seat =
        $result->num_rows > 0
            ? $result->fetch_assoc()
            : null;

    $stmt->close();


    jsonResponse([
        "success" => true,
        "seat" => $seat
    ]);
}


/* =========================================================
   SAVE SEAT
========================================================= */

if ($action === "save_seat") {

    $memberId =
        intval($_POST["member_id"] ?? 0);

    $seatNo =
        trim($_POST["seat_no"] ?? "");

    $shift =
        trim(
            $_POST["shift"] ??
            "Full Day"
        );

    $startDate =
        trim($_POST["start_date"] ?? "");

    $endDate =
        trim($_POST["end_date"] ?? "");

    $status =
        trim(
            $_POST["status"] ??
            "Assigned"
        );

    $remarks =
        trim($_POST["remarks"] ?? "");


    if ($memberId <= 0) {

        jsonResponse([
            "success" => false,
            "message" => "Invalid member."
        ], 400);
    }


    if ($seatNo === "") {

        jsonResponse([
            "success" => false,
            "message" =>
                "Seat number is required."
        ], 400);
    }


    if (!in_array(
        $shift,
        [
            "Morning Shift",
            "Morning Shift Reserved",
            "Evening Shift",
            "Evening Shift Reserved",
            "Full Day",
            "Full Day Reserved"
        ],
        true
    )) {

        $shift = "Full Day";
    }


    $clear = $conn->prepare("
        UPDATE member_seats
        SET status = 'Inactive'
        WHERE member_id = ?
        AND status = 'Assigned'
    ");

    $clear->bind_param(
        "i",
        $memberId
    );

    $clear->execute();

    $clear->close();


    $stmt = $conn->prepare("
        INSERT INTO member_seats
        (
            member_id,
            seat_no,
            shift,
            start_date,
            end_date,
            status,
            remarks
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "issssss",
        $memberId,
        $seatNo,
        $shift,
        $startDate,
        $endDate,
        $status,
        $remarks
    );


    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        jsonResponse([
            "success" => false,
            "message" =>
                "Could not save seat: " . $error
        ], 500);
    }


    $stmt->close();


    jsonResponse([
        "success" => true,
        "message" =>
            "Seat information saved successfully."
    ]);
}


/* =========================================================
   INVALID ACTION
========================================================= */

jsonResponse([
    "success" => false,
    "message" => "Invalid API action."
], 400);

?>