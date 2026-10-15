<?php
/**
 * ساخت دکمه‌ها، منوها و صفحه‌ها.
 * منوی اصلی فقط یک‌بار تعریف شده و هم به‌صورت دکمه‌ی زیر پیام (شیشه‌ای) و هم به‌صورت کیبورد پایین صفحه نمایش داده می‌شود.
 */
declare(strict_types=1);

/** خط عرض ثابت: باعث می‌شود حباب پیام همیشه عرض کافی داشته باشد و دکمه‌ها کوچک نشوند. */
const RULE = "\n\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}\u{2800}";

/** بخش‌های رنگی قابل تنظیم از پنل مدیریت. */
const COLOR_CATEGORIES = [
    'buy' => 'خرید پنل',
    'panels' => 'پنل‌های من',
    'test' => 'تست رایگان',
    'wallet' => 'کیف پول',
    'account' => 'حساب کاربری',
    'referral' => 'دعوت دوستان',
    'miniapp' => 'ورود به مینی‌اپ',
    'support' => 'پشتیبانی',
    'admin' => 'پنل مدیریت',
    'nav' => 'دکمه‌های بازگشت',
];

const COLOR_DEFAULTS = [
    'buy' => 'success', 'panels' => 'primary', 'test' => 'primary', 'wallet' => 'success',
    'account' => 'primary', 'referral' => 'primary', 'miniapp' => 'success', 'support' => 'primary',
    'admin' => 'primary', 'nav' => 'primary',
];

const COLOR_OPTIONS = [
    'primary' => 'آبی',
    'success' => 'سبز',
    'danger' => 'قرمز',
    'default' => 'پیش‌فرض تلگرام',
];

/** رنگ دکمه‌ی یک بخش. مقدار 'default'یعنی بدون رنگ خاص. */
function color_of(string $category): string
{
    $fallback = COLOR_DEFAULTS[$category] ?? 'primary';
    $value = setting_get('color_' . $category, $fallback);
    return array_key_exists($value, COLOR_OPTIONS) ? $value : $fallback;
}

/** دکمه‌ی اینلاین (زیر پیام). */
function btn(string $text, ?string $callback = null, string $style = 'primary'): array
{
    $b = ['text' => $text, 'callback_data' => $callback ?? 'noop'];
    if (in_array($style, ['primary', 'success', 'danger'], true)) {
        $b['style'] = $style;
    }
    return $b;
}

/** دکمه‌ی مینی‌اپ که داخل تلگرام باز می‌شود. */
function btn_web(string $text, string $url, string $style = 'primary'): array
{
    $b = ['text' => $text, 'web_app' => ['url' => $url]];
    if (in_array($style, ['primary', 'success', 'danger'], true)) {
        $b['style'] = $style;
    }
    return $b;
}

/** دکمه‌ی لینک خارجی. */
function btn_url(string $text, string $url, string $style = 'primary'): array
{
    $b = ['text' => $text, 'url' => $url];
    if (in_array($style, ['primary', 'success', 'danger'], true)) {
        $b['style'] = $style;
    }
    return $b;
}

/**
 * کیبورد اینلاین. تلگرام فقط «ردیفِ دکمه» (آرایه‌ای از دکمه‌ها) می‌پذیرد.
 * اگر یک دکمه‌ی تنها به‌جای ردیف داده شود، خودکار در یک ردیف قرار می‌گیرد.
 */
function kb(array $rows): string
{
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['text'])) {
            $out[] = [$row];
            continue;
        }
        $cells = [];
        foreach ((array) $row as $cell) {
            if (!is_array($cell)) {
                continue;
            }
            if (isset($cell['text'])) {
                $cells[] = $cell;
                continue;
            }
            foreach ($cell as $inner) {
                if (is_array($inner) && isset($inner['text'])) {
                    $cells[] = $inner;
                }
            }
        }
        if ($cells !== []) {
            $out[] = $cells;
        }
    }
    $out = kb_pair_rows($out);
    return json_encode(['inline_keyboard' => $out], JSON_UNESCAPED_UNICODE);
}

