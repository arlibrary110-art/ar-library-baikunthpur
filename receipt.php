<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_permission('fees');

$id = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare(""
    . "SELECT p.*, m.name, m.phone, m.email, m.address, m.shift, m.membership_plan,
       (SELECT ms.seat_no FROM member_seats ms WHERE ms.member_id = m.id AND ms.status = 'Assigned' AND (ms.end_date IS NULL OR ms.end_date >= CURDATE()) ORDER BY ms.id DESC LIMIT 1) AS seat_no "
    . "FROM payments p "
    . "LEFT JOIN members m ON m.id = p.member_id "
    . "WHERE p.id = ? LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);
    exit('Could not load receipt.');
}

$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$p = $result->fetch_assoc();
$stmt->close();

if (!$p) {
    http_response_code(404);
    exit('Receipt not found.');
}

$amountPaid = (float)($p['amount'] ?? 0);
$feeAmount = (float)($p['fee_amount'] ?? $amountPaid);
$additionalCharges = (float)($p['additional_charges'] ?? 0);
$downloadReceipt = isset($_GET['download']) && $_GET['download'] === '1';

/*
 * Calculate the remaining fee after this payment.
 * Pending member_fees are the source of truth for split/partial dues.
 */
$balanceDue = 0.0;
$dueStmt = $conn->prepare(""
    . "SELECT COALESCE(SUM(amount), 0) AS balance_due "
    . "FROM member_fees "
    . "WHERE member_id = ? AND status = 'Pending'"
);

if ($dueStmt) {
    $memberDbId = (int)$p['member_id'];
    $dueStmt->bind_param('i', $memberDbId);
    $dueStmt->execute();
    $dueRow = $dueStmt->get_result()->fetch_assoc();
    $balanceDue = (float)($dueRow['balance_due'] ?? 0);
    $dueStmt->close();
}

$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Embed receipt images as data URIs so downloaded HTML receipts remain self-contained.
// Relative asset paths do not work after the receipt file is downloaded to the user's device.
function receipt_asset_data_uri(string $path, string $mime): string {
    if (!is_file($path)) {
        return '';
    }
    $data = @file_get_contents($path);
    return $data !== false ? 'data:' . $mime . ';base64,' . base64_encode($data) : '';
}
$logoDataUri = receipt_asset_data_uri(__DIR__ . '/assets/ar-library-logo.webp', 'image/webp');
$signatureDataUri = receipt_asset_data_uri(__DIR__ . '/assets/abhimanyu-signature.png', 'image/png');

// WhatsApp receipt message (opens WhatsApp with the receipt details pre-filled).
$rawPhone = preg_replace('/\D+/', '', (string)($p['phone'] ?? ''));
if (strlen($rawPhone) === 10) {
    $rawPhone = '91' . $rawPhone;
}
$waMessage = "*AR LIBRARY — FEE RECEIPT*\n\n"
    . "Receipt No.: " . ($p['receipt_no'] ?? '—') . "\n"
    . "Member: " . ($p['member_code'] ?? '—') . " — " . ($p['name'] ?? '—') . "\n"
    . "Date: " . ($p['payment_date'] ?? '—') . "\n"
    . "Fee Paid: ₹" . number_format($feeAmount, 2) . "\n"
    . ($additionalCharges > 0 ? "Additional Charges: ₹" . number_format($additionalCharges, 2) . "\n" : '')
    . "*Total Amount Paid: ₹" . number_format($amountPaid, 2) . "*\n"
    . "Balance Due: ₹" . number_format(max(0, $balanceDue), 2) . "\n\n"
    . "Seat No.: " . ($p['seat_no'] ?? '—') . "\n\n"
    . "Thank you for choosing AR Library.";
$waUrl = $rawPhone !== '' ? 'https://wa.me/' . $rawPhone . '?text=' . rawurlencode($waMessage) : '';

