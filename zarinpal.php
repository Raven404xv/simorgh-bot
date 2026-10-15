<?php
/**
 * بازگشت از درگاه زرین‌پال. پرداخت فقط یک بار تأیید و کیف پول فقط یک بار شارژ می‌شود.
 */
declare(strict_types=1);

require __DIR__ . '/src/loader.php';

$authority = trim((string) ($_GET['Authority'] ?? ''));
$status = (string) ($_GET['Status'] ?? '');
$row = $authority !== '' ? one('SELECT * FROM gateway_payments WHERE authority = ?', [$authority]) : null;

$ok = false;
$text = 'اطلاعات پرداخت معتبر نیست.';

if ($row !== null) {
    if ($row['status'] === 'paid') {
        $ok = true;
        $text = 'این پرداخت قبلاً ثبت شده و موجودی شما شارژ شده است.';
    } elseif ($row['status'] === 'pending' && $status === 'OK') {
        // قفل کردن: فقط یک درخواست می‌تواند وارد تأیید شود
        $claim = q("UPDATE gateway_payments SET status = 'verifying' WHERE authority = ? AND status = 'pending'", [$authority]);
        if ($claim->rowCount() === 1) {
            $ref = zarinpal_verify($authority, (int) $row['amount']);
            if ($ref !== null) {
                q("UPDATE gateway_payments SET status = 'paid', ref_id = ? WHERE authority = ?", [$ref, $authority]);
                approve_charge((string) $row['charge_id']);
                $ok = true;
                $text = 'پرداخت با موفقیت انجام شد و موجودی کیف پول شما شارژ شد.';
            } else {
                q("UPDATE gateway_payments SET status = 'failed' WHERE authority = ?", [$authority]);
                $text = 'تأیید پرداخت انجام نشد. اگر مبلغی کسر شده، با پشتیبانی تماس بگیرید.';
            }
        }
    } else {
        q("UPDATE gateway_payments SET status = 'cancelled' WHERE authority = ? AND status = 'pending'", [$authority]);
        q("UPDATE wallet_charges SET status = 'cancelled' WHERE id = ? AND status = 'waiting'", [(string) $row['charge_id']]);
        $text = 'پرداخت لغو شد.';
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>نتیجه‌ی پرداخت</title>'
    . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0f1115;color:#eceef3;'
    . 'font-family:Tahoma,system-ui,sans-serif;padding:24px}.box{max-width:420px;text-align:center;background:#171a21;'
    . 'border:1px solid #262b36;border-radius:16px;padding:28px}.ok{color:#3ccf8e}.bad{color:#ef5b5b}'
    . 'p{line-height:2;margin:10px 0}</style></head><body><div class="box">'
    . '<h2 class="' . ($ok ? 'ok' : 'bad') . '">' . ($ok ? 'پرداخت موفق' : 'پرداخت ناموفق') . '</h2>'
    . '<p>' . h($text) . '</p><p>می‌توانید به تلگرام برگردید.</p></div></body></html>';
