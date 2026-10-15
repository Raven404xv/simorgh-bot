<?php
/**
 * نصب‌کننده‌ی SimorghBot — فقط یک بار اجرا می‌شود.
 * ترتیب: بررسی توکن و مدیر، اتصال به پایگاه‌داده و ساخت جدول‌ها، ثبت Webhook، سپس نوشتن config.php و قفل نصب.
 */
declare(strict_types=1);

require __DIR__ . '/src/loader.php';

/** خروجی صفحه‌ی نصب با سخت‌ترین سیاست‌های امنیتی مرورگر. */
function install_page(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        . '<meta name="theme-color" content="#0b0b0d"><meta name="robots" content="noindex,nofollow">'
        . '<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700&display=swap" rel="stylesheet">'
        . '<title>' . h($title) . '</title><style>'
        . ':root{--bg:#0b0b0d;--card:#131215;--line:#27241e;--text:#f1ebdd;--muted:#968d7d;--amber:#e9a53b;--amber2:#f7cc73;--ok:#8fc27a;--bad:#e26d5c}'
        . '*{box-sizing:border-box}html,body{margin:0;min-height:100%}'
        . 'body{background:var(--bg);color:var(--text);font-family:Vazirmatn,Tahoma,sans-serif;font-size:15px;line-height:1.9;padding:0 0 110px}'
        . 'header{display:flex;align-items:center;gap:12px;padding:18px 18px 10px;max-width:520px;margin:0 auto}'
        . 'header img{width:44px;height:44px;border-radius:12px;border:1px solid var(--line)}'
        . 'header h1{font-size:17px;margin:0;font-weight:700}header p{margin:0;font-size:12px;color:var(--muted)}'
        . '.ver{margin-inline-start:auto;font-size:11px;color:var(--muted);border:1px solid var(--line);padding:3px 9px;border-radius:999px;direction:ltr}'
        . 'main{max-width:520px;margin:0 auto;padding:0 16px}'
        . '.steps{display:flex;gap:8px;overflow-x:auto;padding:6px 0 16px;scrollbar-width:none}.steps::-webkit-scrollbar{display:none}'
        . '.st{flex:0 0 auto;display:flex;align-items:center;gap:7px;padding:7px 12px;border-radius:999px;border:1px solid var(--line);font-size:13px;color:var(--muted);white-space:nowrap}'
        . '.st i{width:20px;height:20px;border-radius:50%;border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;font-style:normal;font-size:11px}'
        . '.st.on{background:var(--amber);border-color:var(--amber);color:#17140d;font-weight:700}.st.on i{background:#17140d;color:var(--amber);border-color:#17140d}'
        . '.st.done{color:var(--ok);border-color:rgba(143,194,122,.4)}.st.done i{color:var(--ok);border-color:var(--ok)}'
        . '.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px 16px;margin-bottom:14px}'
        . '.card h2{font-size:17px;margin:0 0 4px;font-weight:700}.card .lead{margin:0 0 14px;color:var(--muted);font-size:13px}'
        . '.f{margin-bottom:14px}.f label{display:flex;justify-content:space-between;align-items:baseline;font-size:14px;color:#d8d0bf;margin-bottom:6px;gap:8px}'
        . '.f label em{font-style:normal;font-family:monospace;font-size:11px;color:var(--muted);direction:ltr}'
        . '.f input{width:100%;height:50px;padding:0 14px;border-radius:12px;border:1px solid var(--line);background:#0b0b0d;color:var(--text);font:inherit;font-size:15px}'
        . '.f input.ltr{direction:ltr;text-align:left;font-family:monospace}'
        . '.f input::placeholder{color:#55503f;font-size:14px}.f input:focus{outline:none;border-color:var(--amber);box-shadow:0 0 0 4px rgba(233,165,59,.16)}'
        . '.hint{font-size:12px;color:var(--muted);margin-top:6px}'
        . '.tip{background:rgba(233,165,59,.07);border:1px solid rgba(233,165,59,.25);border-radius:14px;padding:12px 14px;font-size:13px;color:#e4dccb;margin-top:6px}'
        . '.err{background:#2a1212;border:1px solid #6b2a24;color:#f6d3cd;border-radius:14px;padding:12px 14px;margin-bottom:14px;font-size:14px}'
        . '.ok{color:#d7ecc9;font-size:14px}.ok ul{padding:0 0 0 18px;margin:10px 0 0}.ok li{margin-bottom:8px}'
        . 'a{color:var(--amber2);direction:ltr;unicode-bidi:isolate;overflow-wrap:anywhere}'
        . '.bar{position:fixed;inset-inline:0;bottom:0;padding:12px 16px calc(12px + env(safe-area-inset-bottom));background:rgba(11,11,13,.94);border-top:1px solid var(--line)}'
        . '.bar button{display:block;width:100%;max-width:520px;margin:0 auto;height:52px;border:0;border-radius:14px;font:inherit;font-weight:700;font-size:16px;color:#17140d;background:linear-gradient(180deg,var(--amber2),var(--amber));cursor:pointer}'
        . '</style></head><body>'
        . '<header><img src="assets/logo.png" width="44" height="44" alt="SimorghBot"><div><h1>نصب SimorghBot</h1><p>راه‌اندازی ربات روی هاست</p></div><span class="ver">v3.3.0</span></header>'
        . $body . '</body></html>';
    exit;
}

