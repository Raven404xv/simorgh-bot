<?php
/**
 * پنل مدیریت کامل: آمار، در انتظار بررسی، کاربران، محصولات، پرداخت، تست رایگان،
 * عضویت اجباری، مدیران، تیکت‌ها، ارسال همگانی، رنگ دکمه‌ها، گزارش‌ها و تنظیمات فروشگاه.
 */
declare(strict_types=1);

const ADMIN_PREFIXES = ['admin_', 'fin_', 'zarin_', 'users_', 'user_', 'pending', 'product_', 'set_', 'force_', 'ticket_',
    'wallet_approve_', 'wallet_reject_', 'order_reply_', 'order_reject_', 'test_reply_', 'test_reject_',
    'color_', 'setcolor_', 'bc_', 'report_', 'store_', 'test_toggle', 'layout_', 'label_', 'role_', 'umsg_', 'apanel_', 'order_view_', 'admin_orders', 'dcode_', 'product_view_', 'product_pname_', 'product_pprice_', 'stock_', 'bulk_', 'svc_', 'referral_set', 'alabel_', 'dur_', 'txt_', 'bkp_', 'faqadm_', 'reports_'];

const USERS_PER_PAGE = 8;

function admin_is_action(string $data): bool
{
    foreach (ADMIN_PREFIXES as $prefix) {
        if (str_starts_with($data, $prefix)) {
            return true;
        }
    }
    return false;
}

/** نام‌های پیش‌فرض بخش‌های مدیریت. مدیر می‌تواند هرکدام را تغییر دهد. */
const ADMIN_MENU_LABELS = [
    'panels' => '📦 مدیریت پنل‌ها',
    'services' => '🧾 سرویس‌های فروخته‌شده',
    'shop' => '🛍 فروشگاه و سفارش',
    'finance' => '💰 امور مالی',
    'users' => '👥 مدیریت کاربران',
    'support' => '🎧 پشتیبانی و تست',
    'report' => '📢 گزارش ربات',
    'store' => '⚙️ تنظیمات',
];

function admin_label(string $key): string
{
    $custom = trim(setting_get('alabel_' . $key, ''));
    return $custom !== '' ? $custom : ADMIN_MENU_LABELS[$key];
}

function admin_menu_markup(): string
{
    return kb([
        [btn('📊 آمار کلی', 'admin_stats', '')],
        [btn(admin_label('panels'), 'admin_panels', ''), btn(admin_label('services'), 'admin_services', '')],
        [btn(admin_label('shop'), 'admin_shop', ''), btn(admin_label('finance'), 'admin_finance', '')],
        [btn(admin_label('users'), 'admin_users', ''), btn(admin_label('store'), 'admin_store', '')],
        [btn(admin_label('support'), 'admin_support', '')],
        [btn(admin_label('report'), 'admin_report', '')],
        [btn('‹ منوی اصلی', 'back_main', '')],
    ]);
}

/** پیام ورود به پنل مدیریت: نسخه‌ی ربات، نام مینی‌اپ و یادآوری‌های ثابت. */
function admin_info_text(): string
{
    return '<b>نسخه‌ی ربات:</b> ' . h(SB_VERSION) . "\n" .
        '<b>نسخه‌ی مینی‌اپ:</b> ' . h(MINIAPP_VERSION) . "\n" .
        '<b>نام مینی‌اپ:</b> ' . h(store_name()) . "\n\n" .
        '<b>🛠 پنل مدیریت ربات</b>' . "\n" .
        '<blockquote>این ربات کاملاً رایگان است و توسط تیم سیمرغ توسعه داده شده است.</blockquote>' . "\n" .
        '<blockquote>هرگونه فروش یا دریافت وجه به‌عنوان استفاده از این ربات، تخلف محسوب می‌شود.</blockquote>' . "\n" .
        '<blockquote>در صورت مشاهده‌ی فروش یا دریافت وجه، لطفاً موضوع را پیگیری کرده و نسبت به بازپس‌گیری آن اقدام کنید.</blockquote>' . "\n" .
        '<blockquote>در صورت بروز خطا یا اختلال در عملکرد ربات، از طریق «گزارش ربات» همین پنل با ما در ارتباط باشید.</blockquote>';
}

/** صفحه‌ی مدیریت (متنی و بدون تصویر). */
function admin_screen(int $chat, ?int $old, string $text, ?string $markup): void
{
    if ($markup !== null && menu_style() === 'keyboard') {
        $rows = json_decode($markup, true)['inline_keyboard'] ?? [];
        $map = [];
        $reply = [];
        $hasLink = false;
        $choices = 0;
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $b) {
                if (isset($b['url']) || isset($b['web_app'])) {
                    $hasLink = true;
                    continue;
                }
                if (!isset($b['callback_data'])) {
                    continue;
                }
                $choices++;
                $label = (string) $b['text'];
                while (isset($map[$label])) {
                    $label .= "\u{200B}";
                }
                $map[$label] = (string) $b['callback_data'];
                $line[] = ['text' => $label];
            }
            if ($line !== []) {
                $reply[] = $line;
            }
        }
        // فهرست‌های بلند (مثل لیست کاربران) زیر پیام می‌مانند؛ کیبورد فقط برای منو و اقدام‌هاست
        if (!$hasLink && $reply !== [] && $choices <= 12) {
            setting_set('akbmap:' . $chat, json_encode($map, JSON_UNESCAPED_UNICODE));
            $keyboard = json_encode(['keyboard' => $reply, 'resize_keyboard' => true, 'is_persistent' => false,
                'input_field_placeholder' => 'یک گزینه را انتخاب کنید'], JSON_UNESCAPED_UNICODE);
            $sent = send_text($chat, $text, $keyboard);
            $prev = (int) setting_get('akblast:' . $chat, '0');
            if ($prev > 0) {
                delete_message($chat, $prev);
            }
            setting_set('akblast:' . $chat, (string) (int) ($sent['result']['message_id'] ?? 0));
            if ($old !== null && $old > 0 && $old !== $prev) {
                delete_message($chat, $old);
            }
            return;
        }
    }
    screen($chat, $old, $text, $markup);
}