/** دکمه‌های تنها (غیر از بازگشت) دو‌به‌دو کنار هم قرار می‌گیرند تا اندازه‌ی همه‌ی منوها یکسان باشد. */
function kb_pair_rows(array $rows): array
{
    $merged = [];
    $pending = null;
    foreach ($rows as $row) {
        $text = (string) ($row[0]['text'] ?? '');
        $isNav = preg_match('/^(‹|⬅|🏠|✖|🔙|منوی|بازگشت|انصراف)/u', $text) === 1;
        if (count($row) === 1 && !$isNav) {
            if ($pending === null) {
                $pending = $row[0];
            } else {
                $merged[] = [$pending, $row[0]];
                $pending = null;
            }
            continue;
        }
        if ($pending !== null) {
            $merged[] = [$pending];
            $pending = null;
        }
        $merged[] = $row;
    }
    if ($pending !== null) {
        $merged[] = [$pending];
    }
    return $merged;
}

/** ردیف دکمه‌ی بازگشت. */
function back_row(string $callback = 'back_main', string $label = '‹ بازگشت'): array
{
    return [btn($label, $callback, color_of('nav'))];
}

/** صفحه‌ی جدید را می‌فرستد و صفحه‌ی قبلی (پیامی که دکمه روی آن زده شده) را حذف می‌کند. */
function screen(int $chat, ?int $oldMessageId, string $text, ?string $markup = null): void
{
    send_text($chat, $text, $markup);
    if ($oldMessageId !== null && $oldMessageId > 0) {
        delete_message($chat, $oldMessageId);
    }
}

/** تقسیم فهرست به صفحه‌ها. برمی‌گرداند: [آیتم‌های صفحه، شماره‌ی صفحه، تعداد کل صفحه‌ها] */
function paginate(array $items, int $page, int $perPage): array
{
    $pages = max(1, (int) ceil(count($items) / $perPage));
    $page = min(max(0, $page), $pages - 1);
    return [array_slice($items, $page * $perPage, $perPage), $page, $pages];
}

/** ردیف دکمه‌های قبلی/بعدی برای فهرست‌های صفحه‌بندی‌شده. */
function page_row(string $prefix, int $page, int $pages): array
{
    $row = [];
    if ($page > 0) {
        $row[] = btn('صفحه‌ی قبل', $prefix . ($page - 1), 'primary');
    }
    if ($page < $pages - 1) {
        $row[] = btn('صفحه‌ی بعد', $prefix . ($page + 1), 'primary');
    }
    return $row;
}

function store_name(): string
{
    $name = setting_get('store_name', '');
    if ($name === '') {
        $name = (string) cfg('store_name', '');
    }
    return $name !== '' ? $name : 'فروشگاه';
}

/** متن پیش‌فرض دکمه‌های منوی اصلی؛ مدیر می‌تواند هرکدام را تغییر دهد. */
const MENU_LABELS = [
    'buy' => '🛒 خرید پنل',
    'panels' => '📦 پنل‌های من',
    'test' => '🎁 تست رایگان',
    'wallet' => '👛 کیف پول',
    'account' => '👤 حساب کاربری',
    'referral' => '🤝 دعوت دوستان',
    'miniapp' => '📱 ورود به مینی‌اپ',
    'support' => '🎧 مرکز پشتیبانی',
    'admin' => '🛠 پنل مدیریت',
];

function label_of(string $key): string
{
    $custom = setting_get('label_' . $key, '');
    return $custom !== '' ? $custom : MENU_LABELS[$key];
}

function test_enabled(): bool
{
    return setting_get('test_enabled', '1') === '1';
}

/** روش نمایش منو: inline (شیشه‌ای زیر پیام) یا keyboard (کیبورد پایین صفحه). */
function menu_style(): string
{
    return setting_get('menu_style', 'keyboard') === 'inline' ? 'inline' : 'keyboard';
}

