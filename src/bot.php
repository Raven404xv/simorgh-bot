<?php
/**
 * بخش کاربر: منوها، خرید، کیف پول، تست رایگان، پنل‌ها، حساب، زیرمجموعه‌گیری و پشتیبانی.
 * هر صفحه با ارسال پیام جدید جایگزین صفحه‌ی قبلی می‌شود.
 */
declare(strict_types=1);

/** نقطه‌ی ورود همه‌ی به‌روزرسانی‌های تلگرام. */
function handle_update(array $update): void
{
    // ربات فقط در گفتگوی خصوصی با کاربران پاسخ می‌دهد؛ پیام‌های گروه‌ها (از جمله گروه گزارش‌ها) نادیده گرفته می‌شوند
    $chatType = (string) ($update['message']['chat']['type'] ?? ($update['callback_query']['message']['chat']['type'] ?? 'private'));
    if ($chatType !== 'private') {
        return;
    }
    try {
        if (isset($update['callback_query'])) {
            handle_callback($update['callback_query']);
        } elseif (isset($update['message'])) {
            handle_message($update['message']);
        }
    } catch (Throwable $e) {
        error_log('[SimorghBot] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $who = (int) ($update['message']['from']['id'] ?? ($update['callback_query']['from']['id'] ?? 0));
        $what = (string) ($update['message']['text'] ?? ($update['callback_query']['data'] ?? ''));
        report_error('bot', $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', $who, $what);
    }
}

function force_join_screen(int $chat, ?int $old): void
{
    $rows = [];
    foreach (rows('SELECT channel FROM force_join ORDER BY id') as $r) {
        $name = ltrim(str_replace('https://t.me/', '', (string) $r['channel']), '@/');
        $rows[] = [btn_url('عضویت در کانال @' . $name, 'https://t.me/' . $name, 'primary')];
    }
    $rows[] = [btn('عضو شدم', 'check_join', 'success')];
    screen($chat, $old, ' <b>برای ادامه‌ی استفاده از ربات، لطفاً ابتدا در کانال‌های زیر عضو شوید.</b>' . "\n" .
        'پس از عضویت، دکمه‌ی «عضو شدم» را بزنید.', kb($rows));
}

/** منوی اصلی بدون پیام خوش‌آمد (برای دکمه‌ی بازگشت). */
function show_menu(int $chat, int $uid, ?int $old = null): void
{
    if (menu_style() === 'keyboard') {
        send_text($chat, '<b>منوی اصلی</b>' . "\n" . 'یکی از گزینه‌های زیر را انتخاب کنید.', reply_menu_markup($uid));
        setting_set('keyboard_active', '1');
        if ($old !== null && $old > 0) {
            delete_message($chat, $old);
        }
        return;
    }
    if (setting_get('keyboard_active', '0') === '1') {
        hide_reply_keyboard($chat);
        setting_set('keyboard_active', '0');
    }
    screen($chat, $old, '<b>منوی اصلی</b>' . "\n" . 'یکی از گزینه‌های زیر را انتخاب کنید.', main_menu_markup($uid));
}

/** صفحه‌ی تأیید خرید: قیمت، کد تخفیف (اگر باشد) و روش پرداخت. */
function show_product_confirm(int $chat, int $uid, int $productId, ?int $old = null): void
{
    $p = product_by_id($productId);
    if ($p === null) {
        screen($chat, $old, 'این پلن در حال حاضر موجود نیست.', kb([back_row('buy_panel')]));
        return;
    }
    $user = get_user($uid);
    $code = current_discount($uid);
    $discount = null;
    $note = '';
    if ($code !== null) {
        $checked = discount_validate($code, $uid);
        if (isset($checked['error'])) {
            state_set($uid, 'discount', '');
            $note = $checked['error'];
        } else {
            $discount = $checked['discount'];
        }
    }
    $pricing = price_with_discount((int) $p['price'], $discount);
    $lines = ['<b>تأیید خرید</b>', RULE,
        'پلن: <b>' . h((string) $p['name']) . '</b>',
        'قیمت: ' . money($p['price']) . ' تومان'];
    if ($discount !== null) {
        $lines[] = 'کد تخفیف <code>' . h($discount['code']) . '</code> (' . fa((string) $discount['percent']) . '٪): '
            . money($pricing['discount']) . ' تومان';
    }
    if ($note !== '') {
        $lines[] = '<i>' . h($note) . '</i>';
    }
    $lines[] = '<b>مبلغ قابل پرداخت: ' . money($pricing['price']) . ' تومان</b>';
    $lines[] = 'موجودی کیف پول: ' . money($user['balance']) . ' تومان';
    $rows = [
        [btn('پرداخت از کیف پول', 'wallet_buy_' . $productId, color_of('buy'))],
        [btn('پرداخت کارت به کارت', 'card_buy_' . $productId, color_of('buy'))],
        $discount === null
            ? [btn('ثبت کد تخفیف', 'disc_enter_' . $productId, '')]
            : [btn('حذف کد تخفیف', 'disc_clear_' . $productId, '')],
        back_row('plan_' . (int) $p['plan']),
    ];
    screen($chat, $old, implode("\n", $lines), kb($rows));
}

function show_home(int $chat, int $uid, string $name, ?int $old = null): void
{
    if (menu_style() === 'keyboard') {
        send_text($chat, home_text($name), reply_menu_markup($uid));
        setting_set('keyboard_active', '1');
        if ($old !== null && $old > 0) {
            delete_message($chat, $old);
        }
        return;
    }
    if (setting_get('keyboard_active', '0') === '1') {
        hide_reply_keyboard($chat);
        setting_set('keyboard_active', '0');
    }
    screen($chat, $old, home_text($name), main_menu_markup($uid));
}

function handle_message(array $m): void
{
    $uid = (int) $m['from']['id'];
    $chat = (int) $m['chat']['id'];
    $text = trim((string) ($m['text'] ?? ''));
    $name = (string) ($m['from']['first_name'] ?? '');
    $isNew = get_user($uid) === null;
    upsert_user($uid, (string) ($m['from']['username'] ?? ''), $name);
    if ($isNew && preg_match('/^\/start/', $text) === 1) {
        new_user_card($uid);
    }
    if (!is_admin($uid) && is_blocked($uid)) {
        send_text($chat, 'حساب شما مسدود شده است. برای پیگیری با پشتیبانی تماس بگیرید.');
        return;
    }

    if (preg_match('/^\/start\s+u_(\d+)$/', $text, $mu) === 1 && is_admin($uid)) {
        dispatch_callback('user_' . (int) $mu[1], $uid, '', $chat, 0);
        return;
    }
    if (preg_match('/^\/start(?:\s+ref_(\d+))?$/', $text, $mm)) {
        clear_states($uid);
        if ($isNew && isset($mm[1])) {
            apply_referral($uid, (int) $mm[1]);
        }
        if (!is_member($uid)) {
            force_join_screen($chat, null);
            return;
        }
        show_home($chat, $uid, $name);
        return;
    }

    if ($text === '/cancel') {
        clear_states($uid);
        show_home($chat, $uid, $name);
        return;
    }

    if (is_admin($uid) && admin_message_flow($m, $uid, $chat, $text)) {
        return;
    }
    if (user_message_flow($m, $uid, $chat, $text)) {
        return;
    }
    if (!is_member($uid)) {
        force_join_screen($chat, null);
        return;
    }
    // دکمه‌ی کیبورد پایین صفحه: متن پیام با برچسب دکمه برابر است
    $cb = menu_label_callback($uid, $text);
    if ($cb !== null) {
        if ($cb !== '') {
            dispatch_callback($cb, $uid, $name, $chat, 0);
        }
        return;
    }
    show_menu($chat, $uid, null);
}

/** پیام‌هایی که در مراحل مختلف (مبلغ، رسید، تیکت) فرستاده می‌شوند. */
function user_message_flow(array $m, int $uid, int $chat, string $text): bool
{
    $pendingProduct = state_get($uid, 'discount_for');
    if ($pendingProduct !== '') {
        $code = strtoupper(trim($text));
        $checked = discount_validate($code, $uid);
        if (isset($checked['error'])) {
            send_text($chat, h($checked['error']) . "\nکد دیگری وارد کنید یا لغو کنید.",
                kb([[btn('انصراف', 'buy_' . (int) $pendingProduct, 'danger')]]));
            return true;
        }
        state_set($uid, 'discount_for', '');
        state_set($uid, 'discount', $code);
        show_product_confirm($chat, $uid, (int) $pendingProduct, null);
        return true;
    }

    // پاسخ کاربر به پیامی که مدیر با اجازه‌ی پاسخ فرستاده است
    $replyTo = state_get($uid, 'ureply');
    if ($replyTo !== '') {
        state_set($uid, 'ureply', '');
        $aid = (int) $replyTo;
        copy_message($aid, $chat, (int) ($m['message_id'] ?? 0));
        send_text($aid, 'پیام از کاربر <code>' . $uid . '</code>', kb([[btn('پاسخ به کاربر', 'user_msg_' . $uid, '')]]));
        send_text($chat, 'پیام شما برای مدیریت ارسال شد.', back_markup());
        return true;
    }

    $hasPhoto = isset($m['photo']) && is_array($m['photo']) && $m['photo'] !== [];
    $photoId = $hasPhoto ? (string) end($m['photo'])['file_id'] : '';

    if (state_get($uid, 'wallet_amount') === 'waiting') {
        $amount = (int) normalize_digits(str_replace([',', '٬'], '', $text));
        if ($amount < 1000) {
            send_text($chat, 'مبلغ وارد شده معتبر نیست. حداقل مبلغ شارژ ۱٬۰۰۰ تومان است.',
                cancel_markup());
            return true;
        }
        state_set($uid, 'wallet_amount', '');
        $cid = create_charge($uid, $amount);
        state_set($uid, 'wallet_receipt', $cid);
        $payUrl = zarinpal_start($uid, $amount, $cid);
        $p = payment_info();
        $payRows = [];
        if ($payUrl !== null) {
            $payRows[] = [btn_url('پرداخت آنلاین', $payUrl, '')];
        }
        send_text($chat, '<b>افزایش موجودی</b>' . "\n\n" .
            'مبلغ: <b>' . money($amount) . ' تومان</b>' . "\n\n" .
            'شماره کارت:' . "\n<code>" . h($p['card']) . '</code>' . "\n\n" .
            'صاحب کارت: <b>' . h($p['owner']) . '</b>' . "\n\n" .
            'پس از پرداخت، تصویر رسید را در همین گفتگو ارسال کنید.',
            kb(array_merge($payRows, [[btn('لغو درخواست', 'wallet_cancel_' . $cid, '')], back_row('wallet')])));
        return true;
    }

    $cid = state_get($uid, 'wallet_receipt');
    if ($cid !== '') {
        if (!$hasPhoto) {
            send_text($chat, 'لطفاً تصویر رسید را ارسال کنید.', cancel_markup('wallet_cancel_' . $cid));
            return true;
        }
        state_set($uid, 'wallet_receipt', '');
        if (attach_charge_receipt($cid, $uid, $photoId)) {
            send_text($chat, '<b>رسید شما دریافت شد</b>' . "\n" .
                'پس از بررسی مدیریت، موجودی شما شارژ می‌شود.', back_markup());
        } else {
            send_text($chat, 'این درخواست دیگر معتبر نیست.', back_markup('wallet'));
        }
        return true;
    }

    $orderId = state_get($uid, 'receipt_order');
    if ($orderId !== '') {
        if (!$hasPhoto) {
            send_text($chat, 'لطفاً تصویر رسید پرداخت را ارسال کنید.', cancel_markup('cancel_order_' . $orderId));
            return true;
        }
        state_set($uid, 'receipt_order', '');
        if (attach_order_receipt($orderId, $uid, $photoId)) {
            send_text($chat, '<b>رسید شما دریافت شد</b>' . "\n" .
                'پس از تأیید مدیریت، اطلاعات سرویس برای شما ارسال می‌شود.', back_markup('my_orders'));
        } else {
            send_text($chat, 'این سفارش دیگر در انتظار رسید نیست.', back_markup('my_orders'));
        }
        return true;
    }

    if (state_get($uid, 'support') === '1') {
        state_set($uid, 'support', '');
        $content = $text !== '' ? $text : ($hasPhoto ? '(تصویر)' : '(پیام)');
        $tid = open_ticket($uid, $content, $hasPhoto ? $photoId : null);
        send_text($chat, '<b>تیکت #' . $tid . 'ثبت شد.</b>' . "\n" .
            'پاسخ مدیریت در همین ربات برای شما ارسال می‌شود.', back_markup('support_center'));
        return true;
    }

    return false;
}

function handle_callback(array $cq): void
{
    $uid = (int) $cq['from']['id'];
    $cbId = (string) $cq['id'];
    $data = (string) ($cq['data'] ?? '');
    $chat = (int) ($cq['message']['chat']['id'] ?? $uid);
    $old = (int) ($cq['message']['message_id'] ?? 0);
    $name = (string) ($cq['from']['first_name'] ?? '');
    upsert_user($uid, (string) ($cq['from']['username'] ?? ''), $name);
    answer_cb($cbId);
    if (!is_admin($uid) && is_blocked($uid)) {
        screen($chat, $old > 0 ? $old : null, 'حساب شما مسدود شده است.', null);
        return;
    }
    dispatch_callback($data, $uid, $name, $chat, $old);
}

/** مسیر واحد برای دکمه‌های زیر پیام و کیبورد پایین صفحه. */
function dispatch_callback(string $data, int $uid, string $name, int $chat, int $old): void
{
    if (admin_is_action($data)) {
        if (!is_admin($uid)) {
            screen($chat, $old > 0 ? $old : null, 'دسترسی به این بخش ندارید.', back_markup());
            return;
        }
        try {
            if (admin_callback($data, $uid, $chat, $old)) {
                return;
            }
        } catch (Throwable $e) {
            error_log('[SimorghBot admin] ' . $data . ': ' . $e->getMessage());
            screen($chat, $old > 0 ? $old : null,
                'خطایی در این بخش رخ داد: ' . h(mb_substr($e->getMessage(), 0, 200)) . "\nلطفاً دوباره تلاش کنید.",
                kb([[admin_back('admin_panel')]]));
            return;
        }
    }
    if ($data !== 'check_join' && !is_member($uid)) {
        force_join_screen($chat, $old > 0 ? $old : null);
        return;
    }
    customer_callback($data, $uid, $name, $chat, $old);
}

function customer_callback(string $data, int $uid, string $name, int $chat, int $old): void
{
    $user = get_user($uid);

    if ($data === 'noop') {
        return;
    }

    if ($data === 'back_main') {
        clear_states($uid);
        show_menu($chat, $uid, $old);
        return;
    }

    if ($data === 'check_join') {
        clear_states($uid);
        show_home($chat, $uid, $name, $old);
        return;
    }

    if (preg_match('/^ureply_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'ureply', $mm[1]);
        screen($chat, $old, 'پیام یا تصویر خود را ارسال کنید. برای مدیریت ارسال خواهد شد.', cancel_markup());
        return;
    }

    if ($data === 'cancel_action') {
        clear_states($uid);
        show_home($chat, $uid, $name, $old);
        return;
    }

    if ($data === 'buy_panel') {
        $plans = plan_durations(true);
        if ($plans === []) {
            screen($chat, $old, '⚠️ در حال حاضر مدت خرید فعالی وجود ندارد.', kb([back_row()]));
            return;
        }
        $rows = [];
        foreach ($plans as $pl) {
            $rows[] = [btn(plan_title((int) $pl['months']), 'plan_' . (int) $pl['months'], color_of('buy'))];
        }
        $rows[] = back_row();
        screen($chat, $old, bot_text('buy_intro'), kb($rows));
        return;
    }

    if (preg_match('/^plan_(\d{1,2})$/', $data, $mm) === 1) {
        $plan = (int) $mm[1];
        if (!plan_is_active($plan)) {
            screen($chat, $old, '⚠️ این مدت دیگر فعال نیست.', kb([back_row('buy_panel')]));
            return;
        }
        $items = rows('SELECT * FROM products WHERE plan = ? ORDER BY price, id', [$plan]);
        if ($items === []) {
            screen($chat, $old, 'در حال حاضر پلنی برای ' . plan_title($plan) . ' ثبت نشده است.',
                kb([back_row('buy_panel')]));
            return;
        }
        $rows = [];
        foreach ($items as $p) {
            $rows[] = [btn((string) $p['name'] . ' | ' . money($p['price']) . ' تومان', 'buy_' . $p['id'], color_of('buy'))];
        }
        $rows[] = back_row('buy_panel');
        screen($chat, $old, '<b>پلن‌های ' . plan_title($plan) . '</b>' . "\n\nپلن موردنظر را انتخاب کنید:", kb($rows));
        return;
    }
    if (preg_match('/^plan_([123])$/', $data, $mm) === 1) {
        $plan = (int) $mm[1];
        $items = rows('SELECT * FROM products WHERE plan = ? ORDER BY price, id', [$plan]);
        if ($items === []) {
            screen($chat, $old, 'در حال حاضر پلنی برای ' . fa((string) $plan) . 'ماهه ثبت نشده است.',
                kb([back_row('buy_panel')]));
            return;
        }
        $rows = [];
        foreach ($items as $p) {
            $rows[] = [btn('' . $p['name'] . ' | ' . money($p['price']) . ' تومان', 'buy_' . $p['id'], color_of('buy'))];
        }
        $rows[] = back_row('buy_panel');
        screen($chat, $old, '<b>پلن‌های ' . fa((string) $plan) . 'ماهه</b>' . "\n\nپلن موردنظر را انتخاب کنید:", kb($rows));
        return;
    }

    if (preg_match('/^buy_(\d+)$/', $data, $mm) === 1) {
        show_product_confirm($chat, $uid, (int) $mm[1], $old);
        return;
    }

    if (preg_match('/^disc_enter_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'discount_for', $mm[1]);
        screen($chat, $old, '<b>کد تخفیف</b>' . "\n" . RULE . "\n" .
            'کد تخفیف خود را ارسال کنید.', kb([[btn('انصراف', 'buy_' . (int) $mm[1], 'danger')]]));
        return;
    }

    if (preg_match('/^disc_clear_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'discount', '');
        show_product_confirm($chat, $uid, (int) $mm[1], $old);
        return;
    }

    if (preg_match('/^wallet_buy_(\d+)$/', $data, $mm) === 1) {
        $code = current_discount($uid);
        $result = buy_with_wallet($uid, (int) $mm[1], $code);
        if (!$result['ok']) {
            screen($chat, $old, '' . h($result['error']), kb([[btn('تأیید خرید', 'buy_' . (int) $mm[1], '')], back_row('wallet')]));
            return;
        }
        state_set($uid, 'discount', '');
        screen($chat, $old, '<b>پرداخت انجام شد</b>' . "\n" . RULE . "\n" .
            'مبلغ پرداختی: ' . money($result['amount']) . ' تومان' . "\n" .
            'شماره سفارش: <code>' . h($result['order_id']) . '</code>' . "\n" .
            ($result['delivered'] ? 'رسید و مشخصات سرویس برای شما ارسال شد.' : 'اطلاعات سرویس شما به‌زودی ارسال می‌شود.'), kb([
            [btn('سفارش‌های من', 'my_orders', '')], back_row(),
        ]));
        return;
    }

    if (preg_match('/^card_buy_(\d+)$/', $data, $mm) === 1) {
        $code = current_discount($uid);
        if ($code !== null) {
            $checked = discount_validate($code, $uid);
            if (isset($checked['error'])) {
                state_set($uid, 'discount', '');
                screen($chat, $old, h($checked['error']), kb([[btn('تأیید خرید', 'buy_' . (int) $mm[1], '')]]));
                return;
            }
        }
        $orderId = create_card_order($uid, (int) $mm[1], $code);
        if ($orderId === null) {
            screen($chat, $old, 'ثبت سفارش انجام نشد. پلن یا کد تخفیف دیگر معتبر نیست.', kb([back_row('buy_panel')]));
            return;
        }
        state_set($uid, 'discount', '');
        state_set($uid, 'receipt_order', $orderId);
        $order = one('SELECT amount FROM orders WHERE id = ?', [$orderId]);
        $p = payment_info();
        screen($chat, $old, '<b>پرداخت کارت به کارت</b>' . "\n" . RULE . "\n" .
            'مبلغ قابل پرداخت: <b>' . money($order['amount']) . ' تومان</b>' . "\n" .
            'شماره کارت:' . "\n<code>" . h($p['card']) . '</code>' . "\n" .
            'به نام: <b>' . h($p['owner']) . '</b>' . "\n" .
            'پس از پرداخت، تصویر رسید را در همین گفتگو ارسال کنید.',
            kb([[btn('لغو سفارش', 'cancel_order_' . $orderId, 'danger')], back_row('buy_panel')]));
        return;
    }

    if (preg_match('/^cancel_order_(.+)$/', $data, $mm) === 1) {
        $st = q("UPDATE orders SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'waiting_receipt'",
            [$mm[1], $uid]);
        state_set($uid, 'receipt_order', '');
        screen($chat, $old, $st->rowCount() > 0 ? 'سفارش لغو شد.' : 'این سفارش دیگر قابل لغو نیست.',
            kb([back_row()]));
        return;
    }

    if ($data === 'wallet') {
        screen($chat, $old, '<b>کیف پول</b>' . "\n━━━━━━━━━━━━━━━━━━━━\n\n" .
            'موجودی فعلی: <b>' . money($user['balance']) . ' تومان</b>' . "\n\n" .
            'موجودی کیف پول برای خرید سریع پلن‌ها استفاده می‌شود.',
            kb([[btn('افزایش موجودی', 'wallet_add', color_of('wallet'))], back_row()]));
        return;
    }

    if ($data === 'wallet_add') {
        clear_states($uid);
        state_set($uid, 'wallet_amount', 'waiting');
        screen($chat, $old, '<b>افزایش موجودی</b>' . "\n\n" .
            'مبلغ شارژ را به تومان ارسال کنید.' . "\n" .
            'نمونه: <code>100000</code>', kb([[btn('انصراف', 'cancel_action', 'danger')], back_row('wallet')]));
        return;
    }

    if (preg_match('/^wallet_cancel_(.+)$/', $data, $mm) === 1) {
        q("UPDATE wallet_charges SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'waiting'",
            [$mm[1], $uid]);
        state_set($uid, 'wallet_receipt', '');
        screen($chat, $old, 'درخواست افزایش موجودی لغو شد.', kb([back_row('wallet')]));
        return;
    }

    if ($data === 'my_panels') {
        $panels = active_panels($uid);
        if ($panels === []) {
            screen($chat, $old, '<b>هنوز پنلی برای شما ثبت نشده است.</b>' . "\n" .
                'برای خرید، از منوی اصلی «خرید پنل» را بزنید.', kb([back_row()]));
            return;
        }
        $rows = [];
        foreach ($panels as $i => $p) {
            $rows[] = [btn('سرویس شماره ' . fa((string) ($i + 1)) . ' — ' . $p['title'], 'panel_get_' . $p['id'], color_of('panels'))];
        }
        $rows[] = back_row();
        screen($chat, $old, '<b>پنل‌های من</b>' . "\nتعداد: " . fa((string) count($panels)), kb($rows));
        return;
    }

    if (preg_match('/^panel_get_(\d+)$/', $data, $mm) === 1) {
        $p = one('SELECT * FROM panels WHERE id = ? AND user_id = ?', [(int) $mm[1], $uid]);
        if ($p === null) {
            screen($chat, $old, 'سرویس پیدا نشد.', kb([back_row('my_panels')]));
            return;
        }
        screen($chat, $old, '<b>' . h($p['title']) . '</b>' . "\n\n" . panel_details_html((string) $p['details']),
            kb([back_row('my_panels')]));
        return;
    }

    if ($data === 'account') {
        $orders = (int) scalar('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$uid]);
        $panels = count(active_panels($uid));
        $refs = (int) scalar('SELECT COUNT(*) FROM referrals WHERE referrer_id = ?', [$uid]);
        screen($chat, $old, '<b>حساب کاربری</b>' . "\n━━━━━━━━━━━━━━━━━━━━\n\n" .
            'شناسه: <code>' . $uid . '</code>' . "\n" .
            'نام: ' . h($user['first_name']) . "\n" .
            'موجودی: <b>' . money($user['balance']) . ' تومان</b>' . "\n" .
            'سرویس‌های فعال: <b>' . fa((string) $panels) . '</b>' . "\n" .
            'کل سفارش‌ها: <b>' . fa((string) $orders) . '</b>' . "\n" .
            'دعوت‌های موفق: <b>' . fa((string) $refs) . '</b>' . "\n" .
            'تست رایگان باقی‌مانده: <b>' . fa((string) max(0, test_limit() - (int) $user['test_count'])) . '</b>',
            kb([[btn('سفارش‌های من', 'my_orders', color_of('account'))], back_row()]));
        return;
    }

    if ($data === 'my_orders') {
        $labels = ['completed' => 'تکمیل شده', 'waiting_receipt' => 'منتظر رسید',
            'waiting_admin' => 'در انتظار بررسی', 'waiting_panel_details' => 'در حال آماده‌سازی',
            'rejected' => 'رد شده', 'cancelled' => 'لغو شده'];
        $lines = ['<b>سفارش‌های من</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach (user_orders($uid) as $o) {
            $lines[] = '' . h($o['product_name'] ?? 'محصول') . ' | ' . money($o['amount']) . ' تومان';
            $lines[] = '' . ($labels[$o['status']] ?? h($o['status'])) . ' | ' . h($o['created_at']);
            $lines[] = '──────────────';
        }
        if (count($lines) === 2) {
            $lines[] = 'هنوز سفارشی ثبت نشده است.';
        }
        screen($chat, $old, implode("\n", $lines), kb([back_row('account')]));
        return;
    }

    if ($data === 'referral') {
        $link = referral_link($uid);
        screen($chat, $old, '<b>زیرمجموعه‌گیری</b>' . "\n\n" .
            'پاداش هر دعوت موفق: <b>' . money(referral_reward()) . ' تومان</b>' . "\n\n" .
            'لینک دعوت اختصاصی شما:' . "\n<code>" . h($link) . '</code>', kb([
            [btn_url('اشتراک‌گذاری لینک', 'https://t.me/share/url?url=' . rawurlencode($link) .
                '&text=' . rawurlencode('به ' . store_name() . 'بپیوندید.'), color_of('referral'))],
            back_row(),
        ]));
        return;
    }

    if ($data === 'free_test') {
        $r = create_test_request($uid);
        if (!$r['ok']) {
            screen($chat, $old, '' . $r['error'], kb([back_row()]));
            return;
        }
        screen($chat, $old, ' <b>درخواست تست رایگان شما ثبت شد.</b>' . "\n" .
            'پس از بررسی، نتیجه در همین گفتگو برای شما ارسال می‌شود.', kb([back_row()]));
        return;
    }

    if ($data === 'miniapp_login') {
        $url = miniapp_url();
        if ($url === '') {
            screen($chat, $old, '⚠️ مینی‌اپ در حال حاضر فعال نیست.', kb([back_row()]));
            return;
        }
        $token = auth_sign('login', $uid, time() + 900);
        screen($chat, $old, '<b>🔐 ورود به مینی‌اپ</b>' . "\n" . RULE . "\n" .
            'برای ورود امن به مینی‌اپ، دکمه‌ی زیر را بزنید. این لینک ۱۵ دقیقه اعتبار دارد.',
            kb([[btn_url('ورود به مینی‌اپ', $url . '?t=' . $token, 'primary')], back_row()]));
        return;
    }

    if ($data === 'support_center') {
        screen($chat, $old, bot_text('support_intro'), kb([
            [btn('سوالات متداول', 'faq', color_of('support')), btn('تیکت جدید', 'new_ticket', 'success')],
            [btn('تیکت‌های من', 'my_tickets', color_of('support'))],
            back_row(),
        ]));
        return;
    }

    if ($data === 'new_ticket') {
        clear_states($uid);
        state_set($uid, 'support', '1');
        screen($chat, $old, '<b>ثبت تیکت جدید</b>' . "\n\nمشکل یا پیام خود را به‌صورت متن یا عکس ارسال کنید.",
            kb([[btn('انصراف', 'cancel_action', 'danger')], back_row('support_center')]));
        return;
    }

    if ($data === 'my_tickets') {
        $st = ['open' => 'باز', 'answered' => 'پاسخ داده شده', 'closed' => 'بسته'];
        $lines = ['<b>تیکت‌های من</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach (rows('SELECT * FROM tickets WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$uid]) as $t) {
            $lines[] = '#' . $t['id'] . ' • ' . ($st[$t['status']] ?? h($t['status'])) . ' • ' . h($t['created_at']);
        }
        if (count($lines) === 2) {
            $lines[] = 'تیکتی ثبت نشده است.';
        }
        screen($chat, $old, implode("\n", $lines), kb([back_row('support_center')]));
        return;
    }

    if ($data === 'faq') {
        $rows = [];
        foreach (rows('SELECT id, question FROM faqs WHERE active = 1 ORDER BY id') as $f) {
            $rows[] = [btn(mb_substr((string) $f['question'], 0, 45), 'faq_view_' . $f['id'], color_of('support'))];
        }
        if ($rows === []) {
            $rows[] = [btn('—', 'noop', 'primary')];
        }
        $rows[] = back_row('support_center');
        screen($chat, $old, '<b>سوالات متداول</b>' . "\n\nسوال موردنظر را انتخاب کنید.", kb($rows));
        return;
    }

    if (preg_match('/^faq_view_(\d+)$/', $data, $mm) === 1) {
        $f = one('SELECT * FROM faqs WHERE id = ? AND active = 1', [(int) $mm[1]]);
        if ($f !== null) {
            screen($chat, $old, '<b>' . h($f['question']) . '</b>' . "\n\n" . h($f['answer']), kb([back_row('faq')]));
        }
        return;
    }

    // دکمه‌ی ناشناخته: به منوی اصلی برگرد
    show_home($chat, $uid, $name, $old);
}
