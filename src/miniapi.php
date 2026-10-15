<?php
/**
 * API مینی‌اپ. همه‌ی مسیرها با امضای initData تلگرام احراز هویت می‌شوند
 * و قوانین مالی را از domain.php می‌گیرند (هیچ قانونی دوباره نوشته نشده است).
 */
declare(strict_types=1);

/** امضای initData تلگرام را بررسی و کاربر را برمی‌گرداند. */
function verify_init_data(string $initData): array
{
    if ($initData === '') {
        throw new RuntimeException('initData ارسال نشده است.');
    }
    parse_str($initData, $data);
    $hash = (string) ($data['hash'] ?? '');
    unset($data['hash']);
    if ($hash === '') {
        throw new RuntimeException('امضای نامعتبر است.');
    }
    ksort($data);
    $pairs = [];
    foreach ($data as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    $secret = hash_hmac('sha256', (string) cfg('bot_token'), 'WebAppData', true);
    $calc = hash_hmac('sha256', implode("\n", $pairs), $secret);
    if (!hash_equals($calc, $hash)) {
        throw new RuntimeException('امضای نامعتبر است.');
    }
    if (time() - (int) ($data['auth_date'] ?? 0) > 86400) {
        throw new RuntimeException('نشست مینی‌اپ منقضی شده است؛ مینی‌اپ را دوباره از ربات باز کنید.');
    }
    $user = json_decode((string) ($data['user'] ?? ''), true);
    if (!is_array($user) || !isset($user['id'])) {
        throw new RuntimeException('اطلاعات کاربر نامعتبر است.');
    }
    return $user;
}

function miniapi_dispatch(string $route, string $method): void
{
    if ($route === 'health') {
        json_out(['ok' => true, 'service' => 'SimorghBot Mini App']);
    }

    if ($route === 'auth' && $method === 'POST') {
        $body = miniapi_body();
        $loginUid = auth_verify('login', (string) ($body['t'] ?? ''));
        if ($loginUid === null || get_user($loginUid) === null) {
            json_out(['ok' => false, 'error' => 'لینک ورود منقضی شده است. از ربات دوباره وارد مینی‌اپ شوید.'], 401);
        }
        json_out(['ok' => true, 'session' => auth_sign('session', $loginUid, time() + 30 * 86400)]);
    }

    $sessionUid = auth_verify('session', (string) ($_SERVER['HTTP_X_SESSION'] ?? ''));
    if ($sessionUid !== null) {
        $known = get_user($sessionUid);
        if ($known === null) {
            json_out(['ok' => false, 'error' => 'نشست شما معتبر نیست. از ربات دوباره وارد مینی‌اپ شوید.'], 401);
        }
        $tgUser = ['id' => $sessionUid, 'username' => (string) $known['username'], 'first_name' => (string) $known['first_name']];
    } else {
        $tgUser = verify_init_data((string) ($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? ''));
    }
    $uid = (int) $tgUser['id'];
    upsert_user($uid, (string) ($tgUser['username'] ?? ''), (string) ($tgUser['first_name'] ?? ''));
    if (!is_member($uid)) {
        json_out(['ok' => false, 'error' => 'برای استفاده از مینی‌اپ ابتدا در کانال‌های اجباری عضو شوید.'], 403);
    }
    $user = get_user($uid);

    if ($route === 'me' && $method === 'GET') {
        json_out([
            'store_name' => store_name(),
            'test_enabled' => test_enabled(),
            'online_enabled' => zarinpal_ready(),
            'user_id' => $uid,
            'username' => (string) $user['username'],
            'first_name' => (string) $user['first_name'],
            'balance' => (int) $user['balance'],
            'referral_link' => referral_link($uid),
            'referral_count' => (int) scalar('SELECT COUNT(*) FROM referrals WHERE referrer_id = ?', [$uid]),
            'referral_reward_total' => (int) scalar('SELECT COALESCE(SUM(reward),0) FROM referrals WHERE referrer_id = ?', [$uid]),
        ]);
    }

    if ($route === 'avatar' && $method === 'GET') {
        miniapi_avatar($uid);
    }

    if ($route === 'transactions' && $method === 'GET') {
        json_out(['items' => miniapi_transactions($uid)]);
    }

    if ($route === 'panels' && $method === 'GET') {
        json_out(['panels' => rows('SELECT id, title, details, status, created_at FROM panels WHERE user_id = ? ORDER BY id DESC', [$uid])]);
    }

    if ($route === 'orders' && $method === 'GET') {
        json_out(['orders' => user_orders($uid, 50)]);
    }

    if ($route === 'products' && $method === 'GET') {
        $plans = array_map(static fn(array $p): array => ['months' => (int) $p['months'],
            'label' => plan_title((int) $p['months'])], plan_durations(true));
        json_out(['products' => rows('SELECT id, plan, name, price, panel_limit FROM products ORDER BY plan, id'),
            'plans' => $plans]);
    }

    if ($route === 'payment' && $method === 'GET') {
        json_out(payment_info());
    }

    if ($route === 'faqs' && $method === 'GET') {
        json_out(['faqs' => rows('SELECT id, question, answer FROM faqs WHERE active = 1 ORDER BY id')]);
    }

    if ($route === 'test' && $method === 'POST') {
        $r = create_test_request($uid);
        json_out($r['ok']
            ? ['ok' => true, 'message' => 'درخواست تست ثبت شد و برای مدیریت ارسال شد.']
            : ['ok' => false, 'error' => $r['error']], $r['ok'] ? 200 : 400);
    }

    if ($route === 'wallet/add' && $method === 'POST') {
        $amount = (int) normalize_digits((string) (miniapi_body()['amount'] ?? '0'));
        if ($amount < 1000) {
            json_out(['ok' => false, 'error' => 'حداقل مبلغ ۱۰۰۰ تومان است.'], 400);
        }
        $cid = create_charge($uid, $amount);
        json_out(['ok' => true, 'charge_id' => $cid, 'message' => 'درخواست شارژ ثبت شد.']);
    }

    if ($route === 'wallet/online' && $method === 'POST') {
        $amount = (int) normalize_digits((string) (miniapi_body()['amount'] ?? '0'));
        if ($amount < 1000) {
            json_out(['ok' => false, 'error' => 'حداقل مبلغ ۱٬۰۰۰ تومان است.'], 400);
        }
        if (!zarinpal_ready()) {
            json_out(['ok' => false, 'error' => 'پرداخت آنلاین در حال حاضر فعال نیست.'], 503);
        }
        $cid = create_charge($uid, $amount);
        $url = zarinpal_start($uid, $amount, $cid);
        if ($url === null) {
            q("UPDATE wallet_charges SET status = 'cancelled' WHERE id = ?", [$cid]);
            json_out(['ok' => false, 'error' => 'اتصال به درگاه پرداخت ناموفق بود. دوباره تلاش کنید.'], 502);
        }
        json_out(['ok' => true, 'url' => $url]);
    }

    if ($route === 'wallet/resume' && $method === 'POST') {
        $cid = (string) (miniapi_body()['charge_id'] ?? '');
        $charge = one('SELECT * FROM wallet_charges WHERE id = ? AND user_id = ?', [$cid, $uid]);
        if ($charge === null || $charge['status'] !== 'waiting') {
            json_out(['ok' => false, 'error' => 'این درخواست دیگر در انتظار پرداخت نیست.'], 400);
        }
        if (!zarinpal_ready()) {
            json_out(['ok' => false, 'error' => 'پرداخت آنلاین در حال حاضر فعال نیست.'], 503);
        }
        $url = zarinpal_start($uid, (int) $charge['amount'], $cid);
        if ($url === null) {
            json_out(['ok' => false, 'error' => 'اتصال به درگاه پرداخت ناموفق بود. دوباره تلاش کنید.'], 502);
        }
        json_out(['ok' => true, 'url' => $url]);
    }

    if ($route === 'wallet/cancel' && $method === 'POST') {
        $cid = (string) (miniapi_body()['charge_id'] ?? '');
        $st = q("UPDATE wallet_charges SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'waiting'", [$cid, $uid]);
        q("UPDATE gateway_payments SET status = 'cancelled' WHERE charge_id = ? AND status = 'pending'", [$cid]);
        if ($st->rowCount() !== 1) {
            json_out(['ok' => false, 'error' => 'این درخواست قابل لغو نیست.'], 400);
        }
        json_out(['ok' => true, 'message' => 'درخواست شارژ لغو شد.']);
    }

    if ($route === 'wallet/receipt' && $method === 'POST') {
        miniapi_receipt($uid);
    }

    if ($route === 'discount/check' && $method === 'POST') {
        $product = product_by_id((int) (miniapi_body()['product_id'] ?? 0));
        if ($product === null) {
            json_out(['ok' => false, 'error' => 'این پلن دیگر موجود نیست.'], 404);
        }
        $checked = discount_validate((string) (miniapi_body()['code'] ?? ''), $uid);
        if (isset($checked['error'])) {
            json_out(['ok' => false, 'error' => $checked['error']], 400);
        }
        $pricing = price_with_discount((int) $product['price'], $checked['discount']);
        json_out(['ok' => true, 'price' => $pricing['price'], 'discount' => $pricing['discount'],
            'percent' => $pricing['percent'], 'code' => $pricing['code']]);
    }

    if ($route === 'buy' && $method === 'POST') {
        $productId = (int) (miniapi_body()['product_id'] ?? 0);
        $code = trim((string) (miniapi_body()['discount_code'] ?? ''));
        $r = buy_with_wallet($uid, $productId, $code !== '' ? $code : null);
        json_out($r['ok']
            ? ['ok' => true, 'order_id' => $r['order_id'], 'message' => 'خرید ثبت شد و در حال آماده‌سازی پنل شماست.']
            : ['ok' => false, 'error' => $r['error']], $r['ok'] ? 200 : 400);
    }

    if ($route === 'support' && $method === 'POST') {
        $text = trim((string) (miniapi_body()['message'] ?? ''));
        if ($text === '' || mb_strlen($text) > 4000) {
            json_out(['ok' => false, 'error' => 'متن تیکت نامعتبر است.'], 400);
        }
        $tid = open_ticket($uid, $text);
        json_out(['ok' => true, 'ticket_id' => $tid, 'message' => 'تیکت شما با موفقیت ثبت شد.']);
    }

    json_out(['ok' => false, 'error' => 'not_found'], 404);
}

/** بدنه‌ی JSON درخواست. */
function miniapi_body(): array
{
    static $body = null;
    if ($body === null) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($decoded) ? $decoded : [];
    }
    return $body;
}

