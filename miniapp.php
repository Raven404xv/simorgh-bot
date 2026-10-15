<?php
/** صفحه‌ی مینی‌اپ فارسی. نام کاربری ربات از تنظیمات جایگزین می‌شود. */
declare(strict_types=1);

require __DIR__ . '/src/loader.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header("Content-Security-Policy: frame-ancestors 'self' https://web.telegram.org https://telegram.org https://*.telegram.org");

$html = (string) file_get_contents(__DIR__ . '/miniapp.html');
echo str_replace(['__BOT_USERNAME__', '__STORE_NAME__'],
    [(string) (cfg('bot_username') ?: bot_username()), h(store_name())], $html);
