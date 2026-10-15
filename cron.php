<?php
/**
 * اجرای صف ارسال همگانی. هر دقیقه از cPanel فراخوانی می‌شود.
 */
declare(strict_types=1);

require __DIR__ . '/src/loader.php';

header('Content-Type: text/plain; charset=utf-8');
$key = (string) ($_GET['key'] ?? '');
$real = (string) setting_get('cron_key', '');
if ($real === '' || !hash_equals($real, $key)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

// یک نمونه در هر لحظه؛ اجرای همزمان دو کار صف را دو بار ارسال نمی‌کند
$lock = fopen(SB_ROOT . '/storage/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo 'busy';
    exit;
}

@set_time_limit(90);
$start = time();
$batches = 0;
while (time() - $start < 50 && broadcast_tick(30)) {
    $batches++;
}
try {
    backup_maybe_run();
} catch (Throwable $e) {
    report_error('backup', $e->getMessage());
}
setting_set('cron_last', now());
echo 'ok ' . $batches;

flock($lock, LOCK_UN);
fclose($lock);
