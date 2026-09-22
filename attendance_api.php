<?php

require_once __DIR__ . "/security.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

/*
|--------------------------------------------------------------------------
| Attendance API Protection
|--------------------------------------------------------------------------
| PHP warning / notice / HTML output को JSON response खराब करने से रोकता है.
*/

ob_start();


register_shutdown_function(function () {

    $err = error_get_last();

    if (!$err) {
        return;
    }

    $fatal = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR
    ];

    if (!in_array($err['type'], $fatal, true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header("Content-Type: application/json; charset=utf-8");
    }

    echo json_encode(
        [
            "success" => false,
            "message" => "Attendance service temporarily unavailable. Please try again."
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
});


/*
|--------------------------------------------------------------------------
| JSON RESPONSE HELPER
|--------------------------------------------------------------------------
*/

function response_json(
    $success,
    $message = "",
    $extra = [],
    $status = 200
) {

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);

    header("Content-Type: application/json; charset=utf-8");

    echo json_encode(
        array_merge(
            [
                "success" => $success,
                "message" => $message
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| ACTION
|--------------------------------------------------------------------------
*/

$action = $_GET["action"] ?? "";

if (in_array($action, ['today','member_history','attendance','checkout'], true)) require_permission('attendance');

if ($_SERVER["REQUEST_METHOD"] !== "GET" && $action !== "scan_attendance") {
    require_csrf();
}





function db_today($conn)
{
    $result = $conn->query("SELECT CURDATE() AS today");
    if (!$result) {
        return date("Y-m-d");
    }
    $row = $result->fetch_assoc();
    return $row["today"] ?? date("Y-m-d");
}

/*
|--------------------------------------------------------------------------
| ADMIN - TODAY ATTENDANCE
|--------------------------------------------------------------------------
|
| GET:
| attendance.php?action=today
|
*/

if ($action === "today") {

    require_permission('attendance');

    $today = date("Y-m-d");

    $sql = "
        SELECT
            a.id AS id,
            a.id AS attendance_id,
            a.member_id AS member_db_id,

            m.member_id AS member_id,
            m.name,
            m.phone,
            m.shift,

            a.attendance_date,

            DATE_FORMAT(
                a.check_in,
                '%h:%i %p'
            ) AS check_in_time,

            DATE_FORMAT(
                a.check_out,
                '%h:%i %p'
            ) AS check_out_time,

            a.check_in,
            a.check_out,

            a.status AS status

        FROM attendance AS a

        INNER JOIN members AS m
            ON m.id = a.member_id

        WHERE a.attendance_date = ?

        ORDER BY
            a.check_in DESC,
            a.id DESC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {

        response_json(
            false,
            "Database query error: " . $conn->error,
            [],
            500
        );
    }

    $stmt->bind_param(
        "s",
        $today
    );

    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        response_json(
            false,
            "Could not load today's attendance: " . $error,
            [],
            500
        );
    }

    $result = $stmt->get_result();

    $attendance = [];

    while ($row = $result->fetch_assoc()) {

        $attendance[] = $row;
    }

    $stmt->close();

    response_json(
        true,
        "",
        [
            "attendance" => $attendance,
            "date" => $today
        ]
    );
}


/*
|--------------------------------------------------------------------------
| ADMIN - MEMBER ATTENDANCE HISTORY
|--------------------------------------------------------------------------
|
| GET:
| attendance.php?action=member_history&member_id=123
|
*/

if ($action === "member_history") {

    require_permission('attendance');

    $memberDbId = (int)($_GET["member_id"] ?? 0);

    if ($memberDbId <= 0) {

        response_json(
            false,
            "Invalid member",
            [],
            422
        );
    }

    /*
    | IMPORTANT:
    | हर column को table alias के साथ लिखा गया है.
    | इससे status / id / attendance_date ambiguity नहीं होगी.
    */

    $sql = "
        SELECT

            a.id AS attendance_id,

            a.member_id AS member_db_id,

            m.member_id AS member_code,
            m.name,
            m.phone,
            m.shift,

            a.attendance_date,

            DATE_FORMAT(
                a.attendance_date,
                '%d %b %Y'
            ) AS attendance_date_display,

            a.check_in,
            a.check_out,

            DATE_FORMAT(
                a.check_in,
                '%h:%i %p'
            ) AS check_in_time,

            DATE_FORMAT(
                a.check_out,
                '%h:%i %p'
            ) AS check_out_time,

            a.status AS status

        FROM attendance AS a

        INNER JOIN members AS m
            ON m.id = a.member_id

        WHERE a.member_id = ?

        ORDER BY
            a.attendance_date DESC,
            a.id DESC

        LIMIT 365
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {

        response_json(
            false,
            "Database query error: " . $conn->error,
            [],
            500
        );
    }

    $stmt->bind_param(
        "i",
        $memberDbId
    );

    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        response_json(
            false,
            "Could not load attendance history: " . $error,
            [],
            500
        );
    }

    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {

        $rows[] = $row;
    }

    $stmt->close();

    response_json(
        true,
        "",
        [
            "attendance" => $rows
        ]
    );
}


/*
|--------------------------------------------------------------------------
| ADMIN - ATTENDANCE
|--------------------------------------------------------------------------
|
| GET:
| attendance.php?action=attendance&date=2026-08-29
|
| POST:
| member_id
| action = in / out
|
*/

if ($action === "attendance") {

    require_permission('attendance');


    /*
    |--------------------------------------------------------------------------
    | GET - Attendance List
    |--------------------------------------------------------------------------
    */

    if ($_SERVER["REQUEST_METHOD"] === "GET") {

        $date = trim(
            $_GET["date"] ?? date("Y-m-d")
        );

        /*
        | Correct date validation
        */

        if (!preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $date
        )) {

            $date = date("Y-m-d");
        }


        $sql = "
            SELECT

                a.id AS attendance_id,

                a.member_id AS member_db_id,

                m.member_id AS member_id,

                m.name,
                m.phone,
                m.shift,

                a.attendance_date,

                a.check_in,
                a.check_out,

                DATE_FORMAT(
                    a.check_in,
                    '%h:%i %p'
                ) AS check_in_time,

                DATE_FORMAT(
                    a.check_out,
                    '%h:%i %p'
                ) AS check_out_time,

                CASE
                    WHEN a.check_in IS NOT NULL AND a.check_out IS NOT NULL
                    THEN TIMESTAMPDIFF(MINUTE, a.check_in, a.check_out)
                    ELSE NULL
                END AS duration_minutes,

                a.status AS status

            FROM attendance AS a

            INNER JOIN members AS m
                ON m.id = a.member_id

            WHERE a.attendance_date = ?

            ORDER BY
                a.check_in DESC,
                a.id DESC
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {

            response_json(
                false,
                "Database query error: " . $conn->error,
                [],
                500
            );
        }

        $stmt->bind_param(
            "s",
            $date
        );

        if (!$stmt->execute()) {

            $error = $stmt->error;

            $stmt->close();

            response_json(
                false,
                "Could not load attendance: " . $error,
                [],
                500
            );
        }

        $result = $stmt->get_result();

        $rows = [];

        while ($row = $result->fetch_assoc()) {

            $rows[] = $row;
        }

        $stmt->close();

        response_json(
            true,
            "",
            [
                "attendance" => $rows,
                "date" => $date
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | POST - Check In / Check Out
    |--------------------------------------------------------------------------
    */

    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $memberId = (int)(
            $_POST["member_id"] ?? 0
        );

        $act = strtolower(
            trim(
                $_POST["action"] ?? "in"
            )
        );


        /*
        | Validate member ID
        */

        if ($memberId <= 0) {

            response_json(
                false,
                "Invalid member.",
                [],
                422
            );
        }


        /*
        | Validate attendance action
        */

        if (!in_array(
            $act,
            ["in", "out"],
            true
        )) {

            response_json(
                false,
                "Invalid attendance action.",
                [],
                422
            );
        }


        /*
        |--------------------------------------------------------------------------
        | GET MEMBER
        |--------------------------------------------------------------------------
        */

        $sql = "
            SELECT

                id,
                member_id,
                name,
                phone,
                shift,
                status,
                validity_date

            FROM members

            WHERE id = ?

            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {

            response_json(
                false,
                "Member database error: " . $conn->error,
                [],
                500
            );
        }

        $stmt->bind_param(
            "i",
            $memberId
        );

        if (!$stmt->execute()) {

            $error = $stmt->error;

            $stmt->close();

            response_json(
                false,
                "Could not find member: " . $error,
                [],
                500
            );
        }

        $member = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();


        if (!$member) {

            response_json(
                false,
                "Member not found.",
                [],
                404
            );
        }


        /*
        |--------------------------------------------------------------------------
        | MEMBER ACTIVE CHECK
        |--------------------------------------------------------------------------
        */

        if (
            strtolower(
                trim(
                    (string)$member["status"]
                )
            ) !== "active"
        ) {

            response_json(
                false,
                "Member is not active.",
                [],
                422
            );
        }


        /*
        |--------------------------------------------------------------------------
        | MEMBERSHIP VALIDITY
        |--------------------------------------------------------------------------
        */

        if (
            !empty($member["validity_date"]) &&
            $member["validity_date"] < db_today($conn)
        ) {

            response_json(
                false,
                "Member membership has expired.",
                [],
                422
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CHECK OUT
        |--------------------------------------------------------------------------
        */

        if ($act === "out") {

            $sql = "
                UPDATE attendance

                SET
                    check_out = NOW(),
                    status = 'Closed'

                WHERE
                    member_id = ?

                    AND attendance_date = CURDATE()

                    AND check_in IS NOT NULL

                    AND check_out IS NULL

                ORDER BY id DESC

                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {

                response_json(
                    false,
                    "Could not prepare check-out: " . $conn->error,
                    [],
                    500
                );
            }

            $stmt->bind_param(
                "i",
                $memberId
            );

            if (!$stmt->execute()) {

                $error = $stmt->error;

                $stmt->close();

                response_json(
                    false,
                    "Could not record check-out: " . $error,
                    [],
                    500
                );
            }

            $ok = $stmt->affected_rows > 0;

            $stmt->close();


            if (!$ok) {

                response_json(
                    false,
                    "No open attendance found for this member.",
                    [],
                    422
                );
            }


            response_json(
                true,
                "Check-out recorded."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CHECK IF ALREADY CHECKED IN
        |--------------------------------------------------------------------------
        */

        $sql = "
            SELECT
                id

            FROM attendance

            WHERE
                member_id = ?

                AND attendance_date = CURDATE()

                AND check_in IS NOT NULL

                AND check_out IS NULL

            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {

            response_json(
                false,
                "Attendance database error: " . $conn->error,
                [],
                500
            );
        }

        $stmt->bind_param(
            "i",
            $memberId
        );

        if (!$stmt->execute()) {

            $error = $stmt->error;

            $stmt->close();

            response_json(
                false,
                "Could not check attendance: " . $error,
                [],
                500
            );
        }

        $open = (
            $stmt
                ->get_result()
                ->num_rows > 0
        );

        $stmt->close();


        if ($open) {

            response_json(
                false,
                "Member is already checked in.",
                [],
                422
            );
        }


        /*
        |--------------------------------------------------------------------------
        | INSERT CHECK IN
        |--------------------------------------------------------------------------
        */

        $sql = "
            INSERT INTO attendance
            (
                member_id,
                attendance_date,
                check_in,
                status
            )

            VALUES
            (
                ?,
                CURDATE(),
                NOW(),
                'Open'
            )
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {

            response_json(
                false,
                "Could not prepare check-in: " . $conn->error,
                [],
                500
            );
        }

        $stmt->bind_param(
            "i",
            $memberId
        );

        if (!$stmt->execute()) {

            $error = $stmt->error;

            $stmt->close();

            response_json(
                false,
                "Could not record check-in: " . $error,
                [],
                500
            );
        }

        $attendanceId = $stmt->insert_id;

        $stmt->close();


        response_json(
            true,
            "Check-in recorded.",
            [
                "attendance_id" => $attendanceId
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | INVALID METHOD
    |--------------------------------------------------------------------------
    */

    response_json(
        false,
        "Method not allowed.",
        [],
        405
    );
}


/*
|--------------------------------------------------------------------------
| ADMIN - CHECKOUT BY ATTENDANCE ID
|--------------------------------------------------------------------------
|
| POST:
| attendance.php?action=checkout
|
| attendance_id
|
*/

if (
    $action === "checkout" &&
    $_SERVER["REQUEST_METHOD"] === "POST"
) {

    require_permission('attendance');

    $id = (int)(
        $_POST["attendance_id"] ?? 0
    );


    if ($id <= 0) {

        response_json(
            false,
            "Invalid attendance record.",
            [],
            422
        );
    }


    $sql = "
        UPDATE attendance

        SET
            check_out = NOW(),
            status = 'Closed'

        WHERE
            id = ?

            AND check_out IS NULL
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {

        response_json(
            false,
            "Could not prepare check-out: " . $conn->error,
            [],
            500
        );
    }

    $stmt->bind_param(
        "i",
        $id
    );

    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        response_json(
            false,
            "Could not record check-out: " . $error,
            [],
            500
        );
    }

    $ok = $stmt->affected_rows > 0;

    $stmt->close();


    response_json(
        $ok,
        $ok
            ? "Check-out recorded."
            : "Attendance record not found or already closed.",
        [],
        $ok ? 200 : 422
    );
}


/*
|--------------------------------------------------------------------------
| MEMBER APP - PERMANENT QR + GPS ATTENDANCE
|--------------------------------------------------------------------------
| POST: action=scan_attendance
| token, latitude, longitude, accuracy(optional)
|--------------------------------------------------------------------------
*/
if ($action === "scan_attendance" && $_SERVER["REQUEST_METHOD"] === "POST") {

    if (!isset($_SESSION["member_app_id"])) {
        response_json(false, "Member login required", [], 401);
    }

    $memberDbId = (int)$_SESSION["member_app_id"];
    $token = trim((string)($_POST["token"] ?? ""));
    $lat = filter_var($_POST["latitude"] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_POST["longitude"] ?? null, FILTER_VALIDATE_FLOAT);
    $accuracy = filter_var($_POST["accuracy"] ?? null, FILTER_VALIDATE_FLOAT);

    if ($token !== "AR_LIBRARY_ATTENDANCE_V1") {
        response_json(false, "Invalid attendance QR code", [], 422);
    }
    if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        response_json(false, "Could not verify your location. Please allow location permission and try again.", [], 422);
    }
    if ($accuracy !== false && $accuracy !== null && ($accuracy < 0 || $accuracy > 500)) {
        response_json(false, "Location accuracy is too low. Please turn on precise location and try again.", [], 422);
    }

    // Load configured library coordinates from DB. Defaults match the requested AR Library location.
    $cfg = ["lat"=>24.735323,"lng"=>81.409182,"radius"=>100];
    $res = $conn->query("SELECT setting_key, setting_value FROM library_settings WHERE setting_key IN ('attendance_latitude','attendance_longitude','attendance_radius_meters')");
    if ($res) {
        while ($row=$res->fetch_assoc()) {
            if ($row['setting_key']==='attendance_latitude') $cfg['lat']=(float)$row['setting_value'];
            if ($row['setting_key']==='attendance_longitude') $cfg['lng']=(float)$row['setting_value'];
            if ($row['setting_key']==='attendance_radius_meters') $cfg['radius']=max(1,(int)$row['setting_value']);
        }
    }

    // Haversine distance in meters, calculated server-side.
    $earth = 6371000.0;
    $dLat = deg2rad($lat - $cfg['lat']);
    $dLng = deg2rad($lng - $cfg['lng']);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($cfg['lat'])) * cos(deg2rad($lat)) * sin($dLng/2) * sin($dLng/2);
    $distance = 2 * $earth * atan2(sqrt($a), sqrt(max(0,1-$a)));

    if ($distance > $cfg['radius']) {
        response_json(false, "Attendance is allowed only within {$cfg['radius']} meters of AR Library. You are approximately " . round($distance) . " meters away.", [
            "distance_meters"=>round($distance,1), "allowed_radius_meters"=>$cfg['radius']
        ], 422);
    }

    // Get member and validate account/membership.
    $stmt=$conn->prepare("SELECT id,member_id,name,phone,email,membership_plan,shift,joining_date,validity_date,address,status FROM members WHERE id=? LIMIT 1");
    if (!$stmt) response_json(false,"Member database error: ".$conn->error,[],500);
    $stmt->bind_param("i",$memberDbId);
    if (!$stmt->execute()) { $e=$stmt->error; $stmt->close(); response_json(false,"Could not load member: ".$e,[],500); }
    $member=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$member) response_json(false,"Member not found",[],404);
    if (strtolower(trim((string)$member['status'])) !== 'active') response_json(false,"Your membership is not active",[],422);
    if (!empty($member['validity_date']) && $member['validity_date'] < db_today($conn)) response_json(false,"Your membership has expired",[],422);

    $today=db_today($conn);
    $stmt=$conn->prepare("SELECT id,check_in,check_out,status FROM attendance WHERE member_id=? AND attendance_date=? ORDER BY id DESC LIMIT 1");
    if (!$stmt) response_json(false,"Attendance database error: ".$conn->error,[],500);
    $stmt->bind_param("is",$memberDbId,$today);
    if (!$stmt->execute()) { $e=$stmt->error; $stmt->close(); response_json(false,"Could not load attendance: ".$e,[],500); }
    $existing=$stmt->get_result()->fetch_assoc(); $stmt->close();

    if (!$existing) {
        $stmt=$conn->prepare("INSERT INTO attendance(member_id,attendance_date,check_in,status,remarks) VALUES(?,?,NOW(),'Open',?)");
        if (!$stmt) response_json(false,"Could not prepare check-in: ".$conn->error,[],500);
        $remarks='GPS verified; distance '.round($distance,1).'m; accuracy '.(($accuracy===false||$accuracy===null)?'unknown':round($accuracy,1).'m');
        $stmt->bind_param("iss",$memberDbId,$today,$remarks);
        if (!$stmt->execute()) { $e=$stmt->error; $stmt->close(); response_json(false,"Could not record check-in: ".$e,[],500); }
        $id=$stmt->insert_id; $stmt->close();
        response_json(true,"Check-in successful",["action"=>"check_in","attendance_id"=>$id,"distance_meters"=>round($distance,1),"member"=>$member]);
    }

    if (empty($existing['check_out'])) {
        $stmt=$conn->prepare("UPDATE attendance SET check_out=NOW(),status='Completed',remarks=CONCAT(COALESCE(remarks,''),' | Check-out GPS verified; distance ',?,'m') WHERE id=?");
        if (!$stmt) response_json(false,"Could not prepare check-out: ".$conn->error,[],500);
        $distText=number_format($distance,1,'.',''); $id=(int)$existing['id'];
        $stmt->bind_param("si",$distText,$id);
        if (!$stmt->execute()) { $e=$stmt->error; $stmt->close(); response_json(false,"Could not record check-out: ".$e,[],500); }
        $stmt->close();
        response_json(true,"Check-out successful",["action"=>"check_out","attendance_id"=>$id,"distance_meters"=>round($distance,1),"member"=>$member]);
    }

    response_json(false,"Today's attendance is already completed.",[
        "action"=>"completed","distance_meters"=>round($distance,1),"member"=>$member
    ],422);
}

response_json(false,"Invalid attendance action",[],404);
