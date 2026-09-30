<?php

/* Start buffering before loading any dependency so PHP warnings/notices or
 * accidental whitespace can never corrupt the JSON API response. */
ob_start();

require_once __DIR__ . '/security.php';

/* Payment endpoint response helper.  Invalid UTF-8 from an old DB record is
 * replaced instead of making json_encode() return false/empty output. */
function payment_json_out($ok, $message = '', $extra = [], $status = 200) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $payload = array_merge(['success' => (bool)$ok, 'message' => (string)$message], $extra);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = json_encode(['success' => false, 'message' => 'Payment saved, but the server response could not be encoded.'], JSON_UNESCAPED_UNICODE);
    }
    echo $json;
    exit;
}

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";


/*
|--------------------------------------------------------------------------
| LOGIN CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["staff_id"])) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Please login first."
    ]);

    exit;
}


if (empty($_SESSION['_payments_schema_ready'])) {
/*
|--------------------------------------------------------------------------
| CREATE PAYMENTS TABLE
|--------------------------------------------------------------------------
*/

$createTable = "
CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    receipt_no VARCHAR(50) NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    member_code VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    additional_charges DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_type VARCHAR(20) NOT NULL DEFAULT 'Full',
    payment_date DATE NOT NULL,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
    plan VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_receipt_no (receipt_no),
    KEY idx_member_id (member_id),
    KEY idx_member_code (member_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";


if (!$conn->query($createTable)) {

    echo json_encode([
        "success" => false,
        "message" => "Payments table create error: " . $conn->error
    ]);

    exit;
}

/* Add split-payment fields to older installations without deleting existing data. */
$paymentColumns = [
    "fee_amount" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    "additional_charges" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    "payment_type" => "VARCHAR(20) NOT NULL DEFAULT 'Full'"
];
foreach ($paymentColumns as $column => $definition) {
    $check = $conn->query("SHOW COLUMNS FROM payments LIKE '" . $conn->real_escape_string($column) . "'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE payments ADD COLUMN $column $definition");
    }
}
$conn->query("UPDATE payments SET fee_amount = amount WHERE fee_amount = 0 AND amount > 0");



    $_SESSION['_payments_schema_ready']=1;
}

/*
|--------------------------------------------------------------------------
| ACTION
|--------------------------------------------------------------------------
*/


