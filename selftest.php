<?php
/**
 * بررسی سلامت نصب SimorghBot. فقط با کلید دریافتی از پایان صفحه‌ی نصب باز می‌شود.
 */
declare(strict_types=1);

require __DIR__ . '/src/loader.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
    . "font-src https://fonts.gstatic.com; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'");

/** @var array<int, array{group:string,title:string,ok:bool,detail:string}> $checks */
$checks = [];
$add = static function (string $group, string $title, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = ['group' => $group, 'title' => $title, 'ok' => $ok, 'detail' => $detail];
};

$add('سرور', 'نسخه‌ی PHP (حداقل 8.0)', PHP_VERSION_ID >= 80000, PHP_VERSION);
foreach (['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring'] as $ext) {
    $add('سرور', 'افزونه‌ی ' . $ext, extension_loaded($ext));
}
$add('سرور', 'فایل .htaccess', is_file(SB_ROOT . '/.htaccess'));

$installed = is_installed();
$add('نصب', 'نصب کامل شده', $installed, $installed ? 'بله' : 'خیر');

if (!$installed) {
    $add('نصب', 'بررسی پایگاه‌داده و تلگرام', false, 'ابتدا نصب را کامل کنید.');
} else {
    $key = (string) ($_GET['key'] ?? '');
    $expected = substr(hash('sha256', (string) cfg('webhook_secret')), 0, 16);
    if (!hash_equals($expected, $key)) {
        http_response_code(403);
        echo 'کلید بررسی نامعتبر است. کلید را از پایان صفحه‌ی نصب کپی کنید.';
        exit;
    }

    // جدول‌های مورد انتظار از خود نصب‌کننده خوانده می‌شوند تا همیشه با نسخه‌ی واقعی برابر باشند
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', (string) file_get_contents(SB_ROOT . '/src/schema.php'), $found);
    $wanted = array_values(array_unique($found[1]));
    try {
        $in = implode(',', array_fill(0, count($wanted), '?'));
        $st = db()->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ($in)");
        $st->execute($wanted);
        $have = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff($wanted, $have));
        $add('پایگاه‌داده', 'اتصال به پایگاه‌داده', true, 'برقرار است');
        $add('پایگاه‌داده', 'جدول‌های ربات', $missing === [],
            fa((string) count($have)) . ' از ' . fa((string) count($wanted)) . ' جدول'
            . ($missing ? ' — ناقص: ' . implode('، ', $missing) : ''));
    } catch (Throwable $e) {
        error_log('[SimorghBot selftest] ' . $e->getMessage());
        $add('پایگاه‌داده', 'اتصال به پایگاه‌داده', false, 'اتصال برقرار نشد');
    }

    $me = tg('getMe');
    $add('تلگرام', 'توکن ربات', !empty($me['ok']), !empty($me['ok']) ? '@' . (string) ($me['result']['username'] ?? '') : 'نامعتبر');

    $hook = tg('getWebhookInfo');
    $url = (string) ($hook['result']['url'] ?? '');
    $add('تلگرام', 'Webhook', $url !== '' && str_ends_with($url, '/webhook.php'), $url !== '' ? $url : 'ثبت نشده');

    $lastError = (string) ($hook['result']['last_error_message'] ?? '');
    $add('تلگرام', 'خطای آخر Webhook', $lastError === '', $lastError === '' ? 'بدون خطا' : $lastError);
}

$total = count($checks);
$passed = count(array_filter($checks, static fn(array $c): bool => $c['ok']));
$allOk = $passed === $total;
$groups = [];
foreach ($checks as $c) {
    $groups[$c['group']][] = $c;
}

$row = static function (array $c): string {
    $icon = $c['ok'] ? '<span class="ic ok">✓</span>' : '<span class="ic bad">✕</span>';
    return '<li class="row">' . $icon
        . '<div class="txt"><div class="t">' . h($c['title']) . '</div>'
        . ($c['detail'] !== '' ? '<div class="d">' . h($c['detail']) . '</div>' : '') . '</div></li>';
};

$body = '';
foreach ($groups as $name => $items) {
    $body .= '<section class="card"><h2>' . h($name) . '</h2><ul>';
    foreach ($items as $c) {
        $body .= $row($c);
    }
    $body .= '</ul></section>';
}

$verdict = $allOk
    ? '<div class="verdict ok"><b>همه‌ی بررسی‌ها موفق بود</b><span>SimorghBot آماده‌ی استفاده است.</span></div>'
    : '<div class="verdict bad"><b>' . h(fa((string) ($total - $passed))) . ' مورد نیاز به بررسی دارد</b>'
      . '<span>موارد علامت‌زده‌ی ✕ را برطرف کنید و دوباره بررسی کنید.</span></div>';

echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
    . '<meta name="theme-color" content="#0b0b0d"><meta name="robots" content="noindex,nofollow">'
    . '<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700&display=swap" rel="stylesheet">'
    . '<title>بررسی سلامت SimorghBot</title><style>'
    . ':root{--bg:#0b0b0d;--card:#131215;--line:#27241e;--text:#f1ebdd;--muted:#968d7d;--amber:#e9a53b;--ok:#8fc27a;--bad:#e26d5c}'
    . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Vazirmatn,Tahoma,sans-serif;font-size:15px;line-height:1.8;padding:0 0 40px}'
    . 'header{display:flex;align-items:center;gap:12px;padding:18px 18px 8px;max-width:560px;margin:0 auto}'
    . 'header img{width:44px;height:44px;border-radius:12px;border:1px solid var(--line)}'
    . 'header h1{font-size:17px;margin:0;font-weight:700}header p{margin:0;font-size:12px;color:var(--muted)}'
    . 'main{max-width:560px;margin:0 auto;padding:0 16px}'
    . '.verdict{border-radius:16px;padding:16px;margin:12px 0 16px;display:flex;flex-direction:column;gap:4px;border:1px solid var(--line)}'
    . '.verdict b{font-size:16px}.verdict span{font-size:13px;color:var(--muted)}'
    . '.verdict.ok{border-color:rgba(143,194,122,.35);background:rgba(143,194,122,.06)}.verdict.ok b{color:var(--ok)}'
    . '.verdict.bad{border-color:rgba(226,109,92,.35);background:rgba(226,109,92,.06)}.verdict.bad b{color:var(--bad)}'
    . '.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:6px 16px;margin-bottom:14px}'
    . '.card h2{font-size:15px;margin:12px 0 4px;font-weight:700;color:#d8d0bf}'
    . 'ul{list-style:none;margin:0;padding:0}.row{display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-top:1px solid var(--line)}'
    . '.row:first-of-type{border-top:0}'
    . '.ic{flex:0 0 26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700}'
    . '.ic.ok{background:rgba(143,194,122,.14);color:var(--ok)}.ic.bad{background:rgba(226,109,92,.14);color:var(--bad)}'
    . '.txt{min-width:0;flex:1}.t{font-size:14px;color:var(--text)}'
    . '.d{font-size:12px;color:var(--muted);margin-top:2px;direction:ltr;text-align:right;overflow-wrap:anywhere;word-break:break-word}'
    . '</style></head><body><header><img src="assets/logo.png" width="44" height="44" alt="SimorghBot">'
    . '<div><h1>بررسی سلامت SimorghBot</h1><p>وضعیت سرور، پایگاه‌داده و تلگرام</p></div></header>'
    . '<main>' . $verdict . $body . '</main></body></html>';
