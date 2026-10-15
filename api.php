<?php
/** مسیرهای API مینی‌اپ. .htaccess درخواست‌های /api/... را به اینجا می‌فرستد. */
declare(strict_types=1);

require __DIR__ . '/src/loader.php';

if (!is_installed()) {
    json_out(['ok' => false, 'error' => 'not_installed'], 503);
}

try {
    miniapi_dispatch(trim((string) ($_GET['r'] ?? ''), '/'), $_SERVER['REQUEST_METHOD'] ?? 'GET');
} catch (RuntimeException $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 401);
} catch (Throwable $e) {
    error_log('[SimorghBot API] ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    report_error('مینی‌اپ', $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 0,
        'مسیر: ' . trim((string) ($_GET['r'] ?? ''), '/'));
    json_out(['ok' => false, 'error' => 'خطای سرور. دوباره تلاش کنید.'], 500);
}
