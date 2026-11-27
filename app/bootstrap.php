<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
define('DB_FILE', DATA_DIR . '/shop.db');
define('CONFIG_FILE', __DIR__ . '/config.php');

if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }

date_default_timezone_set('Asia/Shanghai');

function app_config(bool $reload = false): array {
    static $cfg = null;
    if ($reload) $cfg = null;
    if ($cfg === null) {
        $cfg = is_file(CONFIG_FILE) ? (require CONFIG_FILE) : [];
        if (!is_array($cfg)) $cfg = [];
    }
    return $cfg;
}
function is_installed(): bool {
    $c = app_config();
    return !empty($c['db']['type']);
}
function db_driver(): string {
    $c = app_config();
    return ($c['db']['type'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('vpnshop');
    session_start();
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $c = app_config();
    if (empty($c['db']['type'])) {
        throw new RuntimeException('尚未安装，请访问 /install.php');
    }

    if ($c['db']['type'] === 'mysql') {
        $d = $c['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $d['host'] ?? '127.0.0.1', (int)($d['port'] ?? 3306), $d['name'] ?? '');
        $pdo = new PDO($dsn, $d['user'] ?? '', $d['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } else {
        if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
    }

    init_schema($pdo);
    return $pdo;
}

/* ============ 建表 ============ */
function init_schema(PDO $pdo): void {
    if (db_driver() === 'mysql') { init_schema_mysql($pdo); }
    else { init_schema_sqlite($pdo); }
    migrate_common_columns($pdo);
    seed_plans($pdo);
}

/** 老库补列：MySQL / SQLite 都安全（重复执行只抛异常并忽略） */
function migrate_common_columns(PDO $pdo): void {
    $add = [
        ['orders', 'last_seen', "TEXT NOT NULL DEFAULT ''"],
        ['orders', 'last_sync', "TEXT NOT NULL DEFAULT ''"],
        ['order_accounts', 'last_seen', "TEXT NOT NULL DEFAULT ''"],
        ['subs', 'last_seen', "TEXT NOT NULL DEFAULT ''"],
        ['sub_accounts', 'last_seen', "TEXT NOT NULL DEFAULT ''"],
        ['sub_accounts', 'panel_used', 'REAL NOT NULL DEFAULT 0'],
        ['stats_daily', 'start_gb', 'REAL NOT NULL DEFAULT 0'],
        ['subs', 'note', "TEXT NOT NULL DEFAULT ''"],
        ['orders', 'admin_note', "TEXT NOT NULL DEFAULT ''"],
        ['orders', 'expire_at', "TEXT NOT NULL DEFAULT ''"],
        ['orders', 'panel_enable', 'INTEGER NOT NULL DEFAULT 1'],
        ['plans', 'kind', "TEXT NOT NULL DEFAULT 'normal'"],
    ];
    $added = [];
    foreach ($add as [$t, $c, $def]) {
        try {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN {$c} {$def}");
            $added[$t . '.' . $c] = true;
        } catch (Throwable $e) {}
    }
    // 新增 panel_used 时用当前面板值做基线，避免第一次同步重复计一遍
    if (!empty($added['sub_accounts.panel_used'])) {
        try { $pdo->exec("UPDATE sub_accounts SET panel_used = usage_gb"); } catch (Throwable $e) {}
    }
    // 老数据补 start_gb = 前一天的累计值
    if (!empty($added['stats_daily.start_gb'])) {
        try {
            $rows = $pdo->query("SELECT d, used_gb FROM stats_daily ORDER BY d")->fetchAll();
            $prev = 0.0;
            $up = $pdo->prepare("UPDATE stats_daily SET start_gb=? WHERE d=?");
            foreach ($rows as $r) {
                $up->execute([$prev, $r['d']]);
                $prev = (float)$r['used_gb'];
            }
        } catch (Throwable $e) {}
    }
}

/** 每台面板的时区差（秒）= 面板UTC偏移 - PHP时区偏移；缓存 12 小时 */
function panel_tz_delta(int $serverId): int {
    $GLOBALS['__panel_tz'] = $GLOBALS['__panel_tz'] ?? [];
    if (isset($GLOBALS['__panel_tz'][$serverId])) return (int)$GLOBALS['__panel_tz'][$serverId];
    $h = trim(setting('server_tz_h_' . $serverId));
    if ($h !== '') {
        return $GLOBALS['__panel_tz'][$serverId] = (int)round((float)$h * 3600) - (int)date('Z');
    }
    $v  = setting('tz_delta_' . $serverId);
    $at = (int)setting('tz_delta_at_' . $serverId);
    if ($v !== '' && $at > time() - 43200) {
        return $GLOBALS['__panel_tz'][$serverId] = (int)$v;
    }
    return $GLOBALS['__panel_tz'][$serverId] = ($v !== '' ? (int)$v : 0);
}
function panel_tz_set(int $serverId, int $delta): void {
    set_setting('tz_delta_' . $serverId, (string)$delta);
    set_setting('tz_delta_at_' . $serverId, (string)time());
    $GLOBALS['__panel_tz'][$serverId] = $delta;
}
/** 面板返回的是该服务器本地时间，按各自的时区差换算到 PHP 时区 */
function panel_time(string $ts, int $serverId = 0): string {
    $ts = trim($ts);
    if ($ts === '' || $serverId <= 0) return $ts;
    $e = strtotime($ts);
    if (!$e) return $ts;
    return date('Y-m-d H:i:s', $e - panel_tz_delta($serverId));
}

/** 相对时间：刚刚 / N 分钟前 / N 小时前 / N 天前 */
/** 纯流量套餐（不限时）：内部用 100 年表示，展示时统一显示「不限时」 */
const NO_EXPIRE_DAYS = 36500;
function is_no_expire($days): bool { return (int)$days >= NO_EXPIRE_DAYS; }
function days_label($days): string { return is_no_expire($days) ? '不限时' : ((int)$days . ' 天'); }
function expire_label($expireTs, $days = 0): string {
    if (is_no_expire($days)) return '不限时';
    $expireTs = (int)$expireTs;
    if ($expireTs > 0 && $expireTs >= strtotime('2099-01-01')) return '不限时';
    return $expireTs > 0 ? date('Y-m-d', $expireTs) : '—';
}

/** 百分比文案：极小的占比保留两位小数，避免 0.08% 显示成 0% */
function pct_text(float $p): string {
    $p = max(0.0, $p);
    if ($p > 0 && $p < 1)  return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.') . '%';
    if ($p < 10)           return rtrim(rtrim(number_format($p, 1, '.', ''), '0'), '.') . '%';
    return (string)round($p) . '%';
}
/** 进度条宽度：有使用量就至少给 1.5%，否则 0.08% 的条子肉眼看不见 */
function pct_width(float $p): string {
    if ($p <= 0) return '0%';
    return round(min(100, max(1.5, $p)), 1) . '%';
}

function ago_text(string $ts): string {
    $ts = trim($ts);
    if ($ts === '') return '从未';
    $t = strtotime($ts);
    if (!$t) return '未知';
    $d = time() - $t;
    if ($d < 0) $d = 0;
    if ($d < 45) return '刚刚';
    if ($d < 3600) return max(1, (int)floor($d / 60)) . ' 分钟前';
    if ($d < 86400) return (int)floor($d / 3600) . ' 小时前';
    if ($d < 2592000) return (int)floor($d / 86400) . ' 天前';
    return date('Y-m-d', $t);
}

function seed_plans(PDO $pdo): void {
    if ((int)$pdo->query("SELECT COUNT(*) FROM plans")->fetchColumn() > 0) return;
    $st = $pdo->prepare("INSERT INTO plans (name,days,traffic_gb,price,enabled,sort,badge,descr,scope) VALUES (?,?,?,?,1,?,?,?,'single')");
    $st->execute(['月付套餐', 30, 100, 9.90, 1, '', '100GB / 30天 / 不限速']);
    $st->execute(['季付套餐', 90, 350, 25.90, 2, '推荐', '350GB / 90天 / 不限速']);
    $st->execute(['年付套餐', 365, 1500, 88.00, 3, '最划算', '1500GB / 365天 / 不限速']);
}

function init_schema_sqlite(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT '')");

    $pdo->exec("CREATE TABLE IF NOT EXISTS servers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL DEFAULT '',
        admin_url TEXT NOT NULL,
        password TEXT NOT NULL DEFAULT '',
        region TEXT NOT NULL DEFAULT '',
        region_auto TEXT NOT NULL DEFAULT '',
        country_code TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        sub_base TEXT NOT NULL DEFAULT '',
        enabled INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0,
        last_status TEXT NOT NULL DEFAULT '',
        last_check TEXT NOT NULL DEFAULT '',
        last_error TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, days INTEGER NOT NULL, traffic_gb REAL NOT NULL DEFAULT 0,
        price REAL NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, sort INTEGER NOT NULL DEFAULT 0,
        badge TEXT NOT NULL DEFAULT '', descr TEXT NOT NULL DEFAULT '',
        scope TEXT NOT NULL DEFAULT 'single'
    )");
    $pcols = array_column($pdo->query("PRAGMA table_info(plans)")->fetchAll(), 'name');
    if (!in_array('scope', $pcols, true)) $pdo->exec("ALTER TABLE plans ADD COLUMN scope TEXT NOT NULL DEFAULT 'single'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_no TEXT NOT NULL UNIQUE,
        plan_id INTEGER NOT NULL, plan_name TEXT NOT NULL,
        days INTEGER NOT NULL, traffic_gb REAL NOT NULL DEFAULT 0,
        amount REAL NOT NULL,
        email TEXT NOT NULL DEFAULT '', contact TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'pending',
        uuid TEXT NOT NULL DEFAULT '', sub_url TEXT NOT NULL DEFAULT '',
        pay_method TEXT NOT NULL DEFAULT '', trade_no TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL, paid_at TEXT NOT NULL DEFAULT '',
        note TEXT NOT NULL DEFAULT ''
    )");
    $cols = array_column($pdo->query("PRAGMA table_info(orders)")->fetchAll(), 'name');
    foreach ([
        'server_id' => "INTEGER NOT NULL DEFAULT 0",
        'region'    => "TEXT NOT NULL DEFAULT ''",
        'sub_token' => "TEXT NOT NULL DEFAULT ''",
        'sub_id'    => "INTEGER NOT NULL DEFAULT 0",
        'used_gb'   => "REAL NOT NULL DEFAULT 0",
    ] as $col => $def) {
        if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE orders ADD COLUMN $col $def");
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_email ON orders(email)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS logs (id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT NOT NULL, level TEXT NOT NULL, msg TEXT NOT NULL)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        uuid TEXT NOT NULL DEFAULT '',
        sub_url TEXT NOT NULL DEFAULT '',
        region TEXT NOT NULL DEFAULT ''
    )");
    $oaCols = array_column($pdo->query("PRAGMA table_info(order_accounts)")->fetchAll(), 'name');
    if (!in_array('usage_gb', $oaCols, true)) $pdo->exec("ALTER TABLE order_accounts ADD COLUMN usage_gb REAL NOT NULL DEFAULT 0");
    if (!in_array('last_sync', $oaCols, true)) $pdo->exec("ALTER TABLE order_accounts ADD COLUMN last_sync TEXT NOT NULL DEFAULT ''");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_oa_order ON order_accounts(order_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS subs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT NOT NULL UNIQUE,
        email TEXT NOT NULL DEFAULT '',
        quota_gb REAL NOT NULL DEFAULT 0,
        used_gb REAL NOT NULL DEFAULT 0,
        expire_at TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT NOT NULL DEFAULT '',
        last_sync TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sub_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sub_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        uuid TEXT NOT NULL DEFAULT '',
        sub_url TEXT NOT NULL DEFAULT '',
        region TEXT NOT NULL DEFAULT '',
        usage_gb REAL NOT NULL DEFAULT 0,
        last_sync TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sa_sub ON sub_accounts(sub_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS stats_daily (
        d TEXT NOT NULL PRIMARY KEY,
        used_gb REAL NOT NULL DEFAULT 0,
        orders INTEGER NOT NULL DEFAULT 0,
        revenue REAL NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL, ref TEXT NOT NULL, sent_at TEXT NOT NULL,
        UNIQUE(type,ref)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL, ts TEXT NOT NULL, ok INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_la_ip ON login_attempts(ip,ts)");
}

function init_schema_mysql(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(64) NOT NULL PRIMARY KEY,
        v TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS servers (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL DEFAULT '',
        admin_url VARCHAR(500) NOT NULL,
        password VARCHAR(255) NOT NULL DEFAULT '',
        region VARCHAR(50) NOT NULL DEFAULT '',
        region_auto VARCHAR(50) NOT NULL DEFAULT '',
        country_code VARCHAR(10) NOT NULL DEFAULT '',
        city VARCHAR(100) NOT NULL DEFAULT '',
        sub_base VARCHAR(500) NOT NULL DEFAULT '',
        enabled TINYINT NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0,
        last_status VARCHAR(20) NOT NULL DEFAULT '',
        last_check VARCHAR(30) NOT NULL DEFAULT '',
        last_error VARCHAR(500) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS plans (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        days INT NOT NULL,
        traffic_gb DECIMAL(12,4) NOT NULL DEFAULT 0,
        price DOUBLE NOT NULL,
        enabled TINYINT NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0,
        badge VARCHAR(50) NOT NULL DEFAULT '',
        descr VARCHAR(255) NOT NULL DEFAULT '',
        scope VARCHAR(10) NOT NULL DEFAULT 'single'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_no VARCHAR(64) NOT NULL UNIQUE,
        plan_id INT NOT NULL,
        plan_name VARCHAR(100) NOT NULL,
        days INT NOT NULL,
        traffic_gb DECIMAL(12,4) NOT NULL DEFAULT 0,
        amount DOUBLE NOT NULL,
        email VARCHAR(191) NOT NULL DEFAULT '',
        contact VARCHAR(191) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        uuid VARCHAR(64) NOT NULL DEFAULT '',
        sub_url VARCHAR(500) NOT NULL DEFAULT '',
        pay_method VARCHAR(20) NOT NULL DEFAULT '',
        trade_no VARCHAR(100) NOT NULL DEFAULT '',
        created_at VARCHAR(30) NOT NULL,
        paid_at VARCHAR(30) NOT NULL DEFAULT '',
        note VARCHAR(255) NOT NULL DEFAULT '',
        server_id INT NOT NULL DEFAULT 0,
        region VARCHAR(50) NOT NULL DEFAULT '',
        sub_token VARCHAR(64) NOT NULL DEFAULT '',
        sub_id INT NOT NULL DEFAULT 0,
        used_gb DOUBLE NOT NULL DEFAULT 0,
        KEY idx_orders_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ts VARCHAR(30) NOT NULL,
        level VARCHAR(20) NOT NULL,
        msg TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_accounts (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        server_id INT NOT NULL,
        uuid VARCHAR(64) NOT NULL DEFAULT '',
        sub_url VARCHAR(500) NOT NULL DEFAULT '',
        region VARCHAR(50) NOT NULL DEFAULT '',
        usage_gb DOUBLE NOT NULL DEFAULT 0,
        last_sync VARCHAR(30) NOT NULL DEFAULT '',
        KEY idx_oa_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS subs (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(64) NOT NULL UNIQUE,
        email VARCHAR(191) NOT NULL DEFAULT '',
        quota_gb DOUBLE NOT NULL DEFAULT 0,
        used_gb DOUBLE NOT NULL DEFAULT 0,
        expire_at VARCHAR(30) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at VARCHAR(30) NOT NULL DEFAULT '',
        last_sync VARCHAR(30) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sub_accounts (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sub_id INT NOT NULL,
        server_id INT NOT NULL,
        uuid VARCHAR(64) NOT NULL DEFAULT '',
        sub_url VARCHAR(500) NOT NULL DEFAULT '',
        region VARCHAR(50) NOT NULL DEFAULT '',
        usage_gb DOUBLE NOT NULL DEFAULT 0,
        last_sync VARCHAR(30) NOT NULL DEFAULT '',
        KEY idx_sa_sub (sub_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS stats_daily (
        d VARCHAR(20) NOT NULL PRIMARY KEY,
        used_gb DOUBLE NOT NULL DEFAULT 0,
        orders INT NOT NULL DEFAULT 0,
        revenue DOUBLE NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notices (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(30) NOT NULL,
        ref VARCHAR(160) NOT NULL,
        sent_at VARCHAR(30) NOT NULL,
        UNIQUE KEY uk_type_ref (type,ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(64) NOT NULL,
        ts VARCHAR(30) NOT NULL,
        ok TINYINT NOT NULL DEFAULT 0,
        KEY idx_la_ip (ip,ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ---------- settings ---------- */
function setting(string $k, string $default = ''): string {
    if (!isset($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache'] = [];
        foreach (db()->query("SELECT k,v FROM settings") as $r) $GLOBALS['__settings_cache'][$r['k']] = (string)$r['v'];
    }
    return array_key_exists($k, $GLOBALS['__settings_cache']) ? $GLOBALS['__settings_cache'][$k] : $default;
}
function set_setting(string $k, string $v): void {
    if (db_driver() === 'mysql') {
        $st = db()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
    } else {
        $st = db()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v");
    }
    $st->execute([$k, $v]);
    $GLOBALS['__settings_cache'][$k] = $v;
}
function gb_text($gb): string {
    $gb = (float)$gb;
    if ($gb <= 0) return '0';
    if ($gb < 1) {
        $mb = (int)round($gb * 1024);
        return max(1, $mb) . ' MB';
    }
    if ($gb >= 1024) {
        $tb = round($gb / 1024, 2);
        // 只有换算回去不丢精度才用 TB 显示（1500GB 仍显示 1500 GB，1536GB 显示 1.5 TB）
        if (abs($tb * 1024 - $gb) < 1) return rtrim(rtrim(number_format($tb, 2, '.', ''), '0'), '.') . ' TB';
    }
    return rtrim(rtrim(number_format($gb, 3, '.', ''), '0'), '.') . ' GB';
}
function has_admin(): bool { return setting('admin_user') !== '' && setting('admin_hash') !== ''; }

/* ---------- 加密（面板密码 / SMTP 密码） ---------- */
function app_secret_key(): string {
    $c = app_config();
    return hash('sha256', 'vpnshop|' . (string)($c['site_key'] ?? 'default-key'), true);
}
function secret_encrypt(string $plain): string {
    if ($plain === '') return '';
    if (!function_exists('openssl_encrypt')) return $plain;
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', app_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) return $plain;
    return 'enc1:' . base64_encode($iv . $tag . $ct);
}
function secret_decrypt(string $stored): string {
    if ($stored === '' || !str_starts_with($stored, 'enc1:')) return $stored; // 兼容旧明文
    if (!function_exists('openssl_decrypt')) return '';
    $raw = base64_decode(substr($stored, 5), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $ct = substr($raw, 28);
    $p = openssl_decrypt($ct, 'aes-256-gcm', app_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $p === false ? '' : $p;
}

/* ---------- 登录失败限流 ---------- */
function login_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cron'), 0, 60); }
function login_fails(string $ip, int $minutes = 10): int {
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND ok=0 AND ts>=?");
        $st->execute([$ip, date('Y-m-d H:i:s', time() - $minutes * 60)]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
function login_record(string $ip, bool $ok): void {
    try {
        db()->prepare("INSERT INTO login_attempts (ip,ts,ok) VALUES (?,?,?)")->execute([$ip, now(), $ok ? 1 : 0]);
        if (random_int(1, 20) === 1) {
            db()->prepare("DELETE FROM login_attempts WHERE ts<?")->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]);
        }
    } catch (Throwable $e) {}
}

/* ---------- 通知去重 ---------- */
function notice_done(string $type, string $ref): bool {
    $st = db()->prepare("SELECT 1 FROM notices WHERE type=? AND ref=? LIMIT 1");
    $st->execute([$type, $ref]);
    return (bool)$st->fetchColumn();
}
function notice_mark(string $type, string $ref): void {
    try { db()->prepare("INSERT INTO notices (type,ref,sent_at) VALUES (?,?,?)")->execute([$type, $ref, now()]); }
    catch (Throwable $e) {}
}

/* ---------- helpers ---------- */
function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function now(): string { return date('Y-m-d H:i:s'); }
function cfg(string $k, string $d = ''): string { return setting($k, $d); }
function money(float $v): string { return number_format($v, 2, '.', ''); }
function order_no(): string { return date('YmdHis') . strtoupper(bin2hex(random_bytes(3))); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    $t = $_POST['_csrf'] ?? ''; $s = $_SESSION['csrf'] ?? '';
    if (!is_string($t) || $t === '' || !is_string($s) || $s === '' || !hash_equals($s, $t)) {
        http_response_code(419); exit('会话已过期，请返回重试。');
    }
}
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = ['m' => $msg, 't' => $type]; }
function take_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function slog(string $level, string $msg): void {
    try {
        $st = db()->prepare("INSERT INTO logs (ts,level,msg) VALUES (?,?,?)");
        $st->execute([now(), $level, mb_substr($msg, 0, 4000)]);
    } catch (Throwable $e) {}
}

function is_admin(): bool {
    if (empty($_SESSION['admin'])) return false;
    $epoch = (string)setting('admin_epoch');
    if ($epoch !== '' && (string)($_SESSION['admin_epoch'] ?? '') !== $epoch) return false;
    return true;
}
function require_admin(): void { if (!is_admin()) redirect('/admin/login'); }

/* ---------- servers ---------- */
function all_servers(bool $onlyEnabled = false): array {
    $sql = "SELECT * FROM servers" . ($onlyEnabled ? " WHERE enabled=1" : "") . " ORDER BY sort,id";
    return db()->query($sql)->fetchAll();
}
function get_server(int $id): ?array {
    $st = db()->prepare("SELECT * FROM servers WHERE id=?"); $st->execute([$id]);
    $r = $st->fetch(); return $r ?: null;
}
function server_region(array $s): string {
    if (($s['region'] ?? '') !== '') return (string)$s['region'];
    if (($s['region_auto'] ?? '') !== '') return (string)$s['region_auto'];
    return '未设置';
}
function server_city(array $s): string {
    // 填了自定义地区就不再拼自动检测的城市，避免出现「美国-水牛城 · 多伦多」
    if (($s['region'] ?? '') !== '') return '';
    return (string)($s['city'] ?? '');
}
function server_label(array $s): string {
    $r = server_region($s);
    $c = server_city($s);
    return $r . ($c !== '' ? ' · ' . $c : '') . '（' . $s['name'] . '）';
}
function shop_ready(): bool { return count(all_servers(true)) > 0; }

/* ---------- region ---------- */
const REGION_MAP = [
    'JP' => '日本', 'HK' => '香港', 'TW' => '台湾', 'SG' => '新加坡', 'KR' => '韩国',
    'US' => '美国', 'GB' => '英国', 'DE' => '德国', 'NL' => '荷兰', 'FR' => '法国',
    'CA' => '加拿大', 'AU' => '澳大利亚', 'RU' => '俄罗斯', 'TR' => '土耳其',
    'IN' => '印度', 'TH' => '泰国', 'VN' => '越南', 'MY' => '马来西亚', 'PH' => '菲律宾',
    'ID' => '印尼', 'AE' => '阿联酋', 'CH' => '瑞士', 'SE' => '瑞典', 'FI' => '芬兰',
    'PL' => '波兰', 'IT' => '意大利', 'ES' => '西班牙', 'BR' => '巴西', 'MX' => '墨西哥',
    'NZ' => '新西兰', 'IE' => '爱尔兰', 'AT' => '奥地利', 'BE' => '比利时', 'DK' => '丹麦',
    'NO' => '挪威', 'CZ' => '捷克', 'HU' => '匈牙利', 'RO' => '罗马尼亚', 'BG' => '保加利亚',
    'UA' => '乌克兰', 'KZ' => '哈萨克斯坦', 'IL' => '以色列', 'SA' => '沙特', 'QA' => '卡塔尔',
    'EG' => '埃及', 'ZA' => '南非', 'NG' => '尼日利亚', 'KE' => '肯尼亚', 'CL' => '智利',
    'AR' => '阿根廷', 'CO' => '哥伦比亚', 'PE' => '秘鲁', 'PK' => '巴基斯坦', 'BD' => '孟加拉',
    'LK' => '斯里兰卡', 'NP' => '尼泊尔', 'KH' => '柬埔寨', 'MM' => '缅甸', 'LA' => '老挝',
    'MN' => '蒙古', 'MO' => '澳门', 'IS' => '冰岛', 'PT' => '葡萄牙', 'GR' => '希腊',
    'HR' => '克罗地亚', 'RS' => '塞尔维亚', 'SK' => '斯洛伐克', 'SI' => '斯洛文尼亚',
    'LT' => '立陶宛', 'LV' => '拉脱维亚', 'EE' => '爱沙尼亚', 'LU' => '卢森堡', 'MT' => '马耳他',
    'CY' => '塞浦路斯', 'GE' => '格鲁吉亚', 'AM' => '亚美尼亚', 'AZ' => '阿塞拜疆',
    'UZ' => '乌兹别克斯坦', 'BY' => '白俄罗斯', 'MD' => '摩尔多瓦', 'AL' => '阿尔巴尼亚',
    'MK' => '北马其顿', 'BA' => '波黑', 'ME' => '黑山', 'CR' => '哥斯达黎加', 'PA' => '巴拿马',
    'GT' => '危地马拉', 'DO' => '多米尼加', 'PR' => '波多黎各', 'UY' => '乌拉圭', 'PY' => '巴拉圭',
    'BO' => '玻利维亚', 'EC' => '厄瓜多尔', 'VE' => '委内瑞拉', 'TT' => '特立尼达', 'JM' => '牙买加',
    'FJ' => '斐济', 'BN' => '文莱', 'TL' => '东帝汶', 'MV' => '马尔代夫',
];

/** 机房城市中文名：ip-api 返回的多是英文，未收录的不显示，避免中文界面里蹦英文 */
function city_zh(string $city): string {
    static $m = [
        'chiyoda city' => '千代田', 'chiyoda' => '千代田', 'tokyo' => '东京', 'shinjuku' => '东京',
        'osaka' => '大阪', 'saitama' => '埼玉', 'yokohama' => '横滨', 'fukuoka' => '福冈',
        'seoul' => '首尔', 'busan' => '釜山', 'singapore' => '新加坡', 'hong kong' => '香港',
        'taipei' => '台北', 'bangkok' => '曼谷', 'kuala lumpur' => '吉隆坡', 'jakarta' => '雅加达',
        'hanoi' => '河内', 'ho chi minh city' => '胡志明市', 'manila' => '马尼拉', 'mumbai' => '孟买',
        'dubai' => '迪拜', 'los angeles' => '洛杉矶', 'san jose' => '圣何塞', 'seattle' => '西雅图',
        'dallas' => '达拉斯', 'new york' => '纽约', 'ashburn' => '阿什本', 'chicago' => '芝加哥',
        'frankfurt' => '法兰克福', 'amsterdam' => '阿姆斯特丹', 'london' => '伦敦', 'paris' => '巴黎',
        'moscow' => '莫斯科', 'sydney' => '悉尼', 'toronto' => '多伦多', 'sao paulo' => '圣保罗',
        'south brisbane' => '布里斯班', 'brisbane' => '布里斯班', 'melbourne' => '墨尔本',
        'auckland' => '奥克兰', 'wellington' => '惠灵顿', 'dublin' => '都柏林', 'madrid' => '马德里',
        'barcelona' => '巴塞罗那', 'milan' => '米兰', 'rome' => '罗马', 'vienna' => '维也纳',
        'zurich' => '苏黎世', 'geneva' => '日内瓦', 'warsaw' => '华沙', 'prague' => '布拉格',
        'budapest' => '布达佩斯', 'bucharest' => '布加勒斯特', 'sofia' => '索非亚', 'athens' => '雅典',
        'stockholm' => '斯德哥尔摩', 'oslo' => '奥斯陆', 'copenhagen' => '哥本哈根',
        'helsinki' => '赫尔辛基', 'tallinn' => '塔林', 'riga' => '里加', 'vilnius' => '维尔纽斯',
        'istanbul' => '伊斯坦布尔', 'tel aviv' => '特拉维夫', 'riyadh' => '利雅得', 'cairo' => '开罗',
        'johannesburg' => '约翰内斯堡', 'cape town' => '开普敦', 'lagos' => '拉各斯', 'nairobi' => '内罗毕',
        'santiago' => '圣地亚哥', 'buenos aires' => '布宜诺斯艾利斯', 'bogota' => '波哥大',
        'lima' => '利马', 'mexico city' => '墨西哥城', 'miami' => '迈阿密', 'atlanta' => '亚特兰大',
        'denver' => '丹佛', 'phoenix' => '凤凰城', 'portland' => '波特兰', 'vancouver' => '温哥华',
        'montreal' => '蒙特利尔', 'nagoya' => '名古屋', 'macau' => '澳门', 'kaohsiung' => '高雄',
        'taichung' => '台中', 'chiang mai' => '清迈', 'da nang' => '岘港', 'cebu' => '宿务',
        'new delhi' => '新德里', 'delhi' => '德里', 'bangalore' => '班加罗尔', 'chennai' => '金奈',
        'hyderabad' => '海得拉巴', 'kolkata' => '加尔各答', 'karachi' => '卡拉奇', 'dhaka' => '达卡',
        'colombo' => '科伦坡', 'kathmandu' => '加德满都', 'phnom penh' => '金边', 'yangon' => '仰光',
    ];
    $k = strtolower(trim($city));
    if (isset($m[$k])) return $m[$k];
    if ($k !== '' && preg_match('/\p{Han}/u', $city)) return $city; // 本来就是中文
    return '';
}

function detect_region(string $adminUrl): array {
    $host = parse_url($adminUrl, PHP_URL_HOST) ?: '';
    if ($host === '') return ['ok' => false, 'msg' => '地址无效'];
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : (gethostbyname($host) ?: '');
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) return ['ok' => false, 'msg' => '域名解析失败'];

    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: vpnshop\r\n"]]);
    $raw = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city&lang=zh-CN", false, $ctx);
    if ($raw) {
        $d = json_decode($raw, true);
        if (($d['status'] ?? '') === 'success') {
            $code = strtoupper((string)($d['countryCode'] ?? ''));
            $region = REGION_MAP[$code] ?? (string)($d['country'] ?? $code);
            return ['ok' => true, 'region' => $region, 'code' => $code, 'city' => city_zh((string)($d['city'] ?? '')), 'ip' => $ip];
        }
    }
    $raw2 = @file_get_contents("https://ipinfo.io/{$ip}/json", false, $ctx);
    if ($raw2) {
        $d2 = json_decode($raw2, true);
        if (!empty($d2['country'])) {
            $code = strtoupper((string)$d2['country']);
            return ['ok' => true, 'region' => REGION_MAP[$code] ?? $code, 'code' => $code,
                    'city' => city_zh((string)($d2['city'] ?? '')), 'ip' => $ip];
        }
    }
    return ['ok' => false, 'msg' => '地区检测失败'];
}