if (is_installed()) {
    install_page('نصب انجام شده', '<main><div class="card"><h2>SimorghBot قبلاً نصب شده است</h2>'
        . '<p class="lead">برای تغییر تنظیمات، فایل config.php را ویرایش کنید. این صفحه دیگر کار نمی‌کند.</p></div></main>');
}

$errors = [];
$values = ['token' => '', 'admin' => '', 'db_host' => 'localhost', 'db_name' => '', 'db_user' => ''];
$done = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $values['token'] = trim((string) ($_POST['token'] ?? ''));
    $values['admin'] = normalize_digits(trim((string) ($_POST['admin'] ?? '')));
    $values['db_host'] = trim((string) ($_POST['db_host'] ?? 'localhost')) ?: 'localhost';
    $values['db_name'] = trim((string) ($_POST['db_name'] ?? ''));
    $values['db_user'] = trim((string) ($_POST['db_user'] ?? ''));
    $dbPass = (string) ($_POST['db_pass'] ?? '');

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

    if (!preg_match('/^\d{5,12}:[A-Za-z0-9_-]{30,}$/', $values['token'])) {
        $errors[] = 'فرمت توکن درست نیست. توکن را از @BotFather کپی کنید.';
    }
    if (!preg_match('/^\d{5,15}$/', $values['admin'])) {
        $errors[] = 'شناسه‌ی عددی مدیر را وارد کنید (فقط عدد).';
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $values['db_name']) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $values['db_user'])) {
        $errors[] = 'نام پایگاه‌داده و کاربر فقط می‌تواند حروف انگلیسی، عدد و خط زیر داشته باشد.';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $values['db_host'])) {
        $errors[] = 'میزبان پایگاه‌داده نامعتبر است.';
    }
    if (!$https) {
        $errors[] = 'این صفحه باید با آدرس https باز شود. تلگرام فقط آدرس امن را می‌پذیرد.';
    }

    $botUsername = '';
    if (!$errors) {
        $me = tg_raw($values['token'], 'getMe');
        if (empty($me['ok'])) {
            $errors[] = 'توکن توسط تلگرام پذیرفته نشد. توکن را دوباره بررسی کنید.';
        } else {
            $botUsername = (string) $me['result']['username'];
        }
    }

    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO('mysql:host=' . $values['db_host'] . ';dbname=' . $values['db_name'] . ';charset=utf8mb4',
                $values['db_user'], $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            install_schema($pdo);
        } catch (Throwable $e) {
            error_log('[SimorghBot install] ' . $e->getMessage());
            $errors[] = 'اتصال به پایگاه‌داده یا ساخت جدول‌ها ناموفق بود. نام پایگاه‌داده، کاربر و رمز را بررسی کنید.';
        }
    }

    if (!$errors && $pdo !== null) {
        $secret = bin2hex(random_bytes(24));
        // ابتدا Webhook ثبت می‌شود؛ اگر ناموفق باشد، هیچ فایل تنظیمات یا قفل نصبی ساخته نمی‌شود.
        $hook = tg_raw($values['token'], 'setWebhook', [
            'url' => $base . '/webhook.php',
            'secret_token' => $secret,
            'allowed_updates' => json_encode(['message', 'callback_query']),
            'drop_pending_updates' => 'true',
        ]);
        if (empty($hook['ok'])) {
            error_log('[SimorghBot install] setWebhook: ' . (string) ($hook['description'] ?? ''));
            $errors[] = 'ثبت آدرس Webhook در تلگرام ناموفق بود. آدرس https و دسترسی هاست را بررسی کنید.';
        } else {
            $config = [
                'bot_token' => $values['token'],
                'bot_username' => $botUsername,
                'admin_id' => (int) $values['admin'],
                'db_host' => $values['db_host'],
                'db_name' => $values['db_name'],
                'db_user' => $values['db_user'],
                'db_pass' => $dbPass,
                'webhook_secret' => $secret,
                'store_name' => '',
                'webapp_url' => $base,
            ];
            $file = SB_ROOT . '/config.php';
            $tmp = $file . '.tmp';
            file_put_contents($tmp, "<?php\n// تنظیمات SimorghBot - این فایل را هیچ‌گاه منتشر نکنید\nreturn " . var_export($config, true) . ";\n");
            @chmod($tmp, 0600);
            rename($tmp, $file);

            $seed = $pdo->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)');
            foreach ([['bot_username', $botUsername], ['test_limit', '1'],
                      ['payment_card', 'ثبت نشده'], ['payment_owner', 'ثبت نشده']] as [$k, $v]) {
                $seed->execute([$k, $v]);
            }
            file_put_contents(SB_ROOT . '/installed.lock', 'installed ' . date('c') . "\n");
            @chmod(SB_ROOT . '/installed.lock', 0600);
            $done = ['username' => $botUsername, 'base' => $base,
                'key' => substr(hash('sha256', $secret), 0, 16)];
        }
    }
}

