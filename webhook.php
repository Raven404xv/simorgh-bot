<?php
/**
 * دریافت به‌روزرسانی‌های تلگرام (Webhook). آدرس این فایل هنگام نصب در تلگرام ثبت می‌شود.
 */
declare(strict_types=1);

require __DIR__ . '/src/loader.php';

if (!is_installed()) {
    http_response_code(503);
    exit;
}

// فقط درخواست‌هایی که با secret_token ثبت‌شده‌ی ما آمده‌اند پذیرفته می‌شوند.
$expected = (string) cfg('webhook_secret', '');
$given = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit;
}

$update = json_decode((string) file_get_contents('php://input'), true);
if (is_array($update)) {
    handle_update($update);
}
http_response_code(200);
echo 'ok';