if ($downloadReceipt) {
    $safeReceiptNo = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($p['receipt_no'] ?? 'receipt'));
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="AR-Library-Receipt-' . $safeReceiptNo . '.html"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Receipt <?= $esc($p['receipt_no']) ?></title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#f4f6fb;color:#26365b;margin:0;padding:24px}
.receipt{max-width:700px;margin:auto;background:#fff;padding:32px;border:1px solid #d8deec;border-radius:14px;box-shadow:0 12px 35px rgba(30,45,90,.10)}
.head{text-align:center;border-bottom:2px solid #26365b;padding-bottom:18px;margin-bottom:10px}
.head h1{margin:0;font-size:28px}
.head p{margin:5px 0 0;color:#7d879f}
.row{display:flex;justify-content:space-between;gap:20px;padding:10px 0;border-bottom:1px dashed #ccd2df}
.row span{text-align:right;word-break:break-word}
.amount{font-size:22px;font-weight:bold;color:#2f80ed}
.balance{margin-top:18px;padding:16px;border-radius:10px;background:#fff4db;border:1px solid #efcf82;color:#8a5b00;display:flex;justify-content:space-between;gap:15px;font-size:18px;font-weight:800}
.balance.clear{background:#e8f7ee;border-color:#a8dcbc;color:#27784e}
.notes{margin-top:18px;padding:12px;background:#f7f8fc;border-radius:9px;color:#5e6982}
.footer{margin-top:35px;text-align:center;color:#69748d;font-size:12px;line-height:1.6}
.signature{margin-top:35px;text-align:right}.signature-label{font-weight:800;color:#26365b;margin-bottom:2px}.signature-img{display:block;width:190px;height:auto;max-height:72px;object-fit:contain;object-position:right center;margin:0 0 0 auto}
.actions{text-align:center;margin:18px auto 0;display:flex;justify-content:center;gap:8px;flex-wrap:wrap}
.btn{padding:10px 18px;border:0;border-radius:7px;cursor:pointer;background:#26365b;color:#fff;font-weight:800}
.btn.secondary{background:#e1e6f2;color:#26365b}.btn.whatsapp{background:#25D366;color:#fff;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
@media(max-width:560px){body{padding:10px}.receipt{padding:20px}.row{font-size:13px}.amount{font-size:19px}.balance{font-size:16px}}
@media print{body{background:#fff;padding:0}.receipt{border:0;box-shadow:none;max-width:none;border-radius:0}.actions{display:none}}
</style>
</head>
<body>
<div class="receipt">
    <div class="head">
        <img src="<?= $logoDataUri ?: 'assets/ar-library-logo.webp' ?>" alt="AR Library logo" style="width:86px;height:86px;object-fit:contain;border-radius:16px">
        <h1>AR LIBRARY</h1>
        <p>Payment Receipt</p>
    </div>

    <div class="row"><b>Receipt No.</b><span><?= $esc($p['receipt_no']) ?></span></div>
    <div class="row"><b>Date</b><span><?= $esc($p['payment_date']) ?></span></div>
    <div class="row"><b>Member</b><span><?= $esc($p['member_code']) ?> — <?= $esc($p['name']) ?></span></div>
    <div class="row"><b>Phone</b><span><?= $esc($p['phone'] ?? '—') ?></span></div>
    <div class="row"><b>Shift</b><span><?= $esc($p['shift'] ?? '—') ?></span></div>
    <div class="row"><b>Seat No.</b><span><?= $esc($p['seat_no'] ?? '—') ?></span></div>
    <div class="row"><b>Plan</b><span><?= $esc($p['plan'] ?: ($p['membership_plan'] ?? '—')) ?></span></div>
    <div class="row"><b>Payment Method</b><span><?= $esc($p['payment_method']) ?></span></div>
    <div class="row"><b>Fee Paid</b><span>₹<?= number_format($feeAmount, 2) ?></span></div>
    <?php if ($additionalCharges > 0): ?>
        <div class="row"><b>Additional Charges</b><span>₹<?= number_format($additionalCharges, 2) ?></span></div>
    <?php endif; ?>
    <div class="row amount"><b>Total Amount Paid</b><span>₹<?= number_format($amountPaid, 2) ?></span></div>

    <div class="balance <?= $balanceDue <= 0.009 ? 'clear' : '' ?>">
        <span><?= $balanceDue <= 0.009 ? 'Fee Balance' : 'Payment Remaining / Due' ?></span>
        <span>₹<?= number_format(max(0, $balanceDue), 2) ?></span>
    </div>

    <?php if (!empty($p['notes'])): ?>
        <div class="notes"><b>Notes:</b> <?= $esc($p['notes']) ?></div>
    <?php endif; ?>

    <div class="footer">
        <?php if ($balanceDue <= 0.009): ?>
            <b>Fee fully paid. Thank you!</b>
        <?php else: ?>
            <b>Remaining fee is due. Please clear the balance at the earliest.</b>
        <?php endif; ?>
        <br>
        <?=htmlspecialchars((string)get_setting('library_name','AR LIBRARY'),ENT_QUOTES,'UTF-8')?> — <?=htmlspecialchars((string)get_setting('address','सरकारी हॉस्पिटल के सामने, बैकुण्ठपुर (म.प्र.)'),ENT_QUOTES,'UTF-8')?><br>
        <?=htmlspecialchars((string)get_setting('phone',''),ENT_QUOTES,'UTF-8')?>
    </div>

    <div class="signature"><div class="signature-label">Authorized Signature</div><img class="signature-img" src="<?= $signatureDataUri ?: 'assets/abhimanyu-signature.png' ?>" alt="Authorized signature"></div>
</div>


</body>
</html>
