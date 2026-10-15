<?php
/**
 * درگاه پرداخت زرین‌پال (نسخه‌ی v4 API). مبلغ‌ها در داخل برنامه به تومان و در API به ریال هستند.
 */
declare(strict_types=1);

const ZARIN_REQUEST_URL = 'https://api.zarinpal.com/pg/v4/payment/request.json';
const ZARIN_VERIFY_URL = 'https://api.zarinpal.com/pg/v4/payment/verify.json';
const ZARIN_START_URL = 'https://www.zarinpal.com/pg/StartPay/';

/** آیا درگاه فعال و کامل تنظیم شده است؟ */
function zarinpal_ready(): bool
{
    return setting_get('zarin_enabled', '0') === '1'
        && setting_get('zarin_merchant', '') !== ''
        && (string) cfg('webapp_url', '') !== '';
}

function zarinpal_post(string $url, array $payload): array
{
    if (!function_exists('curl_init')) {
        return [];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

/** درخواست پرداخت می‌سازد و آدرس صفحه‌ی پرداخت را برمی‌گرداند؛ در صورت خطا null. */
function zarinpal_start(int $uid, int $toman, string $chargeId): ?string
{
    if (!zarinpal_ready() || $toman < 1000) {
        return null;
    }
    $callback = rtrim((string) cfg('webapp_url'), '/') . '/zarinpal.php';
    $r = zarinpal_post(ZARIN_REQUEST_URL, [
        'merchant_id' => setting_get('zarin_merchant'),
        'amount' => $toman * 10,
        'callback_url' => $callback,
        'description' => 'افزایش موجودی کیف پول',
    ]);
    $authority = (string) ($r['data']['authority'] ?? '');
    if ((int) ($r['data']['code'] ?? 0) !== 100 || $authority === '') {
        return null;
    }
    q('INSERT INTO gateway_payments (authority, user_id, charge_id, amount, status, created_at)
       VALUES (?, ?, ?, ?, ?, ?)', [$authority, $uid, $chargeId, $toman, 'pending', now()]);
    return ZARIN_START_URL . $authority;
}

/** تأیید پرداخت نزد زرین‌پال. شناسه‌ی مرجع یا null. */
function zarinpal_verify(string $authority, int $toman): ?string
{
    $r = zarinpal_post(ZARIN_VERIFY_URL, [
        'merchant_id' => setting_get('zarin_merchant'),
        'amount' => $toman * 10,
        'authority' => $authority,
    ]);
    $code = (int) ($r['data']['code'] ?? 0);
    if ($code === 100 || $code === 101) {
        return (string) ($r['data']['ref_id'] ?? '0');
    }
    return null;
}