if ($done !== null) {
    install_page('نصب انجام شد', '<main><div class="card ok"><h2>نصب با موفقیت انجام شد</h2>'
        . '<p class="lead">ربات <a href="https://t.me/' . h($done['username']) . '">@' . h($done['username']) . '</a> آماده است.</p>'
        . '<ul><li>در تلگرام به ربات بروید و <b>/start</b> را بفرستید.</li>'
        . '<li>با حساب مدیر، شماره کارت و پلن‌ها را از پنل مدیریت ثبت کنید.</li>'
        . '<li>مینی‌اپ: <a href="' . h($done['base']) . '/miniapp.php">' . h($done['base']) . '/miniapp.php</a></li>'
        . '<li>بررسی سلامت: <a href="' . h($done['base']) . '/selftest.php?key=' . h($done['key']) . '">selftest.php</a></li>'
        . '<li><b>پس از اطمینان، فایل install.php را از هاست حذف کنید.</b></li></ul></div></main>');
}

$errHtml = '';
if ($errors) {
    $errHtml = '<div class="err">' . implode('<br>', array_map('h', $errors)) . '</div>';
}

/** یک فیلد فرم؛ رمز هیچ‌وقت دوباره در صفحه نوشته نمی‌شود. */
$field = static function (string $name, string $label, string $hint, string $type = 'text', string $cls = '') use (&$values): string {
    $val = $type === 'password' ? '' : h($values[$name] ?? '');
    return '<div class="f"><label><span>' . h($label) . '</span><em>' . h($hint) . '</em></label>'
        . '<input class="' . $cls . '" type="' . $type . '" name="' . $name . '" value="' . $val . '"'
        . ' autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="256">'
        . '</div>';
};

install_page('نصب SimorghBot', '<main>'
    . '<nav class="steps" aria-label="مراحل نصب">'
    . '<div class="st on"><i>۱</i>ربات تلگرام</div>'
    . '<div class="st"><i>۲</i>مدیر اصلی</div>'
    . '<div class="st"><i>۳</i>پایگاه‌داده</div>'
    . '<div class="st"><i>۴</i>پایان</div></nav>'
    . $errHtml
    . '<form id="install" method="post" novalidate>'
    . '<section class="card"><h2>ربات تلگرام</h2><p class="lead">توکن را از @BotFather و شناسه‌ی عددی مدیر را از @userinfobot بگیرید.</p>'
    . $field('token', 'توکن ربات', 'مثال: 123456789:AAHxxxxxxxxxxxx', 'text', 'ltr')
    . $field('admin', 'شناسه‌ی عددی مدیر', 'مثال: 6125199433', 'text', 'ltr')
    . '</section>'
    . '<section class="card"><h2>پایگاه‌داده</h2><p class="lead">پیش از نصب، در cPanel یک MySQL Database و کاربر آن را بسازید و دسترسی کامل بدهید.</p>'
    . $field('db_host', 'میزبان پایگاه‌داده', 'مثال: localhost', 'text', 'ltr')
    . $field('db_name', 'نام پایگاه‌داده', 'مثال: cpuser_simorgh', 'text', 'ltr')
    . $field('db_user', 'نام کاربری پایگاه‌داده', 'مثال: cpuser_simorgh', 'text', 'ltr')
    . $field('db_pass', 'رمز پایگاه‌داده', 'رمز کاربر پایگاه‌داده', 'password', 'ltr')
    . '<div class="tip">نام کامل پایگاه‌داده معمولاً به شکل <b>نام‌کاربری_نام‌دلخواه</b> است.</div>'
    . '</section>'
    . '</form></main>'
    . '<div class="bar"><button type="submit" form="install">بررسی و نصب</button></div>');