/** آپلود رسید شارژ از مینی‌اپ. */
function miniapi_receipt(int $uid): void
{
    $cid = (string) ($_POST['charge_id'] ?? '');
    $file = $_FILES['receipt'] ?? null;
    if ($cid === '' || !is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'error' => 'رسید ارسال نشده است.'], 400);
    }
    if ((int) $file['size'] > MAX_UPLOAD_BYTES) {
        json_out(['ok' => false, 'error' => 'حجم رسید بیشتر از ۲ مگابایت است.'], 400);
    }
    $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 12);
    $isImage = str_starts_with($head, "\xFF\xD8\xFF") || str_starts_with($head, "\x89PNG")
        || (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP');
    if (!$isImage) {
        json_out(['ok' => false, 'error' => 'فقط تصویر (JPG / PNG / WEBP) قابل قبول است.'], 400);
    }
    // تلگرام فایل را از سرور ما نمی‌خواند؛ عکس را مستقیم برای مدیر می‌فرستیم.
    $upload = tg('sendPhoto', ['chat_id' => (int) cfg('admin_id'),
        'photo' => new CURLFile($file['tmp_name'], 'image/jpeg', 'receipt.jpg'),
        'caption' => 'رسید شارژ از مینی‌اپ — کاربر ' . $uid . ' — شارژ ' . $cid]);
    if (empty($upload['ok'])) {
        json_out(['ok' => false, 'error' => 'ارسال رسید به مدیریت ناموفق بود.'], 500);
    }
    $fileId = (string) end($upload['result']['photo'])['file_id'];
    // پیام موقت را پاک می‌کنیم تا مدیر فقط یک بار (با دکمه‌ها) رسید را ببیند.
    tg('deleteMessage', ['chat_id' => (int) cfg('admin_id'), 'message_id' => (int) $upload['result']['message_id']]);
    if (!attach_charge_receipt($cid, $uid, $fileId)) {
        json_out(['ok' => false, 'error' => 'درخواست شارژ معتبر نیست.'], 400);
    }
    json_out(['ok' => true, 'message' => 'رسید ارسال شد و برای مدیریت فرستاده شد.']);
}