/** ساخت کد تخفیف از مراحل ویزارد. */
function dcode_finalize(int $uid, int $chat): void
{
    $pct = (int) state_get($uid, 'dcode_pct');
    $name = state_get($uid, 'dcode_nm');
    $hours = (int) state_get($uid, 'dcode_exp');
    $max = (int) state_get($uid, 'dcode_max');
    $scope = state_get($uid, 'dcode_sc');
    if ($scope === '') {
        $scope = 'all_once';
    }
    if ($pct < 1 || $name === '') {
        send_text($chat, 'اطلاعات کد تخفیف ناقص است. لطفاً از ابتدا دوباره شروع کنید.', kb([[admin_back('admin_discounts')]]));
        clear_states($uid);
        return;
    }
    if (one('SELECT id FROM discount_codes WHERE code = ?', [$name]) !== null) {
        send_text($chat, '⚠️ این نام قبلاً ثبت شده است. لطفاً نام دیگری انتخاب کنید.', kb([[admin_back('admin_discounts')]]));
        clear_states($uid);
        return;
    }
    $expires = $hours > 0 ? date('Y-m-d H:i:s', time() + $hours * 3600) : null;
    $maxUses = $max > 0 ? $max : null;
    q('INSERT INTO discount_codes (code, percent, active, used_count, created_at, expires_at, max_uses, scope)
       VALUES (?, ?, 1, 0, ?, ?, ?, ?)', [$name, $pct, now(), $expires, $maxUses, $scope]);
    $id = (int) db()->lastInsertId();
    clear_states($uid);
    send_text($chat, '✅ <b>کد تخفیف ایجاد شد</b>' . "\n" . RULE . "\n" .
        'کد: <code>' . h($name) . '</code>' . "\n" .
        'میزان تخفیف: <b>' . $pct . '٪</b>' . "\n" .
        'محدوده: ' . h(dcode_scope_label($scope)) . "\n" .
        'انقضا: ' . ($expires ?? 'بدون انقضا') . "\n" .
        'سقف استفاده: ' . ($maxUses === null ? 'نامحدود' : $maxUses . 'بار'),
        kb([[btn('مشاهده‌ی کد', 'dcode_view_' . $id, '')], [admin_back('admin_discounts')]]));
}

/** برچسب محدوده‌ی کد تخفیف. */
function dcode_scope_label(string $scope): string
{
    return ['first_order' => 'فقط اولین خرید مشتری', 'all_once' => 'همه‌ی خریدها (هر مشتری یک بار)',
        'all' => 'همه‌ی خریدها (بدون محدودیت هر مشتری)'][$scope] ?? 'همه‌ی خریدها (هر مشتری یک بار)';
}

/** مرحله‌ی انتخاب محدوده‌ی کد تخفیف. */
function dcode_scope_prompt(int $chat, ?int $old): void
{
    screen($chat, $old, '<b>➕ افزودن کد تخفیف</b>' . "\n" . RULE . "\n" .
        'مرحله ۴ از ۵: محدوده‌ی استفاده از کد را انتخاب کنید.' . "\n\n" .
        '<b>اولین خرید:</b> فقط برای خرید اول هر مشتری.' . "\n" .
        '<b>همه‌ی خریدها (یک بار):</b> برای هر خرید، اما هر مشتری فقط یک بار.' . "\n" .
        '<b>همه‌ی خریدها:</b> بدون محدودیت تعداد برای هر مشتری.',
        kb([
            [btn('اولین خرید', 'dcode_sc_first_order', '')],
            [btn('همه‌ی خریدها (یک بار)', 'dcode_sc_all_once', '')],
            [btn('همه‌ی خریدها', 'dcode_sc_all', '')],
            [admin_back('admin_discounts')],
        ]));
}

/** برچسب وضعیت کد تخفیف برای نمایش به مدیر. */
function dcode_status_label(array $d): string
{
    return ['active' => 'فعال', 'disabled' => 'غیرفعال', 'expired' => 'منقضی', 'exhausted' => 'ظرفیت تکمیل'][discount_state($d)] ?? '';
}

function dcode_usage_label(array $d): string
{
    return $d['max_uses'] === null ? 'نامحدود' : (int) $d['used_count'] . 'از ' . (int) $d['max_uses'];
}

/** دکمه‌ی بازگشت پنل مدیریت (بدون رنگ). */
function admin_back(string $callback = 'admin_home'): array
{
    if ($callback === 'admin_home') {
        return [btn('🏠 منوی اصلی پنل', 'admin_home', '')];
    }
    return [btn('⬅️ منوی قبل', $callback, ''), btn('🏠 منوی اصلی پنل', 'admin_home', '')];
}

function admin_home_text(): string
{
    return '<b>پنل مدیریت ' . h(store_name()) . '</b>' . "\n━━━━━━━━━━━━━━━━━━━━\n" .
        'یکی از بخش‌های زیر را انتخاب کنید.';
}

/* ------------------------------------------------------------------ پیام‌های ورودی مدیر */

/** مراحل افزودن پنل (آدرس، نام، رمز، پلن) و تغییر نام پنل. */
function admin_stock_input(string $input, int $uid, int $chat, string $text): void
{
    $text = trim($text);
    $cancel = kb([[btn('✖️ انصراف', 'admin_cancel', '')]]);
    if (str_starts_with($input, 'stock_rename:')) {
        $id = (int) substr($input, 13);
        if (mb_strlen($text) < 2 || mb_strlen($text) > 60 || preg_match('/[\r\n]/', $text) === 1) {
            state_set($uid, 'input', $input);
            send_text($chat, '⚠️ نام کاربری باید بین ۲ تا ۶۰ نویسه باشد.', $cancel);
            return;
        }
        $st = q("UPDATE panel_stock SET name = ? WHERE id = ? AND status = 'available'", [$text, $id]);
        send_text($chat, $st->rowCount() === 1 ? '✅ نام کاربری به‌روز شد.' : '⚠️ این پنل دیگر قابل ویرایش نیست.',
            kb([admin_back('stock_view_' . $id)]));
        return;
    }
    $draft = json_decode(setting_get('stockdraft:' . $uid, ''), true);
    if (!is_array($draft)) {
        $draft = ['kind' => 'manual'];
    }
    if ($input === 'stock_address') {
        if (mb_strlen($text) > 255 || preg_match('#^https?://[A-Za-z0-9._-]+(:[0-9]{1,5})?(/\S*)?$#', $text) !== 1) {
            state_set($uid, 'input', 'stock_address');
            send_text($chat, '⚠️ آدرس معتبر نیست. نمونه: https://panel.example.com:2053', $cancel);
            return;
        }
        $draft['address'] = $text;
        setting_set('stockdraft:' . $uid, json_encode($draft, JSON_UNESCAPED_UNICODE));
        state_set($uid, 'input', 'stock_name');
        send_text($chat, '<b>👤 نام کاربری پنل</b>' . "\n" . RULE . "\n" .
            'نام کاربری ورود به پنل را ارسال کنید (۲ تا ۶۰ نویسه).', $cancel);
        return;
    }
    if ($input === 'stock_name') {
        if (mb_strlen($text) < 2 || mb_strlen($text) > 60 || preg_match('/[\r\n]/', $text) === 1) {
            state_set($uid, 'input', 'stock_name');
            send_text($chat, '⚠️ نام کاربری باید بین ۲ تا ۶۰ نویسه باشد.', $cancel);
            return;
        }
        $draft['name'] = $text;
        setting_set('stockdraft:' . $uid, json_encode($draft, JSON_UNESCAPED_UNICODE));
        state_set($uid, 'input', 'stock_pass');
        send_text($chat, '<b>🔑 رمز عبور پنل</b>' . "\n" . RULE . "\n" .
            'رمز عبور ورود به پنل را ارسال کنید (۱ تا ۱۲۰ نویسه).', $cancel);
        return;
    }
    if ($input === 'stock_pass') {
        if (mb_strlen($text) < 1 || mb_strlen($text) > 120 || preg_match('/[\r\n]/', $text) === 1) {
            state_set($uid, 'input', 'stock_pass');
            send_text($chat, '⚠️ رمز عبور باید بین ۱ تا ۱۲۰ نویسه باشد.', $cancel);
            return;
        }
        $draft['password'] = $text;
        setting_set('stockdraft:' . $uid, json_encode($draft, JSON_UNESCAPED_UNICODE));
        state_set($uid, 'input', '');
        $products = rows('SELECT * FROM products ORDER BY plan, price LIMIT 40');
        if ($products === []) {
            send_text($chat, 'ابتدا یک پلن در بخش فروشگاه ثبت کنید.', kb([admin_back('admin_panels')]));
            return;
        }
        $rows = [];
        foreach ($products as $p) {
            $rows[] = [btn((string) $p['name'] . ' · ' . (int) $p['plan'] . ' ماهه · ' . money($p['price']) . ' تومان',
                'stock_prod_' . (int) $p['id'], '')];
        }
        $rows[] = admin_back('admin_panels');
        send_text($chat, '<b>📦 انتخاب پلن</b>' . "\n" . RULE . "\n" .
            'این پنل به کدام پلن متصل شود؟', kb($rows));
    }
}

/** صفحه‌ی جزئیات یک مدت خرید. */
function admin_dur_detail(int $chat, ?int $old, int $months): void
{
    $p = plan_find($months);
    if ($p === null) {
        admin_screen($chat, $old, '⚠️ این مدت پیدا نشد.', kb([admin_back('admin_plans')]));
        return;
    }
    $active = (int) $p['active'] === 1;
    $count = (int) scalar('SELECT COUNT(*) FROM products WHERE plan = ?', [$months]);
    admin_screen($chat, $old, '<b>📅 ' . h(plan_title($months)) . '</b>' . "\n" . RULE . "\n" .
        'مدت: <b>' . fa((string) $months) . ' ماه</b>' . "\n" .
        'وضعیت: <b>' . ($active ? '🟢 فعال' : '⏸ غیرفعال') . '</b>' . "\n" .
        'تعداد پلن‌های این مدت: <b>' . fa((string) $count) . '</b>' . "\n\n" .
        ($active ? 'با غیرفعال کردن، این مدت از فروشگاه و مینی‌اپ پنهان می‌شود.'
            : 'این مدت برای مشتریان نمایش داده نمی‌شود.'),
        kb([
            [btn('✏️ تغییر نام', 'dur_rename_' . $months, ''), btn($active ? '⏸ غیرفعال کردن' : '▶️ فعال کردن', 'dur_toggle_' . $months, '')],
            [btn('🗑 حذف مدت', 'dur_delete_' . $months, '')],
            admin_back('admin_plans'),
        ]));
}

/** ورودی‌های مدیریت مدت‌های خرید: تعداد ماه، سپس نام. */
function admin_duration_input(string $input, int $uid, int $chat, string $text): void
{
    $text = trim($text);
    $cancel = kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_plans')]);
    if ($input === 'dur_new_months') {
        $months = (int) normalize_digits($text);
        if ($months < 1 || $months > 36) {
            state_set($uid, 'input', 'dur_new_months');
            send_text($chat, '⚠️ تعداد ماه باید عددی بین ۱ و ۳۶ باشد.', $cancel);
            return;
        }
        if (plan_find($months) !== null) {
            send_text($chat, '⚠️ این مدت قبلاً ثبت شده است.', kb([admin_back('admin_plans')]));
            return;
        }
        state_set($uid, 'input', 'dur_new_label:' . $months);
        send_text($chat, '<b>نام مدت</b>' . "\n" . RULE . "\n" .
            'نامی برای نمایش این مدت ارسال کنید (۲ تا ۳۰ نویسه). برای نام پیش‌فرض، عبارت «پیش‌فرض» را ارسال کنید.', $cancel);
        return;
    }
    if (str_starts_with($input, 'dur_new_label:')) {
        $months = (int) substr($input, 14);
        $label = $text === 'پیش‌فرض' ? '' : $text;
        if ($label !== '' && (mb_strlen($label) < 2 || mb_strlen($label) > 30 || preg_match('/[\r\n]/', $label) === 1)) {
            state_set($uid, 'input', $input);
            send_text($chat, '⚠️ نام باید بین ۲ تا ۳۰ نویسه و در یک سطر باشد.', $cancel);
            return;
        }
        q('INSERT INTO plan_durations (months, label, active, created_at) VALUES (?, ?, 1, ?)', [$months, $label, now()]);
        send_text($chat, '✅ مدت خرید ثبت شد: <b>' . h(plan_title($months)) . '</b>', kb([admin_back('admin_plans')]));
        return;
    }
    if (str_starts_with($input, 'dur_name:')) {
        $months = (int) substr($input, 9);
        $label = $text === 'پیش‌فرض' ? '' : $text;
        if ($label !== '' && (mb_strlen($label) < 2 || mb_strlen($label) > 30 || preg_match('/[\r\n]/', $label) === 1)) {
            state_set($uid, 'input', $input);
            send_text($chat, '⚠️ نام باید بین ۲ تا ۳۰ نویسه و در یک سطر باشد.', $cancel);
            return;
        }
        q('UPDATE plan_durations SET label = ? WHERE months = ?', [$label, $months]);
        send_text($chat, '✅ نام مدت ذخیره شد: <b>' . h(plan_title($months)) . '</b>', kb([admin_back('dur_def_' . $months)]));
    }
}

/** نمایش متن فعلی یک بخش، همراه با پیش‌نمایش آن. */
function admin_text_detail(int $chat, ?int $old, string $key): void
{
    $part = bot_text_parts()[$key];
    $current = bot_text($key);
    $preview = $current !== '' ? $current : '(متن پیش‌فرض سیستم)';
    admin_screen($chat, $old, '<b>✏️ ' . h($part['title']) . '</b>' . "\n" . RULE . "\n" .
        h($part['hint']) . "\n\n" . '<b>پیش‌نمایش متن فعلی:</b>' . "\n" . $preview,
        kb([
            [btn('✏️ ویرایش متن', 'txt_edit_' . $key, ''), btn('♻️ بازنشانی', 'txt_reset_' . $key, '')],
            admin_back('admin_texts'),
        ]));
}

/** ذخیره‌ی متن جدید بخش‌های قابل ویرایش ربات. */
function admin_text_input(string $key, int $uid, int $chat, string $text): void
{
    $parts = bot_text_parts();
    if (!isset($parts[$key])) {
        send_text($chat, '⚠️ این بخش پیدا نشد.', kb([admin_back('admin_texts')]));
        return;
    }
    $text = trim($text);
    if ($text === 'پیش‌فرض') {
        setting_set($parts[$key]['setting'], '');
        send_text($chat, '✅ متن به حالت پیش‌فرض بازگشت.', kb([admin_back('txt_def_' . $key)]));
        return;
    }
    if (mb_strlen($text) < 2 || mb_strlen($text) > 3000) {
        state_set($uid, 'input', 'txt:' . $key);
        send_text($chat, '⚠️ متن باید بین ۲ تا ۳۰۰۰ نویسه باشد.', kb([[btn('✖️ انصراف', 'admin_cancel', '')]]));
        return;
    }
    if (!bot_text_is_valid($text)) {
        state_set($uid, 'input', 'txt:' . $key);
        send_text($chat, '⚠️ قالب‌بندی متن معتبر نیست. فقط برچسب‌های <b>، <i>، <u>، <code> و <blockquote> مجازند و هر برچسب باید بسته شود.',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')]]));
        return;
    }
    setting_set($parts[$key]['setting'], $text);
    send_text($chat, '✅ متن ذخیره شد. نمایش در ربات به‌روز است.', kb([admin_back('txt_def_' . $key)]));
}

/** صفحه‌ی جزئیات یک سوال متداول. */
function admin_faq_detail(int $chat, ?int $old, int $id): void
{
    $f = one('SELECT * FROM faqs WHERE id = ?', [$id]);
    if ($f === null) {
        admin_screen($chat, $old, '⚠️ این سوال پیدا نشد.', kb([admin_back('admin_faqs')]));
        return;
    }
    $active = (int) $f['active'] === 1;
    admin_screen($chat, $old, '<b>❓ ' . h((string) $f['question']) . '</b>' . "\n" . RULE . "\n" .
        'وضعیت: <b>' . ($active ? '🟢 نمایش به مشتری' : '⏸ پنهان') . '</b>' . "\n\n" .
        '<b>پاسخ:</b>' . "\n" . h((string) $f['answer']),
        kb([
            [btn('✏️ ویرایش پرسش', 'faqadm_q_' . $id, ''), btn('✏️ ویرایش پاسخ', 'faqadm_a_' . $id, '')],
            [btn($active ? '⏸ پنهان کردن' : '▶️ نمایش دادن', 'faqadm_t_' . $id, ''), btn('🗑 حذف', 'faqadm_del_' . $id, '')],
            admin_back('admin_faqs'),
        ]));
}

/** اعتبارسنجی و ذخیره‌ی ورودی‌های مدیریت سوالات متداول. */
function admin_faq_input(string $input, int $uid, int $chat, string $text): void
{
    $text = trim($text);
    $cancel = kb([[btn('✖️ انصراف', 'admin_cancel', '')]]);
    $qOk = mb_strlen($text) >= 3 && mb_strlen($text) <= 150 && preg_match('/[\r\n]/', $text) !== 1;
    $aOk = mb_strlen($text) >= 2 && mb_strlen($text) <= 3000;
    if ($input === 'faqadm_newq') {
        if (!$qOk) {
            state_set($uid, 'input', $input);
            send_text($chat, '⚠️ پرسش باید بین ۳ تا ۱۵۰ نویسه و در یک سطر باشد.', $cancel);
            return;
        }
        setting_set('faqdraft:' . $uid, $text);
        state_set($uid, 'input', 'faqadm_newa');
        send_text($chat, '<b>پاسخ</b>' . "\n" . RULE . "\nپاسخ را ارسال کنید (۲ تا ۳۰۰۰ نویسه). می‌توانید چند سطر بنویسید.", $cancel);
        return;
    }
    if ($input === 'faqadm_newa') {
        $question = (string) setting_get('faqdraft:' . $uid, '');
        if ($question === '' || !$aOk) {
            state_set($uid, 'input', $input);
            send_text($chat, '⚠️ پاسخ باید بین ۲ تا ۳۰۰۰ نویسه باشد.', $cancel);
            return;
        }
        q('INSERT INTO faqs (question, answer, active) VALUES (?, ?, 1)', [$question, $text]);
        $id = (int) db()->lastInsertId();
        setting_set('faqdraft:' . $uid, '');
        state_set($uid, 'input', '');
        send_text($chat, '✅ سوال متداول ثبت شد و برای مشتریان نمایش داده می‌شود.',
            kb([[btn('مشاهده‌ی سوال', 'faqadm_def_' . $id, '')], admin_back('admin_faqs')]));
        return;
    }
    if (preg_match('/^faqadm_editq:(\d+)$/', $input, $mm) === 1) {
        if (!$qOk) {
            state_set($uid, 'input', $input);
            send_text($chat, '⚠️ پرسش باید بین ۳ تا ۱۵۰ نویسه و در یک سطر باشد.', $cancel);
            return;
        }
        q('UPDATE faqs SET question = ? WHERE id = ?', [$text, (int) $mm[1]]);
        send_text($chat, '✅ پرسش به‌روز شد.', kb([admin_back('faqadm_def_' . (int) $mm[1])]));
        return;
    }
    if (preg_match('/^faqadm_edita:(\d+)$/', $input, $mm) === 1) {
        if (!$aOk) {
            state_set($uid, 'input', $input);
            send_text($chat, '⚠️ پاسخ باید بین ۲ تا ۳۰۰۰ نویسه باشد.', $cancel);
            return;
        }
        q('UPDATE faqs SET answer = ? WHERE id = ?', [$text, (int) $mm[1]]);
        send_text($chat, '✅ پاسخ به‌روز شد.', kb([admin_back('faqadm_def_' . (int) $mm[1])]));
        return;
    }
}

function admin_message_flow(array $m, int $uid, int $chat, string $text): bool
{
    $msgId = (int) $m['message_id'];

    $labels = json_decode(setting_get('akbmap:' . $chat, ''), true);
    if (is_array($labels) && isset($labels[$text])) {
        clear_states($uid);
        dispatch_callback((string) $labels[$text], $uid, '', $chat, 0);
        return true;
    }

    $input = state_get($uid, 'input');
    if ($input !== '') {
        state_set($uid, 'input', '');
        admin_apply_input($input, $uid, $chat, trim($text), $msgId);
        return true;
    }

    $product = state_get($uid, 'product');
    if ($product !== '') {
        return admin_product_step($product, $uid, $chat, trim($text));
    }

    $orderId = state_get($uid, 'order_reply');
    if ($orderId !== '') {
        state_set($uid, 'order_reply', '');
        $ok = complete_order($orderId, $chat, $msgId, $text !== '' ? $text : '✅ اطلاعات پنل به‌صورت رسانه ارسال شد.');
        send_text($chat, $ok ? '✅ اطلاعات برای کاربر ارسال شد.' : '⚠️ این سفارش دیگر قابل تکمیل نیست.',
            kb([admin_back('admin_pending')]));
        return true;
    }

    $testId = state_get($uid, 'test_reply');
    if ($testId !== '') {
        state_set($uid, 'test_reply', '');
        $req = one("SELECT * FROM test_requests WHERE id = ? AND status = 'waiting'", [$testId]);
        if ($req === null) {
            send_text($chat, '⚠️ این درخواست دیگر فعال نیست.', kb([admin_back('admin_home')]));
            return true;
        }
        copy_message((int) $req['user_id'], $chat, $msgId);
        q("UPDATE test_requests SET status = 'answered' WHERE id = ?", [$testId]);
        q('UPDATE users SET test_count = test_count + 1 WHERE user_id = ?', [(int) $req['user_id']]);
        send_text($chat, '✅ تست برای کاربر ارسال شد.', kb([admin_back('admin_home')]));
        return true;
    }

    $ticketId = state_get($uid, 'ticket_reply');
    if ($ticketId !== '') {
        state_set($uid, 'ticket_reply', '');
        $t = one('SELECT * FROM tickets WHERE id = ?', [(int) $ticketId]);
        if ($t === null || $t['status'] === 'closed') {
            send_text($chat, '⚠️ تیکت بسته است یا پیدا نشد.', kb([admin_back('admin_tickets')]));
            return true;
        }
        copy_message((int) $t['user_id'], $chat, $msgId);
        q("UPDATE tickets SET status = 'answered', updated_at = ? WHERE id = ?", [now(), (int) $ticketId]);
        send_text((int) $t['user_id'], '<b>پاسخ پشتیبانی #' . (int) $ticketId . '</b>' . "\nپیام مدیریت برای شما ارسال شد.");
        send_text($chat, 'پاسخ تیکت #' . (int) $ticketId . '✅ ارسال شد.', kb([admin_back('admin_tickets')]));
        return true;
    }

    $broadcast = state_get($uid, 'broadcast');
    if ($broadcast !== '') {
        state_set($uid, 'broadcast', '');
        admin_run_broadcast($chat, $msgId, $broadcast);
        return true;
    }
    return false;
}

function admin_run_broadcast(int $chat, int $msgId, string $type): void
{
    $total = (int) scalar('SELECT COUNT(*) FROM users WHERE blocked = 0 AND user_id <> ?', [bot_user_id()]);
    if ($total === 0) {
        send_text($chat, '⚠️ کاربری برای ارسال وجود ندارد.', kb([admin_back('admin_home')]));
        return;
    }
    q("INSERT INTO broadcast_jobs (admin_id, from_chat, msg_id, markup_type, status, last_user, total, ok_count, fail_count, progress_msg, created_at)
       VALUES (?, ?, ?, ?, 'running', 0, ?, 0, 0, 0, ?)", [$chat, $chat, $msgId, $type, $total, now()]);
    $jobId = (int) db()->lastInsertId();
    $job = ['id' => $jobId, 'admin_id' => $chat, 'total' => $total, 'ok_count' => 0, 'fail_count' => 0, 'progress_msg' => 0];
    $sent = send_text($chat, '<b>📤 ارسال همگانی در حال انجام است</b>' . "\n" . RULE . "\n" .
        'پیشرفت: <b>0</b> از <b>' . fa((string) $total) . '</b>' . "\n" .
        'ارسال به‌صورت دسته‌ای و خودکار انجام می‌شود.',
        kb([[btn('لغو عملیات', 'bc_cancel_' . $jobId, '')]]));
    $pm = (int) ($sent['result']['message_id'] ?? 0);
    q('UPDATE broadcast_jobs SET progress_msg = ? WHERE id = ?', [$pm, $jobId]);
    $job['progress_msg'] = $pm;
}

/** مقادیری که مدیر در مرحله‌ی input وارد می‌کند. */
function admin_apply_input(string $input, int $uid, int $chat, string $text, int $msgId): void
{
    $back = kb([admin_back('admin_home')]);
    if ($input === 'card') {
        setting_set('payment_card', $text);
        send_text($chat, '✅ شماره کارت ذخیره شد.', kb([admin_back('fin_card')]));
    } elseif ($input === 'owner') {
        setting_set('payment_owner', $text);
        send_text($chat, '✅ نام صاحب کارت ذخیره شد.', kb([admin_back('fin_card')]));
    } elseif ($input === 'test_limit') {
        setting_set('test_limit', (string) max(0, (int) normalize_digits($text)));
        send_text($chat, '✅ سقف تست ذخیره شد.', kb([admin_back('admin_test')]));
    } elseif ($input === 'force_add') {
        $channel = $text;
        if (strpos($channel, 'https://t.me/') === 0) {
            $channel = '@' . trim(substr($channel, strlen('https://t.me/')), '/');
        }
        if ($channel !== '' && $channel[0] !== '@' && !ctype_digit(ltrim($channel, '-'))) {
            $channel = '@' . $channel;
        }
        q('INSERT IGNORE INTO force_join (channel) VALUES (?)', [$channel]);
        send_text($chat, '✅ کانال اضافه شد.', kb([admin_back('admin_force')]));
    } elseif ($input === 'add_admin') {
        $aid = (int) normalize_digits($text);
        if ($aid <= 0 || get_user($aid) === null) {
            send_text($chat, '⚠️ این کاربر هنوز ربات را راه‌اندازی نکرده است (/start).', kb([admin_back('admin_admins')]));
            return;
        }
        send_text($chat, '<b>🔐 سطح دسترسی</b>' . "\n" . RULE . "\n" .
            'سطح دسترسی مدیر جدید را انتخاب کنید.', kb([
            [btn('دسترسی کامل', 'role_' . $aid . '_full', '')],
            [btn('مالی و سفارش‌ها', 'role_' . $aid . '_finance', '')],
            [btn('پشتیبانی و کاربران', 'role_' . $aid . '_support', '')],
            [admin_back('admin_admins')],
        ]));
    } elseif ($input === 'store_name') {
        if (mb_strlen($text) < 2 || mb_strlen($text) > 40 || strpbrk($text, "\r\n") !== false) {
            send_text($chat, '⚠️ نام باید بین ۲ تا ۴۰ حرف باشد و در یک سطر نوشته شود.', kb([admin_back('admin_store')]));
            return;
        }
        setting_set('store_name', $text);
        send_text($chat, 'نام فروشگاه به «' . h($text) . '» تغییر یافت.', kb([admin_back('admin_store')]));
    } elseif ($input === 'report_chat') {
        $chatId = normalize_digits($text);
        if (!preg_match('/^-100\d{5,}$/', $chatId)) {
            send_text($chat, '⚠️ شناسه‌ی سوپرگروه باید به شکل -1001234567890 باشد.', kb([admin_back('admin_reports')]));
            return;
        }
        $r = set_report_chat($chatId);
        send_text($chat, $r['ok'] ? '✅ گروه گزارش متصل شد: <b>' . h($r['title']) . '</b>' . "\nتاپیک‌ها ساخته شدند."
            : '' . $r['error'], kb([admin_back('admin_reports')]));
    } elseif ($input === 'zarin_merchant') {
        $code = strtolower(trim($text));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $code)) {
            send_text($chat, '⚠️ کد پذیرنده باید ۳۶ نویسه با خط تیره باشد.', kb([admin_back('fin_gateway')]));
            return;
        }
        setting_set('zarin_merchant', $code);
        install_schema(db());
        send_text($chat, '✅ کد پذیرنده ذخیره شد.', kb([admin_back('fin_gateway')]));
    } elseif (str_starts_with($input, 'panel_edit:')) {
        $pid = (int) substr($input, 11);
        if (mb_strlen($text) < 1 || mb_strlen($text) > 4000) {
            send_text($chat, '⚠️ متن اطلاعات پنل باید بین ۱ تا ۴۰۰۰ حرف باشد.', kb([admin_back('apanel_view_' . $pid)]));
            return;
        }
        q('UPDATE panels SET details = ? WHERE id = ?', [$text, $pid]);
        send_text($chat, '✅ اطلاعات پنل به‌روز شد.', kb([admin_back('apanel_view_' . $pid)]));
    } elseif ($input === 'dcode_percent') {
        $pct = (int) normalize_digits($text);
        if ($pct < 1 || $pct > 90) {
            state_set($uid, 'input', 'dcode_percent');
            send_text($chat, '⚠️ درصد تخفیف باید عددی بین ۱ و ۹۰ باشد.', kb([[admin_back('admin_discounts')]]));
            return;
        }
        state_set($uid, 'dcode_pct', (string) $pct);
        state_set($uid, 'input', 'dcode_name');
        send_text($chat, '<b>➕ افزودن کد تخفیف</b>' . "\n" . RULE . "\n" .
            'مرحله ۲ از ۵: نام کد را ارسال کنید. فقط حروف انگلیسی، عدد، «_» و «-» (۳ تا ۲۰ نویسه) مجاز است.',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')]]));
    } elseif ($input === 'dcode_name') {
        $name = strtoupper(trim($text));
        if (!preg_match('/^[A-Z0-9_-]{3,20}$/', $name)) {
            state_set($uid, 'input', 'dcode_name');
            send_text($chat, '⚠️ نام کد نامعتبر است. فقط حروف انگلیسی، عدد، «_» و «-» (۳ تا ۲۰ نویسه) مجاز است.',
                kb([[btn('✖️ انصراف', 'admin_cancel', '')]]));
            return;
        }
        if (one('SELECT id FROM discount_codes WHERE code = ?', [$name]) !== null) {
            state_set($uid, 'input', 'dcode_name');
            send_text($chat, '⚠️ این نام قبلاً ثبت شده است. نام دیگری ارسال کنید.', kb([[btn('✖️ انصراف', 'admin_cancel', '')]]));
            return;
        }
        state_set($uid, 'dcode_nm', $name);
        send_text($chat, '<b>➕ افزودن کد تخفیف</b>' . "\n" . RULE . "\n" .
            'مرحله ۳ از ۵: مدت اعتبار کد را انتخاب کنید.', kb([
            [btn('۱ ساعت', 'dcode_exp_1', ''), btn('۱ روز', 'dcode_exp_24', '')],
            [btn('۱۰ روز', 'dcode_exp_240', ''), btn('۳۰ روز', 'dcode_exp_720', '')],
            [btn('بدون انقضا', 'dcode_exp_0', ''), btn('مدت دلخواه', 'dcode_expc', '')],
            [btn('✖️ انصراف', 'admin_cancel', '')],
        ]));
    } elseif ($input === 'dcode_expday') {
        $days = (int) normalize_digits($text);
        if ($days < 1 || $days > 365) {
            state_set($uid, 'input', 'dcode_expday');
            send_text($chat, '⚠️ تعداد روز باید عددی بین ۱ و ۳۶۵ باشد.', kb([[btn('✖️ انصراف', 'admin_cancel', '')]]));
            return;
        }
        state_set($uid, 'dcode_exp', (string) ($days * 24));
        dcode_scope_prompt($chat, null);
    } elseif ($input === 'dcode_maxval') {
        $max = (int) normalize_digits(str_replace([',', '٬'], '', $text));
        if ($max < 1 || $max > 100000) {
            state_set($uid, 'input', 'dcode_maxval');
            send_text($chat, '⚠️ تعداد باید عددی بین ۱ و ۱۰۰٬۰۰۰ باشد.', kb([[btn('✖️ انصراف', 'admin_cancel', '')]]));
            return;
        }
        state_set($uid, 'dcode_max', (string) $max);
        dcode_finalize($uid, $chat);
    } elseif (str_starts_with($input, 'edit_pname:')) {
        $pid = (int) substr($input, 11);
        if (mb_strlen($text) < 2 || mb_strlen($text) > 120) {
            send_text($chat, '⚠️ نام پلن باید بین ۲ تا ۱۲۰ حرف باشد.', kb([[admin_back('product_view_' . $pid)]]));
            state_set($uid, 'input', $input);
            return;
        }
        q('UPDATE products SET name = ? WHERE id = ?', [$text, $pid]);
        send_text($chat, '✅ نام پلن به‌روز شد.', kb([[admin_back('product_view_' . $pid)]]));
    } elseif (str_starts_with($input, 'edit_pprice:')) {
        $pid = (int) substr($input, 12);
        $price = (int) normalize_digits(str_replace([',', '٬'], '', $text));
        if ($price <= 0 || $price > 1000000000) {
            send_text($chat, '⚠️ قیمت معتبر نیست.', kb([[admin_back('product_view_' . $pid)]]));
            state_set($uid, 'input', $input);
            return;
        }
        q('UPDATE products SET price = ? WHERE id = ?', [$price, $pid]);
        send_text($chat, '✅ قیمت پلن به‌روز شد.', kb([[admin_back('product_view_' . $pid)]]));
    } elseif ($input === 'stock_address' || $input === 'stock_name' || $input === 'stock_pass' || str_starts_with($input, 'stock_rename:')) {
        admin_stock_input($input, $uid, $chat, $text);
    } elseif ($input === 'user_search') {
        $q = ltrim(trim($text), '@');
        $found = ctype_digit($q) ? one('SELECT user_id, first_name FROM users WHERE user_id = ?', [(int) $q])
            : one('SELECT user_id, first_name FROM users WHERE username = ?', [$q]);
        if ($found === null) {
            state_set($uid, 'input', 'user_search');
            send_text($chat, '⚠️ کاربری با این مشخصات پیدا نشد. دوباره ارسال کنید یا انصراف دهید.', kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_users')]));
            return;
        }
        send_text($chat, '✅ <b>کاربر پیدا شد</b>' . "
" . RULE . "
" .
            h((string) $found['first_name']) . ' · <code>' . (int) $found['user_id'] . '</code>',
            kb([[btn('مشاهده‌ی پروفایل', 'user_' . (int) $found['user_id'], '')], admin_back('admin_users')]));
    } elseif ($input === 'bulk_amount') {
        $amount = (int) normalize_digits(str_replace([',', '٬'], '', $text));
        if ($amount === 0 || abs($amount) > 1000000000) {
            state_set($uid, 'input', 'bulk_amount');
            send_text($chat, '⚠️ مبلغ معتبر نیست. عددی غیرصفر و کمتر از ۱٬۰۰۰٬۰۰۰٬۰۰۰ ارسال کنید.', kb([[btn('✖️ انصراف', 'admin_cancel', '')]]));
            return;
        }
        $count = (int) scalar('SELECT COUNT(*) FROM users WHERE blocked = 0');
        send_text($chat, '<b>⚠️ تأیید تغییر گروهی</b>' . "
" . RULE . "
" .
            'مبلغ: <b>' . money($amount) . '</b>' . "
" .
            'تعداد کاربران: <b>' . fa((string) $count) . '</b>' . "

" .
            'کاربران مسدود تغییر نمی‌کنند. آیا تأیید می‌کنید؟',
            kb([[btn('بله، تأیید می‌کنم', 'bulk_yes_' . $amount, '')], admin_back('admin_users')]));
    } elseif ($input === 'svc_search') {
        $id = (int) normalize_digits($text);
        if ($id <= 0) {
            state_set($uid, 'input', 'svc_search');
            send_text($chat, '⚠️ شناسه‌ی عددی معتبر نیست.', kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_services')]));
            return;
        }
        $list = rows('SELECT id, title, status FROM panels WHERE user_id = ? ORDER BY id DESC', [$id]);
        $rows = [];
        foreach ($list as $p) {
            $rows[] = [btn(mb_substr((string) $p['title'], 0, 30), 'apanel_view_' . (int) $p['id'], '')];
        }
        if ($list === []) {
            $rows[] = [btn('این کاربر سرویسی ندارد', 'admin_services', '')];
        }
        $rows[] = admin_back('admin_services');
        send_text($chat, '<b>سرویس‌های کاربر</b>' . "
" . RULE . "
" .
            'شناسه‌ی کاربر: <code>' . $id . '</code>' . "
" .
            'تعداد سرویس: <b>' . fa((string) count($list)) . '</b>', kb($rows));
    } elseif (str_starts_with($input, 'alabel:')) {
        $key = substr($input, 7);
        if (!isset(ADMIN_MENU_LABELS[$key])) {
            send_text($chat, '⚠️ بخش نامعتبر است.', kb([admin_back('admin_alabels')]));
            return;
        }
        if ($text === 'پیش‌فرض') {
            setting_set('alabel_' . $key, '');
            send_text($chat, '✅ نام بخش به حالت پیش‌فرض بازگشت.', kb([admin_back('admin_alabels')]));
            return;
        }
        if (mb_strlen($text) < 2 || mb_strlen($text) > 32 || preg_match('/[
]/', $text) === 1) {
            state_set($uid, 'input', $input);
            send_text($chat, '⚠️ نام باید بین ۲ تا ۳۲ نویسه و در یک سطر باشد.', kb([[btn('انصراف', 'admin_cancel', '')]]));
            return;
        }
        setting_set('alabel_' . $key, $text);
        send_text($chat, '✅ نام بخش ذخیره شد.', kb([admin_back('admin_alabels')]));
    } elseif (str_starts_with($input, 'dur_') || $input === 'dur_new_months') {
        admin_duration_input($input, $uid, $chat, $text);
    } elseif (str_starts_with($input, 'txt:')) {
        admin_text_input(substr($input, 4), $uid, $chat, $text);
    } elseif (str_starts_with($input, 'faqadm_')) {
        admin_faq_input($input, $uid, $chat, $text);
    } elseif ($input === 'referral_reward') {
        $amount = (int) normalize_digits(str_replace([',', '٬'], '', $text));
        if ($amount < 0 || $amount > 100000000) {
            send_text($chat, '⚠️ مبلغ معتبر نیست.', kb([admin_back('admin_store')]));
            return;
        }
        setting_set('referral_reward', (string) $amount);
        send_text($chat, 'مبلغ هر دعوت ' . money($amount) . ' تومان ذخیره شد.', kb([admin_back('admin_store')]));
    } elseif ($input === 'welcome') {
        if ($text === 'پیش‌فرض') {
            q("DELETE FROM settings WHERE k = 'welcome_text'");
        } elseif (mb_strlen($text) < 2 || mb_strlen($text) > 600) {
            send_text($chat, '⚠️ متن باید بین ۲ تا ۶۰۰ حرف باشد.', kb([admin_back('admin_store')]));
            return;
        } else {
            setting_set('welcome_text', $text);
        }
        send_text($chat, '✅ متن خوش‌آمد ذخیره شد.', kb([admin_back('admin_store')]));
    } elseif (str_starts_with($input, 'label:')) {
        $key = substr($input, 6);
        if (!isset(MENU_LABELS[$key])) {
            send_text($chat, '⚠️ دکمه‌ی نامعتبر است.', kb([admin_back('admin_labels')]));
            return;
        }
        if ($text === 'پیش‌فرض') {
            q('DELETE FROM settings WHERE k = ?', ['label_' . $key]);
        } elseif (mb_strlen($text) < 2 || mb_strlen($text) > 32 || strpbrk($text, "\r\n") !== false) {
            send_text($chat, '⚠️ متن دکمه باید بین ۲ تا ۳۲ حرف باشد.', kb([admin_back('admin_labels')]));
            return;
        } else {
            setting_set('label_' . $key, $text);
        }
        send_text($chat, '✅ متن دکمه ذخیره شد.', kb([admin_back('admin_labels')]));
    } elseif (str_starts_with($input, 'balneg:')) {
        $target = (int) substr($input, 7);
        $amount = abs((int) normalize_digits(str_replace([',', '٬'], '', $text)));
        if ($amount === 0 || !adjust_balance($target, -$amount)) {
            state_set($uid, 'input', $input);
            send_text($chat, '⚠️ مبلغ معتبر نیست. عددی بزرگ‌تر از صفر ارسال کنید.', kb([admin_back('user_bal_' . $target)]));
            return;
        }
        send_text($target, 'مبلغ ' . money($amount) . ' تومان از کیف پول شما کسر شد.');
        send_text($chat, '✅ موجودی کاربر کاهش یافت. موجودی جدید: ' . money((int) get_user($target)['balance']) . ' تومان',
            kb([admin_back('user_' . $target)]));
    } elseif (str_starts_with($input, 'bal:')) {
        $target = (int) substr($input, 4);
        $delta = (int) normalize_digits(str_replace([',', '٬'], '', $text));
        if ($delta === 0 || !adjust_balance($target, $delta)) {
            send_text($chat, '⚠️ مبلغ معتبر نیست. برای افزایش عدد مثبت و برای کاهش عدد منفی ارسال کنید.', kb([admin_back('user_' . $target)]));
            return;
        }
        send_text($target, ($delta > 0 ? 'مبلغ ' . money($delta) . ' تومان به کیف پول شما اضافه شد.'
            : 'مبلغ ' . money(-$delta) . ' تومان از کیف پول شما کسر شد.'));
        send_text($chat, '✅ موجودی کاربر به‌روز شد. موجودی جدید: ' .
            money(get_user($target)['balance']) . ' تومان', kb([admin_back('user_' . $target)]));
    } elseif (str_starts_with($input, 'umsg:')) {
        $target = (int) substr($input, 5);
        setting_set('umsgp:' . $uid, $target . '|' . $msgId);
        send_text($chat, '<b>💬 اجازه‌ی پاسخ</b>' . "\n" . RULE . "\n" .
            'آیا کاربر می‌تواند به این پیام پاسخ دهد؟', kb([
            [btn('بله، پاسخ مجاز است', 'umsg_yes', '')],
            [btn('خیر، فقط مشاهده', 'umsg_no', '')],
            [admin_back('user_' . $target)],
        ]));
    } else {
        send_text($chat, '⚠️ ورودی نامعتبر است.', $back);
    }
}

/** مراحل افزودن محصول: plan -> name:N -> price:N:NAME */
function admin_product_step(string $state, int $uid, int $chat, string $text): bool
{
    $cancel = kb([[btn('✖️ انصراف', 'admin_cancel', '')]]);
    if ($state === 'plan') {
        $plan = (int) normalize_digits($text);
        if (!plan_is_active($plan)) {
            send_text($chat, '⚠️ این مدت وجود ندارد یا فعال نیست. یکی از مدت‌های فعال را ارسال کنید: ' . plan_list_text(), $cancel);
            return true;
        }
        state_set($uid, 'product', 'name:' . $plan);
        send_text($chat, 'نام پلن را ارسال کنید. نمونه: ۳۰ روز ۲۰ گیگ', $cancel);
        return true;
    }
    if (str_starts_with($state, 'name:')) {
        state_set($uid, 'product', 'price:' . substr($state, 5) . ':' . $text);
        send_text($chat, 'قیمت را به تومان ارسال کنید.', $cancel);
        return true;
    }
    if (str_starts_with($state, 'price:')) {
        [, $plan, $name] = explode(':', $state, 3);
        $price = (int) normalize_digits(str_replace([',', '٬'], '', $text));
        if ($price <= 0) {
            send_text($chat, '⚠️ قیمت معتبر نیست.', $cancel);
            return true;
        }
        q('INSERT INTO products (plan, name, price, panel_limit, created_at) VALUES (?, ?, ?, 1, ?)',
            [(int) $plan, $name, $price, now()]);
        state_set($uid, 'product', '');
        send_text($chat, 'محصول «' . h($name) . '» اضافه شد.', kb([admin_back('admin_products')]));
        return true;
    }
    return false;
}

/* ------------------------------------------------------------------ دکمه‌های مدیریت */

function admin_callback(string $data, int $uid, int $chat, int $old): bool
{
    if (!admin_allowed($uid, $data)) {
        screen($chat, $old > 0 ? $old : null, '⚠️ این بخش برای سطح دسترسی شما فعال نیست.', kb([admin_back('admin_home')]));
        return true;
    }
    $show = static fn(string $text, ?string $markup) => admin_screen($chat, $old > 0 ? $old : null, $text, $markup);

    if ($data === 'admin_panel') {
        clear_states($uid);
        send_text($chat, admin_info_text());
        $show(admin_home_text(), admin_menu_markup());
        return true;
    }

    if ($data === 'admin_home' || $data === 'admin_cancel') {
        clear_states($uid);
        $show(admin_home_text(), admin_menu_markup());
        return true;
    }

    if ($data === 'admin_stats') {
        $cnt = static fn(string $sql, array $p = []): string => fa((string) (int) scalar($sql, $p));
        $sum = static fn(string $sql, array $p = []): string => money((int) scalar($sql, $p));
        $show('<b>📊 آمار کلی ربات</b>' . "\n" . RULE . "\n\n" .
            '<b>👥 کاربران</b>' . "\n" .
            'کل کاربران: <b>' . $cnt('SELECT COUNT(*) FROM users') . '</b>' . "\n" .
            'کاربران دارای خرید: <b>' . $cnt("SELECT COUNT(DISTINCT user_id) FROM orders WHERE status = 'completed'") . '</b>' . "\n" .
            'کاربران مسدود: <b>' . $cnt('SELECT COUNT(*) FROM users WHERE blocked = 1') . '</b>' . "\n\n" .
            '<b>🛍 فروش و سفارش</b>' . "\n" .
            'سفارش‌های تکمیل‌شده: <b>' . $cnt("SELECT COUNT(*) FROM orders WHERE status = 'completed'") . '</b>' . "\n" .
            'مجموع فروش: <b>' . $sum("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status = 'completed'") . '</b>' . "\n" .
            'فروش امروز: <b>' . $sum("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE()") . '</b>' . "\n" .
            'در انتظار بررسی: <b>' . $cnt("SELECT COUNT(*) FROM orders WHERE status IN ('waiting_admin','waiting_panel_details')") . '</b>' . "\n" .
            'رد یا لغو شده: <b>' . $cnt("SELECT COUNT(*) FROM orders WHERE status IN ('rejected','cancelled')") . '</b>' . "\n\n" .
            '<b>💰 امور مالی</b>' . "\n" .
            'موجودی کل کاربران: <b>' . $sum('SELECT COALESCE(SUM(balance),0) FROM users') . '</b>' . "\n" .
            'شارژ تأییدشده: <b>' . $sum("SELECT COALESCE(SUM(amount),0) FROM wallet_charges WHERE status = 'approved'") . '</b>' . "\n" .
            'پرداخت آنلاین موفق: <b>' . $sum("SELECT COALESCE(SUM(amount),0) FROM gateway_payments WHERE status = 'paid'") . '</b>' . "\n" .
            'شارژ در انتظار: <b>' . $cnt("SELECT COUNT(*) FROM wallet_charges WHERE status = 'waiting_admin'") . '</b>' . "\n\n" .
            '<b>📦 پنل‌ها و سرویس‌ها</b>' . "\n" .
            'پنل آماده‌ی فروش: <b>' . $cnt("SELECT COUNT(*) FROM panel_stock WHERE status = 'available'") . '</b>' . "\n" .
            'پنل فروخته‌شده: <b>' . $cnt("SELECT COUNT(*) FROM panel_stock WHERE status = 'sold'") . '</b>' . "\n" .
            'سرویس‌های فعال کاربران: <b>' . $cnt("SELECT COUNT(*) FROM panels WHERE status = 'active'") . '</b>' . "\n\n" .
            '<b>🎁 تخفیف، تست و دعوت</b>' . "\n" .
            'کدهای تخفیف فعال: <b>' . $cnt("SELECT COUNT(*) FROM discount_codes WHERE active = 1") . '</b>' . "\n" .
            'دفعات استفاده از کدها: <b>' . $cnt('SELECT COALESCE(SUM(used_count),0) FROM discount_codes') . '</b>' . "\n" .
            'درخواست تست منتظر: <b>' . $cnt("SELECT COUNT(*) FROM test_requests WHERE status = 'waiting'") . '</b>' . "\n" .
            'دعوت موفق: <b>' . $cnt('SELECT COUNT(*) FROM referrals') . '</b>' . "\n" .
            'پاداش دعوت پرداخت‌شده: <b>' . $sum('SELECT COALESCE(SUM(reward),0) FROM referrals') . '</b>' . "\n\n" .
            '<b>🎧 پشتیبانی</b>' . "\n" .
            'تیکت‌های باز: <b>' . $cnt("SELECT COUNT(*) FROM tickets WHERE status IN ('open','answered')") . '</b>' . "\n\n" .
            '<b>🗓 آمار دیروز</b>' . "\n" .
            'کاربران جدید: <b>' . $cnt('SELECT COUNT(*) FROM users WHERE DATE(joined_at) = CURDATE() - INTERVAL 1 DAY') . '</b>' . "\n" .
            'سفارش‌های تکمیل‌شده: <b>' . $cnt("SELECT COUNT(*) FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE() - INTERVAL 1 DAY") . '</b>' . "\n" .
            'فروش: <b>' . $sum("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE() - INTERVAL 1 DAY") . '</b>' . "\n" .
            'شارژ کیف پول تأییدشده: <b>' . $sum("SELECT COALESCE(SUM(amount),0) FROM wallet_charges WHERE status = 'approved' AND DATE(created_at) = CURDATE() - INTERVAL 1 DAY") . '</b>' . "\n" .
            'پرداخت آنلاین موفق: <b>' . $sum("SELECT COALESCE(SUM(amount),0) FROM gateway_payments WHERE status = 'paid' AND DATE(created_at) = CURDATE() - INTERVAL 1 DAY") . '</b>' . "\n" .
            'درخواست تست رایگان: <b>' . $cnt("SELECT COUNT(*) FROM test_requests WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY") . '</b>' . "\n" .
            'دعوت موفق: <b>' . $cnt("SELECT COUNT(*) FROM referrals WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY") . '</b>' . "\n\n" .
            '<b>🧩 امکانات ربات</b>' . "\n" .
            '✅ خرید از کیف پول با تحویل خودکار پنل' . "\n" .
            '✅ خرید کارت به کارت با تأیید مدیر' . "\n" .
            '✅ درگاه پرداخت زرین‌پال' . "\n" .
            '✅ کد تخفیف با انقضا و سقف استفاده' . "\n" .
            '✅ مینی‌اپ با پنل کاربری' . "\n" .
            '✅ تست رایگان و دعوت دوستان' . "\n" .
            '✅ تیکت پشتیبانی و ارسال پیام به کاربر' . "\n" .
            '✅ ارسال همگانی با پیشرفت و لغو' . "\n" .
            '✅ مرکز گزارش‌ها با تاپیک' . "\n" .
            '✅ مدیریت کاربران، مسدودسازی و تغییر موجودی' . "\n" .
            '✅ مدیریت پنل‌ها، سطح دسترسی مدیران' . "\n" .
            '⏳ اتصال خودکار به API پنل‌ها' . "\n" .
            '✖️ تمدید خودکار و تاریخ انقضای سرویس' . "\n" .
            '✖️ سیستم نمایندگی و سطح‌بندی فروشندگان',
            kb([admin_back('admin_home')]));
        return true;
    }


    if ($data === 'admin_pending') {
        $lines = [' <b>در انتظار بررسی</b>', ''];
        $rows = [];
        foreach (rows("SELECT * FROM wallet_charges WHERE status = 'waiting_admin' ORDER BY created_at LIMIT 10") as $c) {
            $lines[] = 'شارژ ' . money($c['amount']) . ' تومان | کاربر ' . $c['user_id'];
            $rows[] = [btn('✅ تأیید شارژ', 'wallet_approve_' . $c['id'], ''), btn('❌ رد', 'wallet_reject_' . $c['id'], '')];
        }
        foreach (rows("SELECT o.*, p.name AS pname FROM orders o LEFT JOIN products p ON p.id = o.product_id
                       WHERE o.status IN ('waiting_admin','waiting_panel_details') ORDER BY o.created_at LIMIT 10") as $o) {
            $lines[] = '' . h($o['pname'] ?? 'محصول') . ' | ' . money($o['amount']) . ' تومان | کاربر ' . $o['user_id'];
            $rows[] = [btn('تأیید و تحویل', 'order_reply_' . $o['id'], ''), btn('❌ رد', 'order_reject_' . $o['id'], '')];
        }
        if (count($lines) === 2) {
            $lines[] = 'موردی برای بررسی وجود ندارد.';
        }
        $rows[] = admin_back('admin_shop');
        $show(implode("\n", $lines), kb($rows));
        return true;
    }

    if ($data === 'admin_users' || preg_match('/^users_(\d+)$/', $data, $mm) === 1) {
        $page = isset($mm[1]) ? (int) $mm[1] : 0;
        $all = rows('SELECT user_id, first_name, username FROM users ORDER BY joined_at DESC, user_id DESC');
        [$items, $page, $pages] = paginate($all, $page, USERS_PER_PAGE);
        $rows = [[btn('شناسه', 'users_' . $page, ''), btn('نام کاربری', 'users_' . $page, ''), btn('عملیات', 'users_' . $page, '')]];
        foreach ($items as $u) {
            $uid_ = (int) $u['user_id'];
            $name = $u['username'] ? '@' . $u['username'] : mb_substr((string) ($u['first_name'] ?: 'بی‌نام'), 0, 16);
            $rows[] = [btn((string) $uid_, 'user_' . $uid_, ''), btn($name, 'user_' . $uid_, ''), btn('⚙️ مدیریت', 'user_' . $uid_, '')];
        }
        $nav = page_row('users_', $page, $pages);
        if ($nav !== []) {
            $rows[] = $nav;
        }
        $rows[] = [btn('🔍 جستجو با شناسه', 'users_search', ''), btn('🚫 کاربران مسدود', 'users_blocked_0', '')];
        $rows[] = [btn('💳 تغییر موجودی همه‌ی کاربران', 'users_bulk', '')];
        $rows[] = admin_back('admin_home');
        $show('<b>👥 کاربران</b>' . "\nتعداد کل: " . fa((string) count($all)) . "\nصفحه " .
            fa((string) ($page + 1)) . 'از ' . fa((string) $pages), kb($rows));
        return true;
    }

    if (preg_match('/^user_(\d+)$/', $data, $mm) === 1) {
        $target = (int) $mm[1];
        $u = get_user($target);
        if ($u === null) {
            $show('⚠️ کاربر پیدا نشد.', kb([admin_back('admin_users')]));
            return true;
        }
        $orders = (int) scalar('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$target]);
        $completed = (int) scalar("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'completed'", [$target]);
        $spent = (int) scalar("SELECT COALESCE(SUM(amount),0) FROM orders WHERE user_id = ? AND status = 'completed'", [$target]);
        $active = (int) scalar("SELECT COUNT(*) FROM panels WHERE user_id = ? AND status = 'active'", [$target]);
        $refs = (int) scalar('SELECT COUNT(*) FROM referrals WHERE referrer_id = ?', [$target]);
        $blocked = (int) $u['blocked'] === 1;
        $show('<b>👤 ' . h((string) $u['first_name']) . '</b>' . "\n" . RULE . "\n" .
            'شناسه‌ی عددی: <code>' . $target . '</code>' . "\n" .
            'نام کاربری: ' . ($u['username'] ? '@' . h((string) $u['username']) : 'ثبت نشده') . "\n" .
            'وضعیت: <b>' . ($blocked ? '🚫 مسدود' : '🟢 فعال') . '</b>' . "\n" .
            'تاریخ عضویت: ' . h((string) $u['joined_at']) . "\n\n" .
            '<b>💰 مالی</b>' . "\n" .
            'موجودی کیف پول: <b>' . money((int) $u['balance']) . '</b>' . "\n" .
            'مجموع خرید: <b>' . money($spent) . '</b>' . "\n" .
            'سفارش‌ها: ' . fa((string) $orders) . ' (تکمیل‌شده: ' . fa((string) $completed) . ')' . "\n\n" .
            '<b>📦 سرویس و دعوت</b>' . "\n" .
            'سرویس‌های فعال: ' . fa((string) $active) . "\n" .
            'تست رایگان استفاده‌شده: ' . fa((string) (int) $u['test_count']) . "\n" .
            'دعوت‌های موفق: ' . fa((string) $refs),
            kb([
                [btn('🧾 سفارش‌ها', 'user_orders_' . $target, ''), btn('📦 سرویس‌ها', 'user_panels_' . $target, '')],
                [btn('💳 تغییر موجودی', 'user_bal_' . $target, ''), btn('✉️ ارسال پیام', 'user_msg_' . $target, '')],
                [$blocked ? btn('✅ رفع مسدودی', 'user_unblock_' . $target, '') : btn('🚫 مسدود کردن', 'user_block_' . $target, '')],
                [btn('♻️ بازنشانی تست رایگان', 'user_resettest_' . $target, '')],
                admin_back('admin_users'),
            ]));
        return true;
    }

    if (preg_match('/^user_resettest_(\d+)$/', $data, $mm) === 1) {
        q('UPDATE users SET test_count = 0 WHERE user_id = ?', [(int) $mm[1]]);
        $show('✅ شمارنده‌ی تست رایگان این کاربر بازنشانی شد.', kb([admin_back('user_' . (int) $mm[1])]));
        return true;
    }

    if (preg_match('/^user_bal_(\d+)$/', $data, $mm) === 1) {
        $target = (int) $mm[1];
        $show('<b>💳 تغییر موجودی کاربر</b>' . "\n" . RULE . "\n" .
            'نوع تغییر را انتخاب کنید.',
            kb([
                [btn('➕ افزایش موجودی', 'user_balplus_' . $target, ''), btn('➖ کاهش موجودی', 'user_balminus_' . $target, '')],
                admin_back('user_' . $target),
            ]));
        return true;
    }

    if (preg_match('/^user_balplus_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'input', 'bal:' . (int) $mm[1]);
        $show('<b>➕ افزایش موجودی</b>' . "\n" . RULE . "\n" .
            'مبلغ افزایش را به تومان ارسال کنید. نمونه: 50000',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('user_bal_' . (int) $mm[1])]));
        return true;
    }

    if (preg_match('/^user_balminus_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'input', 'balneg:' . (int) $mm[1]);
        $show('<b>➖ کاهش موجودی</b>' . "\n" . RULE . "\n" .
            'مبلغ کاهش را به تومان ارسال کنید. نمونه: 50000',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('user_bal_' . (int) $mm[1])]));
        return true;
    }


    if (preg_match('/^user_msg_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'input', 'umsg:' . (int) $mm[1]);
        $show('پیام خود را برای کاربر ارسال کنید (متن، عکس یا فایل).',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('user_' . (int) $mm[1])]));
        return true;
    }

    if (preg_match('/^user_orders_(\d+)$/', $data, $mm) === 1) {
        $target = (int) $mm[1];
        $lines = ['<b>سفارش‌های کاربر ' . $target . '</b>', ''];
        foreach (user_orders($target) as $o) {
            $lines[] = '' . h($o['product_name'] ?? 'محصول') . ' | ' . money($o['amount']) . ' تومان | ' . h($o['status']);
        }
        if (count($lines) === 2) {
            $lines[] = 'سفارشی ثبت نشده است.';
        }
        $show(implode("\n", $lines), kb([admin_back('user_' . $target)]));
        return true;
    }

    if (preg_match('/^user_panels_(\d+)$/', $data, $mm) === 1) {
        $target = (int) $mm[1];
        $lines = ['<b>پنل‌های کاربر ' . $target . '</b>', ''];
        foreach (rows('SELECT * FROM panels WHERE user_id = ? ORDER BY id DESC', [$target]) as $p) {
            $lines[] = '' . h($p['title']) . ' | ' . h($p['status']) . "\n<code>" . h($p['details']) . '</code>';
        }
        if (count($lines) === 2) {
            $lines[] = 'پنلی ثبت نشده است.';
        }
        $show(implode("\n", $lines), kb([admin_back('user_' . $target)]));
        return true;
    }

    if ($data === 'admin_products') {
        $show('<b>📦 مدیریت پلن‌ها</b>', kb([
            [btn('➕ افزودن پلن', 'product_add', ''), btn('📋 فهرست و حذف', 'product_list', '')],
            admin_back('admin_shop'),
        ]));
        return true;
    }

    if ($data === 'product_add') {
        state_set($uid, 'product', 'plan');
        $show('مدت پلن را انتخاب کنید (ماه) از میان: ' . plan_list_text(),
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_products')]));
        return true;
    }

    if ($data === 'product_list') {
        $rows = [];
        $lines = ['<b>📦 پلن‌ها</b>', ''];
        foreach (rows('SELECT * FROM products ORDER BY plan, price') as $p) {
            $lines[] = '' . $p['id'] . ' | ' . fa((string) $p['plan']) . 'ماهه | ' . h($p['name']) . ' | ' . money($p['price']) . ' تومان';
            $rows[] = [btn((string) $p['name'] . ' · ' . money($p['price']) . ' تومان', 'product_view_' . $p['id'], '')];
        }
        if (count($lines) === 2) {
            $lines[] = 'پلنی ثبت نشده است.';
        }
        $rows[] = admin_back('admin_products');
        $show(implode("\n", $lines), kb($rows));
        return true;
    }

    if (preg_match('/^product_delete_(\d+)$/', $data, $mm) === 1) {
        q('DELETE FROM products WHERE id = ?', [(int) $mm[1]]);
        $show('✅ پلن حذف شد.', kb([admin_back('admin_products')]));
        return true;
    }

    if ($data === 'admin_finance') {
        $sales = (int) scalar("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status = 'completed'");
        $today = (int) scalar("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE()");
        $balances = (int) scalar('SELECT COALESCE(SUM(balance),0) FROM users');
        $online = (int) scalar("SELECT COALESCE(SUM(amount),0) FROM gateway_payments WHERE status = 'paid'");
        $pendCharge = (int) scalar("SELECT COUNT(*) FROM wallet_charges WHERE status = 'waiting_admin'");
        $pendOrder = (int) scalar("SELECT COUNT(*) FROM orders WHERE status = 'waiting_admin'");
        $show('<b>💰 امور مالی</b>' . "\n" . RULE . "\n" .
            'کل فروش: <b>' . money($sales) . ' تومان</b>' . "\n" .
            'فروش امروز: <b>' . money($today) . ' تومان</b>' . "\n" .
            'موجودی کاربران: <b>' . money($balances) . ' تومان</b>' . "\n" .
            'پرداخت آنلاین موفق: <b>' . money($online) . ' تومان</b>' . "\n" .
            'در انتظار تأیید: ' . $pendCharge . 'شارژ و ' . $pendOrder . 'سفارش',
            kb([
                [btn('در انتظار تأیید', 'admin_pending', '')],
                [btn('💳 کارت پرداخت', 'fin_card', ''), btn('🏦 درگاه زرین‌پال', 'fin_gateway', '')],
                [btn('📈 گزارش فروش', 'fin_sales', ''), btn('📊 آمار کلی', 'admin_stats', '')],
                [admin_back('admin_home')],
            ]));
        return true;
    }

    if ($data === 'fin_card') {
        $p = payment_info();
        $show('<b>💳 کارت پرداخت</b>' . "\n" . RULE . "\n" .
            'شماره‌ی کارت: <code>' . h($p['card']) . '</code>' . "\n" .
            'به نام: <b>' . h($p['owner']) . '</b>',
            kb([
                [btn('تغییر شماره‌ی کارت', 'set_card', '')],
                [btn('تغییر نام صاحب کارت', 'set_owner', '')],
                [admin_back('admin_finance')],
            ]));
        return true;
    }

    if ($data === 'fin_gateway') {
        $enabled = setting_get('zarin_enabled', '0') === '1';
        $merchant = setting_get('zarin_merchant', '');
        $masked = $merchant === '' ? 'ثبت نشده' : substr($merchant, 0, 8) . '…' . substr($merchant, -4);
        $show('<b>🏦 درگاه زرین‌پال</b>' . "\n" . RULE . "\n" .
            'وضعیت: <b>' . ($enabled ? 'فعال' : 'غیرفعال') . '</b>' . "\n" .
            'کد پذیرنده: <code>' . h($masked) . '</code>' . "\n\n" .
            'با فعال بودن درگاه، در شارژ کیف پول دکمه‌ی پرداخت آنلاین نمایش داده می‌شود.',
            kb([
                [btn('کد پذیرنده', 'zarin_merchant', '')],
                [btn($enabled ? 'غیرفعال کردن درگاه' : 'فعال کردن درگاه', 'zarin_toggle', '')],
                [admin_back('admin_finance')],
            ]));
        return true;
    }

    if ($data === 'zarin_toggle') {
        if (setting_get('zarin_merchant', '') === '') {
            $show('⚠️ ابتدا کد پذیرنده‌ی زرین‌پال را ثبت کنید.', kb([[admin_back('fin_gateway')]]));
            return true;
        }
        setting_set('zarin_enabled', setting_get('zarin_enabled', '0') === '1' ? '0' : '1');
        return admin_callback('fin_gateway', $uid, $chat, $old);
    }

    if ($data === 'zarin_merchant') {
        state_set($uid, 'input', 'zarin_merchant');
        $show('کد پذیرنده‌ی زرین‌پال (Merchant ID) را ارسال کنید.',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], [admin_back('fin_gateway')]]));
        return true;
    }

    if ($data === 'fin_sales') {
        $lines = ['<b>📈 گزارش فروش</b>', RULE];
        foreach (rows("SELECT o.*, p.name AS pname FROM orders o LEFT JOIN products p ON p.id = o.product_id
                       WHERE o.status = 'completed' ORDER BY o.created_at DESC LIMIT 10") as $o) {
            $lines[] = h($o['created_at']) . ' | ' . h($o['pname'] ?? 'پلن') . ' | ' . money($o['amount']) . ' تومان';
        }
        if (count($lines) === 2) {
            $lines[] = 'فروشی ثبت نشده است.';
        }
        $show(implode("\n", $lines), kb([[admin_back('admin_finance')]]));
        return true;
    }

    if ($data === 'set_card' || $data === 'set_owner') {
        $input = $data === 'set_card' ? 'card' : 'owner';
        state_set($uid, 'input', $input);
        $show($data === 'set_card' ? 'شماره‌ی کارت جدید را ارسال کنید.' : 'نام صاحب کارت را ارسال کنید.',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('fin_card')]));
        return true;
    }

    if ($data === 'admin_test') {
        $on = test_enabled();
        $show('<b>🎁 تست رایگان</b>' . "\n" . RULE . "\n" .
            'وضعیت: <b>' . ($on ? 'فعال' : 'غیرفعال') . '</b>' . "\n" .
            'سقف هر کاربر: <b>' . fa((string) test_limit()) . '</b>' . "\n\n" .
            ($on ? 'با غیرفعال کردن، دکمه‌ی تست از منوی کاربران و مینی‌اپ حذف می‌شود.'
                 : 'دکمه‌ی تست برای کاربران پنهان است و درخواست جدیدی پذیرفته نمی‌شود.'),
            kb([
                [btn($on ? '⏸ غیرفعال کردن تست' : '▶️ فعال کردن تست', 'test_toggle', $on ? 'danger' : 'success')],
                [btn('تغییر سقف تست', 'set_test_limit', '')],
                admin_back('admin_support'),
            ]));
        return true;
    }

    if ($data === 'test_toggle') {
        setting_set('test_enabled', test_enabled() ? '0' : '1');
        return admin_callback('admin_test', $uid, $chat, $old);
    }

    if ($data === 'set_test_limit') {
        state_set($uid, 'input', 'test_limit');
        $show('سقف تست رایگان را به عدد ارسال کنید.', kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_test')]));
        return true;
    }

    if ($data === 'admin_force') {
        $rows = [[btn('➕ افزودن کانال', 'force_add', '')]];
        $lines = [' <b>📢 عضویت اجباری</b>', ''];
        foreach (rows('SELECT id, channel FROM force_join ORDER BY id') as $c) {
            $lines[] = '• ' . h($c['channel']);
            $rows[] = [btn('حذف ' . $c['channel'], 'force_delete_' . $c['id'], '')];
        }
        if (count($lines) === 2) {
            $lines[] = 'کانالی ثبت نشده است.';
        }
        $rows[] = admin_back('admin_shop');
        $show(implode("\n", $lines), kb($rows));
        return true;
    }

    if ($data === 'force_add') {
        state_set($uid, 'input', 'force_add');
        $show('نام کاربری کانال را ارسال کنید. نمونه: <code>@your_channel</code>',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_force')]));
        return true;
    }

    if (preg_match('/^force_delete_(\d+)$/', $data, $mm) === 1) {
        q('DELETE FROM force_join WHERE id = ?', [(int) $mm[1]]);
        $show('✅ کانال حذف شد.', kb([admin_back('admin_force')]));
        return true;
    }

    if ($data === 'admin_admins') {
        $rows = [[btn('➕ افزودن مدیر', 'admin_add', '')]];
        $lines = [' <b>👑 مدیران</b>', '• <code>' . cfg('admin_id') . '</code> (مدیر اصلی)'];
        foreach (rows('SELECT user_id, role FROM admins') as $a) {
            $roleLabel = ['full' => 'دسترسی کامل', 'finance' => 'مالی', 'support' => 'پشتیبانی'][$a['role']] ?? $a['role'];
            $lines[] = '• <code>' . $a['user_id'] . '</code> — ' . $roleLabel;
            $rows[] = [btn('حذف ' . $a['user_id'], 'admin_remove_' . $a['user_id'], '')];
        }
        $rows[] = admin_back('admin_store');
        $show(implode("\n", $lines), kb($rows));
        return true;
    }

    if ($data === 'admin_add') {
        state_set($uid, 'input', 'add_admin');
        $show('شناسه‌ی عددی تلگرام کاربر را ارسال کنید. کاربر باید ربات را راه‌اندازی کرده باشد (/start).',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_admins')]));
        return true;
    }

    if (preg_match('/^admin_remove_(\d+)$/', $data, $mm) === 1) {
        q('DELETE FROM admins WHERE user_id = ?', [(int) $mm[1]]);
        $show('✅ دسترسی مدیر حذف شد.', kb([admin_back('admin_admins')]));
        return true;
    }

    if ($data === 'admin_tickets') {
        $rows = [];
        $lines = ['<b>🎧 تیکت‌های فعال</b>', ''];
        foreach (rows("SELECT * FROM tickets WHERE status IN ('open','answered') ORDER BY id DESC LIMIT 15") as $t) {
            $lines[] = '#' . $t['id'] . ' • کاربر <code>' . $t['user_id'] . '</code> • ' . h(mb_substr((string) $t['message'], 0, 50));
            $rows[] = [btn('پاسخ #' . $t['id'], 'ticket_reply_' . $t['id'], ''),
                btn('بستن', 'ticket_close_' . $t['id'], '')];
        }
        if (count($lines) === 2) {
            $lines[] = 'تیکت باز وجود ندارد.';
        }
        $rows[] = admin_back('admin_support');
        $show(implode("\n", $lines), kb($rows));
        return true;
    }

    if (preg_match('/^ticket_reply_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'ticket_reply', $mm[1]);
        $show('پاسخ تیکت #' . $mm[1] . 'را ارسال کنید (متن، عکس یا فایل).',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_support')]));
        return true;
    }

    if (preg_match('/^ticket_close_(\d+)$/', $data, $mm) === 1) {
        $t = one('SELECT * FROM tickets WHERE id = ?', [(int) $mm[1]]);
        if ($t !== null) {
            q("UPDATE tickets SET status = 'closed', updated_at = ? WHERE id = ?", [now(), (int) $mm[1]]);
            send_text((int) $t['user_id'], 'تیکت <b>#' . $mm[1] . '</b> توسط مدیریت بسته شد.');
        }
        $show('✅ تیکت بسته شد.', kb([admin_back('admin_support')]));
        return true;
    }

    if ($data === 'admin_broadcast') {
        $show(' <b>📣 ارسال همگانی</b>' . "\n\nابتدا دکمه‌ی همراه پیام را انتخاب کنید:", kb([
            [btn('دکمه‌ی خرید', 'bc_buy', ''), btn('دکمه‌ی دعوت', 'bc_referral', '')],
            [btn('دکمه‌ی کیف پول', 'bc_wallet', ''), btn('بدون دکمه', 'bc_none', '')],
            admin_back('admin_shop'),
        ]));
        return true;
    }

    if (preg_match('/^bc_(buy|referral|wallet|none)$/', $data, $mm) === 1) {
        state_set($uid, 'broadcast', $mm[1]);
        $show('پیام همگانی را ارسال کنید (متن، عکس، ویدیو یا فایل).' . "\nپیام به همه‌ی کاربران ارسال می‌شود.",
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_shop')]));
        return true;
    }

    if ($data === 'admin_colors') {
        $rows = [];
        foreach (COLOR_CATEGORIES as $key => $label) {
            $rows[] = [btn($label . ': ' . COLOR_OPTIONS[color_of($key)], 'color_' . $key, '')];
        }
        $rows[] = admin_back('admin_store');
        $show('<b>🎨 رنگ دکمه‌ها</b>' . "\n" . RULE . "\n" .
            'برای هر بخش رنگ دلخواه را انتخاب کنید. «پیش‌فرض تلگرام» یعنی بدون رنگ خاص.', kb($rows));
        return true;
    }

    if (preg_match('/^color_([a-z]+)$/', $data, $mm) === 1 && isset(COLOR_CATEGORIES[$mm[1]])) {
        $cat = $mm[1];
        $opt = static fn(string $key, string $style): array => btn(COLOR_OPTIONS[$key], 'setcolor_' . $cat . '_' . $key, $style);
        $show('رنگ «' . COLOR_CATEGORIES[$cat] . '»' . "\n" . RULE . "\n" .
            'رنگ فعلی: <b>' . COLOR_OPTIONS[color_of($cat)] . '</b>', kb([
            [$opt('primary', 'primary'), $opt('success', 'success')],
            [$opt('danger', 'danger'), $opt('default', '')],
            admin_back('admin_store'),
        ]));
        return true;
    }

    if (preg_match('/^setcolor_([a-z]+)_(primary|success|danger|default)$/', $data, $mm) === 1
        && isset(COLOR_CATEGORIES[$mm[1]])) {
        setting_set('color_' . $mm[1], $mm[2]);
        $show('✅ رنگ ذخیره شد.', kb([admin_back('admin_store')]));
        return true;
    }

    if ($data === 'admin_layout') {
        $keyboard = menu_style() === 'keyboard';
        $show('<b>⌨️ چیدمان دکمه‌ها</b>' . "\n" . RULE . "\n" .
            '<b>شیشه‌ای:</b> دکمه‌ها زیر پیام قرار می‌گیرند و با هر صفحه تغییر می‌کنند.' . "\n" .
            '<b>کیبورد:</b> دکمه‌ها در بخش پایین صفحه‌ی گفتگو ثابت می‌مانند.' . "\n\n" .
            'حالت فعلی: <b>' . ($keyboard ? 'کیبورد پایین صفحه' : 'شیشه‌ای (زیر پیام)') . '</b>',
            kb([
                [btn('شیشه‌ای (زیر پیام)', 'layout_inline', $keyboard ? 'primary' : 'success')],
                [btn('کیبورد پایین صفحه', 'layout_keyboard', $keyboard ? 'success' : 'primary')],
                admin_back('admin_store'),
            ]));
        return true;
    }

    if ($data === 'layout_inline' || $data === 'layout_keyboard') {
        setting_set('menu_style', $data === 'layout_keyboard' ? 'keyboard' : 'inline');
        $show('✅ چیدمان ذخیره شد. برای مشاهده‌ی تغییر، دستور /start را در ربات ارسال کنید.', kb([admin_back('admin_store')]));
        return true;
    }

    if ($data === 'admin_reports') {
        $chatId = report_chat();
        $created = 0;
        foreach (array_keys(REPORT_TOPICS) as $cat) {
            if (setting_get('report_topic2_' . $cat, '') !== '') {
                $created++;
            }
        }
        $show('<b>📑 مرکز گزارش‌ها</b>' . "\n━━━━━━━━━━━━━━━━━━━━\n" .
            'گروه: ' . ($chatId !== '' ? '<code>' . h($chatId) . '</code>' : 'تنظیم نشده') . "\n" .
            'تاپیک‌های ساخته‌شده: ' . fa((string) $created) . 'از ' . fa((string) count(REPORT_TOPICS)) . "\n\n" .
            'گروه باید <b>سوپرگروه با قابلیت تاپیک</b> باشد و ربات در آن با دسترسی مدیریت تاپیک‌ها، مدیر باشد.',
            kb([
                [btn('⚙️ تنظیم گروه گزارش', 'report_set', '')],
                [btn('🧩 ساخت یا بازسازی تاپیک‌ها', 'report_create', ''), btn('♻️ بازنشانی تاپیک‌ها', 'report_reset', '')],
                [btn('🗑 حذف تاپیک‌های قدیمی', 'reports_oldclean', '')],
                admin_back('admin_store'),
            ]));
        return true;
    }

    if ($data === 'report_set') {
        state_set($uid, 'input', 'report_chat');
        $show('شناسه‌ی عددی سوپرگروه را ارسال کنید. نمونه: -1001234567890',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_reports')]));
        return true;
    }

    if ($data === 'report_create') {
        if (report_chat() === '') {
            $show('⚠️ ابتدا گروه گزارش را تنظیم کنید.', kb([admin_back('admin_reports')]));
            return true;
        }
        foreach (array_keys(REPORT_TOPICS) as $cat) {
            setting_set('report_topic2_' . $cat, '');
            ensure_topic($cat);
        }
        $show('✅ تاپیک‌ها ساخته شدند.', kb([admin_back('admin_reports')]));
        return true;
    }

    if ($data === 'report_reset') {
        foreach (array_keys(REPORT_TOPICS) as $cat) {
            setting_set('report_topic2_' . $cat, '');
        }
        setting_set('report_chat_id', '');
        $show('✅ تنظیمات گزارش پاک شد.', kb([admin_back('admin_reports')]));
        return true;
    }

    if ($data === 'admin_store') {
        $welcome = setting_get('welcome_text', '');
        $show('<b>⚙️ تنظیمات فروشگاه</b>' . "\n" . RULE . "\n" .
            'نام فروشگاه: <b>' . h(store_name()) . '</b>' . "\n" .
            'مبلغ هر دعوت: <b>' . money(referral_reward()) . ' تومان</b>' . "\n" .
            'متن خوش‌آمد: ' . ($welcome !== '' ? 'سفارشی' : 'پیش‌فرض'),
            kb([
                [btn('🏪 نام فروشگاه', 'store_name', '')],
                [btn('🎁 مبلغ هر دعوت', 'referral_set', '')],
                [btn('👋 متن خوش‌آمد', 'admin_welcome', ''), btn('🔤 متن دکمه‌ها', 'admin_labels', '')],
                [btn('⌨️ چیدمان دکمه‌ها', 'admin_layout', ''), btn('🎨 رنگ دکمه‌ها', 'admin_colors', '')],
                [btn('📑 مرکز گزارش‌ها', 'admin_reports', ''), btn('👑 مدیران و دسترسی', 'admin_admins', '')],
                [btn('🧭 نام بخش‌های مدیریت', 'admin_alabels', '')],
                [btn('📦 پشتیبان‌گیری', 'admin_backup', '')],
                [btn('✏️ ویرایش متن‌های ربات', 'admin_texts', '')],
                [admin_back('admin_home')],
            ]));
        return true;
    }

    if ($data === 'referral_set') {
        state_set($uid, 'input', 'referral_reward');
        $show('<b>🎁 مبلغ هر دعوت</b>' . "\n" . RULE . "\n" .
            'مبلغ پاداش هر دعوت موفق را به تومان ارسال کنید. نمونه: 10,000',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], [admin_back('admin_store')]]));
        return true;
    }

    if ($data === 'admin_welcome') {
        state_set($uid, 'input', 'welcome');
        $show('<b>👋 متن خوش‌آمد</b>' . "\n" . RULE . "\n" .
            'متن جدید را ارسال کنید. برای نام کاربر از {name} و برای نام فروشگاه از {store} استفاده کنید.' . "\n" .
            'برای بازگشت به متن پیش‌فرض، عبارت «پیش‌فرض» را ارسال کنید.',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_store')]));
        return true;
    }

    if ($data === 'admin_labels') {
        $rows = [];
        foreach (MENU_LABELS as $key => $default) {
            $rows[] = [btn(label_of($key), 'label_' . $key, '')];
        }
        $rows[] = admin_back('admin_store');
        $show('<b>🔤 متن دکمه‌های منو</b>' . "\n" . RULE . "\n" .
            'دکمه‌ی موردنظر را انتخاب کنید تا متن آن تغییر کند.', kb($rows));
        return true;
    }

    if (preg_match('/^label_([a-z]+)$/', $data, $mm) === 1 && isset(MENU_LABELS[$mm[1]])) {
        state_set($uid, 'input', 'label:' . $mm[1]);
        $show('<b>' . h(MENU_LABELS[$mm[1]]) . '</b>' . "\n" . RULE . "\n" .
            'متن جدید دکمه را ارسال کنید (۲ تا ۳۲ حرف). برای بازگشت به حالت پیش‌فرض، «پیش‌فرض» را ارسال کنید.',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_labels')]));
        return true;
    }

    if ($data === 'store_name') {
        state_set($uid, 'input', 'store_name');
        $show('نام جدید فروشگاه را ارسال کنید (۲ تا ۴۰ حرف).',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_store')]));
        return true;
    }

    // تأیید/رد از دکمه‌های پیام‌های ادمین یا صفحه‌ی در انتظار بررسی
    if (preg_match('/^wallet_approve_(.+)$/', $data, $mm) === 1) {
        $show(approve_charge($mm[1]) ? '✅ شارژ تأیید و به کیف پول کاربر اضافه شد.' : '⚠️ این درخواست قبلاً بررسی شده است.',
            kb([admin_back('admin_pending')]));
        return true;
    }

    if (preg_match('/^wallet_reject_(.+)$/', $data, $mm) === 1) {
        $show(reject_charge($mm[1]) ? '❌ شارژ رد شد.' : '⚠️ این درخواست قبلاً بررسی شده است.',
            kb([admin_back('admin_pending')]));
        return true;
    }

    if (preg_match('/^order_reply_(.+)$/', $data, $mm) === 1) {
        $pending = one('SELECT * FROM orders WHERE id = ?', [$mm[1]]);
        if ($pending !== null && in_array($pending['status'], ['waiting_admin', 'waiting_panel_details'], true)) {
            $stock = reserve_stock((int) $pending['product_id'], (int) $pending['user_id'], (string) $pending['id']);
            if ($stock !== null && deliver_stock_order((string) $pending['id'], $stock)) {
                $show('✅ سفارش تحویل شد و پنل به‌صورت خودکار برای مشتری ارسال شد.', kb([admin_back('admin_pending')]));
                return true;
            }
        }
        state_set($uid, 'order_reply', $mm[1]);
        $show('اطلاعات پنل را برای کاربر ارسال کنید (متن یا فایل).',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_pending')]));
        return true;
    }

    if (preg_match('/^order_reject_(.+)$/', $data, $mm) === 1) {
        $show(reject_order($mm[1]) ? '❌ سفارش رد شد.' : '⚠️ این سفارش قبلاً تعیین تکلیف شده است.',
            kb([admin_back('admin_pending')]));
        return true;
    }

    if (preg_match('/^test_reply_(.+)$/', $data, $mm) === 1) {
        state_set($uid, 'test_reply', $mm[1]);
        $show('متن یا فایل تست را ارسال کنید.', kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_home')]));
        return true;
    }

    if (preg_match('/^test_reject_(.+)$/', $data, $mm) === 1) {
        $req = one("SELECT * FROM test_requests WHERE id = ? AND status = 'waiting'", [$mm[1]]);
        if ($req !== null) {
            q("UPDATE test_requests SET status = 'rejected' WHERE id = ?", [$mm[1]]);
            send_text((int) $req['user_id'], 'درخواست تست رایگان شما تأیید نشد.');
        }
        $show('❌ درخواست تست رد شد.', kb([admin_back('admin_home')]));
        return true;
    }

    if ($data === 'admin_support') {
        $open = (int) scalar("SELECT COUNT(*) FROM tickets WHERE status IN ('open','answered')");
        $tests = (int) scalar("SELECT COUNT(*) FROM test_requests WHERE status = 'waiting'");
        $show('<b>🎧 پشتیبانی و تست</b>' . "\n" . RULE . "\n" .
            'تیکت‌های باز: <b>' . $open . '</b>' . "\n" .
            'درخواست تست منتظر: <b>' . $tests . '</b>' . "\n" .
            'سقف تست هر کاربر: <b>' . test_limit() . '</b>',
            kb([
                [btn('🎫 تیکت‌ها', 'admin_tickets', ''), btn('🎁 تست رایگان', 'admin_test', '')],
                [btn('👥 کاربران', 'admin_users', '')],
                [btn('❓ سوالات متداول', 'admin_faqs', '')],
                [admin_back('admin_home')],
            ]));
        return true;
    }

    if ($data === 'admin_shop') {
        $products = (int) scalar('SELECT COUNT(*) FROM products');
        $channels = (int) scalar('SELECT COUNT(*) FROM force_join');
        $waiting = (int) scalar("SELECT COUNT(*) FROM orders WHERE status = 'waiting_admin'");
        $show('<b>🛍 فروشگاه و سفارش</b>' . "\n" . RULE . "\n" .
            'محصولات: <b>' . $products . '</b>' . "\n" .
            'سفارش‌های در انتظار: <b>' . $waiting . '</b>' . "\n" .
            'کانال‌های عضویت اجباری: <b>' . $channels . '</b>',
            kb([
                [btn('📦 پلن‌ها', 'admin_products', ''), btn('⏳ سفارش‌های در انتظار', 'admin_pending', '')],
                [btn('🧾 همه‌ی سفارش‌ها', 'admin_orders', ''), btn('🏷 کدهای تخفیف', 'admin_discounts', '')],
                [btn('📢 عضویت اجباری', 'admin_force', ''), btn('📣 ارسال همگانی', 'admin_broadcast', '')],
                [btn('⏱ تنظیم زمان‌بندی ارسال همگانی', 'bc_setup', '')],
                [btn('📅 مدت‌های خرید', 'admin_plans', '')],
                [admin_back('admin_home')],
            ]));
        return true;
    }

    if (preg_match('/^user_block_(\d+)$/', $data, $mm) === 1) {
        q('UPDATE users SET blocked = 1 WHERE user_id = ?', [(int) $mm[1]]);
        $show('🚫 کاربر مسدود شد.', kb([[admin_back('user_' . (int) $mm[1])]]));
        return true;
    }

    if (preg_match('/^user_unblock_(\d+)$/', $data, $mm) === 1) {
        q('UPDATE users SET blocked = 0 WHERE user_id = ?', [(int) $mm[1]]);
        send_text((int) $mm[1], 'دسترسی شما به ربات دوباره فعال شد.');
        $show('✅ مسدودی کاربر برداشته شد.', kb([[admin_back('user_' . (int) $mm[1])]]));
        return true;
    }

    if (preg_match('/^role_(\d+)_(full|finance|support)$/', $data, $mm) === 1) {
        $aid = (int) $mm[1];
        q('INSERT INTO admins (user_id, added_at, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)',
            [$aid, now(), $mm[2]]);
        send_text($aid, 'دسترسی مدیریت برای شما فعال شد.');
        send_report('users', report_card('🛡', 'مدیر جدید اضافه شد', [report_user_line($aid), 'سطح دسترسی: <b>' . h(admin_role_label($mm[2])) . '</b>']), null, report_markup($aid));
        $show('✅ مدیر با سطح دسترسی تعیین‌شده اضافه شد.', kb([[admin_back('admin_home')]]));
        return true;
    }

    if ($data === 'umsg_yes' || $data === 'umsg_no') {
        $pending = setting_get('umsgp:' . $uid, '');
        if ($pending === '') {
            $show('⚠️ پیام معلقی وجود ندارد.', kb([[admin_back('admin_home')]]));
            return true;
        }
        [$target, $msgId] = array_map('intval', explode('|', $pending));
        $markup = $data === 'umsg_yes' ? kb([[btn('↩️ پاسخ به پیام', 'ureply_' . $uid, '')]]) : null;
        copy_message($target, $chat, $msgId, $markup);
        setting_set('umsgp:' . $uid, '');
        $show($data === 'umsg_yes' ? '✅ پیام با امکان پاسخ برای کاربر ارسال شد.' : '✅ پیام برای کاربر (بدون امکان پاسخ) ارسال شد.',
            kb([[admin_back('user_' . $target)]]));
        return true;
    }

    if ($data === 'admin_panels') {
        $available = (int) scalar("SELECT COUNT(*) FROM panel_stock WHERE status = 'available'");
        $sold = (int) scalar("SELECT COUNT(*) FROM panel_stock WHERE status = 'sold'");
        $show('<b>📦 مدیریت پنل‌ها</b>' . "\n" . RULE . "\n" .
            'پنل‌های آماده‌ی فروش: <b>' . $available . '</b>' . "\n" .
            'پنل‌های فروخته‌شده: <b>' . $sold . '</b>' . "\n\n" .
            'پس از خرید یا تأیید سفارش، پنل متناسب با پلن به‌صورت خودکار برای مشتری ارسال می‌شود.',
            kb([
                [btn('➕ افزودن پنل', 'stock_add', ''), btn('📋 فهرست پنل‌ها', 'stock_list_0', '')],
                [btn('🗑 حذف پنل', 'stock_delpick_0', '')],
                admin_back('admin_home'),
            ]));
        return true;
    }

    if ($data === 'stock_add') {
        clear_states($uid);
        setting_set('stockdraft:' . $uid, '');
        $show('<b>➕ افزودن پنل</b>' . "\n" . RULE . "\nنوع پنل را انتخاب کنید.", kb([
            [btn('پنل دستی (ثبت اطلاعات)', 'stock_type_manual', '')],
            admin_back('admin_panels'),
        ]));
        return true;
    }

    if ($data === 'stock_type_manual') {
        setting_set('stockdraft:' . $uid, json_encode(['kind' => 'manual'], JSON_UNESCAPED_UNICODE));
        state_set($uid, 'input', 'stock_address');
        $show('<b>🌐 آدرس پنل</b>' . "\n" . RULE . "\n" .
            'آدرس پنل را ارسال کنید. نمونه: https://panel.example.com:2053',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('stock_add')]));
        return true;
    }

    if (preg_match('/^stock_list_(\d+)$/', $data, $mm) === 1) {
        [$items, $page, $pages] = paginate(rows('SELECT s.id, s.name, s.status, p.name AS pname
            FROM panel_stock s LEFT JOIN products p ON p.id = s.product_id ORDER BY s.id DESC'), (int) $mm[1], 8);
        $rows = [];
        foreach ($items as $it) {
            $rows[] = [btn(mb_substr((string) $it['name'], 0, 24) . ' · ' . mb_substr((string) ($it['pname'] ?? 'بدون پلن'), 0, 16) .
                ' · ' . ($it['status'] === 'available' ? '🟢 آماده' : '🔴 فروخته شد'), 'stock_view_' . $it['id'], '')];
        }
        if ($items === []) {
            $rows[] = [btn('پنلی ثبت نشده است', 'admin_panels', '')];
        }
        $nav = page_row('stock_list_', $page, $pages);
        if ($nav !== []) {
            $rows[] = $nav;
        }
        $rows[] = admin_back('admin_panels');
        $show('<b>📋 فهرست پنل‌ها</b>' . "\n" . RULE . "\nصفحه " . ($page + 1) . ' از ' . $pages, kb($rows));
        return true;
    }

    if (preg_match('/^stock_view_(\d+)$/', $data, $mm) === 1) {
        $s = one('SELECT s.*, p.name AS pname FROM panel_stock s LEFT JOIN products p ON p.id = s.product_id WHERE s.id = ?', [(int) $mm[1]]);
        if ($s === null) {
            $show('⚠️ این پنل پیدا نشد.', kb([admin_back('stock_list_0')]));
            return true;
        }
        $id = (int) $s['id'];
        $available = $s['status'] === 'available';
        $details = panel_details_html('آدرس پنل: ' . $s['address'] . "\n" . 'نام کاربری: ' . $s['name'] . "\n" . 'رمز عبور: ' . $s['password']);
        $head = '<b>' . h((string) ($s['pname'] ?? 'بدون پلن')) . '</b>' . "\n" .
            'وضعیت: <b>' . ($available ? '🟢 آماده‌ی فروش' : '🔴 فروخته شد') . '</b>' . "\n" .
            'تاریخ ثبت: ' . h((string) $s['created_at']);
        $tail = '';
        if (!$available) {
            $tail = "\n\n" . 'شماره سفارش: <code>' . h((string) $s['order_id']) . '</code>' . "\n" .
                'شناسه‌ی خریدار: <code>' . (int) $s['user_id'] . '</code>';
        }
        $rows = [];
        if ($available) {
            $rows[] = [btn('✏️ تغییر نام کاربری', 'stock_rename_' . $id, ''), btn('🗑 حذف پنل', 'stock_del_' . $id, '')];
        }
        $rows[] = admin_back('stock_list_0');
        $show($head . "\n" . RULE . "\n" . $details . $tail, kb($rows));
        return true;
    }

    if (preg_match('/^stock_rename_(\d+)$/', $data, $mm) === 1) {
        if (one("SELECT id FROM panel_stock WHERE id = ? AND status = 'available'", [(int) $mm[1]]) === null) {
            $show('⚠️ این پنل دیگر قابل ویرایش نیست.', kb([admin_back('stock_list_0')]));
            return true;
        }
        state_set($uid, 'input', 'stock_rename:' . (int) $mm[1]);
        $show('نام کاربری جدید را ارسال کنید (۲ تا ۶۰ نویسه).',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('stock_view_' . (int) $mm[1])]));
        return true;
    }

    if (preg_match('/^stock_del_(\d+)$/', $data, $mm) === 1) {
        $id = (int) $mm[1];
        $s = one('SELECT s.*, p.name AS pname FROM panel_stock s LEFT JOIN products p ON p.id = s.product_id WHERE s.id = ?', [$id]);
        if ($s === null) {
            $show('⚠️ این پنل پیدا نشد.', kb([admin_back('stock_list_0')]));
            return true;
        }
        if ($s['status'] !== 'available') {
            $show('⚠️ این پنل فروخته شده و قابل حذف نیست.', kb([admin_back('stock_view_' . $id)]));
            return true;
        }
        $show('<b>🗑 حذف پنل</b>' . "\n" . RULE . "\n" .
            'پلن: ' . h((string) ($s['pname'] ?? 'بدون پلن')) . "\n" .
            'نام کاربری: <b>' . h((string) $s['name']) . '</b>' . "\n\n" .
            'پنل برای همیشه حذف می‌شود. آیا تأیید می‌کنید؟',
            kb([[btn('✅ بله، حذف شود', 'stock_delok_' . $id, '')], admin_back('stock_view_' . $id)]));
        return true;
    }

    if (preg_match('/^stock_delok_(\d+)$/', $data, $mm) === 1) {
        $st = q("DELETE FROM panel_stock WHERE id = ? AND status = 'available'", [(int) $mm[1]]);
        $show($st->rowCount() === 1 ? '✅ پنل با موفقیت حذف شد.' : '⚠️ این پنل حذف نشد؛ احتمالاً قبلاً فروخته شده است.',
            kb([admin_back('stock_list_0')]));
        return true;
    }

    if (preg_match('/^stock_delpick_(\d+)$/', $data, $mm) === 1) {
        [$items, $page, $pages] = paginate(rows("SELECT s.id, s.name, p.name AS pname FROM panel_stock s
            LEFT JOIN products p ON p.id = s.product_id WHERE s.status = 'available' ORDER BY s.id DESC"), (int) $mm[1], 8);
        $rows = [];
        foreach ($items as $it) {
            $rows[] = [btn(mb_substr((string) ($it['pname'] ?? 'بدون پلن'), 0, 16) . ' · ' . mb_substr((string) $it['name'], 0, 24),
                'stock_del_' . $it['id'], '')];
        }
        if ($items === []) {
            $rows[] = [btn('پنل آماده‌ای برای حذف وجود ندارد', 'admin_panels', '')];
        }
        $nav = page_row('stock_delpick_', $page, $pages);
        if ($nav !== []) {
            $rows[] = $nav;
        }
        $rows[] = admin_back('admin_panels');
        $show('<b>🗑 حذف پنل</b>' . "\n" . RULE . "\nپنلی را که می‌خواهید حذف شود انتخاب کنید.", kb($rows));
        return true;
    }

    if (preg_match('/^stock_prod_(\d+)$/', $data, $mm) === 1) {
        $draft = json_decode(setting_get('stockdraft:' . $uid, ''), true);
        $p = product_by_id((int) $mm[1]);
        if (!is_array($draft) || !isset($draft['address'], $draft['name'], $draft['password']) || $p === null) {
            $show('⚠️ اطلاعات پنل ناقص است. لطفاً از ابتدا دوباره شروع کنید.', kb([admin_back('stock_add')]));
            return true;
        }
        q('INSERT INTO panel_stock (product_id, kind, name, address, password, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [(int) $p['id'], 'manual', (string) $draft['name'], (string) $draft['address'], (string) $draft['password'], 'available', now()]);
        $id = (int) db()->lastInsertId();
        setting_set('stockdraft:' . $uid, '');
        $ready = (int) scalar("SELECT COUNT(*) FROM panel_stock WHERE product_id = ? AND status = 'available'", [(int) $p['id']]);
        $show('✅ <b>پنل با موفقیت ثبت شد</b>' . "\n" . RULE . "\n" .
            'پلن: <b>' . h((string) $p['name']) . '</b>' . "\n" .
            'پنل‌های آماده‌ی این پلن: <b>' . $ready . '</b>' . "\n\n" .
            'پس از خرید یا تأیید سفارش این پلن، مشخصات پنل به‌صورت خودکار برای مشتری ارسال می‌شود.',
            kb([
                [btn('مشاهده‌ی پنل', 'stock_view_' . $id, '')],
                [btn('➕ افزودن پنل دیگر', 'stock_add', '')],
                admin_back('admin_panels'),
            ]));
        return true;
    }

    if ($data === 'admin_services' || preg_match('/^apanel_list_(\d+)$/', $data, $mm) === 1) {
        $page = isset($mm[1]) ? (int) $mm[1] : 0;
        $counts = ['active' => 0, 'inactive' => 0, 'suspended' => 0, 'expired' => 0];
        foreach (rows('SELECT status, COUNT(*) AS c FROM panels GROUP BY status') as $c) {
            $counts[$c['status']] = (int) $c['c'];
        }
        $all = rows('SELECT id, title, status FROM panels ORDER BY id DESC LIMIT 300');
        [$items, $page, $pages] = paginate($all, $page, 8);
        $labels = ['active' => 'فعال', 'inactive' => 'غیرفعال', 'suspended' => 'متوقف', 'expired' => 'منقضی'];
        $rows = [];
        foreach ($items as $p) {
            $rows[] = [btn(mb_substr((string) $p['title'], 0, 30) . ' · ' . ($labels[$p['status']] ?? $p['status']),
                'apanel_view_' . $p['id'], '')];
        }
        $nav = page_row('apanel_list_', $page, $pages);
        if ($nav !== []) {
            $rows[] = $nav;
        }
        $rows[] = [btn('🔍 جستجو با شناسه‌ی کاربر', 'svc_search', '')];
        $rows[] = [admin_back('admin_home')];
        $show('<b>🧾 سرویس‌های فروخته‌شده</b>' . "\n" . RULE . "\n" .
            'فعال: <b>' . $counts['active'] . '</b> | غیرفعال: <b>' . $counts['inactive'] . '</b>' . "\n" .
            'متوقف: <b>' . $counts['suspended'] . '</b> | منقضی: <b>' . $counts['expired'] . '</b>' . "\n" .
            'صفحه ' . ($page + 1) . 'از ' . $pages, kb($rows));
        return true;
    }

    if (preg_match('/^apanel_view_(\d+)$/', $data, $mm) === 1) {
        $p = one('SELECT pn.*, u.first_name, u.username FROM panels pn LEFT JOIN users u ON u.user_id = pn.user_id WHERE pn.id = ?', [(int) $mm[1]]);
        if ($p === null) {
            $show('⚠️ سرویس پیدا نشد.', kb([admin_back('admin_services')]));
            return true;
        }
        $pid = (int) $p['id'];
        $labels = ['active' => 'فعال', 'inactive' => 'غیرفعال', 'suspended' => 'متوقف', 'expired' => 'منقضی'];
        $statusRow = [];
        foreach ($labels as $key => $label) {
            if ($key !== $p['status']) {
                $statusRow[] = btn($label, 'apanel_status_' . $pid . '_' . $key, '');
            }
        }
        $details = mb_substr((string) $p['details'], 0, 500);
        $show('<b>' . h($p['title']) . '</b>' . "\n" . RULE . "\n" .
            'وضعیت: <b>' . ($labels[$p['status']] ?? h($p['status'])) . '</b>' . "\n" .
            'مشتری: ' . h($p['first_name'] ?? '') . ($p['username'] ? ' @' . h($p['username']) : '') .
            ' (<code>' . (int) $p['user_id'] . '</code>)' . "\n" .
            'ثبت: ' . h((string) $p['created_at']) . "\n" .
            'سفارش: <code>' . h((string) $p['order_id']) . '</code>' . "\n\n" .
            '<code>' . h($details) . '</code>',
            kb([
                $statusRow,
                [btn('✏️ ویرایش اطلاعات', 'apanel_edit_' . $pid, '')],
                [btn('🗑 حذف پنل', 'apanel_del_' . $pid, '')],
                [admin_back('admin_services')],
            ]));
        return true;
    }

    if (preg_match('/^apanel_status_(\d+)_(active|inactive|suspended|expired)$/', $data, $mm) === 1) {
        $pid = (int) $mm[1];
        $p = one('SELECT user_id FROM panels WHERE id = ?', [$pid]);
        if ($p !== null) {
            q('UPDATE panels SET status = ? WHERE id = ?', [$mm[2], $pid]);
            $fa = ['active' => 'فعال', 'inactive' => 'غیرفعال', 'suspended' => 'متوقف', 'expired' => 'منقضی'][$mm[2]];
            send_text((int) $p['user_id'], 'وضعیت سرویس شما به «' . $fa . '» تغییر یافت.');
        }
        return admin_callback('apanel_view_' . $pid, $uid, $chat, $old);
    }

    if (preg_match('/^apanel_edit_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'input', 'panel_edit:' . (int) $mm[1]);
        $show('اطلاعات جدید پنل را ارسال کنید (متن کامل).',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], [admin_back('apanel_view_' . (int) $mm[1])]]));
        return true;
    }

    if (preg_match('/^apanel_del_(\d+)$/', $data, $mm) === 1) {
        $show('<b>🗑 حذف پنل</b>' . "\n" . RULE . "\n" .
            'این پنل برای همیشه حذف می‌شود. ادامه می‌دهید؟',
            kb([
                [btn('✅ بله، حذف شود', 'apanel_delok_' . (int) $mm[1], '')],
                [admin_back('apanel_view_' . (int) $mm[1])],
            ]));
        return true;
    }

    if (preg_match('/^apanel_delok_(\d+)$/', $data, $mm) === 1) {
        q('DELETE FROM panels WHERE id = ?', [(int) $mm[1]]);
        $show('✅ پنل حذف شد.', kb([admin_back('admin_services')]));
        return true;
    }

    if ($data === 'admin_orders') {
        $labels = ['completed' => '✅ تکمیل شده', 'waiting_receipt' => '🕓 منتظر رسید', 'waiting_admin' => 'در انتظار بررسی', 'waiting_panel_details' => '🕓 در انتظار ارسال پنل', 'rejected' => '❌ رد شده', 'cancelled' => '🚫 لغو شده'];
        $rows = [];
        foreach (rows("SELECT o.id, o.status, o.amount, p.name AS pname FROM orders o
                       LEFT JOIN products p ON p.id = o.product_id ORDER BY o.created_at DESC LIMIT 15") as $o) {
            $rows[] = [btn(mb_substr((string) ($o['pname'] ?? 'محصول'), 0, 24) . ' · ' . ($labels[$o['status']] ?? $o['status']),
                'order_view_' . $o['id'], '')];
        }
        if ($rows === []) {
            $rows[] = [btn('سفارشی ثبت نشده است', 'admin_shop', '')];
        }
        $rows[] = [admin_back('admin_shop')];
        $show('<b>🧾 همه‌ی سفارش‌ها</b>' . "\n" . RULE . "\nآخرین ۱۵ سفارش", kb($rows));
        return true;
    }

    if (preg_match('/^order_view_([A-Za-z0-9]+)$/', $data, $mm) === 1) {
        $o = one('SELECT o.*, p.name AS pname FROM orders o LEFT JOIN products p ON p.id = o.product_id WHERE o.id = ?', [$mm[1]]);
        if ($o === null) {
            $show('⚠️ سفارش پیدا نشد.', kb([admin_back('admin_orders')]));
            return true;
        }
        $labels = ['completed' => '✅ تکمیل شده', 'waiting_receipt' => '🕓 منتظر رسید', 'waiting_admin' => 'در انتظار بررسی', 'waiting_panel_details' => '🕓 در انتظار ارسال پنل', 'rejected' => '❌ رد شده', 'cancelled' => '🚫 لغو شده'];
        $buttons = [];
        if (in_array($o['status'], ['waiting_admin', 'waiting_panel_details'], true)) {
            $buttons[] = [btn('📤 تحویل سفارش', 'order_reply_' . $o['id'], '')];
        }
        if (!in_array($o['status'], ['rejected', 'cancelled', 'completed'], true)) {
            $buttons[] = [btn('❌ رد سفارش', 'order_reject_' . $o['id'], '')];
        }
        $buttons[] = [admin_back('admin_orders')];
        $show('<b>🧾 جزئیات سفارش</b>' . "\n" . RULE . "\n" .
            'شماره: <code>' . h((string) $o['id']) . '</code>' . "\n" .
            'محصول: ' . h((string) ($o['pname'] ?? 'پلن حذف‌شده')) . "\n" .
            'مبلغ: <b>' . money($o['amount']) . ' تومان</b>' . "\n" .
            'روش پرداخت: ' . ($o['payment_method'] === 'wallet' ? 'کیف پول' : 'کارت به کارت') . "\n" .
            'وضعیت: <b>' . ($labels[$o['status']] ?? h((string) $o['status'])) . '</b>' . "\n" .
            'تاریخ: ' . h((string) $o['created_at']) . "\n" .
            'کاربر: <code>' . (int) $o['user_id'] . '</code>', kb($buttons));
        return true;
    }

    if ($data === 'admin_discounts') {
        $total = (int) scalar('SELECT COUNT(*) FROM discount_codes');
        $lines = ['<b>🏷 کدهای تخفیف</b>', RULE, 'تعداد کل: <b>' . $total . '</b>', ''];
        $rows = [];
        foreach (rows('SELECT * FROM discount_codes ORDER BY id DESC LIMIT 20') as $d) {
            $lines[] = '<code>' . h((string) $d['code']) . '</code> · ' . (int) $d['percent'] . '٪ · ' . dcode_status_label($d);
            $rows[] = [btn((string) $d['code'] . ' · ' . dcode_status_label($d), 'dcode_view_' . (int) $d['id'], '')];
        }
        if ($total === 0) {
            $lines[] = 'کد تخفیفی ثبت نشده است.';
        }
        $rows[] = [btn('➕ افزودن کد تخفیف', 'dcode_add', '')];
        $rows[] = [admin_back('admin_shop')];
        $show(implode("\n", $lines), kb($rows));
        return true;
    }

    if ($data === 'dcode_add') {
        clear_states($uid);
        $show('<b>➕ افزودن کد تخفیف</b>' . "\n" . RULE . "\n" .
            'مرحله ۱ از ۵: درصد تخفیف را انتخاب کنید یا عدد دلخواه را ارسال کنید.', kb([
            [btn('۱۰٪', 'dcode_pct_10', ''), btn('۱۵٪', 'dcode_pct_15', ''), btn('۲۰٪', 'dcode_pct_20', '')],
            [btn('۲۵٪', 'dcode_pct_25', ''), btn('۳۰٪', 'dcode_pct_30', ''), btn('عدد دلخواه', 'dcode_custom', '')],
            [admin_back('admin_discounts')],
        ]));
        return true;
    }

    if (preg_match('/^dcode_pct_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'dcode_pct', $mm[1]);
        state_set($uid, 'input', 'dcode_name');
        $show('<b>➕ افزودن کد تخفیف</b>' . "\n" . RULE . "\n" .
            'مرحله ۲ از ۵: نام کد را ارسال کنید. فقط حروف انگلیسی، عدد، «_» و «-» (۳ تا ۲۰ نویسه) مجاز است.' . "\n" .
            'درصد انتخاب‌شده: <b>' . (int) $mm[1] . '٪</b>',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], [admin_back('admin_discounts')]]));
        return true;
    }

    if ($data === 'dcode_custom') {
        state_set($uid, 'input', 'dcode_percent');
        $show('درصد تخفیف دلخواه را ارسال کنید (عددی بین ۱ و ۹۰).',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], [admin_back('admin_discounts')]]));
        return true;
    }

    if ($data === 'dcode_expc') {
        state_set($uid, 'input', 'dcode_expday');
        $show('تعداد روزهای اعتبار کد را ارسال کنید (عددی بین ۱ و ۳۶۵).',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], [admin_back('dcode_add')]]));
        return true;
    }

    if (preg_match('/^dcode_exp_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'dcode_exp', $mm[1]);
        dcode_scope_prompt($chat, $old > 0 ? $old : null);
        return true;
    }

    if (preg_match('/^dcode_sc_([a-z_]+)$/', $data, $mm) === 1) {
        state_set($uid, 'dcode_sc', $mm[1]);
        $show('<b>➕ افزودن کد تخفیف</b>' . "\n" . RULE . "\n" .
            'مرحله ۵ از ۵: سقف تعداد کل استفاده را انتخاب کنید.', kb([
            [btn('نامحدود', 'dcode_max_0', ''), btn('۱۰ بار', 'dcode_max_10', '')],
            [btn('۵۰ بار', 'dcode_max_50', ''), btn('۱۰۰ بار', 'dcode_max_100', '')],
            [btn('تعداد دلخواه', 'dcode_maxc', '')],
            [admin_back('admin_discounts')],
        ]));
        return true;
    }

    if (preg_match('/^dcode_max_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'dcode_max', $mm[1]);
        dcode_finalize($uid, $chat);
        return true;
    }

    if ($data === 'dcode_maxc') {
        state_set($uid, 'input', 'dcode_maxval');
        $show('تعداد دفعات مجاز استفاده از کد را ارسال کنید (عددی بین ۱ و ۱۰۰٬۰۰۰).',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], [admin_back('admin_discounts')]]));
        return true;
    }

    if (preg_match('/^dcode_view_(\d+)$/', $data, $mm) === 1) {
        $d = one('SELECT * FROM discount_codes WHERE id = ?', [(int) $mm[1]]);
        if ($d === null) {
            $show('⚠️ کد تخفیف پیدا نشد.', kb([[admin_back('admin_discounts')]]));
            return true;
        }
        $id = (int) $d['id'];
        $show('<b>🏷 کد تخفیف</b>' . "\n" . RULE . "\n" .
            'کد: <code>' . h((string) $d['code']) . '</code>' . "\n" .
            'تخفیف: <b>' . (int) $d['percent'] . '٪</b>' . "\n" .
            'وضعیت: <b>' . dcode_status_label($d) . '</b>' . "\n" .
            'انقضا: ' . h((string) ($d['expires_at'] ?? 'بدون انقضا')) . "\n" .
            'محدوده: ' . h(dcode_scope_label((string) ($d['scope'] ?? 'all_once'))) . "\n" .
            'استفاده: ' . dcode_usage_label($d) . "\n" .
            'ایجاد: ' . h((string) $d['created_at']), kb([
            [btn((int) $d['active'] === 1 ? 'غیرفعال کردن' : 'فعال کردن', 'dcode_toggle_' . $id, '')],
            [btn('🗑 حذف کد', 'dcode_del_' . $id, '')],
            [admin_back('admin_discounts')],
        ]));
        return true;
    }

    if (preg_match('/^dcode_toggle_(\d+)$/', $data, $mm) === 1) {
        q('UPDATE discount_codes SET active = 1 - active WHERE id = ?', [(int) $mm[1]]);
        return admin_callback('dcode_view_' . (int) $mm[1], $uid, $chat, $old);
    }

    if (preg_match('/^dcode_del_(\d+)$/', $data, $mm) === 1) {
        $show('<b>🗑 حذف کد تخفیف</b>' . "\n" . RULE . "\n" .
            'کد برای همیشه حذف می‌شود. سفارش‌های قبلی تغییری نمی‌کنند. ادامه می‌دهید؟',
            kb([[btn('✅ بله، حذف شود', 'dcode_delok_' . (int) $mm[1], '')], [admin_back('dcode_view_' . (int) $mm[1])]]));
        return true;
    }

    if (preg_match('/^dcode_delok_(\d+)$/', $data, $mm) === 1) {
        q('DELETE FROM discount_uses WHERE code_id = ?', [(int) $mm[1]]);
        q('DELETE FROM discount_codes WHERE id = ?', [(int) $mm[1]]);
        $show('✅ کد تخفیف حذف شد.', kb([[admin_back('admin_discounts')]]));
        return true;
    }

    if (preg_match('/^product_view_(\d+)$/', $data, $mm) === 1) {
        $p = product_by_id((int) $mm[1]);
        if ($p === null) {
            $show('⚠️ این پلن پیدا نشد.', kb([[admin_back('admin_products')]]));
            return true;
        }
        $pid = (int) $p['id'];
        $show('<b>' . h((string) $p['name']) . '</b>' . "\n" . RULE . "\n" .
            'مدت: ' . (int) $p['plan'] . 'ماهه' . "\n" .
            'قیمت: <b>' . money($p['price']) . ' تومان</b>' . "\n" .
            'ظرفیت کاربر: ' . (int) $p['panel_limit'], kb([
            [btn('✏️ ویرایش نام', 'product_pname_' . $pid, ''), btn('💲 ویرایش قیمت', 'product_pprice_' . $pid, '')],
            [btn('🗑 حذف پلن', 'product_delete_' . $pid, '')],
            [admin_back('admin_products')],
        ]));
        return true;
    }

    if (preg_match('/^product_pname_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'input', 'edit_pname:' . (int) $mm[1]);
        $show('نام جدید پلن را ارسال کنید (۲ تا ۱۲۰ حرف). نمونه: پلن ۳۰ روزه ۲۰ گیگابایت',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], [admin_back('product_view_' . (int) $mm[1])]]));
        return true;
    }

    if (preg_match('/^product_pprice_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'input', 'edit_pprice:' . (int) $mm[1]);
        $show('قیمت جدید را به تومان ارسال کنید. نمونه: 350000',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], [admin_back('product_view_' . (int) $mm[1])]]));
        return true;
    }

    if ($data === 'admin_report') {
        $show(report_text(), kb([
            [btn_url('👥 ورود به گروه سیمرغ', report_link(), '')],
            [admin_back('admin_home')],
        ]));
        return true;
    }

    if ($data === 'svc_search') {
        state_set($uid, 'input', 'svc_search');
        $show('<b>🔍 جستجوی سرویس‌ها</b>' . "
" . RULE . "
" .
            'شناسه‌ی عددی کاربر را ارسال کنید تا سرویس‌های خریداری‌شده‌ی او نمایش داده شود.',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_services')]));
        return true;
    }

    if ($data === 'users_search') {
        state_set($uid, 'input', 'user_search');
        $show('<b>🔍 جستجوی کاربر</b>' . "\n" . RULE . "\n" .
            'شناسه‌ی عددی یا نام کاربری کاربر را ارسال کنید.',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_users')]));
        return true;
    }

    if (preg_match('/^users_blocked_(\d+)$/', $data, $mm) === 1) {
        [$items, $page, $pages] = paginate(rows('SELECT user_id, first_name, username FROM users WHERE blocked = 1 ORDER BY user_id DESC'), (int) $mm[1], USERS_PER_PAGE);
        $rows = [];
        foreach ($items as $u) {
            $rows[] = [btn(mb_substr((string) ($u['first_name'] ?: 'بی‌نام'), 0, 30) . ' · ' . $u['user_id'], 'user_' . $u['user_id'], '')];
        }
        if ($items === []) {
            $rows[] = [btn('کاربر مسدودی وجود ندارد', 'admin_users', '')];
        }
        $nav = page_row('users_blocked_', $page, $pages);
        if ($nav !== []) {
            $rows[] = $nav;
        }
        $rows[] = admin_back('admin_users');
        $show('<b>🚫 کاربران مسدود</b>' . "\n" . RULE . "\n" . 'تعداد: ' . fa((string) count(rows('SELECT user_id FROM users WHERE blocked = 1'))), kb($rows));
        return true;
    }

    if ($data === 'users_bulk') {
        state_set($uid, 'input', 'bulk_amount');
        $show('<b>💳 تغییر موجودی همه‌ی کاربران</b>' . "\n" . RULE . "\n" .
            'مبلغ را به تومان ارسال کنید. عدد مثبت برای افزایش و عدد منفی برای کاهش. مثال: 50000 یا -20000.',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_users')]));
        return true;
    }

    if (preg_match('/^bulk_yes_(-?\d+)$/', $data, $mm) === 1) {
        $amount = (int) $mm[1];
        $st = q('UPDATE users SET balance = GREATEST(0, balance + ?) WHERE blocked = 0', [$amount]);
        $show('✅ <b>تغییر موجودی انجام شد</b>' . "\n" . RULE . "\n" .
            'مبلغ هر کاربر: ' . money($amount) . "\n" .
            'کاربران به‌روز شده: <b>' . fa((string) $st->rowCount()) . '</b>',
            kb([admin_back('admin_users')]));
        return true;
    }

    if (preg_match('/^bc_cancel_(\d+)$/', $data, $mm) === 1) {
        $job = one('SELECT * FROM broadcast_jobs WHERE id = ?', [(int) $mm[1]]);
        if ($job === null || $job['status'] !== 'running') {
            $show('این عملیات دیگر فعال نیست.', kb([admin_back('admin_home')]));
            return true;
        }
        q("UPDATE broadcast_jobs SET status = 'cancelled' WHERE id = ? AND status = 'running'", [(int) $job['id']]);
        if ((int) $job['progress_msg'] > 0) {
            delete_message((int) $job['admin_id'], (int) $job['progress_msg']);
        }
        send_text((int) $job['admin_id'], '<b>🚫 ارسال همگانی لغو شد</b>' . "\n" . RULE . "\n" .
            'موفق: <b>' . fa((string) $job['ok_count']) . '</b>' . "\n" .
            'ناموفق: <b>' . fa((string) $job['fail_count']) . '</b>' . "\n\n" .
            'پس از این، هیچ پیامی برای کاربران ارسال نخواهد شد.',
            kb([admin_back('admin_home')]));
        return true;
    }

    if ($data === 'bc_setup') {
        $key = setting_get('cron_key', '');
        if ($key === '') {
            $key = bin2hex(random_bytes(16));
            setting_set('cron_key', $key);
        }
        $url = rtrim((string) cfg('webapp_url', ''), '/') . '/cron.php?key=' . $key;
        $last = setting_get('cron_last', '');
        $show('<b>⏱ تنظیم زمان‌بندی ارسال همگانی</b>' . "\n" . RULE . "\n" .
            'ارسال همگانی از طریق یک کار زمان‌بندی‌شده روی هاست انجام می‌شود. این کار را یک بار تنظیم کنید:' . "\n\n" .
            '<b>۱.</b> در cPanel بخش Cron Jobs را باز کنید و زمان‌بندی را «هر دقیقه» (* * * * *) قرار دهید.' . "\n" .
            '<b>۲.</b> فرمان زیر را در فیلد فرمان وارد کنید:' . "\n" .
            '<code>wget -q -O /dev/null "' . h($url) . '"</code>' . "\n\n" .
            'آخرین اجرا: ' . ($last !== '' ? h($last) : 'هنوز اجرا نشده است'),
            kb([admin_back('admin_shop')]));
        return true;
    }

    if ($data === 'admin_alabels') {
        $lines = ['<b>🧭 نام بخش‌های مدیریت</b>', RULE, 'برای تغییر نام، بخش موردنظر را انتخاب کنید.', ''];
        $rows = [];
        foreach (ADMIN_MENU_LABELS as $key => $default) {
            $rows[] = [btn(admin_label($key), 'alabel_' . $key, '')];
        }
        $rows[] = admin_back('admin_store');
        $show(implode("\n", $lines), kb($rows));
        return true;
    }

    if (preg_match('/^alabel_([a-z]+)$/', $data, $mm) === 1 && isset(ADMIN_MENU_LABELS[$mm[1]])) {
        state_set($uid, 'input', 'alabel:' . $mm[1]);
        $show('<b>' . h(ADMIN_MENU_LABELS[$mm[1]]) . '</b>' . "\n" . RULE . "\n" .
            'نام جدید را ارسال کنید (۲ تا ۳۲ نویسه).' . "\n" .
            'برای بازگشت به نام پیش‌فرض، عبارت «پیش‌فرض» را ارسال کنید.',
            kb([[btn('انصراف', 'admin_cancel', '')], admin_back('admin_alabels')]));
        return true;
    }

    if ($data === 'admin_plans') {
        $rows = [];
        foreach (plan_durations(false) as $pl) {
            $m = (int) $pl['months'];
            $rows[] = [btn(plan_title($m) . ((int) $pl['active'] === 1 ? ' · فعال' : ' · غیرفعال'), 'dur_def_' . $m, '')];
        }
        $rows[] = [btn('➕ افزودن مدت', 'dur_add', '')];
        $rows[] = admin_back('admin_shop');
        $show('<b>📅 مدت‌های خرید</b>' . "\n" . RULE . "\n" .
            'مدت‌هایی که فعال باشند، در فروشگاه و مینی‌اپ نمایش داده می‌شوند.', kb($rows));
        return true;
    }

    if ($data === 'dur_add') {
        state_set($uid, 'input', 'dur_new_months');
        $show('<b>➕ افزودن مدت خرید</b>' . "\n" . RULE . "\n" .
            'تعداد ماه را ارسال کنید (عددی بین ۱ و ۳۶). نمونه: 6',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_plans')]));
        return true;
    }

    if (preg_match('/^dur_def_(\d{1,2})$/', $data, $mm) === 1) {
        admin_dur_detail($chat, $old, (int) $mm[1]);
        return true;
    }

    if (preg_match('/^dur_rename_(\d{1,2})$/', $data, $mm) === 1) {
        state_set($uid, 'input', 'dur_name:' . (int) $mm[1]);
        $show('<b>✏️ تغییر نام مدت</b>' . "\n" . RULE . "\n" .
            'نام جدید را ارسال کنید (۲ تا ۳۰ نویسه). برای بازگشت به نام پیش‌فرض، عبارت «پیش‌فرض» را ارسال کنید.',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('dur_def_' . (int) $mm[1])]));
        return true;
    }

    if (preg_match('/^dur_toggle_(\d{1,2})$/', $data, $mm) === 1) {
        $months = (int) $mm[1];
        $p = plan_find($months);
        if ($p === null) {
            $show('⚠️ این مدت پیدا نشد.', kb([admin_back('admin_plans')]));
            return true;
        }
        if ((int) $p['active'] === 1 && (int) scalar('SELECT COUNT(*) FROM plan_durations WHERE active = 1') <= 1) {
            $show('⚠️ حداقل یک مدت خرید باید فعال باقی بماند.', kb([admin_back('dur_def_' . $months)]));
            return true;
        }
        q('UPDATE plan_durations SET active = 1 - active WHERE months = ?', [$months]);
        admin_dur_detail($chat, $old, $months);
        return true;
    }

    if (preg_match('/^dur_delete_(\d{1,2})$/', $data, $mm) === 1) {
        $months = (int) $mm[1];
        $count = (int) scalar('SELECT COUNT(*) FROM products WHERE plan = ?', [$months]);
        if ($count > 0) {
            $show('⚠️ این مدت دارای ' . fa((string) $count) . ' پلن است و قابل حذف نیست.' . "\n" .
                'برای پنهان کردن آن، مدت را غیرفعال کنید.', kb([admin_back('dur_def_' . $months)]));
            return true;
        }
        $show('<b>🗑 حذف مدت خرید</b>' . "\n" . RULE . "\n" .
            'مدت <b>' . h(plan_title($months)) . '</b> برای همیشه حذف می‌شود. آیا تأیید می‌کنید؟',
            kb([[btn('✅ بله، حذف شود', 'dur_delok_' . $months, '')], admin_back('dur_def_' . $months)]));
        return true;
    }

    if (preg_match('/^dur_delok_(\d{1,2})$/', $data, $mm) === 1) {
        $months = (int) $mm[1];
        $count = (int) scalar('SELECT COUNT(*) FROM products WHERE plan = ?', [$months]);
        if ($count > 0) {
            $show('⚠️ این مدت دیگر قابل حذف نیست، زیرا پلن دارد.', kb([admin_back('admin_plans')]));
            return true;
        }
        $st = q('DELETE FROM plan_durations WHERE months = ?', [$months]);
        $show($st->rowCount() === 1 ? '✅ مدت خرید حذف شد.' : '⚠️ این مدت پیدا نشد.', kb([admin_back('admin_plans')]));
        return true;
    }

    if ($data === 'admin_texts') {
        $rows = [];
        foreach (bot_text_parts() as $key => $part) {
            $rows[] = [btn($part['title'], 'txt_def_' . $key, '')];
        }
        $rows[] = admin_back('admin_store');
        $show('<b>✏️ متن‌های ربات</b>' . "\n" . RULE . "\n" .
            'متنی را که می‌خواهید تغییر دهید انتخاب کنید.', kb($rows));
        return true;
    }

    if (preg_match('/^txt_def_([a-z_]+)$/', $data, $mm) === 1 && isset(bot_text_parts()[$mm[1]])) {
        admin_text_detail($chat, $old, $mm[1]);
        return true;
    }

    if (preg_match('/^txt_edit_([a-z_]+)$/', $data, $mm) === 1 && isset(bot_text_parts()[$mm[1]])) {
        $part = bot_text_parts()[$mm[1]];
        state_set($uid, 'input', 'txt:' . $mm[1]);
        $show('<b>✏️ ویرایش: ' . h($part['title']) . '</b>' . "\n" . RULE . "\n" .
            h($part['hint']) . "\n\n" .
            'متن جدید را ارسال کنید. برای بازگشت به متن پیش‌فرض، عبارت «پیش‌فرض» را ارسال کنید.',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('txt_def_' . $mm[1])]));
        return true;
    }

    if (preg_match('/^txt_reset_([a-z_]+)$/', $data, $mm) === 1 && isset(bot_text_parts()[$mm[1]])) {
        setting_set(bot_text_parts()[$mm[1]]['setting'], '');
        $show('✅ متن به حالت پیش‌فرض بازگشت.', kb([admin_back('txt_def_' . $mm[1])]));
        return true;
    }

    if ($data === 'admin_backup') {
        $h = backup_interval();
        $last = (string) setting_get('backup_last', '');
        $label = $h > 0 ? 'هر ' . fa((string) $h) . ' ساعت' : 'خاموش';
        $show('<b>📦 پشتیبان‌گیری از پایگاه‌داده</b>' . "\n" . RULE . "\n" .
            'وضعیت: <b>' . $label . '</b>' . "\n" .
            'آخرین پشتیبان: ' . ($last !== '' ? h($last) : 'هنوز ساخته نشده') . "\n\n" .
            'فایل پشتیبان به بخش «پشتیبان‌گیری» گروه گزارش‌ها ارسال می‌شود. فقط ۱۰ فایل آخر نگه داشته می‌شود.',
            kb([
                [btn('خاموش', 'bkp_set_0', ''), btn('هر ۱ ساعت', 'bkp_set_1', '')],
                [btn('هر ۳ ساعت', 'bkp_set_3', ''), btn('هر ۵ ساعت', 'bkp_set_5', '')],
                [btn('هر ۱۰ ساعت', 'bkp_set_10', ''), btn('هر ۲۴ ساعت', 'bkp_set_24', '')],
                [btn('📦 ارسال پشتیبان همین حالا', 'bkp_now', '')],
                admin_back('admin_store'),
            ]));
        return true;
    }

    if (preg_match('/^bkp_set_(\d{1,2})$/', $data, $mm) === 1 && in_array((int) $mm[1], [0, 1, 3, 5, 10, 24], true)) {
        setting_set('backup_hours', $mm[1]);
        $show('✅ زمان‌بندی پشتیبان ذخیره شد.', kb([admin_back('admin_backup')]));
        return true;
    }

    if ($data === 'bkp_now') {
        if (report_chat() === '') {
            $show('⚠️ ابتدا گروه گزارش‌ها را تنظیم کنید.', kb([admin_back('admin_backup')]));
            return true;
        }
        $ok = backup_run();
        $show($ok ? '✅ فایل پشتیبان به گروه گزارش‌ها ارسال شد.' : '⚠️ ارسال پشتیبان انجام نشد؛ گزارش آن در بخش خطاها ثبت شد.',
            kb([admin_back('admin_backup')]));
        return true;
    }

    if ($data === 'admin_faqs') {
        $rows = [];
        foreach (rows('SELECT id, question, active FROM faqs ORDER BY id') as $f) {
            $label = mb_substr((string) $f['question'], 0, 36) . ((int) $f['active'] === 1 ? '' : ' · پنهان');
            $rows[] = [btn($label, 'faqadm_def_' . (int) $f['id'], '')];
        }
        $rows[] = [btn('➕ افزودن سوال', 'faqadm_add', '')];
        $rows[] = admin_back('admin_support');
        $show('<b>❓ سوالات متداول</b>' . "\n" . RULE . "\n" .
            'تعداد: <b>' . fa((string) (int) scalar('SELECT COUNT(*) FROM faqs')) . '</b>' . "\n" .
            'سوال موردنظر را انتخاب کنید یا سوال جدید اضافه کنید.', kb($rows));
        return true;
    }

    if ($data === 'faqadm_add') {
        setting_set('faqdraft:' . $uid, '');
        state_set($uid, 'input', 'faqadm_newq');
        $show('<b>➕ افزودن سوال متداول</b>' . "\n" . RULE . "\n" .
            'پرسش را ارسال کنید (۳ تا ۱۵۰ نویسه).',
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('admin_faqs')]));
        return true;
    }

    if (preg_match('/^faqadm_def_(\d+)$/', $data, $mm) === 1) {
        admin_faq_detail($chat, $old, (int) $mm[1]);
        return true;
    }

    if (preg_match('/^faqadm_q_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'input', 'faqadm_editq:' . (int) $mm[1]);
        $show('<b>✏️ ویرایش پرسش</b>' . "\n" . RULE . "\nپرسش جدید را ارسال کنید (۳ تا ۱۵۰ نویسه).",
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('faqadm_def_' . (int) $mm[1])]));
        return true;
    }

    if (preg_match('/^faqadm_a_(\d+)$/', $data, $mm) === 1) {
        state_set($uid, 'input', 'faqadm_edita:' . (int) $mm[1]);
        $show('<b>✏️ ویرایش پاسخ</b>' . "\n" . RULE . "\nپاسخ جدید را ارسال کنید (۲ تا ۳۰۰۰ نویسه). می‌توانید چند سطر بنویسید.",
            kb([[btn('✖️ انصراف', 'admin_cancel', '')], admin_back('faqadm_def_' . (int) $mm[1])]));
        return true;
    }

    if (preg_match('/^faqadm_t_(\d+)$/', $data, $mm) === 1) {
        q('UPDATE faqs SET active = 1 - active WHERE id = ?', [(int) $mm[1]]);
        admin_faq_detail($chat, $old, (int) $mm[1]);
        return true;
    }

    if (preg_match('/^faqadm_del_(\d+)$/', $data, $mm) === 1) {
        $show('<b>🗑 حذف سوال متداول</b>' . "\n" . RULE . "\nاین سوال برای همیشه حذف می‌شود. آیا تأیید می‌کنید؟",
            kb([[btn('✅ بله، حذف شود', 'faqadm_delok_' . (int) $mm[1], '')], admin_back('faqadm_def_' . (int) $mm[1])]));
        return true;
    }

    if (preg_match('/^faqadm_delok_(\d+)$/', $data, $mm) === 1) {
        q('DELETE FROM faqs WHERE id = ?', [(int) $mm[1]]);
        $show('✅ سوال متداول حذف شد.', kb([admin_back('admin_faqs')]));
        return true;
    }

    if ($data === 'reports_oldclean') {
        $show('<b>🗑 حذف تاپیک‌های قدیمی</b>' . "\n" . RULE . "\n" .
            'تاپیک‌های نسخه‌ی قبلی گروه (سفارش‌ها، پرداخت‌ها، کیف پول، تست‌ها، پشتیبانی و کاربران قدیمی) با همه‌ی پیام‌هایشان حذف می‌شوند. '
            . 'پنج تاپیک فعلی تغییری نمی‌کنند. آیا تأیید می‌کنید؟',
            kb([[btn('✅ بله، حذف شوند', 'reports_oldclean_ok', '')], admin_back('admin_reports')]));
        return true;
    }

    if ($data === 'reports_oldclean_ok') {
        if (report_chat() === '') {
            $show('⚠️ گروه گزارش‌ها تنظیم نشده است.', kb([admin_back('admin_reports')]));
            return true;
        }
        $r = delete_legacy_report_topics();
        $show('<b>✅ پاک‌سازی انجام شد</b>' . "\n" . RULE . "\n" .
            'حذف‌شده: <b>' . fa((string) $r['deleted']) . '</b>' . "\n" .
            'ناموفق: <b>' . fa((string) $r['failed']) . '</b>' .
            ($r['failed'] > 0 ? "\n\nتاپیک‌های ناموفق احتمالاً قبلاً حذف شده‌اند." : ''),
            kb([admin_back('admin_reports')]));
        return true;
    }

    return false;
}
