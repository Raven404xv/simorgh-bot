<?php
/**
 * هسته‌ی برنامه: تنظیمات، اتصال دیتابیس (MySQL) و توابع کمکی.
 */
declare(strict_types=1);

define('SB_ROOT', dirname(__DIR__));
define('SB_VERSION', '3.3.0');
const MINIAPP_VERSION = '3.3.0';
define('REF_REWARD', 10000);       // پاداش هر دعوت موفق (تومان)
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024);

/** مقدار یک تنظیم از config.php را برمی‌گرداند. */
function cfg(string $key, $default = null)
{
    static $config = null;
    if ($config === null) {
        $file = SB_ROOT . '/config.php';
        $config = is_file($file) ? (require $file) : [];
    }
    return $config[$key] ?? $default;
}

/** آیا نصب انجام شده است؟ (قفل نصب وجود دارد) */
function is_installed(): bool
{
    return is_file(SB_ROOT . '/config.php') && is_file(SB_ROOT . '/installed.lock');
}

/** اتصال PDO به MySQL (یک‌بار در هر درخواست). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function scalar(string $sql, array $params = [])
{
    return q($sql, $params)->fetchColumn();
}

function setting_get(string $key, string $default = ''): string
{
    $row = one('SELECT v FROM settings WHERE k = ?', [$key]);
    return $row === null ? $default : (string) $row['v'];
}

function setting_set(string $key, string $value): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, $value]);
}

/** وضعیت گفتگوی هر کاربر (مثلاً «منتظر مبلغ شارژ هستم»). */
function state_get(int $uid, string $name): string
{
    return setting_get('st:' . $uid . ':' . $name, '');
}

function state_set(int $uid, string $name, string $value = ''): void
{
    setting_set('st:' . $uid . ':' . $name, $value);
}

function clear_states(int $uid): void
{
    q('DELETE FROM settings WHERE k LIKE ?', ['st:' . $uid . ':%']);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function new_id(string $prefix): string
{
    return $prefix . bin2hex(random_bytes(6));
}

/** ارقام فارسی با جداکننده‌ی هزارگان فارسی (بدون پسوند واحد). */
function fa(string $s): string
{
    return $s; // ارقام لاتین خوانا هستند؛ تابع برای سازگاری با کدهای قدیمی نگه داشته شده است
}

function money($amount): string
{
    return number_format((int) $amount, 0, '.', ',');
}

function h($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** ارقام فارسی و عربی را به لاتین تبدیل می‌کند. */
function normalize_digits(string $s): string
{
    $map = [];
    foreach (['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'] as $i => $c) {
        $map[$c] = (string) $i;
    }
    foreach (['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'] as $i => $c) {
        $map[$c] = (string) $i;
    }
    return strtr($s, $map);
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
