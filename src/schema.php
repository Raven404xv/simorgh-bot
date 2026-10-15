<?php
/**
 * ساخت جدول‌ها و داده‌های پیش‌فرض. اجرای مجدد بی‌خطر است.
 */
declare(strict_types=1);

function install_schema(PDO $pdo): void
{
    $tables = [
        'users' => 'CREATE TABLE IF NOT EXISTS users (
            user_id BIGINT PRIMARY KEY,
            username VARCHAR(64) NOT NULL DEFAULT \'\',
            first_name VARCHAR(255) NOT NULL DEFAULT \'\',
            balance BIGINT NOT NULL DEFAULT 0,
            test_count INT NOT NULL DEFAULT 0,
            joined_at DATETIME NULL
        ) CHARACTER SET utf8mb4',
        'products' => 'CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan TINYINT NOT NULL,
            name VARCHAR(255) NOT NULL,
            price BIGINT NOT NULL DEFAULT 0,
            panel_limit INT NOT NULL DEFAULT 1,
            created_at DATETIME NULL
        ) CHARACTER SET utf8mb4',
        'orders' => 'CREATE TABLE IF NOT EXISTS orders (
            id VARCHAR(40) PRIMARY KEY,
            user_id BIGINT NOT NULL,
            product_id INT NULL,
            amount BIGINT NOT NULL DEFAULT 0,
            payment_method VARCHAR(20) NOT NULL DEFAULT \'\',
            status VARCHAR(40) NOT NULL DEFAULT \'\',
            panel_details TEXT NULL,
            receipt_file_id TEXT NULL,
            created_at DATETIME NULL,
            INDEX idx_orders_user (user_id)
        ) CHARACTER SET utf8mb4',
        'wallet_charges' => 'CREATE TABLE IF NOT EXISTS wallet_charges (
            id VARCHAR(40) PRIMARY KEY,
            user_id BIGINT NOT NULL,
            amount BIGINT NOT NULL DEFAULT 0,
            receipt_file_id TEXT NULL,
            status VARCHAR(40) NOT NULL DEFAULT \'waiting\',
            created_at DATETIME NULL,
            INDEX idx_charges_user (user_id)
        ) CHARACTER SET utf8mb4',
        'panels' => 'CREATE TABLE IF NOT EXISTS panels (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT \'\',
            details TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'active\',
            order_id VARCHAR(40) NULL,
            created_at DATETIME NULL,
            INDEX idx_panels_user (user_id)
        ) CHARACTER SET utf8mb4',
        'tickets' => 'CREATE TABLE IF NOT EXISTS tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            message TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'open\',
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_tickets_user (user_id)
        ) CHARACTER SET utf8mb4',
        'test_requests' => 'CREATE TABLE IF NOT EXISTS test_requests (
            id VARCHAR(40) PRIMARY KEY,
            user_id BIGINT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'waiting\',
            created_at DATETIME NULL,
            INDEX idx_tests_user (user_id)
        ) CHARACTER SET utf8mb4',
        'faqs' => 'CREATE TABLE IF NOT EXISTS faqs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NULL
        ) CHARACTER SET utf8mb4',
        'referrals' => 'CREATE TABLE IF NOT EXISTS referrals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            referrer_id BIGINT NOT NULL,
            referred_user_id BIGINT NOT NULL UNIQUE,
            reward BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            INDEX idx_ref_referrer (referrer_id)
        ) CHARACTER SET utf8mb4',
        'admins' => 'CREATE TABLE IF NOT EXISTS admins (
            user_id BIGINT PRIMARY KEY,
            added_at DATETIME NULL
        ) CHARACTER SET utf8mb4',
        'force_join' => 'CREATE TABLE IF NOT EXISTS force_join (
            id INT AUTO_INCREMENT PRIMARY KEY,
            channel VARCHAR(191) NOT NULL UNIQUE
        ) CHARACTER SET utf8mb4',
        'gateway_payments' => 'CREATE TABLE IF NOT EXISTS gateway_payments (
            authority VARCHAR(80) PRIMARY KEY,
            user_id BIGINT NOT NULL,
            charge_id VARCHAR(40) NOT NULL,
            amount BIGINT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            ref_id VARCHAR(60) NULL,
            created_at DATETIME NULL,
            INDEX idx_gw_charge (charge_id)
        ) CHARACTER SET utf8mb4',
        'discount_codes' => 'CREATE TABLE IF NOT EXISTS discount_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL UNIQUE,
            percent INT NOT NULL,
            active TINYINT NOT NULL DEFAULT 1,
            used_count INT NOT NULL DEFAULT 0,
            created_at DATETIME NULL
        ) CHARACTER SET utf8mb4',
        'discount_uses' => 'CREATE TABLE IF NOT EXISTS discount_uses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code_id INT NOT NULL,
            user_id BIGINT NOT NULL,
            order_id VARCHAR(40) NOT NULL,
            created_at DATETIME NULL,
            INDEX idx_uses_code_user (code_id, user_id),
            INDEX idx_uses_order (order_id)
        ) CHARACTER SET utf8mb4',
        'panel_stock' => 'CREATE TABLE IF NOT EXISTS panel_stock (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT \'manual\',
            name VARCHAR(120) NOT NULL,
            address VARCHAR(255) NOT NULL,
            password VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'available\',
            user_id BIGINT NULL,
            order_id VARCHAR(40) NULL,
            created_at DATETIME NULL,
            sold_at DATETIME NULL,
            INDEX idx_stock_product (product_id, status)
        ) CHARACTER SET utf8mb4',
        'broadcast_jobs' => 'CREATE TABLE IF NOT EXISTS broadcast_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id BIGINT NOT NULL,
            from_chat BIGINT NOT NULL,
            msg_id INT NOT NULL,
            markup_type VARCHAR(20) NOT NULL DEFAULT \'none\',
            status VARCHAR(20) NOT NULL DEFAULT \'running\',
            last_user BIGINT NOT NULL DEFAULT 0,
            total INT NOT NULL DEFAULT 0,
            ok_count INT NOT NULL DEFAULT 0,
            fail_count INT NOT NULL DEFAULT 0,
            progress_msg INT NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            INDEX idx_bc_status (status)
        ) CHARACTER SET utf8mb4',
        'plan_durations' => 'CREATE TABLE IF NOT EXISTS plan_durations (
            months INT NOT NULL PRIMARY KEY,
            label VARCHAR(60) NOT NULL DEFAULT \'\',
            active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NULL
        ) CHARACTER SET utf8mb4',
        'settings' => 'CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(191) PRIMARY KEY,
            v TEXT NULL
        ) CHARACTER SET utf8mb4',
    ];
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    // ستون‌های جدید برای نصب‌های قبلی (بدون حذف داده)
    if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'blocked'")->fetchAll()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN blocked TINYINT NOT NULL DEFAULT 0');
    }
    if (!$pdo->query("SHOW COLUMNS FROM admins LIKE 'role'")->fetchAll()) {
        $pdo->exec("ALTER TABLE admins ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'full'");
    }
    $pdo->exec("INSERT IGNORE INTO settings (k, v) VALUES ('referral_reward', '10000')");
    if (!$pdo->query("SHOW COLUMNS FROM orders LIKE 'discount_code'")->fetchAll()) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN discount_code VARCHAR(32) NULL');
    }
    if (!$pdo->query("SHOW COLUMNS FROM discount_codes LIKE 'expires_at'")->fetchAll()) {
        $pdo->exec('ALTER TABLE discount_codes ADD COLUMN expires_at DATETIME NULL');
    }
    if (!$pdo->query("SHOW COLUMNS FROM discount_codes LIKE 'scope'")->fetchAll()) {
        $pdo->exec("ALTER TABLE discount_codes ADD COLUMN scope VARCHAR(20) NOT NULL DEFAULT 'all_once'");
    }
    if ($pdo->query("SHOW INDEX FROM discount_uses WHERE Key_name = 'uniq_code_user'")->fetchAll()) {
        $pdo->exec('ALTER TABLE discount_uses DROP INDEX uniq_code_user');
        $pdo->exec('ALTER TABLE discount_uses ADD INDEX idx_uses_code_user (code_id, user_id)');
    }
    if (!$pdo->query("SHOW COLUMNS FROM discount_codes LIKE 'max_uses'")->fetchAll()) {
        $pdo->exec('ALTER TABLE discount_codes ADD COLUMN max_uses INT NULL');
    }
    if (!$pdo->query("SHOW COLUMNS FROM orders LIKE 'discount_amount'")->fetchAll()) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN discount_amount BIGINT NOT NULL DEFAULT 0');
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM faqs')->fetchColumn() === 0) {
        $faqs = [
            ['چطور پنل خریداری کنم؟', 'از بخش «خرید پنل» مدت و حجم سرویس را انتخاب کنید و مراحل سفارش را کامل کنید.'],
            ['بعد از پرداخت چه کاری انجام بدهم؟', 'اگر پرداخت کارت‌به‌کارت است، رسید واضح پرداخت را ارسال کنید تا مدیریت بررسی کند.'],
            ['چطور کیف پول را شارژ کنم؟', 'از بخش «کیف پول» مبلغ را وارد کنید، پرداخت را انجام دهید و رسید را ارسال کنید.'],
            ['تست رایگان چطور فعال می‌شود؟', 'از بخش «تست رایگان» درخواست بدهید؛ در صورت وجود شرایط و ظرفیت، تست برایتان ارسال می‌شود.'],
            ['اگر اتصال مشکل داشت چه کنم؟', 'از «پشتیبانی» مشکل را همراه با توضیحات ارسال کنید.'],
        ];
        $stmt = $pdo->prepare('INSERT INTO faqs (question, answer, active, created_at) VALUES (?, ?, 1, ?)');
        foreach ($faqs as [$question, $answer]) {
            $stmt->execute([$question, $answer, now()]);
        }
    }
}