if (empty($_SESSION['_fee_settings_ready'])) {
/*
|--------------------------------------------------------------------------
| FEE SETTINGS TABLE
|--------------------------------------------------------------------------
*/

$settingsTable = "
CREATE TABLE IF NOT EXISTS fee_settings (
    setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
    setting_value DECIMAL(10,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

if (!$conn->query($settingsTable)) {
    echo json_encode([
        "success" => false,
        "message" => "Fee settings table error: " . $conn->error
    ]);
    exit;
}

$defaults = [
    "half_day_one" => 600,
    "half_day_three" => 1710,
    "half_day_six" => 3240,
    "half_reserved_one" => 800,
    "half_reserved_three" => 2280,
    "half_reserved_six" => 4320,
    "full_day_one" => 1100,
    "full_day_three" => 3135,
    "full_day_six" => 5940,
    "full_reserved_one" => 1300,
    "full_reserved_three" => 3705,
    "full_reserved_six" => 7020
];

$insertSetting = $conn->prepare("INSERT IGNORE INTO fee_settings(setting_key,setting_value) VALUES(?,?)");
if ($insertSetting) {
    foreach ($defaults as $key => $value) {
        $insertSetting->bind_param("sd", $key, $value);
        $insertSetting->execute();
    }
    $insertSetting->close();
}

    $_SESSION['_fee_settings_ready']=1;
}

$action = $_GET["action"] ?? "";

if (in_array($action, ['fee_settings','save_fee_settings','monthly_income','dues','list','add','get','edit_due','delete'], true)) require_permission('fees');
verify_state_change();
require_csrf();


/*
|--------------------------------------------------------------------------
| LIST ALL PAYMENTS
|--------------------------------------------------------------------------
|
| URL:
| payments_api.php?action=list
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| GET FEE SETTINGS
|--------------------------------------------------------------------------
*/

if ($action === "fee_settings") {
    $rows = [];
    $result = $conn->query("SELECT setting_key,setting_value FROM fee_settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[$row["setting_key"]] = (float)$row["setting_value"];
        }
    }

    echo json_encode([
        "success" => true,
        "settings" => [
            "half_day" => [
                "one_month" => $rows["half_day_one"] ?? 600,
                "three_month" => $rows["half_day_three"] ?? 1710,
                "six_month" => $rows["half_day_six"] ?? 3240
            ],
            "half_reserved" => [
                "one_month" => $rows["half_reserved_one"] ?? 800,
                "three_month" => $rows["half_reserved_three"] ?? 2280,
                "six_month" => $rows["half_reserved_six"] ?? 4320
            ],
            "full_day" => [
                "one_month" => $rows["full_day_one"] ?? 1100,
                "three_month" => $rows["full_day_three"] ?? 3135,
                "six_month" => $rows["full_day_six"] ?? 5940
            ],
            "full_reserved" => [
                "one_month" => $rows["full_reserved_one"] ?? 1300,
                "three_month" => $rows["full_reserved_three"] ?? 3705,
                "six_month" => $rows["full_reserved_six"] ?? 7020
            ]
        ]
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| SAVE FEE SETTINGS
|--------------------------------------------------------------------------
*/

if ($action === "save_fee_settings" && $_SERVER["REQUEST_METHOD"] === "POST") { require_admin();
    $raw = $_POST["settings"] ?? "";
    $settings = json_decode($raw, true);

    if (!is_array($settings)) {
        echo json_encode(["success" => false, "message" => "Invalid fee settings."]);
        exit;
    }

    $allowed = ["half_day", "half_reserved", "full_day", "full_reserved"];
    $terms = ["one_month", "three_month", "six_month"];
    $stmt = $conn->prepare("INSERT INTO fee_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");

    foreach ($allowed as $plan) {
        foreach ($terms as $term) {
            if (!isset($settings[$plan][$term])) continue;
            $key = $plan . "_" . str_replace("_month", "", $term);
            if ($term === "one_month") $key = $plan . "_one";
            if ($term === "three_month") $key = $plan . "_three";
            if ($term === "six_month") $key = $plan . "_six";
            $value = max(0, (float)$settings[$plan][$term]);
            $stmt->bind_param("sd", $key, $value);
            $stmt->execute();
        }
    }
    $stmt->close();
    echo json_encode(["success" => true, "message" => "Fee settings saved successfully."]);
    exit;
}

/*
|--------------------------------------------------------------------------
| MONTHLY INCOME - DATE WISE
|--------------------------------------------------------------------------
*/

if ($action === "monthly_income") {
    $month = trim($_GET["month"] ?? date("Y-m"));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        echo json_encode(["success" => false, "message" => "Invalid month."]);
        exit;
    }
    $start = $month . "-01";
    $end = date("Y-m-t", strtotime($start));

    /* Daily summary + member-wise payment details for the calendar. */
    $stmt = $conn->prepare("SELECT p.id,p.payment_date,p.amount,p.receipt_no,p.payment_method,p.payment_type,p.member_id,p.member_code,COALESCE(NULLIF(TRIM(m.name),''),p.member_code) AS member_name FROM payments p LEFT JOIN members m ON m.id=p.member_id WHERE p.payment_date BETWEEN ? AND ? ORDER BY p.payment_date DESC,p.id DESC");
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();
    $byDate = [];
    $total = 0;
    $transactions = 0;
    while ($row = $result->fetch_assoc()) {
        $date = $row["payment_date"];
        if (!isset($byDate[$date])) {
            $byDate[$date] = ["payment_date"=>$date,"transactions"=>0,"total_income"=>0,"payments"=>[]];
        }
        $amount = (float)$row["amount"];
        $row["amount"] = $amount;
        $row["id"] = (int)$row["id"];
        $row["member_id"] = (int)$row["member_id"];
        $byDate[$date]["transactions"]++;
        $byDate[$date]["total_income"] += $amount;
        $byDate[$date]["payments"][] = [
            "id"=>$row["id"],
            "member_id"=>$row["member_id"],
            "member_code"=>$row["member_code"],
            "member_name"=>$row["member_name"],
            "amount"=>$amount,
            "receipt_no"=>$row["receipt_no"],
            "payment_method"=>$row["payment_method"],
            "payment_type"=>$row["payment_type"]
        ];
        $total += $amount;
        $transactions++;
    }
    $stmt->close();
    foreach ($byDate as &$day) {
        $day["transactions"] = (int)$day["transactions"];
        $day["total_income"] = (float)$day["total_income"];
    }
    unset($day);
    $days = array_values($byDate);

    echo json_encode(["success"=>true,"month"=>$month,"days"=>$days,"total_income"=>$total,"total_transactions"=>$transactions]);
    exit;
}

/*
|--------------------------------------------------------------------------
| DUE FEE MEMBERS
|--------------------------------------------------------------------------
| Members whose validity date has arrived/passed are shown as due.
| The displayed amount is an estimated next-period amount based on plan.
|--------------------------------------------------------------------------
*/

if ($action === "dues") {
    /*
    | Show members with an explicit Pending fee first. This means a newly
    | registered member appears in Due Fee Members immediately, even when
    | their membership validity date is still active.
    */
    $sql = "
        SELECT
            m.id,
            m.member_id,
            m.name,
            m.phone,
            m.membership_plan,
            m.shift,
            m.joining_date,
            m.validity_date,
            m.status,
            mf.id AS due_fee_id,
            mf.amount AS pending_amount,
            mf.due_date AS fee_due_date
        FROM members m
        LEFT JOIN member_fees mf
            ON mf.id = (
                SELECT mf2.id
                FROM member_fees mf2
                WHERE mf2.member_id = m.id
                  AND mf2.status = 'Pending'
                ORDER BY mf2.due_date ASC, mf2.id ASC
                LIMIT 1
            )
        WHERE m.status = 'Active'
          AND (
              mf.id IS NOT NULL
              OR (m.validity_date IS NOT NULL AND m.validity_date <= CURDATE())
              OR NOT EXISTS (
                  SELECT 1 FROM member_fees mf0
                  WHERE mf0.member_id = m.id
                    AND mf0.status = 'Paid'
              )
          )
        ORDER BY
            CASE WHEN mf.id IS NOT NULL THEN 0 ELSE 1 END,
            COALESCE(mf.due_date, m.validity_date) ASC,
            m.id DESC
    ";

    $result = $conn->query($sql);
    if (!$result) {
        echo json_encode(["success"=>false,"message"=>"Could not load due fees: ".$conn->error]);
        exit;
    }

    $feeRows = [];
    $feeResult = $conn->query("SELECT setting_key, setting_value FROM fee_settings");
    if ($feeResult) {
        while ($fr = $feeResult->fetch_assoc()) {
            $feeRows[$fr["setting_key"]] = (float)$fr["setting_value"];
        }
    }

    $members = [];
    $totalDue = 0;

    while ($row = $result->fetch_assoc()) {
        if ($row["due_fee_id"] !== null) {
            $amount = (float)$row["pending_amount"];
            $row["due_date"] = $row["fee_due_date"];
        } else {
            $shift = strtolower(trim((string)$row["shift"]));
            $plan = strtolower(trim((string)$row["membership_plan"]));
            $isFull = strpos($shift, "full day") !== false;
            $isReserved = strpos($shift, "reserved") !== false;

            // New members without a Pending fee record must still get the
            // correct plan amount. Previously this always used the 1-month
            // rate, so a 3-month Full Day member showed ₹1100 instead of ₹3135.
            $duration = 1;
            if (strpos($plan, "6 month") !== false || strpos($plan, "6 months") !== false) {
                $duration = 6;
            } elseif (strpos($plan, "3 month") !== false || strpos($plan, "3 months") !== false) {
                $duration = 3;
            }

            $term = $duration === 6 ? "six" : ($duration === 3 ? "three" : "one");
            $settingKey = $isFull
                ? ($isReserved ? "full_reserved_{$term}" : "full_day_{$term}")
                : ($isReserved ? "half_reserved_{$term}" : "half_day_{$term}");

            $fallbacks = [
                "full_day_one" => 1100, "full_day_three" => 3135, "full_day_six" => 5940,
                "full_reserved_one" => 1300, "full_reserved_three" => 3705, "full_reserved_six" => 7020,
                "half_day_one" => 600, "half_day_three" => 1710, "half_day_six" => 3240,
                "half_reserved_one" => 800, "half_reserved_three" => 2280, "half_reserved_six" => 4320
            ];
            $amount = $feeRows[$settingKey] ?? $fallbacks[$settingKey];
            $row["due_date"] = $row["validity_date"];
        }

        $row["due_amount"] = $amount;
        $totalDue += $amount;
        $members[] = $row;
    }

    echo json_encode([
        "success"=>true,
        "members"=>$members,
        "total_due"=>$totalDue
    ]);
    exit;
}

if ($action === "list") {

    $memberId = intval($_GET["member_id"] ?? 0);


    /*
    | If member_id is supplied,
    | show only that member's payments.
    */

    if ($memberId > 0) {

        $stmt = $conn->prepare("
            SELECT
                p.id,
                p.receipt_no,
                p.member_id,
                p.member_code,
                p.amount,
                p.fee_amount,
                p.additional_charges,
                p.payment_type,
                p.payment_date,
                p.payment_method,
                p.plan,
                p.notes,
                p.created_at,
                m.name,
                m.phone,
                m.shift,
                m.membership_plan,
                (SELECT ms.seat_no FROM member_seats ms WHERE ms.member_id = m.id AND ms.status = 'Assigned' AND (ms.end_date IS NULL OR ms.end_date >= CURDATE()) ORDER BY ms.id DESC LIMIT 1) AS seat_no,
                (SELECT COALESCE(SUM(mf3.amount),0) FROM member_fees mf3 WHERE mf3.member_id = p.member_id AND mf3.status = 'Pending') AS balance_due
            FROM payments p
            LEFT JOIN members m ON m.id = p.member_id
            WHERE p.member_id = ?
            ORDER BY p.id DESC
        ");

        $stmt->bind_param(
            "i",
            $memberId
        );

    } else {

        /*
        | Otherwise show all payments.
        */

        $stmt = $conn->prepare("
            SELECT
                p.id,
                p.receipt_no,
                p.member_id,
                p.member_code,
                p.amount,
                p.fee_amount,
                p.additional_charges,
                p.payment_type,
                p.payment_date,
                p.payment_method,
                p.plan,
                p.notes,
                p.created_at,
                m.name,
                m.phone,
                m.shift,
                m.membership_plan,
                (SELECT ms.seat_no FROM member_seats ms WHERE ms.member_id = m.id AND ms.status = 'Assigned' AND (ms.end_date IS NULL OR ms.end_date >= CURDATE()) ORDER BY ms.id DESC LIMIT 1) AS seat_no,
                (SELECT COALESCE(SUM(mf3.amount),0) FROM member_fees mf3 WHERE mf3.member_id = p.member_id AND mf3.status = 'Pending') AS balance_due
            FROM payments p
            LEFT JOIN members m ON m.id = p.member_id
            ORDER BY p.id DESC
        ");
    }


    if (!$stmt->execute()) {

        echo json_encode([
            "success" => false,
            "message" => "Could not load payments: " . $stmt->error
        ]);

        exit;
    }


    $result = $stmt->get_result();

    $payments = [];

    $totalPaid = 0;


    while ($row = $result->fetch_assoc()) {

        $row["amount"] = (float)$row["amount"];
        $row["fee_amount"] = (float)($row["fee_amount"] ?? $row["amount"]);
        $row["additional_charges"] = (float)($row["additional_charges"] ?? 0);
        $row["balance_due"] = (float)($row["balance_due"] ?? 0);

        $totalPaid += $row["amount"];

        $payments[] = $row;
    }


    echo json_encode([
        "success" => true,
        "payments" => $payments,
        "total_paid" => $totalPaid
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| ADD PAYMENT
|--------------------------------------------------------------------------
|
| POST:
| member_id
| amount
| payment_date
| payment_method
| plan
| notes
|
|--------------------------------------------------------------------------
*/

if ($action === "add") {
    /*
     * Record the payment and update the fee ledger as one transaction.
     * Every database write is checked so a failed ledger update cannot leave
     * the UI thinking a payment was collected when it was not.
     */
    $memberInput = trim((string)($_POST["member_id"] ?? ""));
    $memberId = ctype_digit($memberInput) ? (int)$memberInput : 0;
    $memberCodeInput = $memberInput;

    $feeAmountInput = trim((string)($_POST["fee_amount"] ?? $_POST["amount"] ?? ""));
    $additionalChargesInput = trim((string)($_POST["additional_charges"] ?? "0"));
    $paymentType = trim((string)($_POST["payment_type"] ?? "Full"));
    $paymentDate = trim((string)($_POST["payment_date"] ?? date("Y-m-d")));
    $paymentMethod = trim((string)($_POST["payment_method"] ?? "Cash"));
    $plan = trim((string)($_POST["plan"] ?? ""));
    $notes = trim((string)($_POST["notes"] ?? ""));

    if ($memberInput === "") payment_json_out(false, "Please select a member.");
    if ($feeAmountInput === "" || !is_numeric($feeAmountInput) || (float)$feeAmountInput <= 0) {
        payment_json_out(false, "Please enter a valid fee payment amount.");
    }
    if ($additionalChargesInput === "" || !is_numeric($additionalChargesInput)) $additionalChargesInput = "0";

    $feeAmount = round((float)$feeAmountInput, 2);
    $additionalCharges = max(0, round((float)$additionalChargesInput, 2));
    $amount = round($feeAmount + $additionalCharges, 2);
    $paymentType = in_array(strtolower($paymentType), ["split", "partial", "split payment", "partial payment"], true) ? "Split" : "Full";
    if ($amount <= 0) payment_json_out(false, "Total payment must be greater than zero.");
    if ($paymentMethod === "") $paymentMethod = "Cash";

    $dateObject = DateTime::createFromFormat("Y-m-d", $paymentDate);
    if (!$dateObject || $dateObject->format("Y-m-d") !== $paymentDate) payment_json_out(false, "Invalid payment date.");

    if (!$conn->begin_transaction()) payment_json_out(false, "Could not start payment transaction.");

    try {
        $memberStmt = $conn->prepare("SELECT id, member_id, name, membership_plan, joining_date, validity_date, status FROM members WHERE (id = ? AND ? <> '') OR member_id = ? LIMIT 1");
        if (!$memberStmt) throw new Exception("Member query error: " . $conn->error);
        $memberStmt->bind_param("iis", $memberId, $memberInput, $memberCodeInput);
        if (!$memberStmt->execute()) throw new Exception("Could not load member: " . $memberStmt->error);
        $member = $memberStmt->get_result()->fetch_assoc();
        $memberStmt->close();
        if (!$member) throw new Exception("Member not found. Please select a valid member.");

        $memberId = (int)$member["id"];
        if ($plan === "") $plan = (string)($member["membership_plan"] ?? "");

        $receiptNo = "";
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $receiptNo = "REC-" . date("YmdHis") . "-" . random_int(100, 999);
            $checkReceipt = $conn->prepare("SELECT id FROM payments WHERE receipt_no = ? LIMIT 1");
            if (!$checkReceipt) throw new Exception("Receipt check error: " . $conn->error);
            $checkReceipt->bind_param("s", $receiptNo);
            if (!$checkReceipt->execute()) throw new Exception("Receipt check failed: " . $checkReceipt->error);
            $receiptResult = $checkReceipt->get_result();
            $exists = $receiptResult && $receiptResult->num_rows > 0;
            $checkReceipt->close();
            if (!$exists) break;
            $receiptNo = "";
        }
        if ($receiptNo === "") throw new Exception("Could not generate a unique receipt number.");

        $stmt = $conn->prepare("INSERT INTO payments (receipt_no, member_id, member_code, amount, fee_amount, additional_charges, payment_type, payment_date, payment_method, plan, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception("Payment query error: " . $conn->error);
        $memberDbId = $memberId;
        $memberCode = (string)$member["member_id"];
        $stmt->bind_param("sisdddsssss", $receiptNo, $memberDbId, $memberCode, $amount, $feeAmount, $additionalCharges, $paymentType, $paymentDate, $paymentMethod, $plan, $notes);
        if (!$stmt->execute()) throw new Exception("Could not save payment: " . $stmt->error);
        $paymentId = (int)$stmt->insert_id;
        $stmt->close();

        /* Apply the fee portion to the oldest pending balance. */
        $pendingStmt = $conn->prepare("SELECT id, amount FROM member_fees WHERE member_id = ? AND status = 'Pending' ORDER BY due_date ASC, id ASC LIMIT 1 FOR UPDATE");
        if (!$pendingStmt) throw new Exception("Due query error: " . $conn->error);
        $pendingStmt->bind_param("i", $memberId);
        if (!$pendingStmt->execute()) throw new Exception("Could not load pending fee: " . $pendingStmt->error);
        $pendingRow = $pendingStmt->get_result()->fetch_assoc();
        $pendingStmt->close();

        if ($pendingRow) {
            $pendingId = (int)$pendingRow["id"];
            $pendingAmount = round((float)$pendingRow["amount"], 2);
            $remaining = round($pendingAmount - $feeAmount, 2);

            if ($remaining <= 0.009) {
                $paidRemark = $notes !== "" ? $notes : "Fee fully paid";
                $paidStmt = $conn->prepare("UPDATE member_fees SET receipt_no = ?, amount = ?, payment_date = ?, payment_method = ?, status = 'Paid', remarks = ? WHERE id = ? LIMIT 1");
                if (!$paidStmt) throw new Exception("Could not prepare fee update: " . $conn->error);
                $paidStmt->bind_param("sdsssi", $receiptNo, $pendingAmount, $paymentDate, $paymentMethod, $paidRemark, $pendingId);
                if (!$paidStmt->execute()) throw new Exception("Could not update fee record: " . $paidStmt->error);
                $paidStmt->close();
            } else {
                $updateDue = $conn->prepare("UPDATE member_fees SET amount = ?, remarks = ? WHERE id = ? AND status = 'Pending' LIMIT 1");
                if (!$updateDue) throw new Exception("Could not prepare due update: " . $conn->error);
                $dueRemark = "Remaining due after payment: ₹" . number_format($remaining, 2, '.', '');
                $updateDue->bind_param("dsi", $remaining, $dueRemark, $pendingId);
                if (!$updateDue->execute()) throw new Exception("Could not update due amount: " . $updateDue->error);
                $updateDue->close();
            }
        } else {
            /* No pending balance existed: add the paid history row. */
            $syncPaid = $conn->prepare("INSERT INTO member_fees (member_id, receipt_no, amount, payment_date, due_date, payment_method, status, remarks) VALUES (?, ?, ?, ?, NULL, ?, 'Paid', ?)");
            if (!$syncPaid) throw new Exception("Could not prepare fee history: " . $conn->error);
            $syncRemark = $notes !== "" ? $notes : ($paymentType === "Split" ? "Split fee payment" : "Fee payment");
            $syncPaid->bind_param("isdsss", $memberId, $receiptNo, $feeAmount, $paymentDate, $paymentMethod, $syncRemark);
            if (!$syncPaid->execute()) throw new Exception("Could not save fee history: " . $syncPaid->error);
            $syncPaid->close();

            if ($paymentType === "Split") {
                $planText = strtolower(trim($plan));
                $shiftText = strtolower(trim((string)($_POST["shift"] ?? "")));
                $duration = 1;
                if (strpos($planText, "6 month") !== false) $duration = 6;
                elseif (strpos($planText, "3 month") !== false) $duration = 3;
                $isFull = strpos($shiftText, "full day") !== false;
                $isReserved = strpos($shiftText, "reserved") !== false;
                $planKey = $isFull ? ($isReserved ? "full_reserved" : "full_day") : ($isReserved ? "half_reserved" : "half_day");
                $suffix = $duration === 6 ? "_six" : ($duration === 3 ? "_three" : "_one");
                $settingKey = $planKey . $suffix;
                $scheduledTotal = 0.0;
                $sr = $conn->prepare("SELECT setting_value FROM fee_settings WHERE setting_key = ? LIMIT 1");
                if ($sr) {
                    $sr->bind_param("s", $settingKey);
                    if ($sr->execute()) {
                        $sv = $sr->get_result()->fetch_assoc();
                        if ($sv) $scheduledTotal = (float)$sv["setting_value"];
                    }
                    $sr->close();
                }
                if ($scheduledTotal <= 0) {
                    $fallbacks = [
                        "half_day_one"=>600,"half_day_three"=>1710,"half_day_six"=>3240,
                        "half_reserved_one"=>800,"half_reserved_three"=>2280,"half_reserved_six"=>4320,
                        "full_day_one"=>1100,"full_day_three"=>3135,"full_day_six"=>5940,
                        "full_reserved_one"=>1300,"full_reserved_three"=>3705,"full_reserved_six"=>7020
                    ];
                    $scheduledTotal = (float)($fallbacks[$settingKey] ?? 0);
                }
                $remaining = round($scheduledTotal - $feeAmount, 2);
                if ($remaining > 0.009) {
                    $dueDate = !empty($member["validity_date"]) ? $member["validity_date"] : $paymentDate;
                    $dueRemark = "Created from split payment; remaining due: ₹" . number_format($remaining, 2, '.', '');
                    $createDue = $conn->prepare("INSERT INTO member_fees (member_id, receipt_no, amount, payment_date, due_date, payment_method, status, remarks) VALUES (?, NULL, ?, ?, ?, 'Pending', 'Pending', ?)");
                    if (!$createDue) throw new Exception("Could not prepare split due: " . $conn->error);
                    $createDue->bind_param("idsss", $memberId, $remaining, $paymentDate, $dueDate, $dueRemark);
                    if (!$createDue->execute()) throw new Exception("Could not create split due: " . $createDue->error);
                    $createDue->close();
                }
            }
        }

        if (!$conn->commit()) throw new Exception("Could not complete payment transaction.");

        payment_json_out(true, "Payment recorded successfully.", [
            "payment_id" => $paymentId,
            "receipt_no" => $receiptNo,
            "member_id" => $memberId,
            "member_code" => $memberCode,
            "member_name" => (string)$member["name"],
            "amount" => $amount,
            "fee_amount" => $feeAmount,
            "additional_charges" => $additionalCharges,
            "payment_type" => $paymentType,
            "payment_date" => $paymentDate,
            "payment_method" => $paymentMethod,
            "plan" => $plan
        ]);
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        payment_json_out(false, $e->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| GET SINGLE PAYMENT
|--------------------------------------------------------------------------
|
| URL:
| payments_api.php?action=get&id=1
|
|--------------------------------------------------------------------------
*/

if ($action === "get") {

    $id = intval(
        $_GET["id"] ?? 0
    );


    if ($id <= 0) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid payment ID."
        ]);

        exit;
    }


    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.receipt_no,
            p.member_id,
            p.member_code,
            p.amount,
            p.payment_date,
            p.payment_method,
            p.plan,
            p.notes,
            p.created_at,

            m.name AS member_name,
            m.phone AS member_phone,
            m.email AS member_email,
            m.address AS member_address

        FROM payments p

        LEFT JOIN members m
            ON m.id = p.member_id

        WHERE p.id = ?

        LIMIT 1
    ");


    if (!$stmt) {

        echo json_encode([
            "success" => false,
            "message" => "Payment query error: " . $conn->error
        ]);

        exit;
    }


    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    $result =
        $stmt->get_result();


    if ($result->num_rows === 0) {

        echo json_encode([
            "success" => false,
            "message" => "Payment not found."
        ]);

        exit;
    }


    $payment =
        $result->fetch_assoc();


    echo json_encode([
        "success" => true,
        "payment" => $payment
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| EDIT DUE FEE
|--------------------------------------------------------------------------
|
| Admin can correct an existing Pending due amount/date. If a member is
| overdue but has no explicit member_fees Pending row yet, editing the due
| creates one so the corrected amount becomes the source of truth.
|
| URL: payments_api.php?action=edit_due
|--------------------------------------------------------------------------
*/
if ($action === "edit_due" && $_SERVER["REQUEST_METHOD"] === "POST") { require_admin();
    $memberId = intval($_POST["member_id"] ?? 0);
    $dueFeeId = intval($_POST["due_fee_id"] ?? 0);
    $amountRaw = trim((string)($_POST["amount"] ?? ""));
    $dueDate = trim((string)($_POST["due_date"] ?? ""));
    $remarks = trim((string)($_POST["remarks"] ?? ""));

    if ($memberId <= 0) {
        echo json_encode(["success"=>false,"message"=>"Invalid member ID."]); exit;
    }
    if ($amountRaw === '' || !is_numeric($amountRaw)) {
        echo json_encode(["success"=>false,"message"=>"Please enter a valid due amount."]); exit;
    }
    $amount = round((float)$amountRaw, 2);
    if ($amount < 0) {
        echo json_encode(["success"=>false,"message"=>"Due amount cannot be negative."]); exit;
    }
    if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        echo json_encode(["success"=>false,"message"=>"Invalid due date."]); exit;
    }

    $memberStmt = $conn->prepare("SELECT id, name, member_id, validity_date FROM members WHERE id = ? LIMIT 1");
    if (!$memberStmt) { echo json_encode(["success"=>false,"message"=>"Member query error: ".$conn->error]); exit; }
    $memberStmt->bind_param("i", $memberId);
    $memberStmt->execute();
    $member = $memberStmt->get_result()->fetch_assoc();
    $memberStmt->close();
    if (!$member) { echo json_encode(["success"=>false,"message"=>"Member not found."]); exit; }

    if ($dueDate === '') $dueDate = !empty($member['validity_date']) ? $member['validity_date'] : date('Y-m-d');

    $conn->begin_transaction();
    try {
        $targetId = 0;
        if ($dueFeeId > 0) {
            $lock = $conn->prepare("SELECT id FROM member_fees WHERE id = ? AND member_id = ? AND status = 'Pending' LIMIT 1 FOR UPDATE");
            if (!$lock) throw new Exception("Could not verify due record: ".$conn->error);
            $lock->bind_param("ii", $dueFeeId, $memberId);
            $lock->execute();
            $found = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$found) throw new Exception("Due record not found or already paid.");
            $targetId = (int)$found['id'];
        } else {
            $find = $conn->prepare("SELECT id FROM member_fees WHERE member_id = ? AND status = 'Pending' ORDER BY due_date ASC, id ASC LIMIT 1 FOR UPDATE");
            if (!$find) throw new Exception("Could not locate pending due: ".$conn->error);
            $find->bind_param("i", $memberId);
            $find->execute();
            $found = $find->get_result()->fetch_assoc();
            $find->close();
            if ($found) $targetId = (int)$found['id'];
        }

        if ($amount <= 0.00) {
            if ($targetId > 0) {
                $del = $conn->prepare("DELETE FROM member_fees WHERE id = ? AND member_id = ? AND status = 'Pending' LIMIT 1");
                if (!$del) throw new Exception("Could not prepare due removal: ".$conn->error);
                $del->bind_param("ii", $targetId, $memberId);
                if (!$del->execute()) throw new Exception("Could not remove due record: ".$del->error);
                $del->close();
            }
            if (function_exists('app_log')) app_log('Due fee edited', 'Member: '.($member['member_id'] ?? $memberId).' | Due set to ₹0 / removed');
            $conn->commit();
            echo json_encode(["success"=>true,"message"=>"Due fee cleared successfully.","amount"=>0]);
            exit;
        }

        if ($targetId > 0) {
            $remarkText = $remarks !== '' ? $remarks : 'Due fee edited by admin';
            $upd = $conn->prepare("UPDATE member_fees SET amount = ?, due_date = ?, payment_method = 'Pending', remarks = ? WHERE id = ? AND member_id = ? AND status = 'Pending' LIMIT 1");
            if (!$upd) throw new Exception("Could not prepare due update: ".$conn->error);
            $upd->bind_param("dssii", $amount, $dueDate, $remarkText, $targetId, $memberId);
            if (!$upd->execute()) throw new Exception("Could not update due fee: ".$upd->error);
            $upd->close();
        } else {
            $remarkText = $remarks !== '' ? $remarks : 'Due fee created/edited by admin';
            $ins = $conn->prepare("INSERT INTO member_fees (member_id, receipt_no, amount, payment_date, due_date, payment_method, status, remarks) VALUES (?, NULL, ?, ?, ?, 'Pending', 'Pending', ?)");
            if (!$ins) throw new Exception("Could not prepare due creation: ".$conn->error);
            $today = date('Y-m-d');
            $ins->bind_param("idsss", $memberId, $amount, $today, $dueDate, $remarkText);
            if (!$ins->execute()) throw new Exception("Could not create due fee: ".$ins->error);
            $ins->close();
        }

        if (function_exists('app_log')) app_log('Due fee edited', 'Member: '.($member['member_id'] ?? $memberId).' | Amount: ₹'.number_format($amount,2,'.','').' | Due date: '.$dueDate);
        $conn->commit();
        echo json_encode(["success"=>true,"message"=>"Due fee updated successfully.","amount"=>$amount,"due_date"=>$dueDate]);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Due fee edit rollback: '.$e->getMessage());
        echo json_encode(["success"=>false,"message"=>$e->getMessage()]);
    }
    exit;
}


/*
|--------------------------------------------------------------------------
| DELETE PAYMENT
|--------------------------------------------------------------------------
|
| URL:
| payments_api.php?action=delete
|
|--------------------------------------------------------------------------
*/

if ($action === "delete") { require_admin();

    $id = intval($_POST["id"] ?? 0);
    if ($id <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid payment ID."]);
        exit;
    }

    /*
     * Deleting a payment must also reverse the fee-ledger effect created when
     * that payment was recorded.  A payment can either:
     *   1) consume/reduce an existing Pending member_fees row, or
     *   2) create a new Pending row for a split payment when no due existed.
     *
     * The add-payment flow also creates one Paid history row using the same
     * receipt number.  We therefore restore the oldest receipt-linked row
     * when it represents the pre-existing due, and remove the newer history
     * row(s).  This keeps Due Fees and member fee history in sync.
     */
    $conn->begin_transaction();

    try {
        $paymentStmt = $conn->prepare("\n            SELECT id, receipt_no, member_id, amount, fee_amount, additional_charges, payment_type, payment_date\n            FROM payments\n            WHERE id = ?\n            LIMIT 1\n            FOR UPDATE\n        ");
        if (!$paymentStmt) throw new Exception("Payment query error: " . $conn->error);
        $paymentStmt->bind_param("i", $id);
        if (!$paymentStmt->execute()) throw new Exception("Could not load payment: " . $paymentStmt->error);
        $payment = $paymentStmt->get_result()->fetch_assoc();
        $paymentStmt->close();

        if (!$payment) {
            throw new Exception("Payment record not found or already deleted.");
        }

        $receiptNo = (string)($payment['receipt_no'] ?? '');
        $memberId = (int)($payment['member_id'] ?? 0);
        $feeAmount = (float)($payment['fee_amount'] ?? $payment['amount'] ?? 0);
        $paymentType = (string)($payment['payment_type'] ?? 'Full');

        /* Lock all receipt-linked ledger rows before deciding what to restore. */
        $ledgerRows = [];
        if ($receiptNo !== '') {
            $ledgerStmt = $conn->prepare("\n                SELECT id, member_id, receipt_no, amount, payment_date, due_date, payment_method, status, remarks, created_at\n                FROM member_fees\n                WHERE receipt_no = ?\n                ORDER BY id ASC\n                FOR UPDATE\n            ");
            if (!$ledgerStmt) throw new Exception("Fee ledger query error: " . $conn->error);
            $ledgerStmt->bind_param("s", $receiptNo);
            if (!$ledgerStmt->execute()) throw new Exception("Could not load fee ledger: " . $ledgerStmt->error);
            $res = $ledgerStmt->get_result();
            while ($row = $res->fetch_assoc()) $ledgerRows[] = $row;
            $ledgerStmt->close();
        }

        /*
         * Rows with NULL receipt_no are created only as split-payment pending
         * balances.  They belong to the deleted payment when their member and
         * timing/remarks indicate they were created by the split flow.
         */
        $nullReceiptPendingIds = [];
        if (strcasecmp($paymentType, 'Split') === 0) {
            $pendingStmt = $conn->prepare("\n                SELECT id, amount, payment_date, due_date, remarks\n                FROM member_fees\n                WHERE member_id = ? AND receipt_no IS NULL AND status = 'Pending'\n                  AND payment_date = ?\n                  AND remarks LIKE 'Created from split payment; remaining due:%'\n                ORDER BY id DESC\n                LIMIT 5\n                FOR UPDATE\n            ");
            if ($pendingStmt) {
                $pendingStmt->bind_param("is", $memberId, $payment['payment_date']);
                $pendingStmt->execute();
                $pr = $pendingStmt->get_result();
                while ($row = $pr->fetch_assoc()) $nullReceiptPendingIds[] = (int)$row['id'];
                $pendingStmt->close();
            }
        }

        /*
         * If the receipt is linked to multiple rows, the oldest row is the
         * pre-existing due that was modified by the payment flow; newer rows
         * are the newly inserted Paid history entry. Restore the old row by
         * adding the actual fee amount back to its balance.
         */
        $restoreId = 0;
        if (count($ledgerRows) >= 2) {
            $restoreId = (int)$ledgerRows[0]['id'];
            $originalLedgerAmount = (float)$ledgerRows[0]['amount'];
            $wasFullyPaid = (strcasecmp((string)$ledgerRows[0]['status'], 'Paid') === 0);
            $restoredAmount = $wasFullyPaid ? $originalLedgerAmount : round($originalLedgerAmount + $feeAmount, 2);

            $restoreStmt = $conn->prepare("\n                UPDATE member_fees\n                SET amount = ?, status = 'Pending', receipt_no = NULL, payment_date = ?, payment_method = 'Pending', remarks = ?\n                WHERE id = ?\n                LIMIT 1\n            ");
            if (!$restoreStmt) throw new Exception("Could not prepare due restoration: " . $conn->error);
            $restoreRemark = 'Restored after deletion of payment ' . $receiptNo . '; amount due restored.';
            $restoreStmt->bind_param("dssi", $restoredAmount, $payment['payment_date'], $restoreRemark, $restoreId);
            if (!$restoreStmt->execute()) throw new Exception("Could not restore due fee: " . $restoreStmt->error);
            $restoreStmt->close();

            /* Remove every other ledger row carrying this receipt. */
            $deleteLedger = $conn->prepare("DELETE FROM member_fees WHERE receipt_no = ?");
            if (!$deleteLedger) throw new Exception("Could not prepare fee-history cleanup: " . $conn->error);
            $deleteLedger->bind_param("s", $receiptNo);
            if (!$deleteLedger->execute()) throw new Exception("Could not remove linked fee history: " . $deleteLedger->error);
            $deleteLedger->close();
        } elseif (count($ledgerRows) === 1) {
            /* No pre-existing due: this is a standalone paid history row. */
            $deleteLedger = $conn->prepare("DELETE FROM member_fees WHERE receipt_no = ?");
            if (!$deleteLedger) throw new Exception("Could not prepare fee-history cleanup: " . $conn->error);
            $deleteLedger->bind_param("s", $receiptNo);
            if (!$deleteLedger->execute()) throw new Exception("Could not remove linked fee history: " . $deleteLedger->error);
            $deleteLedger->close();
        }

        /* Split payments with no previous due created a new NULL-receipt due. */
        if (!empty($nullReceiptPendingIds)) {
            $deletePending = $conn->prepare("DELETE FROM member_fees WHERE id = ? LIMIT 1");
            if (!$deletePending) throw new Exception("Could not prepare split-due cleanup: " . $conn->error);
            foreach ($nullReceiptPendingIds as $pendingId) {
                $deletePending->bind_param("i", $pendingId);
                if (!$deletePending->execute()) throw new Exception("Could not remove split-payment due: " . $deletePending->error);
            }
            $deletePending->close();
        }

        /* Finally remove the main payment row. */
        $deletePayment = $conn->prepare("DELETE FROM payments WHERE id = ? LIMIT 1");
        if (!$deletePayment) throw new Exception("Payment delete query error: " . $conn->error);
        $deletePayment->bind_param("i", $id);
        if (!$deletePayment->execute() || $deletePayment->affected_rows !== 1) {
            throw new Exception("Could not delete payment record.");
        }
        $deletePayment->close();

        if (function_exists('app_log')) {
            app_log('Payment deleted and fee balance restored', 'Payment ID: ' . $id . ($receiptNo ? ' | Receipt: ' . $receiptNo : '') . ' | Restored fee: ₹' . number_format($feeAmount, 2, '.', ''));
        }

        $conn->commit();
        echo json_encode([
            "success" => true,
            "message" => "Payment deleted and applicable due fee restored successfully.",
            "restored_amount" => $feeAmount
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Payment delete rollback: ' . $e->getMessage());
        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);
    }
    exit;
}


/*
|--------------------------------------------------------------------------
| INVALID ACTION
|--------------------------------------------------------------------------
*/

echo json_encode([
    "success" => false,
    "message" => "Invalid payment API action."
]);

?>