/** تاریخچه‌ی کیف پول: شارژها و خریدهایی که با کیف پول انجام شده است. */
function miniapi_transactions(int $uid): array
{
    $items = [];
    foreach (rows('SELECT id, amount, status, created_at FROM wallet_charges WHERE user_id = ?
                   ORDER BY created_at DESC LIMIT 30', [$uid]) as $c) {
        $items[] = ['type' => 'charge', 'id' => (string) $c['id'], 'title' => 'افزایش موجودی',
            'amount' => (int) $c['amount'], 'status' => $c['status'], 'date' => $c['created_at']];
    }
    foreach (rows("SELECT o.amount, o.status, o.created_at, p.name AS product_name FROM orders o
                   LEFT JOIN products p ON p.id = o.product_id
                   WHERE o.user_id = ? AND o.payment_method = 'wallet'
                   ORDER BY o.created_at DESC LIMIT 30", [$uid]) as $o) {
        $items[] = ['type' => 'purchase', 'title' => (string) ($o['product_name'] ?? 'خرید پنل'),
            'amount' => -(int) $o['amount'], 'status' => $o['status'], 'date' => $o['created_at']];
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));
    return array_slice($items, 0, 40);
}

/** عکس پروفایل کاربر از تلگرام؛ یک‌بار در روز کش می‌شود. */
function miniapi_avatar(int $uid): void
{
    $dir = SB_ROOT . '/storage/avatars';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . $uid . '.jpg';
    if (!is_file($file) || filemtime($file) < time() - 86400) {
        $photos = tg('getUserProfilePhotos', ['user_id' => $uid, 'limit' => 1]);
        $sizes = $photos['result']['photos'][0] ?? null;
        if (is_array($sizes) && $sizes !== []) {
            $big = end($sizes);
            $info = tg('getFile', ['file_id' => (string) $big['file_id']]);
            $path = (string) ($info['result']['file_path'] ?? '');
            if ($path !== '') {
                $bytes = tg_download('https://api.telegram.org/file/bot' . cfg('bot_token') . '/' . $path);
                if ($bytes !== null) {
                    file_put_contents($file, $bytes);
                }
            }
        }
    }
    if (is_file($file)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=3600');
        readfile($file);
        exit;
    }
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false}';
    exit;
}