/**
 * آدرس مینی‌اپ. با شناسه‌ی کاربر، یک توکن ورود امضاشده (۷ روز) به آدرس اضافه می‌شود تا
 * با یک بار زدن دکمه، کاربر مستقیم داخل مینی‌اپ و وارد حساب خودش شود.
 */
function miniapp_url(?int $uid = null): string
{
    $base = (string) cfg('webapp_url', '');
    if ($base === '') {
        return '';
    }
    $url = rtrim($base, '/') . '/miniapp.php';
    return $uid === null ? $url : $url . '?t=' . auth_sign('login', $uid, time() + 7 * 86400);
}

/**
 * ساختار منوی اصلی. هر دکمه: label, callback (یا web برای مینی‌اپ), color
 * این ساختار هم برای دکمه‌های زیر پیام و هم برای کیبورد پایین استفاده می‌شود.
 */
function menu_rows(int $uid): array
{
    $rows = [
        [['label' => label_of('buy'), 'callback' => 'buy_panel', 'color' => color_of('buy')],
         ['label' => label_of('panels'), 'callback' => 'my_panels', 'color' => color_of('panels')]],
    ];
    $second = [];
    if (test_enabled()) {
        $second[] = ['label' => label_of('test'), 'callback' => 'free_test', 'color' => color_of('test')];
    }
    $second[] = ['label' => label_of('wallet'), 'callback' => 'wallet', 'color' => color_of('wallet')];
    $rows[] = $second;
    $rows[] = [['label' => label_of('account'), 'callback' => 'account', 'color' => color_of('account')],
               ['label' => label_of('referral'), 'callback' => 'referral', 'color' => color_of('referral')]];
    if (miniapp_url() !== '') {
        $rows[] = [['label' => label_of('miniapp'), 'web' => miniapp_url($uid), 'color' => color_of('miniapp')]];
    }
    $rows[] = [['label' => label_of('support'), 'callback' => 'support_center', 'color' => color_of('support')]];
    if (is_admin($uid)) {
        $rows[] = [['label' => label_of('admin'), 'callback' => 'admin_panel', 'color' => color_of('admin')]];
    }
    return $rows;
}

/** منوی زیر پیام (شیشه‌ای). */
function main_menu_markup(int $uid): string
{
    $out = [];
    foreach (menu_rows($uid) as $row) {
        $line = [];
        foreach ($row as $item) {
            $line[] = isset($item['web'])
                ? btn_web($item['label'], $item['web'], $item['color'])
                : btn($item['label'], $item['callback'], $item['color']);
        }
        $out[] = $line;
    }
    return kb($out);
}

/** کیبورد پایین صفحه (ماندگار). */
function reply_menu_markup(int $uid): string
{
    $out = [];
    foreach (menu_rows($uid) as $row) {
        $line = [];
        foreach ($row as $item) {
            $b = ['text' => $item['label']];
            if (isset($item['web'])) {
                $b['web_app'] = ['url' => $item['web']];
            }
            if (in_array($item['color'], ['primary', 'success', 'danger'], true)) {
                $b['style'] = $item['color'];
            }
            $line[] = $b;
        }
        $out[] = $line;
    }
    return json_encode(['keyboard' => $out, 'resize_keyboard' => true, 'is_persistent' => false,
        'input_field_placeholder' => 'یک گزینه را انتخاب کنید'],
        JSON_UNESCAPED_UNICODE);
}

/** اگر متن پیام دقیقاً برابر برچسب یکی از دکمه‌های کیبورد باشد، callback آن را برمی‌گرداند. */
function menu_label_callback(int $uid, string $text): ?string
{
    foreach (menu_rows($uid) as $row) {
        foreach ($row as $item) {
            if ($item['label'] === $text) {
                return $item['callback'] ?? '';
            }
        }
    }
    return null;
}

