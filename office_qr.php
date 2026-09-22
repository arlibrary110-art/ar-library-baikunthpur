<?php
require_once __DIR__ . "/security.php";
if (!isset($_SESSION["staff_id"])) {
    header("Location: index.php");
    exit;
}
header("Location: qr_display.php", true, 302);
exit;
