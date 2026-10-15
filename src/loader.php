<?php
/** بارگذاری ترتیبی بخش‌های برنامه. */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/domain.php';
require_once __DIR__ . '/gateway.php';
require_once __DIR__ . '/bot.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/miniapi.php';

// ارتقای خودکار دیتابیس یک‌بار برای هر نسخه (برای نصب‌های قبلی)
if (is_installed() && setting_get('schema_version', '') !== '14') {
    install_schema(db());
    setting_set('schema_version', '14');
}