/** متن خوش‌آمد. مدیر می‌تواند آن را تغییر دهد؛ {name} و {store} جایگزین می‌شوند. */
function home_text(string $name): string
{
    $store = store_name();
    $custom = setting_get('welcome_text', '');
    if ($custom !== '') {
        return str_replace(['{name}', '{store}'], [h($name), h($store)], h($custom));
    }
    return '<b>' . h($store) . '</b>' . "\n\n" .
        'سلام ' . h($name) . '، به ' . h($store) . "\u{200F}" . ' خوش آمدید.' . "\n" .
        'از منوی زیر می‌توانید پلن خریداری کنید، سرویس‌های خود را مشاهده کنید و حساب خود را مدیریت کنید.';
}

function cancel_markup(string $callback = 'cancel_action'): string
{
    return kb([[btn('انصراف', $callback, 'danger')]]);
}

function back_markup(string $callback = 'back_main'): string
{
    return kb([back_row($callback)]);
}

/** لینک گروه گزارش‌ها (قابل تغییر از تنظیمات). */
const REPORT_DEFAULT_LINK = 'https://t.me/+gA6GwXod09dlYjJk';

/**
 * متن ثابت گزارش ربات. این متن در کد تعریف شده و برای همه‌ی مدیران یکسان است؛
 * هیچ بخشی از پنل امکان تغییر آن را ندارد.
 */
function report_text(): string
{
    return implode("\n", [
        '<b>📢 گزارش ربات</b>',
        '<blockquote>گزارش و پیشنهاد شما به بهبود کیفیت خدمات این ربات کمک می‌کند.</blockquote>',
        '<blockquote><b>🐞 گزارش خطا</b>' . "\n" .
            'اگر در عملکرد ربات با مشکل یا خطا مواجه شدید، لطفاً موضوع را برای بررسی به ما اطلاع دهید.</blockquote>',
        '<blockquote><b>🚨 خطاهای جدی</b>' . "\n" .
            'در صورت مشاهده‌ی خطای جدی یا رفتار غیرعادی، گزارش خود را در اسرع وقت ارسال کنید تا موضوع بررسی و رفع شود.</blockquote>',
        '<blockquote><b>💡 پیشنهاد و ایده</b>' . "\n" .
            'اگر پیشنهادی برای افزودن قابلیت جدید یا بهبود عملکرد ربات دارید، نظر شما برای ما ارزشمند است.</blockquote>',
        '<blockquote><b>🎧 راهنمایی و پشتیبانی</b>' . "\n" .
            'برای دریافت راهنمایی یا کمک بیشتر، از طریق «مرکز پشتیبانی» ربات با تیم ما در ارتباط باشید.</blockquote>',
        '<blockquote><b>📩 ارسال گزارش و پیشنهاد</b>' . "\n" .
            'برای ثبت گزارش، پیشنهاد یا درخواست راهنمایی، لطفاً در <b>گروه سیمرغ</b> پیام بگذارید.</blockquote>',
    ]);
}

/** لینک ثابت گروه سیمرغ. */
function report_link(): string
{
    return REPORT_DEFAULT_LINK;
}

/**
 * نمایش مشخصات پنل در تلگرام. هر فیلد در سطری جدا می‌آید: عنوان فارسی و زیر آن مقدار
 * در بلوک چپ‌به‌راست، تا آدرس و نام کاربری و رمز به‌صورت درست و خوانا دیده شوند.
 */
function panel_details_html(string $details): string
{
    $blocks = [];
    foreach (preg_split('/\R/u', trim($details)) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos === false) {
            $blocks[] = '<code>' . "\u{2066}" . h($line) . "\u{2069}" . '</code>';
            continue;
        }
        $label = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        $blocks[] = '<b>' . h($label) . '</b>' . "\n" . '<code>' . "\u{2066}" . h($value) . "\u{2069}" . '</code>';
    }
    return implode("\n\n", $blocks);
}
