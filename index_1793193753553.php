<?php
declare(strict_types=1);

/* ============================================================
   KEYS PANEL — SINGLE FILE EDITION
   ============================================================
   Everything (config, database bootstrap, auth, pricing engine,
   wallet ledger, license-key stock, orders, the JSON API, first-run
   admin setup, and the frontend UI) lives in this one index.php, per
   request. This is the exact same logic as the multi-file version —
   nothing was simplified or faked to make it fit in one file.

   Routing:
     ?api=ACTION   -> handled below, outputs JSON, exits. Never reaches
                      the HTML at the bottom of this file.
     (nothing)     -> shows the existing public login interface.
     Admin and reseller accounts use the same login form; the server
     decides the protected role after password verification.
   ============================================================
   ============================================================ */

ini_set('display_errors', '0'); // never leak PHP errors to visitors
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* ============================================================
   SECURITY HEADERS — applied before any response body.
   These are defense-in-depth controls; HTTPS must still be enabled
   by the hosting provider for production use.
   ============================================================ */
function send_security_headers(): void
{
    if (headers_sent()) return;
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Resource-Policy: same-origin');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
send_security_headers();

define('IS_API_REQUEST', isset($_GET['api']));

/* ============================================================
   CONFIG — Railway MySQL references or explicit DB_* variables.
   NEVER hard-code database credentials here; set them in the hosting
   panel's environment variable settings.
   ============================================================ */
function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

/* ============================================================
   Railway exposes MYSQL_URL and MYSQLHOST/MYSQLPORT/MYSQLUSER/
   MYSQLPASSWORD/MYSQLDATABASE on its MySQL service. Reference them
   from the PHP service Variables panel; never use localhost for the
   separate database service.
   ============================================================ */
$railwayMysqlUrl = env('MYSQL_URL', '');
$railwayMysqlParts = $railwayMysqlUrl !== '' ? parse_url($railwayMysqlUrl) : false;
$railwayMysqlParts = is_array($railwayMysqlParts) ? $railwayMysqlParts : [];
$railwayMysqlDatabase = rawurldecode(ltrim((string)($railwayMysqlParts['path'] ?? ''), '/'));
$railwayMysqlPort = isset($railwayMysqlParts['port']) ? (string)$railwayMysqlParts['port'] : '';

$CONFIG = [
    'db' => [
        // Railway MySQL variables take priority. MYSQL_URL is also supported
        // so one Railway variable reference is enough to configure the DB.
        'host'    => env('MYSQLHOST', $railwayMysqlParts['host'] ?? env('DB_HOST', '')),
        'name'    => env('MYSQLDATABASE', $railwayMysqlDatabase !== '' ? $railwayMysqlDatabase : env('DB_NAME', '')),
        'user'    => env('MYSQLUSER', isset($railwayMysqlParts['user']) ? rawurldecode((string)$railwayMysqlParts['user']) : env('DB_USER', '')),
        'pass'    => env('MYSQLPASSWORD', isset($railwayMysqlParts['pass']) ? rawurldecode((string)$railwayMysqlParts['pass']) : env('DB_PASS', '')),
        'port'    => env('MYSQLPORT', $railwayMysqlPort !== '' ? $railwayMysqlPort : env('DB_PORT', '3306')),
        'charset' => 'utf8mb4',
    ],
    // Overrides automatic domain detection for the API base URL shown in
    // documentation (useful behind a proxy/CDN). Leave null for automatic.
    'app_url_override' => env('APP_URL', null),
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN', ''), // Phase 4 — not used yet
    ],
];

/* ============================================================
   DATABASE — PDO connection + idempotent schema bootstrap
   (CREATE TABLE IF NOT EXISTS everywhere, so this never resets
   existing data — safe to run on every request).
   ============================================================ */
function db(): PDO
{
    global $CONFIG;
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $c = $CONFIG['db'];
    $hasMysqlConfig = !empty($c['host']) && !empty($c['name']) && !empty($c['user']);

    // PRODUCTION DATABASE: MySQL only. Do not silently fall back to a local
    // SQLite file, because that can create a second database and can also leave
    // sensitive data in a web-accessible directory on some shared hosts.
    if (!$hasMysqlConfig) {
        http_response_code(500);
        $message = 'Database is not configured. Add a Railway MySQL service and reference its MYSQL_URL, or configure MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQLPASSWORD and MYSQLDATABASE.';
    } elseif (!extension_loaded('pdo_mysql')) {
        http_response_code(500);
        $message = 'This hosting account does not have the PHP MySQL PDO driver enabled (pdo_mysql). Enable PDO MySQL in the hosting PHP selector.';
    } else {
        $portPart = !empty($c['port']) ? ';port=' . (int)$c['port'] : '';
        $dsn = "mysql:host={$c['host']};dbname={$c['name']}" . $portPart . ";charset={$c['charset']}";
        try {
            $pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            bootstrap_schema($pdo, 'mysql');
            return $pdo;
        } catch (PDOException $e) {
            error_log('MySQL connection failed: ' . $e->getMessage());
            http_response_code(500);
            $message = 'Database connection failed. Please check your MySQL host, database name, username, password and port.';
        }
    }
    if (IS_API_REQUEST) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status'=>'error','message'=>$message]);
    } else {
        echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Decoded Nikhil</title>
<style id="admin-user-nav-final-fix">
/* FINAL ROLE ISOLATION: the reseller bottom navigation belongs ONLY to the user app. */
body.admin-mode #app .bottom-nav,
#adminApp.visible ~ #app .bottom-nav,
#adminApp[aria-hidden="false"] ~ #app .bottom-nav{
    display:none !important;
    visibility:hidden !important;
    opacity:0 !important;
    pointer-events:none !important;
    height:0 !important;
    min-height:0 !important;
    max-height:0 !important;
    overflow:hidden !important;
}
body.admin-mode #app{display:none !important;visibility:hidden !important;pointer-events:none !important;}
</style>
<style id="api-responsive-final-v4">
/* API PAGE — cleaner, tighter and responsive on phone + desktop */
#apiPage .api-video-wrap,#apiDocsPage .api-docs-wrap,#apiProductsPage .api-docs-wrap{
    width:min(100%,920px); margin:0 auto; box-sizing:border-box;
}
#apiPage .api-video-wrap{padding:14px 10px 110px}
#apiPage .api-video-card{padding:24px 28px 26px;border-radius:14px;margin-bottom:14px}
#apiPage .api-wallet-video{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;padding:15px 18px;border-radius:14px}
#apiPage .api-video-keybox{min-width:0}
#apiPage .api-video-keybox code{min-width:0}
#apiPage .api-video-title{font-size:14px;letter-spacing:2px}
#apiPage .api-video-gold-btn,#apiPage .api-video-dark-btn{transition:transform .15s ease,filter .15s ease}
#apiPage .api-video-gold-btn:active,#apiPage .api-video-dark-btn:active{transform:scale(.985)}
#apiPage .api-variants-video-card{overflow:visible}
#apiPage .api-video-product{border-radius:10px}
#apiPage .api-video-plan-row{grid-template-columns:72px minmax(0,1fr) auto}
#apiPage .api-video-plan-info{min-width:0}
#apiPage .api-video-plan-info b{overflow-wrap:anywhere}
#apiPage .api-video-copy-id{cursor:pointer}
#apiDocsPage,#apiProductsPage{padding:12px 10px 110px!important}
#apiDocsPage .api-card,#apiProductsPage .api-card{box-sizing:border-box}
#apiProductsPage .api-duration-row{align-items:center}
@media (min-width:801px){
  #apiPage .api-video-wrap{padding-left:0;padding-right:0}
  #apiPage .api-video-card{padding:26px 34px 28px}
  #apiPage .api-video-product{max-width:100%}
  #apiPage .api-video-plan-row{grid-template-columns:86px minmax(0,1fr) 82px}
  #apiDocsPage .api-card,#apiProductsPage .api-card{padding:22px 24px}
}
@media (max-width:600px){
  #apiPage .api-video-wrap{padding:8px 0 100px}
  #apiPage .api-wallet-video{font-size:14px;padding:13px 14px}
  #apiPage .api-wallet-video b{font-size:18px}
  #apiPage .api-video-card{padding:19px 16px 20px;border-radius:12px;margin-bottom:12px}
  #apiPage .api-video-title{font-size:11px;letter-spacing:1.5px;gap:7px;margin-bottom:14px}
  #apiPage .api-video-title i{margin-left:3px}
  #apiPage .api-video-keybox{padding:10px;min-height:52px}
  #apiPage .api-video-keybox code{font-size:10px}
  #apiPage .api-video-keybox button{padding:8px 9px;font-size:10px}
  #apiPage .api-video-dark-btn,#apiPage .api-video-gold-btn{height:50px;font-size:12px;letter-spacing:1.3px}
  #apiPage .api-video-plan-row{grid-template-columns:54px minmax(0,1fr);gap:8px;padding:10px}
  #apiPage .api-video-plan-id{font-size:11px;padding:8px 4px}
  #apiPage .api-video-plan-info b{font-size:13px}
  #apiPage .api-video-plan-info span{font-size:9px}
  #apiPage .api-video-copy-id{grid-column:2;justify-self:start;padding:6px 9px;font-size:9px}
  #apiPage .api-variants-title strong{font-size:8px;padding:6px 7px;letter-spacing:1px}
  #apiDocsPage,#apiProductsPage{padding:8px 0 100px!important}
  #apiDocsPage .api-card,#apiProductsPage .api-card{padding:14px;border-radius:13px;margin-bottom:11px}
  #apiDocsPage .api-heading{font-size:11px;letter-spacing:1.5px}
  #apiDocsPage .api-endpoint strong{font-size:12px}
}
@media (max-width:360px){
  #apiPage .api-video-card{padding-left:13px;padding-right:13px}
  #apiPage .api-video-title{font-size:10px}
  #apiPage .api-video-plan-row{grid-template-columns:48px minmax(0,1fr)}
}
</style>

<style id="login-reference-design-v1">
/* LOGIN REFERENCE DESIGN — visual-only refresh; authentication logic untouched. */
#loginScreen{
  min-height:100vh!important;
  min-height:100dvh!important;
  box-sizing:border-box!important;
  padding:clamp(24px,5vh,64px) 16px!important;
  align-items:flex-start!important;
  justify-content:center!important;
  overflow-x:hidden!important;
  background-color:#070b14!important;
  background-image:
    linear-gradient(rgba(148,163,184,.075) 1px,transparent 1px),
    linear-gradient(90deg,rgba(148,163,184,.075) 1px,transparent 1px),
    radial-gradient(circle at 50% 34%,rgba(212,175,55,.075),transparent 35%),
    radial-gradient(circle at 50% 100%,rgba(15,23,42,.75),transparent 55%)!important;
  background-size:70px 70px,70px 70px,100% 100%,100% 100%!important;
}
#loginScreen::before,
#loginScreen::after{
  content:"";position:absolute;pointer-events:none;border-radius:50%;filter:blur(.2px);
  background:rgba(212,175,55,.045);border:1px solid rgba(212,175,55,.035);
}
#loginScreen::before{width:170px;height:170px;left:-95px;top:28%;}
#loginScreen::after{width:220px;height:220px;right:-135px;top:18%;}
.login-wrapper{
  width:min(100%,820px)!important;
  max-width:820px!important;
  margin:0 auto!important;
  padding-top:clamp(26px,7vh,105px)!important;
  text-align:center!important;
  position:relative!important;
  z-index:1!important;
}
.login-logo{
  width:clamp(118px,22vw,205px)!important;
  height:clamp(118px,22vw,205px)!important;
  margin:0 auto clamp(22px,3.4vw,34px)!important;
  border-radius:clamp(14px,2.4vw,20px)!important;
  border:2px solid rgba(212,175,55,.72)!important;
  background:linear-gradient(145deg,#2d3b51,#111b2c 58%,#080e18)!important;
  box-shadow:0 18px 55px rgba(0,0,0,.48),0 0 34px rgba(212,175,55,.12),inset 0 0 0 6px rgba(0,0,0,.18)!important;
}
.login-star{font-size:clamp(48px,8vw,78px)!important;color:#f2cb4d!important;}
.login-brand{
  font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace!important;
  font-size:clamp(35px,6.1vw,60px)!important;
  line-height:1!important;
  font-weight:900!important;
  letter-spacing:clamp(1px,.25vw,4px)!important;
  color:#f8fafc!important;
  text-transform:uppercase!important;
  text-shadow:0 2px 18px rgba(0,0,0,.35)!important;
}
.login-brand::first-letter{color:#fff!important;}
.login-subtitle{
  margin-top:clamp(17px,2.6vw,26px)!important;
  color:#9ba7bd!important;
  font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace!important;
  font-size:clamp(15px,2.45vw,28px)!important;
  letter-spacing:clamp(.2px,.12vw,1.5px)!important;
}
.login-card{
  width:100%!important;
  box-sizing:border-box!important;
  margin-top:clamp(28px,5vw,52px)!important;
  padding:clamp(38px,5.2vw,54px) clamp(30px,5.5vw,55px) clamp(30px,4.2vw,43px)!important;
  border-radius:10px!important;
  text-align:left!important;
  background:#17223a!important;
  border:1px solid rgba(148,163,184,.28)!important;
  box-shadow:0 28px 80px rgba(0,0,0,.46),inset 0 1px 0 rgba(255,255,255,.025)!important;
  position:relative!important;
}
.login-card::before,
.login-card::after{
  content:"";position:absolute;pointer-events:none;background:#e1bc36;
}
.login-card::before{left:-1px;top:-1px;width:31px;height:31px;clip-path:polygon(0 0,100% 0,100% 4px,4px 4px,4px 100%,0 100%);}
.login-card::after{right:-1px;bottom:-1px;width:31px;height:31px;clip-path:polygon(0 100%,100% 100%,100% 0,96% 0,96% calc(100% - 4px),0 calc(100% - 4px));}
.auth-badge{
  width:100%!important;
  max-width:590px!important;
  box-sizing:border-box!important;
  margin:0 auto clamp(34px,4.2vw,44px)!important;
  padding:clamp(15px,2vw,20px) 18px!important;
  border-radius:7px!important;
  border:2px solid rgba(212,175,55,.75)!important;
  background:#111c31!important;
  color:#e7c23e!important;
  font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace!important;
  font-size:clamp(14px,2.15vw,23px)!important;
  font-weight:900!important;
  letter-spacing:clamp(1px,.25vw,3px)!important;
  text-align:center!important;
  white-space:nowrap!important;
  overflow:hidden!important;
  text-overflow:ellipsis!important;
}
.auth-badge:before{content:"[  🔐  ";margin-right:0!important;}
.login-field{margin-bottom:clamp(27px,3.5vw,37px)!important;}
.login-label{
  margin-bottom:clamp(10px,1.6vw,16px)!important;
  color:#e3bf42!important;
  font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace!important;
  font-size:clamp(13px,1.7vw,19px)!important;
  font-weight:900!important;
  letter-spacing:clamp(1.5px,.25vw,3px)!important;
}
.login-label::before{content:"#  ";}
.login-input{
  height:clamp(58px,7.2vw,80px)!important;
  box-sizing:border-box!important;
  padding:0 clamp(18px,2.5vw,30px)!important;
  border:1px solid rgba(100,116,139,.38)!important;
  border-radius:7px!important;
  background:#070d1d!important;
  color:#f8fafc!important;
  font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif!important;
  font-size:clamp(17px,2.5vw,27px)!important;
}
.login-input::placeholder{color:#6f7b93!important;opacity:1!important;}
.password-input{padding-right:60px!important;}
.eye-btn{right:13px!important;width:42px!important;height:42px!important;color:#9aa6bc!important;}
.eye-btn svg{width:29px!important;height:29px!important;}
.login-btn{
  width:100%!important;
  height:clamp(62px,8vw,88px)!important;
  margin-top:clamp(2px,1vw,8px)!important;
  border-radius:0 0 8px 8px!important;
  cursor:pointer!important;
  background:#e0b932!important;
  color:#080b12!important;
  font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace!important;
  font-size:clamp(19px,2.8vw,30px)!important;
  font-weight:900!important;
  letter-spacing:clamp(1px,.2vw,3px)!important;
  clip-path:polygon(0 0,96% 0,100% 25%,100% 100%,4% 100%,0 75%)!important;
  box-shadow:none!important;
}
.login-btn:hover{filter:brightness(1.06)!important;transform:none!important;box-shadow:0 8px 28px rgba(212,175,55,.16)!important;}
.login-btn:active{transform:translateY(1px)!important;}
.login-message{font-size:12px!important;margin-top:13px!important;}
.login-footer{display:none!important;}
@media(max-width:600px){
  #loginScreen{padding:18px 10px!important;background-size:58px 58px,58px 58px,100% 100%,100% 100%!important;}
  .login-wrapper{padding-top:clamp(18px,6vh,48px)!important;}
  .login-logo{margin-bottom:20px!important;}
  .login-card{padding:28px 22px 26px!important;margin-top:27px!important;}
  .auth-badge{font-size:12px!important;padding:13px 10px!important;}
  .login-field{margin-bottom:24px!important;}
}
@media(max-width:380px){
  .login-brand{font-size:31px!important;}
  .login-subtitle{font-size:13px!important;}
  .login-card{padding:24px 16px 22px!important;}
  .auth-badge{font-size:10px!important;letter-spacing:1px!important;}
  .login-input{font-size:16px!important;}
  .login-label{font-size:12px!important;}
}
</style>

</head><body></body></html>';
    }
    exit;
}

function bootstrap_schema(PDO $pdo, string $driver = 'mysql'): void
{
    static $done = [];
    if (!empty($done[$driver])) return;
    $done[$driver] = true;

    if ($driver === 'sqlite') {
        $statements = [
            "CREATE TABLE IF NOT EXISTS admins (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS resellers (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, account_type TEXT NOT NULL DEFAULT 'reseller', mobile TEXT DEFAULT NULL, email TEXT DEFAULT NULL, telegram_username TEXT DEFAULT NULL, telegram_chat_id INTEGER DEFAULT NULL, status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS wallets (reseller_id INTEGER PRIMARY KEY, balance NUMERIC NOT NULL DEFAULT 0, FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS wallet_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, reseller_id INTEGER NOT NULL, type TEXT NOT NULL, amount NUMERIC NOT NULL, balance_after NUMERIC NOT NULL, note TEXT DEFAULT NULL, admin_id INTEGER DEFAULT NULL, order_id INTEGER DEFAULT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE, FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE SET NULL)",
            "CREATE TABLE IF NOT EXISTS api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, reseller_id INTEGER NOT NULL, key_hash TEXT NOT NULL UNIQUE, key_prefix TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, revoked_at TEXT DEFAULT NULL, FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER NOT NULL, name TEXT NOT NULL, description TEXT, image TEXT DEFAULT NULL, status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(category_id) REFERENCES categories(id))",
            "CREATE TABLE IF NOT EXISTS plans (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, duration_label TEXT NOT NULL, default_price NUMERIC NOT NULL, status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS custom_prices (id INTEGER PRIMARY KEY AUTOINCREMENT, reseller_id INTEGER NOT NULL, plan_id INTEGER NOT NULL, custom_price NUMERIC NOT NULL, status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(reseller_id,plan_id), FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE, FOREIGN KEY(plan_id) REFERENCES plans(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS license_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INTEGER NOT NULL, key_value TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'available', order_id INTEGER DEFAULT NULL, added_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, sold_at TEXT DEFAULT NULL, FOREIGN KEY(plan_id) REFERENCES plans(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY AUTOINCREMENT, order_code TEXT NOT NULL UNIQUE, reseller_id INTEGER NOT NULL, plan_id INTEGER NOT NULL, product_name TEXT NOT NULL, duration_label TEXT NOT NULL, quantity INTEGER NOT NULL DEFAULT 1, unit_price NUMERIC NOT NULL, total_price NUMERIC NOT NULL, price_source TEXT NOT NULL, license_key TEXT, custom_notes TEXT DEFAULT NULL, source TEXT NOT NULL DEFAULT 'web', status TEXT NOT NULL DEFAULT 'pending', idempotency_key TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(reseller_id) REFERENCES resellers(id), FOREIGN KEY(plan_id) REFERENCES plans(id))",
            "CREATE TABLE IF NOT EXISTS deposits (id INTEGER PRIMARY KEY AUTOINCREMENT, reseller_id INTEGER NOT NULL, amount NUMERIC NOT NULL, method TEXT NOT NULL, reference TEXT DEFAULT NULL, note TEXT DEFAULT NULL, status TEXT NOT NULL DEFAULT 'pending', reviewed_by INTEGER DEFAULT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_at TEXT DEFAULT NULL, FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE, FOREIGN KEY(reviewed_by) REFERENCES admins(id) ON DELETE SET NULL)",
            "CREATE TABLE IF NOT EXISTS settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)",
            "CREATE TABLE IF NOT EXISTS audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER DEFAULT NULL, action TEXT NOT NULL, target TEXT DEFAULT NULL, details TEXT, ip_address TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE SET NULL)"
        ];
    } else {
        // Original MySQL schema is preserved.
        $statements = [
            "CREATE TABLE IF NOT EXISTS admins (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS resellers (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, account_type ENUM('reseller','user') NOT NULL DEFAULT 'reseller', mobile VARCHAR(20) DEFAULT NULL, email VARCHAR(150) DEFAULT NULL, telegram_username VARCHAR(64) DEFAULT NULL, telegram_chat_id BIGINT DEFAULT NULL, status ENUM('active','disabled','banned') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_resellers_account_type(account_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS wallets (reseller_id INT UNSIGNED PRIMARY KEY, balance DECIMAL(12,2) NOT NULL DEFAULT 0.00, FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS wallet_transactions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reseller_id INT UNSIGNED NOT NULL, type ENUM('credit','debit','refund','purchase','adjustment') NOT NULL, amount DECIMAL(12,2) NOT NULL, balance_after DECIMAL(12,2) NOT NULL, note VARCHAR(255) DEFAULT NULL, admin_id INT UNSIGNED DEFAULT NULL, order_id INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE, FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE SET NULL, INDEX idx_wtx_reseller(reseller_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS api_keys (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reseller_id INT UNSIGNED NOT NULL, key_hash CHAR(64) NOT NULL, key_prefix VARCHAR(12) NOT NULL, status ENUM('active','revoked') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, revoked_at DATETIME DEFAULT NULL, FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE, UNIQUE KEY uniq_key_hash(key_hash), INDEX idx_api_reseller(reseller_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS categories (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, sort_order INT NOT NULL DEFAULT 0, status ENUM('active','disabled') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS products (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id INT UNSIGNED NOT NULL, name VARCHAR(150) NOT NULL, description TEXT, image VARCHAR(255) DEFAULT NULL, status ENUM('active','disabled') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(category_id) REFERENCES categories(id), INDEX idx_products_category(category_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS plans (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, duration_label VARCHAR(50) NOT NULL, default_price DECIMAL(10,2) NOT NULL, status ENUM('active','disabled') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE, INDEX idx_plans_product(product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS custom_prices (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reseller_id INT UNSIGNED NOT NULL, plan_id INT UNSIGNED NOT NULL, custom_price DECIMAL(10,2) NOT NULL, status ENUM('active','disabled') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE, FOREIGN KEY(plan_id) REFERENCES plans(id) ON DELETE CASCADE, UNIQUE KEY uniq_reseller_plan(reseller_id,plan_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS license_keys (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, plan_id INT UNSIGNED NOT NULL, key_value VARCHAR(255) NOT NULL, status ENUM('available','reserved','sold','disabled') NOT NULL DEFAULT 'available', order_id INT UNSIGNED DEFAULT NULL, added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, sold_at DATETIME DEFAULT NULL, FOREIGN KEY(plan_id) REFERENCES plans(id) ON DELETE CASCADE, INDEX idx_keys_lookup(plan_id,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS orders (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_code VARCHAR(20) NOT NULL UNIQUE, reseller_id INT UNSIGNED NOT NULL, plan_id INT UNSIGNED NOT NULL, product_name VARCHAR(150) NOT NULL, duration_label VARCHAR(50) NOT NULL, quantity INT UNSIGNED NOT NULL DEFAULT 1, unit_price DECIMAL(10,2) NOT NULL, total_price DECIMAL(10,2) NOT NULL, price_source ENUM('custom','default') NOT NULL, license_key TEXT, custom_notes VARCHAR(255) DEFAULT NULL, source ENUM('web','api','telegram') NOT NULL DEFAULT 'web', status ENUM('pending','success','failed','refunded','cancelled') NOT NULL DEFAULT 'pending', idempotency_key VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(reseller_id) REFERENCES resellers(id), FOREIGN KEY(plan_id) REFERENCES plans(id), INDEX idx_orders_reseller(reseller_id), UNIQUE KEY uniq_reseller_idempotency(reseller_id,idempotency_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS deposits (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reseller_id INT UNSIGNED NOT NULL, amount DECIMAL(12,2) NOT NULL, method VARCHAR(50) NOT NULL, reference VARCHAR(100) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending', reviewed_by INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_at DATETIME DEFAULT NULL, FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE, FOREIGN KEY(reviewed_by) REFERENCES admins(id) ON DELETE SET NULL, INDEX idx_deposits_reseller(reseller_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT)",
            "CREATE TABLE IF NOT EXISTS audit_logs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id INT UNSIGNED DEFAULT NULL, action VARCHAR(100) NOT NULL, target VARCHAR(150) DEFAULT NULL, details TEXT, ip_address VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS login_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip_hash CHAR(64) NOT NULL, username_hash CHAR(64) NOT NULL, failed_count INT UNSIGNED NOT NULL DEFAULT 0, first_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, locked_until DATETIME DEFAULT NULL, UNIQUE KEY uniq_login_attempt(ip_hash,username_hash), INDEX idx_login_attempts_last(last_failed_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];
    }
    foreach ($statements as $sql) $pdo->exec($sql);

    // api_logs — full request/order audit trail for the Admin API Reports
    // screen. Created separately (not in the two schema arrays above) so it
    // is easy to see this is purely additive and touches no existing table.
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, reseller_id INTEGER DEFAULT NULL, api_key_id INTEGER DEFAULT NULL, action TEXT NOT NULL, product_id INTEGER DEFAULT NULL, plan_id INTEGER DEFAULT NULL, quantity INTEGER DEFAULT NULL, order_id INTEGER DEFAULT NULL, order_code TEXT DEFAULT NULL, status TEXT NOT NULL, http_status INTEGER NOT NULL DEFAULT 200, message TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_logs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reseller_id INT UNSIGNED DEFAULT NULL, api_key_id INT UNSIGNED DEFAULT NULL, action VARCHAR(50) NOT NULL, product_id INT UNSIGNED DEFAULT NULL, plan_id INT UNSIGNED DEFAULT NULL, quantity INT DEFAULT NULL, order_id INT UNSIGNED DEFAULT NULL, order_code VARCHAR(20) DEFAULT NULL, status ENUM('success','failed') NOT NULL, http_status SMALLINT NOT NULL DEFAULT 200, message VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_api_logs_reseller(reseller_id), INDEX idx_api_logs_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Additive column + value migrations for existing installs. Every
    // statement is wrapped so a "column already exists" error on a
    // second run is silently ignored — nothing here ever drops data.
    migrate_add_columns($pdo, $driver);
    bootstrap_admin_from_env($pdo);
}

/**
 * Create the configured admin from hosting environment variables.
 * Existing admin accounts and passwords are never modified or replaced.
 */
function configured_admin_credentials(): ?array
{
    // Support common hosting variable names and lowercase spellings.
    // Variable values are never logged.
    $username = trim((string)(
        env('ADMIN_USERNAME')
        ?? env('ADMIN_USER')
        ?? env('ADMINUSER')
        ?? env('adminuser')
        ?? ''
    ));
    $password = (string)(
        env('ADMIN_PASSWORD')
        ?? env('ADMIN_PASS')
        ?? env('ADMINPASSWORD')
        ?? env('adminpassword')
        ?? env('admin_password')
        ?? ''
    );

    if ($username === '' || $password === '') return null;
    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username) || strlen($password) < 8 || strlen($password) > 128) {
        return null;
    }
    return ['username' => $username, 'password' => $password];
}

function bootstrap_admin_from_env(PDO $pdo): void
{
    $credentials = configured_admin_credentials();
    if ($credentials === null) return;
    $username = $credentials['username'];
    $password = $credentials['password'];

    try {
        // Keep existing accounts intact, but create the configured account even
        // if other admins are already present.
        $existing = $pdo->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
        $existing->execute([$username]);
        if ($existing->fetchColumn() !== false) return;

        $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (Throwable $e) {
        // Another request may have created this same admin concurrently.
        try {
            $existing = $pdo->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
            $existing->execute([$username]);
            if ($existing->fetchColumn() !== false) return;
        } catch (Throwable $ignored) {
            // Fall through to the generic, credential-safe log message.
        }
        error_log('Initial admin setup failed. Check database permissions and admin environment variable names.');
    }
}

function migrate_add_columns(PDO $pdo, string $driver): void
{
    $add = function (string $table, string $column, string $sqliteDef, string $mysqlDef) use ($pdo, $driver) {
        try {
            if ($driver === 'sqlite') {
                $cols = $pdo->query("PRAGMA table_info($table)")->fetchAll();
                foreach ($cols as $c) { if (strcasecmp((string)$c['name'], $column) === 0) return; }
                $pdo->exec("ALTER TABLE $table ADD COLUMN $column $sqliteDef");
            } else {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $column $mysqlDef");
            }
        } catch (Throwable $e) {
            // Column already exists (or, on a very old MySQL, no IF NOT EXISTS
            // support) — safe to ignore, existing data is never touched.
        }
    };

    $add('products', 'youtube_url', "TEXT DEFAULT NULL", "VARCHAR(255) DEFAULT NULL");
    $add('products', 'mode', "TEXT NOT NULL DEFAULT 'local'", "VARCHAR(10) NOT NULL DEFAULT 'local'");
    $add('products', 'maintenance', "TEXT NOT NULL DEFAULT 'off'", "VARCHAR(3) NOT NULL DEFAULT 'off'");

    $add('plans', 'cost_price', "NUMERIC NOT NULL DEFAULT 0", "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    $add('plans', 'provider_product_id', "TEXT DEFAULT NULL", "VARCHAR(100) DEFAULT NULL");
    $add('plans', 'dripstore_variant_id', "TEXT DEFAULT NULL", "VARCHAR(100) DEFAULT NULL");
    $add('plans', 'provider_type', "TEXT DEFAULT NULL", "VARCHAR(20) DEFAULT NULL");
    $add('plans', 'stock_mode', "TEXT NOT NULL DEFAULT 'local'", "VARCHAR(10) NOT NULL DEFAULT 'local'");
    $add('plans', 'youtube_url', "TEXT DEFAULT NULL", "VARCHAR(255) DEFAULT NULL");

    // Backward-compatible provider migration: old stock_mode=api means KEYSPANEL.
    // New DRIPSTORE plans use provider_type=dripstore and a separate variant mapping.
    try {
        if ($driver === 'sqlite') {
            $pdo->exec("UPDATE plans SET provider_type = CASE WHEN stock_mode='api' THEN 'keyspanel' ELSE 'local' END WHERE provider_type IS NULL OR provider_type=''");
        } else {
            $pdo->exec("UPDATE plans SET provider_type = CASE WHEN stock_mode='api' THEN 'keyspanel' ELSE 'local' END WHERE provider_type IS NULL OR provider_type=''");
        }
    } catch (Throwable $e) {}

    $add('orders', 'api_key_id', "INTEGER DEFAULT NULL", "INT UNSIGNED DEFAULT NULL");
    // Safe (no-secret) KEYSPANEL request/response diagnostics for the most recent
    // provider attempt on this order — HTTP status, JSON validity, provider ok/error,
    // etc. Never contains the master key. Used to find the real cause of a
    // "Provider response could not be confirmed" pending order.
    $add('orders', 'provider_diagnostics', "TEXT DEFAULT NULL", "TEXT DEFAULT NULL");
    $add('deposits', 'gateway_order_id', "TEXT DEFAULT NULL", "VARCHAR(64) DEFAULT NULL");
    $add('deposits', 'gateway_txn_id', "TEXT DEFAULT NULL", "VARCHAR(64) DEFAULT NULL");
    $add('deposits', 'gateway_status', "TEXT DEFAULT NULL", "VARCHAR(32) DEFAULT NULL");
    $add('deposits', 'gateway_response', "TEXT DEFAULT NULL", "TEXT DEFAULT NULL");

    // Self-signup "user" accounts (fixed pricing, no API access) alongside existing resellers.
    $add('resellers', 'account_type', "TEXT NOT NULL DEFAULT 'reseller'", "VARCHAR(10) NOT NULL DEFAULT 'reseller'");
    $add('resellers', 'mobile', "TEXT DEFAULT NULL", "VARCHAR(20) DEFAULT NULL");
    $add('resellers', 'email', "TEXT DEFAULT NULL", "VARCHAR(150) DEFAULT NULL");

    if ($driver !== 'sqlite') {
        // MySQL ENUMs must be widened explicitly to accept the new 'banned' status.
        try { $pdo->exec("ALTER TABLE resellers MODIFY status ENUM('active','disabled','banned') NOT NULL DEFAULT 'active'"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE resellers MODIFY account_type ENUM('reseller','user') NOT NULL DEFAULT 'reseller'"); } catch (Throwable $e) {}
    }

    // Seed default settings rows the first time only (INSERT IGNORE / OR IGNORE — never overwrites an admin's saved value).
    $defaults = [
        'global_maintenance' => 'off',
        'global_maintenance_message' => 'We are performing scheduled maintenance. Please check back shortly.',
        'support_url' => '',
        'group_update_url' => '',
        'panel_name' => 'Decoded Nikhil',
        'panel_logo' => '',
        'admin_name' => 'Decoded Nikhil Admin',
        'admin_logo' => '',
        'login_name' => 'Decoded Nikhil',
        'login_logo' => '',
        'splash_name' => 'Decoded Nikhil',
        'splash_logo' => '',
        'splash_kicker' => '🔐 SECURE ACCESS POINT',
        'splash_welcome' => 'WELCOME TO',
        'splash_portal' => 'RESELLER PORTAL',
        'splash_subtitle' => 'Setting things up, taking you to sign in...',
        'splash_loading_text' => 'REDIRECTING AUTOMATICALLY',
        'login_subtitle' => '🔐 Ressler Portal — sign in to continue',
        'login_badge' => 'RESSLER PORTAL SIGN IN',
        'login_username_label' => 'Username',
        'login_username_placeholder' => 'Your username',
        'login_password_label' => 'Password',
        'login_password_placeholder' => 'Your password',
        'login_button_text' => '🔐  Log In  →',
        'login_message' => 'Please enter your login details.',
        'login_footer' => 'Authorized personnel only',
        'deposit_min_amount' => '10',
        'deposit_max_amount' => '100000',
        'sound_enabled' => 'on',
        'paytm_enabled' => 'off',
        'paytm_environment' => 'production',
        'paytm_mid' => '',
        'paytm_upi_id' => '',
        'provider_api_enabled' => 'off',
        'provider_api_url' => '',
        'provider_api_token' => '',
        'dripstore_api_enabled' => 'off',
        'dripstore_api_url' => 'https://dripclientstore.shop/api/v1',
        'dripstore_api_token' => '',
        'dripstore_api_token_updated_at' => '',
        // Optional outbound proxy for KEYSPANEL calls only. Exists because a
        // 403 "Request forbidden by administrative rules" from KEYSPANEL is an
        // edge/load-balancer ACL rejection (see keyspanel_detect_waf_signature),
        // most commonly an IP allowlist that only recognizes the bot's egress
        // IP. Routing this server's KEYSPANEL requests through a proxy running
        // on that same allowlisted host/IP lets the website purchase through
        // without KEYSPANEL support having to allowlist a second IP. Leave
        // empty to send requests directly (no proxy) — the default, and no
        // behavior change for anyone who doesn't hit this IP-block scenario.
        // Format: http://host:port, http://user:pass@host:port, or
        // socks5://host:port (socks5h:// to resolve DNS through the proxy too).
        'provider_api_proxy' => '',
        'paytm_upi_name' => 'Decoded Nikhil',
        'paytm_client_id' => '',
        'paytm_pos_id' => '',
        'paytm_display_name' => 'Decoded Nikhil',
        'zapupi_enabled' => 'off',
        'zapupi_key_enc' => '',
    ];
    foreach ($defaults as $k => $v) {
        try {
            if ($driver === 'sqlite') {
                $pdo->prepare('INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (?,?)')->execute([$k, $v]);
            } else {
                $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?,?)')->execute([$k, $v]);
            }
        } catch (Throwable $e) {}
    }

    // Update only untouched old defaults; preserve any custom branding.
    $brandingMigrations = [
        ['panel_name', 'KEYS PANEL', 'Decoded Nikhil'],
        ['admin_name', 'KEYS PANEL ADMIN', 'Decoded Nikhil Admin'],
        ['login_name', 'FLASH STAR', 'Decoded Nikhil'],
        ['splash_name', 'FLASH STAR', 'Decoded Nikhil'],
        ['paytm_upi_name', 'KEYS PANEL', 'Decoded Nikhil'],
        ['paytm_display_name', 'KEYS PANEL', 'Decoded Nikhil'],
    ];
    foreach ($brandingMigrations as [$settingKey, $oldValue, $newValue]) {
        $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=? AND setting_value=?')
            ->execute([$newValue, $settingKey, $oldValue]);
    }
}

function get_setting(string $key, string $default = ''): string
{
    if (!isset($GLOBALS['__settings_runtime_cache']) || !is_array($GLOBALS['__settings_runtime_cache'])) {
        $GLOBALS['__settings_runtime_cache'] = [];
    }
    if (array_key_exists($key, $GLOBALS['__settings_runtime_cache'])) return (string)$GLOBALS['__settings_runtime_cache'][$key];
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return (string)($GLOBALS['__settings_runtime_cache'][$key] = ($val === false ? $default : (string)$val));
}

function set_setting(string $key, string $value): void
{
    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value')->execute([$key, $value]);
    } else {
        $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key, $value]);
    }
    // Keep the in-request cache synchronized with the database write.
    $GLOBALS['__settings_runtime_cache'][$key] = $value;
}

function paytm_secret_key(): string
{
    global $CONFIG;
    $secret = env('PAYTM_CONFIG_SECRET', env('APP_SECRET', null));
    if ($secret !== null && $secret !== '') return hash('sha256', $secret, true);
    $db = $CONFIG['db'] ?? [];
    return hash('sha256', 'keys-panel-paytm|' . ($db['name'] ?? '') . '|' . ($db['user'] ?? '') . '|' . ($db['pass'] ?? ''), true);
}
function paytm_encrypt_secret(string $plaintext): string
{
    if ($plaintext === '') return '';
    $iv=random_bytes(12); $tag='';
    $cipher=openssl_encrypt($plaintext,'aes-256-gcm',paytm_secret_key(),OPENSSL_RAW_DATA,$iv,$tag,'',16);
    if($cipher===false) throw new RuntimeException('Could not encrypt Paytm credential.');
    return base64_encode($iv.$tag.$cipher);
}
function paytm_decrypt_secret(string $encoded): string
{
    if($encoded==='') return '';
    $raw=base64_decode($encoded,true); if($raw===false||strlen($raw)<28)return '';
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',paytm_secret_key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'');
    return $plain===false?'':(string)$plain;
}
function paytm_config(): array
{
    // Paytm Business Auto Payment uses ONLY the same two merchant values as the supplied bot:
    // 1) Paytm MID and 2) Paytm UPI ID. The UPI display name is taken from the panel name.
    return [
        'enabled'=>get_setting('paytm_enabled','off')==='on',
        'mid'=>trim(get_setting('paytm_mid','')),
        'upi_id'=>trim(get_setting('paytm_upi_id','')),
        'upi_name'=>trim(get_setting('paytm_display_name','Decoded Nikhil'))?:'Decoded Nikhil'
    ];
}
/* ============================================================
   ZAPUPI — payment gateway (redirect flow + webhook).
   The ZAP KEY is stored ENCRYPTED (same AES-256-GCM helpers as Paytm)
   and is never sent back to the browser.
   ============================================================ */
function zapupi_config(): array
{
    return [
        'enabled' => get_setting('zapupi_enabled','off')==='on',
        'key'     => paytm_decrypt_secret(get_setting('zapupi_key_enc','')),
    ];
}
function zapupi_post(string $endpoint, array $payload): array
{
    $ch = curl_init('https://pay.zapupi.com/api/' . ltrim($endpoint,'/'));
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>25,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('ZapUPI connection error.');
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) throw new RuntimeException('ZapUPI returned an invalid response (HTTP '.$code.').');
    return $json;
}
function zapupi_app_base_url(): string
{
    global $CONFIG;
    $o = trim((string)($CONFIG['app_url_override'] ?? ''));
    if ($o !== '') return rtrim($o, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host  = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path  = strtok((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '?');
    return ($https ? 'https' : 'http') . '://' . $host . $path;
}
/* Verify with ZapUPI (server-to-server) and credit the wallet exactly once.
   Returns 'SUCCESS' | 'FAILURE' | 'PENDING'. Safe to call from webhook AND poller. */
function zapupi_settle_deposit(int $depositId): string
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND method='zapupi' LIMIT 1");
    $st->execute([$depositId]); $dep = $st->fetch();
    if (!$dep) return 'PENDING';
    if (($dep['status'] ?? '') === 'approved') return 'SUCCESS';
    if (($dep['status'] ?? '') !== 'pending') return 'FAILURE';

    $cfg = zapupi_config();
    if ($cfg['key'] === '') return 'PENDING';
    try {
        $res = zapupi_post('order-status', ['zap_key'=>$cfg['key'], 'order_id'=>(string)$dep['gateway_order_id']]);
    } catch (Throwable $e) { return 'PENDING'; }
    if (($res['status'] ?? '') !== 'success' || !is_array($res['data'] ?? null)) return 'PENDING';
    $d = $res['data'];
    $zStatus = ucfirst(strtolower(trim((string)($d['status'] ?? 'Pending'))));
    $txnId   = trim((string)($d['txn_id'] ?? ''));
    $utr     = trim((string)($d['utr'] ?? ''));
    $env     = strtolower(trim((string)($d['environment'] ?? '')));

    if ($zStatus === 'Failed') {
        $pdo->prepare("UPDATE deposits SET status='rejected',gateway_status='FAILED',gateway_txn_id=?,gateway_response=? WHERE id=? AND status='pending'")
            ->execute([$txnId!==''?$txnId:null, substr(json_encode($d),0,2000), $depositId]);
        return 'FAILURE';
    }
    if ($zStatus !== 'Success') return 'PENDING';

    // Test-environment payments must never credit real money.
    if ($env === 'test' || stripos($txnId, 'DUMMY') === 0) {
        $pdo->prepare("UPDATE deposits SET gateway_status='TEST_IGNORED',gateway_response=? WHERE id=? AND status='pending'")
            ->execute([substr(json_encode($d),0,2000), $depositId]);
        return 'PENDING';
    }
    // Exact amount check (what the customer actually paid vs what we asked for).
    $paid = (float)($d['pay_amount'] ?? $d['amount'] ?? 0);
    if (abs($paid - (float)$dep['amount']) > 0.009) {
        $pdo->prepare("UPDATE deposits SET gateway_status='AMOUNT_MISMATCH',gateway_response=? WHERE id=? AND status='pending'")
            ->execute([substr(json_encode($d),0,2000), $depositId]);
        return 'PENDING';
    }
    $creditTxnId = $txnId !== '' ? $txnId : ('ZAP:' . (string)$dep['gateway_order_id']);

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT status FROM deposits WHERE id=?' . ($driver==='mysql' ? ' FOR UPDATE' : ''));
        $lock->execute([$depositId]);
        if ($lock->fetchColumn() !== 'pending') { if ($pdo->inTransaction()) $pdo->commit(); return zapupi_settle_deposit_result($depositId); }
        // Duplicate-transaction guard INSIDE the transaction (Paytm flow has this outside).
        $dup = $pdo->prepare("SELECT id FROM deposits WHERE gateway_txn_id=? AND status='approved' AND id<>? LIMIT 1");
        $dup->execute([$creditTxnId, $depositId]);
        if ($dup->fetchColumn()) { $pdo->rollBack(); return 'FAILURE'; }

        $rid = (int)$dep['reseller_id'];
        $w = $pdo->prepare('SELECT balance FROM wallets WHERE reseller_id=?' . ($driver==='mysql' ? ' FOR UPDATE' : ''));
        $w->execute([$rid]); $wr = $w->fetch();
        $old = $wr ? (float)$wr['balance'] : 0.0;
        $new = round($old + (float)$dep['amount'], 2);
        if ($wr) $pdo->prepare('UPDATE wallets SET balance=? WHERE reseller_id=?')->execute([$new,$rid]);
        else     $pdo->prepare('INSERT INTO wallets(reseller_id,balance) VALUES(?,?)')->execute([$rid,$new]);
        $pdo->prepare('INSERT INTO wallet_transactions(reseller_id,type,amount,balance_after,note) VALUES(?,?,?,?,?)')
            ->execute([$rid,'credit',(float)$dep['amount'],$new,'ZapUPI deposit '.$dep['gateway_order_id'].($utr!==''?' UTR '.$utr:'')]);
        $pdo->prepare("UPDATE deposits SET status='approved',gateway_status='SUCCESS',gateway_txn_id=?,gateway_response=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'")
            ->execute([$creditTxnId, substr(json_encode($d),0,2000), $depositId]);
        $pdo->commit();
        return 'SUCCESS';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('ZapUPI settle failed: '.$e->getMessage());
        return 'PENDING';
    }
}
function zapupi_settle_deposit_result(int $depositId): string
{
    $st = db()->prepare('SELECT status FROM deposits WHERE id=?'); $st->execute([$depositId]);
    $s = (string)$st->fetchColumn();
    return $s==='approved' ? 'SUCCESS' : ($s==='pending' ? 'PENDING' : 'FAILURE');
}

function paytm_checksum(string $body,string $key): string
{
    $chars='9876543210ZYXWVUTSRQPONMLKJIHGFEDCBAabcdefghijklmnopqrstuvwxyz!@#$&_'; $salt='';
    for($i=0;$i<4;$i++)$salt.=$chars[random_int(0,strlen($chars)-1)];
    $hash=hash('sha256',$body.'|'.$salt).$salt;
    $out=openssl_encrypt($hash,'AES-128-CBC',html_entity_decode($key),0,'@@@@&&&&####$$$$');
    if($out===false)throw new RuntimeException('Could not generate Paytm checksum.'); return $out;
}
function paytm_verify_checksum(string $body,string $key,string $checksum): bool
{
    if($key===''||$checksum==='')return false;
    $plain=openssl_decrypt($checksum,'AES-128-CBC',html_entity_decode($key),0,'@@@@&&&&####$$$$');
    if($plain===false||strlen($plain)<4)return false;
    $salt=substr($plain,-4);$expected=hash('sha256',$body.'|'.$salt).$salt;
    return hash_equals($expected,$plain);
}
function paytm_post_json(string $url,array $payload): array
{
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required for Paytm payments.');
    $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES),CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($raw===false||$err!=='')throw new RuntimeException('Paytm connection failed: '.($err?:'network error'));
    $data=json_decode($raw,true);if(!is_array($data))throw new RuntimeException('Paytm returned an invalid response (HTTP '.$code.').');return $data;
}
function paytm_create_dynamic_qr(string $orderId,float $amount): array
{
    $c=paytm_config();
    if(!$c['enabled']) throw new RuntimeException('Paytm payment is not enabled by admin.');
    if($c['mid']===''||$c['upi_id']==='') throw new RuntimeException('Configure Paytm MID and Paytm UPI ID first.');

    // EXACT bot-compatible payment URI pattern.
    // Keep the UPI payload compatible with the working bot: same fields,
    // same numeric order id and the same transaction reference (tr).
    // URL-encoding is correct for a URI and does not change the decoded UPI data.
    $upi='upi://pay?pa='.rawurlencode($c['upi_id'])
        .'&pn='.rawurlencode($c['upi_name'])
        .'&am='.rawurlencode(number_format($amount,2,'.',''))
        .'&cu=INR&tn='.rawurlencode('Decoded Nikhil '.$orderId)
        .'&tr='.rawurlencode($orderId);

    // QR image is only a visual rendering of the UPI URI; no merchant secret is sent.
    $qrImage='https://api.qrserver.com/v1/create-qr-code/?size=420x420&data='.rawurlencode($upi);
    return ['qr_id'=>'','qr_data'=>$upi,'image'=>$qrImage,'mode'=>'upi_auto','raw'=>null];
}
function paytm_get_qr_status(string $orderId): array
{
    $c=paytm_config();
    if(!$c['enabled']||$c['mid']===''||$c['upi_id']==='') throw new RuntimeException('Paytm MID and UPI ID are not configured.');

    // EXACT status endpoint/payload used by the supplied bot.
    $res=paytm_post_json('https://securegw.paytm.in/merchant-status/getTxnStatus',['MID'=>$c['mid'],'ORDERID'=>$orderId]);
    return ['body'=>$res,'head'=>[],'mode'=>'upi_auto'];
}

function is_global_maintenance(): bool
{
    return get_setting('global_maintenance', 'off') === 'on';
}

function audit_log(?int $adminId, string $action, ?string $target = null, ?string $details = null): void
{
    try {
        db()->prepare('INSERT INTO audit_logs (admin_id,action,target,details,ip_address) VALUES (?,?,?,?,?)')
            ->execute([$adminId, $action, $target, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {}
}

function api_log(?int $resellerId, ?int $apiKeyId, string $action, array $extra = [], string $status = 'success', int $httpStatus = 200, ?string $message = null): void
{
    try {
        db()->prepare('INSERT INTO api_logs (reseller_id,api_key_id,action,product_id,plan_id,quantity,order_id,order_code,status,http_status,message) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $resellerId, $apiKeyId, $action,
                $extra['product_id'] ?? null, $extra['plan_id'] ?? null, $extra['quantity'] ?? null,
                $extra['order_id'] ?? null, $extra['order_code'] ?? null,
                $status, $httpStatus, $message,
            ]);
    } catch (Throwable $e) {}
}

/* ============================================================
   RESELLER API KEY — real, database-backed key issued to resellers
   for the external reseller API (section 22-29 of the spec). Only the
   SHA-256 hash + a short prefix are stored; the full secret is shown
   to the reseller once, at generation time, exactly like a password.
   ============================================================ */
function generate_api_key_for_reseller(int $resellerId): array
{
    $secret = 'kp_' . bin2hex(random_bytes(24));
    $hash = hash('sha256', $secret);
    $prefix = substr($secret, 0, 10);
    $pdo = db();
    $pdo->prepare("UPDATE api_keys SET status='revoked', revoked_at=CURRENT_TIMESTAMP WHERE reseller_id=? AND status='active'")->execute([$resellerId]);
    $pdo->prepare('INSERT INTO api_keys (reseller_id,key_hash,key_prefix,status) VALUES (?,?,?,\'active\')')->execute([$resellerId, $hash, $prefix]);
    return ['key' => $secret, 'prefix' => $prefix];
}

function authenticate_api_key(): array
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $key = trim((string) $key);
    $action=(string)($_GET['api']??'api');
    if ($key === '') {
        api_log(null,null,$action,[],'failed',401,'Missing API key.');
        json_error('Missing API key. Send it in the X-API-Key header.', 401);
    }
    $hash = hash('sha256', $key);
    $stmt = db()->prepare(
        "SELECT r.*, ak.id AS api_key_id FROM api_keys ak
         JOIN resellers r ON r.id = ak.reseller_id
         WHERE ak.key_hash = ? AND ak.status = 'active' LIMIT 1"
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        api_log(null,null,$action,[],'failed',401,'Invalid or revoked API key.');
        json_error('Invalid or revoked API key.', 401);
    }
    if ($row['status'] !== 'active') {
        api_log((int)$row['id'],(int)$row['api_key_id'],$action,[],'failed',403,'This reseller account is '.$row['status'].'.');
        json_error('This reseller account is ' . $row['status'] . '.', 403);
    }
    if (($row['account_type'] ?? 'reseller') === 'user') {
        api_log((int)$row['id'],(int)$row['api_key_id'],$action,[],'failed',403,'API access is not available on user accounts.');
        json_error('API access is not available on this account.', 403);
    }
    return $row;
}

/* ============================================================
   RESPONSE HELPERS
   ============================================================ */
function json_ok(array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array_merge(['status' => 'success'], $data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        json_error('Method not allowed.', 405);
    }
}
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/* ============================================================
   SESSION / AUTH — reseller and admin sessions are completely
   separate ($_SESSION['reseller_id'] vs $_SESSION['admin_id']),
   so one can never satisfy the other's check.
   ============================================================ */
function start_session_if_needed(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $isHttps,
        ]);
        session_start();
    }
}

function current_reseller(): ?array
{
    start_session_if_needed();
    if (empty($_SESSION['reseller_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM resellers WHERE id = ?');
    $stmt->execute([$_SESSION['reseller_id']]);
    $reseller = $stmt->fetch();
    if (!$reseller || $reseller['status'] !== 'active') {
        session_unset();
        return null;
    }
    return $reseller;
}

function require_reseller(): array
{
    $reseller = current_reseller();
    if ($reseller === null) {
        if (IS_API_REQUEST) {
            json_error('Not authenticated.', 401);
        }
        header('Location: index.php');
        exit;
    }
    return $reseller;
}

/* Self-signup "user" accounts get wallet + buy, but never API-key access. */
function require_reseller_api_allowed(): array
{
    $reseller = require_reseller();
    if (($reseller['account_type'] ?? 'reseller') === 'user') {
        json_error('API access is not available on this account.', 403);
    }
    return $reseller;
}

function login_reseller(string $username, string $password): array
{
    $stmt = db()->prepare('SELECT * FROM resellers WHERE username = ?');
    $stmt->execute([trim($username)]);
    $reseller = $stmt->fetch();

    if (!$reseller || !password_verify($password, $reseller['password_hash'])) {
        record_login_failure($username);
        return [false, 'Invalid username or password.'];
    }
    if ($reseller['status'] === 'banned') {
        return [false, 'This account has been banned. Contact the admin.'];
    }
    if ($reseller['status'] !== 'active') {
        return [false, 'This account has been disabled. Contact the admin.'];
    }

    start_session_if_needed();
    session_regenerate_id(true);
    $_SESSION['reseller_id'] = (int) $reseller['id'];
    unset($_SESSION['admin_id']);

    return [true, (int) $reseller['id']];
}

function logout_reseller(): void
{
    start_session_if_needed();
    unset($_SESSION['reseller_id']);
    session_regenerate_id(true);
}

function current_admin(): ?array
{
    start_session_if_needed();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, created_at FROM admins WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    return $stmt->fetch() ?: null;
}

function require_admin(): array
{
    $admin = current_admin();
    if ($admin === null) {
        if (IS_API_REQUEST) {
            json_error('Admin authentication required.', 401);
        }
        header('Location: index.php');
        exit;
    }
    return $admin;
}

function login_admin(string $username, string $password): array
{
    $stmt = db()->prepare('SELECT * FROM admins WHERE username = ?');
    $stmt->execute([trim($username)]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        return [false, 'Invalid username or password.'];
    }

    start_session_if_needed();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    unset($_SESSION['reseller_id']);

    return [true, (int) $admin['id']];
}

/* One public login form authenticates either an admin or a reseller.
   Admin credentials are checked first; reseller credentials are checked second. */
function login_rate_key(string $username): array
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return [hash('sha256', $ip), hash('sha256', strtolower(trim($username)))];
}

function login_rate_limited(string $username): bool
{
    try {
        [$ipHash, $userHash] = login_rate_key($username);
        $stmt = db()->prepare('SELECT failed_count, first_failed_at, locked_until FROM login_attempts WHERE ip_hash=? AND username_hash=? LIMIT 1');
        $stmt->execute([$ipHash, $userHash]);
        $row = $stmt->fetch();
        if (!$row) return false;
        if (!empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time()) return true;
        if (strtotime((string)$row['last_failed_at']) < time() - 900) return false;
        return (int)$row['failed_count'] >= 5;
    } catch (Throwable $e) {
        error_log('login_rate_limited: '.$e->getMessage());
        return false;
    }
}

function record_login_failure(string $username): void
{
    try {
        [$ipHash, $userHash] = login_rate_key($username);
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id,failed_count,first_failed_at FROM login_attempts WHERE ip_hash=? AND username_hash=? LIMIT 1');
        $stmt->execute([$ipHash, $userHash]);
        $row = $stmt->fetch();
        $now = date('Y-m-d H:i:s');
        if (!$row || strtotime((string)$row['first_failed_at']) < time() - 900) {
            if ($row) {
                $pdo->prepare('UPDATE login_attempts SET failed_count=1,first_failed_at=?,last_failed_at=?,locked_until=NULL WHERE id=?')->execute([$now,$now,(int)$row['id']]);
            } else {
                $pdo->prepare('INSERT INTO login_attempts(ip_hash,username_hash,failed_count,first_failed_at,last_failed_at) VALUES(?,?,1,?,?)')->execute([$ipHash,$userHash,$now,$now]);
            }
            return;
        }
        $count=(int)$row['failed_count']+1;
        $locked=$count>=5 ? date('Y-m-d H:i:s', time()+900) : null;
        $pdo->prepare('UPDATE login_attempts SET failed_count=?,last_failed_at=?,locked_until=? WHERE id=?')->execute([$count,$now,$locked,(int)$row['id']]);
    } catch (Throwable $e) {
        error_log('record_login_failure: '.$e->getMessage());
    }
}

function clear_login_failures(string $username): void
{
    try {
        [$ipHash, $userHash] = login_rate_key($username);
        db()->prepare('DELETE FROM login_attempts WHERE ip_hash=? AND username_hash=?')->execute([$ipHash,$userHash]);
    } catch (Throwable $e) { error_log('clear_login_failures: '.$e->getMessage()); }
}

function login_unified(string $username, string $password): array
{ 

    $username = trim($username);
    if ($username === '' || $password === '') {
        return [false, 'Username and password are required.'];
    }
    if (login_rate_limited($username)) {
        return [false, 'Too many failed login attempts. Please wait 15 minutes and try again.'];
    }

    $stmt = db()->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    $validDatabasePassword = $admin && password_verify($password, $admin['password_hash']);
    $configuredAdmin = configured_admin_credentials();
    $validEnvironmentPassword = $configuredAdmin !== null
        && hash_equals($configuredAdmin['username'], $username)
        && hash_equals($configuredAdmin['password'], $password);

    // The configured environment credential can also authenticate an existing
    // admin account with the same username, without changing its stored hash.
    if ($admin && ($validDatabasePassword || $validEnvironmentPassword)) {
        start_session_if_needed();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        unset($_SESSION['reseller_id']);
        clear_login_failures($username);
        return [true, ['role' => 'admin', 'id' => (int) $admin['id'], 'username' => $admin['username']]];
    }

    $stmt = db()->prepare('SELECT * FROM resellers WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $reseller = $stmt->fetch();

    if (!$reseller || !password_verify($password, $reseller['password_hash'])) {
        return [false, 'Invalid username or password.'];
    }
    if ($reseller['status'] === 'banned') {
        return [false, 'This account has been banned. Contact the admin.'];
    }
    if ($reseller['status'] !== 'active') {
        return [false, 'This account has been disabled. Contact the admin.'];
    }

    start_session_if_needed();
    session_regenerate_id(true);
    $_SESSION['reseller_id'] = (int) $reseller['id'];
    unset($_SESSION['admin_id']);
    clear_login_failures($username);
    return [true, ['role' => 'reseller', 'id' => (int) $reseller['id'], 'username' => $reseller['username'], 'account_type' => ($reseller['account_type'] ?? 'reseller') === 'user' ? 'user' : 'reseller']];
}

function csrf_token(): string
{
    start_session_if_needed();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_valid_csrf(): void
{
    start_session_if_needed();
    $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        json_error('Your session expired. Please refresh and try again.', 400);
    }
}

function normalize_login_username(string $username): string
{
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
        json_error('Username must be 3-64 characters and use only letters, numbers, dot, dash or underscore.', 422);
    }
    return $username;
}
function validate_youtube_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') return null;
    if (!filter_var($url, FILTER_VALIDATE_URL)) json_error('That YouTube URL is not valid.', 422);
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    if (!preg_match('/(^|\.)(youtube\.com|youtu\.be)$/i', $host)) {
        json_error('Please enter a valid YouTube URL.', 422);
    }
    return $url;
}
function normalize_signup_mobile(string $mobile): string
{
    $mobile = trim($mobile);
    if (!preg_match('/^[6-9][0-9]{9}$/', $mobile)) {
        json_error('Enter a valid 10-digit mobile number.', 422);
    }
    return $mobile;
}
function normalize_signup_email(string $email): string
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        json_error('Enter a valid email address.', 422);
    }
    return strtolower($email);
}
function signup_rate_limited(string $ip): bool
{
    // login_attempts.username_hash is CHAR(64); hash the namespaced key
    // instead of prefixing a 64-character hash and overflowing the column.
    $key = hash('sha256', 'signup_' . $ip);
    $st = db()->prepare('SELECT failed_count, locked_until FROM login_attempts WHERE username_hash = ? AND ip_hash = ?');
    $st->execute([$key, hash('sha256', $ip)]);
    $row = $st->fetch();
    if (!$row) return false;
    if ($row['locked_until'] !== null && strtotime((string)$row['locked_until']) > time()) return true;
    return false;
}
function signup_record_attempt(string $ip): void
{
    $ipHash = hash('sha256', $ip);
    // Keep the signup marker within username_hash CHAR(64).
    $key = hash('sha256', 'signup_' . $ip);
    $pdo = db();
    $st = $pdo->prepare('SELECT id, failed_count FROM login_attempts WHERE username_hash = ? AND ip_hash = ?');
    $st->execute([$key, $ipHash]);
    $row = $st->fetch();
    $count = $row ? ((int)$row['failed_count'] + 1) : 1;
    $lock = $count >= 6 ? date('Y-m-d H:i:s', time() + 900) : null; // 6 signups / 15 min per IP
    if ($row) {
        $pdo->prepare('UPDATE login_attempts SET failed_count=?, last_failed_at=CURRENT_TIMESTAMP, locked_until=? WHERE id=?')
            ->execute([$count, $lock, $row['id']]);
    } else {
        $pdo->prepare('INSERT INTO login_attempts (ip_hash, username_hash, failed_count, locked_until) VALUES (?,?,?,?)')
            ->execute([$ipHash, $key, $count, $lock]);
    }
}
function generate_login_username(): string { return 'user' . random_int(10000, 99999); }
function generate_login_password(): string { return 'KP-' . strtoupper(bin2hex(random_bytes(4))) . '-' . random_int(1000,9999); }
function admin_create_reseller(string $username, string $password): array
{
    $username = normalize_login_username($username);
    if (strlen($password) < 8 || strlen($password) > 128) json_error('Password must be 8-128 characters.', 422);
    $pdo = db();
    $check = $pdo->prepare('SELECT username FROM resellers WHERE username = ? UNION SELECT username FROM admins WHERE username = ? LIMIT 1');
    $check->execute([$username,$username]);
    if ($check->fetch()) json_error('That username is already in use. Generate another one.',409);
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare("INSERT INTO resellers (username,password_hash,status) VALUES (?,?,'active')");
        $stmt->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO wallets (reseller_id,balance) VALUES (?,0.00)')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        error_log('Create reseller failed: '.$e->getMessage());
        json_error('Could not create the user account.',500);
    }
    return ['id'=>$id,'username'=>$username,'password'=>$password,'status'=>'active'];
}

/* ============================================================
   PRICING ENGINE — the single authoritative price calculation.
   Priority: reseller-specific ACTIVE custom price wins; otherwise
   the plan's default reseller price. There is no normal-user price
   anywhere on this platform.
   ============================================================ */
const USER_PRICE_TIER_ID = 0; // reserved reseller_id used only for the shared 'user' price list
function get_effective_price(int $resellerId, int $planId, ?string $accountType = null): ?array
{
    $pdo = db();
    if ($accountType === null) {
        // Not passed by the caller — resolve it so every existing call site (buy,
        // catalog preview, API purchase, etc.) automatically applies the right
        // price list without needing to be touched individually.
        $accountType = 'reseller';
        $at = $pdo->prepare('SELECT account_type FROM resellers WHERE id = ?');
        $at->execute([$resellerId]);
        $row = $at->fetch();
        if ($row && ($row['account_type'] ?? '') === 'user') $accountType = 'user';
    }
    $plan = $pdo->prepare(
        "SELECT p.id, p.default_price, p.status, pr.name AS product_name, pr.id AS product_id
         FROM plans p JOIN products pr ON pr.id = p.product_id WHERE p.id = ?"
    );
    $plan->execute([$planId]);
    $plan = $plan->fetch();

    if (!$plan || $plan['status'] !== 'active') {
        return null;
    }
    $defaultPrice = (float) $plan['default_price'];

    // Self-signup "user" accounts all share ONE admin-set price list, stored under
    // the reserved id USER_PRICE_TIER_ID (0) — never a real reseller/user account.
    $lookupId = ($accountType === 'user') ? USER_PRICE_TIER_ID : $resellerId;
    $custom = $pdo->prepare(
        "SELECT custom_price FROM custom_prices WHERE reseller_id = ? AND plan_id = ? AND status = 'active'"
    );
    $custom->execute([$lookupId, $planId]);
    $customRow = $custom->fetch();

    if ($customRow !== false) {
        return ['price' => (float) $customRow['custom_price'], 'source' => 'custom', 'default_price' => $defaultPrice];
    }
    return ['price' => $defaultPrice, 'source' => 'default', 'default_price' => $defaultPrice];
}

function get_plan_stock(int $planId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM license_keys WHERE plan_id = ? AND status = 'available'");
    $stmt->execute([$planId]);
    return (int) $stmt->fetchColumn();
}

/* ============================================================
   WALLET — the ONLY function anywhere allowed to change a balance.
   Writes the new balance and a ledger row in the same transaction.
   ============================================================ */
function get_wallet_balance(int $resellerId): float
{
    $stmt = db()->prepare('SELECT balance FROM wallets WHERE reseller_id = ?');
    $stmt->execute([$resellerId]);
    $row = $stmt->fetch();
    return $row ? (float) $row['balance'] : 0.0;
}

function adjust_wallet(int $resellerId, string $type, float $amount, ?string $note = null, ?int $adminId = null, ?int $orderId = null): float
{
    if ($amount < 0) {
        throw new InvalidArgumentException('adjust_wallet() amount must be positive; direction is set by $type.');
    }
    $isDebit = in_array($type, ['debit', 'purchase'], true);
    $delta = $isDebit ? -$amount : $amount;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT balance FROM wallets WHERE reseller_id = ?');
        $stmt->execute([$resellerId]);
        $row = $stmt->fetch();
        $current = $row ? (float) $row['balance'] : 0.0;

        $new = round($current + $delta, 2);
        if ($new < 0) {
            throw new RuntimeException('Insufficient wallet balance.');
        }

        if ($row) {
            $pdo->prepare('UPDATE wallets SET balance = ? WHERE reseller_id = ?')->execute([$new, $resellerId]);
        } else {
            $pdo->prepare('INSERT INTO wallets (reseller_id, balance) VALUES (?, ?)')->execute([$resellerId, $new]);
        }

        $pdo->prepare(
            'INSERT INTO wallet_transactions (reseller_id, type, amount, balance_after, note, admin_id, order_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$resellerId, $type, $amount, $new, $note, $adminId, $orderId]);

        $pdo->commit();
        return $new;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function get_wallet_history(int $resellerId, int $limit = 50): array
{
    $stmt = db()->prepare('SELECT * FROM wallet_transactions WHERE reseller_id = ? ORDER BY id DESC LIMIT ?');
    $stmt->bindValue(1, $resellerId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/* ============================================================
   ORDERS — atomic stock claim + purchase, shared by web and future API.
   ============================================================ */
function generate_order_code(): string
{
    return 'KP' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

function claim_license_keys(PDO $pdo, int $planId, int $quantity, int $orderId): array
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $lockSql = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $find = $pdo->prepare(
        "SELECT id, key_value FROM license_keys WHERE plan_id = ? AND status = 'available'
         ORDER BY id ASC LIMIT ?{$lockSql}"
    );
    $find->bindValue(1, $planId, PDO::PARAM_INT);
    $find->bindValue(2, $quantity, PDO::PARAM_INT);
    $find->execute();
    $rows = $find->fetchAll();

    if (count($rows) < $quantity) {
        throw new RuntimeException('Insufficient stock.');
    }

    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $update = $pdo->prepare(
        "UPDATE license_keys SET status = 'sold', order_id = ?, sold_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders) AND status = 'available'"
    );
    $update->execute(array_merge([$orderId], $ids));

    if ($update->rowCount() !== count($ids)) {
        throw new RuntimeException('Stock changed during purchase. Please try again.');
    }
    return array_column($rows, 'key_value');
}

/* KEYSPANEL provider — kept separate from every other provider.
 * Contract copied from bot(8).py: POST form-urlencoded to one endpoint,
 * x-master-key header, action=buy, variant_id and quantity.
 * There is intentionally NO balance/status probe because KEYSPANEL documents
 * only the real purchase action.
 */
const KEYSPANEL_DEFAULT_API_URL = 'https://keyspanelshop.shop/reseller_api.php';
const KEYSPANEL_MAX_QUANTITY_PER_REQUEST = 10;

function provider_api_config(): array
{
    $url = trim((string)get_setting('provider_api_url',''));
    $url = preg_replace('/\s+/', '', $url);
    if ($url === '') $url = KEYSPANEL_DEFAULT_API_URL;
    return [
        'enabled' => get_setting('provider_api_enabled','off') === 'on',
        'url' => $url,
        'token' => preg_replace('/\s+/', '', trim((string)get_setting('provider_api_token',''))),
        // Optional egress proxy — see the 'provider_api_proxy' default-setting
        // comment above for why this exists. Empty = send directly, no proxy.
        'proxy' => trim((string)get_setting('provider_api_proxy','')),
    ];
}

/**
 * Applies the optional KEYSPANEL egress proxy (see provider_api_config)
 * to an already-initialized cURL handle. No-op if no proxy is configured.
 * Supports http://, https://, socks5:// and socks5h:// proxy URLs, with or
 * without embedded user:pass@ credentials. Returns true if a proxy was set.
 */
function keyspanel_apply_proxy($ch, string $proxyUrl): bool
{
    if ($proxyUrl === '') return false;
    $scheme = strtolower((string)parse_url($proxyUrl, PHP_URL_SCHEME));
    $typeMap = [
        'http' => CURLPROXY_HTTP,
        'https' => CURLPROXY_HTTP,
        'socks5' => CURLPROXY_SOCKS5,
        'socks5h' => CURLPROXY_SOCKS5_HOSTNAME,
        'socks4' => CURLPROXY_SOCKS4,
    ];
    if (!isset($typeMap[$scheme])) return false; // unrecognized scheme: skip rather than guess
    curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);
    curl_setopt($ch, CURLOPT_PROXYTYPE, $typeMap[$scheme]);
    if (in_array($scheme, ['http','https'], true)) {
        curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true); // CONNECT-tunnel the HTTPS target through an HTTP proxy
    }
    return true;
}

/**
 * Fresh, blank diagnostics record for one KEYSPANEL purchase attempt.
 * Every field here is safe to persist and safe to show an admin/reseller —
 * the master key is NEVER placed in this array.
 */
function keyspanel_new_diagnostics(string $variantId, int $quantity): array
{
    return [
        'requested_at'       => date('c'),
        'variant_id'         => $variantId,
        'quantity'           => $quantity,
        'master_key_length'  => null,
        'endpoint_url'       => null,
        'proxy_used'         => false,
        'effective_url'      => null,
        'total_time_ms'      => null,
        'response_length'    => null,
        'http_status'        => null,
        'curl_error'         => null,
        'content_type'       => null,
        'json_valid'         => null,
        'provider_ok'        => null,
        'provider_error'     => null,
        'shortfall'          => null,
        'key_returned'       => null,
        'provider_order_id'  => null,
        'expires_at'         => null,
        'waf_signature'      => null,
        'response_preview'   => null,
        'possible_cause'     => null,
    ];
}

/**
 * Safe, human-readable summary of a diagnostics record for the Admin panel
 * and for troubleshooting a pending order. Contains no secrets.
 */
function keyspanel_diagnostics_summary(array $d): string
{
    $lines=[];
    $lines[]='HTTP: '.($d['http_status']!==null ? (string)$d['http_status'] : '—');
    $lines[]='Content-Type: '.($d['content_type'] !== null && $d['content_type']!=='' ? $d['content_type'] : '—');
    $lines[]='Response Time: '.($d['total_time_ms']!==null?$d['total_time_ms'].'ms':'—');
    $lines[]='Response Length: '.($d['response_length']!==null?$d['response_length'].' bytes':'—');
    $lines[]='JSON: '.($d['json_valid']===true?'VALID':($d['json_valid']===false?'INVALID':'—'));
    if(!empty($d['waf_signature'])) $lines[]='WAF/HTML Signature: '.$d['waf_signature'];
    if(!empty($d['curl_error'])) $lines[]='cURL Error: '.$d['curl_error'];
    $lines[]='Provider OK: '.($d['provider_ok']===true?'TRUE':($d['provider_ok']===false?'FALSE':'—'));
    if(!empty($d['provider_error'])) $lines[]='Provider Error: '.$d['provider_error'];
    if($d['shortfall']!==null && $d['shortfall']!=='') $lines[]='Shortfall: '.(string)$d['shortfall'];
    $lines[]='Key Returned: '.($d['key_returned']===true?'YES':($d['key_returned']===false?'NO':'—'));
    if(!empty($d['provider_order_id'])) $lines[]='Provider Order ID: '.$d['provider_order_id'];
    if(!empty($d['expires_at'])) $lines[]='Expires At: '.$d['expires_at'];
    $lines[]='Variant ID: '.($d['variant_id']!==''?$d['variant_id']:'—');
    $lines[]='Quantity: '.($d['quantity']??'—');
    if(isset($d['master_key_length'])) $lines[]='Master Key Length Sent: '.$d['master_key_length'].' chars (value never shown)';
    if(!empty($d['endpoint_url'])) $lines[]='Endpoint (saved): '.$d['endpoint_url'];
    $lines[]='Egress Proxy: '.(!empty($d['proxy_used'])?'USED (see Admin settings)':'NOT USED (direct connection)');
    if(!empty($d['effective_url']) && $d['effective_url']!==($d['endpoint_url']??null)) $lines[]='Effective URL (after any redirect): '.$d['effective_url'];
    if(!empty($d['response_preview'])) $lines[]='Response Preview: '.$d['response_preview'];
    if(!empty($d['possible_cause'])) $lines[]='Possible Cause: '.$d['possible_cause'];
    return implode("\n",$lines);
}

/**
 * KEYSPANEL purchase call. $diagnostics is filled in by reference on every
 * code path (success, confirmed failure, or unknown/timeout) so the caller
 * can persist/display exactly what happened — never the master key itself.
 */
function provider_api_buy(string $variantId, int $quantity, ?array &$diagnostics = null): array
{
    $quantity=max(1,min((int)$quantity,KEYSPANEL_MAX_QUANTITY_PER_REQUEST));
    $diagnostics=keyspanel_new_diagnostics($variantId,$quantity);

    $cfg=provider_api_config();
    if(!$cfg['enabled']) { $diagnostics['possible_cause']='KEYSPANEL API is turned OFF in Admin settings.'; throw new RuntimeException('KEYSPANEL API is disabled.'); }
    if($cfg['url']==='' || !filter_var($cfg['url'],FILTER_VALIDATE_URL)) { $diagnostics['possible_cause']='Saved KEYSPANEL endpoint URL is missing or invalid.'; throw new RuntimeException('KEYSPANEL API URL is not configured correctly.'); }
    if($cfg['token']==='') { $diagnostics['possible_cause']='No master key saved in Admin settings.'; throw new RuntimeException('KEYSPANEL master key is not configured.'); }
    if($variantId==='') { $diagnostics['possible_cause']='This duration has no KEYSPANEL Variant ID saved.'; throw new RuntimeException('KEYSPANEL variant_id is missing for this duration.'); }
    if(!function_exists('curl_init')) { $diagnostics['possible_cause']='PHP cURL extension is not installed/enabled on this server.'; throw new RuntimeException('PHP cURL extension is required for KEYSPANEL purchases.'); }

    // Safe, non-secret sanity signal on the master key actually being sent:
    // length only, never the value. Lets Admin confirm "the freshly saved key
    // is what went out on the wire" without ever exposing it.
    $diagnostics['master_key_length']=strlen($cfg['token']);
    $diagnostics['endpoint_url']=$cfg['url'];

    $payload=http_build_query(['action'=>'buy','variant_id'=>$variantId,'quantity'=>$quantity],'','&',PHP_QUERY_RFC3986);
    $ch=curl_init($cfg['url']);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'x-master-key: '.$cfg['token'],
        ],
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>15,
        // Match the working KEYSPANEL client contract closely: allow the
        // provider's HTTPS endpoint to normalize/redirect while preserving the
        // authenticated POST on the same destination. Never disable TLS checks.
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>3,
        CURLOPT_POSTREDIR=>CURL_REDIR_POST_ALL,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'python-requests/2.32.3',
    ]);
    $diagnostics['proxy_used']=keyspanel_apply_proxy($ch,$cfg['proxy']);
    $raw=curl_exec($ch);
    $err=curl_error($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $contentType=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
    $effectiveUrl=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
    $totalTime=(float)curl_getinfo($ch,CURLINFO_TOTAL_TIME);
    curl_close($ch);

    $diagnostics['http_status']=$http>0?$http:null;
    $diagnostics['content_type']=$contentType!==''?$contentType:null;
    $diagnostics['effective_url']=$effectiveUrl!==''?$effectiveUrl:null;
    $diagnostics['total_time_ms']=(int)round($totalTime*1000);
    $diagnostics['response_length']=$raw!==false?strlen((string)$raw):null;

    if($raw===false || $err!=='') {
        $detail=$err!==''?$err:'request failed';
        $diagnostics['curl_error']=$detail;
        $diagnostics['possible_cause']='Network/timeout/DNS/SSL failure reaching KEYSPANEL from this server (check hosting outbound network/firewall).';
        throw new RuntimeException('KEYSPANEL connection error: '.$detail.'. HTTP '.$http.'. Result unknown; do not retry automatically.');
    }
    if($http===301 || $http===302 || $http===303 || $http===307 || $http===308) {
        $diagnostics['possible_cause']='Endpoint URL redirected instead of answering directly — the saved URL may not be the final reseller_api.php address.';
        throw new RuntimeException('KEYSPANEL endpoint redirected (HTTP '.$http.'). Use the final HTTPS reseller_api.php URL in Admin so the x-master-key is sent to the correct endpoint.');
    }

    // Inspect the actual response body BEFORE deciding what an HTTP status
    // means. A 401/403 with a real KEYSPANEL JSON error body should surface
    // that exact error, not a guess — a 401/403 with an HTML/WAF page never
    // reached KEYSPANEL's application at all, which is a different problem.
    $body=(string)$raw;
    $stripped=ltrim($body);
    $isHtml = $stripped!=='' && $stripped[0]==='<';
    $wafSig = $isHtml ? keyspanel_detect_waf_signature($body) : null;
    if($isHtml){
        $diagnostics['json_valid']=false;
        $diagnostics['waf_signature']=$wafSig ?? 'Unrecognized HTML/text page (no known WAF vendor pattern matched)';
        $diagnostics['response_preview']=trim(preg_replace('/\s+/',' ',substr($body,0,400)));
    }

    $data = $isHtml ? null : json_decode($body,true);
    if(!$isHtml) $diagnostics['json_valid']=is_array($data);

    // Case A: a real documented JSON body came back (ok/error/key/keys),
    // regardless of the HTTP status code — trust the documented contract.
    if(is_array($data) && array_key_exists('ok',$data)) {
        $diagnostics['provider_ok']=($data['ok']===true);
        if($data['ok']!==true) {
            $errMsg=trim((string)($data['error']??'unknown error'));
            $diagnostics['provider_error']=$errMsg;
            if(isset($data['shortfall']) && is_scalar($data['shortfall'])) $diagnostics['shortfall']=$data['shortfall'];
            $diagnostics['possible_cause']='KEYSPANEL confirmed the purchase could not be completed (see Provider Error) — HTTP '.$http.'.';
            $msg='KEYSPANEL reported failure: '.$errMsg;
            if(isset($data['shortfall']) && is_scalar($data['shortfall'])) $msg.=' (shortfall: '.(string)$data['shortfall'].')';
            throw new RuntimeException($msg);
        }
        $keys=[];
        if(isset($data['keys']) && is_array($data['keys'])) foreach($data['keys'] as $k) if(is_scalar($k) && trim((string)$k)!=='') $keys[]=trim((string)$k);
        $single=$data['key']??null;
        if(is_scalar($single) && trim((string)$single)!=='' && !in_array(trim((string)$single),$keys,true)) array_unshift($keys,trim((string)$single));
        $diagnostics['key_returned']=!empty($keys);
        $diagnostics['provider_order_id']=isset($data['order_id'])&&is_scalar($data['order_id'])?(string)$data['order_id']:null;
        $diagnostics['expires_at']=isset($data['expires_at'])&&is_scalar($data['expires_at'])?(string)$data['expires_at']:null;
        if(!$keys) {
            $diagnostics['possible_cause']='Provider said ok=true but sent no key/keys — unusual provider-side response, not a bug in this integration.';
            throw new RuntimeException('KEYSPANEL reported ok=true but returned no key/keys.');
        }
        return [
            'keys'=>$keys,
            'provider_order_id'=>$diagnostics['provider_order_id']??'',
            'expires_at'=>$diagnostics['expires_at']??'',
        ];
    }

    // Case B: no documented JSON body. Classify using the HTTP status AND
    // whatever the body actually looked like, instead of guessing blind.
    if($http===401 || $http===403) {
        if($isHtml) {
            $cause='Rejected before reaching KEYSPANEL\'s application: an edge/WAF/firewall layer returned an HTML block page ('.$wafSig.'), not KEYSPANEL\'s documented JSON reply. This points to a network/IP-allowlist/edge-security rule, not necessarily the master key itself. If the Telegram bot\'s purchases succeed from a different server/IP, this server\'s outbound IP is most likely simply not on KEYSPANEL\'s allowlist yet — see Admin → KEYSPANEL Settings → Outbound IP, and consider the optional Egress Proxy setting to route through the bot\'s already-allowlisted IP.';
        } else {
            $cause='No JSON and no HTML block page came back — the body matched neither the documented API contract nor a typical WAF page. See the response preview for what was actually received.';
            $preview=trim(preg_replace('/\s+/',' ',substr($body,0,400)));
            if($preview!=='') $diagnostics['response_preview']=$preview;
        }
        $diagnostics['possible_cause']=$cause;
        throw new RuntimeException('KEYSPANEL rejected the authenticated POST (HTTP '.$http.'). '.$cause);
    }
    if($http===429) { $diagnostics['possible_cause']='Rate limited by KEYSPANEL.'; throw new RuntimeException('KEYSPANEL rate limited the request (HTTP 429). Please try again later.'); }
    if($http>=500) {
        $diagnostics['possible_cause']='KEYSPANEL server-side error'.($isHtml?' ('.$wafSig.')':'').'.';
        throw new RuntimeException('KEYSPANEL server error (HTTP '.$http.'). Please try again later.');
    }
    if($isHtml){
        // A WAF/HTML page confirms the request never reached KEYSPANEL's
        // application logic, so no purchase could have been processed —
        // safe to treat as a confirmed (refundable) failure, not "unknown".
        $diagnostics['possible_cause']='Non-JSON WAF/HTML response received instead of the documented API reply ('.$wafSig.'), on HTTP '.$http.'.';
        throw new RuntimeException('KEYSPANEL returned a non-JSON HTML/WAF response ('.$wafSig.', HTTP '.$http.') instead of the documented API reply. The request did not reach KEYSPANEL\'s application.');
    }

    // Truly ambiguous: not HTML, not valid documented JSON — e.g. a
    // truncated/garbled body. This is the one case where the provider may
    // genuinely have processed the request before the response broke, so it
    // stays classified as UNKNOWN (no auto-refund, no auto-retry).
    $preview=trim(preg_replace('/\s+/',' ',substr($body,0,400)));
    $diagnostics['response_preview']=$preview;
    $diagnostics['possible_cause']='KEYSPANEL returned a body that is neither valid documented JSON nor a recognizable HTML page.';
    throw new RuntimeException('Invalid JSON in KEYSPANEL response (HTTP '.$http.')'.($preview!==''?': '.$preview:'').'. Result unknown; do not retry automatically.');
}

/**
 * Best-effort, safe (no secrets) detection of a known WAF/CDN block or
 * challenge page from an HTML response body. Returns null if the body looks
 * like plain unrelated HTML with no recognizable vendor signature — the
 * caller still records that it was HTML, just without a vendor guess.
 */
function keyspanel_detect_waf_signature(string $body): ?string
{
    $lower=strtolower($body);
    $title=null;
    if(preg_match('/<title[^>]*>(.*?)<\/title>/is',$body,$m)) {
        $title=trim(preg_replace('/\s+/',' ',strip_tags($m[1])));
        if($title==='') $title=null;
        if($title!==null && strlen($title)>120) $title=substr($title,0,120).'…';
    }
    $vendor=null;
    if(str_contains($lower,'cf-browser-verification') || str_contains($lower,'just a moment') || str_contains($lower,'cloudflare')) {
        $vendor='Cloudflare';
    } elseif(str_contains($lower,'akamai')) {
        $vendor='Akamai';
    } elseif(str_contains($lower,'aws waf') || str_contains($lower,'awswaf')) {
        $vendor='AWS WAF';
    } elseif(str_contains($lower,'modsecurity') || str_contains($lower,'mod_security')) {
        $vendor='ModSecurity';
    } elseif(str_contains($lower,'sucuri')) {
        $vendor='Sucuri';
    } elseif(str_contains($lower,'request forbidden by administrative rules')) {
        $vendor='HAProxy (built-in "administrative rules" block page — a load balancer/reverse-proxy ACL rejected this request, most often an IP-based rule, before it ever reached the application)';
    } elseif(str_contains($lower,'access denied') || str_contains($lower,'request blocked') || str_contains($lower,'forbidden')) {
        $vendor='Generic access-denied page';
    }
    if($vendor===null && $title===null) return null;
    if($vendor===null) $vendor='Unrecognized HTML page';
    return $title!==null ? $vendor.' (page title: "'.$title.'")' : $vendor;
}

function keyspanel_configuration_status(): array
{
    $cfg=provider_api_config();
    $urlOk=$cfg['url']!=='' && filter_var($cfg['url'],FILTER_VALIDATE_URL)!==false;
    $tokenOk=$cfg['token']!=='';
    return [
        'enabled'=>$cfg['enabled'],
        'url_configured'=>$urlOk,
        'master_key_configured'=>$tokenOk,
        'configured'=>($urlOk && $tokenOk),
        'url'=>$cfg['url'],
        'max_quantity_per_request'=>KEYSPANEL_MAX_QUANTITY_PER_REQUEST,
    ];
}


class DripstoreApiException extends RuntimeException {
    public bool $unknownResult = false;
    public ?array $diagnostics = null;
}

/* ============================================================
   DRIPSTORE provider — isolated from KEYSPANEL and Local Store.
   Contract: X-API-Token header + form-urlencoded generate_key.php.
   Customer-facing stock is virtual 99; actual purchases always call
   the real DRIPSTORE endpoint.
   ============================================================ */
const DRIPSTORE_DEFAULT_API_URL = 'https://dripclientstore.shop/api/v1';
const DRIPSTORE_DISPLAY_STOCK = 99;
const DRIPSTORE_MAX_QUANTITY_PER_REQUEST = 100;

function dripstore_api_config(): array
{
    $url = trim((string)get_setting('dripstore_api_url', DRIPSTORE_DEFAULT_API_URL));
    $url = preg_replace('/\s+/', '', $url);
    if ($url === '') $url = DRIPSTORE_DEFAULT_API_URL;
    return [
        'enabled' => get_setting('dripstore_api_enabled','off') === 'on',
        'url' => rtrim($url,'/'),
        'token' => preg_replace('/\s+/', '', trim((string)get_setting('dripstore_api_token',''))),
    ];
}

function dripstore_validate_base_url(string $url): bool
{
    if ($url === '' || filter_var($url,FILTER_VALIDATE_URL) === false) return false;
    $p=parse_url($url);
    return strtolower((string)($p['scheme']??'')) === 'https'
        && strtolower((string)($p['host']??'')) === 'dripclientstore.shop'
        && empty($p['user']) && empty($p['pass']);
}

function dripstore_new_diagnostics(string $endpoint,int $quantity): array
{
    return [
        'provider'=>'DRIPSTORE',
        'requested_at'=>date('c'),
        'endpoint_url'=>$endpoint,
        'quantity'=>$quantity,
        'http_status'=>null,
        'curl_error'=>null,
        'content_type'=>null,
        'response_length'=>null,
        'json_valid'=>null,
        'provider_ok'=>null,
        'provider_error'=>null,
        'key_returned'=>false,
        'provider_order_id'=>null,
        'expires_at'=>null,
        'response_preview'=>null,
        'possible_cause'=>null,
    ];
}

function dripstore_extract_keys($data): array
{
    // Only consume values under explicit key/license-key field names.
    // We never treat arbitrary response strings (e.g. messages) as keys.
    $keys=[];
    $walk=function($node) use (&$walk,&$keys){
        if (!is_array($node)) return;
        foreach ($node as $name=>$value) {
            $lname=strtolower((string)$name);
            $isKeyField=in_array($lname,[
                'key','keys','license_key','license_keys','generated_key','generated_keys'
            ],true);
            if ($isKeyField) {
                if (is_string($value) && trim($value)!=='') {
                    $keys[]=trim($value);
                } elseif (is_array($value)) {
                    foreach ($value as $v) {
                        if (is_string($v) && trim($v)!=='') $keys[]=trim($v);
                        elseif (is_array($v)) $walk($v);
                    }
                }
            } elseif (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($data);
    $out=[];
    foreach ($keys as $k) if (!in_array($k,$out,true)) $out[]=$k;
    return $out;
}

function dripstore_api_request(string $path,string $method='GET',array $body=[]): array
{
    $cfg=dripstore_api_config();
    if (!$cfg['enabled']) throw new RuntimeException('DRIPSTORE API is disabled.');
    if (!dripstore_validate_base_url($cfg['url'])) throw new RuntimeException('DRIPSTORE API URL is not configured correctly.');
    if ($cfg['token']==='') throw new RuntimeException('DRIPSTORE API token is not configured.');
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for DRIPSTORE API requests.');

    $url=$cfg['url'].'/'.ltrim($path,'/');
    $ch=curl_init($url);
    $opts=[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>[
            'X-API-Token: '.$cfg['token'],
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>3,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'KEYS-PANEL-DRIPSTORE/1.0',
    ];
    if ($method==='POST') {
        $opts[CURLOPT_POST]=true;
        $opts[CURLOPT_POSTFIELDS]=http_build_query($body,'','&',PHP_QUERY_RFC3986);
        $opts[CURLOPT_HTTPHEADER][]='Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch,$opts);
    $raw=curl_exec($ch);
    $err=curl_error($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $ct=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $diag=[
        'http_status'=>$http>0?$http:null,
        'curl_error'=>$err!==''?$err:null,
        'content_type'=>$ct!==''?$ct:null,
        'response_length'=>$raw!==false?strlen((string)$raw):null,
    ];
    if ($raw===false || $err!=='') {
        $e=new DripstoreApiException('DRIPSTORE API connection error. Please try again later.');
        $e->unknownResult=true; $e->diagnostics=$diag;
        throw $e;
    }

    $bodyText=(string)$raw;
    $trim=ltrim($bodyText);
    $isHtml=$trim!=='' && ($trim[0]==='<' ||
        stripos($bodyText,'Just a moment')!==false ||
        stripos($bodyText,'cf-browser-verification')!==false ||
        stripos($bodyText,'Attention Required')!==false ||
        stripos($bodyText,'Cloudflare')!==false);
    if ($isHtml) {
        $diag['json_valid']=false;
        $diag['response_preview']=trim(preg_replace('/\s+/',' ',substr($bodyText,0,400)));
        $diag['possible_cause']='Cloudflare/edge challenge or HTML block page returned instead of JSON. Check the server IP/API allowlist.';
        $e=new DripstoreApiException('DRIPSTORE API returned a Cloudflare/edge challenge instead of JSON. Check the server IP/API allowlist.');
        $e->unknownResult=false; $e->diagnostics=$diag;
        throw $e;
    }

    $data=json_decode($bodyText,true);
    $diag['json_valid']=is_array($data);
    if (!is_array($data)) {
        $diag['response_preview']=trim(preg_replace('/\s+/',' ',substr($bodyText,0,400)));
        $diag['possible_cause']='Provider returned non-JSON data.';
        $e=new DripstoreApiException('DRIPSTORE API returned invalid JSON. Result unknown; do not retry automatically.');
        $e->unknownResult=true; $e->diagnostics=$diag;
        throw $e;
    }

    // Detect documented/common error containers without assuming a success schema.
    $keys=dripstore_extract_keys($data);
    $error=null;
    foreach (['error','message'] as $field) {
        if (isset($data[$field]) && is_scalar($data[$field])) {
            $v=trim((string)$data[$field]);
            if ($v!=='' && !$keys) $error=$v;
        }
    }
    $ok=null;
    if (array_key_exists('success',$data) && is_bool($data['success'])) $ok=$data['success'];
    if (array_key_exists('ok',$data) && is_bool($data['ok'])) $ok=$data['ok'];
    $statusValue=isset($data['status']) && is_scalar($data['status']) ? strtolower(trim((string)$data['status'])) : '';
    if ($ok===false || in_array($statusValue,['error','failed','failure','false'],true) || ($http>=400 && !$keys)) {
        $diag['provider_ok']=false;
        $diag['provider_error']=$error;
        $diag['possible_cause']='DRIPSTORE rejected the request (HTTP '.$http.').';
        $msg=$error!==null?'DRIPSTORE reported failure: '.$error:'DRIPSTORE rejected the request (HTTP '.$http.').';
        $e=new DripstoreApiException($msg);
        $e->unknownResult=false; $e->diagnostics=$diag;
        throw $e;
    }

    if ($http>=500 || $http===429) {
        $diag['provider_ok']=false;
        $diag['possible_cause']=$http===429?'DRIPSTORE rate limited the request.':'DRIPSTORE server error.';
        $e=new DripstoreApiException($http===429?'DRIPSTORE rate limit reached. Please try again later.':'DRIPSTORE server error. Please try again later.');
        $e->unknownResult=true; $e->diagnostics=$diag;
        throw $e;
    }

    $diag['provider_ok']=$ok===true ? true : null;
    $diag['key_returned']=!empty($keys);
    foreach (['order_id','reference','transaction_id'] as $field) {
        if (isset($data[$field]) && is_scalar($data[$field])) { $diag['provider_order_id']=(string)$data[$field]; break; }
    }
    foreach (['expires_at','expiry','expires'] as $field) {
        if (isset($data[$field]) && is_scalar($data[$field])) { $diag['expires_at']=(string)$data[$field]; break; }
    }

    return ['data'=>$data,'keys'=>$keys,'diagnostics'=>$diag,'http_status'=>$http];
}

function dripstore_api_generate_keys(string $variantId,int $quantity,?array &$diagnostics=null): array
{
    if ($variantId==='') throw new RuntimeException('DRIPSTORE Variant ID is missing for this duration.');
    if ($quantity<1 || $quantity>DRIPSTORE_MAX_QUANTITY_PER_REQUEST) throw new RuntimeException('Quantity must be between 1 and '.DRIPSTORE_MAX_QUANTITY_PER_REQUEST.'.');
    $cfg=dripstore_api_config();
    $result=dripstore_api_request('/generate_key.php','POST',['variant_id'=>$variantId,'quantity'=>$quantity]);
    $diagnostics=$result['diagnostics'];
    $keys=$result['keys'];
    if (!$keys) {
        $diagnostics['possible_cause']='The DRIPSTORE response was valid JSON but did not contain a recognized key/license-key field.';
        $e=new DripstoreApiException('DRIPSTORE returned no generated key data.');
        $e->unknownResult=true; $e->diagnostics=$diagnostics;
        throw $e;
    }
    return [
        'keys'=>$keys,
        'provider_order_id'=>$diagnostics['provider_order_id']??'',
        'expires_at'=>$diagnostics['expires_at']??'',
    ];
}

function dripstore_api_get_balance(): array { return dripstore_api_request('/balance.php','GET')['data']; }
function dripstore_api_get_products(): array { return dripstore_api_request('/products.php','GET')['data']; }
function dripstore_api_get_reset_apis(): array { return dripstore_api_request('/reset_apis.php','GET')['data']; }
function dripstore_api_reset_key(string $api,string $key): array
{
    if (!in_array($api,['fluorite','drip'],true)) throw new RuntimeException('Unsupported DRIPSTORE reset API.');
    return dripstore_api_request('/reset_key.php','POST',['api'=>$api,'key'=>$key])['data'];
}
function dripstore_api_key_history(int $limit=20): array
{
    $limit=max(1,min($limit,100));
    return dripstore_api_request('/key_history.php?limit='.$limit,'GET')['data'];
}

function dripstore_configuration_status(): array
{
    $cfg=dripstore_api_config();
    return [
        'enabled'=>$cfg['enabled'],
        'url_configured'=>dripstore_validate_base_url($cfg['url']),
        'token_configured'=>$cfg['token']!=='',
        'configured'=>dripstore_validate_base_url($cfg['url']) && $cfg['token']!=='',
        'url'=>$cfg['url'],
        'display_stock'=>DRIPSTORE_DISPLAY_STOCK,
    ];
}

function dripstore_exception_is_unknown(Throwable $e): bool
{
    return $e instanceof DripstoreApiException ? $e->unknownResult : false;
}
function dripstore_exception_diagnostics(Throwable $e): ?array
{
    return $e instanceof DripstoreApiException && is_array($e->diagnostics) ? $e->diagnostics : null;
}

function execute_provider_purchase(int $resellerId,int $planId,int $quantity,?string $customNotes,string $source,?string $idempotencyKey=null): array
{
    if($quantity<1||$quantity>100)throw new RuntimeException('Quantity must be between 1 and 100.');
    $pdo=db();$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if($idempotencyKey!==''){
        $q=$pdo->prepare('SELECT * FROM orders WHERE reseller_id=? AND idempotency_key=? LIMIT 1');$q->execute([$resellerId,$idempotencyKey]);
        if($row=$q->fetch()){
            if(($row['status']??'')==='success')return ['order_code'=>$row['order_code'],'license_key'=>$row['license_key'],'unit_price'=>(float)$row['unit_price'],'total_price'=>(float)$row['total_price'],'price_source'=>$row['price_source'],'remaining_balance'=>get_wallet_balance($resellerId),'idempotent_replay'=>true];
            if(($row['status']??'')==='pending')throw new RuntimeException('This purchase is still being processed. Please check My Keys before retrying.');
        }
    }
    $st=$pdo->prepare("SELECT p.id,p.duration_label,p.default_price,p.status,p.stock_mode,p.provider_type,p.provider_product_id,p.dripstore_variant_id,pr.name AS product_name,pr.maintenance AS product_maintenance,pr.status AS product_status FROM plans p JOIN products pr ON pr.id=p.product_id WHERE p.id=?");$st->execute([$planId]);$plan=$st->fetch();
    if(!$plan||$plan['status']!=='active'||$plan['product_status']!=='active')throw new RuntimeException('This plan is not available.');
    if($plan['product_maintenance']==='on'||is_global_maintenance())throw new RuntimeException('Purchases are temporarily disabled.');
    $providerType=$plan['provider_type']??((($plan['stock_mode']??'local')==='api')?'keyspanel':'local');
    if($providerType==='dripstore'){
        $variantId=trim((string)($plan['dripstore_variant_id']??''));
        if($variantId==='')throw new RuntimeException('DRIPSTORE Variant ID is missing for this duration.');
        $maxQty=DRIPSTORE_MAX_QUANTITY_PER_REQUEST;
    } elseif($providerType==='keyspanel'){
        $variantId=trim((string)($plan['provider_product_id']??''));
        if($variantId==='')throw new RuntimeException('KEYSPANEL variant_id is missing for this duration.');
        $maxQty=KEYSPANEL_MAX_QUANTITY_PER_REQUEST;
    } else {
        throw new RuntimeException('This duration is not configured for a remote API provider.');
    }
    if($quantity>$maxQty)throw new RuntimeException('This provider allows a maximum of '.$maxQty.' keys per API request.');
    $priced=get_effective_price($resellerId,$planId);if($priced===null)throw new RuntimeException('This plan is not available.');
    $unitPrice=round((float)$priced['price'],2);$totalPrice=round($unitPrice*$quantity,2);

    // Reserve wallet funds before the supplier call so two requests cannot spend the same balance.
    $pdo->beginTransaction();
    try{
        $wsSql='SELECT balance FROM wallets WHERE reseller_id=?';if($driver==='mysql')$wsSql.=' FOR UPDATE';$ws=$pdo->prepare($wsSql);$ws->execute([$resellerId]);$wr=$ws->fetch();$balance=$wr?(float)$wr['balance']:0.0;
        if($balance<$totalPrice)throw new RuntimeException('Insufficient wallet balance.');
        $orderCode=generate_order_code();
        $ins=$pdo->prepare("INSERT INTO orders (order_code,reseller_id,plan_id,product_name,duration_label,quantity,unit_price,total_price,price_source,custom_notes,source,status,idempotency_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',?)");$ins->execute([$orderCode,$resellerId,$planId,$plan['product_name'],$plan['duration_label'],$quantity,$unitPrice,$totalPrice,$priced['source'],$customNotes,$source,$idempotencyKey]);$orderId=(int)$pdo->lastInsertId();$newBalance=round($balance-$totalPrice,2);
        if($wr)$pdo->prepare('UPDATE wallets SET balance=? WHERE reseller_id=?')->execute([$newBalance,$resellerId]);else$pdo->prepare('INSERT INTO wallets(reseller_id,balance) VALUES(?,?)')->execute([$resellerId,$newBalance]);
        $pdo->prepare('INSERT INTO wallet_transactions (reseller_id,type,amount,balance_after,note,admin_id,order_id) VALUES (?,?,?,?,?,?,?)')->execute([$resellerId,'purchase',$totalPrice,$newBalance,"Order {$orderCode}: {$plan['product_name']} ({$plan['duration_label']}) x{$quantity}",null,$orderId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw new RuntimeException($e->getMessage());}

    $diag=null;
    try{
        if($providerType==='dripstore'){
            $provider=dripstore_api_generate_keys($variantId,$quantity,$diag);
        } else {
            $provider=provider_api_buy($variantId,$quantity,$diag);
        }
        $keyText=implode("\n",$provider['keys']);
        $diagJson=$diag!==null?json_encode($diag):null;
        $pdo->beginTransaction();$pdo->prepare("UPDATE orders SET license_key=?,status='success',provider_diagnostics=? WHERE id=? AND status='pending'")->execute([$keyText,$diagJson,$orderId]);$pdo->commit();
        return ['order_code'=>$orderCode,'license_key'=>$keyText,'unit_price'=>$unitPrice,'total_price'=>$totalPrice,'price_source'=>$priced['source'],'remaining_balance'=>get_wallet_balance($resellerId),'idempotent_replay'=>false,'provider_order_id'=>$provider['provider_order_id'],'expires_at'=>$provider['expires_at'],'product_name'=>$plan['product_name'],'duration_label'=>$plan['duration_label']];
    }catch(Throwable $e){
        $msg=$e->getMessage();$low=strtolower($msg);
        $uncertain=($providerType==='dripstore' ? dripstore_exception_is_unknown($e) : (str_contains($low,'connection error')||str_contains($low,'connection failed')||str_contains($low,'timed out')||str_contains($low,'result unknown')||str_contains($low,'invalid json')));
        if($diag===null && $providerType==='dripstore') $diag=dripstore_exception_diagnostics($e);
        $diagJson=$diag!==null?json_encode($diag):null;
        $hasProviderJsonError=$diag!==null && (($diag['json_valid']??false)===true) && !empty($diag['provider_error']);
        if(!$uncertain){
            $pdo->beginTransaction();
            try{
                $wsSql='SELECT balance FROM wallets WHERE reseller_id=?';if($driver==='mysql')$wsSql.=' FOR UPDATE';$ws=$pdo->prepare($wsSql);$ws->execute([$resellerId]);$wr=$ws->fetch();$bal=$wr?(float)$wr['balance']:0.0;$refund=round($bal+$totalPrice,2);
                if($wr)$pdo->prepare('UPDATE wallets SET balance=? WHERE reseller_id=?')->execute([$refund,$resellerId]);else$pdo->prepare('INSERT INTO wallets(reseller_id,balance) VALUES(?,?)')->execute([$resellerId,$refund]);
                $pdo->prepare("UPDATE orders SET status='refunded',provider_diagnostics=? WHERE id=? AND status='pending'")->execute([$diagJson,$orderId]);$pdo->prepare('INSERT INTO wallet_transactions (reseller_id,type,amount,balance_after,note,admin_id,order_id) VALUES (?,?,?,?,?,?,?)')->execute([$resellerId,'refund',$totalPrice,"$refund","Refund {$orderCode}: provider rejected purchase",null,$orderId]);$pdo->commit();
            }catch(Throwable $re){if($pdo->inTransaction())$pdo->rollBack();}
        } else {
            // Result genuinely unknown (network/timeout/invalid JSON): do NOT touch the
            // wallet or order status — only record what we saw, for troubleshooting.
            try{ $pdo->prepare("UPDATE orders SET provider_diagnostics=? WHERE id=?")->execute([$diagJson,$orderId]); }catch(Throwable $de){}
        }
        // Full technical detail (HTTP codes, WAF signatures, response previews) is
        // NEVER shown to the reseller — it's only persisted to
        // orders.provider_diagnostics for Admin → Orders to inspect. Resellers only
        // ever see a clean, professional message here.
        if($hasProviderJsonError){
            // A valid provider JSON error is safe to surface as the business error.
            $userMsg=$msg;
        } elseif($uncertain){
            $userMsg="We couldn't confirm the result of this purchase with the supplier. Order {$orderCode} has been recorded as pending — please check My Keys before retrying, or contact support with this order code.";
        } else {
            $userMsg="Purchase could not be completed because the supplier rejected the request. Order {$orderCode} has been refunded to your wallet automatically. Please try again shortly, or contact support with this order code if this continues.";
        }
        throw new RuntimeException($userMsg);
    }
}

function execute_purchase(int $resellerId, int $planId, int $quantity, ?string $customNotes, string $source, ?string $idempotencyKey = null): array
{
    if ($quantity < 1 || $quantity > 100) {
        throw new RuntimeException('Quantity must be between 1 and 100.');
    }

    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $pdo->beginTransaction();

    try {
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $pdo->prepare('SELECT * FROM orders WHERE reseller_id = ? AND idempotency_key = ? LIMIT 1');
            $existing->execute([$resellerId, $idempotencyKey]);
            if ($row = $existing->fetch()) {
                $pdo->commit();
                return [
                    'order_code' => $row['order_code'],
                    'license_key' => $row['license_key'],
                    'unit_price' => (float)$row['unit_price'],
                    'total_price' => (float)$row['total_price'],
                    'price_source' => $row['price_source'],
                    'remaining_balance' => get_wallet_balance($resellerId),
                    'idempotent_replay' => true
                ];
            }
        }

        $planSql = "SELECT p.id,p.duration_label,p.default_price,p.status,p.stock_mode,p.provider_type,p.provider_product_id,p.dripstore_variant_id,
                           pr.id AS product_id,pr.name AS product_name,
                           pr.maintenance AS product_maintenance,pr.status AS product_status
                    FROM plans p JOIN products pr ON pr.id=p.product_id WHERE p.id=?";
        if ($driver === 'mysql') $planSql .= " FOR UPDATE";
        $stmt = $pdo->prepare($planSql);
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();

        if (!$plan || $plan['status'] !== 'active' || $plan['product_status'] !== 'active') {
            throw new RuntimeException('This plan is not available.');
        }
        if ($plan['product_maintenance'] === 'on') {
            throw new RuntimeException('This product is currently under maintenance. Purchases are temporarily disabled.');
        }
        if (is_global_maintenance()) {
            throw new RuntimeException('The website is currently under maintenance. Purchases are temporarily disabled.');
        }

        $providerType=$plan['provider_type']??((($plan['stock_mode']??'local')==='api')?'keyspanel':'local');
        if (in_array($providerType,['keyspanel','dripstore'],true)) {
            $pdo->rollBack();
            return execute_provider_purchase($resellerId,$planId,$quantity,$customNotes,$source,$idempotencyKey);
        }

        $priced = get_effective_price($resellerId,$planId);
        if ($priced === null) throw new RuntimeException('This plan is not available.');
        $unitPrice = round((float)$priced['price'],2);
        $totalPrice = round($unitPrice * $quantity,2);

        $walletSql = "SELECT balance FROM wallets WHERE reseller_id=?";
        if ($driver === 'mysql') $walletSql .= " FOR UPDATE";
        $walletStmt = $pdo->prepare($walletSql);
        $walletStmt->execute([$resellerId]);
        $walletRow = $walletStmt->fetch();
        $balance = $walletRow ? (float)$walletRow['balance'] : 0.0;
        if ($balance < $totalPrice) throw new RuntimeException('Insufficient wallet balance.');

        $orderCode = generate_order_code();
        $insert = $pdo->prepare(
            "INSERT INTO orders
             (order_code,reseller_id,plan_id,product_name,duration_label,quantity,unit_price,total_price,
              price_source,custom_notes,source,status,idempotency_key)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',?)"
        );
        $insert->execute([
            $orderCode,$resellerId,$planId,$plan['product_name'],$plan['duration_label'],$quantity,
            $unitPrice,$totalPrice,$priced['source'],$customNotes,$source,$idempotencyKey
        ]);
        $orderId=(int)$pdo->lastInsertId();

        $keySql = "SELECT id,key_value FROM license_keys
                   WHERE plan_id=? AND status='available' ORDER BY id ASC LIMIT ".(int)$quantity;
        if ($driver === 'mysql') $keySql .= " FOR UPDATE";
        $keyStmt=$pdo->prepare($keySql);
        $keyStmt->execute([$planId]);
        $keys=$keyStmt->fetchAll();
        if (count($keys) < $quantity) throw new RuntimeException('Insufficient stock.');

        $ids=array_map('intval',array_column($keys,'id'));
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $upd=$pdo->prepare(
            "UPDATE license_keys SET status='sold',order_id=?,sold_at=CURRENT_TIMESTAMP
             WHERE id IN ($ph) AND status='available'"
        );
        $upd->execute(array_merge([$orderId],$ids));
        if ($upd->rowCount() !== count($ids)) throw new RuntimeException('Stock changed during purchase. Please try again.');

        $keyText=implode("\n",array_column($keys,'key_value'));
        $newBalance=round($balance-$totalPrice,2);

        if ($walletRow) {
            $pdo->prepare('UPDATE wallets SET balance=? WHERE reseller_id=?')->execute([$newBalance,$resellerId]);
        } else {
            $pdo->prepare('INSERT INTO wallets(reseller_id,balance) VALUES(?,?)')->execute([$resellerId,$newBalance]);
        }

        $pdo->prepare("UPDATE orders SET license_key=?,status='success' WHERE id=?")->execute([$keyText,$orderId]);
        $pdo->prepare(
            'INSERT INTO wallet_transactions
             (reseller_id,type,amount,balance_after,note,admin_id,order_id)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $resellerId,'purchase',$totalPrice,
            $newBalance,
            "Order {$orderCode}: {$plan['product_name']} ({$plan['duration_label']}) x{$quantity}",
            null,$orderId
        ]);

        $pdo->commit();
        return [
            'order_code'=>$orderCode,'license_key'=>$keyText,'unit_price'=>$unitPrice,
            'total_price'=>$totalPrice,'price_source'=>$priced['source'],
            'remaining_balance'=>$newBalance,'idempotent_replay'=>false
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException($e->getMessage());
    }
}

/* ============================================================
   DYNAMIC DOMAIN
   ============================================================ */
function app_base_url(): string
{
    global $CONFIG;
    if (!empty($CONFIG['app_url_override'])) {
        return rtrim($CONFIG['app_url_override'], '/');
    }
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ||
        (($_SERVER['SERVER_PORT'] ?? '') === '443')
    );
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    return "{$scheme}://{$host}";
}
function api_base_url(): string
{
    return app_base_url() . '/index.php?api=';
}

/* ============================================================
   BRANDING / LOGO UPLOADS
   ============================================================ */
function validate_brand_name(string $name, string $fallback): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') return $fallback;
    if (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 40) {
        $name = mb_substr($name, 0, 40, 'UTF-8');
    } elseif (strlen($name) > 40) {
        $name = substr($name, 0, 40);
    }
    return $name;
}
function validate_brand_text(string $text, string $fallback, int $maxLength = 180): string
{
    $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '');
    if ($text === '') return $fallback;
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $maxLength) $text = mb_substr($text, 0, $maxLength, 'UTF-8');
    elseif (strlen($text) > $maxLength) $text = substr($text, 0, $maxLength);
    return $text;
}

function branding_upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE => 'The server rejected this upload because it is larger than the hosting PHP upload limit. The browser normally compresses logos automatically; please try the same logo again or use a smaller image.',
        UPLOAD_ERR_FORM_SIZE => 'The server rejected this upload because the submitted file is too large.',
        UPLOAD_ERR_PARTIAL => 'The logo upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Please select an image file.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary folder is unavailable. Please contact your hosting provider.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file. Check hosting storage/permissions.',
        UPLOAD_ERR_EXTENSION => 'A server extension stopped the logo upload. Please contact your hosting provider.',
        default => 'Logo upload failed. Please try again.',
    };
}

function save_brand_logo_upload(string $target): string
{
    if (!isset($_FILES['logo']) || !is_array($_FILES['logo'])) {
        throw new RuntimeException('Please select an image file.');
    }

    $file = $_FILES['logo'];
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException(branding_upload_error_message($uploadError));
    }

    // Keep a generous application limit. The browser-side optimizer below
    // normally sends a much smaller file, so this also works on shared hosts
    // whose PHP upload_max_filesize is around 2 MB.
    $size = (int)($file['size'] ?? 0);
    $maxBytes = 8 * 1024 * 1024;
    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('Logo must be between 1 byte and 8 MB.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Invalid upload.');

    $info = @getimagesize($tmp);
    if (!$info || empty($info['mime'])) {
        throw new RuntimeException('The uploaded file is not a valid image. Use PNG, JPG/JPEG or WEBP.');
    }

    $mime = strtolower((string)$info['mime']);
    $extMap = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    if (!isset($extMap[$mime])) throw new RuntimeException('Only PNG, JPG/JPEG and WEBP images are allowed.');

    $width = isset($info[0]) ? (int)$info[0] : 0;
    $height = isset($info[1]) ? (int)$info[1] : 0;
    if ($width < 1 || $height < 1 || $width > 5000 || $height > 5000) {
        throw new RuntimeException('Logo dimensions are too large. Please use an image up to 5000×5000 pixels.');
    }

    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'branding';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the branding folder. Check hosting permissions.');
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0755);
        if (!is_writable($dir)) {
            throw new RuntimeException('The branding folder is not writable by PHP. Set the branding folder permission to 0755 or contact your hosting provider.');
        }
    }

    $safeTarget = $target === 'login' ? 'login' : ($target === 'splash' ? 'splash' : ($target === 'admin' ? 'admin' : 'panel'));
    $filename = $safeTarget . '_' . bin2hex(random_bytes(12)) . '.' . $extMap[$mime];
    $destination = $dir . DIRECTORY_SEPARATOR . $filename;

    if (!@move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Could not save the uploaded logo. The branding folder may not be writable on this server.');
    }
    @chmod($destination, 0644);

    $logoSettingKey = $safeTarget === 'login' ? 'login_logo' : ($safeTarget === 'splash' ? 'splash_logo' : ($safeTarget === 'admin' ? 'admin_logo' : 'panel_logo'));
    $old = trim(get_setting($logoSettingKey, ''));
    $oldName = basename(parse_url($old, PHP_URL_PATH) ?: '');
    if ($oldName !== '' && preg_match('/^(login|panel|splash|admin)_[a-f0-9]{24}\.(png|jpg|webp)$/i', $oldName)) {
        $oldPath = $dir . DIRECTORY_SEPARATOR . $oldName;
        if (is_file($oldPath) && $oldPath !== $destination) @unlink($oldPath);
    }

    $relative = 'branding/' . $filename;
    try {
        set_setting($logoSettingKey, $relative);
    } catch (Throwable $e) {
        @unlink($destination);
        throw new RuntimeException('Logo file was uploaded, but the branding setting could not be saved. Please try again.');
    }
    return $relative;
}

/* ============================================================
   API ROUTER — every action below matches one of the old separate
   /api/*.php files exactly (same logic, same validation, same error
   codes). Runs and exits BEFORE any HTML is output.
   ============================================================ */
if (IS_API_REQUEST) {
    $action = (string) $_GET['api'];

    // GLOBAL WEBSITE MAINTENANCE — resellers may still log in so the
    // authenticated maintenance screen can be shown after login. All
    // reseller actions other than login/logout/session-status are blocked
    // server-side. Admin actions always remain fully accessible.
    $maintenanceAllowedWhenOn = ['login', 'logout', 'me', 'zapupi_webhook'];
    if (is_global_maintenance() && strpos($action, 'admin_') !== 0 && !in_array($action, $maintenanceAllowedWhenOn, true) && current_admin() === null) {
        json_error(get_setting('global_maintenance_message', 'The website is currently under maintenance. Please check back shortly.'), 503);
    }

    switch ($action) {

        case 'signup': {
            require_method('POST');
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            if (signup_rate_limited($ip)) json_error('Too many signup attempts. Please try again in 15 minutes.', 429);
            $b = json_body();
            $username = normalize_login_username((string)($b['username'] ?? ''));
            $password = (string)($b['password'] ?? '');
            $mobile   = normalize_signup_mobile((string)($b['mobile'] ?? ''));
            $email    = normalize_signup_email((string)($b['email'] ?? ''));
            if (strlen($password) < 8 || strlen($password) > 128) json_error('Password must be 8-128 characters.', 422);

            $pdo = db();
            $check = $pdo->prepare('SELECT username FROM resellers WHERE username = ? UNION SELECT username FROM admins WHERE username = ? LIMIT 1');
            $check->execute([$username, $username]);
            if ($check->fetch()) { signup_record_attempt($ip); json_error('That username is already taken.', 409); }
            $dupMobile = $pdo->prepare("SELECT id FROM resellers WHERE mobile = ? LIMIT 1");
            $dupMobile->execute([$mobile]);
            if ($dupMobile->fetch()) { signup_record_attempt($ip); json_error('That mobile number is already registered.', 409); }
            $dupEmail = $pdo->prepare("SELECT id FROM resellers WHERE email = ? LIMIT 1");
            $dupEmail->execute([$email]);
            if ($dupEmail->fetch()) { signup_record_attempt($ip); json_error('That email is already registered.', 409); }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO resellers (username,password_hash,account_type,mobile,email,status) VALUES (?,?,'user',?,?,'active')");
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $mobile, $email]);
                $id = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO wallets (reseller_id,balance) VALUES (?,0.00)')->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                signup_record_attempt($ip);
                error_log('Signup failed: ' . $e->getMessage());
                json_error('Could not create your account. Please try again.', 500);
            }
            signup_record_attempt($ip); // count successes too, so bulk-signup bots still get rate-limited

            start_session_if_needed();
            session_regenerate_id(true);
            $_SESSION['reseller_id'] = $id;
            unset($_SESSION['admin_id']);

            json_ok(['role' => 'reseller', 'account' => [
                'id' => $id, 'username' => $username, 'account_type' => 'user',
            ], 'csrf_token' => csrf_token(),
                'global_maintenance' => is_global_maintenance(),
                'global_maintenance_message' => get_setting('global_maintenance_message'),
                'group_update_url' => get_setting('group_update_url'),
                'deposit_min_amount' => max(0.01, (float)get_setting('deposit_min_amount','10')),
                'deposit_max_amount' => max(0.01, (float)get_setting('deposit_max_amount','100000')),
                'branding' => [
                    'panel_name' => get_setting('panel_name','KEYS PANEL'),
                    'panel_logo' => get_setting('panel_logo',''),
                ],
            ]);
        }

        case 'login': {
            require_method('POST');
            $body = json_body();
            $username = trim($body['username'] ?? '');
            $password = (string) ($body['password'] ?? '');
            [$success, $result] = login_unified($username, $password);
            if (!$success) {
                json_error($result, 401);
            }
            json_ok(['role' => $result['role'], 'account' => [
                'id' => (int) $result['id'],
                'username' => $result['username'],
                'account_type' => $result['account_type'] ?? 'reseller',
            ], 'csrf_token' => csrf_token(),
                'global_maintenance' => $result['role'] === 'reseller' ? is_global_maintenance() : false,
                'global_maintenance_message' => $result['role'] === 'reseller' ? get_setting('global_maintenance_message') : '',
                'group_update_url' => $result['role'] === 'reseller' ? get_setting('group_update_url') : '',
                'deposit_min_amount' => $result['role'] === 'reseller' ? max(0.01, (float)get_setting('deposit_min_amount','10')) : 10,
                'deposit_max_amount' => $result['role'] === 'reseller' ? max(0.01, (float)get_setting('deposit_max_amount','100000')) : 100000,
                'branding' => [
                    'panel_name' => get_setting('panel_name','KEYS PANEL'),
                    'panel_logo' => get_setting('panel_logo',''),
                    'admin_name' => get_setting('admin_name','KEYS PANEL ADMIN'),
                    'admin_logo' => get_setting('admin_logo',''),
                    'login_name' => get_setting('login_name','FLASH STAR'),
                    'login_logo' => get_setting('login_logo',''),
                    'splash_name' => get_setting('splash_name','FLASH STAR'),
                    'splash_logo' => get_setting('splash_logo',''),
                    'splash_kicker' => get_setting('splash_kicker','🔐 SECURE ACCESS POINT'),
                    'splash_welcome' => get_setting('splash_welcome','WELCOME TO'),
                    'splash_portal' => get_setting('splash_portal','RESELLER PORTAL'),
                    'splash_subtitle' => get_setting('splash_subtitle','Setting things up, taking you to sign in...'),
                    'splash_loading_text' => get_setting('splash_loading_text','REDIRECTING AUTOMATICALLY'),
                    'login_subtitle' => get_setting('login_subtitle','🔐 Ressler Portal — sign in to continue'),
                    'login_badge' => get_setting('login_badge','RESSLER PORTAL SIGN IN'),
                    'login_username_label' => get_setting('login_username_label','Username'),
                    'login_username_placeholder' => get_setting('login_username_placeholder','Your username'),
                    'login_password_label' => get_setting('login_password_label','Password'),
                    'login_password_placeholder' => get_setting('login_password_placeholder','Your password'),
                    'login_button_text' => get_setting('login_button_text','🔐  Log In  →'),
                    'login_message' => get_setting('login_message','Please enter your login details.'),
                    'login_footer' => get_setting('login_footer','Authorized personnel only'),
                ],
            ]);
        }

        case 'logout': {
            require_method('POST');
            start_session_if_needed();
            unset($_SESSION['reseller_id'], $_SESSION['admin_id']);
            session_regenerate_id(true);
            json_ok();
        }

        case 'me': {
            require_method('GET');
            $admin = current_admin();
            if ($admin !== null) {
                json_ok(['role' => 'admin', 'account' => [
                    'id' => (int) $admin['id'],
                    'username' => $admin['username'],
                ], 'csrf_token' => csrf_token(), 'sound_enabled' => get_setting('sound_enabled', 'on') === 'on', 'global_maintenance' => is_global_maintenance(), 'global_maintenance_message' => get_setting('global_maintenance_message'),
                'branding' => [
                    'panel_name' => get_setting('panel_name','KEYS PANEL'), 'panel_logo' => get_setting('panel_logo',''),
                    'admin_name' => get_setting('admin_name','KEYS PANEL ADMIN'), 'admin_logo' => get_setting('admin_logo',''),
                    'login_name' => get_setting('login_name','FLASH STAR'), 'login_logo' => get_setting('login_logo',''),
                    'splash_name' => get_setting('splash_name','FLASH STAR'), 'splash_logo' => get_setting('splash_logo',''),
                    'splash_kicker' => get_setting('splash_kicker','🔐 SECURE ACCESS POINT'), 'splash_welcome' => get_setting('splash_welcome','WELCOME TO'),
                    'splash_portal' => get_setting('splash_portal','RESELLER PORTAL'), 'splash_subtitle' => get_setting('splash_subtitle','Setting things up, taking you to sign in...'),
                    'splash_loading_text' => get_setting('splash_loading_text','REDIRECTING AUTOMATICALLY'), 'login_subtitle' => get_setting('login_subtitle','🔐 Ressler Portal — sign in to continue'),
                    'login_badge' => get_setting('login_badge','RESSLER PORTAL SIGN IN'), 'login_username_label' => get_setting('login_username_label','Username'),
                    'login_username_placeholder' => get_setting('login_username_placeholder','Your username'), 'login_password_label' => get_setting('login_password_label','Password'),
                    'login_password_placeholder' => get_setting('login_password_placeholder','Your password'), 'login_button_text' => get_setting('login_button_text','🔐  Log In  →'),
                    'login_message' => get_setting('login_message','Please enter your login details.'), 'login_footer' => get_setting('login_footer','Authorized personnel only'),
                ]]);
            }
            $reseller = current_reseller();
            if ($reseller === null) {
                json_error('Not authenticated.', 401);
            }
            json_ok(['role' => 'reseller', 'reseller' => [
                'id' => (int) $reseller['id'], 'username' => $reseller['username'],
                'account_type' => ($reseller['account_type'] ?? 'reseller') === 'user' ? 'user' : 'reseller',
                'balance' => get_wallet_balance((int) $reseller['id']),
                'telegram_username' => $reseller['telegram_username'],
            ], 'csrf_token' => csrf_token(),
                'sound_enabled' => get_setting('sound_enabled', 'on') === 'on',
                'support_url' => get_setting('support_url'),
                'group_update_url' => get_setting('group_update_url'),
                'deposit_min_amount' => max(0.01, (float)get_setting('deposit_min_amount','10')),
                'deposit_max_amount' => max(0.01, (float)get_setting('deposit_max_amount','100000')),
                'global_maintenance' => is_global_maintenance(),
                'global_maintenance_message' => get_setting('global_maintenance_message'),
                'branding' => [
                    'panel_name' => get_setting('panel_name','KEYS PANEL'),
                    'panel_logo' => get_setting('panel_logo',''),
                    'admin_name' => get_setting('admin_name','KEYS PANEL ADMIN'),
                    'admin_logo' => get_setting('admin_logo',''),
                    'login_name' => get_setting('login_name','FLASH STAR'),
                    'login_logo' => get_setting('login_logo',''),
                    'splash_name' => get_setting('splash_name','FLASH STAR'),
                    'splash_logo' => get_setting('splash_logo',''),
                    'splash_kicker' => get_setting('splash_kicker','🔐 SECURE ACCESS POINT'),
                    'splash_welcome' => get_setting('splash_welcome','WELCOME TO'),
                    'splash_portal' => get_setting('splash_portal','RESELLER PORTAL'),
                    'splash_subtitle' => get_setting('splash_subtitle','Setting things up, taking you to sign in...'),
                    'splash_loading_text' => get_setting('splash_loading_text','REDIRECTING AUTOMATICALLY'),
                    'login_subtitle' => get_setting('login_subtitle','🔐 Ressler Portal — sign in to continue'),
                    'login_badge' => get_setting('login_badge','RESSLER PORTAL SIGN IN'),
                    'login_username_label' => get_setting('login_username_label','Username'),
                    'login_username_placeholder' => get_setting('login_username_placeholder','Your username'),
                    'login_password_label' => get_setting('login_password_label','Password'),
                    'login_password_placeholder' => get_setting('login_password_placeholder','Your password'),
                    'login_button_text' => get_setting('login_button_text','🔐  Log In  →'),
                    'login_message' => get_setting('login_message','Please enter your login details.'),
                    'login_footer' => get_setting('login_footer','Authorized personnel only'),
                ],
            ]);
        }

        case 'admin_generate_credentials': {
            require_method('GET'); require_admin();
            json_ok(['username'=>generate_login_username(),'password'=>generate_login_password(),'csrf_token'=>csrf_token()]);
        }
        case 'admin_create_user': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf();
            $body=json_body(); $username=trim((string)($body['username']??'')); $password=(string)($body['password']??'');
            if($username==='') $username=generate_login_username();
            if($password==='') $password=generate_login_password();
            $created=admin_create_reseller($username,$password);
            $log=db()->prepare('INSERT INTO audit_logs (admin_id,action,target,details,ip_address) VALUES (?,?,?,?,?)');
            $log->execute([(int)$admin['id'],'create_reseller',$created['username'],'Created reseller login account.',$_SERVER['REMOTE_ADDR']??null]);
            json_ok(['user'=>$created]);
        }
        case 'admin_users': {
            require_method('GET'); require_admin();
            $rows=db()->query("SELECT id,username,status,created_at FROM resellers ORDER BY id DESC LIMIT 200")->fetchAll();
            foreach($rows as &$row){ $row['id']=(int)$row['id']; $row['balance']=get_wallet_balance((int)$row['id']); } unset($row);
            json_ok(['users'=>$rows,'csrf_token'=>csrf_token()]);
        }
        case 'admin_toggle_user': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf();
            $body=json_body(); $id=(int)($body['id']??0); $status=(string)($body['status']??'');
            if($id<=0 || !in_array($status,['active','disabled'],true)) json_error('Invalid user or status.',422);
            db()->prepare('UPDATE resellers SET status=? WHERE id=?')->execute([$status,$id]);
            $log=db()->prepare('INSERT INTO audit_logs (admin_id,action,target,details,ip_address) VALUES (?,?,?,?,?)');
            $log->execute([(int)$admin['id'],'toggle_reseller',(string)$id,'Set status to '.$status,$_SERVER['REMOTE_ADDR']??null]);
            json_ok();
        }

        case 'admin_catalog': {
            require_method('GET'); require_admin();
            $pdo=db();
            $categories=$pdo->query("SELECT id,name,sort_order,status,created_at FROM categories ORDER BY sort_order ASC,name ASC")->fetchAll();
            $products=$pdo->query("SELECT p.id,p.category_id,p.name,p.description,p.maintenance,p.status,c.name AS category_name FROM products p JOIN categories c ON c.id=p.category_id ORDER BY p.id DESC")->fetchAll();
            $plans=$pdo->query("SELECT pl.id,pl.product_id,pl.duration_label,pl.default_price,pl.status,pl.stock_mode,pl.provider_type,pl.provider_product_id,pl.dripstore_variant_id,pr.name AS product_name FROM plans pl JOIN products pr ON pr.id=pl.product_id ORDER BY pl.id DESC")->fetchAll();
            foreach($plans as &$pl){ $providerType=$pl['provider_type']??(($pl['stock_mode']==='api')?'keyspanel':'local'); $pl['stock']=($providerType==='dripstore'||$providerType==='keyspanel') ? 99 : get_plan_stock((int)$pl['id']); } unset($pl);
            $keys=$pdo->query("SELECT lk.id,lk.plan_id,lk.key_value,lk.status,pl.duration_label,pr.name AS product_name FROM license_keys lk JOIN plans pl ON pl.id=lk.plan_id JOIN products pr ON pr.id=pl.product_id ORDER BY lk.id DESC LIMIT 300")->fetchAll();
            json_ok(['categories'=>$categories,'products'=>$products,'plans'=>$plans,'keys'=>$keys,'csrf_token'=>csrf_token()]);
        }
        case 'admin_add_category': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $b=json_body(); $name=trim((string)($b['name']??''));
            if($name==='') json_error('Category name is required.',422);
            db()->prepare('INSERT INTO categories(name,sort_order,status) VALUES(?,?,?)')->execute([$name,(int)($b['sort_order']??0),'active']);
            json_ok(['message'=>'Category created.']);
        }
        case 'admin_add_product': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf();
            $b=json_body(); $name=trim((string)($b['name']??'')); $cid=(int)($b['category_id']??0);
            $description=trim((string)($b['description']??''));
            if($name===''||$cid<=0) json_error('Product name and category are required.',422);

            $pdo=db();
            $cat=$pdo->prepare("SELECT id FROM categories WHERE id=? AND status='active'");
            $cat->execute([$cid]);
            if(!$cat->fetchColumn()) json_error('Selected category is not active.',422);

            $plans=is_array($b['plans']??null)?array_values($b['plans']):[];
            if(!$plans) json_error('At least one duration/plan is required.',422);

            $pdo->beginTransaction();
            try{
                $pdo->prepare("INSERT INTO products(category_id,name,description,status,maintenance) VALUES(?,?,?,?,?)")
                    ->execute([$cid,$name,$description,'active','off']);
                $productId=(int)$pdo->lastInsertId();
                $planStmt=$pdo->prepare("INSERT INTO plans(product_id,duration_label,default_price,status,stock_mode,provider_type,provider_product_id,dripstore_variant_id) VALUES(?,?,?,?,?,?,?,?)");
                $keyStmt=$pdo->prepare("INSERT INTO license_keys(plan_id,key_value,status) VALUES(?,?,?)");
                $createdPlans=0; $addedKeys=0;

                foreach($plans as $plan){
                    $duration=trim((string)($plan['duration_label']??''));
                    $price=(float)($plan['default_price']??0);
                    if($duration===''||!is_finite($price)||$price<0) throw new RuntimeException('Each duration needs a valid duration and selling price.');
                    $providerType=in_array(($plan['provider_type']??''),['local','keyspanel','dripstore'],true)?$plan['provider_type']:'local';
                    $legacyMode=$providerType==='local'?'local':'api';
                    $keyspanelVariant=trim((string)($plan['provider_variant_id']??''));
                    $dripstoreVariant=trim((string)($plan['dripstore_variant_id']??''));
                    if($providerType==='keyspanel' && $keyspanelVariant==='') throw new RuntimeException('KEYSPANEL provider requires a Variant ID.');
                    if($providerType==='dripstore' && $dripstoreVariant==='') throw new RuntimeException('DRIPSTORE provider requires a Variant ID.');
                    $planStmt->execute([$productId,$duration,round($price,2),'active',$legacyMode,$providerType,
                        $keyspanelVariant!==''?$keyspanelVariant:null,
                        $dripstoreVariant!==''?$dripstoreVariant:null]);
                    $planId=(int)$pdo->lastInsertId(); $createdPlans++;
                    $seen=[];
                    $lines=preg_split('/\R/u',(string)($plan['keys_text']??'')) ?: [];
                    foreach($lines as $line){
                        $key=trim((string)$line);
                        if($key===''||isset($seen[$key])) continue;
                        $seen[$key]=true; $keyStmt->execute([$planId,$key,'available']); $addedKeys++;
                    }
                }
                $pdo->commit();
            }catch(Throwable $e){
                if($pdo->inTransaction()) $pdo->rollBack();
                json_error($e->getMessage()?:'Could not create product.',422);
            }
            audit_log((int)$admin['id'],'create_product',$name,'Created product #'.$productId.' with '.$createdPlans.' duration(s) and '.$addedKeys.' local key(s).');
            json_ok(['message'=>'Product created successfully.','product_id'=>$productId,'plans_created'=>$createdPlans,'keys_added'=>$addedKeys]);
        }
        case 'admin_update_product': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf();
            $b=json_body(); $id=(int)($b['id']??0);
            if($id<=0) json_error('Invalid product.',422);
            $name=trim((string)($b['name']??'')); $cid=(int)($b['category_id']??0);
            if($name===''||$cid<=0) json_error('Product name and category are required.',422);
            $status=in_array(($b['status']??'active'),['active','disabled'],true)?$b['status']:'active';
            $maintenance=in_array(($b['maintenance']??'off'),['on','off'],true)?$b['maintenance']:'off';
            db()->prepare('UPDATE products SET category_id=?,name=?,description=?,status=?,maintenance=? WHERE id=?')
                ->execute([$cid,$name,trim((string)($b['description']??'')),$status,$maintenance,$id]);
            audit_log((int)$admin['id'],'update_product',$name,'Updated local product #'.$id);
            json_ok(['message'=>'Product updated.']);
        }
        case 'admin_delete_product': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $id=(int)(json_body()['id']??0); if($id<=0) json_error('Invalid product.',422);
            $pdo=db();
            $exists=$pdo->prepare('SELECT id,name FROM products WHERE id=? LIMIT 1'); $exists->execute([$id]); $productRow=$exists->fetch();
            if(!$productRow) json_error('Product not found.',404);
            $planIds=array_map('intval',array_column($pdo->query('SELECT id FROM plans WHERE product_id='.(int)$id)->fetchAll(),'id'));
            $pdo->beginTransaction();
            try{
                if($planIds){
                    $ph=implode(',',array_fill(0,count($planIds),'?'));
                    /* A hard delete must also remove dependent order/ledger rows; otherwise FK constraints
                       force the old product to remain disabled. This is an explicit TOTAL DELETE operation. */
                    $pdo->prepare("UPDATE wallet_transactions SET order_id=NULL WHERE order_id IN (SELECT id FROM orders WHERE plan_id IN ($ph))")->execute($planIds);
                    $pdo->prepare("DELETE FROM orders WHERE plan_id IN ($ph)")->execute($planIds);
                    $pdo->prepare("DELETE FROM custom_prices WHERE plan_id IN ($ph)")->execute($planIds);
                    $pdo->prepare("DELETE FROM license_keys WHERE plan_id IN ($ph)")->execute($planIds);
                    $pdo->prepare("DELETE FROM plans WHERE id IN ($ph)")->execute($planIds);
                }
                $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
                $pdo->commit();
            }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); error_log('admin_delete_product: '.$e->getMessage()); json_error('Could not completely delete product.',500); }
            audit_log((int)$admin['id'],'delete_product',(string)$id,'Permanently deleted product #'.$id.', its durations, local keys, custom prices and related orders.');
            json_ok(['message'=>'Product permanently deleted.']);
        }
        case 'admin_toggle_product_maintenance': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $b=json_body(); $id=(int)($b['id']??0); $m=(string)($b['maintenance']??'');
            if($id<=0||!in_array($m,['on','off'],true)) json_error('Invalid product or maintenance value.',422);
            db()->prepare('UPDATE products SET maintenance=? WHERE id=?')->execute([$m,$id]);
            audit_log((int)$admin['id'],'product_maintenance',(string)$id,'Maintenance set to '.$m);
            json_ok(['message'=>'Product maintenance mode set to '.strtoupper($m).'.']);
        }
        case 'admin_update_category': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $b=json_body(); $id=(int)($b['id']??0); $name=trim((string)($b['name']??''));
            if($id<=0||$name==='') json_error('Category and name are required.',422);
            $status = in_array(($b['status']??'active'),['active','disabled'],true) ? $b['status'] : 'active';
            db()->prepare('UPDATE categories SET name=?,sort_order=?,status=? WHERE id=?')->execute([$name,(int)($b['sort_order']??0),$status,$id]);
            audit_log((int)$admin['id'],'update_category',$name,'Updated category #'.$id);
            json_ok(['message'=>'Category updated.']);
        }
        case 'admin_delete_category': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $id=(int)(json_body()['id']??0); if($id<=0) json_error('Invalid category.',422);
            $pdo=db();
            $inUse=(int)$pdo->query("SELECT COUNT(*) FROM products WHERE category_id=".(int)$id." AND status='active'")->fetchColumn();
            if($inUse>0) json_error('Move or delete the products in this category first.',409);
            $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
            audit_log((int)$admin['id'],'delete_category',(string)$id,null);
            json_ok(['message'=>'Category deleted.']);
        }
        case 'admin_add_plan': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf();
            $b=json_body(); $pid=(int)($b['product_id']??0); $duration=trim((string)($b['duration_label']??'')); $price=(float)($b['default_price']??0);
            $providerType = in_array(($b['provider_type']??''),['local','keyspanel','dripstore'],true) ? $b['provider_type'] : ((($b['stock_mode']??'local')==='api')?'keyspanel':'local');
            $stockMode = $providerType==='local' ? 'local' : 'api';
            $providerVariantId = trim((string)($b['provider_variant_id']??''));
            $dripstoreVariantId = trim((string)($b['dripstore_variant_id']??''));
            if($providerType==='keyspanel' && $providerVariantId==='') json_error('KEYSPANEL Variant ID is required.',422);
            if($providerType==='dripstore' && $dripstoreVariantId==='') json_error('DRIPSTORE Variant ID is required.',422);
            if($pid<=0||$duration===''||!is_finite($price)||$price<0) json_error('Product, duration and valid price are required.',422);
            $check=db()->prepare("SELECT id FROM products WHERE id=? AND status='active'"); $check->execute([$pid]);
            if(!$check->fetchColumn()) json_error('Product not found or disabled.',404);
            $pdo=db();
            $pdo->prepare('INSERT INTO plans(product_id,duration_label,default_price,status,stock_mode,provider_type,provider_product_id,dripstore_variant_id) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([$pid,$duration,round($price,2),'active',$stockMode,$providerType,
                    $providerType==='keyspanel'?$providerVariantId:null,
                    $providerType==='dripstore'?$dripstoreVariantId:null]);
            $planId=(int)$pdo->lastInsertId();
            $keysText=(string)($b['keys_text']??''); $added=0;
            if($stockMode==='local'){
                $lines=preg_split('/\R/u',$keysText)?:[]; $seen=[];
                $stmt=$pdo->prepare("INSERT INTO license_keys(plan_id,key_value,status) VALUES(?,?,?)");
                $pdo->beginTransaction();
                try{
                    foreach($lines as $line){$key=trim((string)$line);if($key===''||isset($seen[$key]))continue;$seen[$key]=true;$stmt->execute([$planId,$key,'available']);$added++;}
                    $pdo->commit();
                }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_error('Could not add initial keys.',500);}
            }
            audit_log((int)$admin['id'],'create_plan',$duration,'Created '.strtoupper($providerType).' duration #'.$planId.' for product #'.$pid.' with '.$added.' local key(s).');
            json_ok(['message'=>'Duration created successfully.','plan_id'=>$planId,'keys_added'=>$added]);
        }
        case 'admin_update_plan': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf();
            $b=json_body(); $id=(int)($b['id']??0); $duration=trim((string)($b['duration_label']??'')); $price=(float)($b['default_price']??0);
            if($id<=0||$duration===''||!is_finite($price)||$price<0) json_error('Duration and valid price are required.',422);
            $status=in_array(($b['status']??'active'),['active','disabled'],true)?$b['status']:'active';
            $providerType=in_array(($b['provider_type']??''),['local','keyspanel','dripstore'],true)?$b['provider_type']:((($b['stock_mode']??'local')==='api')?'keyspanel':'local');
            $stockMode=$providerType==='local'?'local':'api';
            $providerVariantId=trim((string)($b['provider_variant_id']??''));
            $dripstoreVariantId=trim((string)($b['dripstore_variant_id']??''));
            if($providerType==='keyspanel' && $providerVariantId==='') json_error('KEYSPANEL Variant ID is required.',422);
            if($providerType==='dripstore' && $dripstoreVariantId==='') json_error('DRIPSTORE Variant ID is required.',422);
            if($providerType==='local'){ $providerVariantId=''; $dripstoreVariantId=''; }
            elseif($providerType==='keyspanel') $dripstoreVariantId='';
            else $providerVariantId='';
            db()->prepare('UPDATE plans SET duration_label=?,default_price=?,status=?,stock_mode=?,provider_type=?,provider_product_id=?,dripstore_variant_id=? WHERE id=?')
                ->execute([$duration,round($price,2),$status,$stockMode,$providerType,
                    $providerVariantId!==''?$providerVariantId:null,
                    $dripstoreVariantId!==''?$dripstoreVariantId:null,$id]);
            audit_log((int)$admin['id'],'update_plan',$duration,'Updated '.strtoupper($providerType).' duration #'.$id);
            json_ok(['message'=>'Duration updated.']);
        }
        case 'admin_delete_plan': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $id=(int)(json_body()['id']??0); if($id<=0) json_error('Invalid variation.',422);
            $pdo=db(); $pdo->beginTransaction();
            try{
                $pdo->prepare('UPDATE wallet_transactions SET order_id=NULL WHERE order_id IN (SELECT id FROM orders WHERE plan_id=?)')->execute([$id]);
                $pdo->prepare('DELETE FROM orders WHERE plan_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM custom_prices WHERE plan_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM license_keys WHERE plan_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM plans WHERE id=?')->execute([$id]);
                $pdo->commit();
            }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); error_log('admin_delete_plan: '.$e->getMessage()); json_error('Could not completely delete duration.',500); }
            audit_log((int)$admin['id'],'delete_plan',(string)$id,'Permanently deleted duration, its keys, custom prices and related orders.');
            json_ok(['message'=>'Duration permanently deleted.']);
        }
        case 'admin_plan_keys': {
            // Every variation/plan has its own, completely separate key list —
            // never mixed with any other plan's keys.
            require_method('GET'); require_admin(); $pid=(int)($_GET['plan_id']??0); if($pid<=0) json_error('Invalid variation.',422);
            $page = max(1,(int)($_GET['page']??1)); $perPage=50; $offset=($page-1)*$perPage;
            $pdo=db();
            $countStmt=$pdo->prepare('SELECT COUNT(*) FROM license_keys WHERE plan_id=?'); $countStmt->execute([$pid]); $total=(int)$countStmt->fetchColumn();
            $stmt=$pdo->prepare('SELECT id,key_value,status,added_at,sold_at,order_id FROM license_keys WHERE plan_id=? ORDER BY id DESC LIMIT '.$perPage.' OFFSET '.$offset);
            $stmt->execute([$pid]);
            $available=(int)get_plan_stock($pid);
            json_ok(['keys'=>$stmt->fetchAll(),'available_count'=>$available,'total'=>$total,'page'=>$page,'per_page'=>$perPage]);
        }
        case 'admin_add_keys_bulk': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $b=json_body(); $pid=(int)($b['plan_id']??0);
            $text=(string)($b['keys_text']??''); if($pid<=0||trim($text)==='') json_error('Variation and at least one key are required.',422);
            $lines = array_values(array_filter(array_map('trim', explode("\n",$text)), fn($l)=>$l!==''));
            if(!$lines) json_error('No valid keys found.',422);
            $pdo=db();
            $exists=$pdo->prepare('SELECT 1 FROM license_keys WHERE plan_id=? AND key_value=? LIMIT 1');
            $stmt=$pdo->prepare("INSERT INTO license_keys(plan_id,key_value,status) VALUES(?,?,'available')");
            $pdo->beginTransaction(); $added=0; $duplicates=0;
            try{
                foreach($lines as $l){
                    $exists->execute([$pid,$l]);
                    if($exists->fetchColumn()){ $duplicates++; continue; }
                    $stmt->execute([$pid,$l]); $added++;
                }
                $pdo->commit();
            }catch(Throwable $e){ $pdo->rollBack(); json_error('Could not add keys.',500); }
            audit_log((int)$admin['id'],'bulk_add_keys',(string)$pid,$added.' keys added; '.$duplicates.' duplicate key(s) skipped.');
            json_ok(['message'=>$added.' license key(s) added to stock.'.($duplicates?' '.$duplicates.' duplicate(s) skipped.':''),'added'=>$added,'duplicates'=>$duplicates]);
        }
        case 'admin_clear_keys': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $pid=(int)(json_body()['plan_id']??0); if($pid<=0) json_error('Invalid variation.',422);
            $stmt=db()->prepare("DELETE FROM license_keys WHERE plan_id=? AND status='available'"); $stmt->execute([$pid]);
            $n=$stmt->rowCount();
            audit_log((int)$admin['id'],'clear_keys',(string)$pid,$n.' unsold keys cleared.');
            json_ok(['message'=>$n.' unsold key(s) cleared from this variation.','cleared'=>$n]);
        }

        /* ============================================================
           USER MANAGEMENT — enable/disable/ban/unban/reset/delete/balance
           ============================================================ */
        case 'admin_ban_user': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $id=(int)(json_body()['id']??0); if($id<=0) json_error('Invalid user.',422);
            db()->prepare("UPDATE resellers SET status='banned' WHERE id=?")->execute([$id]);
            audit_log((int)$admin['id'],'ban_user',(string)$id,null);
            json_ok(['message'=>'User banned.']);
        }
        case 'admin_unban_user': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $id=(int)(json_body()['id']??0); if($id<=0) json_error('Invalid user.',422);
            db()->prepare("UPDATE resellers SET status='active' WHERE id=?")->execute([$id]);
            audit_log((int)$admin['id'],'unban_user',(string)$id,null);
            json_ok(['message'=>'User unbanned and re-activated.']);
        }
        case 'admin_reset_password': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $b=json_body(); $id=(int)($b['id']??0); if($id<=0) json_error('Invalid user.',422);
            $newPassword = trim((string)($b['password']??'')); if($newPassword==='') $newPassword = generate_login_password();
            if(strlen($newPassword)<8) json_error('Password must be at least 8 characters.',422);
            db()->prepare('UPDATE resellers SET password_hash=? WHERE id=?')->execute([password_hash($newPassword,PASSWORD_DEFAULT),$id]);
            audit_log((int)$admin['id'],'reset_password',(string)$id,null);
            json_ok(['message'=>'Password reset.','password'=>$newPassword]);
        }
        case 'admin_delete_user': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $id=(int)(json_body()['id']??0); if($id<=0) json_error('Invalid user.',422);
            $pdo=db();
            try {
                // Hard delete when possible (cascades wallet/keys/api_keys). If the account
                // has order history, the foreign key blocks the delete on purpose — in that
                // case we soft-delete instead so past orders/revenue are never lost.
                $pdo->prepare('DELETE FROM resellers WHERE id=?')->execute([$id]);
                audit_log((int)$admin['id'],'delete_user',(string)$id,'Hard deleted.');
                json_ok(['message'=>'User deleted.']);
            } catch (Throwable $e) {
                $anon = 'deleted_user_' . $id . '_' . random_int(1000,9999);
                $pdo->prepare("UPDATE resellers SET username=?, status='disabled' WHERE id=?")->execute([$anon,$id]);
                audit_log((int)$admin['id'],'delete_user',(string)$id,'Soft-deleted (has order history); login disabled.');
                json_ok(['message'=>'User has order history so the account was disabled and anonymized instead of hard-deleted.']);
            }
        }
        case 'admin_add_balance': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $b=json_body(); $id=(int)($b['id']??0); $amount=(float)($b['amount']??0);
            if($id<=0||$amount<=0) json_error('Valid user and a positive amount are required.',422);
            try{ $bal=adjust_wallet($id,'credit',round($amount,2),trim((string)($b['note']??'Admin added balance')),(int)$admin['id']); }
            catch(Throwable $e){ json_error('Could not add balance.',500); }
            audit_log((int)$admin['id'],'add_balance',(string)$id,'+'.$amount);
            json_ok(['message'=>'Balance added.','balance'=>$bal]);
        }
        case 'admin_remove_balance': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $b=json_body(); $id=(int)($b['id']??0); $amount=(float)($b['amount']??0);
            if($id<=0||$amount<=0) json_error('Valid user and a positive amount are required.',422);
            try{ $bal=adjust_wallet($id,'debit',round($amount,2),trim((string)($b['note']??'Admin removed balance')),(int)$admin['id']); }
            catch(Throwable $e){ json_error('Insufficient balance to remove that amount.',422); }
            audit_log((int)$admin['id'],'remove_balance',(string)$id,'-'.$amount);
            json_ok(['message'=>'Balance removed.','balance'=>$bal]);
        }
        case 'admin_user_details': {
            require_method('GET'); require_admin(); $id=(int)($_GET['id']??0); if($id<=0) json_error('Invalid user.',422);
            $pdo=db();
            $stmt=$pdo->prepare('SELECT id,username,status,telegram_username,created_at FROM resellers WHERE id=?'); $stmt->execute([$id]);
            $user=$stmt->fetch(); if(!$user) json_error('User not found.',404);
            $user['id']=(int)$user['id']; $user['balance']=get_wallet_balance($id);
            $orders=$pdo->prepare('SELECT order_code,product_name,duration_label,quantity,total_price,status,source,created_at FROM orders WHERE reseller_id=? ORDER BY id DESC LIMIT 50');
            $orders->execute([$id]);
            $history=$pdo->prepare('SELECT type,amount,balance_after,note,created_at FROM wallet_transactions WHERE reseller_id=? ORDER BY id DESC LIMIT 50');
            $history->execute([$id]);
            $apiKeyStmt=$pdo->prepare("SELECT key_prefix,status,created_at FROM api_keys WHERE reseller_id=? ORDER BY id DESC LIMIT 5");
            $apiKeyStmt->execute([$id]);
            json_ok(['user'=>$user,'orders'=>$orders->fetchAll(),'wallet_history'=>$history->fetchAll(),'api_keys'=>$apiKeyStmt->fetchAll()]);
        }

        /* ============================================================
           SETTINGS — global maintenance, support URL, sound toggle
           ============================================================ */
        case 'admin_upload_brand_logo': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf();
            $target = (string)($_POST['target'] ?? '');
            if ($target !== 'panel' && $target !== 'login' && $target !== 'splash' && $target !== 'admin') json_error('Invalid branding target.',422);
            try {
                $path = save_brand_logo_upload($target);
                audit_log((int)$admin['id'],'brand_logo_upload',$target,'Uploaded new '.$target.' logo.');
                json_ok(['message'=>ucfirst($target).' logo uploaded.','path'=>$path]);
            } catch (Throwable $e) {
                json_error($e->getMessage(),422);
            }
        }

        case 'admin_user_pricelist_get': {
            require_method('GET'); require_admin();
            $rows = db()->query(
                "SELECT p.id AS plan_id, p.duration_label, p.default_price, pr.name AS product_name, pr.id AS product_id,
                        cp.custom_price AS user_price
                 FROM plans p JOIN products pr ON pr.id = p.product_id
                 LEFT JOIN custom_prices cp ON cp.plan_id = p.id AND cp.reseller_id = ".USER_PRICE_TIER_ID." AND cp.status='active'
                 WHERE p.status='active' ORDER BY pr.name, p.duration_label"
            )->fetchAll();
            json_ok(['plans' => $rows]);
        }
        case 'admin_user_pricelist_set': {
            require_method('POST'); $admin = require_admin(); require_valid_csrf();
            $b = json_body();
            $planId = (int)($b['plan_id'] ?? 0);
            $price = $b['price'] ?? null; // null/'' => remove override, fall back to default_price
            $pdo = db();
            $plan = $pdo->prepare('SELECT id FROM plans WHERE id=?'); $plan->execute([$planId]);
            if (!$plan->fetch()) json_error('Plan not found.', 404);
            if ($price === null || $price === '') {
                $pdo->prepare('DELETE FROM custom_prices WHERE reseller_id=? AND plan_id=?')->execute([USER_PRICE_TIER_ID, $planId]);
                audit_log((int)$admin['id'], 'user_pricelist_reset', null, "Plan #$planId reset to default price.");
                json_ok(['message' => 'Reset to default price.']);
            }
            $price = round((float)$price, 2);
            if ($price < 0 || $price > 1000000) json_error('Enter a valid price.', 422);
            $existing = $pdo->prepare('SELECT id FROM custom_prices WHERE reseller_id=? AND plan_id=?');
            $existing->execute([USER_PRICE_TIER_ID, $planId]);
            if ($row = $existing->fetch()) {
                $pdo->prepare("UPDATE custom_prices SET custom_price=?, status='active' WHERE id=?")->execute([$price, $row['id']]);
            } else {
                $pdo->prepare("INSERT INTO custom_prices (reseller_id,plan_id,custom_price,status) VALUES (?,?,?,'active')")->execute([USER_PRICE_TIER_ID, $planId, $price]);
            }
            audit_log((int)$admin['id'], 'user_pricelist_set', null, "Plan #$planId user price set to ₹$price.");
            json_ok(['message' => 'User price saved.']);
        }
        case 'admin_settings_get': {
            require_method('GET'); require_admin();
            json_ok(['settings'=>[
                'global_maintenance'=>get_setting('global_maintenance','off'),
                'global_maintenance_message'=>get_setting('global_maintenance_message'),
                'support_url'=>get_setting('support_url'),
                'group_update_url'=>get_setting('group_update_url'),
                'panel_name'=>get_setting('panel_name','KEYS PANEL'),
                'panel_logo'=>get_setting('panel_logo',''),
                'admin_name'=>get_setting('admin_name','KEYS PANEL ADMIN'),
                'admin_logo'=>get_setting('admin_logo',''),
                'login_name'=>get_setting('login_name','FLASH STAR'),
                'login_logo'=>get_setting('login_logo',''),
                'splash_name'=>get_setting('splash_name','FLASH STAR'),
                'splash_logo'=>get_setting('splash_logo',''),
                'splash_kicker'=>get_setting('splash_kicker','🔐 SECURE ACCESS POINT'),
                'splash_welcome'=>get_setting('splash_welcome','WELCOME TO'),
                'splash_portal'=>get_setting('splash_portal','RESELLER PORTAL'),
                'splash_subtitle'=>get_setting('splash_subtitle','Setting things up, taking you to sign in...'),
                'splash_loading_text'=>get_setting('splash_loading_text','REDIRECTING AUTOMATICALLY'),
                'login_subtitle'=>get_setting('login_subtitle','🔐 Ressler Portal — sign in to continue'),
                'login_badge'=>get_setting('login_badge','RESSLER PORTAL SIGN IN'),
                'login_username_label'=>get_setting('login_username_label','Username'),
                'login_username_placeholder'=>get_setting('login_username_placeholder','Your username'),
                'login_password_label'=>get_setting('login_password_label','Password'),
                'login_password_placeholder'=>get_setting('login_password_placeholder','Your password'),
                'login_button_text'=>get_setting('login_button_text','🔐  Log In  →'),
                'login_message'=>get_setting('login_message','Please enter your login details.'),
                'login_footer'=>get_setting('login_footer','Authorized personnel only'),
                'deposit_min_amount'=>get_setting('deposit_min_amount','10'),
                'deposit_max_amount'=>get_setting('deposit_max_amount','100000'),
                'sound_enabled'=>get_setting('sound_enabled','on'),
                'paytm_enabled'=>get_setting('paytm_enabled','off'),
                'paytm_mid'=>get_setting('paytm_mid',''),
                'paytm_upi_id'=>get_setting('paytm_upi_id',''),
                'paytm_display_name'=>get_setting('paytm_display_name','KEYS PANEL'),
                'provider_api_enabled'=>get_setting('provider_api_enabled','off'),
                'provider_api_url'=>provider_api_config()['url'],
                'provider_api_configured'=>keyspanel_configuration_status()['configured'],
                'provider_api_token_updated_at'=>get_setting('provider_api_token_updated_at',''),
                'dripstore_api_enabled'=>get_setting('dripstore_api_enabled','off'),
                'dripstore_api_url'=>dripstore_api_config()['url'],
                'dripstore_api_configured'=>dripstore_configuration_status()['configured'],
                'dripstore_api_token_updated_at'=>get_setting('dripstore_api_token_updated_at',''),
                // The proxy string can carry embedded user:pass@ credentials, so —
                // same masking pattern as provider_api_token — the frontend only
                // ever gets whether one is set, never the value itself.
                'provider_api_proxy_configured'=>trim((string)get_setting('provider_api_proxy',''))!=='',
                'keyspanel_max_quantity_per_request'=>KEYSPANEL_MAX_QUANTITY_PER_REQUEST,
                'paytm_configured'=>(get_setting('paytm_mid','')!=='' && get_setting('paytm_upi_id','')!==''),
                'zapupi_enabled'=>get_setting('zapupi_enabled','off'),
                'zapupi_configured'=>(get_setting('zapupi_key_enc','')!==''),
            ],'csrf_token'=>csrf_token()]);
        }
        case 'admin_settings_update': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $b=json_body();
            if(isset($b['global_maintenance'])){
                $v = $b['global_maintenance']==='on' ? 'on' : 'off';
                set_setting('global_maintenance',$v);
                audit_log((int)$admin['id'],'global_maintenance',null,'Set to '.$v);
            }
            if(isset($b['global_maintenance_message'])) set_setting('global_maintenance_message', trim((string)$b['global_maintenance_message']));
            if(isset($b['support_url'])){
                $url = trim((string)$b['support_url']);
                if($url!=='' && !filter_var($url, FILTER_VALIDATE_URL)) json_error('Support URL is not a valid URL.',422);
                set_setting('support_url',$url);
            }
            if(isset($b['group_update_url'])){
                $url = trim((string)$b['group_update_url']);
                if($url!=='' && !filter_var($url, FILTER_VALIDATE_URL)) json_error('Group update URL is not a valid URL.',422);
                set_setting('group_update_url',$url);
            }
            if(isset($b['panel_name'])) set_setting('panel_name', validate_brand_name((string)$b['panel_name'],'KEYS PANEL'));
            if(isset($b['admin_name'])) set_setting('admin_name', validate_brand_name((string)$b['admin_name'],'KEYS PANEL ADMIN'));
            if(isset($b['login_name'])) set_setting('login_name', validate_brand_name((string)$b['login_name'],'FLASH STAR'));
            if(isset($b['splash_name'])) set_setting('splash_name', validate_brand_name((string)$b['splash_name'],'FLASH STAR'));

            // Admin-controlled splash/login copy. Presentation-only; authentication logic is untouched.
            $textSettings = [
                'splash_kicker'=>['fallback'=>'🔐 SECURE ACCESS POINT','max'=>80],
                'splash_welcome'=>['fallback'=>'WELCOME TO','max'=>80],
                'splash_portal'=>['fallback'=>'RESELLER PORTAL','max'=>80],
                'splash_subtitle'=>['fallback'=>'Setting things up, taking you to sign in...','max'=>180],
                'splash_loading_text'=>['fallback'=>'REDIRECTING AUTOMATICALLY','max'=>80],
                'login_subtitle'=>['fallback'=>'🔐 Ressler Portal — sign in to continue','max'=>180],
                'login_badge'=>['fallback'=>'RESSLER PORTAL SIGN IN','max'=>100],
                'login_username_label'=>['fallback'=>'Username','max'=>60],
                'login_username_placeholder'=>['fallback'=>'Your username','max'=>100],
                'login_password_label'=>['fallback'=>'Password','max'=>60],
                'login_password_placeholder'=>['fallback'=>'Your password','max'=>100],
                'login_button_text'=>['fallback'=>'🔐  Log In  →','max'=>80],
                'login_message'=>['fallback'=>'Please enter your login details.','max'=>180],
                'login_footer'=>['fallback'=>'Authorized personnel only','max'=>120],
            ];
            foreach($textSettings as $key=>$cfg){
                if(isset($b[$key])) set_setting($key, validate_brand_text((string)$b[$key], $cfg['fallback'], $cfg['max']));
            }
            if(isset($b['deposit_min_amount']) || isset($b['deposit_max_amount'])){
                $min = isset($b['deposit_min_amount']) ? round((float)$b['deposit_min_amount'],2) : round((float)get_setting('deposit_min_amount','10'),2);
                $max = isset($b['deposit_max_amount']) ? round((float)$b['deposit_max_amount'],2) : round((float)get_setting('deposit_max_amount','100000'),2);
                if(!is_finite($min)||!is_finite($max)||$min<=0||$max<=0) json_error('Deposit minimum and maximum must be positive numbers.',422);
                if($min>$max) json_error('Deposit minimum cannot be greater than maximum.',422);
                if($max>100000000) json_error('Deposit maximum is too large.',422);
                set_setting('deposit_min_amount',number_format($min,2,'.',''));
                set_setting('deposit_max_amount',number_format($max,2,'.',''));
                audit_log((int)$admin['id'],'deposit_limits',null,'Min ₹'.$min.' / Max ₹'.$max);
            }
            if(isset($b['dripstore_api_enabled'])) {
                set_setting('dripstore_api_enabled',$b['dripstore_api_enabled']==='on'?'on':'off');
                audit_log((int)$admin['id'],'dripstore_api_enabled',null,'DRIPSTORE API set to '.($b['dripstore_api_enabled']==='on'?'on':'off'));
            }
            if(isset($b['dripstore_api_url'])){
                $url=trim((string)$b['dripstore_api_url']);
                if($url!=='' && !dripstore_validate_base_url($url)) json_error('DRIPSTORE API URL must be a valid HTTPS base URL.',422);
                if($url!=='') set_setting('dripstore_api_url',rtrim($url,'/'));
            }
            if(isset($b['dripstore_api_token'])){
                $token=preg_replace('/\s+/', '', trim((string)$b['dripstore_api_token']));
                if(strlen($token)>512) json_error('DRIPSTORE API token is too long.',422);
                if($token!==''){
                    set_setting('dripstore_api_token',$token);
                    set_setting('dripstore_api_token_updated_at',date('Y-m-d H:i:s'));
                    audit_log((int)$admin['id'],'dripstore_api_token_save',null,'DRIPSTORE API token saved/replaced. Secret value was not logged.');
                }
            }
            if(isset($b['provider_api_enabled'])) set_setting('provider_api_enabled',$b['provider_api_enabled']==='on'?'on':'off');
            if(isset($b['provider_api_url'])){ $url=trim((string)$b['provider_api_url']); if($url!==''&&!filter_var($url,FILTER_VALIDATE_URL))json_error('Provider API URL is not a valid URL.',422); set_setting('provider_api_url',$url); }
            if(isset($b['provider_api_token'])){
                // A non-empty new key ALWAYS overwrites the previous saved key.
                // An empty field means "keep the current key" so endpoint-only saves
                // cannot accidentally erase a working credential.
                $token=preg_replace('/\s+/', '', trim((string)$b['provider_api_token']));
                if(strlen($token)>512) json_error('Provider API token is too long.',422);
                if($token!==''){
                    set_setting('provider_api_token',$token);
                    set_setting('provider_api_token_updated_at',date('Y-m-d H:i:s'));
                    audit_log((int)$admin['id'],'provider_api_master_key_save',null,'KEYSPANEL master key saved/replaced from Admin settings. Secret value was not logged.');
                }
            }
            if(isset($b['provider_api_proxy'])){
                // Same "empty means keep current value" pattern as the master key
                // above, since this field can also carry a credential (user:pass@).
                $proxy=trim((string)$b['provider_api_proxy']);
                if($proxy==='__CLEAR__'){
                    set_setting('provider_api_proxy','');
                    audit_log((int)$admin['id'],'keyspanel_proxy_cleared',null,'KEYSPANEL egress proxy cleared from Admin settings.');
                } elseif($proxy!==''){
                    $proxyScheme=strtolower((string)parse_url($proxy,PHP_URL_SCHEME));
                    if(!in_array($proxyScheme,['http','https','socks5','socks5h','socks4'],true)){
                        json_error('Proxy URL must start with http://, https://, socks5://, socks5h:// or socks4://.',422);
                    }
                    if(strlen($proxy)>255) json_error('Proxy URL is too long.',422);
                    set_setting('provider_api_proxy',$proxy);
                    audit_log((int)$admin['id'],'keyspanel_proxy_save',null,'KEYSPANEL egress proxy saved/replaced from Admin settings. Value was not logged.');
                }
            }
            if(isset($b['sound_enabled'])) set_setting('sound_enabled', $b['sound_enabled']==='on' ? 'on' : 'off');
            if(isset($b['paytm_enabled'])) set_setting('paytm_enabled',$b['paytm_enabled']==='on'?'on':'off');
            if(isset($b['paytm_mid'])) set_setting('paytm_mid',trim((string)$b['paytm_mid']));
            if(isset($b['paytm_upi_id'])) set_setting('paytm_upi_id',trim((string)$b['paytm_upi_id']));
            if(isset($b['paytm_display_name'])) set_setting('paytm_display_name',trim((string)$b['paytm_display_name'])?:'KEYS PANEL');
            if(isset($b['zapupi_enabled'])) set_setting('zapupi_enabled',$b['zapupi_enabled']==='on'?'on':'off');
            if(isset($b['zapupi_key']) && trim((string)$b['zapupi_key'])!==''){
                $zk=trim((string)$b['zapupi_key']);
                if(strlen($zk)>200||preg_match('/\s/',$zk)) json_error('Invalid ZAP KEY format.',422);
                set_setting('zapupi_key_enc',paytm_encrypt_secret($zk));
                audit_log((int)$admin['id'],'zapupi_key_save',null,'ZapUPI key saved/replaced. Value was not logged.');
            }
            json_ok(['message'=>'Settings saved.']);
        }

        case 'admin_dripstore_test': {
            require_method('POST'); require_admin(); require_valid_csrf();
            try {
                $result=dripstore_api_request('/balance.php','GET');
                json_ok([
                    'configured'=>true,
                    'connected'=>true,
                    'http_status'=>$result['http_status']??0,
                    'message'=>'DRIPSTORE connection verified using GET /balance.php. No purchase was sent.'
                ]);
            } catch(Throwable $e) {
                $diag=dripstore_exception_diagnostics($e);
                json_error($e->getMessage(),422,[
                    'configured'=>dripstore_configuration_status()['configured'],
                    'connected'=>false,
                    'http_status'=>(int)($diag['http_status']??0)
                ]);
            }
        }
        case 'admin_dripstore_products': {
            require_method('GET'); require_admin();
            try {
                json_ok(['products'=>dripstore_api_get_products()]);
            } catch(Throwable $e) {
                json_error($e->getMessage(),422);
            }
        }
        case 'admin_dripstore_reset_apis': {
            require_method('GET'); require_admin();
            try { json_ok(['reset_apis'=>dripstore_api_get_reset_apis()]); }
            catch(Throwable $e){ json_error($e->getMessage(),422); }
        }
        case 'admin_dripstore_reset_key': {
            require_method('POST'); require_admin(); require_valid_csrf(); $b=json_body();
            $api=trim((string)($b['api']??'')); $key=trim((string)($b['key']??''));
            if($key==='') json_error('Key is required.',422);
            try { json_ok(['result'=>dripstore_api_reset_key($api,$key)]); }
            catch(Throwable $e){ json_error($e->getMessage(),422); }
        }
        case 'admin_dripstore_history': {
            require_method('GET'); require_admin();
            try { json_ok(['history'=>dripstore_api_key_history((int)($_GET['limit']??20))]); }
            catch(Throwable $e){ json_error($e->getMessage(),422); }
        }

        case 'admin_provider_api_test': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf();
            // KEYSPANEL documents POST action=buy as the authenticated contract,
            // but does not document a separate balance/auth/status endpoint.
            // Therefore this diagnostic sends the SAME authenticated POST shape
            // through the real server, but with a deliberately impossible variant
            // id. It must never create a real purchase. A valid credential should
            // reach the supplier's variant validation; 401/403 means access/auth
            // was rejected before the variant can be validated.
            $savedUrl=preg_replace('/\s+/', '', trim((string)get_setting('provider_api_url','')));
            $savedToken=preg_replace('/\s+/', '', trim((string)get_setting('provider_api_token','')));
            $urlOk=$savedUrl!=='' && filter_var($savedUrl,FILTER_VALIDATE_URL)!==false;
            $parts=$urlOk?parse_url($savedUrl):[];
            $scheme=strtolower((string)($parts['scheme']??''));
            $urlOk=$urlOk && in_array($scheme,['http','https'],true);
            $tokenOk=$savedToken!=='';
            if(!$urlOk) json_error('KEYSPANEL endpoint is not saved correctly. Enter the endpoint and click SAVE KEYSPANEL SETTINGS first.',422);
            if(!$tokenOk) json_error('KEYSPANEL master key is not saved. Enter the new master key and click SAVE KEYSPANEL SETTINGS first.',422);
            if(!function_exists('curl_init')) json_error('PHP cURL extension is required for the KEYSPANEL connection test.',500);

            $testVariant='__KEYSPANEL_AUTH_TEST_INVALID_VARIANT__';
            $payload=http_build_query(['action'=>'buy','variant_id'=>$testVariant,'quantity'=>1],'','&',PHP_QUERY_RFC3986);
            $ch=curl_init($savedUrl);
            $started=microtime(true);
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>$payload,
                CURLOPT_HTTPHEADER=>[
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                    'X-Master-Key: '.$savedToken,
                ],
                CURLOPT_CONNECTTIMEOUT=>8,
                CURLOPT_TIMEOUT=>15,
                CURLOPT_FOLLOWLOCATION=>true,
                CURLOPT_MAXREDIRS=>3,
                CURLOPT_POSTREDIR=>CURL_REDIR_POST_ALL,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_USERAGENT=>'python-requests/2.32.3',
            ]);
            $testProxy=trim((string)get_setting('provider_api_proxy',''));
            $proxyUsed=keyspanel_apply_proxy($ch,$testProxy);
            $raw=curl_exec($ch);
            $curlErr=curl_error($ch);
            $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            $effectiveUrl=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
            $contentType=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            $latency=(int)round((microtime(true)-$started)*1000);

            if($raw===false || $curlErr!==''){
                audit_log((int)$admin['id'],'keyspanel_connection_test',null,'Authenticated POST diagnostic failed at transport level; secret value was not logged.');
                json_ok([
                    'connected'=>false,'configured'=>true,'reachable'=>false,'authenticated'=>null,
                    'http_status'=>$http,'latency_ms'=>$latency,'purchase_called'=>false,
                    'key_source'=>'saved database key','key_length'=>strlen($savedToken),'key_saved'=>true,
                    'test_variant'=>$testVariant,'curl_error'=>$curlErr,'response_preview'=>'',
                    'proxy_used'=>$proxyUsed,
                    'message'=>'KEYSPANEL could not be reached from this server. '.($curlErr!==''?'cURL: '.$curlErr.'. ':'').'No purchase was sent.'
                ]);
            }

            $body=(string)$raw;
            $decoded=json_decode($body,true);
            $preview=trim(preg_replace('/\s+/',' ',substr($body,0,240)));
            $authenticated=null;
            $message='KEYSPANEL returned a response, but the authentication result could not be determined safely.';

            $wafSig=null;
            if($http===401 || $http===403){
                $authenticated=false;
                $bodyStripped=ltrim($body);
                $isHtml=$bodyStripped!=='' && $bodyStripped[0]==='<';
                // A 401/403 with a PLAIN TEXT body (not HTML, no '<') is also
                // an edge/ACL block on some reverse proxies (e.g. HAProxy's
                // default deny page is plain text: "403 Forbidden\nRequest
                // forbidden by administrative rules."), so it's checked for a
                // WAF signature too, not just genuine HTML pages.
                $wafSig=keyspanel_detect_waf_signature($body);
                if($isHtml || $wafSig!==null){
                    $message='KEYSPANEL rejected the diagnostic (HTTP '.$http.') at an edge/proxy layer, not from its application. Detected: '.($wafSig??'unrecognized block page').'. This is almost always an IP-allowlist/ACL rule, NOT the master key. If the Telegram bot purchases successfully from a different server, that server\'s IP is what KEYSPANEL has allowlisted — this website\'s outbound IP is most likely not yet on that list (see Admin → Outbound IP, and the optional Egress Proxy setting). No purchase was sent.';
                } else {
                    $message='KEYSPANEL rejected the authenticated POST diagnostic (HTTP '.$http.') with a plain response body, not an application-level block page. This more likely means the master key itself is invalid/revoked. No purchase was sent.';
                }
            }elseif(is_array($decoded) && (($decoded['ok']??null)===false)) {
                $errText=strtolower(trim((string)($decoded['error']??'')));
                $variantError=str_contains($errText,'variant')||str_contains($errText,'product')||str_contains($errText,'not found')||str_contains($errText,'invalid');
                if($variantError){
                    $authenticated=true;
                    $message='KEYSPANEL accepted the saved master key and reached variant validation. The test variant was intentionally invalid, so no purchase was sent.';
                }else{
                    $message='KEYSPANEL responded with an application error, but it did not clearly identify variant validation. No purchase was sent.';
                }
            }elseif($http>=200 && $http<300){
                // A 2xx response to the impossible variant is not enough by itself
                // to prove a purchase; only a clear variant-validation response does.
                $message='KEYSPANEL returned HTTP '.$http.' for the diagnostic, but the response did not clearly prove authentication. No purchase was sent.';
            }else{
                $message='KEYSPANEL returned HTTP '.$http.' during the diagnostic. No purchase was sent.';
            }

            audit_log((int)$admin['id'],'keyspanel_connection_test',null,'Authenticated POST diagnostic completed; no purchase was sent; secret value was not logged. HTTP '.$http.'.');
            json_ok([
                'connected'=>($authenticated===true),
                'configured'=>true,
                'reachable'=>true,
                'authenticated'=>$authenticated,
                'http_status'=>$http,
                'latency_ms'=>$latency,
                'purchase_called'=>false,
                'key_source'=>'saved database key',
                'key_length'=>strlen($savedToken),
                'key_saved'=>true,
                'key_matches_saved'=>true,
                'test_variant'=>$testVariant,
                'content_type'=>$contentType,
                'effective_url'=>$effectiveUrl,
                'response_preview'=>$preview,
                'waf_signature'=>$wafSig,
                'proxy_used'=>$proxyUsed,
                'message'=>$message,
                'url'=>$savedUrl,
                'max_quantity_per_request'=>KEYSPANEL_MAX_QUANTITY_PER_REQUEST,
            ]);
        }

        case 'admin_outbound_ip': {
            // Admin-only: shows the public IP this server's outbound requests
            // actually use, so it can be given to KEYSPANEL support to add to
            // an allowlist. Never exposed to resellers/frontend JS elsewhere.
            require_method('GET'); require_admin();
            if(!function_exists('curl_init')) json_error('PHP cURL extension is required to detect the outbound IP.',500);
            $ch=curl_init('https://api.ipify.org?format=json');
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>6,
                CURLOPT_TIMEOUT=>10,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
            ]);
            $raw=curl_exec($ch);
            $err=curl_error($ch);
            $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            curl_close($ch);
            if($raw===false || $err!==''){
                json_error('Could not determine outbound IP from this server (cURL: '.($err!==''?$err:'unknown error').'). This does not affect KEYSPANEL purchases directly — it only means this diagnostic tool could not reach api.ipify.org.',502);
            }
            $data=json_decode((string)$raw,true);
            $ip=is_array($data) && isset($data['ip']) && is_scalar($data['ip']) ? (string)$data['ip'] : null;
            if($ip===null || $ip===''){
                json_error('api.ipify.org returned an unexpected response (HTTP '.$http.'). Could not determine outbound IP.',502);
            }
            json_ok(['outbound_ip'=>$ip,'note'=>'This is the public IP this server uses for outbound requests such as the KEYSPANEL purchase call. Give this exact IP to KEYSPANEL support if they require IP allowlisting.']);
        }

        /* ============================================================
           API REPORTS — orders placed via the reseller API, plus every
           logged API request (including failures that never became orders).
           ============================================================ */
        case 'admin_api_reports': {
            require_method('GET'); require_admin();
            $pdo=db();
            $page=max(1,(int)($_GET['page']??1)); $perPage=25; $offset=($page-1)*$perPage;
            $where=["1=1"]; $params=[];
            if(!empty($_GET['date_from'])){ $where[]="l.created_at >= ?"; $params[]=$_GET['date_from'].' 00:00:00'; }
            if(!empty($_GET['date_to'])){ $where[]="l.created_at <= ?"; $params[]=$_GET['date_to'].' 23:59:59'; }
            if(!empty($_GET['username'])){ $where[]="r.username LIKE ?"; $params[]='%'.$_GET['username'].'%'; }
            if(!empty($_GET['status'])){ $where[]="l.status = ?"; $params[]=$_GET['status']; }
            if(!empty($_GET['order_code'])){ $where[]="l.order_code LIKE ?"; $params[]='%'.$_GET['order_code'].'%'; }
            if(!empty($_GET['plan_id'])){ $where[]="l.plan_id = ?"; $params[]=(int)$_GET['plan_id']; }
            $whereSql = implode(' AND ',$where);
            $countStmt=$pdo->prepare("SELECT COUNT(*) FROM api_logs l LEFT JOIN resellers r ON r.id=l.reseller_id WHERE $whereSql");
            $countStmt->execute($params); $total=(int)$countStmt->fetchColumn();
            $stmt=$pdo->prepare(
                "SELECT l.id,l.action,l.status,l.http_status,l.message,l.quantity,l.order_id,l.order_code,l.created_at,
                        r.username, o.total_price, o.unit_price, o.product_name, o.duration_label
                 FROM api_logs l
                 LEFT JOIN resellers r ON r.id = l.reseller_id
                 LEFT JOIN orders o ON o.id = l.order_id
                 WHERE $whereSql ORDER BY l.id DESC LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute($params);
            $rows=$stmt->fetchAll();

            $sumStmt=$pdo->prepare(
                "SELECT
                    COUNT(*) AS total_requests,
                    SUM(CASE WHEN l.status='success' AND l.order_id IS NOT NULL THEN 1 ELSE 0 END) AS successful_orders,
                    SUM(CASE WHEN l.status='failed' THEN 1 ELSE 0 END) AS failed_orders,
                    SUM(CASE WHEN l.status='failed' AND l.action='v1_order' THEN 1 ELSE 0 END) AS api_errors
                 FROM api_logs l WHERE $whereSql"
            );
            $sumStmt->execute($params); $summary=$sumStmt->fetch();
            $revStmt=$pdo->prepare("SELECT COALESCE(SUM(o.total_price),0) FROM orders o WHERE o.source='api' AND o.status='success'");
            $revStmt->execute();
            $refundStmt=$pdo->prepare("SELECT COALESCE(SUM(wt.amount),0) FROM wallet_transactions wt WHERE wt.type='refund'");
            $refundStmt->execute();
            json_ok([
                'rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$perPage,
                'summary'=>[
                    'total_requests'=>(int)($summary['total_requests']??0),
                    'successful_orders'=>(int)($summary['successful_orders']??0),
                    'failed_orders'=>(int)($summary['failed_orders']??0),
                    'api_errors'=>(int)($summary['api_errors']??0),
                    'revenue'=>(float)$revStmt->fetchColumn(),
                    'refunds'=>(float)$refundStmt->fetchColumn(),
                ],
            ]);
        }
        case 'admin_api_report_detail': {
            require_method('GET'); require_admin(); $id=(int)($_GET['id']??0); if($id<=0) json_error('Invalid record.',422);
            $stmt=db()->prepare(
                "SELECT l.*, r.username, o.total_price, o.unit_price, o.license_key, o.status AS order_status
                 FROM api_logs l LEFT JOIN resellers r ON r.id=l.reseller_id LEFT JOIN orders o ON o.id=l.order_id WHERE l.id=?"
            );
            $stmt->execute([$id]); $row=$stmt->fetch(); if(!$row) json_error('Record not found.',404);
            json_ok(['record'=>$row]);
        }

        case 'admin_orders': {
            require_method('GET'); require_admin();
            $page=max(1,(int)($_GET['page']??1)); $perPage=30; $offset=($page-1)*$perPage;
            $pdo=db();
            $total=(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
            $stmt=$pdo->prepare(
                "SELECT o.id,o.order_code,o.product_name,o.duration_label,o.quantity,o.total_price,o.status,o.source,o.created_at,o.provider_diagnostics,r.username
                 FROM orders o JOIN resellers r ON r.id=o.reseller_id ORDER BY o.id DESC LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute();
            json_ok(['orders'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'per_page'=>$perPage]);
        }

        case 'admin_logs': {
            require_method('GET'); require_admin();
            $page=max(1,(int)($_GET['page']??1)); $perPage=50; $offset=($page-1)*$perPage;
            $pdo=db();
            $total=(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
            $stmt=$pdo->prepare('SELECT al.id,al.action,al.target,al.details,al.ip_address,al.created_at,a.username AS admin_username FROM audit_logs al LEFT JOIN admins a ON a.id=al.admin_id ORDER BY al.id DESC LIMIT '.$perPage.' OFFSET '.$offset);
            $stmt->execute();
            json_ok(['logs'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'per_page'=>$perPage]);
        }

        /* ============================================================
           RESELLER-FACING: own API key management (web session)
           ============================================================ */
        case 'api_key_generate': {
            require_method('POST'); $reseller=require_reseller_api_allowed(); require_valid_csrf();
            $result = generate_api_key_for_reseller((int)$reseller['id']);
            json_ok(['api_key'=>$result['key'],'prefix'=>$result['prefix'],'message'=>'New API key generated. Save it now — it will not be shown again.']);
        }
        case 'api_key_info': {
            require_method('GET'); $reseller=require_reseller_api_allowed();
            $stmt=db()->prepare("SELECT key_prefix,status,created_at FROM api_keys WHERE reseller_id=? AND status='active' ORDER BY id DESC LIMIT 1");
            $stmt->execute([(int)$reseller['id']]);
            json_ok(['api_key'=>$stmt->fetch() ?: null]);
        }

        /* ============================================================
           EXTERNAL RESELLER API (v1) — real, database-backed, authenticated
           by X-API-Key header (not the web session/CSRF flow above).
           ============================================================ */
        case 'api_catalog_preview': {
            require_method('GET'); $reseller=require_reseller(); $resellerId=(int)$reseller['id'];
            $pdo=db();
            $products=$pdo->query("SELECT id,category_id,name,maintenance FROM products WHERE status='active' ORDER BY name ASC")->fetchAll();
            $plansStmt=$pdo->prepare("SELECT id,duration_label,status,stock_mode,provider_type,provider_product_id,dripstore_variant_id FROM plans WHERE product_id=? AND status='active' ORDER BY id ASC");
            foreach($products as &$p){
                $maintenance=($p['maintenance']==='on');
                $plansStmt->execute([(int)$p['id']]); $plans=$plansStmt->fetchAll();
                foreach($plans as &$pl){
                    $durationId=(int)$pl['id'];
                    $priced=get_effective_price($resellerId,$durationId);
                    $pl['id']=$durationId;
                    $pl['duration_id']=$durationId;
                    $pl['api_product_id']=$durationId;
                    $pl['parent_product_id']=(int)$p['id'];
                    $pl['price']=$priced ? (float)$priced['price'] : 0;
                    $pl['stock']=$maintenance ? null : ((($pl['provider_type']??(($pl['stock_mode']??'local')==='api'?'keyspanel':'local'))!=='local') ? 99 : get_plan_stock($durationId));
                    $pl['status']=$maintenance ? 'maintenance' : 'available';
                }
                unset($pl);
                $p['id']=(int)$p['id']; $p['maintenance']=$maintenance; $p['plans']=$plans;
            }
            unset($p);
            json_ok(['products'=>$products]);
        }
        case 'v1_balance': {
            require_method('GET'); $reseller=authenticate_api_key();
            api_log((int)$reseller['id'],(int)$reseller['api_key_id'],'v1_balance');
            json_ok(['balance'=>get_wallet_balance((int)$reseller['id']),'username'=>$reseller['username']]);
        }
        case 'v1_products': {
            require_method('GET'); $reseller=authenticate_api_key(); $resellerId=(int)$reseller['id'];
            $pdo=db();
            $products=$pdo->query("SELECT id,category_id,name,maintenance FROM products WHERE status='active' ORDER BY name ASC")->fetchAll();
            $plansStmt=$pdo->prepare("SELECT id,duration_label,stock_mode,provider_type FROM plans WHERE product_id=? AND status='active' ORDER BY id ASC");
            foreach($products as &$p){
                $inMaintenance = $p['maintenance']==='on';
                $plansStmt->execute([$p['id']]); $plans=$plansStmt->fetchAll();
                foreach($plans as &$pl){
                    $priced=get_effective_price($resellerId,(int)$pl['id']);
                    $pl['id']=(int)$pl['id'];
                    // Explicit aliases make the relationship unambiguous for external API users:
                    // product.id is only the parent/group ID. The exact orderable API product ID is plan.id/duration_id.
                    $pl['duration_id']=(int)$pl['id'];
                    $pl['api_product_id']=(int)$pl['id'];
                    $pl['parent_product_id']=(int)$p['id'];
                    $pl['price']=$priced['price'];
                    $pl['stock']=$inMaintenance?null:((($pl['provider_type']??(($pl['stock_mode']??'local')==='api'?'keyspanel':'local'))!=='local') ? 99 : get_plan_stock((int)$pl['id']));
                    $pl['status']=$inMaintenance?'maintenance':'available';
                }
                unset($pl);
                $p['id']=(int)$p['id']; $p['maintenance']=$inMaintenance; $p['plans']=$plans;
            }
            unset($p);
            api_log($resellerId,(int)$reseller['api_key_id'],'v1_products');
            json_ok(['products'=>$products]);
        }
        case 'v1_order': {
            require_method('POST'); $reseller=authenticate_api_key(); $resellerId=(int)$reseller['id'];
            // Supports both the existing JSON v1 contract and the PHP documentation
            // format: action=buy, variant_id, quantity as form-urlencoded data.
            $contentType=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
            $isFormRequest=str_contains($contentType,'application/x-www-form-urlencoded') || isset($_POST['action']) || isset($_POST['variant_id']);
            $body=$isFormRequest ? $_POST : json_body();
            $isBuyFormat=((string)($body['action']??''))==='buy' || array_key_exists('variant_id',$body);
            // variant_id is the documented alias for the exact orderable duration/plan ID.
            $planId=(int)($body['duration_id'] ?? $body['variant_id'] ?? $body['api_product_id'] ?? $body['plan_id'] ?? $body['variation_id'] ?? 0);
            $requestedProductId=(int)($body['product_id']??0);
            $quantity=max(1,(int)($body['quantity']??1));
            if($isBuyFormat) $quantity=min($quantity,10);
            $idempotencyKey=isset($body['idempotency_key'])?substr((string)$body['idempotency_key'],0,64):null;
            if($planId<=0){
                api_log($resellerId,(int)$reseller['api_key_id'],'v1_order',['plan_id'=>$planId,'quantity'=>$quantity],'failed',422,'Invalid variation_id/plan_id.');
                json_error('A valid duration_id (API product ID) is required.',422);
            }
            try{
                if($requestedProductId>0){
                    $check=db()->prepare('SELECT product_id FROM plans WHERE id=?');
                    $check->execute([$planId]);
                    $actualProductId=(int)$check->fetchColumn();
                    if($actualProductId<=0 || $actualProductId!==$requestedProductId){
                        api_log($resellerId,(int)$reseller['api_key_id'],'v1_order',['product_id'=>$requestedProductId,'plan_id'=>$planId,'quantity'=>$quantity],'failed',422,'The product_id does not match the supplied duration/plan ID.');
                        json_error('product_id does not match the supplied duration/plan ID.',422);
                    }
                }
                $result = execute_purchase($resellerId,$planId,$quantity,null,'api',$idempotencyKey);
            }catch(RuntimeException $e){
                $status = strpos($e->getMessage(),'Insufficient wallet')!==false ? 402
                    : ((stripos($e->getMessage(),'stock')!==false) ? 409 : 422);
                api_log($resellerId,(int)$reseller['api_key_id'],'v1_order',['plan_id'=>$planId,'quantity'=>$quantity],'failed',$status,$e->getMessage());
                json_error($e->getMessage(),$status);
            }
            $orderRow=db()->prepare('SELECT id FROM orders WHERE order_code=?'); $orderRow->execute([$result['order_code']]);
            $orderId=(int)$orderRow->fetchColumn();
            db()->prepare('UPDATE orders SET api_key_id=? WHERE id=?')->execute([(int)$reseller['api_key_id'],$orderId]);
            api_log($resellerId,(int)$reseller['api_key_id'],'v1_order',['plan_id'=>$planId,'quantity'=>$quantity,'order_id'=>$orderId,'order_code'=>$result['order_code']],'success',200);
            $pidStmt=db()->prepare('SELECT product_id FROM plans WHERE id=?');
            $pidStmt->execute([$planId]);
            $result['product_id']=(int)$pidStmt->fetchColumn();
            $result['plan_id']=$planId;
            $result['duration_id']=$planId;
            if($isBuyFormat){
                $keys=array_values(array_filter(array_map('trim',preg_split('/\R+/',(string)($result['license_key']??'')))));
                json_ok([
                    'ok'=>true,
                    'key'=>$keys[0]??'',
                    'keys'=>$keys,
                    'order_id'=>$result['order_code']??'',
                    'expires_at'=>$result['expires_at']??'',
                    'product_id'=>$result['product_id'],
                    'variant_id'=>$planId,
                    'quantity'=>$quantity,
                    'balance_left'=>$result['remaining_balance']??null,
                    'order'=>$result,
                ]);
            }
            json_ok(['order'=>$result]);
        }
        case 'v1_order_status': {
            require_method('GET'); $reseller=authenticate_api_key();
            $code=trim((string)($_GET['order_code']??'')); if($code==='') json_error('order_code is required.',422);
            $stmt=db()->prepare('SELECT order_code,status,product_name,duration_label,quantity,total_price,license_key,created_at FROM orders WHERE reseller_id=? AND order_code=?');
            $stmt->execute([(int)$reseller['id'],$code]); $order=$stmt->fetch();
            if(!$order){ api_log((int)$reseller['id'],(int)$reseller['api_key_id'],'v1_order_status',[],'failed',404,'Order not found.'); json_error('Order not found.',404); }
            api_log((int)$reseller['id'],(int)$reseller['api_key_id'],'v1_order_status',['order_code'=>$code],'success',200);
            json_ok(['order'=>$order]);
        }

        case 'admin_add_key': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $b=json_body(); $pid=(int)($b['plan_id']??0); $key=trim((string)($b['key_value']??''));
            if($pid<=0||$key==='') json_error('Plan and key are required.',422);
            $check=db()->prepare('SELECT id FROM license_keys WHERE plan_id=? AND key_value=? LIMIT 1'); $check->execute([$pid,$key]);
            if($check->fetchColumn()) json_error('This key already exists in this duration.',409);
            db()->prepare('INSERT INTO license_keys(plan_id,key_value,status) VALUES(?,?,?)')->execute([$pid,$key,'available']);
            json_ok(['message'=>'License key added to stock.']);
        }
        case 'admin_delete_key': {
            require_method('POST'); $admin=require_admin(); require_valid_csrf(); $id=(int)(json_body()['id']??0); if($id<=0) json_error('Invalid key.',422);
            db()->prepare("DELETE FROM license_keys WHERE id=? AND status='available'")->execute([$id]); json_ok(['message'=>'Available key removed.']);
        }

        case 'deposit_create_paytm': {
            require_method('POST');
            $reseller = require_reseller();
            require_valid_csrf();
            $b = json_body();
            $amount = round((float)($b['amount'] ?? 0), 2);
            $depositMin = round((float)get_setting('deposit_min_amount','10'), 2);
            $depositMax = round((float)get_setting('deposit_max_amount','100000'), 2);
            if ($depositMin <= 0) $depositMin = 10;
            if ($depositMax < $depositMin) $depositMax = max($depositMin, 100000);
            if ($amount < $depositMin || $amount > $depositMax) json_error('Deposit amount must be between ₹'.number_format($depositMin,2).' and ₹'.number_format($depositMax,2).'.', 422);
            if (is_global_maintenance()) json_error('Deposits are temporarily disabled during maintenance.', 503);
            $cfg = paytm_config();
            if (!$cfg['enabled']) json_error('Paytm payment is not enabled by admin.', 503);
            $orderId = date('YmdHis') . random_int(10000, 99999); // Match the bot: 19-digit numeric Paytm order ID.
            $pdo = db();
            $pdo->prepare("INSERT INTO deposits(reseller_id,amount,method,reference,status,gateway_order_id,gateway_status) VALUES(?,?,?,?,?,?,?)")
                ->execute([(int)$reseller['id'], $amount, 'paytm_qr', $orderId, 'pending', $orderId, 'INITIATED']);
            $depositId = (int)$pdo->lastInsertId();
            try {
                $qr = paytm_create_dynamic_qr($orderId, $amount);
                $meta = json_encode(['qr_id' => $qr['qr_id'], 'created_at' => date('c')], JSON_UNESCAPED_SLASHES);
                $pdo->prepare('UPDATE deposits SET gateway_status=?,gateway_response=? WHERE id=? AND reseller_id=?')
                    ->execute(['QR_CREATED', $meta, $depositId, (int)$reseller['id']]);
                json_ok([
                    'deposit_id' => $depositId,
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'qr_image' => $qr['image'],
                    'qr_data' => $qr['qr_data'],
                    'qr_id' => $qr['qr_id'],
                    'mode' => $qr['mode'] ?? 'dynamic',
                    'expires_in' => ($qr['mode'] ?? 'dynamic') === 'upi_auto' ? 180 : 600,
                ]);
            } catch (Throwable $e) {
                $pdo->prepare('UPDATE deposits SET status=?,gateway_status=?,gateway_response=? WHERE id=? AND reseller_id=?')
                    ->execute(['rejected', 'CREATE_FAILED', substr($e->getMessage(), 0, 500), $depositId, (int)$reseller['id']]);
                json_error($e->getMessage(), 502);
            }
        }
        case 'deposit_status_paytm': {
            require_method('GET');
            $reseller = require_reseller();
            $id = (int)($_GET['deposit_id'] ?? 0);
            if ($id <= 0) json_error('Invalid deposit.', 422);
            $pdo = db();
            $st = $pdo->prepare('SELECT * FROM deposits WHERE id=? AND reseller_id=? LIMIT 1');
            $st->execute([$id, (int)$reseller['id']]);
            $dep = $st->fetch();
            if (!$dep) json_error('Deposit not found.', 404);
            if (($dep['status'] ?? '') === 'approved') {
                json_ok(['deposit_id'=>$id, 'status'=>'SUCCESS', 'balance'=>get_wallet_balance((int)$reseller['id'])]);
            }
            if (($dep['status'] ?? '') !== 'pending' || empty($dep['gateway_order_id'])) {
                json_ok(['deposit_id'=>$id, 'status'=>strtoupper((string)($dep['status'] ?? 'PENDING'))]);
            }
            // Match the bot's 3-minute pending-payment lifetime on the server too.
            $createdTs = strtotime((string)($dep['created_at'] ?? ''));
            if ($createdTs !== false && (time() - $createdTs) > 180) {
                $pdo->prepare('UPDATE deposits SET status=?,gateway_status=? WHERE id=? AND reseller_id=? AND status=?')
                    ->execute(['rejected','EXPIRED',$id,(int)$reseller['id'],'pending']);
                json_ok(['deposit_id'=>$id,'status'=>'FAILURE','message'=>'Payment window expired. Please create a new payment.']);
            }
            try {
                $res = paytm_get_qr_status((string)$dep['gateway_order_id']);
            } catch (Throwable $e) {
                json_error('Paytm status check failed. Please retry shortly.', 502);
            }
            $body = $res['body'] ?? [];
            if (!is_array($body)) $body = [];

            // Paytm's merchant-status endpoint used by the working bot returns
            // STATUS/TXNAMOUNT/TXNID at the top level. Be tolerant of wrappers too,
            // because some Paytm responses/proxies may nest the same fields.
            $candidateBodies = [$body];
            foreach (['body','data','result','response'] as $k) {
                if (isset($body[$k]) && is_array($body[$k])) $candidateBodies[] = $body[$k];
            }
            $pick = function(array $names, $default = null) use ($candidateBodies) {
                foreach ($candidateBodies as $cb) {
                    foreach ($names as $name) {
                        if (array_key_exists($name, $cb) && $cb[$name] !== null && $cb[$name] !== '') return $cb[$name];
                    }
                }
                return $default;
            };
            $status = strtoupper(trim((string)$pick(['STATUS','status','resultStatus'], 'PENDING')));
            $txnAmount = (float)$pick(['TXNAMOUNT','txnAmount','TXN_AMOUNT'], 0);
            $txnId = trim((string)$pick(['TXNID','txnId','TXN_ID'], ''));
            $resultMsg = (string)$pick(['RESPMSG','respMsg','resultMsg','message'], 'Payment status pending.');

            // Only a confirmed Paytm success can ever reach the wallet-credit block.
            // All other states remain pending so a temporary/early response cannot
            // incorrectly reject a payment that is still being reconciled.
            if ($status === 'TXN_SUCCESS') {
                // Match the working bot: success is determined by Paytm's TXN_SUCCESS
                // response plus an exact amount check. Some Paytm/UPI responses can
                // omit TXNID, so use the unique Paytm order ID as the internal fallback
                // instead of leaving a genuinely successful payment stuck on PENDING.
                if (abs($txnAmount - (float)$dep['amount']) > 0.009) json_error('Payment amount mismatch. Wallet was not credited.', 409);
                $creditTxnId = $txnId !== '' ? $txnId : ('ORDER:' . (string)$dep['gateway_order_id']);
                $dup = $pdo->prepare("SELECT id FROM deposits WHERE gateway_txn_id=? AND status='approved' AND id<>? LIMIT 1");
                $dup->execute([$creditTxnId,$id]);
                if ($dup->fetchColumn()) json_error('This Paytm transaction has already been credited.', 409);
                $pdo->beginTransaction();
                try {
                    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                    $lockSql = 'SELECT status FROM deposits WHERE id=? AND reseller_id=?' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
                    $lock = $pdo->prepare($lockSql);
                    $lock->execute([$id, (int)$reseller['id']]);
                    if ($lock->fetchColumn() === 'approved') {
                        $pdo->commit();
                        json_ok(['deposit_id'=>$id, 'status'=>'SUCCESS', 'balance'=>get_wallet_balance((int)$reseller['id'])]);
                    }
                    $walletSql = 'SELECT balance FROM wallets WHERE reseller_id=?' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
                    $w = $pdo->prepare($walletSql);
                    $w->execute([(int)$reseller['id']]);
                    $wr = $w->fetch();
                    $old = $wr ? (float)$wr['balance'] : 0.0;
                    $new = round($old + (float)$dep['amount'], 2);
                    if ($wr) {
                        $pdo->prepare('UPDATE wallets SET balance=? WHERE reseller_id=?')->execute([$new, (int)$reseller['id']]);
                    } else {
                        $pdo->prepare('INSERT INTO wallets(reseller_id,balance) VALUES(?,?)')->execute([(int)$reseller['id'], $new]);
                    }
                    $pdo->prepare('INSERT INTO wallet_transactions(reseller_id,type,amount,balance_after,note) VALUES(?,?,?,?,?)')
                        ->execute([(int)$reseller['id'], 'credit', (float)$dep['amount'], $new, 'Paytm deposit '.$dep['gateway_order_id']]);
                    $pdo->prepare('UPDATE deposits SET status=?,gateway_status=?,gateway_txn_id=?,gateway_response=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=? AND reseller_id=?')
                        ->execute(['approved', 'TXN_SUCCESS', $creditTxnId, json_encode($res, JSON_UNESCAPED_SLASHES), $id, (int)$reseller['id']]);
                    $pdo->commit();
                    json_ok(['deposit_id'=>$id, 'status'=>'SUCCESS', 'balance'=>$new, 'txn_id'=>$creditTxnId]);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    json_error('Could not credit wallet safely.', 500);
                }
            }
            // Do not permanently reject a UPI QR deposit merely because the first
            // status response says TXN_FAILURE/Invalid Order Id. The supplied bot
            // keeps polling for the complete 3-minute window, so the website does too.
            // The deposit is only expired by the server-side 180-second window above.
            if ($status === 'TXN_FAILURE') {
                $pdo->prepare('UPDATE deposits SET gateway_status=?,gateway_txn_id=?,gateway_response=? WHERE id=? AND reseller_id=?')
                    ->execute(['TXN_FAILURE_PENDING', $txnId, json_encode($res, JSON_UNESCAPED_SLASHES), $id, (int)$reseller['id']]);
                json_ok(['deposit_id'=>$id, 'status'=>'PENDING', 'message'=>$resultMsg ?: 'Payment status pending.']);
            }
            $pdo->prepare('UPDATE deposits SET gateway_status=?,gateway_txn_id=?,gateway_response=? WHERE id=? AND reseller_id=?')
                ->execute([$status, $txnId, json_encode($res, JSON_UNESCAPED_SLASHES), $id, (int)$reseller['id']]);
            json_ok(['deposit_id'=>$id, 'status'=>'PENDING']);
        }
        case 'deposit_create_zapupi': {
            require_method('POST');
            $reseller = require_reseller();
            require_valid_csrf();
            $b = json_body();
            $amount = round((float)($b['amount'] ?? 0), 2);
            $depositMin = round((float)get_setting('deposit_min_amount','10'), 2);
            $depositMax = round((float)get_setting('deposit_max_amount','100000'), 2);
            if ($depositMin <= 0) $depositMin = 10;
            if ($depositMax < $depositMin) $depositMax = max($depositMin, 100000);
            if ($amount < $depositMin || $amount > $depositMax) json_error('Deposit amount must be between ₹'.number_format($depositMin,2).' and ₹'.number_format($depositMax,2).'.', 422);
            if (is_global_maintenance()) json_error('Deposits are temporarily disabled during maintenance.', 503);
            $cfg = zapupi_config();
            if (!$cfg['enabled'] || $cfg['key'] === '') json_error('ZapUPI payment is not enabled by admin.', 503);

            $orderId = 'ZAP' . date('YmdHis') . random_int(10000, 99999); // unique per payment
            $pdo = db();
            $pdo->prepare("INSERT INTO deposits(reseller_id,amount,method,reference,status,gateway_order_id,gateway_status) VALUES(?,?,?,?,?,?,?)")
                ->execute([(int)$reseller['id'], $amount, 'zapupi', $orderId, 'pending', $orderId, 'INITIATED']);
            $depositId = (int)$pdo->lastInsertId();
            $base = zapupi_app_base_url();
            try {
                $res = zapupi_post('create-order', [
                    'zap_key'     => $cfg['key'],
                    'order_id'    => $orderId,
                    'amount'      => number_format($amount, 2, '.', ''),
                    'remark'      => 'Wallet deposit | R' . (int)$reseller['id'],
                    'webhook_url' => $base . '?api=zapupi_webhook',
                ]);
                $payUrl = (string)($res['payment_url'] ?? '');
                if (($res['status'] ?? '') !== 'success' || $payUrl === '' || stripos($payUrl, 'https://') !== 0) {
                    throw new RuntimeException((string)($res['message'] ?? 'ZapUPI could not create the order.'));
                }
                $pdo->prepare('UPDATE deposits SET gateway_status=? WHERE id=? AND reseller_id=?')
                    ->execute(['ORDER_CREATED', $depositId, (int)$reseller['id']]);
                json_ok(['deposit_id'=>$depositId, 'order_id'=>$orderId, 'amount'=>$amount, 'payment_url'=>$payUrl]);
            } catch (Throwable $e) {
                $pdo->prepare("UPDATE deposits SET status='rejected',gateway_status='CREATE_FAILED',gateway_response=? WHERE id=? AND reseller_id=?")
                    ->execute([substr($e->getMessage(), 0, 500), $depositId, (int)$reseller['id']]);
                error_log('ZapUPI create-order failed: '.$e->getMessage());
                json_error('Could not start ZapUPI payment. Please try again.', 502);
            }
        }
        case 'deposit_status_zapupi': {
            require_method('GET');
            $reseller = require_reseller();
            $id = (int)($_GET['deposit_id'] ?? 0);
            if ($id <= 0) json_error('Invalid deposit.', 422);
            $st = db()->prepare("SELECT id,status FROM deposits WHERE id=? AND reseller_id=? AND method='zapupi' LIMIT 1");
            $st->execute([$id, (int)$reseller['id']]);
            $dep = $st->fetch();
            if (!$dep) json_error('Deposit not found.', 404);
            $result = ($dep['status'] === 'pending') ? zapupi_settle_deposit($id) : zapupi_settle_deposit_result($id);
            json_ok(['deposit_id'=>$id, 'status'=>$result, 'balance'=>get_wallet_balance((int)$reseller['id'])]);
        }
        case 'zapupi_webhook': {
            // Public endpoint (ZapUPI server calls it) — so NOTHING in the body is trusted.
            // We only use order_id from it, then re-verify with the order-status API.
            $raw  = file_get_contents('php://input');
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) $data = $_POST;
            $orderId = trim((string)($data['order_id'] ?? ''));
            if ($orderId !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $orderId)) {
                try {
                    $q = db()->prepare("SELECT id FROM deposits WHERE gateway_order_id=? AND method='zapupi' LIMIT 1");
                    $q->execute([$orderId]);
                    $depId = (int)$q->fetchColumn();
                    if ($depId > 0) zapupi_settle_deposit($depId);
                } catch (Throwable $e) { error_log('ZapUPI webhook error: '.$e->getMessage()); }
            }
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status'=>'ok']);
            exit;
        }
        case 'zapupi_available': {
            require_method('GET');
            require_reseller();
            $c = zapupi_config();
            json_ok(['zapupi' => ($c['enabled'] && $c['key'] !== ''), 'paytm' => paytm_config()['enabled']]);
        }
        case 'my_deposits': {
            require_method('GET');
            $reseller = require_reseller();
            $st = db()->prepare('SELECT id,amount,method,reference,status,gateway_status,gateway_txn_id,created_at,reviewed_at FROM deposits WHERE reseller_id=? ORDER BY id DESC LIMIT 20');
            $st->execute([(int)$reseller['id']]);
            json_ok(['deposits'=>$st->fetchAll()]);
        }


        case 'catalog': {
            require_method('GET');
            $reseller = require_reseller();
            $resellerId = (int) $reseller['id'];
            $pdo = db();

            $categories = $pdo->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY sort_order ASC, name ASC")->fetchAll();
            $products = $pdo->query("SELECT id, category_id, name, description, maintenance FROM products WHERE status = 'active' ORDER BY name ASC")->fetchAll();
            $plansStmt = $pdo->prepare("SELECT id, duration_label, stock_mode, provider_type FROM plans WHERE product_id = ? AND status = 'active' ORDER BY default_price ASC");

            foreach ($products as &$product) {
                $inMaintenance = $product['maintenance'] === 'on';
                $plansStmt->execute([$product['id']]);
                $plans = $plansStmt->fetchAll();
                foreach ($plans as &$plan) {
                    $priced = get_effective_price($resellerId, (int) $plan['id']);
                    $plan['id'] = (int) $plan['id'];
                    $plan['price'] = $priced['price'];
                    $plan['price_source'] = $priced['source'];
                    // Maintenance products never expose stock numbers to the store —
                    // the frontend must show "UNDER MAINTENANCE" instead, never a count.
                    $plan['stock'] = $inMaintenance ? null : ((($plan['provider_type']??(($plan['stock_mode']??'local')==='api'?'keyspanel':'local'))!=='local') ? 99 : get_plan_stock((int) $plan['id']));
                    $plan['stock_visible'] = false;
                }
                unset($plan);
                $product['id'] = (int) $product['id'];
                $product['category_id'] = (int) $product['category_id'];
                $product['maintenance'] = $inMaintenance;
                $product['plans'] = $plans;
            }
            unset($product);

            json_ok([
                'categories' => $categories, 'products' => $products,
                'global_maintenance' => is_global_maintenance(),
                'global_maintenance_message' => get_setting('global_maintenance_message'),
                'support_url' => get_setting('support_url'),
            ]);
        }

        case 'buy': {
            require_method('POST');
            $reseller = require_reseller();
            $body = json_body();
            $planId = (int) ($body['plan_id'] ?? 0);
            $quantity = max(1, (int) ($body['quantity'] ?? 1));
            $idempotencyKey = isset($body['idempotency_key']) ? substr((string) $body['idempotency_key'], 0, 64) : null;

            if ($planId <= 0) {
                json_error('Invalid plan.', 422);
            }
            try {
                $result = execute_purchase((int) $reseller['id'], $planId, $quantity, null, 'web', $idempotencyKey);
            } catch (RuntimeException $e) {
                $status = strpos($e->getMessage(), 'Insufficient wallet') !== false ? 402
                    : ((strpos($e->getMessage(), 'stock') !== false || strpos($e->getMessage(), 'Stock') !== false) ? 409 : 422);
                json_error($e->getMessage(), $status);
            }
            json_ok(['order' => $result]);
        }

        case 'top_resellers': {
            require_method('GET');
            require_reseller();
            // Live monthly leaderboard: only successful purchases count.
            // The ranking is calculated from the real orders table, never localStorage/demo data.
            $monthStart = date('Y-m-01 00:00:00');
            $stmt = db()->prepare(
                "SELECT r.id, r.username,
                        COALESCE(SUM(o.quantity),0) AS keys,
                        COALESCE(SUM(o.total_price),0) AS amount
                 FROM orders o
                 JOIN resellers r ON r.id = o.reseller_id
                 WHERE o.status = 'success' AND o.created_at >= ?
                 GROUP BY r.id, r.username
                 ORDER BY amount DESC, keys DESC, r.id ASC
                 LIMIT 5"
            );
            $stmt->execute([$monthStart]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $row['id'] = (int)$row['id'];
                $row['keys'] = (int)$row['keys'];
                $row['amount'] = (float)$row['amount'];
            }
            unset($row);
            json_ok(['resellers'=>$rows]);
        }

        case 'my_orders': {
            require_method('GET');
            $reseller = require_reseller();
            $stmt = db()->prepare(
                "SELECT o.order_code, o.plan_id AS duration_id, o.product_name, o.duration_label,
                        o.quantity, o.unit_price, o.total_price, o.price_source, o.license_key,
                        o.status, o.source, o.created_at
                 FROM orders o WHERE o.reseller_id = ? ORDER BY o.id DESC LIMIT 100"
            );
            $stmt->execute([$reseller['id']]);
            $orders = $stmt->fetchAll();
            foreach ($orders as &$o) {
                $o['quantity'] = (int) $o['quantity'];
                $o['unit_price'] = (float) $o['unit_price'];
                $o['total_price'] = (float) $o['total_price'];
            }
            unset($o);
            json_ok(['orders' => $orders]);
        }

        default:
            json_error('Unknown API action.', 404);
    }
    exit; // safety net — every case above already exits via json_ok()/json_error()
}

/* Admin authentication uses the same public login screen; no separate admin login page is exposed. */

$panelName = get_setting('panel_name', 'KEYS PANEL');
$panelLogo = get_setting('panel_logo', '');
$adminName = get_setting('admin_name', 'KEYS PANEL ADMIN');
$adminLogo = get_setting('admin_logo', '');
$loginName = get_setting('login_name', 'FLASH STAR');
$loginLogo = get_setting('login_logo', '');
$splashName = get_setting('splash_name', 'FLASH STAR');
$splashLogo = get_setting('splash_logo', '');
$splashKicker = get_setting('splash_kicker', '🔐 SECURE ACCESS POINT');
$splashWelcome = get_setting('splash_welcome', 'WELCOME TO');
$splashPortal = get_setting('splash_portal', 'RESELLER PORTAL');
$splashSubtitle = get_setting('splash_subtitle', 'Setting things up, taking you to sign in...');
$splashLoadingText = get_setting('splash_loading_text', 'REDIRECTING AUTOMATICALLY');
$loginSubtitle = get_setting('login_subtitle', '🔐 Ressler Portal — sign in to continue');
$loginBadge = get_setting('login_badge', 'RESSLER PORTAL SIGN IN');
$loginUsernameLabel = get_setting('login_username_label', 'Username');
$loginUsernamePlaceholder = get_setting('login_username_placeholder', 'Your username');
$loginPasswordLabel = get_setting('login_password_label', 'Password');
$loginPasswordPlaceholder = get_setting('login_password_placeholder', 'Your password');
$loginButtonText = get_setting('login_button_text', '🔐  Log In  →');
$loginMessage = get_setting('login_message', 'Please enter your login details.');
$loginFooter = get_setting('login_footer', 'Authorized personnel only');

/* ============================================================
   Public entry point — ALWAYS render the existing login interface first.
   Database connection is opened only by authenticated/API operations, so
   a missing MySQL configuration can never replace the login screen.
   ============================================================ */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#0b0f19">
<title><?= htmlspecialchars($panelName ?: "KEYS PANEL", ENT_QUOTES, "UTF-8") ?></title>

<style>
/* =========================================================
   FLASH STAR — SINGLE FILE FRONTEND APPLICATION
   ========================================================= */

*{
    box-sizing:border-box;
    margin:0;
    padding:0;
    -webkit-tap-highlight-color:transparent;
}

:root{
    --bg:#0b0f19;
    --bg2:#0f1522;
    --card:#111827;
    --card2:#151d2d;
    --input:#080e1c;
    --gold:#d4af37;
    --gold2:#f5d76e;
    --gold3:#a98216;
    --white:#f8fafc;
    --muted:#94a3b8;
    --muted2:#64748b;
    --green:#22c55e;
    --blue:#d9b43a;
    --red:#ef4444;
    --purple:#a78bfa;
    --border:rgba(212,175,55,.22);
}

html,body{
    width:100%;
    min-height:100%;
}

body{
    min-height:100vh;
    overflow-x:hidden;
    background:
        radial-gradient(circle at 0% 0%,rgba(212,175,55,.09),transparent 30%),
        radial-gradient(circle at 100% 10%,rgba(212,175,55,.035),transparent 28%),
        radial-gradient(circle at 50% 100%,rgba(212,175,55,.06),transparent 35%),
        var(--bg);
    color:var(--white);
    font-family:
        Inter,
        ui-sans-serif,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

/* Unicode / emoji-safe branding typography. The browser can fall back to its native color-emoji font. */
#splashKicker,#splashWelcome,#splashBrandName,#splashPortal,#splashSubtitle,#splashLoadingText,
#loginBrandName,#loginSubtitle,#loginBadge,#usernameLabel,#passwordLabel,#loginMessage,.login-footer,
#adminBrandName,#adminLogoBox,.admin-card input,.admin-card textarea,.admin-card button{
    font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI","Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif;
    unicode-bidi:plaintext;
}

#splash .splash-kicker,#splash .splash-welcome,#splash .splash-name,#splash .splash-portal,#splash .splash-subtitle,#splash .loading-text,
#loginScreen .login-brand,#loginScreen .login-subtitle,#loginScreen .auth-badge,#loginScreen .login-label,#loginScreen .login-message,#loginScreen .login-footer,#loginScreen .login-btn{
    font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI","Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif;
}

/* =========================================================
   SPLASH SCREEN — KEYS PANEL REFERENCE DESIGN
   Visual-only redesign. Existing boot/session logic is untouched.
   Branding is controlled independently from Login and Dashboard.
   ========================================================= */
#splash{
    position:fixed;
    inset:0;
    z-index:99999;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    min-height:100vh;
    min-height:100dvh;
    padding:16px 10px;
    box-sizing:border-box;
    color:#f8fafc;
    background-color:#070b13;
    background-image:
        linear-gradient(rgba(148,163,184,.055) 1px,transparent 1px),
        linear-gradient(90deg,rgba(148,163,184,.055) 1px,transparent 1px),
        radial-gradient(circle at 50% 38%,rgba(212,175,55,.075),transparent 34%),
        radial-gradient(circle at 50% 100%,rgba(15,23,42,.7),transparent 60%);
    background-size:58px 58px,58px 58px,100% 100%,100% 100%;
    transition:opacity .55s ease,visibility .55s ease,transform .55s ease;
}
#splash:before,
#splash:after{
    content:"";
    position:absolute;
    pointer-events:none;
    border-radius:50%;
    border:1px solid rgba(212,175,55,.025);
    background:rgba(212,175,55,.025);
}
#splash:before{width:250px;height:250px;left:-150px;top:12%;}
#splash:after{width:310px;height:310px;right:-190px;top:55%;}
#splash.hide{
    opacity:0;
    visibility:hidden;
    transform:translateY(-8px);
    pointer-events:none;
}
.splash-inner{
    position:relative;
    z-index:2;
    width:min(92vw,620px);
    text-align:center;
    padding:6px 6px 18px;
}
.splash-logo{
    width:154px;
    height:154px;
    margin:0 auto 48px;
    display:flex;
    align-items:center;
    justify-content:center;
    position:relative;
    overflow:visible;
    border-radius:11px;
    background:linear-gradient(145deg,#4e5c6d 0%,#263446 43%,#111a29 100%);
    border:3px solid rgba(212,175,55,.38);
    box-shadow:
        0 0 0 6px rgba(212,175,55,.035),
        0 25px 65px rgba(0,0,0,.52),
        0 0 48px rgba(212,175,55,.13),
        inset 0 0 0 3px rgba(0,0,0,.22);
    animation:splashLogoFloat 2.4s ease-in-out infinite;
}
.splash-logo:after{
    content:"";
    position:absolute;
    inset:-23px;
    border:2px solid rgba(225,188,54,.95);
    border-radius:9px;
    transform:rotate(20deg);
    box-shadow:0 0 22px rgba(212,175,55,.12);
    pointer-events:none;
}
.splash-logo:before{
    content:"";
    position:absolute;
    inset:-11px;
    border-radius:50%;
    background:rgba(245,215,110,.10);
    filter:blur(16px);
    z-index:-1;
}
.splash-logo img{
    width:100%;
    height:100%;
    object-fit:cover;
    position:relative;
    z-index:2;
    display:block;
    border-radius:8px;
}
.splash-star{
    position:relative;
    z-index:2;
    font-size:54px;
    color:#f2cb4d;
    text-shadow:0 0 16px rgba(245,215,110,.72),0 0 35px rgba(212,175,55,.35);
}
.splash-kicker{
    margin-bottom:20px;
    color:#d6b448;
    font:900 12px/1.2 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;
    letter-spacing:3.2px;
    text-transform:uppercase;
}
.splash-welcome{
    color:#f8fafc;
    font:900 clamp(27px,5vw,46px)/.98 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;
    letter-spacing:1px;
    text-transform:uppercase;
}
.splash-name{
    margin-top:8px;
    color:#dfbd45;
    font:900 clamp(25px,5vw,43px)/.98 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;
    letter-spacing:clamp(1px,.25vw,4px);
    text-transform:uppercase;
    overflow-wrap:anywhere;
    word-break:break-word;
}
.splash-portal{
    margin-top:12px;
    color:#dfbd45;
    font:900 clamp(22px,4.5vw,40px)/1 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;
    letter-spacing:clamp(1px,.2vw,3px);
    text-transform:uppercase;
}
.splash-subtitle{
    margin-top:18px;
    color:#9aa6bc;
    font:500 clamp(12px,2vw,18px)/1.35 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
.loading-line{
    width:min(300px,70vw);
    height:6px;
    margin:28px auto 0;
    overflow:hidden;
    border-radius:999px;
    background:#182844;
    box-shadow:inset 0 1px 3px rgba(0,0,0,.5);
}
.loading-progress{
    width:0;
    height:100%;
    border-radius:999px;
    background:linear-gradient(90deg,#b88918,#f5d76e,#d4af37);
    box-shadow:0 0 14px rgba(212,175,55,.52);
    animation:loading 1.5s cubic-bezier(.4,0,.2,1) forwards;
}
.loading-text{
    margin-top:16px;
    color:#68738a;
    font:500 10px/1.2 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;
    letter-spacing:2px;
    text-transform:uppercase;
}
@keyframes loading{to{width:100%}}
@keyframes splashLogoFloat{
    0%,100%{transform:translateY(0) rotate(0deg);}
    50%{transform:translateY(-8px) rotate(-1deg);}
}
@media(max-width:600px){
    #splash{padding:12px 8px;background-size:46px 46px,46px 46px,100% 100%,100% 100%;}
    .splash-inner{width:min(90vw,520px);padding:2px 3px 12px;}
    .splash-logo{width:128px;height:128px;margin-bottom:40px;border-width:2px;}
    .splash-logo:after{inset:-18px;border-width:2px;}
    .splash-logo:before{inset:-9px;filter:blur(13px);}
    .splash-star{font-size:44px;}
    .splash-kicker{font-size:9px;letter-spacing:2.4px;margin-bottom:17px;}
    .splash-welcome{font-size:25px;}
    .splash-name{font-size:23px;margin-top:6px;}
    .splash-portal{font-size:20px;margin-top:7px;}
    .splash-subtitle{font-size:11px;margin-top:14px;}
    .loading-line{height:5px;margin-top:22px;width:min(270px,68vw);}
    .loading-text{font-size:8px;letter-spacing:1.7px;margin-top:12px;}
}
@media(max-width:380px){
    .splash-logo{width:112px;height:112px;margin-bottom:34px;}
    .splash-logo:after{inset:-15px;}
    .splash-kicker{font-size:8px;letter-spacing:2px;margin-bottom:14px;}
    .splash-welcome{font-size:22px;}
    .splash-name{font-size:20px;}
    .splash-portal{font-size:18px;}
    .splash-subtitle{font-size:10px;margin-top:12px;}
    .loading-line{height:5px;margin-top:19px;width:min(240px,66vw);}
    .loading-text{font-size:7px;letter-spacing:1.5px;margin-top:10px;}
}

/* =========================================================
   APP
   ========================================================= */

#app{
    display:none;
    min-height:100vh;
}

#app.visible{
    display:block;
    animation:appIn .5s ease;
}

@keyframes appIn{
    from{
        opacity:0;
        transform:translateY(8px);
    }
    to{
        opacity:1;
        transform:translateY(0);
    }
}

.app-shell{
    width:100%;
    max-width:1100px;
    margin:0 auto;
    padding:0 16px 105px;
}


/* =========================================================
   TOP HEADER
   ========================================================= */

.top-header{
    height:68px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    border-bottom:1px solid rgba(148,163,184,.07);
}

.header-brand{
    display:flex;
    align-items:center;
    gap:10px;
}

.header-logo{
    width:36px;
    height:36px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:10px;
    background:linear-gradient(145deg,#27364e,#0e1625);
    border:1px solid rgba(212,175,55,.4);
    color:#f1cb4c;
    font-size:18px;
    box-shadow:0 0 18px rgba(212,175,55,.08);
}

.header-title{
    font-size:16px;
    font-weight:800;
    letter-spacing:1px;
}

.header-title span{
    background:linear-gradient(100deg,#c99720,#f7d96a,#d4af37);
    -webkit-background-clip:text;
    background-clip:text;
    color:transparent;
}

.balance-badge{
    display:flex;
    align-items:center;
    gap:7px;
    padding:8px 11px;
    border-radius:10px;
    background:rgba(212,175,55,.07);
    border:1px solid rgba(212,175,55,.25);
    color:#f2d15c;
    font-size:12px;
    font-weight:800;
}

.balance-dot{
    width:6px;
    height:6px;
    border-radius:50%;
    background:#22c55e;
    box-shadow:0 0 8px rgba(34,197,94,.7);
}


/* =========================================================
   PAGE VIEWS
   ========================================================= */

.page{
    display:none;
    padding-top:20px;
}

.page.active{
    display:block;
    animation:pageIn .3s ease;
}

@keyframes pageIn{
    from{
        opacity:0;
        transform:translateY(5px);
    }
    to{
        opacity:1;
        transform:translateY(0);
    }
}


/* =========================================================
   WELCOME
   ========================================================= */

.welcome{
    margin-bottom:17px;
}

.welcome-small{
    color:#e5c45a;
    font-size:11px;
    font-weight:800;
    letter-spacing:1.5px;
}

.welcome h1{
    margin-top:5px;
    font-size:24px;
    line-height:1.15;
    font-weight:800;
}

.username-text{
    color:#f1cf5b;
}


/* =========================================================
   UPDATE BANNER
   ========================================================= */

.update-banner{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:13px 14px;
    margin-bottom:17px;
    border-radius:14px;
    background:
        linear-gradient(
            105deg,
            rgba(212,175,55,.10),
            rgba(212,175,55,.035)
        );
    border:1px solid rgba(212,175,55,.22);
    box-shadow:0 10px 30px rgba(0,0,0,.13);
}

.update-info{
    min-width:0;
}

.update-title{
    font-size:11px;
    font-weight:800;
    letter-spacing:.7px;
}

.update-sub{
    margin-top:3px;
    color:#64748b;
    font-size:9px;
}

.join-btn{
    flex:none;
    border:0;
    padding:8px 11px;
    border-radius:8px;
    background:linear-gradient(110deg,#d4af37,#f3d264);
    color:#17120a;
    font-size:9px;
    font-weight:900;
    cursor:pointer;
}


/* =========================================================
   STATS
   ========================================================= */

.stats-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:10px;
    margin-bottom:17px;
}

.stat-card{
    min-width:0;
    padding:14px;
    border-radius:14px;
    background:
        linear-gradient(
            145deg,
            rgba(20,29,44,.98),
            rgba(13,19,31,.98)
        );
    border:1px solid rgba(148,163,184,.10);
    box-shadow:
        0 12px 30px rgba(0,0,0,.16),
        inset 0 1px 0 rgba(255,255,255,.025);
    transition:
        transform .2s ease,
        border-color .2s ease;
}

.stat-card:hover{
    transform:translateY(-2px);
    border-color:rgba(212,175,55,.22);
}

.stat-icon{
    width:30px;
    height:30px;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:10px;
    border-radius:9px;
    background:rgba(212,175,55,.08);
    color:#e7c753;
    font-size:14px;
}

.stat-value{
    font-size:18px;
    font-weight:800;
    white-space:nowrap;
}

.stat-name{
    margin-top:4px;
    color:#64748b;
    font-size:9px;
    line-height:1.3;
}


/* =========================================================
   DASHBOARD TWO COLUMN
   ========================================================= */

.dashboard-grid{
    display:grid;
    grid-template-columns:minmax(0,1.35fr) minmax(280px,.65fr);
    gap:14px;
}


/* =========================================================
   GENERAL CARD
   ========================================================= */

.panel{
    padding:17px;
    border-radius:16px;
    background:
        linear-gradient(
            145deg,
            rgba(19,28,43,.98),
            rgba(12,18,29,.98)
        );
    border:1px solid rgba(148,163,184,.10);
    box-shadow:
        0 15px 38px rgba(0,0,0,.16),
        inset 0 1px 0 rgba(255,255,255,.025);
}

.panel-heading{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:15px;
}

.panel-title{
    font-size:12px;
    font-weight:800;
    letter-spacing:.5px;
}

.live{
    display:flex;
    align-items:center;
    gap:5px;
    color:#22c55e;
    font-size:9px;
    font-weight:700;
}

.live-dot{
    width:6px;
    height:6px;
    border-radius:50%;
    background:#22c55e;
    box-shadow:0 0 8px rgba(34,197,94,.8);
    animation:livePulse 1.3s infinite;
}

@keyframes livePulse{
    50%{
        opacity:.35;
        transform:scale(.7);
    }
}


/* =========================================================
   CHALLENGE
   ========================================================= */

.challenge-header{
    margin-bottom:14px;
}

.challenge-name{
    font-size:18px;
    font-weight:800;
}

.challenge-date{
    margin-top:4px;
    color:#64748b;
    font-size:9px;
}

.podium{
    display:grid;
    grid-template-columns:1fr 1.15fr 1fr;
    align-items:end;
    gap:8px;
    height:160px;
}

.podium-place{
    text-align:center;
}

.podium-user{
    width:34px;
    height:34px;
    margin:0 auto 7px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:50%;
    background:linear-gradient(145deg,#27364b,#101827);
    border:1px solid rgba(212,175,55,.25);
    font-size:13px;
    font-weight:800;
}

.podium-name{
    font-size:9px;
    color:#cbd5e1;
    margin-bottom:6px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.podium-block{
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:9px 9px 3px 3px;
    background:linear-gradient(180deg,rgba(212,175,55,.22),rgba(212,175,55,.05));
    border:1px solid rgba(212,175,55,.15);
    color:#e9c94e;
    font-size:11px;
    font-weight:900;
}

.second .podium-block{
    height:65px;
}

.first .podium-block{
    height:91px;
}

.third .podium-block{
    height:48px;
}

.reward{
    margin-top:5px;
    color:#64748b;
    font-size:8px;
}


/* =========================================================
   TOP 5
   ========================================================= */

.reseller-list{
    display:flex;
    flex-direction:column;
    gap:8px;
}

.reseller{
    display:flex;
    align-items:center;
    gap:9px;
    padding:9px;
    border-radius:10px;
    background:rgba(8,14,28,.65);
    border:1px solid rgba(148,163,184,.06);
}

.rank{
    width:21px;
    height:21px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:7px;
    background:rgba(212,175,55,.08);
    color:#d8bc54;
    font-size:9px;
    font-weight:800;
}

.avatar{
    width:29px;
    height:29px;
    display:flex;
    align-items:center;
    justify-content:center;
    flex:none;
    border-radius:50%;
    background:linear-gradient(145deg,#26354c,#0c1422);
    border:1px solid rgba(148,163,184,.13);
    color:#cbd5e1;
    font-size:9px;
    font-weight:800;
}

.reseller-info{
    flex:1;
    min-width:0;
}

.reseller-name{
    font-size:10px;
    font-weight:700;
}

.reseller-sold{
    margin-top:2px;
    color:#64748b;
    font-size:8px;
}

.reseller-keys{
    color:#e5c45a;
    font-size:9px;
    font-weight:800;
}


/* =========================================================
   STORE PAGE
   ========================================================= */

.section-title{
    font-size:20px;
    font-weight:800;
}

.section-sub{
    margin-top:4px;
    margin-bottom:17px;
    color:#64748b;
    font-size:10px;
}

.product-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:11px;
}

.product{
    padding:13px;
    border-radius:13px;
    background:linear-gradient(145deg,#151f30,#0d1420);
    border:1px solid rgba(148,163,184,.09);
}

.product-image{
    height:80px;
    margin-bottom:11px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:10px;
    background:radial-gradient(circle,rgba(212,175,55,.12),transparent 70%),#080e1c;
    color:#e4c34d;
    font-size:25px;
}

.product-name{
    font-size:11px;
    font-weight:800;
}

.product-duration{
    margin-top:3px;
    color:#64748b;
    font-size:8px;
}

.product-bottom{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:5px;
    margin-top:11px;
}

.product-price{
    color:#f0cd56;
    font-size:11px;
    font-weight:800;
}

.buy-btn{
    padding:6px 8px;
    border:0;
    border-radius:7px;
    background:rgba(212,175,55,.12);
    color:#e5c45a;
    font-size:8px;
    font-weight:800;
}


/* =========================================================
   DEPOSIT / MY KEY / API
   ========================================================= */

.center-panel{
    max-width:650px;
    margin:0 auto;
}

.form-input{
    width:100%;
    height:45px;
    margin-top:7px;
    margin-bottom:13px;
    padding:10px 13px;
    outline:none;
    border:1px solid rgba(100,116,139,.2);
    border-radius:10px;
    background:#080e1c;
    color:white;
    font-size:12px;
}

.form-input:focus{
    border-color:rgba(212,175,55,.6);
    box-shadow:0 0 0 3px rgba(212,175,55,.07);
}

.form-label-small{
    color:#94a3b8;
    font-size:9px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:1px;
}

.gold-action{
    width:100%;
    height:43px;
    border:0;
    border-radius:10px;
    background:linear-gradient(110deg,#d4af37,#f3d264);
    color:#17120a;
    font-weight:800;
    cursor:pointer;
}

.api-box{
    padding:15px;
    border-radius:12px;
    background:#080e1c;
    border:1px solid rgba(212,175,55,.13);
    overflow:auto;
}

.api-code{
    color:#d9c267;
    font-family:monospace;
    font-size:10px;
    line-height:1.7;
    white-space:pre-wrap;
    word-break:break-word;
}

.info-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:12px 0;
    border-bottom:1px solid rgba(148,163,184,.07);
}

.info-row:last-child{
    border-bottom:0;
}

.info-label{
    color:#64748b;
    font-size:9px;
}

.info-value{
    font-size:10px;
    font-weight:700;
}



/* =========================================================
   FULL-SCREEN USER MAINTENANCE LOCK
   Shown only inside the reseller app. Admin has a separate app and
   therefore never receives this overlay.
   ========================================================= */
.user-maintenance-overlay{
    position:fixed;
    inset:0;
    z-index:100000;
    display:none;
    align-items:center;
    justify-content:center;
    flex-direction:column;
    gap:0;
    padding:30px 22px;
    box-sizing:border-box;
    overflow:hidden;
    text-align:center;
    color:#f8fafc;
    background:
        radial-gradient(circle at 50% 38%,rgba(212,175,55,.08),transparent 24%),
        radial-gradient(circle at 50% 50%,rgba(59,130,246,.045),transparent 42%),
        #000;
}
.user-maintenance-overlay.visible{display:flex;}
.admin-maintenance-overlay{z-index:100001;}
.maintenance-admin-back{margin-top:22px;padding:12px 22px;border:1px solid rgba(212,175,55,.55);border-radius:12px;background:rgba(212,175,55,.12);color:#f5d76e;font-weight:900;letter-spacing:1px;cursor:pointer;box-shadow:0 0 18px rgba(212,175,55,.08);}
.maintenance-admin-back:hover{background:rgba(212,175,55,.2);}
body.user-maintenance-active{overflow:hidden !important;}
.user-maintenance-overlay::before,
.user-maintenance-overlay::after{
    content:"";
    position:absolute;
    left:50%;
    top:50%;
    width:min(92vw,620px);
    height:min(92vw,620px);
    border:1px solid rgba(212,175,55,.055);
    border-radius:50%;
    transform:translate(-50%,-50%);
    pointer-events:none;
}
.user-maintenance-overlay::before{animation:maintenancePulse 2.8s ease-in-out infinite;}
.user-maintenance-overlay::after{width:min(70vw,430px);height:min(70vw,430px);border-color:rgba(148,163,184,.045);animation:maintenancePulse 2.8s ease-in-out 1.4s infinite;}
.maintenance-orbit{
    position:relative;
    width:118px;
    height:118px;
    margin-bottom:30px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    filter:drop-shadow(0 0 18px rgba(212,175,55,.15));
}
.orbit-ring{position:absolute;inset:9px;border:2px solid rgba(212,175,55,.25);border-top-color:#f5d76e;border-right-color:rgba(245,215,110,.08);border-radius:50%;}
.ring-one{animation:maintenanceSpin 1.45s linear infinite;}
.ring-two{inset:23px;border-color:rgba(148,163,184,.16);border-bottom-color:#94a3b8;border-left-color:transparent;animation:maintenanceSpinReverse 2.2s linear infinite;}
.orbit-core{width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#d4af37,#f5d76e);color:#111827;font-size:20px;font-weight:900;box-shadow:0 0 24px rgba(212,175,55,.28),inset 0 0 0 1px rgba(255,255,255,.22);animation:maintenanceCore 1.8s ease-in-out infinite;}
.orbit-signal{position:absolute;width:6px;height:6px;border-radius:50%;background:#f5d76e;box-shadow:0 0 12px rgba(245,215,110,.7);}
.signal-one{top:4px;left:56px;animation:maintenanceSignal 1.3s ease-in-out infinite;}
.signal-two{right:7px;bottom:26px;animation:maintenanceSignal 1.3s ease-in-out .35s infinite;}
.signal-three{left:14px;bottom:18px;animation:maintenanceSignal 1.3s ease-in-out .7s infinite;}
.maintenance-lock-title{position:relative;z-index:2;font-size:clamp(22px,6vw,38px);line-height:1.08;font-weight:1000;letter-spacing:2px;text-transform:uppercase;text-shadow:0 0 22px rgba(245,215,110,.10);}
.maintenance-lock-line{position:relative;z-index:2;width:72px;height:2px;margin:17px auto 14px;background:linear-gradient(90deg,transparent,#f5d76e,transparent);box-shadow:0 0 12px rgba(245,215,110,.25);}
.maintenance-lock-message{position:relative;z-index:2;max-width:520px;color:#94a3b8;font-size:11px;line-height:1.65;}
.maintenance-lock-status{position:relative;z-index:2;display:flex;align-items:center;gap:7px;margin-top:18px;padding:8px 12px;border:1px solid rgba(245,215,110,.12);border-radius:999px;background:rgba(255,255,255,.025);color:#cbd5e1;font-size:9px;font-weight:900;letter-spacing:.12em;}
.maintenance-live-dot{width:6px;height:6px;border-radius:50%;background:#f5d76e;box-shadow:0 0 10px rgba(245,215,110,.7);animation:maintenanceDot 1s ease-in-out infinite;}
@keyframes maintenanceSpin{to{transform:rotate(360deg)}}
@keyframes maintenanceSpinReverse{to{transform:rotate(-360deg)}}
@keyframes maintenanceCore{0%,100%{transform:scale(1) rotate(0deg)}50%{transform:scale(1.08) rotate(45deg)}}
@keyframes maintenanceSignal{0%,100%{opacity:.25;transform:scale(.65)}50%{opacity:1;transform:scale(1.25)}}
@keyframes maintenanceDot{0%,100%{opacity:.35;transform:scale(.7)}50%{opacity:1;transform:scale(1.25)}}
@keyframes maintenancePulse{0%,100%{opacity:.35;transform:translate(-50%,-50%) scale(.92)}50%{opacity:.8;transform:translate(-50%,-50%) scale(1)}}
@media(max-width:480px){.maintenance-orbit{width:102px;height:102px;margin-bottom:25px}.maintenance-lock-title{font-size:24px;letter-spacing:1.5px}.maintenance-lock-message{font-size:10px}.maintenance-lock-status{font-size:8px;letter-spacing:.08em}}

/* =========================================================
   BOTTOM NAVIGATION
   ========================================================= */

.bottom-nav{
    position:fixed;
    z-index:500;
    left:50%;
    bottom:0;
    transform:translateX(-50%);
    width:min(680px,100%);
    padding:7px;
    display:grid;
    grid-template-columns:repeat(6,1fr);
    gap:3px;
    border-radius:17px;
    background:rgba(12,18,29,.94);
    border:1px solid rgba(212,175,55,.18);
    box-shadow:
        0 15px 45px rgba(0,0,0,.45),
        0 0 25px rgba(212,175,55,.035);
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
}

.nav-btn{
    min-width:0;
    height:52px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:4px;
    border:0;
    border-radius:11px;
    background:transparent;
    color:#64748b;
    cursor:pointer;
    font-size:8px;
    font-weight:700;
    transition:
        color .18s ease,
        background .18s ease,
        transform .18s ease;
}

.nav-icon{
    font-size:17px;
    line-height:1;
}

.nav-btn:hover{
    color:#d8bc54;
}

.nav-btn.active{
    color:#f0cf59;
    background:rgba(212,175,55,.09);
}

.nav-btn:active{
    transform:scale(.95);
}


/* =========================================================
   ADMIN ROLE SCREEN — hidden until a valid admin login
   ========================================================= */

/* ADMIN STORE MANAGEMENT */
.admin-management-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}
.admin-management-grid select,.admin-management-grid input,.admin-management-grid textarea{width:100%;box-sizing:border-box;background:#080e1c;color:#fff;border:1px solid rgba(255,255,255,.10);border-radius:11px;padding:12px;font-size:13px;outline:none;}
.admin-management-grid select:focus,.admin-management-grid input:focus,.admin-management-grid textarea:focus{border-color:rgba(245,215,110,.65);box-shadow:0 0 0 3px rgba(245,215,110,.07);}
.create-plan-block{margin-top:10px;padding:13px;border:1px solid rgba(212,175,55,.18);border-radius:12px;background:rgba(8,14,28,.55)}.create-plan-block textarea{width:100%;min-height:100px;margin-top:9px;background:#070b16;color:#fff;border:1px solid rgba(255,255,255,.10);border-radius:10px;padding:10px;font-family:ui-monospace,monospace;font-size:12px;resize:vertical;outline:none}.create-plan-block textarea:focus{border-color:rgba(245,215,110,.65)}.admin-management-box{min-width:0;padding:14px;border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(255,255,255,.025)}
.admin-management-box b{display:block;font-size:11px;letter-spacing:.8px;color:#f5d76e;margin-bottom:9px}
.admin-management-box input,.admin-management-box select{display:block;width:100%;min-width:0;height:46px;margin:7px 0;padding:0 12px;border-radius:10px;border:1px solid rgba(255,255,255,.10);background:#070b16;color:#f8fafc;outline:none}
.admin-management-box button{width:100%;min-height:44px;margin-top:6px;border:0;border-radius:10px;cursor:pointer}
.admin-data-summary{padding:11px 12px;margin-bottom:9px;border-radius:10px;background:rgba(245,215,110,.07);color:#cbd5e1;font-size:12px;overflow-wrap:anywhere}
@media(max-width:700px){.admin-management-grid{grid-template-columns:1fr}}
#adminApp{display:none;min-height:100vh;padding:24px 14px;box-sizing:border-box;background:radial-gradient(circle at 8% 8%,rgba(212,175,55,.10),transparent 30%),radial-gradient(circle at 92% 18%,rgba(212,175,55,.035),transparent 28%),#0b0f19;}
#adminApp.visible{display:block;}
.admin-shell{width:100%;max-width:980px;margin:0 auto;}
.admin-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:14px 0;}
.admin-tab-btn{background:#111827;border:1px solid rgba(212,175,55,.22);color:#cbd5e1;font-size:11px;font-weight:800;letter-spacing:.03em;padding:8px 12px;border-radius:10px;cursor:pointer;}
.admin-tab-btn.active{background:linear-gradient(135deg,#d4af37,#b8860b);color:#111;border-color:transparent;}
.admin-tab-panel{display:none;}
.admin-tab-panel.active{display:block;}
.admin-maint-banner{background:rgba(220,38,38,.18);border:1px solid rgba(220,38,38,.4);color:#fecaca;font-size:12px;font-weight:800;padding:10px 14px;border-radius:12px;margin-bottom:6px;}
.admin-summary-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-top:12px;}
.admin-summary-cards>div{background:#0b0f19;border:1px solid rgba(212,175,55,.2);border-radius:10px;padding:10px;text-align:center;}
.admin-summary-cards b{display:block;font-size:16px;color:#f5d76e;}
.admin-summary-cards span{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;}
.maintenance-badge{display:inline-block;background:rgba(220,38,38,.18);color:#fecaca;border:1px solid rgba(220,38,38,.4);font-size:10px;font-weight:900;letter-spacing:.05em;padding:4px 8px;border-radius:8px;}
.admin-modal{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;}
.admin-modal-box{background:#111827;border:1px solid rgba(212,175,55,.3);border-radius:16px;padding:22px;max-width:520px;width:100%;max-height:82vh;overflow:auto;position:relative;}
.admin-modal-close{position:absolute;top:10px;right:12px;background:none;border:none;color:#94a3b8;font-size:16px;cursor:pointer;}
.admin-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;}
.admin-brand{display:flex;align-items:center;gap:11px;min-width:0;}
.admin-logo{width:48px;height:48px;flex:0 0 48px;border:1px solid rgba(212,175,55,.5);border-radius:14px;display:flex;align-items:center;justify-content:center;color:#f5d76e;font-size:25px;background:rgba(212,175,55,.08);}.admin-logo img{width:100%;height:100%;display:block;object-fit:contain;border-radius:12px;}
.admin-title{font-size:20px;font-weight:900;letter-spacing:1px;}.admin-title span{color:#f5d76e;}.admin-sub{font-size:11px;color:#94a3b8;margin-top:3px;}
.admin-logout{border:1px solid rgba(239,68,68,.28);background:rgba(239,68,68,.10);color:#fecaca;border-radius:11px;padding:10px 13px;font-weight:800;cursor:pointer;}
.admin-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}.admin-card{background:#111827;border:1px solid rgba(212,175,55,.22);border-radius:18px;padding:18px;box-shadow:0 20px 60px rgba(0,0,0,.28);min-width:0;}.admin-card.wide{grid-column:1/-1;}
.admin-card-title{font-size:16px;font-weight:900;margin-bottom:6px;}.admin-card-note{font-size:12px;color:#94a3b8;line-height:1.55;margin-bottom:15px;overflow-wrap:anywhere;}
.admin-field{margin-bottom:11px;}.admin-field label{display:block;font-size:11px;font-weight:800;color:#cbd5e1;margin-bottom:6px;}.admin-field input{width:100%;box-sizing:border-box;background:#080e1c;color:#fff;border:1px solid rgba(255,255,255,.10);border-radius:11px;padding:12px;font-size:14px;outline:none;}.admin-field input:focus{border-color:rgba(245,215,110,.65);box-shadow:0 0 0 3px rgba(245,215,110,.07);}
.admin-actions{display:flex;gap:9px;flex-wrap:wrap;}.admin-primary,.admin-secondary{border-radius:11px;padding:12px 14px;font-weight:900;cursor:pointer;}.admin-primary{border:0;background:linear-gradient(135deg,#d4af37,#f5d76e);color:#16120a;flex:1;}.admin-secondary{background:#1b2436;color:#e5e7eb;border:1px solid rgba(255,255,255,.08);}
.admin-credentials{display:none;margin-top:14px;padding:13px;border-radius:12px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.28);}.admin-credentials.show{display:block;}.admin-credentials .cred{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.07);font-size:13px;}.admin-credentials .cred:last-child{border-bottom:0;}.admin-credentials code{font-family:ui-monospace,monospace;color:#f5d76e;overflow-wrap:anywhere;text-align:right;}
.admin-list{display:grid;gap:8px;max-height:420px;overflow:auto;}.admin-user-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 12px;border:1px solid rgba(255,255,255,.07);border-radius:12px;background:rgba(255,255,255,.025);min-width:0;}.admin-user-name{font-weight:800;overflow-wrap:anywhere;}.admin-user-meta{font-size:10px;color:#94a3b8;margin-top:3px;}.admin-user-status{font-size:10px;font-weight:900;padding:5px 8px;border-radius:999px;white-space:nowrap;}.admin-user-status.active{color:#bbf7d0;background:rgba(34,197,94,.10);}.admin-user-status.disabled{color:#fecaca;background:rgba(239,68,68,.10);}.admin-mini-btn{border:1px solid rgba(255,255,255,.10);background:#111827;color:#e5e7eb;border-radius:8px;padding:7px 9px;font-size:10px;font-weight:800;cursor:pointer;}.admin-empty{color:#94a3b8;font-size:12px;text-align:center;padding:15px;}
@media(max-width:620px){.admin-grid{grid-template-columns:1fr;}.admin-card.wide{grid-column:auto;}.admin-actions>*{width:100%;}.admin-primary{flex:auto;}}

/* FINAL VISIBILITY HARDENING — never allow a loaded dashboard page
   to collapse into an empty/black viewport because of an async request. */
#app.visible{display:block !important;visibility:visible !important;opacity:1 !important;}
#app.visible .page.active{display:block !important;visibility:visible !important;}
#app.visible .bottom-nav{display:grid !important;visibility:visible !important;}
#adminApp.visible{display:block !important;visibility:visible !important;opacity:1 !important;}
#adminApp.visible .admin-shell{display:block !important;visibility:visible !important;}

/* =========================================================
   LOGIN
   ========================================================= */

#loginScreen{
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:25px 14px;
    position:relative;
    background:
        radial-gradient(circle at 5% 5%,rgba(212,175,55,.10),transparent 30%),
        radial-gradient(circle at 95% 15%,rgba(212,175,55,.035),transparent 28%),
        #0b0f19;
}

.login-wrapper{
    width:100%;
    max-width:380px;
    text-align:center;
}

.login-logo{
    width:65px;
    height:65px;
    margin:0 auto 14px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:16px;
    position:relative;
    overflow:hidden;
    background:linear-gradient(145deg,#293954,#111a2b 55%,#080d17);
    border:1px solid rgba(212,175,55,.45);
    box-shadow:
        0 10px 35px rgba(0,0,0,.4),
        0 0 30px rgba(212,175,55,.12);
}

.login-star{
    position:relative;
    z-index:1;
    font-size:33px;
    color:#f2cb4d;
    text-shadow:0 0 10px rgba(245,215,110,.7);
}

.login-brand{
    font-size:20px;
    line-height:1;
    font-weight:800;
    letter-spacing:1px;
}

.login-brand span{
    background:linear-gradient(100deg,#c99720,#f7d96a,#d4af37);
    -webkit-background-clip:text;
    background-clip:text;
    color:transparent;
}

.login-subtitle{
    margin-top:7px;
    color:#94a3b8;
    font-size:11px;
}

.login-card{
    width:100%;
    margin-top:22px;
    padding:20px 18px;
    border-radius:20px;
    text-align:left;
    background:linear-gradient(145deg,rgba(23,32,49,.97),rgba(13,19,31,.98));
    border:1.5px solid rgba(212,175,55,.30);
    box-shadow:
        0 24px 70px rgba(0,0,0,.42),
        0 0 35px rgba(212,175,55,.055),
        inset 0 1px 0 rgba(255,255,255,.045);
}

.auth-badge{
    width:max-content;
    margin:0 auto 18px;
    padding:6px 12px;
    border-radius:999px;
    border:1px solid rgba(212,175,55,.35);
    background:rgba(212,175,55,.07);
    color:#e5c45a;
    font-size:10px;
    font-weight:700;
    letter-spacing:1px;
}

.auth-badge:before{
    content:"✦";
    margin-right:7px;
}

.login-field{
    margin-bottom:14px;
}

.login-label{
    display:block;
    margin-bottom:7px;
    color:#aebbd0;
    font-size:10px;
    font-weight:700;
    letter-spacing:1.5px;
    text-transform:uppercase;
}

.login-input-wrap{
    position:relative;
}

.login-input{
    width:100%;
    height:43px;
    padding:10px 14px;
    outline:none;
    border:1px solid rgba(100,116,139,.25);
    border-radius:10px;
    background:#080e1c;
    color:#f8fafc;
    font-size:13px;
    transition:.2s;
}

.login-input::placeholder{
    color:#64748b;
}

.login-input:focus{
    border-color:rgba(212,175,55,.75);
    box-shadow:
        0 0 0 3px rgba(212,175,55,.08),
        0 0 20px rgba(212,175,55,.08);
}

.password-input{
    padding-right:45px;
}

.eye-btn{
    position:absolute;
    right:9px;
    top:50%;
    transform:translateY(-50%);
    width:29px;
    height:29px;
    border:0;
    border-radius:7px;
    background:transparent;
    color:#64748b;
    cursor:pointer;
}

.eye-btn:hover{
    color:#e5c45a;
    background:rgba(212,175,55,.08);
}

.eye-btn svg{
    width:18px;
    height:18px;
}

.login-btn{
    width:100%;
    height:43px;
    margin-top:2px;
    border:0;
    border-radius:10px;
    cursor:pointer;
    background:linear-gradient(110deg,#d4af37,#f3d264 48%,#c99d24);
    color:#17120a;
    font-size:14px;
    font-weight:800;
    box-shadow:
        0 8px 22px rgba(212,175,55,.18),
        inset 0 1px 0 rgba(255,255,255,.35);
    transition:.18s;
}

.login-btn:hover{
    transform:translateY(-1px);
    filter:brightness(1.06);
    box-shadow:0 11px 30px rgba(212,175,55,.27);
}

.login-btn:active{
    transform:translateY(1px);
}

.login-footer{
    margin-top:12px;
    padding-top:12px;
    border-top:1px solid rgba(148,163,184,.09);
    text-align:center;
    color:#64748b;
    font-size:9px;
    letter-spacing:.5px;
}

.login-message{
    min-height:16px;
    margin-top:9px;
    text-align:center;
    color:#94a3b8;
    font-size:10px;
    opacity:0;
    transition:.2s;
}

.login-message.show{
    opacity:1;
}


/* =========================================================
   TOAST
   ========================================================= */

#toast{
    position:fixed;
    z-index:100000;
    left:50%;
    bottom:82px;
    transform:translate(-50%,15px);
    max-width:calc(100% - 30px);
    padding:10px 14px;
    border-radius:10px;
    background:#151f30;
    border:1px solid rgba(212,175,55,.2);
    color:#e5e7eb;
    font-size:10px;
    box-shadow:0 12px 35px rgba(0,0,0,.4);
    opacity:0;
    pointer-events:none;
    transition:.25s ease;
    text-align:center;
}

#toast.show{
    opacity:1;
    transform:translate(-50%,0);
}


/* =========================================================
   RESPONSIVE
   ========================================================= */

@media(max-width:800px){

    .stats-grid{
        grid-template-columns:repeat(2,1fr);
    }

    .dashboard-grid{
        grid-template-columns:1fr;
    }

    .product-grid{
        grid-template-columns:repeat(2,1fr);
    }

    .app-shell{
        padding-left:13px;
        padding-right:13px;
    }
}

@media(max-width:520px){

    .top-header{
        height:62px;
    }

    .header-title{
        font-size:14px;
    }

    .balance-badge{
        font-size:10px;
        padding:7px 9px;
    }

    .welcome h1{
        font-size:21px;
    }

    .stats-grid{
        gap:8px;
    }

    .stat-card{
        padding:12px;
    }

    .stat-value{
        font-size:16px;
    }

    .product-grid{
        gap:8px;
    }

    .product{
        padding:10px;
    }

    .product-image{
        height:68px;
    }

    .bottom-nav{
        bottom:0;
        width:100%;
        padding:5px;
        border-radius:15px;
    }

    .nav-btn{
        height:48px;
    }

    .nav-icon{
        font-size:16px;
    }
}

@media(max-width:360px){

    .app-shell{
        padding-left:10px;
        padding-right:10px;
    }

    .balance-badge{
        padding:6px 7px;
    }

    .header-logo{
        width:33px;
        height:33px;
    }

    .header-title{
        font-size:13px;
    }

    .nav-btn{
        font-size:7px;
    }

    .nav-icon{
        font-size:14px;
    }
}

@media(prefers-reduced-motion:reduce){

    *,
    *::before,
    *::after{
        animation-duration:.01ms!important;
        animation-iteration-count:1!important;
        transition-duration:.01ms!important;
    }
}

/* Exact dashboard emoji/icon alignment */
.stat-icon,.nav-icon,.podium-user{font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif;}
.podium-user{font-size:22px;text-shadow:0 2px 8px rgba(0,0,0,.35);}
.reward{display:flex;align-items:center;justify-content:center;gap:3px;color:#e5c45a;font-weight:800;}
.live-dot{box-shadow:0 0 8px rgba(34,197,94,.8),0 0 16px rgba(34,197,94,.35);}
.reseller{min-height:47px;}
.reseller-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex:0 0 34px;font-size:10px;font-weight:900;color:#e8bd35;background:#14213a;border:1px solid rgba(128,145,177,.28);}
.reseller-sold{white-space:nowrap;}
.reseller-keys{min-width:45px;text-align:right;white-space:nowrap;}
@media(max-width:520px){.header-user-wrap{gap:5px}.header-user{font-size:9px}.header-title{font-size:13px}.header-logo{width:32px;height:32px;font-size:16px}.nav-icon{font-size:17px;}}

/* =========================================================
   KEYS PANEL — VIDEO MATCH / MOBILE OVERRIDES
   ========================================================= */
:root{
    --kp-bg:#050913;
    --kp-panel:#121b2d;
    --kp-panel2:#1b2639;
    --kp-gold:#e8bd2f;
    --kp-muted:#9ca7c0;
    --kp-purple:#8b4bd4;
}
html,body{
    background:#050913 !important;
}
body{
    font-family:"Arial Narrow","Trebuchet MS",Arial,sans-serif;
    letter-spacing:.2px;
}
#app{
    background:
      radial-gradient(circle at 12% 25%,rgba(53,73,117,.08),transparent 28%),
      linear-gradient(180deg,#080a16 0%,#050913 100%);
}
.app-shell{
    max-width:100%;
    padding:0 34px 150px !important;
}
.top-header{
    height:155px !important;
    margin:0 -34px;
    padding:0 38px !important;
    background:linear-gradient(180deg,#080a16,#080b18);
    border-bottom:1px solid rgba(106,120,157,.35) !important;
}
.header-brand{
    gap:22px !important;
}
.header-logo{
    width:86px !important;
    height:86px !important;
    border-radius:20px !important;
    background:linear-gradient(145deg,#18243a,#0b111f) !important;
    border:1px solid rgba(219,177,51,.32) !important;
    box-shadow:0 0 24px rgba(220,177,51,.11) !important;
    overflow:hidden;
}
.header-logo img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}
.header-title{
    font-size:34px !important;
    letter-spacing:.5px !important;
    line-height:1 !important;
}
.header-user-wrap{
    display:flex !important;
    flex-direction:column;
    align-items:flex-start !important;
    gap:4px !important;
}
.header-user{
    color:#d7ad31 !important;
    font-size:20px !important;
    font-weight:800 !important;
    letter-spacing:5px !important;
}
.header-user::first-letter{color:#d9b43a;}
.balance-badge{
    min-width:205px;
    height:60px;
    justify-content:center;
    padding:0 22px !important;
    border-radius:32px !important;
    background:rgba(212,175,55,.08) !important;
    border:1px solid rgba(212,175,55,.34) !important;
    box-shadow:inset 0 0 22px rgba(212,175,55,.04);
    color:#e7bd35 !important;
    font-size:23px !important;
    letter-spacing:3px;
}
.balance-dot{display:none !important;}
.balance-money{font-size:27px;margin-right:7px;}

.page{
    padding-top:44px !important;
}
.welcome{
    margin-bottom:14px !important;
}
.welcome-small{
    color:#f8f8fa !important;
    font-size:39px !important;
    letter-spacing:.5px !important;
    line-height:1.1 !important;
    font-weight:900 !important;
}
.welcome-small:first-letter{
    font-size:46px;
}
.welcome h1{
    margin:14px 0 0 !important;
    font-size:27px !important;
    font-weight:500 !important;
    line-height:1 !important;
}
.username-text{
    color:#aab2c8 !important;
}

.update-banner{
    display:block !important;
    padding:37px 30px 31px !important;
    margin-bottom:33px !important;
    border-radius:34px !important;
    background:linear-gradient(135deg,rgba(32,42,82,.94),rgba(22,39,61,.9)) !important;
    border:2px solid rgba(132,67,203,.75) !important;
    box-shadow:0 0 28px rgba(72,50,160,.12), inset 0 0 30px rgba(47,57,109,.14) !important;
}
.update-title{
    color:#f7f7fb !important;
    font-size:28px !important;
    line-height:1.15 !important;
    letter-spacing:.2px !important;
    font-weight:900 !important;
}
.update-sub{
    margin-top:19px !important;
    color:#aab3c9 !important;
    font-size:23px !important;
}
.join-btn{
    width:100%;
    height:84px;
    margin-top:37px;
    border-radius:25px !important;
    background:rgba(31,35,72,.72) !important;
    border:2px solid rgba(142,69,217,.75) !important;
    color:#b967f1 !important;
    font-size:27px !important;
    letter-spacing:.4px;
}

.stats-grid{
    grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    gap:33px !important;
    margin-bottom:34px !important;
}
.stat-card{
    min-height:216px;
    padding:31px 30px !important;
    border-radius:34px !important;
    background:linear-gradient(180deg,rgba(43,50,62,.96),rgba(20,30,51,.98)) !important;
    border:2px solid rgba(177,153,69,.26) !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.03),0 12px 25px rgba(0,0,0,.18) !important;
}
.stat-icon{
    width:auto !important;
    height:auto !important;
    margin-bottom:19px !important;
    background:none !important;
    font-size:43px !important;
    justify-content:flex-start !important;
}
.stat-value{
    font-size:38px !important;
    color:#e8bc2d !important;
    line-height:1 !important;
    font-weight:900 !important;
}
.stat-name{
    margin-top:20px !important;
    color:#a6afc6 !important;
    font-size:22px !important;
    line-height:1 !important;
    letter-spacing:3px !important;
    text-transform:uppercase;
    order:2;
}
.stat-card .stat-icon{order:0;}
.stat-card .stat-value{order:1;}

.dashboard-grid{
    display:flex !important;
    flex-direction:column !important;
    gap:34px !important;
}
.panel{
    padding:31px !important;
    border-radius:34px !important;
    background:linear-gradient(180deg,rgba(32,43,63,.98),rgba(22,35,58,.98)) !important;
    border:2px solid rgba(179,150,67,.34) !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.03),0 12px 28px rgba(0,0,0,.16) !important;
}
.challenge-panel{
    padding-bottom:37px !important;
}
.challenge-heading{
    margin-bottom:9px !important;
}
.panel-title{
    color:#f4f5f9 !important;
    font-size:29px !important;
    line-height:1.15 !important;
    font-weight:900 !important;
    letter-spacing:0 !important;
}
.challenge-header{
    margin-bottom:23px !important;
}
.challenge-date{
    color:#aeb6ca !important;
    font-size:23px !important;
    margin-top:0 !important;
    line-height:1.25 !important;
}
.challenge-date strong{
    color:#e5bb31;
}
.live{
    min-width:125px;
    height:49px;
    padding:0 19px;
    border-radius:30px;
    background:#e8bd35;
    color:#151515;
    font-size:18px;
    letter-spacing:2px;
    justify-content:center;
}
.live-dot{
    width:0 !important;
    height:0 !important;
    border-top:7px solid transparent;
    border-bottom:7px solid transparent;
    border-left:12px solid #fff0a4;
    border-radius:0 !important;
    background:transparent !important;
    box-shadow:none !important;
    animation:none !important;
}
.podium{
    height:185px !important;
    gap:21px !important;
    grid-template-columns:repeat(3,1fr) !important;
}
.podium-place{
    height:100%;
    display:flex;
    flex-direction:column;
    justify-content:flex-end;
}
.podium-user{
    width:auto !important;
    height:auto !important;
    margin:0 auto 11px !important;
    background:none !important;
    border:0 !important;
    font-size:41px !important;
    text-shadow:none !important;
}
.podium-name{
    color:#aeb6ca !important;
    font-size:20px !important;
    font-weight:500 !important;
    letter-spacing:1px !important;
    margin-bottom:10px !important;
}
.podium-block{
    display:none !important;
}
.reward{
    margin:0 !important;
    color:#fff !important;
    font-size:27px !important;
    font-weight:800 !important;
}
.first .reward{color:#e9bf35 !important;}
.reward b{font-size:28px;}
.reward::first-letter{font-size:25px;}

.top5-panel{
    padding:35px 48px 45px !important;
    background:linear-gradient(180deg,#1c2a44,#17243b) !important;
    border-color:rgba(74,102,154,.32) !important;
}
.top5-panel .panel-heading{
    justify-content:flex-start !important;
    margin-bottom:27px !important;
}
.top5-panel .panel-title{
    color:#e5bb35 !important;
    font-size:26px !important;
    letter-spacing:6px !important;
}
.reseller-list{
    gap:26px !important;
}
.reseller{
    min-height:114px !important;
    padding:0 35px !important;
    gap:24px !important;
    border-radius:28px !important;
    background:#071020 !important;
    border:2px solid rgba(194,158,51,.24) !important;
}
.reseller:first-child{
    background:rgba(54,55,60,.72) !important;
    border-color:rgba(205,171,58,.65) !important;
}
.rank{
    width:61px !important;
    height:61px !important;
    border-radius:50% !important;
    background:#14213a !important;
    border:1px solid rgba(128,145,177,.28) !important;
    color:#9da8c1 !important;
    font-size:25px !important;
    flex:none;
}
.rank-first{
    background:#e4bc38 !important;
    border-color:#e4bc38 !important;
    color:#111 !important;
}
.reseller-info{flex:1 !important;}
.reseller-name{
    font-size:28px !important;
    font-weight:800 !important;
    line-height:1.1 !important;
}
.reseller-sold{
    margin-top:9px !important;
    color:#9ca6bf !important;
    font-size:23px !important;
}
.reseller-amount{
    color:#e8bd34 !important;
    font-size:26px !important;
    font-weight:900 !important;
    white-space:nowrap;
}

.bottom-nav{
    position:fixed !important;
    left:0 !important;
    right:0 !important;
    bottom:0 !important;
    height:144px !important;
    padding:18px 20px 15px !important;
    z-index:9999 !important;
    display:grid !important;
    grid-template-columns:repeat(6,1fr) !important;
    gap:4px !important;
    border-top:1px solid rgba(87,105,144,.38) !important;
    background:rgba(5,9,19,.98) !important;
    backdrop-filter:blur(12px);
}
.nav-btn{
    min-width:0 !important;
    height:108px !important;
    padding:10px 3px !important;
    border-radius:27px !important;
    color:#9ba5be !important;
    background:transparent !important;
    font-size:20px !important;
    font-weight:800 !important;
    letter-spacing:1px !important;
    text-transform:uppercase;
}
.nav-btn.active{
    background:rgba(56,53,41,.55) !important;
    color:#e4ba32 !important;
}
.nav-icon{
    width:42px !important;
    height:42px !important;
    margin-bottom:10px !important;
    display:block !important;
    font-size:0 !important;
}
.nav-icon svg{
    width:42px;
    height:42px;
    fill:none;
    stroke:currentColor;
    stroke-width:1.7;
    stroke-linecap:round;
    stroke-linejoin:round;
}
.nav-btn span:last-child{
    display:block;
}
@media (min-width:801px){
    .app-shell{max-width:1100px;padding-left:24px !important;padding-right:24px !important;}
    .top-header{margin:0 -24px;padding:0 24px !important;}
}
@media (max-width:520px){
    .app-shell{padding-left:34px !important;padding-right:34px !important;padding-bottom:150px !important;}
    .top-header{margin-left:-34px;margin-right:-34px;padding-left:38px !important;padding-right:38px !important;}
    .header-logo{width:82px !important;height:82px !important;}
    .header-title{font-size:31px !important;}
    .header-user{font-size:17px !important;letter-spacing:4px !important;}
    .balance-badge{min-width:185px;height:56px;font-size:20px !important;}
    .welcome-small{font-size:35px !important;}
    .update-title{font-size:24px !important;}
    .update-sub{font-size:20px !important;}
    .join-btn{font-size:24px !important;}
    .stat-card{min-height:200px;padding:27px 28px !important;}
    .stat-name{font-size:18px !important;letter-spacing:2px !important;}
    .stat-value{font-size:34px !important;}
    .panel-title{font-size:25px !important;}
    .challenge-date{font-size:20px !important;}
    .top5-panel{padding-left:36px !important;padding-right:36px !important;}
    .top5-panel .panel-title{font-size:21px !important;letter-spacing:4px !important;}
    .reseller{min-height:102px !important;padding:0 26px !important;gap:18px !important;}
    .rank{width:55px !important;height:55px !important;font-size:22px !important;}
    .reseller-name{font-size:24px !important;}
    .reseller-sold{font-size:20px !important;}
    .reseller-amount{font-size:23px !important;}
    .nav-btn{font-size:17px !important;}
}


/* =========================================================
   FINAL RESPONSIVE SIZE FIX
   Same dashboard canvas on phone and desktop preview.
   Desktop stays phone-width and centered; phone uses full width.
   ========================================================= */
html,body{
    margin:0 !important;
    width:100% !important;
    min-width:0 !important;
    overflow-x:hidden !important;
}
#app{
    width:100% !important;
    min-width:0 !important;
}
.app-shell{
    width:100% !important;
    max-width:430px !important;
    margin:0 auto !important;
    padding:0 12px 92px !important;
    box-sizing:border-box !important;
}
.top-header{
    width:calc(100% + 24px) !important;
    height:64px !important;
    margin-left:-12px !important;
    margin-right:-12px !important;
    padding:0 14px !important;
    box-sizing:border-box !important;
}
.header-brand{gap:9px !important;}
.header-logo{
    width:36px !important;
    height:36px !important;
    border-radius:10px !important;
}
.header-title{font-size:16px !important;letter-spacing:1px !important;}
.header-user-wrap{gap:3px !important;}
.header-user{font-size:9px !important;letter-spacing:1.5px !important;}
.balance-badge{
    min-width:0 !important;
    height:auto !important;
    padding:7px 9px !important;
    border-radius:10px !important;
    font-size:11px !important;
    letter-spacing:0 !important;
}
.balance-money{font-size:13px !important;margin-right:3px !important;}
.page{padding-top:20px !important;}
.welcome{margin-bottom:14px !important;}
.welcome-small{font-size:11px !important;letter-spacing:1.5px !important;}
.welcome-small:first-letter{font-size:initial !important;}
.welcome h1{margin:5px 0 0 !important;font-size:24px !important;line-height:1.15 !important;}
.update-banner{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:10px !important;
    padding:13px 14px !important;
    margin-bottom:17px !important;
    border-radius:14px !important;
}
.update-title{font-size:11px !important;line-height:1.2 !important;}
.update-sub{font-size:9px !important;margin-top:3px !important;}
.join-btn{
    width:auto !important;
    height:auto !important;
    margin-top:0 !important;
    padding:8px 11px !important;
    border-radius:8px !important;
    font-size:10px !important;
}
.stats-grid{
    grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    gap:9px !important;
    margin-bottom:17px !important;
}
.stat-card{
    min-height:112px !important;
    padding:13px !important;
    border-radius:14px !important;
    box-sizing:border-box !important;
}
.stat-icon{font-size:22px !important;margin-bottom:9px !important;}
.stat-value{font-size:18px !important;line-height:1 !important;}
.stat-name{margin-top:8px !important;font-size:9px !important;letter-spacing:1px !important;line-height:1.1 !important;}
.dashboard-grid{gap:17px !important;}
.panel{padding:14px !important;border-radius:15px !important;}
.challenge-panel{padding-bottom:17px !important;}
.panel-title{font-size:16px !important;line-height:1.2 !important;}
.challenge-heading{margin-bottom:5px !important;}
.challenge-header{margin-bottom:12px !important;}
.challenge-date{font-size:10px !important;line-height:1.25 !important;}
.live{min-width:58px !important;height:27px !important;padding:0 9px !important;font-size:9px !important;letter-spacing:1px !important;}
.live-dot{border-top-width:4px !important;border-bottom-width:4px !important;border-left-width:7px !important;}
.podium{height:108px !important;gap:8px !important;}
.podium-user{font-size:24px !important;margin-bottom:5px !important;}
.podium-name{font-size:9px !important;margin-bottom:5px !important;}
.reward{font-size:12px !important;gap:2px !important;}
.reward b{font-size:13px !important;}
.top5-panel{padding:16px 14px 19px !important;}
.top5-panel .panel-heading{margin-bottom:12px !important;}
.top5-panel .panel-title{font-size:13px !important;letter-spacing:2px !important;}
.reseller-list{gap:8px !important;}
.reseller{
    min-height:58px !important;
    padding:0 11px !important;
    gap:9px !important;
    border-radius:13px !important;
}
.rank{width:32px !important;height:32px !important;font-size:11px !important;}
.reseller-name{font-size:13px !important;line-height:1.1 !important;}
.reseller-sold{margin-top:4px !important;font-size:9px !important;}
.reseller-amount{font-size:12px !important;}
.reseller-keys{min-width:36px !important;font-size:9px !important;}
.bottom-nav{
    left:50% !important;
    right:auto !important;
    bottom:0 !important;
    transform:translateX(-50%) !important;
    width:min(430px,100%) !important;
    height:auto !important;
    min-height:62px !important;
    padding:5px !important;
    border-radius:15px !important;
    box-sizing:border-box !important;
    gap:2px !important;
}
.nav-btn{
    height:52px !important;
    padding:5px 2px !important;
    border-radius:10px !important;
    font-size:8px !important;
    letter-spacing:.5px !important;
}
.nav-icon{
    width:22px !important;
    height:22px !important;
    margin-bottom:3px !important;
    font-size:17px !important;
}
.nav-icon svg{width:22px !important;height:22px !important;}

/* Desktop: keep the exact same phone-sized canvas instead of stretching it. */
@media (min-width:801px){
    .app-shell{max-width:430px !important;padding-left:12px !important;padding-right:12px !important;}
    .top-header{width:calc(100% + 24px) !important;margin-left:-12px !important;margin-right:-12px !important;padding-left:14px !important;padding-right:14px !important;}
    .bottom-nav{width:430px !important;}
}

/* Small phones: shrink only the outer spacing, never enlarge the dashboard. */
@media (max-width:360px){
    .app-shell{padding-left:10px !important;padding-right:10px !important;}
    .top-header{width:calc(100% + 20px) !important;margin-left:-10px !important;margin-right:-10px !important;padding-left:11px !important;padding-right:11px !important;}
    .header-title{font-size:14px !important;}
    .header-user{font-size:8px !important;letter-spacing:1px !important;}
    .balance-badge{font-size:10px !important;padding:6px 7px !important;}
    .stats-grid{gap:7px !important;}
    .stat-card{padding:11px !important;min-height:105px !important;}
    .stat-name{font-size:8px !important;}
    .reseller{padding:0 9px !important;gap:7px !important;}
    .reseller-name{font-size:12px !important;}
}



/* =========================================================
   STORE - VIDEO MATCH
   ========================================================= */
.store-buy-panel{
    width:100%;
    max-width:760px;
    margin:0 auto;
    padding:28px 30px 30px;
    box-sizing:border-box;
    border-radius:30px;
    background:linear-gradient(145deg,#1d2b45 0%,#17243b 100%);
    border:1px solid rgba(91,116,160,.35);
    box-shadow:0 20px 45px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.025);
}
.store-heading{
    display:flex; align-items:center; gap:15px;
    color:#d7ad31; font-size:20px; font-weight:900;
    letter-spacing:5px; margin-bottom:38px;
}
.store-heading:after{
    content:""; height:2px; flex:1;
    background:linear-gradient(90deg,rgba(83,108,151,.35),transparent);
}
.store-key{font-size:27px; letter-spacing:0;}
.store-field{margin-bottom:30px;}
.store-field-last{margin-bottom:0;}
.store-field label{
    display:block; margin-bottom:15px;
    color:#aab4ca; font-size:17px; font-weight:800;
    letter-spacing:3px;
}
.store-field select{
    width:100%; height:70px; padding:0 48px 0 31px;
    border-radius:20px; border:1px solid rgba(77,101,145,.34);
    outline:none; appearance:auto; -webkit-appearance:auto;
    background:#0c1529; color:#c9cbd0;
    font-size:18px; font-weight:500;
    box-sizing:border-box; cursor:pointer;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.015);
}
.store-field:first-of-type select{
    border:2px solid #b9952e;
    box-shadow:0 0 0 5px rgba(185,149,46,.08), inset 0 0 0 1px rgba(255,220,95,.05);
}
.store-field select:disabled{color:#b5b6bb; opacity:1; cursor:not-allowed;}
.store-field select option{background:#0c1529; color:#e5e7eb;}
.store-order{
    display:none; margin-top:22px; padding:18px;
    border:1px solid rgba(185,149,46,.35); border-radius:16px;
    background:rgba(8,15,30,.6);
}
.store-order.show{display:block;}
.store-order div{display:flex;justify-content:space-between;gap:15px;margin-bottom:9px;color:#9da8bd;font-size:12px;}
.store-order strong{color:#e4bc35;}
.store-order button{width:100%;height:44px;border:0;border-radius:12px;background:#b9952e;color:#0a1020;font-weight:900;margin-top:8px;cursor:pointer;}

@media(max-width:800px){
    .store-buy-panel{padding:27px 30px 29px;border-radius:30px;max-width:none;}
}
@media(max-width:520px){
    .store-buy-panel{padding:27px 24px 29px;border-radius:30px;}
    .store-heading{font-size:16px;letter-spacing:4px;gap:12px;margin-bottom:35px;}
    .store-key{font-size:23px;}
    .store-field{margin-bottom:30px;}
    .store-field label{font-size:15px;letter-spacing:2.5px;margin-bottom:13px;}
    .store-field select{height:70px;border-radius:18px;padding:0 20px;font-size:18px;}
}
@media(max-width:360px){
    .store-buy-panel{padding:23px 18px 24px;}
    .store-heading{font-size:14px;letter-spacing:3px;margin-bottom:28px;}
    .store-field label{font-size:12px;}
    .store-field select{height:62px;font-size:16px;}
}

/* LOGIN ISOLATION: dashboard and bottom navigation must never appear before login. */
#app:not(.visible){
    display:none !important;
    visibility:hidden !important;
    pointer-events:none !important;
}
#loginScreen{
    z-index:10000 !important;
}
#app.visible .bottom-nav{
    display:grid !important;
}
#app:not(.visible) .bottom-nav{
    display:none !important;
}
/* ADMIN ISOLATION: reseller-only navigation is NEVER part of the admin UI.
   Force-hide it even if an inline display value or stale UI state exists. */
body.admin-mode #app{
    display:none !important;
    visibility:hidden !important;
    pointer-events:none !important;
}
body.admin-mode #app .bottom-nav,
body.admin-mode .bottom-nav,
#adminApp.visible ~ #app .bottom-nav{
    display:none !important;
    visibility:hidden !important;
    height:0 !important;
    min-height:0 !important;
    max-height:0 !important;
    overflow:hidden !important;
    pointer-events:none !important;
}

/* MY KEY / ORDER CARDS — compact mobile layout matching the supplied reference. */
.mykey-list{
    display:flex;
    flex-direction:column;
    gap:10px;
    margin-top:12px;
}
.mykey-order-card{
    background:rgba(8,15,30,.82);
    border:1px solid rgba(212,175,55,.18);
    border-radius:10px;
    padding:17px 17px 15px;
    box-sizing:border-box;
    box-shadow:0 8px 22px rgba(0,0,0,.16);
}
.mykey-product{
    color:#f1f5f9;
    font-size:16px;
    line-height:1.35;
    font-weight:800;
    margin-bottom:12px;
}
.mykey-product::first-letter{font-size:18px;}
.mykey-license{
    color:#d8ad32;
    font-size:14px;
    line-height:1.7;
    word-break:break-all;
    font-family:monospace;
    margin-bottom:13px;
}
.mykey-meta-row{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
}
.mykey-chip{
    min-height:38px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0 13px;
    box-sizing:border-box;
    border-radius:9px;
    border:1px solid rgba(148,163,184,.25);
    background:rgba(30,41,59,.62);
    color:#aeb8cb;
    font-size:13px;
    font-weight:700;
    white-space:nowrap;
}
.mykey-source-api{
    border-color:rgba(99,102,241,.8);
    color:#9da3ff;
}
.mykey-source-web{
    border-color:rgba(56,189,248,.45);
    color:#a9b7ca;
}
.mykey-copy{
    width:43px;
    height:38px;
    padding:0;
    border-radius:9px;
    border:1px solid rgba(148,163,184,.25);
    background:rgba(30,41,59,.62);
    color:#f1f5f9;
    font-size:18px;
    cursor:pointer;
}
.mykey-purchased{
    margin-top:12px;
    color:#a4aec1;
    font-size:13px;
}
.mykey-order-code{
    margin-top:7px;
    color:#64748b;
    font-size:10px;
    font-family:monospace;
}
@media(max-width:420px){
    .mykey-order-card{padding:15px 14px 14px;}
    .mykey-product{font-size:15px;}
    .mykey-license{font-size:13px;}
    .mykey-chip{min-height:36px;padding:0 11px;font-size:12px;}
    .mykey-copy{height:36px;width:40px;}
}

/* STORE SCREENSHOT MATCH — exact compact proportions from supplied references */
.store-buy-panel{
    width:100% !important;
    max-width:none !important;
    margin:0 !important;
    padding:27px 24px 29px !important;
    border-radius:30px !important;
    box-sizing:border-box !important;
}
.store-heading{
    font-size:16px !important;
    letter-spacing:4px !important;
    gap:12px !important;
    margin-bottom:35px !important;
    line-height:1.15 !important;
}
.store-key{font-size:23px !important;}
.store-field{margin-bottom:30px !important;}
.store-field label{
    font-size:14px !important;
    letter-spacing:2.5px !important;
    margin-bottom:13px !important;
    line-height:1.15 !important;
}
.store-field select{
    height:58px !important;
    min-height:58px !important;
    border-radius:14px !important;
    padding:0 18px !important;
    font-size:18px !important;
    line-height:58px !important;
}
.store-field:last-of-type{margin-bottom:0 !important;}
.store-order{
    margin-top:15px !important;
    padding:0 !important;
    border:0 !important;
    background:transparent !important;
    box-shadow:none !important;
}
.store-order div{display:none !important;}
.store-order button{
    height:52px !important;
    border-radius:14px !important;
    margin-top:0 !important;
    font-size:16px !important;
    color:#fff !important;
    background:linear-gradient(135deg,#e5b92f,#b88e1d) !important;
    box-shadow:0 12px 24px rgba(210,170,43,.20) !important;
}

@media (min-width:801px){
    .store-buy-panel{width:100% !important;max-width:none !important;}
}
@media (max-width:360px){
    .store-buy-panel{padding:23px 18px 24px !important;}
    .store-heading{font-size:14px !important;letter-spacing:3px !important;margin-bottom:28px !important;}
    .store-key{font-size:20px !important;}
    .store-field label{font-size:12px !important;}
    .store-field select{height:54px !important;min-height:54px !important;font-size:16px !important;line-height:54px !important;}
    .store-order button{height:49px !important;font-size:15px !important;}
}


/* FINAL STORE COMPACT TYPOGRAPHY + CONTROLS */
#storePage .store-buy-panel{
    padding:20px 18px 22px !important;
}
#storePage .store-heading{
    font-size:13px !important;
    letter-spacing:2.6px !important;
    gap:9px !important;
    margin-bottom:23px !important;
}
#storePage .store-key{
    font-size:18px !important;
}
#storePage .store-field{
    margin-bottom:18px !important;
}
#storePage .store-field label{
    font-size:11px !important;
    letter-spacing:2px !important;
    margin-bottom:8px !important;
}
#storePage .store-field select{
    height:52px !important;
    min-height:52px !important;
    border-radius:14px !important;
    padding:0 16px !important;
    font-size:14px !important;
    line-height:52px !important;
}
#storePage .store-order button{
    height:42px !important;
    border-radius:11px !important;
    margin-top:6px !important;
    font-size:14px !important;
}
@media(max-width:360px){
    #storePage .store-buy-panel{
        padding:18px 15px 20px !important;
    }
    #storePage .store-heading{
        font-size:12px !important;
        margin-bottom:20px !important;
    }
    #storePage .store-key{
        font-size:17px !important;
    }
    #storePage .store-field{
        margin-bottom:16px !important;
    }
    #storePage .store-field select{
        height:49px !important;
        min-height:49px !important;
        font-size:13px !important;
        line-height:49px !important;
    }
    #storePage .store-order button{
        height:40px !important;
        font-size:13px !important;
    }
}

/* FINAL STORE PANEL SIZE/TOP POSITION — keep changes isolated to Store only */
#storePage{
    padding-top:10px !important;
}
#storePage .store-buy-panel{
    width:92% !important;
    max-width:none !important;
    margin:0 auto !important;
    padding:24px 22px 26px !important;
    border-radius:26px !important;
}
#storePage .store-heading{
    margin-bottom:30px !important;
}
#storePage .store-field{
    margin-bottom:24px !important;
}
#storePage .store-field label{
    margin-bottom:11px !important;
}
#storePage .store-field select{
    height:60px !important;
    min-height:60px !important;
    border-radius:16px !important;
}
@media(max-width:360px){
    #storePage{padding-top:8px !important;}
    #storePage .store-buy-panel{
        width:92% !important;
        padding:20px 17px 22px !important;
        border-radius:23px !important;
    }
    #storePage .store-field select{
        height:54px !important;
        min-height:54px !important;
    }
}

</style>

<style id="final-ui-fixes">
/* FINAL UI FIXES — compact top-aligned sections, no PSP app buttons, gold-only accent */
html{scroll-behavior:auto !important;}
#storePage,#depositPage,#keyPage,#apiPage{padding-top:8px !important;}
#storePage.active,#depositPage.active,#keyPage.active,#apiPage.active{transform:none !important;}
.paytm-app-title,.paytm-app-buttons,.paytm-app-btn{display:none !important;}
.reseller-empty{color:#7e8ca5;text-align:center;font:700 9px ui-monospace,monospace;padding:14px 8px;}
/* BRANDING + TOP-ALIGNED SECTION FIX */
.login-logo img{width:100%;height:100%;object-fit:cover;display:block;position:relative;z-index:2}
.header-logo img{width:100%;height:100%;object-fit:cover;display:block}
#panelLogoFallback{font-size:26px;color:#f2cb4d;text-shadow:0 0 12px rgba(245,215,110,.7)}
/* Keep every user section immediately under the site header. */
#app .page,
#app .page.active,
#storePage,#depositPage,#keyPage,#apiPage,#apiProductsPage,#apiDocsPage{padding-top:6px !important;margin-top:0 !important;}
#app .page.active{min-height:0 !important;align-content:flex-start !important;display:block !important;}
#storePage .store-buy-panel,#depositPage .deposit-wrap,#keyPage .center-panel,#apiPage .api-video-wrap,#apiProductsPage .api-docs-wrap,#apiDocsPage .api-docs-wrap{margin-top:0 !important;}
/* Do not let old large-spacing rules push content down. */
#homePage{padding-top:8px !important;}
#storePage,#depositPage,#keyPage,#apiPage,#apiProductsPage,#apiDocsPage{scroll-margin-top:0 !important;}
/* REFRESH / NAV VISIBILITY HARDENING — keep the existing controls rendered
   after async refreshes and page transitions; never alter their layout. */
#app.visible .bottom-nav .nav-btn{visibility:visible;}
#app.visible .page.active .nav-btn,#app.visible .page.active button,#app.visible .page.active a{max-width:100%;}

</style>
</head>

<body>

<!-- =========================================================
     SPLASH SCREEN
     ========================================================= -->

<div id="splash">
    <div class="splash-inner">
        <div class="splash-logo" id="splashLogoBox">
            <?php if ($splashLogo !== ''): ?>
                <img id="splashLogoImage" src="<?= htmlspecialchars($splashLogo, ENT_QUOTES, "UTF-8") ?>" alt="Loading screen logo">
            <?php else: ?>
                <div class="splash-star" id="splashLogoFallback">★</div>
            <?php endif; ?>
        </div>

        <div class="splash-kicker" id="splashKicker"><?= htmlspecialchars($splashKicker, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="splash-welcome" id="splashWelcome"><?= htmlspecialchars($splashWelcome, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="splash-name" id="splashBrandName"><?= htmlspecialchars($splashName ?: 'FLASH STAR', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="splash-portal" id="splashPortal"><?= htmlspecialchars($splashPortal, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="splash-subtitle" id="splashSubtitle"><?= htmlspecialchars($splashSubtitle, ENT_QUOTES, 'UTF-8') ?></div>

        <div class="loading-line" aria-hidden="true">
            <div class="loading-progress"></div>
        </div>

        <div class="loading-text" id="splashLoadingText"><?= htmlspecialchars($splashLoadingText, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
</div>


<!-- =========================================================
     LOGIN SCREEN
     ========================================================= -->

<section id="loginScreen">

    <div class="login-wrapper">

        <div class="login-logo">
            <?php if ($loginLogo !== ''): ?>
                <img id="loginLogoImage" src="<?= htmlspecialchars($loginLogo, ENT_QUOTES, "UTF-8") ?>" alt="Login logo">
            <?php else: ?>
                <div class="login-star" id="loginLogoFallback">★</div>
            <?php endif; ?>
        </div>

        <div class="login-brand" id="loginBrandName"><?= htmlspecialchars($loginName ?: 'FLASH STAR', ENT_QUOTES, 'UTF-8') ?></div>

        <div class="login-subtitle" id="loginSubtitle">
            <?= htmlspecialchars($loginSubtitle, ENT_QUOTES, 'UTF-8') ?>
        </div>


        <div class="login-card">

            <div class="auth-badge" id="loginBadge">
                <?= htmlspecialchars($loginBadge, ENT_QUOTES, 'UTF-8') ?>
            </div>


            <form id="loginForm">

                <div class="login-field">

                    <label class="login-label" id="usernameLabel">
                        <?= htmlspecialchars($loginUsernameLabel, ENT_QUOTES, 'UTF-8') ?>
                    </label>

                    <div class="login-input-wrap">

                        <input
                            id="usernameInput"
                            class="login-input"
                            type="text"
                            placeholder="<?= htmlspecialchars($loginUsernamePlaceholder, ENT_QUOTES, 'UTF-8') ?>"
                            autocomplete="username"
                            required
                        >

                    </div>

                </div>


                <div class="login-field">

                    <label class="login-label" id="passwordLabel">
                        <?= htmlspecialchars($loginPasswordLabel, ENT_QUOTES, 'UTF-8') ?>
                    </label>

                    <div class="login-input-wrap">

                        <input
                            id="passwordInput"
                            class="login-input password-input"
                            type="password"
                            placeholder="<?= htmlspecialchars($loginPasswordPlaceholder, ENT_QUOTES, 'UTF-8') ?>"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="eye-btn"
                            id="eyeButton"
                            aria-label="Show password"
                        >

                            <svg
                                id="eyeIcon"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>
                                <circle cx="12" cy="12" r="2.5"/>
                            </svg>

                        </button>

                    </div>

                </div>


                <button
                    class="login-btn"
                    type="submit"
                >
                    <?= htmlspecialchars($loginButtonText, ENT_QUOTES, 'UTF-8') ?>
                </button>


                <div
                    class="login-message"
                    id="loginMessage"
                >
                    <?= htmlspecialchars($loginMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>

            </form>

            <div class="login-toggle-row" id="loginToggleRow" style="text-align:center;margin-top:10px">
                <span style="opacity:.75">New here?</span>
                <button type="button" id="showSignupBtn" class="login-link-btn" style="background:none;border:none;color:inherit;text-decoration:underline;cursor:pointer;font:inherit;margin-left:6px">Create an account</button>
            </div>

            <form id="signupForm" style="display:none">
                <div class="login-field">
                    <label class="login-label">Username</label>
                    <div class="login-input-wrap">
                        <input id="signupUsername" class="login-input" type="text" placeholder="Choose a username" autocomplete="username" required>
                    </div>
                </div>
                <div class="login-field">
                    <label class="login-label">Mobile Number</label>
                    <div class="login-input-wrap">
                        <input id="signupMobile" class="login-input" type="tel" placeholder="10-digit mobile number" autocomplete="tel" maxlength="10" required>
                    </div>
                </div>
                <div class="login-field">
                    <label class="login-label">Email</label>
                    <div class="login-input-wrap">
                        <input id="signupEmail" class="login-input" type="email" placeholder="you@example.com" autocomplete="email" required>
                    </div>
                </div>
                <div class="login-field">
                    <label class="login-label">Password</label>
                    <div class="login-input-wrap">
                        <input id="signupPassword" class="login-input password-input" type="password" placeholder="At least 8 characters" autocomplete="new-password" required>
                    </div>
                </div>
                <button class="login-btn" type="submit">Create Account →</button>
                <div class="login-message" id="signupMessage"></div>
            </form>

            <div class="login-toggle-row" id="signupToggleRow" style="display:none;text-align:center;margin-top:10px">
                <span style="opacity:.75">Already have an account?</span>
                <button type="button" id="showLoginBtn" class="login-link-btn" style="background:none;border:none;color:inherit;text-decoration:underline;cursor:pointer;font:inherit;margin-left:6px">Log in</button>
            </div>

            <div class="login-footer">
                <?= htmlspecialchars($loginFooter, ENT_QUOTES, 'UTF-8') ?>
            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     ADMIN APPLICATION (same login screen, role-aware after auth)
     ========================================================= -->
<div id="adminModal" class="admin-modal" style="display:none" onclick="if(event.target===this)closeAdminModal()">
  <div class="admin-modal-box">
    <button type="button" class="admin-modal-close" onclick="closeAdminModal()">✕</button>
    <div id="adminModalBody"></div>
  </div>
</div>
<div id="adminApp" aria-hidden="true">
  <div class="admin-shell">
    <div class="admin-top">
      <div class="admin-brand"><div class="admin-logo" id="adminLogoBox">
        <?php if ($adminLogo !== ''): ?><img id="adminLogoImage" src="<?= htmlspecialchars($adminLogo, ENT_QUOTES, 'UTF-8') ?>" alt="Admin logo"><?php else: ?><div id="adminLogoFallback">★</div><?php endif; ?>
      </div><div><div class="admin-title" id="adminBrandName"><?= htmlspecialchars($adminName ?: 'KEYS PANEL ADMIN', ENT_QUOTES, 'UTF-8') ?></div><div class="admin-sub">Administrator control center</div></div></div>
      <button type="button" class="admin-logout" onclick="logout()">LOG OUT</button>
    </div>

    <div id="adminMaintBanner" class="admin-maint-banner" style="display:none">⚠ GLOBAL MAINTENANCE IS ON — resellers cannot log in or purchase right now.</div>

    <div class="admin-tabs" id="adminTabs">
      <button type="button" class="admin-tab-btn active" data-tab="users" onclick="adminSwitchTab('users')">Users</button>
      <button type="button" class="admin-tab-btn" data-tab="store" onclick="adminSwitchTab('store')">Store / Products</button>
      <button type="button" class="admin-tab-btn" data-tab="wallet" onclick="adminSwitchTab('wallet')">Wallet</button>
      <button type="button" class="admin-tab-btn" data-tab="orders" onclick="adminSwitchTab('orders')">Orders</button>
      <button type="button" class="admin-tab-btn" data-tab="api" onclick="adminSwitchTab('api')">API</button>
      <button type="button" class="admin-tab-btn" data-tab="apireports" onclick="adminSwitchTab('apireports')">API Reports</button>
      <button type="button" class="admin-tab-btn" data-tab="maintenance" onclick="adminSwitchTab('maintenance')">Maintenance</button>
      <button type="button" class="admin-tab-btn" data-tab="support" onclick="adminSwitchTab('support')">Support</button>
      <button type="button" class="admin-tab-btn" data-tab="settings" onclick="adminSwitchTab('settings')">Settings</button>
      <button type="button" class="admin-tab-btn" data-tab="logs" onclick="adminSwitchTab('logs')">Logs</button>
    </div>

    <!-- USERS -->
    <div class="admin-tab-panel active" id="adminTab-users">
      <div class="admin-grid">
        <div class="admin-card">
          <div class="admin-card-title">Create New User</div>
          <div class="admin-card-note">Generate a unique username and password. Only accounts created here and stored in the database can log in — the generated credentials work immediately on the same login form.</div>
          <div class="admin-field"><label>USERNAME</label><input id="newUserUsername" type="text" autocomplete="off" placeholder="Generated username"></div>
          <div class="admin-field"><label>PASSWORD</label><input id="newUserPassword" type="text" autocomplete="off" placeholder="Generated password"></div>
          <div class="admin-actions"><button type="button" class="admin-secondary" onclick="generateUserCredentials()">GENERATE</button><button type="button" class="admin-primary" onclick="createNewUser()">CREATE USER</button></div>
          <div id="createdCredentials" class="admin-credentials"><div style="font-size:11px;color:#bbf7d0;font-weight:900;margin-bottom:5px;">USER CREATED — SAVE THESE DETAILS</div><div class="cred"><span>Username</span><code id="createdUsername">—</code></div><div class="cred"><span>Password</span><code id="createdPassword">—</code></div></div>
        </div>
        <div class="admin-card">
          <div class="admin-card-title">Admin Account</div>
          <div class="admin-card-note">Logged in as the protected administrator account. Admin credentials are never shown to normal users.</div>
          <div style="font-size:15px;color:#f5d76e;font-weight:900;overflow-wrap:anywhere;">@<span id="adminUsername">admin</span></div>
          <div style="font-size:11px;color:#94a3b8;line-height:1.5;margin-top:8px;">Role: Administrator<br>Authentication: Database + password hash</div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">User Accounts</div>
          <div class="admin-card-note">Enable, disable, ban, unban, reset password, add/remove balance, view details or delete a user account.</div>
          <div class="admin-field" style="margin-top:10px"><input id="adminUserSearch" type="search" autocomplete="off" placeholder="Search user by username…" oninput="filterAdminUsers()" aria-label="Search user by username"></div>
          <div id="adminUsersList" class="admin-list"><div class="admin-empty">Loading users…</div></div>
        </div>
      </div>
    </div>

    <!-- STORE / PRODUCT MANAGEMENT — ONE LANDING PAGE -->
    <div class="admin-tab-panel" id="adminTab-store">
      <div class="admin-grid">
        <div class="admin-card wide">
          <div class="admin-card-title">Store / Product Management</div>
          <div class="admin-card-note">Manage local products, durations, prices and license-key inventory from this one landing page. Each duration has its own plan ID and separate stock.</div>
        </div>

        <div class="admin-card">
          <div class="admin-card-title">1. Create Category</div>
          <div class="admin-field"><label>NAME</label><input id="adminCategoryName" placeholder="Category name"></div>
          <div class="admin-field"><label>SORT ORDER</label><input id="adminCategorySort" type="number" value="0"></div>
          <div class="admin-actions"><button class="admin-primary" type="button" onclick="adminAddCategory()">ADD CATEGORY</button></div>
        </div>

        <div class="admin-card">
          <div class="admin-card-title">2. Create Product</div>
          <div class="admin-field"><label>CATEGORY</label><select id="adminProductCategory"></select></div>
          <div class="admin-field"><label>PRODUCT NAME</label><input id="adminProductName" placeholder="Product name"></div>
          <div class="admin-field"><label>DESCRIPTION</label><input id="adminProductDescription" placeholder="Optional description"></div>
        </div>

        <div class="admin-card wide">
          <div class="admin-card-title">3. Duration / Price / Stock Mode</div>
          <div class="admin-card-note">Each duration has its own stock mode. LOCAL uses the keys you add. API mode keeps an internal provider stock value of 99 and does not expose a stock count to resellers.</div>
          <div id="createPlanBlocks"></div>
          <div class="admin-actions" style="margin-top:10px">
            <button class="admin-secondary" type="button" onclick="addCreateDurationBlock()">+ ADD DURATION</button>
            <button class="admin-primary" type="button" onclick="adminAddProduct()">CREATE PRODUCT</button>
          </div>
        </div>

        <div class="admin-card wide">
          <div class="admin-card-title">4. Add Duration to Existing Product</div>
          <div class="admin-card-note">Already-created product है? Product को दोबारा create करने की जरूरत नहीं। यहाँ product चुनकर नया duration, price और stock mode add करें। API mode में नीचे साफ-साफ Product ID / Variant ID डालें; LOCAL mode में license keys डालें।</div>
          <div class="admin-management-grid" style="margin-top:10px">
            <select id="adminPlanProduct" onchange="toggleAddPlanMode()"></select>
            <input id="adminPlanDuration" placeholder="Duration e.g. 10 Days">
          </div>
          <div class="admin-management-grid" style="margin-top:8px">
            <input id="adminPlanPrice" type="number" min="0" step="0.01" placeholder="Selling price ₹">
            <select id="adminPlanStockMode" onchange="toggleAddPlanMode()"><option value="local">LOCAL STORE</option><option value="keyspanel">KEYSPANEL API</option><option value="dripstore">DRIPSTORE API</option></select>
          </div>
          <div id="adminPlanProviderWrap" class="admin-field" style="margin-top:8px;display:none">
            <label>KEYSPANEL VARIANT ID</label>
            <input id="adminPlanProviderId" placeholder="Enter KEYSPANEL Variant ID">
          </div>
          <div id="adminPlanDripstoreWrap" class="admin-field" style="margin-top:8px;display:none">
            <label>DRIPSTORE VARIANT ID</label>
            <input id="adminPlanDripstoreId" placeholder="Enter DRIPSTORE Variant ID">
          </div>
          <div id="adminPlanKeysWrap" class="admin-field" style="margin-top:8px">
            <label>LOCAL LICENSE KEYS</label>
            <textarea id="adminPlanKeys" placeholder="Paste multiple license keys, one per line"></textarea>
          </div>
          <div class="admin-actions"><button class="admin-primary" type="button" onclick="adminAddPlan()">ADD DURATION</button></div>
        </div>

        <div class="admin-card wide">
          <div class="admin-card-title">Existing Products</div>
          <div id="adminProductsList" class="admin-list"><div class="admin-empty">Loading…</div></div>
        </div>

        <div class="admin-card wide">
          <div class="admin-card-title">Existing Durations / Plans / Stock</div>
          <div id="adminPlansList" class="admin-list"><div class="admin-empty">Loading…</div></div>
        </div>

        <div class="admin-card wide">
          <div class="admin-card-title">Categories</div>
          <div id="adminCategoriesList" class="admin-list"><div class="admin-empty">Loading…</div></div>
        </div>

        <div class="admin-card wide">
          <div class="admin-card-title">Duration Key Management</div>
          <div class="admin-card-note">Select one exact duration. Only that duration's keys are shown below.</div>
          <div class="admin-management-grid">
            <select id="adminKeyPlan" onchange="loadAdminPlanKeys()"></select>
          </div>
          <textarea id="adminKeyBulk" placeholder="Paste multiple license keys, one per line" style="width:100%;margin-top:10px;min-height:100px;background:#0b0f19;border:1px solid rgba(212,175,55,.25);border-radius:10px;color:#fff;padding:10px;font-family:monospace;font-size:12px;"></textarea>
          <div class="admin-actions">
            <button class="admin-primary" type="button" onclick="adminAddKeysBulk()">ADD MULTIPLE KEYS</button>
            <button class="admin-secondary" type="button" onclick="adminClearKeys()">CLEAR UNSOLD KEYS</button>
          </div>
          <div id="adminKeyStockLine" style="font-size:12px;color:#94a3b8;margin-top:8px;"></div>
          <div id="adminKeysList" class="admin-list" style="margin-top:10px"><div class="admin-empty">Select a duration above.</div></div>
        </div>
      </div>
    </div>

    <!-- WALLET -->
    <div class="admin-tab-panel" id="adminTab-wallet">
      <div class="admin-grid">
        <div class="admin-card wide">
          <div class="admin-card-title">Wallet — Add / Remove Balance</div>
          <div class="admin-card-note">Every change writes a ledger row automatically. Balances can never go negative.</div>
          <div id="adminWalletList" class="admin-list"><div class="admin-empty">Loading users…</div></div>
        </div>
      </div>
    </div>

    <!-- ORDERS -->
    <div class="admin-tab-panel" id="adminTab-orders">
      <div class="admin-grid">
        <div class="admin-card wide">
          <div class="admin-card-title">All Orders</div>
          <div id="adminOrdersList" class="admin-list"><div class="admin-empty">Loading…</div></div>
          <div class="admin-actions"><button class="admin-secondary" type="button" onclick="adminOrdersPage(-1)">◀ PREV</button><span id="adminOrdersPageLabel" style="align-self:center;font-size:12px;color:#94a3b8;">Page 1</span><button class="admin-secondary" type="button" onclick="adminOrdersPage(1)">NEXT ▶</button></div>
        </div>
      </div>
    </div>

    <!-- API -->
    <div class="admin-tab-panel" id="adminTab-api">
      <div class="admin-grid">
        <div class="admin-card wide">
          <div class="admin-card-title">Reseller API</div>
          <div class="admin-card-note">Real, database-backed API. Resellers generate their own key from their dashboard's API page (X-API-Key header). Endpoints: <code>v1_balance</code>, <code>v1_products</code>, <code>v1_order</code>, <code>v1_order_status</code>. Orders always use the exact variation's own key stock; out-of-stock never charges the wallet, and a failed key delivery after a successful charge is automatically refunded.</div>
        </div>
      </div>
    </div>

    <!-- API REPORTS -->
    <div class="admin-tab-panel" id="adminTab-apireports">
      <div class="admin-grid">
        <div class="admin-card wide">
          <div class="admin-card-title">API Reports</div>
          <div class="admin-management-grid">
            <input id="apiRepDateFrom" type="date">
            <input id="apiRepDateTo" type="date">
            <input id="apiRepUsername" placeholder="API key / username">
            <select id="apiRepStatus"><option value="">All statuses</option><option value="success">Success</option><option value="failed">Failed</option></select>
            <input id="apiRepOrderCode" placeholder="Order ID / code">
          </div>
          <div class="admin-actions"><button class="admin-primary" type="button" onclick="loadApiReports(1)">APPLY FILTERS</button></div>
          <div id="apiReportsSummary" class="admin-summary-cards"></div>
          <div id="apiReportsList" class="admin-list" style="margin-top:10px"><div class="admin-empty">Loading…</div></div>
          <div class="admin-actions"><button class="admin-secondary" type="button" onclick="apiReportsPage(-1)">◀ PREV</button><span id="apiReportsPageLabel" style="align-self:center;font-size:12px;color:#94a3b8;">Page 1</span><button class="admin-secondary" type="button" onclick="apiReportsPage(1)">NEXT ▶</button></div>
        </div>
      </div>
    </div>

    <!-- MAINTENANCE -->
    <div class="admin-tab-panel" id="adminTab-maintenance">
      <div class="admin-grid">
        <div class="admin-card wide">
          <div class="admin-card-title">Global Website Maintenance</div>
          <div class="admin-card-note">When ON, resellers can log in but are shown a full-screen maintenance lock and cannot access the store, wallet, keys, API or other user features. Admin login and this panel always stay accessible.</div>
          <div class="admin-actions"><button id="globalMaintBtn" class="admin-primary" type="button" onclick="toggleGlobalMaintenance()">TURN MAINTENANCE ON</button></div>
          <div class="admin-field" style="margin-top:14px"><label>MAINTENANCE MESSAGE</label><textarea id="globalMaintMessage" style="width:100%;min-height:70px;background:#0b0f19;border:1px solid rgba(212,175,55,.25);border-radius:10px;color:#fff;padding:10px;"></textarea></div>
          <div class="admin-actions"><button class="admin-secondary" type="button" onclick="saveGlobalMaintMessage()">SAVE MESSAGE</button></div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">Per-Product Maintenance</div>
          <div class="admin-card-note">Puts a single product's purchasing on hold while keeping it visible in the store as "UNDER MAINTENANCE".</div>
          <div id="adminMaintProductsList" class="admin-list"><div class="admin-empty">Loading…</div></div>
        </div>
      </div>
    </div>

    <!-- SUPPORT -->
    <div class="admin-tab-panel" id="adminTab-support">
      <div class="admin-grid">
        <div class="admin-card wide">
          <div class="admin-card-title">Support URL / Button</div>
          <div class="admin-card-note">Shown to resellers as a "Support" button/link throughout the app.</div>
          <div class="admin-field"><label>SUPPORT URL</label><input id="supportUrlInput" placeholder="https://t.me/yoursupport"></div>
          <div class="admin-actions"><button class="admin-primary" type="button" onclick="saveSupportUrl()">SAVE SUPPORT URL</button></div>
        </div>
      </div>
    </div>

    <!-- SETTINGS -->
    <div class="admin-tab-panel" id="adminTab-settings">
      <div class="admin-grid">
        <div class="admin-card wide">
          <div class="admin-card-title">Admin Panel Branding</div>
          <div class="admin-card-note">Change the Admin control-center logo and name directly from Admin. This branding is separate from the reseller dashboard, login and loading-screen branding.</div>
          <div class="admin-field"><label>ADMIN PANEL NAME</label><input id="adminNameSetting" maxlength="40" placeholder="KEYS PANEL ADMIN" autocomplete="off"></div>
          <div class="admin-field"><label>ADMIN PANEL LOGO</label><input id="adminLogoFile" type="file" accept="image/png,image/jpeg,image/webp"></div>
          <div class="admin-actions"><button class="admin-primary" type="button" onclick="saveAdminBranding()">SAVE ADMIN BRANDING</button></div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">Panel Branding</div>
          <div class="admin-card-note">Edit the reseller dashboard name and logo. PNG, JPG/JPEG or WEBP are supported. Large logos are automatically resized and optimized for shared-host upload limits.</div>
          <div class="admin-field"><label>DASHBOARD / PANEL NAME</label><input id="panelNameSetting" maxlength="40" placeholder="KEYS PANEL" autocomplete="off"></div>
          <div class="admin-field"><label>DASHBOARD LOGO</label><input id="panelLogoFile" type="file" accept="image/png,image/jpeg,image/webp"></div>
          <div class="admin-actions"><button class="admin-primary" type="button" onclick="savePanelBranding()">SAVE PANEL BRANDING</button></div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">Login Branding</div>
          <div class="admin-card-note">Edit the login screen name and logo separately from the dashboard branding. Large logos are automatically optimized before upload.</div>
          <div class="admin-field"><label>LOGIN NAME</label><input id="loginNameSetting" maxlength="40" placeholder="FLASH STAR" autocomplete="off"></div>
          <div class="admin-field"><label>LOGIN LOGO</label><input id="loginLogoFile" type="file" accept="image/png,image/jpeg,image/webp"></div>
          <div class="admin-actions"><button class="admin-primary" type="button" onclick="saveLoginBranding()">SAVE LOGIN BRANDING</button></div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">Loading Screen Branding</div>
          <div class="admin-card-note">Change the loading screen logo and name directly from Admin. No coding is required for future logo changes.</div>
          <div class="admin-field"><label>LOADING SCREEN NAME</label><input id="splashNameSetting" maxlength="40" placeholder="FLASH STAR" autocomplete="off"></div>
          <div class="admin-field"><label>LOADING SCREEN LOGO</label><input id="splashLogoFile" type="file" accept="image/png,image/jpeg,image/webp"></div>
          <div class="admin-actions"><button class="admin-primary" type="button" onclick="saveSplashBranding()">SAVE LOADING SCREEN BRANDING</button></div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">Loading Screen Text Controls</div>
          <div class="admin-card-note">Every visible loading-screen text line has its own field. Emojis and Unicode text are supported.</div>
          <div class="admin-field"><label>TOP / KICKER TEXT</label><input id="splashKickerSetting" maxlength="80" placeholder="🔐 SECURE ACCESS POINT" autocomplete="off"></div>
          <div class="admin-field"><label>WELCOME TEXT</label><input id="splashWelcomeSetting" maxlength="80" placeholder="WELCOME TO" autocomplete="off"></div>
          <div class="admin-field"><label>PORTAL TEXT</label><input id="splashPortalSetting" maxlength="80" placeholder="RESELLER PORTAL" autocomplete="off"></div>
          <div class="admin-field"><label>SUBTITLE TEXT</label><input id="splashSubtitleSetting" maxlength="180" placeholder="Setting things up, taking you to sign in..." autocomplete="off"></div>
          <div class="admin-field"><label>LOADING / BOTTOM TEXT</label><input id="splashLoadingTextSetting" maxlength="80" placeholder="REDIRECTING AUTOMATICALLY" autocomplete="off"></div>
          <div class="admin-actions"><button class="admin-primary" type="button" onclick="saveSplashTextSettings()">SAVE LOADING TEXT</button></div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">Login Text Controls</div>
          <div class="admin-card-note">Control the visible login-page wording from Admin. This changes text only; authentication logic stays unchanged.</div>
          <div class="admin-field"><label>LOGIN SUBTITLE</label><input id="loginSubtitleSetting" maxlength="180" placeholder="🔐 Ressler Portal — sign in to continue" autocomplete="off"></div>
          <div class="admin-field"><label>LOGIN BADGE / TITLE</label><input id="loginBadgeSetting" maxlength="100" placeholder="RESSLER PORTAL SIGN IN" autocomplete="off"></div>
          <div class="admin-field"><label>USERNAME LABEL</label><input id="loginUsernameLabelSetting" maxlength="60" placeholder="Username" autocomplete="off"></div>
          <div class="admin-field"><label>USERNAME PLACEHOLDER</label><input id="loginUsernamePlaceholderSetting" maxlength="100" placeholder="Your username" autocomplete="off"></div>
          <div class="admin-field"><label>PASSWORD LABEL</label><input id="loginPasswordLabelSetting" maxlength="60" placeholder="Password" autocomplete="off"></div>
          <div class="admin-field"><label>PASSWORD PLACEHOLDER</label><input id="loginPasswordPlaceholderSetting" maxlength="100" placeholder="Your password" autocomplete="off"></div>
          <div class="admin-field"><label>LOGIN BUTTON TEXT</label><input id="loginButtonTextSetting" maxlength="80" placeholder="🔐  Log In  →" autocomplete="off"></div>
          <div class="admin-field"><label>LOGIN MESSAGE</label><input id="loginMessageSetting" maxlength="180" placeholder="Please enter your login details." autocomplete="off"></div>
          <div class="admin-field"><label>LOGIN FOOTER TEXT</label><input id="loginFooterSetting" maxlength="120" placeholder="Authorized personnel only" autocomplete="off"></div>
          <div class="admin-actions"><button class="admin-primary" type="button" onclick="saveLoginTextSettings()">SAVE LOGIN TEXT</button></div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">KEYSPANEL API Controller</div>
          <div class="admin-card-note">Connect this website to KEYSPANEL using the real reseller endpoint. Purchases from the Store and from your reseller API use the same server-side provider connection. The <b>x-master-key</b> is never returned to the browser or written to audit logs.</div>
          <div class="admin-actions"><button id="providerApiToggleBtn" class="admin-primary" type="button" onclick="toggleProviderApiSetting()">API: OFF</button></div>
          <div class="admin-field"><label>KEYSPANEL API ENDPOINT</label><input id="providerApiUrl" type="url" placeholder="https://keyspanelshop.shop/reseller_api.php" autocomplete="off"></div>
          <div class="admin-field"><label>KEYSPANEL MASTER KEY</label><input id="providerApiToken" type="password" placeholder="Enter new master key to save / replace old key" autocomplete="new-password"></div>
          <div class="admin-actions" style="display:flex;gap:8px;flex-wrap:wrap">
            <button id="providerApiSaveBtn" class="admin-primary" type="button" onclick="saveProviderApiSettings()">SAVE KEYSPANEL SETTINGS</button>
            <button id="providerApiTestBtn" class="admin-secondary" type="button" onclick="testProviderApiConnection()">CHECK SAVED CONNECTION</button>
            <button id="outboundIpBtn" class="admin-secondary" type="button" onclick="checkOutboundIp()">CHECK OUR OUTBOUND IP</button>
          </div>
          <div id="providerApiStatus" class="admin-card-note" style="margin-top:8px;overflow-wrap:anywhere">Not configured</div>
          <div id="providerApiTestResult" class="admin-card-note" style="margin-top:8px;display:none;overflow-wrap:anywhere"></div>
          <div id="outboundIpResult" class="admin-card-note" style="margin-top:8px;display:none;overflow-wrap:anywhere"></div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">DRIPSTORE API</div>
          <div class="admin-card-note">Additional stock provider. The token is stored server-side and is never returned to the browser. Customer-facing DRIPSTORE stock is always 99; actual purchases call DRIPSTORE in real time.</div>
          <div class="admin-actions"><button id="dripstoreApiToggleBtn" class="admin-primary" type="button" onclick="toggleDripstoreApiSetting()">DRIPSTORE API: OFF</button></div>
          <div class="admin-field"><label>DRIPSTORE API URL</label><input id="dripstoreApiUrl" type="url" placeholder="https://dripclientstore.shop/api/v1" autocomplete="off"></div>
          <div class="admin-field"><label>DRIPSTORE API TOKEN</label><input id="dripstoreApiToken" type="password" placeholder="Enter new API token to save / replace old token" autocomplete="new-password"></div>
          <div class="admin-actions" style="display:flex;gap:8px;flex-wrap:wrap">
            <button id="dripstoreApiSaveBtn" class="admin-primary" type="button" onclick="saveDripstoreApiSettings()">SAVE DRIPSTORE SETTINGS</button>
            <button id="dripstoreApiTestBtn" class="admin-secondary" type="button" onclick="testDripstoreApiConnection()">CHECK CONNECTION</button>
            <button id="dripstoreProductsBtn" class="admin-secondary" type="button" onclick="loadDripstoreProducts()">LOAD PRODUCTS / VARIANTS</button>
            <button class="admin-secondary" type="button" onclick="loadDripstoreHistory()">KEY HISTORY</button>
            <button class="admin-secondary" type="button" onclick="dripstoreResetPrompt()">RESET KEY</button>
          </div>
          <div id="dripstoreApiStatus" class="admin-card-note" style="margin-top:8px;overflow-wrap:anywhere">Not configured</div>
          <div id="dripstoreApiTestResult" class="admin-card-note" style="margin-top:8px;display:none;overflow-wrap:anywhere"></div>
          <div id="dripstoreProductsResult" class="admin-card-note" style="margin-top:8px;display:none;overflow-wrap:anywhere"></div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">Sound Effects</div>
          <div class="admin-card-note">Turns the short tap/click sound on or off for every user, everywhere in the app.</div>
          <div class="admin-actions"><button id="soundToggleBtn" class="admin-primary" type="button" onclick="toggleSoundSetting()">SOUND: ON</button></div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">Reseller Update Group</div>
          <div class="admin-card-note">Controls the <b>JOIN NOW</b> button in the reseller dashboard. Enter your Telegram/group/channel invite or update URL.</div>
          <div class="admin-field"><label>UPDATE GROUP / CHANNEL URL</label><input id="groupUpdateUrlInput" type="url" placeholder="https://t.me/yourgroup" autocomplete="off"></div>
          <div class="admin-actions"><button class="admin-primary" type="button" onclick="saveGroupUpdateUrl()">SAVE UPDATE GROUP LINK</button></div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">Deposit Amount Limits</div>
          <div class="admin-card-note">Set the minimum and maximum amount a reseller can enter in the Deposit section. These limits are enforced on the server too.</div>
          <div class="admin-management-grid"><input id="depositMinSetting" type="number" min="0.01" step="0.01" placeholder="Minimum ₹10"><input id="depositMaxSetting" type="number" min="0.01" step="0.01" placeholder="Maximum ₹100000"></div>
          <div class="admin-actions"><button class="admin-primary" type="button" onclick="saveDepositLimits()">SAVE DEPOSIT LIMITS</button></div>
          <div id="depositLimitsStatus" class="admin-card-note" style="margin-top:8px"></div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">Paytm Business — Auto Payment</div>
          <div class="admin-card-note">Same Paytm Business Auto Payment function as the supplied bot. You only enter <b>Paytm MID</b> and <b>Paytm UPI ID</b>. The website creates the UPI QR, checks Paytm merchant status automatically, verifies the exact amount, and credits the wallet only once.</div>
          <div class="admin-management-grid"><input id="paytmMid" placeholder="Paytm Merchant ID (MID)"><input id="paytmUpiId" placeholder="Paytm UPI ID — e.g. yourid@paytm"></div>
          <div class="admin-actions"><button id="paytmToggleBtn" class="admin-primary" type="button" onclick="togglePaytmSetting()">PAYTM: OFF</button><button class="admin-secondary" type="button" onclick="savePaytmSettings()">SAVE PAYTM SETTINGS</button></div>
          <div id="paytmConfigStatus" class="admin-card-note" style="margin-top:8px">Paytm not configured.</div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">ZapUPI — Auto Payment</div>
          <div class="admin-card-note">Paste your <b>ZAP KEY</b> from the ZapUPI dashboard. The key is stored encrypted and is never shown again. Set this as your webhook URL in ZapUPI if asked: <code id="zapupiWebhookHint"></code></div>
          <div class="admin-management-grid"><input id="zapupiKey" type="password" autocomplete="off" placeholder="ZAP KEY (leave empty to keep the saved one)"></div>
          <div class="admin-actions"><button id="zapupiToggleBtn" class="admin-primary" type="button" onclick="toggleZapupiSetting()">ZAPUPI: OFF</button><button class="admin-secondary" type="button" onclick="saveZapupiSettings()">SAVE ZAPUPI KEY</button></div>
          <div id="zapupiConfigStatus" class="admin-card-note" style="margin-top:8px">ZapUPI not configured.</div>
        </div>
        <div class="admin-card wide">
          <div class="admin-card-title">User Price List (self-signup accounts)</div>
          <div class="admin-card-note">One shared price list applies to every self-registered "user" account. Resellers keep the default/custom pricing they already have; this list never affects them. Leave a field empty to fall back to the default price.</div>
          <div id="userPriceListBody" class="admin-card-note">Loading…</div>
        </div>
      </div>
    </div>

    <!-- LOGS -->
    <div class="admin-tab-panel" id="adminTab-logs">
      <div class="admin-grid">
        <div class="admin-card wide">
          <div class="admin-card-title">Audit Logs</div>
          <div class="admin-card-note">Important admin actions and API actions, most recent first.</div>
          <div id="adminLogsList" class="admin-list"><div class="admin-empty">Loading…</div></div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- =========================================================
     DASHBOARD APPLICATION
     ========================================================= -->

<div id="app">

    <div class="app-shell">

        <!-- Header -->
        <header class="top-header">

            <div class="header-brand">

                <div class="header-logo">
                    <?php if ($panelLogo !== ''): ?>
                        <img id="panelLogoImage" src="<?= htmlspecialchars($panelLogo, ENT_QUOTES, "UTF-8") ?>" alt="Panel logo">
                    <?php else: ?>
                        <span id="panelLogoFallback">★</span>
                    <?php endif; ?>
                </div>

                <div class="header-title" id="panelBrandName"><?= htmlspecialchars($panelName ?: 'KEYS PANEL', ENT_QUOTES, 'UTF-8') ?></div>

            </div>


            <div class="header-user-wrap">
                <div class="header-user">👤 <span id="headerUsername">ROYAL1235</span></div>
                <div class="balance-badge">
                    <span class="balance-money">💰</span>
                    <span id="headerBalance">₹ 0.00</span>
                </div>
            </div>

        </header>

        <div id="userMaintenanceOverlay" class="user-maintenance-overlay" aria-hidden="true">
            <div class="maintenance-orbit" aria-hidden="true">
                <span class="orbit-ring ring-one"></span>
                <span class="orbit-ring ring-two"></span>
                <span class="orbit-core">✦</span>
                <span class="orbit-signal signal-one"></span>
                <span class="orbit-signal signal-two"></span>
                <span class="orbit-signal signal-three"></span>
            </div>
            <div class="maintenance-lock-title">SITE UNDER<br>MAINTENANCE MODE</div>
            <div class="maintenance-lock-line"></div>
            <div class="maintenance-lock-message" id="userMaintenanceMessage">We are performing scheduled maintenance. Please check back shortly.</div>
            <div class="maintenance-lock-status"><span class="maintenance-live-dot"></span> SYSTEM MAINTENANCE IN PROGRESS</div>
        </div>
        <div id="supportButtonWrap" style="display:none;margin:0 16px 10px;"><button type="button" class="admin-secondary" onclick="window.open(window.__supportUrl,'_blank')">💬 SUPPORT</button></div>


        <!-- =================================================
             HOME
             ================================================= -->

        <section
            class="page active"
            id="homePage"
        >

            <div class="welcome">

                <div class="welcome-small">
                    👋 WELCOME BACK
                </div>

                <h1>
                    <span style="color:#d9b43a;font-size:18px;vertical-align:1px;">👤</span>
                    <span class="username-text" id="dashboardUsername">Royal1235</span>
                </h1>

            </div>


            <!-- Update Banner -->
            <div class="update-banner">

                <div class="update-info">

                    <div class="update-title">
                        🔔 RESELLERS ALL UPDATE CHECK
                    </div>

                    <div class="update-sub">
                        New Update Group Join
                    </div>

                </div>

                <button
                    id="groupUpdateJoinBtn"
                    class="join-btn"
                    type="button"
                    onclick="openGroupUpdateLink()"
                >
                    JOIN NOW
                </button>

            </div>


            <!-- Stats -->
            <div class="stats-grid">

                <div class="stat-card">

                    <div class="stat-icon">💰</div>

                    <div class="stat-value" id="statWalletBalance">
                        ₹0.00
                    </div>

                    <div class="stat-name">
                        Wallet Balance
                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-icon">🔑</div>

                    <div class="stat-value" id="statKeysToday">
                        0
                    </div>

                    <div class="stat-name">
                        Keys Sold Today
                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-icon">📊</div>

                    <div class="stat-value" id="statTotalSpent">
                        ₹0.00
                    </div>

                    <div class="stat-name">
                        Total Spent
                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-icon">🗓️</div>

                    <div class="stat-value" id="statKeysMonth">
                        0
                    </div>

                    <div class="stat-name">
                        Keys Sold This Month
                    </div>

                </div>

            </div>


            <!-- Challenge + Leaderboard -->
            <div class="dashboard-grid">

                <!-- Challenge -->
                <div class="panel challenge-panel">

                    <div class="panel-heading challenge-heading">

                        <div class="panel-title">
                            🎁 September 2026 Challenge
                        </div>

                        <div class="live">
                            <span class="live-dot"></span>
                            LIVE
                        </div>

                    </div>

                    <div class="challenge-header">
                        <div class="challenge-date">
                            ⏰ <strong>24 days</strong> left this month — top 3 resellers win coins
                        </div>
                    </div>

                    <div class="podium">

                        <div class="podium-place second">
                            <div class="podium-user">🥈</div>
                            <div class="podium-name">2ND PLACE</div>
                            <div class="reward">🪙 <b>200</b></div>
                        </div>

                        <div class="podium-place first">
                            <div class="podium-user">🥇</div>
                            <div class="podium-name">1ST PLACE</div>
                            <div class="reward">🪙 <b>300</b></div>
                        </div>

                        <div class="podium-place third">
                            <div class="podium-user">🥉</div>
                            <div class="podium-name">3RD PLACE</div>
                            <div class="reward">🪙 <b>100</b></div>
                        </div>

                    </div>

                </div>

                <!-- Top 5 -->
                <div class="panel top5-panel">

                    <div class="panel-heading">
                        <div class="panel-title">
                            🥇 TOP 5 RESELLERS THIS MONTH
                        </div>
                    </div>

                    <div class="reseller-list" id="topResellersList"></div>
</div>

                </div>

            </div>
        </section>


        <!-- =================================================
             STORE
             ================================================= -->

        <section class="page" id="storePage">
            <div class="store-buy-panel">
                <div class="store-heading">
                    <span class="store-key">🔑</span>
                    <span>BUY A PLAN</span>
                </div>

                <div class="store-field">
                    <label>SELECT CATEGORY</label>
                    <select id="storeCategory" onchange="storeCategoryChanged()">
                        <option value="">— Select a category —</option>
                        <option value="android">Android</option>
                        <option value="ios">iOS</option>
                        <option value="windows">Windows</option>
                    </select>
                </div>

                <div class="store-field">
                    <label>SELECT PRODUCT</label>
                    <select id="storeProduct" disabled onchange="storeProductChanged()">
                        <option value="">— Select Category First —</option>
                    </select>
                </div>

                <div class="store-field store-field-last">
                    <label>SELECT PLAN</label>
                    <select id="storePlan" disabled onchange="storePlanChanged()">
                        <option value="">— Select Product First —</option>
                    </select>
                </div>

                <div class="store-order" id="storeOrder" aria-hidden="true">
                    <div><span>Selected Plan</span><strong id="selectedPlanName">—</strong></div>
                    <div id="storeStockRow" style="display:none!important" aria-hidden="true"><span>Stock</span><strong id="selectedPlanStock">—</strong></div>
                    <div id="storePriceRow"><span>Price</span><strong id="selectedPlanPrice">₹0.00</strong></div>
                    <div id="storeMaintenanceNotice" class="maintenance-badge" style="display:none;margin:8px 0;">⚠ UNDER MAINTENANCE — purchasing temporarily disabled</div>
                    <button type="button" id="storeYoutubeBtn" style="display:none" onclick="if(window.__storeYoutubeUrl) window.open(window.__storeYoutubeUrl,'_blank')">▶ WATCH ON YOUTUBE</button>
                    <button type="button" id="storeBuyBtn" onclick="buySelectedPlan()">BUY NOW</button>
                </div>
            </div>
        </section>

        <!-- =================================================
             DEPOSIT
             ================================================= -->

        <section class="page" id="depositPage">
            <div class="deposit-wrap">
                <div class="deposit-wallet-strip">💰 <span>WALLET BALANCE:</span> ₹<b id="depositWalletBalance">0.00</b></div>
                <button type="button" class="deposit-back" onclick="navigate('storePage',document.querySelector('.bottom-nav .nav-btn[data-page=\"storePage\"]'))">← Back to Store</button>
                <div class="deposit-card deposit-entry-card">
                    <div class="deposit-title">＋ ADD BALANCE</div><label>ENTER AMOUNT (₹)</label>
                    <div class="deposit-amount-wrap"><span>₹</span><input id="depositAmount" type="number" min="10" max="100000" step="0.01" placeholder="0"></div>
                    <div class="deposit-limits"><span id="depositMinLabel">MIN ₹10</span><span id="depositMaxLabel">MAX ₹1,00,000</span></div>
                    <div class="deposit-quick"><button type="button" onclick="setDepositAmount(100)">+₹100</button><button type="button" onclick="setDepositAmount(500)">+₹500</button><button type="button" onclick="setDepositAmount(1000)">+₹1,000</button><button type="button" onclick="setDepositAmount(2000)">+₹2,000</button><button type="button" onclick="setDepositAmount(5000)">+₹5,000</button></div>
                    <div id="depositMethodWrap" class="deposit-quick" style="display:none;margin:10px 0"><button type="button" id="depMethodPaytm" onclick="setDepositMethod('paytm')">PAYTM QR</button><button type="button" id="depMethodZap" onclick="setDepositMethod('zapupi')">ZAPUPI</button></div>
                    <button id="depositPayBtn" type="button" class="deposit-pay-btn" onclick="startDepositPayment()">→ PAYMENT NOW</button>
                </div>
                <div class="deposit-card deposit-history-card"><div class="deposit-history-title">▣ DEPOSIT HISTORY</div><div id="depositHistoryList"><div class="deposit-empty">No deposit history yet.</div></div></div>
            </div>
        </section>
        <!-- PURCHASE RESULT / KEY DELIVERY -->
        <div id="purchaseResultModal" class="purchase-result-modal" style="display:none" aria-hidden="true">
          <div class="purchase-result-card" role="dialog" aria-modal="true" aria-labelledby="purchaseResultTitle">
            <div class="purchase-result-topline"></div>
            <div class="purchase-result-head">
              <div class="purchase-result-icon">✓</div>
              <div>
                <div class="purchase-result-kicker">PURCHASE COMPLETED</div>
                <div class="purchase-result-title" id="purchaseResultTitle">KEY DELIVERED</div>
              </div>
            </div>
            <div class="purchase-result-divider"></div>
            <div class="purchase-result-info" id="purchaseResultInfo"></div>
            <div class="purchase-result-keys-head">
              <span>YOUR KEY<span id="purchaseResultKeysCount"></span></span>
              <button type="button" id="purchaseResultCopyAll" class="purchase-copy-all" style="display:none" onclick="copyPurchaseAll()">📋 COPY ALL</button>
            </div>
            <div id="purchaseResultKeys" class="purchase-result-keys"></div>
            <div class="purchase-result-actions">
              <button type="button" class="purchase-result-close" onclick="closePurchaseResult()">CLOSE</button>
              <button type="button" class="purchase-result-mykeys" onclick="closePurchaseResult();navigate('keyPage',document.querySelector('.bottom-nav .nav-btn[data-page="keyPage"]'))">MY KEYS</button>
            </div>
          </div>
        </div>

        <div id="paytmQrModal" class="paytm-qr-modal" style="display:none">
  <div class="paytm-qr-card">
    <div id="paytmProcessingView" class="paytm-result-view" style="display:none">
      <div class="paytm-result-orbit"><div class="paytm-orbit-dot"></div><div class="paytm-processing-ring"></div><div class="paytm-processing-core"><span>↻</span></div></div>
      <div class="paytm-result-kicker">PAYMENT RECEIVED</div>
      <div class="paytm-result-title">PROCESSING PAYMENT</div>
      <div class="paytm-result-message">Payment verified successfully.<br>We are securely updating your wallet.</div>
      <div class="paytm-result-progress"><span></span></div>
      <div class="paytm-result-status"><i></i> VERIFYING &amp; CREDITING WALLET</div>
    </div>
    <div id="paytmSuccessView" class="paytm-result-view" style="display:none">
      <div class="paytm-success-circle"><span>✓</span></div>
      <div class="paytm-result-kicker success">PAYMENT CONFIRMED</div>
      <div class="paytm-result-title success">PAYMENT SUCCESSFUL</div>
      <div class="paytm-result-amount" id="paytmSuccessAmount">₹0.00</div>
      <div class="paytm-result-message">Your payment has been received and your wallet has been credited successfully.</div>
      <div class="paytm-result-status success"><i></i> ORDER COMPLETED • WALLET CREDITED</div>
    </div>
    <div id="paytmQrPaymentView">
    <div class="paytm-qr-top"><span>KEYS PANEL</span><b><i></i> SECURE PAYMENT</b></div>
    <div class="paytm-pay-badges"><span>UPI</span><span>PAYTM</span><span>INSTANT CREDIT</span></div>
    <div class="paytm-qr-amount-label">AMOUNT PAYABLE</div>
    <div class="paytm-qr-amount" id="paytmQrAmount">₹0.00</div>
    <div class="paytm-qr-order"><span>ORDER ID</span><b id="paytmQrOrder">—</b><button type="button" onclick="copyPaytmOrder()" aria-label="Copy order ID">COPY</button></div>
    <div class="paytm-scan-label"><span>SCAN &amp; PAY</span><small>Open any UPI app and scan this QR</small></div>
    <div class="paytm-qr-image-wrap"><div class="paytm-qr-corner tl"></div><div class="paytm-qr-corner tr"></div><div class="paytm-qr-corner bl"></div><div class="paytm-qr-corner br"></div><img id="paytmQrImage" alt="UPI payment QR"></div>
    <div class="paytm-qr-hint"><span>▣</span> Scan with Google Pay, PhonePe, Paytm or any UPI app</div>
    <button type="button" class="paytm-download-btn" onclick="downloadPaytmQr()">▣ DOWNLOAD QR</button>
    <div class="paytm-countdown-box"><div class="paytm-countdown-head"><span>PAYMENT WINDOW</span><b id="paytmQrCountdown">03:00</b></div><div class="paytm-countdown-track"><span id="paytmQrProgress"></span></div><small>Complete the payment before this timer reaches 00:00</small></div>
    <div id="paytmQrStatus" class="paytm-status waiting"><span class="status-dot"></span><span id="paytmQrStatusText">WAITING FOR PAYMENT</span><span class="status-dots">•••</span></div>
    <button type="button" class="paytm-check-btn" onclick="checkPaytmDepositStatus(true)">↻ CHECK PAYMENT STATUS</button>
    <div class="paytm-qr-note">🔒 Payment is verified directly with Paytm before your wallet is credited.</div>
    </div>
    <button type="button" class="paytm-close-btn" onclick="closePaytmQr()">CLOSE</button>
  </div>
</div>

        <!-- =================================================
             MY KEY
             ================================================= -->

        <section
            class="page"
            id="keyPage"
        >

            <div class="center-panel">

                <div class="section-title">
                    My Key
                </div>

                <div class="section-sub">
                    Your purchased keys and order details.
                </div>


                <div class="panel">

                    <div class="info-row">
                        <div class="info-label">Total Orders</div>
                        <div class="info-value" id="myKeyTotalCount">0</div>
                    </div>

                    <div class="info-row">
                        <div class="info-label">Total Spent</div>
                        <div class="info-value" id="myKeyTotalSpent">₹0.00</div>
                    </div>

                </div>

                <div id="myKeyList" class="mykey-list">
                    <div style="padding-top:16px;text-align:center;color:#64748b;font-size:10px;">
                        No purchase history yet.
                    </div>
                </div>

            </div>

        </section>


        <!-- =================================================
             API DOCUMENTATION
             ================================================= -->
        <section class="page" id="apiPage">
          <div class="api-video-wrap">
            <div class="api-wallet-video">💰 <span>WALLET BALANCE:</span> ₹<b id="apiWalletBalance">0.00</b></div>
            <button class="api-video-back" onclick="showApiHome()">← Back to Store</button>

            <div class="api-video-card api-key-video-card">
              <div class="api-video-title">🔌 <span>KEYS PANEL RESELLER PORTAL — YOUR API</span></div>
              <div class="api-video-label"># &nbsp; API KEY</div>
              <div class="api-video-keybox">
                <code id="apiKeyDisplay">No key generated yet</code>
                <button type="button" onclick="copyApiText(document.getElementById('apiKeyDisplay').textContent)">📋 COPY</button>
              </div>
              <div class="api-video-label api-site-url-label"># &nbsp; API ENDPOINT</div>
              <div class="api-video-keybox api-site-url-box">
                <code id="apiWebsiteUrlDisplay">Loading API endpoint…</code>
                <button type="button" onclick="copyApiText(document.getElementById('apiWebsiteUrlDisplay').textContent)">📋 COPY</button>
              </div>
              <div class="api-video-warning">⚠️ Treat your API key like a password — anyone with it can spend your wallet balance. Don’t share it publicly.</div>
              <button class="api-video-dark-btn" type="button" onclick="generateMyApiKey()">🔄 &nbsp; REGENERATE KEY</button>
            </div>

            <div class="api-video-card api-doc-video-card">
              <div class="api-video-title">📖 <span>FULL API DOCUMENTATION</span><i></i></div>
              <button class="api-video-gold-btn" type="button" onclick="showFullApiDocs()">📖 &nbsp; OPEN FULL DOCUMENTATION</button>
            </div>

            <div class="api-video-card api-variants-video-card">
              <div class="api-video-title api-variants-title">📦 <span>YOUR VARIANTS</span><strong id="apiVariantCount">0 VARIANTS</strong><i></i></div>
              <div id="apiVariantBrowser" class="api-variant-browser"><div class="api-browser-loading">Loading variants…</div></div>
            </div>

            <div class="api-video-card api-product-link-card">
              <div class="api-video-title">🛍️ <span>API PRODUCTS &amp; DURATION IDs</span></div>
              <p>Open a product and select its duration. Every duration has its own unique ID. Use that exact Duration ID on your website when sending an API order.</p>
              <button class="api-video-gold-btn" type="button" onclick="showApiProductsPage()">🛍️ &nbsp; OPEN PRODUCT LIST</button>
            </div>

          </div>
        </section>

        <section class="page" id="apiProductsPage">
          <div class="api-docs-wrap">
            <div class="api-wallet-card">🛍️ <strong>API Products &amp; Duration IDs</strong></div>
            <button class="api-back" onclick="showApiHome()">← Back to API</button>
            <div class="api-card">
              <div class="api-heading">📦 <span>SELECT PRODUCT</span></div>
              <p>Select a product to expand its durations. Every duration has its own unique ID. That ID is the value your external website must send as <code>duration_id</code> when creating an order.</p>
              <button class="api-gold-btn" type="button" onclick="loadApiProductsDirectory()">🔄 REFRESH PRODUCTS</button>
              <div id="apiProductsDirectory" style="margin-top:12px"></div>
            </div>
            <div class="api-card">
              <div class="api-heading">🔗 <span>HOW THE ID MATCHING WORKS</span></div>
              <div class="api-endpoint"><b>1</b><strong>Choose Product</strong><p>Your product is only the parent/group. It contains one or more durations.</p></div>
              <div class="api-endpoint"><b>2</b><strong>Choose Duration</strong><p>Each duration receives its own unique <code>duration_id</code> automatically.</p></div>
              <div class="api-endpoint"><b>3</b><strong>Send Duration ID</strong><p>Your website sends that exact ID to <code>?api=v1_order</code>. The panel automatically finds the matching product, duration, price and stock.</p></div>
            </div>
          </div>
        </section>

        <section class="page" id="apiDocsPage">
          <div class="api-docs-wrap">
            <button class="api-back" onclick="showApiHome()">← Back to My API</button>
            <div class="api-card"><div class="api-heading">📖 <span>FULL API DOCUMENTATION</span></div><p>All examples below match the real routes implemented by this single index.php file.</p></div>
            <div class="api-card"><div class="api-heading">🔐 <span>AUTHENTICATION</span></div>
              <div class="api-endpoint"><b>HEADER</b><strong>X-API-Key: YOUR_RESELLER_API_KEY</strong><p>Send the reseller API key in every v1 request. Raw keys are never stored in the database.</p></div>
              <div class="api-endpoint"><b>API</b><strong id="fullApiBaseUrl"></strong><p>This is the real reseller API endpoint supported by this panel. Copy this endpoint directly; no separate website domain is required.</p></div>
            </div>
            <div class="api-card"><div class="api-heading">🛒 <span>BUY</span></div><div class="api-endpoint"><b>POST</b><strong>?api=v1_order</strong><p>Send <code>action=buy</code>, the exact <code>variant_id</code> (Duration ID) and optional <code>quantity</code> as <code>application/x-www-form-urlencoded</code>.</p><small>action=buy&amp;variant_id=201&amp;quantity=1</small><p>The variant ID is resolved against your real product/duration, wallet balance and stock. Maximum quantity for this documented format is 10 keys per request.</p></div></div>
            <div class="api-card"><div class="api-heading">💻 <span>PHP</span></div><div class="api-code-block"><button type="button" class="copy-code api-doc-copy-btn" onclick="copyApiCodeBlock('fullPhpApiCode', this)">📋 COPY PHP</button><pre id="fullPhpApiCode"></pre></div></div>
          </div>
        </section>

<style id="api-video-match-v1">
.api-video-wrap{width:100%;max-width:760px;margin:0 auto;padding:12px 0 105px;box-sizing:border-box}
.api-wallet-video{margin:0 0 20px;padding:17px 22px;background:#211f19;border:1px solid rgba(212,175,55,.35);border-radius:12px;color:#dfbd45;font:800 18px ui-monospace,monospace;box-shadow:inset 0 0 0 1px rgba(255,255,255,.015)}
.api-wallet-video b{font-size:22px}.api-wallet-video span{letter-spacing:1px}
.api-video-back{background:none;border:0;color:#dfbd45;text-decoration:underline;font-size:15px;font-weight:800;padding:0 0 20px;cursor:pointer}
.api-video-card{position:relative;background:#17233a;border:1px solid rgba(75,102,151,.55);border-radius:9px;padding:28px 48px 30px;margin-bottom:20px;overflow:hidden;box-shadow:0 8px 20px rgba(0,0,0,.12)}
.api-video-card:before,.api-video-card:after{content:"";position:absolute;width:38px;height:38px;pointer-events:none}.api-video-card:before{left:-1px;top:-1px;border-left:4px solid #dfbd45;border-top:4px solid #dfbd45}.api-video-card:after{right:-1px;bottom:-1px;border-right:4px solid #dfbd45;border-bottom:4px solid #dfbd45}
.api-video-title{display:flex;align-items:center;gap:10px;color:#dfbd45;font:800 15px ui-monospace,monospace;letter-spacing:2.5px;margin-bottom:20px}.api-video-title i{height:2px;background:rgba(112,132,166,.22);flex:1;margin-left:8px}.api-video-label{color:#dfbd45;font:800 14px ui-monospace,monospace;letter-spacing:2px;margin:4px 0 10px}
.api-video-keybox{display:flex;align-items:center;gap:8px;background:#070d1b;border:1px solid rgba(212,175,55,.18);border-radius:4px;padding:14px 12px;min-height:62px;box-sizing:border-box}.api-video-keybox code{flex:1;color:#dfbd45;font:13px/1.45 ui-monospace,monospace;word-break:break-all}.api-video-keybox button,.api-video-copy-id{background:#080f1e;color:#eee8dd;border:1px solid rgba(76,96,140,.65);border-radius:6px;padding:10px 13px;font-weight:800;white-space:nowrap}
.api-video-warning{color:#aeb8cc;text-align:center;font-size:12px;line-height:1.45;margin:14px 0 22px}.api-video-dark-btn{width:100%;height:58px;background:#070d1b;color:#eee8dd;border:1px solid rgba(76,96,140,.4);border-radius:4px;font:800 15px ui-monospace,monospace;letter-spacing:2px}
.api-video-gold-btn{width:100%;height:62px;background:#dfb633;color:#151515;border:0;border-radius:2px;font:900 17px ui-monospace,monospace;letter-spacing:2px;clip-path:polygon(0 0,96% 0,100% 22%,100% 100%,4% 100%,0 78%);cursor:pointer}.api-video-card p{color:#aeb8cc;font-size:12px;line-height:1.5;margin:-5px 0 16px}
.api-variants-title{margin-bottom:14px}.api-variants-title strong{margin-left:auto;background:#28261f;color:#dfbd45;border:1px solid rgba(212,175,55,.25);border-radius:6px;padding:10px 14px;font-size:13px;letter-spacing:2px;white-space:nowrap}
.api-video-product{background:#070d1b;border:1px solid rgba(212,175,55,.18);border-radius:5px;overflow:hidden;margin-top:9px}.api-video-product-head{width:100%;display:flex;justify-content:space-between;align-items:center;background:none;border:0;color:#eee8dd;padding:17px 16px;font-size:16px;font-weight:800;text-align:left}.api-video-plan-list{display:none;border-top:1px solid rgba(255,255,255,.06)}.api-video-plan-list.open{display:block}.api-video-plan-row{display:grid;grid-template-columns:68px 1fr auto;gap:12px;align-items:center;padding:13px 12px;border-bottom:1px solid rgba(255,255,255,.06)}.api-video-plan-row:last-child{border-bottom:0}.api-video-plan-id{background:#25231e;color:#dfbd45;border:1px solid rgba(212,175,55,.22);border-radius:6px;padding:9px 6px;text-align:center;font:800 13px ui-monospace,monospace}.api-video-plan-info b{display:block;color:#eee8dd;font-size:16px;font-weight:500;margin-bottom:4px}.api-video-plan-info span{display:block;color:#9da8bd;font-size:11px;line-height:1.45}.api-video-plan-info em{font-style:normal;font-weight:800}.stock-ok{color:#28d99b}.stock-out{color:#ff6670}.api-video-copy-id{padding:8px 9px;font-size:9px;color:#dfbd45;border-color:rgba(212,175,55,.3)}
.api-video-code{position:relative;background:#070d1b;border:1px solid rgba(76,96,140,.4);border-radius:3px;overflow:auto}.api-video-code pre{margin:0;padding:22px;color:#eee8dd;font:13px/1.65 ui-monospace,monospace;min-width:max-content}.api-video-code button{position:absolute;right:10px;top:10px;z-index:2;background:#070d1b;color:#dfbd45;border:1px solid #dfbd45;border-radius:5px;padding:7px 12px;font-weight:800}.api-browser-loading,.api-browser-empty{padding:14px;text-align:center;color:#94a3b8;font-size:11px}
@media(max-width:520px){.api-video-wrap{padding-top:10px}.api-video-card{padding:25px 48px 28px;margin-bottom:18px}.api-video-title{font-size:13px;letter-spacing:2px}.api-wallet-video{font-size:15px}.api-wallet-video b{font-size:19px}.api-video-gold-btn{height:58px;font-size:14px}.api-video-plan-row{grid-template-columns:58px 1fr auto;gap:8px}.api-video-plan-info b{font-size:15px}.api-video-plan-info span{font-size:10px}.api-variants-title strong{font-size:10px;padding:8px 9px}.api-video-keybox code{font-size:11px}}
@media(max-width:380px){.api-video-card{padding-left:30px;padding-right:30px}.api-video-title{font-size:11px;letter-spacing:1.5px}.api-video-plan-row{grid-template-columns:52px 1fr}.api-video-copy-id{grid-column:2;justify-self:start}.api-variants-title strong{font-size:9px}}
</style>
<style id="api-docs-v3">
#apiPage,#apiDocsPage{padding-top:12px!important}.api-docs-wrap{width:100%;max-width:760px;margin:0 auto;padding-bottom:100px}.api-wallet-card{margin-bottom:18px;padding:16px 20px;border:1px solid rgba(212,175,55,.35);border-radius:18px;background:#211f19;color:#dfbd45;font-size:17px;display:flex;align-items:center;gap:10px}.api-back{background:none;border:0;color:#dfbd45;text-decoration:underline;font-weight:800;font-size:15px;padding:4px 0 14px}.api-card{background:linear-gradient(180deg,#1b2943,#18253d);border:1px solid rgba(76,105,157,.4);border-radius:18px;padding:18px;margin-bottom:16px}.api-heading{color:#d9b43a;font-weight:800;letter-spacing:2.5px;font-size:14px;display:flex;align-items:center;gap:10px;margin-bottom:14px}.api-card p{color:#aeb8cc;font-size:12px;line-height:1.5;margin:0}.api-code-row{display:flex;align-items:center;gap:8px;background:#080e1c;border:1px solid rgba(212,175,55,.25);border-radius:11px;padding:10px;margin:8px 0;overflow:hidden}.api-code-row code{color:#eee8dd;font-size:11px;white-space:nowrap;overflow:auto;flex:1}.api-code-row button,.copy-code{background:#25231e;color:#dfbd45;border:1px solid rgba(212,175,55,.35);border-radius:8px;padding:7px 9px;font-weight:800;white-space:nowrap}.api-label{text-align:center;color:#aeb8cc;font-size:12px}.api-warning{color:#aeb8cc;font-size:11px;text-align:center;margin-top:8px}.api-code-block{position:relative;background:#080e1c;border:1px solid rgba(212,175,55,.2);border-radius:11px;overflow:auto}.api-code-block pre{margin:0;padding:16px;color:#eee8dd;font:12px/1.55 ui-monospace,monospace;min-width:max-content;padding-top:46px}.copy-code{position:absolute;right:8px;top:8px;z-index:2}.api-doc-copy-btn{cursor:pointer}.api-endpoint{background:#080e1c;border:1px solid rgba(212,175,55,.16);border-radius:11px;padding:13px;margin-top:10px;color:#aeb8cc}.api-endpoint b{background:#2b281e;color:#d9b43a;border-radius:7px;padding:4px 7px;font-size:10px;margin-right:7px}.api-endpoint strong{color:#f1eee7;font:15px ui-monospace,monospace}.api-endpoint p{margin:8px 0 4px}.api-endpoint small{font-size:10px}.api-param{display:grid;grid-template-columns:1fr 1fr 1.5fr;gap:7px;border-top:1px solid #26324a;margin-top:10px;padding-top:9px;font-size:11px}.api-param em{color:#ff5d66;font-style:normal;font-weight:800}.api-param i{font-style:normal}.api-gold-btn{width:100%;height:48px;border:0;border-radius:12px;background:linear-gradient(180deg,#e2b938,#c99d25);color:white;font-size:15px;font-weight:800}.api-product-item{border:1px solid rgba(212,175,55,.18);border-radius:12px;background:#080e1c;margin-top:8px;overflow:hidden}.api-product-toggle{width:100%;display:flex;justify-content:space-between;align-items:center;border:0;background:transparent;color:#f1eee7;padding:13px;text-align:left;font-weight:800;cursor:pointer}.api-duration-list{display:none;border-top:1px solid rgba(255,255,255,.06)}.api-duration-list.open{display:block}.api-duration-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 13px;border-bottom:1px solid rgba(255,255,255,.05)}.api-duration-row:last-child{border-bottom:0}.api-duration-row b{display:block;color:#eee8dd;font-size:12px}.api-duration-row small{display:block;color:#94a3b8;font-size:10px;margin-top:3px}.api-duration-id{display:flex;align-items:center;gap:6px;white-space:nowrap}.api-duration-id span{font:800 10px ui-monospace,monospace;color:#dfbd45}.api-duration-id button{background:#25231e;color:#dfbd45;border:1px solid rgba(212,175,55,.35);border-radius:7px;padding:5px 7px;font-size:9px;font-weight:800}.api-browser-empty,.api-browser-loading{color:#94a3b8;font-size:11px;text-align:center;padding:12px}.api-parent-id{display:inline-block;margin-left:6px;color:#64748b;font-size:9px;font-weight:700}.api-directory-duration-row{background:rgba(255,255,255,.015)}.api-directory-product .api-product-toggle{padding:14px 13px}.api-directory-product .api-product-toggle>span:first-child{display:flex;align-items:center;gap:5px}.api-tabs{display:flex;gap:8px;margin-top:14px}.api-tab{flex:1;padding:9px;border:1px solid rgba(212,175,55,.3);border-radius:9px;background:#0a1020;color:#aeb8cc;font-weight:800}.api-tab.active{background:#28251c;color:#dfbd45}@media(max-width:520px){.api-card{padding:15px;border-radius:16px}.api-wallet-card{font-size:15px}.api-heading{font-size:12px;letter-spacing:2px}.api-code-block pre{font-size:10px}.api-endpoint strong{font-size:13px}.api-param{grid-template-columns:1fr;gap:3px}.api-gold-btn{height:44px;font-size:14px}}

#depositPage{padding:8px 0 90px!important}.deposit-wrap{width:92%;max-width:760px;margin:0 auto}.deposit-wallet-strip{padding:12px 14px;background:#16233d;border:1px solid rgba(212,175,55,.35);border-radius:10px;color:#d9b43a;font:800 13px ui-monospace,monospace;margin-bottom:8px}.deposit-wallet-strip b{font-size:15px}.deposit-back{background:none;border:0;color:#d9b43a;font-size:10px;padding:3px 0 9px}.deposit-card{background:linear-gradient(180deg,#172541,#14213a);border:1px solid rgba(212,175,55,.35);border-radius:8px;padding:14px;margin-bottom:12px}.deposit-title,.deposit-history-title{color:#d9b43a;font:800 10px ui-monospace,monospace;letter-spacing:1px;margin-bottom:13px}.deposit-card label{display:block;color:#e8e3d8;font:800 9px ui-monospace,monospace;margin-bottom:7px}.deposit-amount-wrap{display:flex;align-items:center;background:#0b1222;border:1px solid rgba(212,175,55,.18);border-radius:5px;height:46px;padding:0 10px}.deposit-amount-wrap span{color:#d9b43a;font:800 18px ui-monospace,monospace;margin-right:8px}.deposit-amount-wrap input{border:0;outline:0;background:transparent;color:#fff;width:100%;font:500 14px ui-monospace,monospace}.deposit-limits{display:flex;justify-content:space-between;color:#7e8ca5;font:700 8px ui-monospace,monospace;padding:7px 2px}.deposit-quick{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:10px}.deposit-quick button{height:30px;background:#192641;border:1px solid rgba(93,117,165,.35);border-radius:5px;color:#aeb8cc;font:800 8px ui-monospace,monospace}.deposit-quick button:first-child{border-color:rgba(212,175,55,.6);color:#d9b43a}.deposit-pay-btn{width:100%;height:38px;border:0;border-radius:5px;background:linear-gradient(180deg,#e1ba3e,#c79d28);color:#161616;font:900 10px ui-monospace,monospace}.deposit-pay-btn:disabled{opacity:.55}.deposit-history-title{margin-bottom:8px}.deposit-history-row{display:flex;justify-content:space-between;gap:8px;padding:10px 7px;border-bottom:1px solid rgba(255,255,255,.05);font-size:9px}.deposit-history-main b{display:block;color:#d9b43a;font:800 10px ui-monospace,monospace}.deposit-history-main small{display:block;color:#8190aa;margin-top:4px;font:700 8px ui-monospace,monospace}.deposit-history-status{align-self:flex-start;padding:4px 6px;border-radius:4px;font:800 8px ui-monospace,monospace}.deposit-history-status.success{background:#103d2c;color:#55d39b}.deposit-history-status.pending{background:#3a3218;color:#e0bc4d}.deposit-history-status.failed{background:#3d1820;color:#ff7d8a}.deposit-empty{text-align:center;color:#7e8ca5;font-size:9px;padding:12px}.paytm-qr-modal{position:fixed;inset:0;z-index:99999;background:rgba(2,7,16,.94);backdrop-filter:blur(7px);display:flex;align-items:center;justify-content:center;padding:12px;overflow:auto}.paytm-qr-card{width:min(372px,100%);max-height:calc(100vh - 24px);overflow:auto;background:linear-gradient(180deg,#18284f 0%,#111a35 100%);border:1px solid rgba(212,175,55,.45);border-radius:16px;padding:15px 14px 13px;text-align:center;box-shadow:0 24px 80px rgba(0,0,0,.65)}.paytm-qr-top{display:flex;align-items:center;justify-content:space-between;color:#f1eee7;font:900 11px ui-monospace,monospace;margin-bottom:9px}.paytm-qr-top b{display:flex;align-items:center;gap:5px;font-size:7px;color:#58d79b;letter-spacing:.4px}.paytm-qr-top b i{width:6px;height:6px;border-radius:50%;background:#58d79b;box-shadow:0 0 9px rgba(88,215,155,.8);display:inline-block}.paytm-pay-badges{display:flex;justify-content:center;gap:5px;margin:0 0 12px}.paytm-pay-badges span{padding:4px 7px;border-radius:999px;background:#0b1428;border:1px solid rgba(255,255,255,.08);color:#9eabc1;font:900 6px ui-monospace,monospace;letter-spacing:.5px}.paytm-pay-badges span:first-child{color:#e0bc4d;border-color:rgba(212,175,55,.3)}.paytm-qr-amount-label{color:#8f9bb0;font:800 7px ui-monospace,monospace;letter-spacing:2.2px}.paytm-qr-amount{color:#eee9ff;font:900 31px ui-monospace,monospace;margin:2px 0 6px}.paytm-qr-order{display:flex;align-items:center;justify-content:center;gap:6px;color:#8592aa;font:700 7px ui-monospace,monospace;margin-bottom:11px}.paytm-qr-order span{opacity:.8}.paytm-qr-order b{color:#d9b43a;letter-spacing:.2px}.paytm-qr-order button{border:0;background:rgba(212,175,55,.1);color:#d9b43a;border-radius:4px;padding:3px 5px;font:900 6px ui-monospace,monospace}.paytm-scan-label{display:flex;align-items:center;justify-content:space-between;text-align:left;margin:0 auto 7px;width:250px}.paytm-scan-label span{color:#eee9ff;font:900 8px ui-monospace,monospace;letter-spacing:.7px}.paytm-scan-label small{color:#7787a2;font:700 6px ui-monospace,monospace}.paytm-qr-image-wrap{position:relative;background:#fff;border:4px solid #e2bd45;border-radius:12px;width:250px;height:250px;margin:0 auto 9px;padding:6px;display:flex;align-items:center;justify-content:center;overflow:hidden;box-shadow:0 0 0 1px rgba(255,255,255,.12),0 12px 34px rgba(0,0,0,.27)}.paytm-qr-image-wrap:after{content:"";position:absolute;inset:7px;border:1px dashed rgba(212,175,55,.35);border-radius:6px;pointer-events:none}.paytm-qr-image-wrap img{width:100%;height:100%;object-fit:contain;display:block}.paytm-qr-corner{position:absolute;width:9px;height:9px;border-color:#d9b43a;z-index:2}.paytm-qr-corner.tl{top:2px;left:2px;border-top:2px solid;border-left:2px solid}.paytm-qr-corner.tr{top:2px;right:2px;border-top:2px solid;border-right:2px solid}.paytm-qr-corner.bl{bottom:2px;left:2px;border-bottom:2px solid;border-left:2px solid}.paytm-qr-corner.br{bottom:2px;right:2px;border-bottom:2px solid;border-right:2px solid}.paytm-qr-hint{color:#7e8ca5;font:700 6.5px ui-monospace,monospace;margin:0 auto 9px}.paytm-qr-hint span{color:#d9b43a;margin-right:3px}.paytm-app-title{width:250px;margin:10px auto 6px;color:#66758e;font:900 6px ui-monospace,monospace;letter-spacing:1px;text-align:left}.paytm-app-buttons{width:250px;margin:0 auto 9px;display:grid;grid-template-columns:repeat(3,1fr);gap:7px}.paytm-app-btn{height:48px;border:1px solid rgba(255,255,255,.08);border-radius:7px;background:#0a1325;color:#eef2fa;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;font:800 6px ui-monospace,monospace;cursor:pointer}.paytm-app-btn b{width:20px;height:20px;border-radius:5px;display:grid;place-items:center;background:#17233b;font:900 9px Arial}.paytm-app-btn.phonepe{border-color:rgba(124,92,255,.25)}.paytm-app-btn.phonepe b{background:#5d42c9;color:#fff}.paytm-app-btn.gpay{border-color:rgba(66,133,244,.25)}.paytm-app-btn.gpay b{background:#fff;color:#4285f4}.paytm-app-btn.paytm{border-color:rgba(38,166,255,.25)}.paytm-app-btn.paytm b{background:#168de0;color:#fff}.paytm-app-btn:active{transform:scale(.97)}.paytm-download-btn{width:250px;height:36px;background:#080f20;border:1px solid rgba(255,255,255,.05);border-radius:6px;color:#e9edf6;font:800 8px ui-monospace,monospace}.paytm-countdown-box{width:250px;margin:9px auto 8px;background:#0c162b;border:1px solid rgba(212,175,55,.16);border-radius:8px;padding:8px 9px;text-align:left}.paytm-countdown-head{display:flex;justify-content:space-between;align-items:center;color:#7585a0;font:800 6.5px ui-monospace,monospace;letter-spacing:.5px}.paytm-countdown-head b{color:#e0bc4d;font:900 11px ui-monospace,monospace}.paytm-countdown-track{height:3px;background:#202d46;border-radius:99px;overflow:hidden;margin:6px 0 5px}.paytm-countdown-track span{display:block;width:100%;height:100%;background:linear-gradient(90deg,#d9b43a,#f0d477);border-radius:inherit;transition:width .9s linear}.paytm-countdown-box small{color:#65748d;font:600 6px ui-monospace,monospace}.paytm-check-btn{width:250px;height:34px;margin:0 auto 2px;display:block;background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.2);border-radius:6px;color:#d9b43a;font:900 7px ui-monospace,monospace}.paytm-check-btn:active{transform:scale(.98)}.paytm-status{display:flex;align-items:center;justify-content:center;gap:5px;background:#111d35;border:1px solid rgba(255,255,255,.05);border-radius:7px;padding:8px;color:#aeb8cc;font:900 7.5px ui-monospace,monospace;letter-spacing:.4px}.paytm-status .status-dot{width:6px;height:6px;border-radius:50%;background:#e0bc4d;box-shadow:0 0 8px rgba(224,188,77,.7)}.paytm-status .status-dots{color:#d9b43a;letter-spacing:1px;animation:paytmDots 1.1s steps(4,end) infinite}.paytm-status.paytm-success-state{background:rgba(27,103,70,.18);border-color:rgba(88,215,155,.28);color:#63dda5}.paytm-status.paytm-success-state .status-dot{background:#58d79b;box-shadow:0 0 9px rgba(88,215,155,.8)}.paytm-status.paytm-success-state .status-dots{display:none}.paytm-qr-note{color:#76849c;font-size:6.5px;line-height:1.4;margin:8px 0}.paytm-close-btn{background:transparent;border:1px solid rgba(255,255,255,.1);color:#8592aa;border-radius:6px;padding:7px 17px;font:800 7.5px ui-monospace,monospace}.paytm-result-view{text-align:center;padding:22px 12px 18px;min-height:360px;display:flex;flex-direction:column;align-items:center;justify-content:center}.paytm-result-orbit{position:relative;width:112px;height:112px;margin:8px auto 22px}.paytm-processing-ring{position:absolute;inset:8px;border:3px solid rgba(212,175,55,.16);border-top-color:#e0bc4d;border-right-color:#f1d56e;border-radius:50%;animation:paytmSpin 1.05s linear infinite;box-shadow:0 0 24px rgba(212,175,55,.12)}.paytm-result-orbit:before{content:"";position:absolute;inset:0;border:1px dashed rgba(255,255,255,.10);border-radius:50%;animation:paytmSpinReverse 2.8s linear infinite}.paytm-orbit-dot{position:absolute;left:50%;top:0;width:8px;height:8px;margin-left:-4px;border-radius:50%;background:#f2d46d;box-shadow:0 0 13px rgba(242,212,109,.9);animation:paytmOrbit 2.2s linear infinite}.paytm-processing-core{position:absolute;inset:28px;border-radius:50%;display:grid;place-items:center;background:radial-gradient(circle at 35% 30%,#293b69,#101a32);border:1px solid rgba(212,175,55,.32);box-shadow:inset 0 0 22px rgba(0,0,0,.35),0 8px 30px rgba(0,0,0,.3)}.paytm-processing-core span{font:900 31px Arial;color:#e0bc4d;animation:paytmCorePulse 1.2s ease-in-out infinite}.paytm-result-kicker{font:900 7px ui-monospace,monospace;letter-spacing:2.2px;color:#d9b43a;margin-bottom:8px}.paytm-result-kicker.success{color:#58d79b}.paytm-result-title{font:900 17px ui-monospace,monospace;letter-spacing:.8px;color:#f0eee8}.paytm-result-title.success{color:#72e2ae}.paytm-result-message{color:#8795ad;font:600 8px/1.7 ui-monospace,monospace;margin:10px auto 15px;max-width:270px}.paytm-result-progress{width:205px;height:4px;border-radius:99px;background:#202c43;overflow:hidden;margin-bottom:12px}.paytm-result-progress span{display:block;width:45%;height:100%;border-radius:inherit;background:linear-gradient(90deg,#d9b43a,#f5db78);animation:paytmProcessingBar 1.35s ease-in-out infinite}.paytm-result-status{display:inline-flex;align-items:center;gap:6px;padding:8px 11px;border-radius:999px;background:rgba(212,175,55,.07);border:1px solid rgba(212,175,55,.16);color:#cdb75f;font:900 6.5px ui-monospace,monospace;letter-spacing:.45px}.paytm-result-status i{width:6px;height:6px;border-radius:50%;background:#e0bc4d;box-shadow:0 0 8px rgba(224,188,77,.8);animation:paytmStatusPulse 1s ease-in-out infinite}.paytm-result-status.success{color:#62dba4;background:rgba(88,215,155,.08);border-color:rgba(88,215,155,.22)}.paytm-result-status.success i{background:#58d79b;box-shadow:0 0 9px rgba(88,215,155,.8);animation:none}.paytm-success-circle{width:106px;height:106px;border-radius:50%;display:grid;place-items:center;margin:8px auto 22px;background:radial-gradient(circle at 35% 30%,#28563f,#10291f 70%);border:1px solid rgba(88,215,155,.34);box-shadow:0 0 0 8px rgba(88,215,155,.035),0 0 38px rgba(88,215,155,.16)}.paytm-success-circle span{width:62px;height:62px;border-radius:50%;display:grid;place-items:center;border:2px solid #58d79b;color:#69e1aa;font:900 35px Arial;animation:paytmSuccessPop .55s cubic-bezier(.2,.9,.2,1) both}.paytm-result-amount{color:#f0eee8;font:900 25px ui-monospace,monospace;margin:2px 0 8px}@keyframes paytmSpin{to{transform:rotate(360deg)}}@keyframes paytmSpinReverse{to{transform:rotate(-360deg)}}@keyframes paytmOrbit{to{transform:rotate(360deg)}}@keyframes paytmCorePulse{0%,100%{transform:scale(.92);opacity:.7}50%{transform:scale(1.08);opacity:1}}@keyframes paytmProcessingBar{0%{transform:translateX(-120%);width:35%}50%{width:55%}100%{transform:translateX(430%);width:35%}}@keyframes paytmStatusPulse{0%,100%{opacity:.45;transform:scale(.85)}50%{opacity:1;transform:scale(1.15)}}@keyframes paytmSuccessPop{0%{transform:scale(.45);opacity:0}70%{transform:scale(1.08);opacity:1}100%{transform:scale(1);opacity:1}}@keyframes paytmDots{0%{opacity:.2}50%{opacity:1}100%{opacity:.2}}@media(max-height:720px){.paytm-qr-card{padding:10px}.paytm-qr-image-wrap{width:210px;height:210px}.paytm-scan-label,.paytm-app-title,.paytm-app-buttons,.paytm-download-btn,.paytm-countdown-box,.paytm-check-btn{width:210px}.paytm-app-btn{height:44px}.paytm-pay-badges{margin-bottom:7px}.paytm-qr-amount{font-size:27px}.paytm-qr-note{margin:5px 0}}@media(min-width:700px){.deposit-wrap{max-width:620px}.paytm-qr-card{width:380px}.deposit-card{padding:18px}.deposit-title,.deposit-history-title{font-size:11px}}
</style>
<script>
function copyApiText(text){
  if(!text)return;
  const value=String(text);
  const done=()=>showToast('Copied to clipboard');
  const fail=()=>{
    try{
      const ta=document.createElement('textarea');
      ta.value=value; ta.setAttribute('readonly','');
      ta.style.position='fixed'; ta.style.opacity='0';
      document.body.appendChild(ta); ta.select();
      const ok=document.execCommand('copy');
      ta.remove();
      if(ok){done();return;}
    }catch(e){}
    showToast('Copy failed');
  };
  if(navigator.clipboard&&window.isSecureContext){
    navigator.clipboard.writeText(value).then(done).catch(fail);
  }else{fail();}
}
function copyApiCodeBlock(id,button){
  const el=document.getElementById(id);
  if(!el)return;
  const value=el.textContent||'';
  if(!value.trim()){
    showToast('Documentation is still loading.');
    return;
  }
  const old=button?button.innerHTML:'';
  const done=()=>{
    if(button){button.innerHTML='✓ COPIED';setTimeout(()=>{button.innerHTML=old||'📋 COPY';},1400);}
  };
  const fail=()=>{
    if(button){button.innerHTML='COPY FAILED';setTimeout(()=>{button.innerHTML=old||'📋 COPY';},1400);}
  };
  const fallback=()=>{
    try{
      const ta=document.createElement('textarea');
      ta.value=value;ta.setAttribute('readonly','');
      ta.style.position='fixed';ta.style.left='-9999px';ta.style.top='0';
      document.body.appendChild(ta);ta.focus();ta.select();
      const ok=document.execCommand('copy');
      ta.remove();
      if(ok){done();showToast('Documentation copied.');return;}
    }catch(e){}
    fail();showToast('Copy failed');
  };
  if(navigator.clipboard&&window.isSecureContext){
    navigator.clipboard.writeText(value).then(()=>{done();showToast('Documentation copied.');}).catch(fallback);
  }else{fallback();}
}
function apiBaseForDocs(){return window.location.origin + window.location.pathname + '?api=v1_order';}
function renderApiWebsiteUrl(){
  const el=document.getElementById('apiWebsiteUrlDisplay');
  if(el)el.textContent=apiBaseForDocs();
}
async function loadApiProductBrowser(){
  const host=document.getElementById('apiProductBrowser');
  if(!host)return;
  host.innerHTML='<div class="api-browser-loading">Loading products…</div>';
  try{
    const d=await apiCall('api_catalog_preview');
    const products=Array.isArray(d.products)?d.products:[];
    if(!products.length){host.innerHTML='<div class="api-browser-empty">No active products available.</div>';return;}
    host.innerHTML=products.map((p,i)=>`
      <div class="api-product-item">
        <button type="button" class="api-product-toggle" onclick="toggleApiProduct(${i})">
          <span>📦 ${escapeHtml(p.name)}</span><span>▾</span>
        </button>
        <div id="api-product-${i}" class="api-duration-list">
          ${(p.plans||[]).length ? (p.plans||[]).map(pl=>`<div class="api-duration-row">
            <div><b>${escapeHtml(pl.duration_label)}</b><small>₹${Number(pl.price||0).toFixed(2)} • ${pl.stock===null?'Maintenance':'Availability protected'}</small></div>
            <div class="api-duration-id"><span>ID ${Number(pl.api_product_id||pl.duration_id||pl.id)}</span><button type="button" onclick="copyApiText('${Number(pl.api_product_id||pl.duration_id||pl.id)}')">COPY</button></div>
          </div>`).join('') : '<div class="api-browser-empty">No active durations.</div>'} 
        </div>
      </div>`).join('');
    window.__apiBrowserProducts=products;
  }catch(e){host.innerHTML='<div class="api-browser-empty">Unable to load products. Please refresh and try again.</div>';}
}
function toggleApiProduct(i){const el=document.getElementById('api-product-'+i);if(el)el.classList.toggle('open');}

async function loadApiVariantBrowser(){
  const host=document.getElementById('apiVariantBrowser');
  if(!host)return;
  host.innerHTML='<div class="api-browser-loading">Loading variants…</div>';
  try{
    const d=await apiCall('api_catalog_preview');
    const products=Array.isArray(d.products)?d.products:[];
    const variants=products.flatMap(p=>(Array.isArray(p.plans)?p.plans:[]).map(pl=>({product:p,plan:pl})));
    const count=document.getElementById('apiVariantCount');
    if(count)count.textContent=variants.length+' VARIANTS';
    if(!variants.length){host.innerHTML='<div class="api-browser-empty">No active variants available.</div>';return;}
    host.innerHTML=products.map((p,i)=>{
      const plans=Array.isArray(p.plans)?p.plans:[];
      return `<div class="api-video-product">
        <button type="button" class="api-video-product-head" onclick="toggleApiProduct(${i})"><span>${escapeHtml(p.name)}</span><span class="api-video-chevron">▾</span></button>
        <div id="api-product-${i}" class="api-video-plan-list">
          ${plans.map(pl=>{
            const id=Number(pl.duration_id||pl.api_product_id||pl.id);
            const stock=pl.stock===null?'Maintenance':Number(pl.stock||0);
            const available=pl.stock===null||Number(pl.stock||0)>0;
            return `<div class="api-video-plan-row">
              <span class="api-video-plan-id">#${id}</span>
              <div class="api-video-plan-info"><b>${escapeHtml(pl.duration_label||'Duration')}</b><span>₹${Number(pl.price||0).toFixed(2)} · <em class="${available?'stock-ok':'stock-out'}">${available?'✓ In stock':'✕ Out of stock'}</em> · 📋 tap to copy variant_id</span></div>
              <button type="button" class="api-video-copy-id" onclick="event.stopPropagation();copyApiText('${id}')">COPY</button>
            </div>`;
          }).join('')}
        </div>
      </div>`;
    }).join('');
  }catch(e){host.innerHTML='<div class="api-browser-empty">Unable to load variants. Please refresh and try again.</div>';}
}

function renderApiDocumentation(){
  const base=apiBaseForDocs();
  renderApiWebsiteUrl();
  const baseEl=document.getElementById('apiBaseUrlText'); if(baseEl)baseEl.textContent=base;
  const fullBase=document.getElementById('fullApiBaseUrl'); if(fullBase)fullBase.textContent=base;

  // PHP-only reseller API documentation. Uses the real website reseller API key.
  // No Python example, master key, second token, or supplier credential is exposed.
  const php='<'+ '?php\n' + `$apiBase = "${base}";
$apiKey = "YOUR_RESELLER_API_KEY";

$data = [
    'action'     => 'buy',
    'variant_id' => 201,                    // Use the real Variant / Duration ID from My API
    'quantity'   => 1,                      // Optional — maximum 10 per request
];

$headers = [
    'Content-Type: application/x-www-form-urlencoded',
    'Accept: application/json',
    'X-API-Key: ' . $apiKey,
];

$ch = curl_init($apiBase);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($data),
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response = curl_exec($ch);
if ($response === false) {
    echo "cURL error: " . curl_error($ch);
    curl_close($ch);
    exit;
}
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);
if (!is_array($result)) {
    echo "Invalid response from server";
} elseif (($result['ok'] ?? false) === true) {
    echo "Key: " . ($result['key'] ?? '');
    echo " | Order: " . ($result['order_id'] ?? '');
    echo " | Expires: " . ($result['expires_at'] ?? '');
} else {
    echo "Error: " . ($result['error'] ?? ($result['message'] ?? 'Unknown error'));
    if (isset($result['shortfall'])) {
        echo " (need ₹" . $result['shortfall'] . " more)";
    }
}`;
  const php2=document.getElementById('fullPhpApiCode'); if(php2)php2.textContent=php;
}
function showApiHome(){renderApiDocumentation();navigate('apiPage',document.querySelector('[data-page="apiPage"]'));loadApiVariantBrowser();window.scrollTo({top:0,behavior:'auto'});}
function showApiProductsPage(){navigate('apiProductsPage',null);loadApiProductsDirectory();window.scrollTo({top:0,behavior:'auto'});}
async function loadApiProductsDirectory(){
  const host=document.getElementById('apiProductsDirectory');
  if(!host)return;
  host.innerHTML='<div class="api-browser-loading">Loading products…</div>';
  try{
    const d=await apiCall('api_catalog_preview');
    const products=Array.isArray(d.products)?d.products:[];
    if(!products.length){host.innerHTML='<div class="api-browser-empty">No active products available.</div>';return;}
    host.innerHTML=products.map((p,i)=>{
      const plans=Array.isArray(p.plans)?p.plans:[];
      return `<div class="api-product-item api-directory-product">
        <button type="button" class="api-product-toggle" onclick="toggleApiDirectoryProduct(${i})">
          <span>📦 ${escapeHtml(p.name)} <small class="api-parent-id">Product #${Number(p.id||0)}</small></span><span id="api-dir-arrow-${i}">▾</span>
        </button>
        <div id="api-directory-product-${i}" class="api-duration-list">
          ${plans.length ? plans.map(pl=>`<div class="api-duration-row api-directory-duration-row">
            <div><b>${escapeHtml(pl.duration_label||'Duration')}</b><small>Price: ₹${Number(pl.price||0).toFixed(2)} • ${pl.stock===null?'Maintenance':'Availability protected'}</small></div>
            <div class="api-duration-id"><span>ID ${Number(pl.duration_id||pl.api_product_id||pl.id)}</span><button type="button" onclick="event.stopPropagation();copyApiText('${Number(pl.duration_id||pl.api_product_id||pl.id)}')">COPY</button></div>
          </div>`).join('') : '<div class="api-browser-empty">No active durations.</div>'}
        </div>
      </div>`;
    }).join('');
  }catch(e){host.innerHTML='<div class="api-browser-empty">Unable to load products. Please refresh and try again.</div>';}
}
function toggleApiDirectoryProduct(i){
  const el=document.getElementById('api-directory-product-'+i);
  if(el)el.classList.toggle('open');
  const arrow=document.getElementById('api-dir-arrow-'+i);
  if(arrow && el)arrow.textContent=el.classList.contains('open')?'▴':'▾';
}
function showFullApiDocs(){renderApiDocumentation();navigate('apiDocsPage',null);window.scrollTo({top:0,behavior:'auto'});}
async function loadMyApiKeyInfo(){
    renderApiDocumentation();
    renderApiWebsiteUrl();
    const keyEl = document.getElementById('apiKeyDisplay');
    if(!keyEl) return;
    try{
        const d = await apiCall('api_key_info');
        keyEl.textContent = d.api_key ? (d.api_key.key_prefix + '••••••••••••••••  (generated ' + d.api_key.created_at + ')') : 'No key generated yet';
    }catch(e){ keyEl.textContent = 'No key generated yet'; }
}
async function generateMyApiKey(){
    renderApiWebsiteUrl();
    try{
        const d = await apiCall('api_key_generate', { method:'POST' });
        document.getElementById('apiKeyDisplay').textContent = d.api_key;
        showToast('New API key generated — copy it now, it will not be shown again.');
    }catch(e){ showToast(e.message || 'Could not generate an API key.'); }
}
</script>

    </div>

<!-- =====================================================
         BOTTOM NAV
         ===================================================== -->

    <nav class="bottom-nav">

        <button
            class="nav-btn active"
            data-page="homePage"
            onclick="navigate('homePage',this)"
        >
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.8 12 3l9 7.8v9.2a1 1 0 0 1-1 1h-5.2v-6.2H9.2V21H4a1 1 0 0 1-1-1z"/><path d="M8.7 21v-6.2h6.6V21"/></svg></span>
            <span>Home</span>
        </button>


        <button
            class="nav-btn"
            data-page="storePage"
            onclick="navigate('storePage',this)"
        >
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 8h14l1 12H4L5 8Z"/><path d="M8 8a4 4 0 0 1 8 0"/><path d="M9 12h.01M15 12h.01"/></svg></span>
            <span>Store</span>
        </button>


        <button
            class="nav-btn"
            data-page="depositPage"
            onclick="navigate('depositPage',this)"
        >
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M7 14h4"/></svg></span>
            <span>Deposit</span>
        </button>


        <button
            class="nav-btn"
            data-page="keyPage"
            onclick="navigate('keyPage',this)"
        >
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 8.5a4.5 4.5 0 1 1-2.1 4L21 4v4h-3v3h-3l-.9.9"/><circle cx="10" cy="13" r=".7"/></svg></span>
            <span>My Key</span>
        </button>


        <button
            class="nav-btn"
            data-page="apiPage"
            onclick="navigate('apiPage',this)"
        >
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19a9 9 0 0 1 0-14M9 16a5 5 0 0 1 0-8M13 13a1 1 0 0 1 0-2M16 8l5-3v14l-5-3"/></svg></span>
            <span>API</span>
        </button>


        <button
            class="nav-btn"
            onclick="logout()"
        >
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v16h-6"/><path d="M3 12h12M11 8l4 4-4 4"/></svg></span>
            <span>Logout</span>
        </button>

    </nav>

</div>


<!-- Toast -->
<div id="toast"></div>


<script>
/* =========================================================
   BACKEND API HELPER — every call below hits the real PHP
   endpoints in /api (session-cookie authenticated). No demo
   data, no localStorage business data past this point.
   ========================================================= */
async function apiCall(path, options = {}) {
    const action = path.replace(/\.php$/, '');
    let url = 'index.php?api=' + encodeURIComponent(action);
    if (options.params) {
        for (const k in options.params) {
            if (options.params[k] === undefined || options.params[k] === null || options.params[k] === '') continue;
            url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(options.params[k]);
        }
    }
    const res = await fetch(url, {
        method: options.method || 'GET',
        headers: Object.assign({ 'Content-Type': 'application/json' }, window.__csrfToken ? { 'X-CSRF-Token': window.__csrfToken } : {}),
        credentials: 'same-origin',
        body: options.body ? JSON.stringify(options.body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.status === 'error') {
        const err = new Error(data.message || 'Request failed.');
        err.status = res.status;
        err.data = data;
        throw err;
    }
    return data;
}

/* =========================================================
   FLASH STAR APPLICATION JAVASCRIPT
   ========================================================= */

const splash = document.getElementById("splash");
const loginScreen = document.getElementById("loginScreen");
const app = document.getElementById("app");
const loginForm = document.getElementById("loginForm");
const usernameInput = document.getElementById("usernameInput");
const passwordInput = document.getElementById("passwordInput");
const dashboardUsername = document.getElementById("dashboardUsername");
const adminApp = document.getElementById("adminApp");
const adminUsername = document.getElementById("adminUsername");
const headerUsername = document.getElementById("headerUsername");
const loginMessage = document.getElementById("loginMessage");
const eyeButton = document.getElementById("eyeButton");
const eyeIcon = document.getElementById("eyeIcon");


/* =========================================================
   SPLASH / REFRESH BOOT
   Keep the loading screen visible for at least 1.5s, but never
   leave a blank dashboard if a refresh/session request is slow.
   ========================================================= */
let splashMinimumReady = false;
let splashBootReady = false;
let splashHidden = false;

function hideSplash(){
    if(splashHidden || !splash) return;
    splashHidden = true;
    splash.classList.add("hide");
    setTimeout(function(){ if(splash && splash.parentNode) splash.remove(); },600);
}
function tryHideSplash(){
    if(splashMinimumReady && splashBootReady) hideSplash();
}
setTimeout(function(){ splashMinimumReady=true; tryHideSplash(); },1500);
// Hard fail-safe: the login/dashboard can render even if a secondary request hangs.
setTimeout(function(){
    splashMinimumReady=true;
    if(!splashBootReady){
        splashBootReady=true;
        tryHideSplash();
    }
},5000);


/* =========================================================
   PASSWORD SHOW / HIDE
   ========================================================= */

eyeButton.addEventListener("click",function(){

    const hidden =
        passwordInput.type === "password";

    passwordInput.type =
        hidden ? "text" : "password";

    eyeButton.setAttribute(
        "aria-label",
        hidden ? "Hide password" : "Show password"
    );

    if(hidden){

        eyeIcon.innerHTML = `
            <path d="M3 3l18 18"/>
            <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
            <path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6 0 9.5 7 9.5 7a16.4 16.4 0 0 1-3.1 3.8"/>
            <path d="M6.6 6.6C4 8.2 2.5 12 2.5 12s3.5 7 9.5 7a9.7 9.7 0 0 0 4.1-.9"/>
        `;

    }else{

        eyeIcon.innerHTML = `
            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>
            <circle cx="12" cy="12" r="2.5"/>
        `;

    }

});


/* =========================================================
   LOGIN → DASHBOARD
   ========================================================= */

const topResellersList = document.getElementById("topResellersList");
async function loadTopResellers(){
    if(!topResellersList)return;
    try{
        const d=await apiCall('top_resellers');
        const list=Array.isArray(d.resellers)?d.resellers:[];
        if(!list.length){
            topResellersList.innerHTML='<div class="reseller-empty">No successful purchases this month yet.</div>';
            return;
        }
        topResellersList.innerHTML=list.slice(0,5).map((u,i)=>{
            const name=String(u.username||'User').replace(/^@/,'');
            const keys=Number(u.keys||0);
            const amount=Number(u.amount||0);
            const initials=name.replace(/[^a-zA-Z0-9]/g,'').slice(0,2).toUpperCase()||'US';
            return `<div class="reseller"><div class="rank ${i===0?'rank-first':''}">#${i+1}</div><div class="reseller-avatar">${escapeHtml(initials)}</div><div class="reseller-info"><div class="reseller-name">@${escapeHtml(name)}</div><div class="reseller-sold">${keys} ${keys===1?'Key':'Keys'}</div></div><div class="reseller-amount">₹${amount.toFixed(2)}</div></div>`;
        }).join('');
    }catch(e){
        topResellersList.innerHTML='<div class="reseller-empty">Unable to load reseller rankings.</div>';
    }
}

/* =========================================================
   ADMIN PANEL — tabs
   ========================================================= */
function adminSwitchTab(tab){
  document.querySelectorAll('.admin-tab-btn').forEach(b=>b.classList.toggle('active', b.dataset.tab===tab));
  document.querySelectorAll('.admin-tab-panel').forEach(p=>p.classList.toggle('active', p.id==='adminTab-'+tab));
  const loaders={
    users: loadAdminUsers, wallet: loadAdminUsers,
    store: loadAdminCatalog, maintenance: loadAdminCatalog,
    orders: ()=>loadAdminOrders(1), apireports: ()=>loadApiReports(1),
    settings: ()=>{loadAdminSettings();loadUserPriceList();}, support: loadAdminSettings, logs: ()=>loadAdminLogs(1),
  };
  if(loaders[tab]) loaders[tab]();
}
function openAdminModal(html){const b=document.getElementById('adminModalBody');if(b)b.innerHTML=html;const m=document.getElementById('adminModal');if(m)m.style.display='flex';}
function closeAdminModal(){const m=document.getElementById('adminModal');if(m)m.style.display='none';}

async function loadUserPriceList(){
  const box=document.getElementById('userPriceListBody'); if(!box) return;
  box.textContent='Loading…';
  try{
    const d=await apiCall('admin_user_pricelist_get');
    const plans=Array.isArray(d.plans)?d.plans:[];
    if(!plans.length){ box.textContent='No active plans yet.'; return; }
    let html='<div class="admin-management-grid" style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:center">';
    html+='<b>Product / Duration</b><b>User Price (₹)</b><b></b>';
    plans.forEach(function(p){
      const val = (p.user_price===null||p.user_price===undefined) ? '' : Number(p.user_price).toFixed(2);
      html += '<span>'+escapeHtml(p.product_name)+' — '+escapeHtml(p.duration_label)+' <span style="opacity:.6">(default ₹'+Number(p.default_price).toFixed(2)+')</span></span>'
            + '<input type="number" step="0.01" min="0" data-plan-id="'+p.plan_id+'" value="'+val+'" placeholder="default" style="width:120px">'
            + '<button type="button" class="admin-secondary" onclick="saveUserPrice('+p.plan_id+',this)">SAVE</button>';
    });
    html+='</div>';
    box.innerHTML=html;
  }catch(e){ box.textContent = e.message || 'Could not load the price list.'; }
}
async function saveUserPrice(planId, btn){
  const input=document.querySelector('input[data-plan-id="'+planId+'"]');
  const raw=input ? input.value.trim() : '';
  const price = raw==='' ? null : Number(raw);
  if(price!==null && (!Number.isFinite(price) || price<0)){ showToast('Enter a valid price.'); return; }
  if(btn) btn.disabled=true;
  try{
    await apiCall('admin_user_pricelist_set',{method:'POST',body:{plan_id:planId, price}});
    showToast(price===null ? 'Reset to default price.' : 'User price saved.');
  }catch(e){ showToast(e.message||'Could not save price.'); }
  finally{ if(btn) btn.disabled=false; }
}
async function generateUserCredentials(){try{const d=await apiCall('admin_generate_credentials');if(d.csrf_token)window.__csrfToken=d.csrf_token;document.getElementById('newUserUsername').value=d.username;document.getElementById('newUserPassword').value=d.password;}catch(e){showToast(e.message||'Could not generate credentials.');}}
async function createNewUser(){const username=document.getElementById('newUserUsername').value.trim(),password=document.getElementById('newUserPassword').value;if(!username||!password){showToast('Generate or enter a username and password first.');return;}try{const d=await apiCall('admin_create_user',{method:'POST',body:{username,password}}),u=d.user;document.getElementById('createdUsername').textContent=u.username;document.getElementById('createdPassword').textContent=u.password;document.getElementById('createdCredentials').classList.add('show');showToast('User account created successfully — these credentials work on the login form right now.');await loadAdminUsers();await generateUserCredentials();}catch(e){showToast(e.message||'Could not create user.');}}

let adminUsersCache=[];
async function loadAdminUsers(){
  const list=document.getElementById('adminUsersList');
  try{
    const d=await apiCall('admin_users'); if(d.csrf_token)window.__csrfToken=d.csrf_token;
    adminUsersCache=Array.isArray(d.users)?d.users:[];
    if(list){
      renderAdminUsers();
    }
    const walletList=document.getElementById('adminWalletList');
    if(walletList){
      walletList.innerHTML = adminUsersCache.length ? adminUsersCache.map(u=>`
        <div class="admin-user-row">
          <div><div class="admin-user-name">@${escapeHtml(u.username)}</div><div class="admin-user-meta">Balance: ₹${Number(u.balance).toFixed(2)}</div></div>
          <div style="display:flex;gap:6px;align-items:center;">
            <input type="number" min="0" step="0.01" id="walletAmt-${Number(u.id)}" style="width:90px;background:#0b0f19;border:1px solid rgba(212,175,55,.25);border-radius:8px;color:#fff;padding:6px;" placeholder="₹ amount">
            <button class="admin-mini-btn" type="button" onclick="adminAddBalance(${Number(u.id)})">ADD</button>
            <button class="admin-mini-btn" type="button" onclick="adminRemoveBalance(${Number(u.id)})">REMOVE</button>
          </div>
        </div>`).join('') : '<div class="admin-empty">No user accounts yet.</div>';
    }
  }catch(e){ if(list) list.innerHTML='<div class="admin-empty">Unable to load user accounts.</div>'; }
}
function renderAdminUsers(){
  const list=document.getElementById('adminUsersList'); if(!list)return;
  const q=(document.getElementById('adminUserSearch')?.value||'').trim().toLowerCase();
  const rows=adminUsersCache.filter(u=>!q || String(u.username||'').toLowerCase().includes(q));
  list.innerHTML=rows.length ? rows.map(u=>{
        const badge = u.status==='active' ? '<span class="admin-user-status active">ACTIVE</span>' : (u.status==='banned' ? '<span class="admin-user-status disabled">BANNED</span>' : '<span class="admin-user-status disabled">DISABLED</span>');
        return `<div class="admin-user-row">
          <div style="min-width:0"><div class="admin-user-name">@${escapeHtml(u.username)}</div><div class="admin-user-meta">₹${Number(u.balance).toFixed(2)} • Created ${escapeHtml(u.created_at||'')}</div></div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end;">
            ${badge}
            <button class="admin-mini-btn" type="button" onclick="toggleAdminUser(${Number(u.id)},'${u.status==='active'?'disabled':'active'}')">${u.status==='active'?'DISABLE':'ENABLE'}</button>
            ${u.status==='banned' ? `<button class="admin-mini-btn" type="button" onclick="adminUnbanUser(${Number(u.id)})">UNBAN</button>` : `<button class="admin-mini-btn" type="button" onclick="adminBanUser(${Number(u.id)})">BAN</button>`}
            <button class="admin-mini-btn" type="button" onclick="adminAddBalancePrompt(${Number(u.id)})">ADD BALANCE</button>
            <button class="admin-mini-btn" type="button" onclick="adminRemoveBalancePrompt(${Number(u.id)})">REMOVE BALANCE</button>
            <button class="admin-mini-btn" type="button" onclick="adminResetPassword(${Number(u.id)})">RESET PW</button>
            <button class="admin-mini-btn" type="button" onclick="adminViewUserDetails(${Number(u.id)})">DETAILS</button>
            <button class="admin-mini-btn" type="button" onclick="adminDeleteUser(${Number(u.id)})">DELETE</button>
          </div>
        </div>`;
      }).join('') : '<div class="admin-empty">'+(q?'No users found.':'No user accounts created yet.')+'</div>';
}
function filterAdminUsers(){renderAdminUsers();}

async function toggleAdminUser(id,status){try{await apiCall('admin_toggle_user',{method:'POST',body:{id,status}});showToast(status==='active'?'User enabled.':'User disabled.');await loadAdminUsers();}catch(e){showToast(e.message||'Could not update user.');}}
async function adminBanUser(id){if(!confirm('Ban this user? They will not be able to log in until unbanned.'))return;try{await apiCall('admin_ban_user',{method:'POST',body:{id}});showToast('User banned.');await loadAdminUsers();}catch(e){showToast(e.message||'Could not ban user.');}}
async function adminUnbanUser(id){try{await apiCall('admin_unban_user',{method:'POST',body:{id}});showToast('User unbanned.');await loadAdminUsers();}catch(e){showToast(e.message||'Could not unban user.');}}
async function adminDeleteUser(id){if(!confirm('Delete this user? This cannot be undone.'))return;try{const d=await apiCall('admin_delete_user',{method:'POST',body:{id}});showToast(d.message||'User deleted.');await loadAdminUsers();}catch(e){showToast(e.message||'Could not delete user.');}}
async function adminResetPassword(id){try{const d=await apiCall('admin_reset_password',{method:'POST',body:{id}});openAdminModal(`<div class="admin-card-title">Password Reset</div><div class="admin-card-note">Save this now — it will not be shown again.</div><div class="cred"><span>New password</span><code>${escapeHtml(d.password)}</code></div>`);showToast('Password reset.');}catch(e){showToast(e.message||'Could not reset password.');}}
async function adminAddBalance(id){const el=document.getElementById('walletAmt-'+id);const v=Number(el?el.value:0);if(!v||v<=0)return showToast('Enter a positive amount.');try{await apiCall('admin_add_balance',{method:'POST',body:{id,amount:v}});showToast('Balance added.');await loadAdminUsers();}catch(e){showToast(e.message||'Could not add balance.');}}
async function adminRemoveBalance(id){const el=document.getElementById('walletAmt-'+id);const v=Number(el?el.value:0);if(!v||v<=0)return showToast('Enter a positive amount.');try{await apiCall('admin_remove_balance',{method:'POST',body:{id,amount:v}});showToast('Balance removed.');await loadAdminUsers();}catch(e){showToast(e.message||'Could not remove balance (check the amount does not exceed the balance).');}}
async function adminAddBalancePrompt(id){const u=adminUsersCache.find(x=>Number(x.id)===Number(id));const v=prompt('Add balance for @'+(u?u.username:'user')+' (₹):','');if(v===null)return;const amount=Number(v);if(!amount||amount<=0)return showToast('Enter a positive amount.');try{await apiCall('admin_add_balance',{method:'POST',body:{id,amount}});showToast('Balance added.');await loadAdminUsers();}catch(e){showToast(e.message||'Could not add balance.');}}
async function adminRemoveBalancePrompt(id){const u=adminUsersCache.find(x=>Number(x.id)===Number(id));const v=prompt('Remove balance from @'+(u?u.username:'user')+' (₹):','');if(v===null)return;const amount=Number(v);if(!amount||amount<=0)return showToast('Enter a positive amount.');try{await apiCall('admin_remove_balance',{method:'POST',body:{id,amount}});showToast('Balance removed.');await loadAdminUsers();}catch(e){showToast(e.message||'Could not remove balance.');}}
async function adminViewUserDetails(id){
  try{
    const d=await apiCall('admin_user_details',{params:{id}});
    const u=d.user;
    const orders=(d.orders||[]).slice(0,15).map(o=>`<div class="admin-user-meta">${escapeHtml(o.order_code)} — ${escapeHtml(o.product_name)} ${escapeHtml(o.duration_label)} — ₹${Number(o.total_price).toFixed(2)} — ${escapeHtml(o.status)} (${escapeHtml(o.source)})</div>`).join('') || '<div class="admin-empty">No orders yet.</div>';
    const wallet=(d.wallet_history||[]).slice(0,10).map(w=>`<div class="admin-user-meta">${escapeHtml(w.type)} ₹${Number(w.amount).toFixed(2)} → balance ₹${Number(w.balance_after).toFixed(2)} — ${escapeHtml(w.created_at)}</div>`).join('') || '<div class="admin-empty">No wallet activity yet.</div>';
    openAdminModal(`
      <div class="admin-card-title">@${escapeHtml(u.username)}</div>
      <div class="admin-user-meta">Status: ${escapeHtml(u.status)} • Balance: ₹${Number(u.balance).toFixed(2)} • Created ${escapeHtml(u.created_at||'')}</div>
      <div style="margin-top:12px;font-weight:800;font-size:12px;color:#f5d76e;">RECENT ORDERS</div>${orders}
      <div style="margin-top:12px;font-weight:800;font-size:12px;color:#f5d76e;">WALLET HISTORY</div>${wallet}
    `);
  }catch(e){showToast(e.message||'Could not load user details.');}
}

/* =========================================================
   ADMIN PANEL — categories / products / plans / keys
   ========================================================= */
let adminCatalogCache={categories:[],products:[],plans:[],keys:[]};
async function loadAdminCatalog(){
  try{
    const d=await apiCall('admin_catalog'); if(d.csrf_token)window.__csrfToken=d.csrf_token;
    adminCatalogCache={categories:d.categories||[],products:d.products||[],plans:d.plans||[],keys:d.keys||[]};
    const fill=(id,items,label)=>{const el=document.getElementById(id);if(!el)return;const cur=el.value;el.innerHTML='<option value="">Select '+label+'</option>'+items.map(x=>`<option value="${Number(x.id)}">${escapeHtml(x.name||(x.product_name+' — '+x.duration_label))}</option>`).join('');if(cur)el.value=cur;};
    fill('adminProductCategory',adminCatalogCache.categories,'category');
    fill('adminPlanProduct',adminCatalogCache.products,'product');
    fill('adminKeyPlan',adminCatalogCache.plans,'variation');
    toggleAddPlanMode();

    const catList=document.getElementById('adminCategoriesList');
    if(catList) catList.innerHTML = adminCatalogCache.categories.length ? adminCatalogCache.categories.map(c=>`
      <div class="admin-user-row"><div><b>${escapeHtml(c.name)}</b><div class="admin-user-meta">Sort ${Number(c.sort_order)} • ${escapeHtml(c.status)}</div></div>
      <div style="display:flex;gap:6px;"><button class="admin-mini-btn" type="button" onclick="adminEditCategory(${Number(c.id)})">EDIT</button><button class="admin-mini-btn" type="button" onclick="adminDeleteCategory(${Number(c.id)})">DELETE</button></div></div>
    `).join('') : '<div class="admin-empty">No categories yet.</div>';

    const prodList=document.getElementById('adminProductsList');
    const prodHtml = adminCatalogCache.products.length ? adminCatalogCache.products.map(p=>`
      <div class="admin-user-row"><div><b>${escapeHtml(p.name)}</b><div class="admin-user-meta">${escapeHtml(p.category_name)} • ${escapeHtml(p.mode)} mode • ${escapeHtml(p.status)} ${p.maintenance==='on'?'<span class="maintenance-badge">MAINTENANCE</span>':''}</div></div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;"><button class="admin-mini-btn" type="button" onclick="adminEditProduct(${Number(p.id)})">EDIT</button><button class="admin-mini-btn" type="button" onclick="adminToggleProductMaintenance(${Number(p.id)},'${p.maintenance==='on'?'off':'on'}')">${p.maintenance==='on'?'END MAINTENANCE':'START MAINTENANCE'}</button><button class="admin-mini-btn" type="button" onclick="adminDeleteProduct(${Number(p.id)})">DELETE</button></div></div>
    `).join('') : '<div class="admin-empty">No products yet.</div>';
    if(prodList) prodList.innerHTML=prodHtml;
    const maintList=document.getElementById('adminMaintProductsList');
    if(maintList) maintList.innerHTML=prodHtml;

    const planList=document.getElementById('adminPlansList');
    if(planList) planList.innerHTML = adminCatalogCache.plans.length ? adminCatalogCache.plans.map(pl=>`
      <div class="admin-user-row"><div><b>${escapeHtml(pl.product_name)} — ${escapeHtml(pl.duration_label)}</b><div class="admin-user-meta">Sell ₹${Number(pl.default_price).toFixed(2)} • ${pl.provider_type==='dripstore'?'DRIPSTORE API • STOCK 99':(pl.provider_type==='keyspanel'||pl.stock_mode==='api'?'KEYSPANEL API • STOCK 99':'LOCAL STOCK '+Number(pl.stock||0))} • ${escapeHtml(pl.status)}</div></div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;"><span class="admin-user-status active">${Number(pl.stock||0)} STOCK</span><button class="admin-mini-btn" type="button" onclick="adminEditPlan(${Number(pl.id)})">EDIT</button><button class="admin-mini-btn" type="button" onclick="adminGoToPlanKeys(${Number(pl.id)})">KEYS</button><button class="admin-mini-btn" type="button" onclick="adminDeletePlan(${Number(pl.id)})">DELETE</button></div></div>
    `).join('') : '<div class="admin-empty">No variations yet.</div>';
  }catch(e){showToast(e.message||'Unable to load store data.');}
}
async function adminPost(action,body){const d=await apiCall(action,{method:'POST',body});if(d.csrf_token)window.__csrfToken=d.csrf_token;showToast(d.message||'Saved successfully.');await loadAdminCatalog();return d;}
async function adminAddCategory(){const name=document.getElementById('adminCategoryName').value.trim();const sort_order=Number(document.getElementById('adminCategorySort').value)||0;if(!name)return showToast('Enter a category name.');await adminPost('admin_add_category',{name,sort_order});document.getElementById('adminCategoryName').value='';}
function adminEditCategory(id){const c=adminCatalogCache.categories.find(x=>Number(x.id)===Number(id));if(!c)return;const name=prompt('Category name:',c.name);if(name===null)return;const sort=prompt('Sort order:',c.sort_order);if(sort===null)return;const status=confirm('OK = keep ACTIVE, Cancel = DISABLE this category')?'active':'disabled';adminPost('admin_update_category',{id,name:name.trim(),sort_order:Number(sort)||0,status});}
function adminDeleteCategory(id){if(!confirm('Delete this category? Products must be moved/removed first.'))return;adminPost('admin_delete_category',{id}).catch(e=>showToast(e.message||'Could not delete category.'));}

let createDurationCount=0;
function toggleCreatePlanMode(block){
  if(!block)return;
  const provider=block.querySelector('.create-plan-stock-mode')?.value||'local';
  const kpWrap=block.querySelector('.create-plan-keyspanel-wrap');
  const dsWrap=block.querySelector('.create-plan-dripstore-wrap');
  const keysWrap=block.querySelector('.create-plan-keys-wrap');
  if(kpWrap) kpWrap.style.display=provider==='keyspanel'?'block':'none';
  if(dsWrap) dsWrap.style.display=provider==='dripstore'?'block':'none';
  if(keysWrap) keysWrap.style.display=provider==='local'?'block':'none';
  const kp=block.querySelector('.create-plan-keyspanel-id');
  const ds=block.querySelector('.create-plan-dripstore-id');
  const keys=block.querySelector('.create-plan-keys');
  if(kp) kp.required=provider==='keyspanel';
  if(ds) ds.required=provider==='dripstore';
  if(keys) keys.disabled=provider!=='local';
}
function createDurationBlock(data={}){
  createDurationCount++;
  const host=document.getElementById('createPlanBlocks');
  if(!host)return;
  const block=document.createElement('div');
  block.className='create-plan-block';
  block.innerHTML=`
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
      <b style="color:#f5d76e;font-size:12px;">DURATION ${createDurationCount}</b>
      ${createDurationCount>1?'<button type="button" class="admin-mini-btn" onclick="this.closest(\'.create-plan-block\').remove()">REMOVE</button>':''}
    </div>
    <div class="admin-management-grid">
      <input class="create-plan-duration" placeholder="1 Day / 7 Days / 30 Days" value="${escapeHtml(data.duration_label||'')}">
      <input class="create-plan-price" type="number" min="0" step="0.01" placeholder="Selling price ₹" value="${data.default_price!==undefined?escapeHtml(data.default_price):''}">
    </div>
    <div class="admin-management-grid">
      <select class="create-plan-stock-mode" onchange="toggleCreatePlanMode(this.closest(\'.create-plan-block\'))">
        <option value="local" ${(data.provider_type||'local')==='local'?'selected':''}>LOCAL STORE</option>
        <option value="keyspanel" ${(data.provider_type||((data.stock_mode||'local')==='api'?'keyspanel':'local'))==='keyspanel'?'selected':''}>KEYSPANEL API</option>
        <option value="dripstore" ${(data.provider_type||'local')==='dripstore'?'selected':''}>DRIPSTORE API</option>
      </select>
    </div>
    <div class="create-plan-keyspanel-wrap admin-field" style="display:none;margin-top:8px">
      <label>KEYSPANEL VARIANT ID</label>
      <input class="create-plan-keyspanel-id" placeholder="ENTER KEYSPANEL VARIANT ID HERE" value="${escapeHtml(data.provider_variant_id||'')}">
    </div>
    <div class="create-plan-dripstore-wrap admin-field" style="display:none;margin-top:8px">
      <label>DRIPSTORE VARIANT ID</label>
      <input class="create-plan-dripstore-id" placeholder="ENTER DRIPSTORE VARIANT ID HERE" value="${escapeHtml(data.dripstore_variant_id||'')}">
      <div class="admin-card-note" style="margin-top:5px">DRIPSTORE API selected. Customer-facing stock is always 99; actual keys are generated by DRIPSTORE.</div>
    </div>
    </div>
    <div class="create-plan-keys-wrap" style="display:none;margin-top:8px">
      <label style="display:block;font-size:10px;font-weight:900;letter-spacing:.8px;color:#94a3b8;margin-bottom:5px">LOCAL LICENSE KEYS</label>
      <textarea class="create-plan-keys" placeholder="Paste multiple license keys, one per line"></textarea>
    </div>`;
  host.appendChild(block);
  toggleCreatePlanMode(block);
}
function resetCreatePlanBlocks(){
  const host=document.getElementById('createPlanBlocks'); if(!host)return;
  host.innerHTML=''; createDurationCount=0; createDurationBlock();
}
function addCreateDurationBlock(){createDurationBlock();}

async function adminAddProduct(){
  const category_id=Number(document.getElementById('adminProductCategory').value);
  const name=document.getElementById('adminProductName').value.trim();
  const description=document.getElementById('adminProductDescription').value.trim();
  if(!category_id||!name)return showToast('Select a category and enter product name.');

  const plans=[...document.querySelectorAll('#createPlanBlocks .create-plan-block')].map(block=>({
    duration_label:block.querySelector('.create-plan-duration')?.value.trim()||'',
    default_price:Number(block.querySelector('.create-plan-price')?.value),
    keys_text:block.querySelector('.create-plan-keys')?.value||'',
    provider_type:block.querySelector('.create-plan-stock-mode')?.value||'local',
    provider_variant_id:block.querySelector('.create-plan-keyspanel-id')?.value.trim()||'',
    dripstore_variant_id:block.querySelector('.create-plan-dripstore-id')?.value.trim()||''
  }));
  if(!plans.length)return showToast('Add at least one duration.');
  if(plans.some(x=>!x.duration_label||!Number.isFinite(x.default_price)||x.default_price<0)){
    return showToast('Every duration needs a valid duration and selling price.');
  }
  try{
    const d=await adminPost('admin_add_product',{category_id,name,description,plans});
    document.getElementById('adminProductName').value='';
    document.getElementById('adminProductDescription').value='';
    resetCreatePlanBlocks();
    showToast(d.message||'Product created.');
  }catch(e){showToast(e.message||'Could not create product.');}
}

function adminEditProduct(id){
  const p=adminCatalogCache.products.find(x=>Number(x.id)===Number(id));if(!p)return;
  const catOptions=adminCatalogCache.categories.map(c=>`<option value="${Number(c.id)}" ${Number(c.id)===Number(p.category_id)?'selected':''}>${escapeHtml(c.name)}</option>`).join('');
  openAdminModal(`
    <div class="admin-card-title">Edit Product</div>
    <div class="admin-field"><label>CATEGORY</label><select id="editProdCategory">${catOptions}</select></div>
    <div class="admin-field"><label>NAME</label><input id="editProdName" value="${escapeHtml(p.name)}"></div>
    <div class="admin-field"><label>DESCRIPTION</label><input id="editProdDesc" value="${escapeHtml(p.description||'')}"></div>
    <div class="admin-field"><label>STATUS</label><select id="editProdStatus"><option value="active" ${p.status==='active'?'selected':''}>Active</option><option value="disabled" ${p.status==='disabled'?'selected':''}>Disabled</option></select></div>
    <div class="admin-field"><label>MAINTENANCE</label><select id="editProdMaintenance"><option value="off" ${p.maintenance==='off'?'selected':''}>OFF</option><option value="on" ${p.maintenance==='on'?'selected':''}>ON</option></select></div>
    <div class="admin-actions"><button class="admin-primary" type="button" onclick="adminSaveProduct(${Number(id)})">SAVE</button></div>`);
}
async function adminSaveProduct(id){
  const body={id,category_id:Number(document.getElementById('editProdCategory').value),name:document.getElementById('editProdName').value.trim(),description:document.getElementById('editProdDesc').value.trim(),status:document.getElementById('editProdStatus').value,maintenance:document.getElementById('editProdMaintenance').value};
  try{await adminPost('admin_update_product',body);closeAdminModal();}catch(e){showToast(e.message||'Could not save product.');}
}
function adminDeleteProduct(id){const p=adminCatalogCache.products.find(x=>Number(x.id)===Number(id));if(!confirm('PERMANENT DELETE\n\n'+(p?p.name:'This product')+' and ALL of its durations, local keys and related orders will be permanently removed. This cannot be undone.\n\nContinue?'))return;adminPost('admin_delete_product',{id}).catch(e=>showToast(e.message||'Could not delete product.'));}
function adminToggleProductMaintenance(id,maintenance){adminPost('admin_toggle_product_maintenance',{id,maintenance}).catch(e=>showToast(e.message||'Could not update maintenance mode.'));}

function toggleAddPlanMode(){
  const mode=document.getElementById('adminPlanStockMode')?.value||'local';
  const provider=document.getElementById('adminPlanProviderWrap');
  const drip=document.getElementById('adminPlanDripstoreWrap');
  const keys=document.getElementById('adminPlanKeysWrap');
  if(provider)provider.style.display=mode==='keyspanel'?'block':'none';
  if(drip)drip.style.display=mode==='dripstore'?'block':'none';
  if(keys)keys.style.display=mode==='local'?'block':'none';
  const pid=document.getElementById('adminPlanProviderId'); if(pid)pid.required=mode==='keyspanel';
  const did=document.getElementById('adminPlanDripstoreId'); if(did)did.required=mode==='dripstore';
}
async function adminAddPlan(){
  const product_id=Number(document.getElementById('adminPlanProduct')?.value||0);
  const duration_label=(document.getElementById('adminPlanDuration')?.value||'').trim();
  const default_price=Number(document.getElementById('adminPlanPrice')?.value);
  const provider_type=document.getElementById('adminPlanStockMode')?.value||'local';
  const provider_variant_id=(document.getElementById('adminPlanProviderId')?.value||'').trim();
  const dripstore_variant_id=(document.getElementById('adminPlanDripstoreId')?.value||'').trim();
  const keys_text=document.getElementById('adminPlanKeys')?.value||'';
  if(!product_id||!duration_label||!Number.isFinite(default_price)||default_price<0)return showToast('Select product and enter duration + valid selling price.');
  if(provider_type==='keyspanel'&&!provider_variant_id)return showToast('Enter the KEYSPANEL Variant ID.');
  if(provider_type==='dripstore'&&!dripstore_variant_id)return showToast('Enter the DRIPSTORE Variant ID.');
  try{await adminPost('admin_add_plan',{product_id,duration_label,default_price,provider_type,provider_variant_id,dripstore_variant_id,keys_text});
    document.getElementById('adminPlanDuration').value='';document.getElementById('adminPlanPrice').value='';document.getElementById('adminPlanProviderId').value='';document.getElementById('adminPlanDripstoreId').value='';document.getElementById('adminPlanKeys').value='';
    document.getElementById('adminPlanStockMode').value='local';toggleAddPlanMode();
  }catch(e){showToast(e.message||'Could not create duration.');return;}
}
function toggleEditPlanMode(){
  const mode=document.getElementById('editPlanStockMode')?.value||'local';
  const wrap=document.getElementById('editPlanProviderWrap');
  const drip=document.getElementById('editPlanDripstoreWrap');
  const note=document.getElementById('editPlanModeNote');
  if(wrap)wrap.style.display=mode==='keyspanel'?'block':'none';
  if(drip)drip.style.display=mode==='dripstore'?'block':'none';
  if(note)note.textContent=mode==='keyspanel'?'KEYSPANEL selected: enter the KEYSPANEL Variant ID.':(mode==='dripstore'?'DRIPSTORE selected: enter the DRIPSTORE Variant ID.':'LOCAL selected: this duration uses local license keys.');
}
function adminEditPlan(id){
  const pl=adminCatalogCache.plans.find(x=>Number(x.id)===Number(id));if(!pl)return;
  openAdminModal(`
    <div class="admin-card-title">Edit Duration — ${escapeHtml(pl.product_name)}</div>
    <div class="admin-field"><label>DURATION</label><input id="editPlanDuration" value="${escapeHtml(pl.duration_label)}"></div>
    <div class="admin-field"><label>SELLING PRICE</label><input id="editPlanPrice" type="number" min="0" step="0.01" value="${Number(pl.default_price)}"></div>
    <div class="admin-field"><label>STOCK SOURCE</label><select id="editPlanStockMode" onchange="toggleEditPlanMode()">
      <option value="local" ${(pl.provider_type||((pl.stock_mode||'local')==='api'?'keyspanel':'local'))==='local'?'selected':''}>LOCAL STORE</option>
      <option value="keyspanel" ${(pl.provider_type||((pl.stock_mode||'local')==='api'?'keyspanel':'local'))==='keyspanel'?'selected':''}>KEYSPANEL API</option>
      <option value="dripstore" ${(pl.provider_type||'local')==='dripstore'?'selected':''}>DRIPSTORE API</option>
    </select></div>
    <div class="admin-field" id="editPlanProviderWrap"><label>KEYSPANEL VARIANT ID</label><input id="editPlanProviderId" value="${escapeHtml(pl.provider_product_id||'')}" placeholder="Enter KEYSPANEL Variant ID"></div>
    <div class="admin-field" id="editPlanDripstoreWrap"><label>DRIPSTORE VARIANT ID</label><input id="editPlanDripstoreId" value="${escapeHtml(pl.dripstore_variant_id||'')}" placeholder="Enter DRIPSTORE Variant ID"></div>
    <div class="admin-card-note" id="editPlanModeNote" style="margin-top:-4px"></div>
    <div class="admin-field"><label>STATUS</label><select id="editPlanStatus"><option value="active" ${pl.status==='active'?'selected':''}>Active</option><option value="disabled" ${pl.status==='disabled'?'selected':''}>Disabled</option></select></div>
    <div class="admin-actions"><button class="admin-primary" type="button" onclick="adminSavePlan(${Number(id)})">SAVE</button></div>`);
  toggleEditPlanMode();
}
async function adminSavePlan(id){
  const body={id,duration_label:document.getElementById('editPlanDuration').value.trim(),default_price:Number(document.getElementById('editPlanPrice').value),provider_type:document.getElementById('editPlanStockMode').value,provider_variant_id:document.getElementById('editPlanProviderId').value.trim(),dripstore_variant_id:document.getElementById('editPlanDripstoreId').value.trim(),status:document.getElementById('editPlanStatus').value};
  try{await adminPost('admin_update_plan',body);closeAdminModal();}catch(e){showToast(e.message||'Could not save duration.');}
}
function adminDeletePlan(id){if(!confirm('PERMANENT DELETE\n\nThis duration, its local keys, custom prices and related orders will be permanently removed. This cannot be undone.\n\nContinue?'))return;adminPost('admin_delete_plan',{id}).catch(e=>showToast(e.message||'Could not delete duration.'));}

function adminGoToPlanKeys(id){adminSwitchTab('store');setTimeout(()=>{const sel=document.getElementById('adminKeyPlan');if(sel){sel.value=String(id);loadAdminPlanKeys();}},50);}

async function adminAddKey(){const plan_id=Number(document.getElementById('adminKeyPlan').value),key_value=document.getElementById('adminKeyValue').value.trim();if(!plan_id||!key_value)return showToast('Select a variation and enter a key.');try{await apiCall('admin_add_key',{method:'POST',body:{plan_id,key_value}});showToast('License key added to stock.');document.getElementById('adminKeyValue').value='';await loadAdminPlanKeys();await loadAdminCatalog();}catch(e){showToast(e.message||'Could not add key.');}}
async function adminAddKeysBulk(){const plan_id=Number(document.getElementById('adminKeyPlan').value),keys_text=document.getElementById('adminKeyBulk').value;if(!plan_id||!keys_text.trim())return showToast('Select a variation and paste at least one key.');try{const d=await apiCall('admin_add_keys_bulk',{method:'POST',body:{plan_id,keys_text}});showToast(d.message||'Keys added.');document.getElementById('adminKeyBulk').value='';await loadAdminPlanKeys();await loadAdminCatalog();}catch(e){showToast(e.message||'Could not add keys.');}}
async function adminClearKeys(){const plan_id=Number(document.getElementById('adminKeyPlan').value);if(!plan_id)return showToast('Select a variation first.');if(!confirm('Clear ALL unsold keys for this variation? This cannot be undone.'))return;try{const d=await apiCall('admin_clear_keys',{method:'POST',body:{plan_id}});showToast(d.message||'Keys cleared.');await loadAdminPlanKeys();await loadAdminCatalog();}catch(e){showToast(e.message||'Could not clear keys.');}}
async function loadAdminPlanKeys(){
  const plan_id=Number(document.getElementById('adminKeyPlan').value);
  const list=document.getElementById('adminKeysList'), line=document.getElementById('adminKeyStockLine');
  if(!plan_id){if(list)list.innerHTML='<div class="admin-empty">Select a variation above.</div>';if(line)line.textContent='';return;}
  try{
    const d=await apiCall('admin_plan_keys',{params:{plan_id}});
    if(line) line.textContent=`Available: ${d.available_count} • Total keys ever added: ${d.total}`;
    if(list) list.innerHTML=(d.keys||[]).length ? d.keys.map(k=>`
      <div class="admin-user-row"><div style="font-family:monospace;font-size:11px;word-break:break-all;">${escapeHtml(k.key_value)}</div>
      <div style="display:flex;align-items:center;gap:6px;"><span class="admin-user-status ${k.status==='available'?'active':'disabled'}">${escapeHtml(k.status).toUpperCase()}</span>${k.status==='available'?`<button class="admin-mini-btn" type="button" onclick="adminDeleteKey(${Number(k.id)})">DELETE</button>`:''}</div></div>
    `).join('') : '<div class="admin-empty">No keys for this variation yet.</div>';
  }catch(e){ if(list) list.innerHTML='<div class="admin-empty">Unable to load keys.</div>'; }
}
async function adminDeleteKey(id){try{await apiCall('admin_delete_key',{method:'POST',body:{id}});showToast('Key removed.');await loadAdminPlanKeys();await loadAdminCatalog();}catch(e){showToast(e.message||'Could not delete key.');}}

/* =========================================================
   ADMIN PANEL — orders, API reports, settings, support, logs
   ========================================================= */
let adminOrdersPageNum=1;
async function loadAdminOrders(page){
  adminOrdersPageNum=page;
  const list=document.getElementById('adminOrdersList'), label=document.getElementById('adminOrdersPageLabel');
  try{
    const d=await apiCall('admin_orders',{params:{page}});
    adminOrdersCache=d.orders||[];
    if(label) label.textContent='Page '+d.page+' of '+Math.max(1,Math.ceil(d.total/d.per_page));
    if(list) list.innerHTML=(d.orders||[]).length ? d.orders.map(o=>`
      <div class="admin-user-row"${o.provider_diagnostics?` style="cursor:pointer" onclick="adminShowOrderDiagnostics('${escapeHtml(o.order_code)}')"`:''}><div><b>${escapeHtml(o.order_code)}</b> — ${escapeHtml(o.product_name)} ${escapeHtml(o.duration_label)} x${Number(o.quantity)}<div class="admin-user-meta">@${escapeHtml(o.username)} • ₹${Number(o.total_price).toFixed(2)} • ${escapeHtml(o.source)} • ${escapeHtml(o.created_at)}${o.provider_diagnostics?' • 🔎 tap for KEYSPANEL diagnostics':''}</div></div>
      <span class="admin-user-status ${o.status==='success'?'active':'disabled'}">${escapeHtml(o.status).toUpperCase()}</span></div>
    `).join('') : '<div class="admin-empty">No orders yet.</div>';
  }catch(e){ if(list) list.innerHTML='<div class="admin-empty">Unable to load orders.</div>'; }
}
function adminOrdersPage(delta){loadAdminOrders(Math.max(1,adminOrdersPageNum+delta));}
let adminOrdersCache=[];
function adminShowOrderDiagnostics(orderCode){
  const o=(adminOrdersCache||[]).find(x=>x.order_code===orderCode);
  if(!o||!o.provider_diagnostics){showToast('No diagnostics recorded for this order.');return;}
  let diag={};
  try{diag=JSON.parse(o.provider_diagnostics);}catch(e){diag={};}
  const rows=[
    ['Requested At',diag.requested_at],
    ['HTTP Status',diag.http_status],
    ['Content-Type',diag.content_type],
    ['Response Time',diag.total_time_ms!==null&&diag.total_time_ms!==undefined?diag.total_time_ms+'ms':null],
    ['Response Length',diag.response_length!==null&&diag.response_length!==undefined?diag.response_length+' bytes':null],
    ['JSON Valid',diag.json_valid===true?'YES':(diag.json_valid===false?'NO':'—')],
    ['WAF/HTML Signature',diag.waf_signature],
    ['cURL Error',diag.curl_error],
    ['Provider OK',diag.provider_ok===true?'TRUE':(diag.provider_ok===false?'FALSE':'—')],
    ['Provider Error',diag.provider_error],
    ['Shortfall',diag.shortfall],
    ['Key Returned',diag.key_returned===true?'YES':(diag.key_returned===false?'NO':'—')],
    ['Provider Order ID',diag.provider_order_id],
    ['Expires At',diag.expires_at],
    ['Variant ID Used',diag.variant_id],
    ['Quantity',diag.quantity],
    ['Master Key Length Sent',diag.master_key_length!==null&&diag.master_key_length!==undefined?diag.master_key_length+' chars (value never shown)':null],
    ['Endpoint (saved)',diag.endpoint_url],
    ['Effective URL',diag.effective_url&&diag.effective_url!==diag.endpoint_url?diag.effective_url:null],
    ['Response Preview',diag.response_preview],
    ['Possible Cause',diag.possible_cause],
  ].filter(r=>r[1]!==null&&r[1]!==undefined&&r[1]!=='');
  openAdminModal(`<div class="admin-card-title">KEYSPANEL Diagnostics — ${escapeHtml(orderCode)}</div>
    <div class="admin-card-note" style="margin-bottom:10px">Safe diagnostic data only. The master key is never recorded or shown here.</div>
    ${rows.map(r=>`<div class="admin-user-meta"><b>${escapeHtml(r[0])}:</b> ${escapeHtml(String(r[1]))}</div>`).join('')}
  `);
}

let apiReportsPageNum=1;
async function loadApiReports(page){
  apiReportsPageNum=page;
  const list=document.getElementById('apiReportsList'), label=document.getElementById('apiReportsPageLabel'), summaryEl=document.getElementById('apiReportsSummary');
  try{
    const params={page,date_from:document.getElementById('apiRepDateFrom').value,date_to:document.getElementById('apiRepDateTo').value,username:document.getElementById('apiRepUsername').value,status:document.getElementById('apiRepStatus').value,order_code:document.getElementById('apiRepOrderCode').value};
    const d=await apiCall('admin_api_reports',{params});
    if(label) label.textContent='Page '+d.page+' of '+Math.max(1,Math.ceil(d.total/d.per_page));
    if(summaryEl){
      const s=d.summary||{};
      summaryEl.innerHTML=`<div><b>${s.total_requests||0}</b><span>Total Requests</span></div><div><b>${s.successful_orders||0}</b><span>Successful Orders</span></div><div><b>${s.failed_orders||0}</b><span>Failed Orders</span></div><div><b>₹${Number(s.revenue||0).toFixed(2)}</b><span>Revenue</span></div><div><b>₹${Number(s.refunds||0).toFixed(2)}</b><span>Refunds</span></div><div><b>${s.api_errors||0}</b><span>API Errors</span></div>`;
    }
    if(list) list.innerHTML=(d.rows||[]).length ? d.rows.map(r=>`
      <div class="admin-user-row" style="cursor:pointer" onclick="adminApiReportDetail(${Number(r.id)})"><div><b>${escapeHtml(r.action)}</b> ${r.order_code?('— '+escapeHtml(r.order_code)):''}<div class="admin-user-meta">@${escapeHtml(r.username||'—')} • ${escapeHtml(r.created_at)} ${r.message?('• '+escapeHtml(r.message)):''}</div></div>
      <span class="admin-user-status ${r.status==='success'?'active':'disabled'}">${escapeHtml(r.status).toUpperCase()}</span></div>
    `).join('') : '<div class="admin-empty">No API requests found for these filters.</div>';
  }catch(e){ if(list) list.innerHTML='<div class="admin-empty">Unable to load API reports.</div>'; }
}
function apiReportsPage(delta){loadApiReports(Math.max(1,apiReportsPageNum+delta));}
async function adminApiReportDetail(id){
  try{
    const d=await apiCall('admin_api_report_detail',{params:{id}});
    const r=d.record;
    openAdminModal(`<div class="admin-card-title">API Request #${Number(r.id)}</div>
      <div class="admin-user-meta">Action: ${escapeHtml(r.action)}</div>
      <div class="admin-user-meta">User: @${escapeHtml(r.username||'—')}</div>
      <div class="admin-user-meta">Status: ${escapeHtml(r.status)} (HTTP ${Number(r.http_status)})</div>
      <div class="admin-user-meta">Order: ${escapeHtml(r.order_code||'—')} ${r.total_price?('— ₹'+Number(r.total_price).toFixed(2)):''}</div>
      <div class="admin-user-meta">Message: ${escapeHtml(r.message||'—')}</div>
      <div class="admin-user-meta">Time: ${escapeHtml(r.created_at)}</div>
    `);
  }catch(e){showToast(e.message||'Could not load request detail.');}
}

function applyBranding(branding){
    const b=branding||{};
    const panelName=String(b.panel_name||'KEYS PANEL').trim()||'KEYS PANEL';
    const loginName=String(b.login_name||'FLASH STAR').trim()||'FLASH STAR';
    const splashName=String(b.splash_name||'FLASH STAR').trim()||'FLASH STAR';
    const adminName=String(b.admin_name||'KEYS PANEL ADMIN').trim()||'KEYS PANEL ADMIN';
    const panelTitle=document.getElementById('panelBrandName'); if(panelTitle) panelTitle.textContent=panelName;
    const loginTitle=document.getElementById('loginBrandName'); if(loginTitle) loginTitle.textContent=loginName;
    const splashTitle=document.getElementById('splashBrandName'); if(splashTitle) splashTitle.textContent=splashName;
    const adminTitle=document.getElementById('adminBrandName'); if(adminTitle) adminTitle.textContent=adminName;
    const headTitle=document.querySelector('title'); if(headTitle) headTitle.textContent=panelName;
    if(b.panel_logo){
        const box=document.querySelector('.header-logo');
        if(box){box.innerHTML='<img id="panelLogoImage" alt="Panel logo">'; const im=box.querySelector('img'); if(im) im.src=String(b.panel_logo);}
    }
    if(b.login_logo){
        const box=document.querySelector('.login-logo');
        if(box){box.innerHTML='<img id="loginLogoImage" alt="Login logo">'; const im=box.querySelector('img'); if(im) im.src=String(b.login_logo);}
    }
    if(b.splash_logo){
        const box=document.getElementById('splashLogoBox');
        if(box){box.innerHTML='<img id="splashLogoImage" alt="Loading screen logo">'; const im=box.querySelector('img'); if(im) im.src=String(b.splash_logo);}
    }
    if(b.admin_logo){
        const box=document.getElementById('adminLogoBox');
        if(box){box.innerHTML='<img id="adminLogoImage" alt="Admin logo">'; const im=box.querySelector('img'); if(im) im.src=String(b.admin_logo);}
    }
    const textMap={splashKicker:'splash_kicker',splashWelcome:'splash_welcome',splashPortal:'splash_portal',splashSubtitle:'splash_subtitle',splashLoadingText:'splash_loading_text',loginSubtitle:'login_subtitle',loginBadge:'login_badge',usernameLabel:'login_username_label',passwordLabel:'login_password_label',loginMessage:'login_message',loginFooter:'login_footer'};
    Object.keys(textMap).forEach(id=>{const el=document.getElementById(id),key=textMap[id];if(el&&b[key]!==undefined)el.textContent=String(b[key]);});
    const usernameInput=document.getElementById('usernameInput'); if(usernameInput&&b.login_username_placeholder!==undefined) usernameInput.placeholder=String(b.login_username_placeholder);
    const passwordInput=document.getElementById('passwordInput'); if(passwordInput&&b.login_password_placeholder!==undefined) passwordInput.placeholder=String(b.login_password_placeholder);
    const loginBtn=document.querySelector('#loginForm .login-btn'); if(loginBtn&&b.login_button_text!==undefined) loginBtn.textContent=String(b.login_button_text);
    window.__branding=b;
}
async function optimizeBrandLogo(file){
    if(!file) throw new Error('Please select a logo image first.');
    const allowed=['image/png','image/jpeg','image/webp'];
    if(!allowed.includes(file.type)) throw new Error('Only PNG, JPG/JPEG and WEBP logos are allowed.');
    if(file.size>12*1024*1024) throw new Error('Logo file is too large. Please choose an image up to 12 MB.');

    // Small files are uploaded unchanged. Larger logos are resized/compressed
    // in the browser so shared-host PHP limits (often ~2 MB) do not block them.
    if(file.size<=1500*1024) return file;

    const maxDimension=1200;
    let img;
    if('createImageBitmap' in window){
        img=await createImageBitmap(file);
    }else{
        img=await new Promise((resolve,reject)=>{
            const u=URL.createObjectURL(file), im=new Image();
            im.onload=()=>{URL.revokeObjectURL(u);resolve(im);};
            im.onerror=()=>{URL.revokeObjectURL(u);reject(new Error('Could not read the logo image.'));};
            im.src=u;
        });
    }
    const sw=img.width||img.naturalWidth, sh=img.height||img.naturalHeight;
    if(!sw||!sh) throw new Error('Could not read the logo dimensions.');
    const scale=Math.min(1,maxDimension/Math.max(sw,sh));
    const w=Math.max(1,Math.round(sw*scale)), h=Math.max(1,Math.round(sh*scale));
    const canvas=document.createElement('canvas'); canvas.width=w; canvas.height=h;
    const ctx=canvas.getContext('2d',{alpha:true});
    if(!ctx) throw new Error('Your browser could not prepare the logo for upload.');
    ctx.imageSmoothingEnabled=true; ctx.imageSmoothingQuality='high';
    ctx.drawImage(img,0,0,w,h);
    if(img.close) img.close();

    const blobFrom=(type,quality)=>new Promise(resolve=>canvas.toBlob(resolve,type,quality));
    // Prefer WebP because it keeps transparency while dramatically reducing
    // large square logos. Fall back to JPEG only when WebP is unavailable.
    let blob=await blobFrom('image/webp',0.90);
    if(!blob) blob=await blobFrom('image/jpeg',0.88);
    if(!blob) throw new Error('Could not compress the logo image.');

    // Aim below ~1.5 MB so the upload remains compatible with common shared-host
    // PHP limits. Quality is reduced only as much as necessary.
    const qualities=[0.86,0.80,0.74,0.68,0.62,0.56];
    for(const q of qualities){
        if(blob.size<=1500*1024) break;
        const next=await blobFrom('image/webp',q)||await blobFrom('image/jpeg',q);
        if(next) blob=next;
    }
    const ext=blob.type==='image/webp'?'webp':'jpg';
    return new File([blob],(file.name.replace(/\.[^.]+$/,'')||'logo')+'.'+ext,{type:blob.type,lastModified:Date.now()});
}

async function uploadBrandLogo(target,file){
    const optimized=await optimizeBrandLogo(file);
    const fd=new FormData(); fd.append('target',target); fd.append('logo',optimized,optimized.name);
    const res=await fetch('index.php?api=admin_upload_brand_logo',{method:'POST',headers:window.__csrfToken?{'X-CSRF-Token':window.__csrfToken}: {},credentials:'same-origin',body:fd,cache:'no-store'});
    const d=await res.json().catch(()=>({}));
    if(!res.ok||d.status==='error') throw new Error(d.message||'Logo upload failed.');
    return d;
}
async function saveAdminBranding(){
    const name=document.getElementById('adminNameSetting')?.value.trim()||'KEYS PANEL ADMIN';
    const file=document.getElementById('adminLogoFile')?.files?.[0]||null;
    try{
        await apiCall('admin_settings_update',{method:'POST',body:{admin_name:name}});
        if(file) await uploadBrandLogo('admin',file);
        showToast('Admin branding saved.'); await loadAdminSettings();
        const d=await apiCall('me.php').catch(()=>null); if(d?.branding) applyBranding(d.branding);
    }catch(e){showToast(e.message||'Could not save admin branding.');}
}

async function savePanelBranding(){
    const name=document.getElementById('panelNameSetting')?.value.trim()||'KEYS PANEL';
    const file=document.getElementById('panelLogoFile')?.files?.[0]||null;
    try{
        await apiCall('admin_settings_update',{method:'POST',body:{panel_name:name}});
        if(file) await uploadBrandLogo('panel',file);
        showToast('Panel branding saved.'); await loadAdminSettings();
        const d=await apiCall('me.php').catch(()=>null); if(d?.branding) applyBranding(d.branding);
    }catch(e){showToast(e.message||'Could not save panel branding.');}
}
async function saveLoginBranding(){
    const name=document.getElementById('loginNameSetting')?.value.trim()||'FLASH STAR';
    const file=document.getElementById('loginLogoFile')?.files?.[0]||null;
    try{
        await apiCall('admin_settings_update',{method:'POST',body:{login_name:name}});
        if(file) await uploadBrandLogo('login',file);
        showToast('Login branding saved.'); await loadAdminSettings();
        const d=await apiCall('me.php').catch(()=>null); if(d?.branding) applyBranding(d.branding);
    }catch(e){showToast(e.message||'Could not save login branding.');}
}
async function saveLoginTextSettings(){
    const body={login_subtitle:document.getElementById('loginSubtitleSetting')?.value.trim()||'🔐 Ressler Portal — sign in to continue',login_badge:document.getElementById('loginBadgeSetting')?.value.trim()||'RESSLER PORTAL SIGN IN',login_username_label:document.getElementById('loginUsernameLabelSetting')?.value.trim()||'Username',login_username_placeholder:document.getElementById('loginUsernamePlaceholderSetting')?.value.trim()||'Your username',login_password_label:document.getElementById('loginPasswordLabelSetting')?.value.trim()||'Password',login_password_placeholder:document.getElementById('loginPasswordPlaceholderSetting')?.value.trim()||'Your password',login_button_text:document.getElementById('loginButtonTextSetting')?.value.trim()||'🔐  Log In  →',login_message:document.getElementById('loginMessageSetting')?.value.trim()||'Please enter your login details.',login_footer:document.getElementById('loginFooterSetting')?.value.trim()||'Authorized personnel only'};
    try{await apiCall('admin_settings_update',{method:'POST',body});showToast('Login text saved.');await loadAdminSettings();const d=await apiCall('me.php').catch(()=>null);if(d?.branding)applyBranding(d.branding);}catch(e){showToast(e.message||'Could not save login text.');}
}

async function saveSplashBranding(){
    const name=document.getElementById('splashNameSetting')?.value.trim()||'FLASH STAR';
    const file=document.getElementById('splashLogoFile')?.files?.[0]||null;
    try{
        await apiCall('admin_settings_update',{method:'POST',body:{splash_name:name}});
        if(file) await uploadBrandLogo('splash',file);
        showToast('Loading screen branding saved.');
        await loadAdminSettings();
        const d=await apiCall('me.php').catch(()=>null); if(d?.branding) applyBranding(d.branding);
    }catch(e){showToast(e.message||'Could not save loading screen branding.');}
}
async function saveSplashTextSettings(){
    const body={splash_kicker:document.getElementById('splashKickerSetting')?.value.trim()||'🔐 SECURE ACCESS POINT',splash_welcome:document.getElementById('splashWelcomeSetting')?.value.trim()||'WELCOME TO',splash_portal:document.getElementById('splashPortalSetting')?.value.trim()||'RESELLER PORTAL',splash_subtitle:document.getElementById('splashSubtitleSetting')?.value.trim()||'Setting things up, taking you to sign in...',splash_loading_text:document.getElementById('splashLoadingTextSetting')?.value.trim()||'REDIRECTING AUTOMATICALLY'};
    try{await apiCall('admin_settings_update',{method:'POST',body});showToast('Loading screen text saved.');await loadAdminSettings();const d=await apiCall('me.php').catch(()=>null);if(d?.branding)applyBranding(d.branding);}catch(e){showToast(e.message||'Could not save loading screen text.');}
}
async function loadAdminSettings(){
  try{
    const d=await apiCall('admin_settings_get'); if(d.csrf_token)window.__csrfToken=d.csrf_token;
    const st=d.settings||{}; const purl=document.getElementById('providerApiUrl'),pbtn=document.getElementById('providerApiToggleBtn'),pstatus=document.getElementById('providerApiStatus'); if(purl)purl.value=st.provider_api_url||''; if(pbtn){pbtn.dataset.state=st.provider_api_enabled==='on'?'on':'off';pbtn.textContent='API: '+(st.provider_api_enabled==='on'?'ON':'OFF');} if(pstatus){const updated=st.provider_api_token_updated_at?' • Key last saved: '+st.provider_api_token_updated_at:''; pstatus.textContent=st.provider_api_configured?'KEYSPANEL CONFIGURED • master key saved server-side • Store + reseller API purchases use this connection'+updated:'KEYSPANEL NOT CONFIGURED • enter endpoint and master key';}
     const durl=document.getElementById('dripstoreApiUrl'),dbtn=document.getElementById('dripstoreApiToggleBtn'),dstatus=document.getElementById('dripstoreApiStatus'); if(durl)durl.value=st.dripstore_api_url||'https://dripclientstore.shop/api/v1'; if(dbtn){dbtn.dataset.state=st.dripstore_api_enabled==='on'?'on':'off';dbtn.textContent='DRIPSTORE API: '+(st.dripstore_api_enabled==='on'?'ON':'OFF');} if(dstatus){const updated=st.dripstore_api_token_updated_at?' • Token last saved: '+st.dripstore_api_token_updated_at:''; dstatus.textContent=st.dripstore_api_configured?'DRIPSTORE CONFIGURED • token saved server-side'+updated:'DRIPSTORE NOT CONFIGURED • enter HTTPS endpoint and token';}
    const s=d.settings||{};
    const onNow = s.global_maintenance==='on';
    const btn=document.getElementById('globalMaintBtn'); if(btn){btn.textContent=onNow?'TURN MAINTENANCE OFF':'TURN MAINTENANCE ON';btn.dataset.state=s.global_maintenance;}
    const msg=document.getElementById('globalMaintMessage'); if(msg) msg.value=s.global_maintenance_message||'';
    const banner=document.getElementById('adminMaintBanner'); if(banner) banner.style.display=onNow?'block':'none';
    const support=document.getElementById('supportUrlInput'); if(support) support.value=s.support_url||'';
    const group=document.getElementById('groupUpdateUrlInput'); if(group) group.value=s.group_update_url||'';
    const an=document.getElementById('adminNameSetting'); if(an) an.value=s.admin_name||'KEYS PANEL ADMIN';
    const pn=document.getElementById('panelNameSetting'); if(pn) pn.value=s.panel_name||'KEYS PANEL';
    const ln=document.getElementById('loginNameSetting'); if(ln) ln.value=s.login_name||'FLASH STAR';
    const sn=document.getElementById('splashNameSetting'); if(sn) sn.value=s.splash_name||'FLASH STAR';
    const setVal=(id,key,fallback)=>{const el=document.getElementById(id);if(el)el.value=s[key]??fallback;};
    setVal('splashKickerSetting','splash_kicker','🔐 SECURE ACCESS POINT');
    setVal('splashWelcomeSetting','splash_welcome','WELCOME TO');
    setVal('splashPortalSetting','splash_portal','RESELLER PORTAL');
    setVal('splashSubtitleSetting','splash_subtitle','Setting things up, taking you to sign in...');
    setVal('splashLoadingTextSetting','splash_loading_text','REDIRECTING AUTOMATICALLY');
    setVal('loginSubtitleSetting','login_subtitle','🔐 Ressler Portal — sign in to continue');
    setVal('loginBadgeSetting','login_badge','RESSLER PORTAL SIGN IN');
    setVal('loginUsernameLabelSetting','login_username_label','Username');
    setVal('loginUsernamePlaceholderSetting','login_username_placeholder','Your username');
    setVal('loginPasswordLabelSetting','login_password_label','Password');
    setVal('loginPasswordPlaceholderSetting','login_password_placeholder','Your password');
    setVal('loginButtonTextSetting','login_button_text','🔐  Log In  →');
    setVal('loginMessageSetting','login_message','Please enter your login details.');
    setVal('loginFooterSetting','login_footer','Authorized personnel only');
    const dmin=document.getElementById('depositMinSetting'); if(dmin) dmin.value=s.deposit_min_amount||'10';
    const dmax=document.getElementById('depositMaxSetting'); if(dmax) dmax.value=s.deposit_max_amount||'100000';
    const dls=document.getElementById('depositLimitsStatus'); if(dls) dls.textContent='Current: MIN ₹'+Number(s.deposit_min_amount||10).toFixed(2)+' • MAX ₹'+Number(s.deposit_max_amount||100000).toFixed(2);
    const soundBtn=document.getElementById('soundToggleBtn'); if(soundBtn){const on=s.sound_enabled!=='off';soundBtn.textContent='SOUND: '+(on?'ON':'OFF');soundBtn.dataset.state=on?'on':'off';window.__soundEnabled=on;}
    const pm=document.getElementById('paytmMid');if(pm)pm.value=s.paytm_mid||'';const pu=document.getElementById('paytmUpiId');if(pu)pu.value=s.paytm_upi_id||'';const pb=document.getElementById('paytmToggleBtn');if(pb){const on=s.paytm_enabled==='on';pb.textContent='PAYTM: '+(on?'ON':'OFF');pb.dataset.state=on?'on':'off';}const ps=document.getElementById('paytmConfigStatus');if(ps)ps.textContent=s.paytm_configured?'Paytm Auto Payment is configured — MID + UPI ID only.':'Enter Paytm MID and UPI ID, then save.';const zb=document.getElementById('zapupiToggleBtn');if(zb){const zon=s.zapupi_enabled==='on';zb.textContent='ZAPUPI: '+(zon?'ON':'OFF');zb.dataset.state=zon?'on':'off';}const zs=document.getElementById('zapupiConfigStatus');if(zs)zs.textContent=s.zapupi_configured?'ZapUPI key is saved (encrypted). Enter a new key only to replace it.':'Enter your ZAP KEY, then save.';const zh=document.getElementById('zapupiWebhookHint');if(zh)zh.textContent=location.origin+location.pathname+'?api=zapupi_webhook';
  }catch(e){showToast(e.message||'Could not load settings.');}
}
async function toggleGlobalMaintenance(){
  const btn=document.getElementById('globalMaintBtn'); const next = btn.dataset.state==='on' ? 'off' : 'on';
  try{ await apiCall('admin_settings_update',{method:'POST',body:{global_maintenance:next}}); showToast('Global maintenance turned '+next.toUpperCase()+'.'); await loadAdminSettings(); }
  catch(e){ showToast(e.message||'Could not update maintenance mode.'); }
}
async function saveGlobalMaintMessage(){
  const message=document.getElementById('globalMaintMessage').value;
  try{ await apiCall('admin_settings_update',{method:'POST',body:{global_maintenance_message:message}}); showToast('Maintenance message saved.'); }
  catch(e){ showToast(e.message||'Could not save message.'); }
}
async function saveGroupUpdateUrl(){
  const group_update_url=document.getElementById('groupUpdateUrlInput')?.value.trim()||'';
  try{ await apiCall('admin_settings_update',{method:'POST',body:{group_update_url}}); showToast('Update group link saved.'); await loadAdminSettings(); }
  catch(e){ showToast(e.message||'Could not save update group link.'); }
}
async function saveDepositLimits(){
  const min=Number(document.getElementById('depositMinSetting')?.value||0), max=Number(document.getElementById('depositMaxSetting')?.value||0);
  if(!Number.isFinite(min)||!Number.isFinite(max)||min<=0||max<=0||min>max)return showToast('Enter valid minimum and maximum amounts.');
  try{ await apiCall('admin_settings_update',{method:'POST',body:{deposit_min_amount:min,deposit_max_amount:max}}); showToast('Deposit limits saved.'); await loadAdminSettings(); }
  catch(e){ showToast(e.message||'Could not save deposit limits.'); }
}
function openGroupUpdateLink(){
  const url=String(window.__groupUpdateUrl||'').trim();
  if(!url)return showToast('Update group link is not configured by admin.');
  window.open(url,'_blank','noopener,noreferrer');
}
function formatInrAmount(v){
  const n=Number(v||0);
  return n.toLocaleString('en-IN',{minimumFractionDigits:0,maximumFractionDigits:2});
}
function applyDepositSettings(min,max){
  let lo=Number(min), hi=Number(max);
  if(!Number.isFinite(lo)||lo<=0)lo=10;
  if(!Number.isFinite(hi)||hi<lo)hi=Math.max(lo,100000);
  const input=document.getElementById('depositAmount');
  if(input){input.min=String(lo);input.max=String(hi);}
  const minLabel=document.getElementById('depositMinLabel'); if(minLabel)minLabel.textContent='MIN ₹'+formatInrAmount(lo);
  const maxLabel=document.getElementById('depositMaxLabel'); if(maxLabel)maxLabel.textContent='MAX ₹'+formatInrAmount(hi);
  const join=document.getElementById('groupUpdateJoinBtn'); if(join)join.title=window.__groupUpdateUrl?'Open reseller update group':'Update group link not configured';
  window.__depositMin=lo; window.__depositMax=hi;
}

async function saveSupportUrl(){
  const support_url=document.getElementById('supportUrlInput').value.trim();
  try{ await apiCall('admin_settings_update',{method:'POST',body:{support_url}}); showToast('Support URL saved.'); }
  catch(e){ showToast(e.message||'Could not save support URL.'); }
}
async function toggleSoundSetting(){
  const btn=document.getElementById('soundToggleBtn'); const next = btn.dataset.state==='on' ? 'off' : 'on';
  try{ await apiCall('admin_settings_update',{method:'POST',body:{sound_enabled:next}}); showToast('Sound effects turned '+next.toUpperCase()+' for everyone.'); await loadAdminSettings(); }
  catch(e){ showToast(e.message||'Could not update sound setting.'); }
}

async function saveProviderApiSettings(){
  const url=document.getElementById('providerApiUrl')?.value.trim()||'';
  const token=document.getElementById('providerApiToken')?.value.trim()||'';
  const btn=document.getElementById('providerApiSaveBtn');
  if(!url)return showToast('Enter the KEYSPANEL API endpoint first.');
  if(!/^https?:\/\//i.test(url))return showToast('KEYSPANEL API URL must start with http:// or https://.');
  if(!token)return showToast('Enter the new KEYSPANEL master key first.');
  if(token.length>512)return showToast('Master key is too long.');
  if(btn){btn.disabled=true;btn.textContent='SAVING…';}
  try{
    await apiCall('admin_settings_update',{method:'POST',body:{provider_api_url:url,provider_api_token:token}});
    const input=document.getElementById('providerApiToken'); if(input)input.value='';
    showToast('KEYSPANEL settings saved. New master key replaced the old key.');
    await loadAdminSettings();
  }catch(e){showToast(e.message||'Could not save KEYSPANEL settings.');}
  finally{if(btn){btn.disabled=false;btn.textContent='SAVE KEYSPANEL SETTINGS';}}
}
async function testProviderApiConnection(){
  const btn=document.getElementById('providerApiTestBtn'), result=document.getElementById('providerApiTestResult');
  if(result){result.style.display='block';result.textContent='⏳ Running authenticated KEYSPANEL POST diagnostic… No real purchase will be sent.';}
  if(btn){btn.disabled=true;btn.textContent='CHECKING…';}
  try{
    const d=await apiCall('admin_provider_api_test',{method:'POST',body:{}});
    if(result){
      const title=(d.configured===true)?'✅ SAVED CONFIGURATION VERIFIED':'❌ CONFIGURATION INCOMPLETE';
      result.style.display='block';
      result.innerHTML='<b>'+title+'</b><br>'+
        escapeHtml(String(d.message||''))+        '<br><span>Supplier request: SENT • Purchase: NOT SENT • Auth result: '+(d.authenticated===true?'CONFIRMED':(d.authenticated===false?'REJECTED':'UNCONFIRMED'))+'</span>'+        '<br><span>HTTP: '+Number(d.http_status||0)+' • Latency: '+Number(d.latency_ms||0)+' ms • Key source: SAVED DATABASE KEY • Saved: '+(d.key_saved?'YES':'NO')+' • Length: '+Number(d.key_length||0)+'</span>'+        (d.response_preview?'<br><small>Response: '+escapeHtml(String(d.response_preview))+'</small>':'')+        (d.url?'<br><small>'+escapeHtml(String(d.url))+'</small>':'');
    }
  }catch(e){
    if(result){result.style.display='block';result.innerHTML='<b>❌ CHECK FAILED</b><br>'+escapeHtml(e.message||'Could not check saved KEYSPANEL configuration.');}
  }finally{if(btn){btn.disabled=false;btn.textContent='CHECK SAVED CONNECTION';}}
}
async function checkOutboundIp(){
  const btn=document.getElementById('outboundIpBtn'), result=document.getElementById('outboundIpResult');
  if(result){result.style.display='block';result.textContent='⏳ Asking this server for its own outbound IP…';}
  if(btn){btn.disabled=true;btn.textContent='CHECKING…';}
  try{
    const d=await apiCall('admin_outbound_ip',{});
    if(result){
      result.innerHTML='<b>🌐 OUTBOUND IP: '+escapeHtml(String(d.outbound_ip||'—'))+'</b><br><small>Give this exact IP to KEYSPANEL support if their side requires an IP allowlist.</small>';
    }
  }catch(e){
    if(result){result.style.display='block';result.innerHTML='<b>❌ COULD NOT DETECT</b><br>'+escapeHtml(e.message||'Could not determine this server\'s outbound IP.');}
  }finally{if(btn){btn.disabled=false;btn.textContent='CHECK OUR OUTBOUND IP';}}
}
async function toggleProviderApiSetting(){const btn=document.getElementById('providerApiToggleBtn');const next=btn?.dataset.state==='on'?'off':'on';try{await apiCall('admin_settings_update',{method:'POST',body:{provider_api_enabled:next}});showToast('Provider API turned '+next.toUpperCase()+'.');await loadAdminSettings();}catch(e){showToast(e.message||'Could not update provider API.');}}

async function saveDripstoreApiSettings(){
  const url=document.getElementById('dripstoreApiUrl')?.value.trim()||'';
  const token=document.getElementById('dripstoreApiToken')?.value.trim()||'';
  const btn=document.getElementById('dripstoreApiSaveBtn');
  if(!url)return showToast('Enter the DRIPSTORE API URL first.');
  if(!/^https:\/\//i.test(url))return showToast('DRIPSTORE API URL must use HTTPS.');
  if(token.length>512)return showToast('DRIPSTORE API token is too long.');
  if(btn){btn.disabled=true;btn.textContent='SAVING…';}
  try{
    const body={dripstore_api_url:url};
    if(token)body.dripstore_api_token=token;
    await apiCall('admin_settings_update',{method:'POST',body});
    const input=document.getElementById('dripstoreApiToken');if(input)input.value='';
    showToast('DRIPSTORE settings saved.');
    await loadAdminSettings();
  }catch(e){showToast(e.message||'Could not save DRIPSTORE settings.');}
  finally{if(btn){btn.disabled=false;btn.textContent='SAVE DRIPSTORE SETTINGS';}}
}
async function toggleDripstoreApiSetting(){
  const btn=document.getElementById('dripstoreApiToggleBtn');
  const next=btn?.dataset.state==='on'?'off':'on';
  try{await apiCall('admin_settings_update',{method:'POST',body:{dripstore_api_enabled:next}});showToast('DRIPSTORE API turned '+next.toUpperCase()+'.');await loadAdminSettings();}
  catch(e){showToast(e.message||'Could not update DRIPSTORE setting.');}
}
async function testDripstoreApiConnection(){
  const btn=document.getElementById('dripstoreApiTestBtn'),result=document.getElementById('dripstoreApiTestResult');
  if(result){result.style.display='block';result.textContent='⏳ Checking DRIPSTORE GET /balance.php… No purchase will be sent.';}
  if(btn){btn.disabled=true;btn.textContent='CHECKING…';}
  try{
    const d=await apiCall('admin_dripstore_test',{method:'POST',body:{}});
    if(result)result.innerHTML='<b>✅ DRIPSTORE CONNECTION VERIFIED</b><br>'+escapeHtml(String(d.message||''))+'<br><span>HTTP: '+Number(d.http_status||0)+'</span>';
  }catch(e){if(result){result.style.display='block';result.innerHTML='<b>❌ CHECK FAILED</b><br>'+escapeHtml(e.message||'Could not check DRIPSTORE connection.');}}
  finally{if(btn){btn.disabled=false;btn.textContent='CHECK CONNECTION';}}
}
async function loadDripstoreProducts(){
  const result=document.getElementById('dripstoreProductsResult');
  if(result){result.style.display='block';result.textContent='⏳ Loading DRIPSTORE products / variants…';}
  try{
    const d=await apiCall('admin_dripstore_products');
    const raw=d.products;
    result.innerHTML='<b>DRIPSTORE PRODUCTS / VARIANTS</b><br><pre style="white-space:pre-wrap;word-break:break-word;margin:6px 0;font-size:11px">'+escapeHtml(JSON.stringify(raw,null,2))+'</pre>';
  }catch(e){if(result)result.innerHTML='<b>❌ LOAD FAILED</b><br>'+escapeHtml(e.message||'Could not load DRIPSTORE products.');}
}

async function loadDripstoreHistory(){
  const result=document.getElementById('dripstoreProductsResult');
  if(result){result.style.display='block';result.textContent='⏳ Loading DRIPSTORE key history…';}
  try{
    const d=await apiCall('admin_dripstore_history',{params:{limit:20}});
    if(result)result.innerHTML='<b>DRIPSTORE KEY HISTORY</b><br><pre style="white-space:pre-wrap;word-break:break-word;margin:6px 0;font-size:11px">'+escapeHtml(JSON.stringify(d.history,null,2))+'</pre>';
  }catch(e){if(result)result.innerHTML='<b>❌ HISTORY FAILED</b><br>'+escapeHtml(e.message||'Could not load history.');}
}
async function dripstoreResetPrompt(){
  const api=prompt('DRIPSTORE reset API name (fluorite or drip):','drip'); if(api===null)return;
  const key=prompt('Enter the generated key to reset:',''); if(key===null||!key.trim())return;
  if(!confirm('Reset this key through DRIPSTORE?'))return;
  try{const d=await apiCall('admin_dripstore_reset_key',{method:'POST',body:{api:api.trim(),key:key.trim()}});showToast('DRIPSTORE reset request completed.');const r=document.getElementById('dripstoreProductsResult');if(r){r.style.display='block';r.innerHTML='<pre style="white-space:pre-wrap;word-break:break-word">'+escapeHtml(JSON.stringify(d.result,null,2))+'</pre>';}}
  catch(e){showToast(e.message||'DRIPSTORE reset failed.');}
}

async function savePaytmSettings(){const body={paytm_mid:document.getElementById('paytmMid').value.trim(),paytm_upi_id:document.getElementById('paytmUpiId').value.trim()};if(!body.paytm_mid||!body.paytm_upi_id)return showToast('Enter both Paytm MID and UPI ID.');try{await apiCall('admin_settings_update',{method:'POST',body});showToast('Paytm MID + UPI ID saved.');await loadAdminSettings();}catch(e){showToast(e.message||'Could not save Paytm settings.');}}
async function togglePaytmSetting(){const btn=document.getElementById('paytmToggleBtn');const next=btn.dataset.state==='on'?'off':'on';try{await apiCall('admin_settings_update',{method:'POST',body:{paytm_enabled:next}});showToast('Paytm deposits turned '+next.toUpperCase()+'.');await loadAdminSettings();}catch(e){showToast(e.message||'Could not update Paytm setting.');}}
let depositPollTimer=null,depositCountdownTimer=null,activeDepositId=0,depositDeadlineMs=0;
function setDepositAmount(v){const el=document.getElementById('depositAmount');if(el)el.value=String(v);}
function setPaytmStatus(text,state='waiting'){const box=document.getElementById('paytmQrStatus');const txt=document.getElementById('paytmQrStatusText');if(!box)return;box.className='paytm-status '+(state==='success'?'paytm-success-state':state);if(txt)txt.textContent=text;}
function copyPaytmOrder(){const el=document.getElementById('paytmQrOrder');const v=el?.textContent?.trim()||'';if(!v||v==='—')return;if(navigator.clipboard?.writeText)navigator.clipboard.writeText(v).then(()=>showToast('Order ID copied.')).catch(()=>{});}
async function refreshWalletAfterDeposit(){
  /*
   * Paytm SUCCESS is returned only after the server credits the wallet.
   * Pull the authenticated session again so all visible wallet amounts
   * update automatically without a full page reload.
   */
  for(let attempt=0;attempt<3;attempt++){
    try{
      const me=await apiCall('me.php');
      if(me?.role==='reseller' && me.reseller){
        const balance=Number(me.reseller.balance);
        if(Number.isFinite(balance)){
          const formatted='₹ '+balance.toFixed(2);
          const header=document.getElementById('headerBalance');
          const stat=document.getElementById('statWalletBalance');
          const deposit=document.getElementById('depositWalletBalance');
          const apiWallet=document.getElementById('apiWalletBalance');
          if(header)header.textContent=formatted;
          if(stat)stat.textContent='₹'+balance.toFixed(2);
          if(deposit)deposit.textContent=balance.toFixed(2);
          if(apiWallet)apiWallet.textContent=balance.toFixed(2);
          window.__walletBalance=balance;
        }
        return true;
      }
    }catch(e){}
    await new Promise(resolve=>setTimeout(resolve,350));
  }
  return false;
}

function closePaytmQr(){
  if(depositPollTimer)clearInterval(depositPollTimer);
  if(depositCountdownTimer)clearInterval(depositCountdownTimer);
  depositPollTimer=depositCountdownTimer=null;
  depositDeadlineMs=0;
  const m=document.getElementById('paytmQrModal');
  if(m)m.style.display='none';
  showPaytmQrView();
  activeDepositId=0;
  loadDepositHistory();
}

function downloadPaytmQr(){const img=document.getElementById('paytmQrImage');if(!img||!img.src)return;const a=document.createElement('a');a.href=img.src;a.download='paytm-payment-qr.png';a.click();}
function showPaytmQrView(){const q=document.getElementById('paytmQrPaymentView');const p=document.getElementById('paytmProcessingView');const s=document.getElementById('paytmSuccessView');if(q)q.style.display='block';if(p)p.style.display='none';if(s)s.style.display='none';}
function showPaytmProcessing(){const q=document.getElementById('paytmQrPaymentView');const p=document.getElementById('paytmProcessingView');const s=document.getElementById('paytmSuccessView');if(q)q.style.display='none';if(p)p.style.display='flex';if(s)s.style.display='none';}
function showPaytmSuccess(){const q=document.getElementById('paytmQrPaymentView');const p=document.getElementById('paytmProcessingView');const s=document.getElementById('paytmSuccessView');if(q)q.style.display='none';if(p)p.style.display='none';if(s)s.style.display='flex';}
function updatePaytmCountdown(){if(!depositDeadlineMs)return;const remaining=Math.max(0,Math.ceil((depositDeadlineMs-Date.now())/1000));const mm=String(Math.floor(remaining/60)).padStart(2,'0');const ss=String(remaining%60).padStart(2,'0');const cd=document.getElementById('paytmQrCountdown');const bar=document.getElementById('paytmQrProgress');if(cd)cd.textContent=mm+':'+ss;if(bar)bar.style.width=Math.max(0,(remaining/180)*100)+'%';if(remaining<=0){if(depositCountdownTimer)clearInterval(depositCountdownTimer);depositCountdownTimer=null;if(depositPollTimer)clearInterval(depositPollTimer);depositPollTimer=null;setPaytmStatus('QR EXPIRED — CREATE A NEW PAYMENT','expired');}}
/* ===== ZapUPI (added) ===== */
window.__depositMethod = window.__depositMethod || 'paytm';
function setDepositMethod(m){
  window.__depositMethod = (m==='zapupi') ? 'zapupi' : 'paytm';
  const a=document.getElementById('depMethodPaytm'), b=document.getElementById('depMethodZap');
  if(a) a.style.opacity = window.__depositMethod==='paytm' ? '1' : '.45';
  if(b) b.style.opacity = window.__depositMethod==='zapupi' ? '1' : '.45';
}
async function startDepositPayment(){
  if(window.__depositMethod==='zapupi') return startZapupiDeposit();
  return startPaytmDeposit();
}
async function startZapupiDeposit(){
  const amount=Number(document.getElementById('depositAmount')?.value||0);
  const min=Number(window.__depositMin||10),max=Number(window.__depositMax||100000);
  if(!Number.isFinite(amount)||amount<min||amount>max) return showToast('Deposit amount must be between ₹'+formatInrAmount(min)+' and ₹'+formatInrAmount(max)+'.');
  const btn=document.getElementById('depositPayBtn'); if(btn) btn.disabled=true;
  try{
    const d=await apiCall('deposit_create_zapupi',{method:'POST',body:{amount}});
    try{ sessionStorage.setItem('zap_dep_id', String(d.deposit_id)); }catch(e){}
    window.location.href = String(d.payment_url); // SAME tab, as ZapUPI requires
  }catch(e){
    if(btn) btn.disabled=false;
    showToast(e.message||'Could not start ZapUPI payment.');
  }
}
async function finishZapupiReturn(){
  let id=0; try{ id=Number(sessionStorage.getItem('zap_dep_id')||0); }catch(e){}
  if(!id) return;
  showToast('Checking your ZapUPI payment…');
  for(let i=0;i<8;i++){
    try{
      const d=await apiCall('deposit_status_zapupi',{params:{deposit_id:id}});
      if(d.status==='SUCCESS'){
        try{ sessionStorage.removeItem('zap_dep_id'); }catch(e){}
        try{ await refreshWalletAfterDeposit(); await loadDepositHistory(); }catch(e){}
        return showToast('Payment successful. Wallet credited.');
      }
      if(d.status==='FAILURE'){
        try{ sessionStorage.removeItem('zap_dep_id'); }catch(e){}
        return showToast('Payment was not completed.');
      }
    }catch(e){}
    await new Promise(r=>setTimeout(r,3000));
  }
  showToast('Payment is still processing. Your wallet will update automatically once confirmed.');
}
async function saveZapupiSettings(){
  const key=(document.getElementById('zapupiKey')?.value||'').trim();
  if(!key) return showToast('Enter your ZAP KEY first.');
  try{ await apiCall('admin_settings_update',{method:'POST',body:{zapupi_key:key}});
       document.getElementById('zapupiKey').value=''; showToast('ZapUPI key saved.'); await loadAdminSettings();
  }catch(e){ showToast(e.message||'Could not save ZapUPI key.'); }
}
async function toggleZapupiSetting(){
  const btn=document.getElementById('zapupiToggleBtn'); const next=btn.dataset.state==='on'?'off':'on';
  try{ await apiCall('admin_settings_update',{method:'POST',body:{zapupi_enabled:next}});
       showToast('ZapUPI deposits turned '+next.toUpperCase()+'.'); await loadAdminSettings();
  }catch(e){ showToast(e.message||'Could not update ZapUPI setting.'); }
}
/* Show the method selector only when ZapUPI is available. Called AFTER the reseller is
   logged in (from navigate('depositPage') and from the post-login hook), never at page load. */
async function refreshDepositMethods(){
  try{
    const d=await apiCall('zapupi_available');
    const w=document.getElementById('depositMethodWrap');
    if(!w) return;
    const both = d && d.zapupi && d.paytm;
    if(d && d.zapupi && !d.paytm){ setDepositMethod('zapupi'); }       // ZapUPI is the only one
    else if(!(d && d.zapupi)){ setDepositMethod('paytm'); }
    w.style.display = both ? 'flex' : 'none';
  }catch(e){}
}
/* After returning from ZapUPI (URL has ?order_id=...&utr=...): open Deposit page + verify. */
let __zapReturnHandled=false;
function handleZapupiReturnOnce(){
  if(__zapReturnHandled) return;
  const q=new URLSearchParams(location.search);
  if(!(q.has('order_id')||q.has('utr'))) return;
  __zapReturnHandled=true;
  try{ navigate('depositPage',document.querySelector('.bottom-nav .nav-btn[data-page="depositPage"]')); }catch(e){}
  finishZapupiReturn();
  try{ history.replaceState(null,'',location.pathname); }catch(e){}   // clean the URL
}
async function startPaytmDeposit(){const amount=Number(document.getElementById('depositAmount')?.value||0);const min=Number(window.__depositMin||10),max=Number(window.__depositMax||100000);if(!Number.isFinite(amount)||amount<min||amount>max)return showToast('Deposit amount must be between ₹'+formatInrAmount(min)+' and ₹'+formatInrAmount(max)+'.');const btn=document.getElementById('depositPayBtn');if(btn)btn.disabled=true;try{const d=await apiCall('deposit_create_paytm',{method:'POST',body:{amount}});if(depositPollTimer)clearInterval(depositPollTimer);if(depositCountdownTimer)clearInterval(depositCountdownTimer);activeDepositId=Number(d.deposit_id);document.getElementById('paytmQrAmount').textContent='₹'+Number(d.amount).toFixed(2);document.getElementById('paytmQrOrder').textContent=d.order_id||'—';const img=document.getElementById('paytmQrImage');if(!d.qr_image)throw new Error('Paytm did not return a QR image.');img.src=String(d.qr_image);img.dataset.upi=String(d.qr_data||'');document.getElementById('paytmQrModal').style.display='flex';showPaytmQrView();setPaytmStatus('WAITING FOR PAYMENT','waiting');const total=Math.max(1,Number(d.expires_in||180));depositDeadlineMs=Date.now()+total*1000;updatePaytmCountdown();depositCountdownTimer=setInterval(updatePaytmCountdown,250);depositPollTimer=setInterval(checkPaytmDepositStatus,5000);/* Match the working bot: do not query Paytm immediately after creating the order. */}catch(e){showToast(e.message||'Could not create Paytm payment.');}finally{if(btn)btn.disabled=false;}}
async function checkPaytmDepositStatus(manual=false){
  if(!activeDepositId)return;
  if(manual)setPaytmStatus('CHECKING PAYTM PAYMENT','waiting');
  try{
    const d=await apiCall('deposit_status_paytm',{params:{deposit_id:activeDepositId}});
    if(d.status==='SUCCESS'){
      if(depositPollTimer)clearInterval(depositPollTimer);
      if(depositCountdownTimer)clearInterval(depositCountdownTimer);
      const amount=document.getElementById('paytmQrAmount')?.textContent||'₹0.00';
      const sa=document.getElementById('paytmSuccessAmount');
      if(sa)sa.textContent=amount;
      // The server has already verified Paytm success and credited the wallet.
      // Refresh the authenticated wallet immediately; no manual page refresh is needed.
      showPaytmProcessing();
      await refreshWalletAfterDeposit();
      setTimeout(()=>{showPaytmSuccess();},1500);
      setTimeout(async ()=>{
        await refreshWalletAfterDeposit();
        await loadDepositHistory();
        closePaytmQr();
        showToast('Payment successful. Wallet credited automatically.');
      },4200);
    }else if(d.status==='FAILURE'){
      const msg=String(d.message||'');
      if(/invalid order id/i.test(msg)||/order not found/i.test(msg)){setPaytmStatus('WAITING FOR PAYMENT','waiting');return;}
      setPaytmStatus('PAYMENT NOT COMPLETED — STILL WAITING','waiting');
    }
  }catch(e){
    if(manual)setPaytmStatus('RETRYING PAYMENT CHECK','waiting');
  }
}
async function loadDepositHistory(){const list=document.getElementById('depositHistoryList');if(!list)return;try{const d=await apiCall('my_deposits');const rows=d.deposits||[];list.innerHTML=rows.length?rows.map(x=>{const st=x.status==='approved'?'success':(x.status==='rejected'?'failed':'pending');const label=x.status==='approved'?'Success':(x.status==='rejected'?'Failed':'Pending');return `<div class="deposit-history-row"><div class="deposit-history-main"><b>₹${Number(x.amount).toFixed(2)}</b><small>${escapeHtml(x.gateway_order_id||x.reference||'—')} • ${escapeHtml(x.created_at||'')}</small></div><span class="deposit-history-status ${st}">${label}</span></div>`}).join(''):'<div class="deposit-empty">No deposit history yet.</div>';}catch(e){list.innerHTML='<div class="deposit-empty">Unable to load deposit history.</div>';}}
let adminLogsPageNum=1;
async function loadAdminLogs(page){
  adminLogsPageNum=page;
  const list=document.getElementById('adminLogsList');
  try{
    const d=await apiCall('admin_logs',{params:{page}});
    if(list) list.innerHTML=(d.logs||[]).length ? d.logs.map(l=>`
      <div class="admin-user-row"><div><b>${escapeHtml(l.action)}</b> ${l.target?('— '+escapeHtml(l.target)):''}<div class="admin-user-meta">by ${escapeHtml(l.admin_username||'—')} • ${escapeHtml(l.created_at)} ${l.details?('• '+escapeHtml(l.details)):''}</div></div></div>
    `).join('') : '<div class="admin-empty">No log entries yet.</div>';
  }catch(e){ if(list) list.innerHTML='<div class="admin-empty">Unable to load logs.</div>'; }
}

/* Self-signup "user" accounts never see API keys / API docs. */
function applyAccountTypeUi(accountType){
    const isUser = accountType === 'user';
    document.body.classList.toggle('account-type-user', isUser);
    document.querySelectorAll('.nav-btn[data-page="apiPage"]').forEach(function(n){ n.style.display = isUser ? 'none' : ''; });
    const apiSection = document.getElementById('apiPage');
    if(apiSection) apiSection.style.display = isUser ? 'none' : '';
}
function enterResellerDashboard(auth){
    setUserMaintenanceMode(!!auth.global_maintenance, auth.global_maintenance_message);
    loginScreen.style.transition = "opacity .35s ease, transform .35s ease";
    loginScreen.style.opacity = "0";
    loginScreen.style.transform = "translateY(-10px)";
    setTimeout(async function(){
        loginScreen.style.display = "none";
        loginScreen.style.visibility = "hidden";
        loginScreen.style.pointerEvents = "none";
        adminApp.classList.remove("visible");
        document.body.classList.remove('admin-mode');
        app.style.display = "block";
        app.classList.add("visible");
        document.querySelectorAll('#app .bottom-nav').forEach(n=>n.style.display='grid');
        app.setAttribute('aria-hidden','false');
        applyAccountTypeUi(auth.account ? auth.account.account_type : 'reseller');
        navigate('homePage', document.querySelector('.nav-btn[data-page="homePage"]'));
        window.scrollTo({ top:0, behavior:"instant" });
        try {
            const ok = await restoreAuthenticatedSession();
            if(!ok) showToast('Dashboard loaded. Some live data could not be refreshed.');
        } catch(e) {
            console.error('Dashboard restore failed:', e);
            showToast('Dashboard loaded.');
        }
    },350);
}
document.getElementById('showSignupBtn')?.addEventListener('click', function(){
    document.getElementById('loginForm').style.display = 'none';
    document.getElementById('loginToggleRow').style.display = 'none';
    document.getElementById('signupForm').style.display = 'block';
    document.getElementById('signupToggleRow').style.display = 'block';
});
document.getElementById('showLoginBtn')?.addEventListener('click', function(){
    document.getElementById('signupForm').style.display = 'none';
    document.getElementById('signupToggleRow').style.display = 'none';
    document.getElementById('loginForm').style.display = 'block';
    document.getElementById('loginToggleRow').style.display = 'block';
});
document.getElementById('signupForm')?.addEventListener('submit', async function(event){
    event.preventDefault();
    const username = document.getElementById('signupUsername').value.trim();
    const mobile   = document.getElementById('signupMobile').value.trim();
    const email    = document.getElementById('signupEmail').value.trim();
    const password = document.getElementById('signupPassword').value;
    const msg = document.getElementById('signupMessage');
    msg.classList.remove('show');
    if(!username || !mobile || !email || !password){
        msg.textContent = 'Please fill in all fields.'; msg.classList.add('show'); return;
    }
    if(!/^[6-9][0-9]{9}$/.test(mobile)){ msg.textContent = 'Enter a valid 10-digit mobile number.'; msg.classList.add('show'); return; }
    if(password.length < 8){ msg.textContent = 'Password must be at least 8 characters.'; msg.classList.add('show'); return; }
    const submitBtn = event.target.querySelector('button[type="submit"]');
    if(submitBtn) submitBtn.disabled = true;
    let auth;
    try{
        auth = await apiCall('signup', { method:'POST', body:{ username, mobile, email, password } });
    }catch(e){
        msg.textContent = e.message || 'Could not create your account.'; msg.classList.add('show');
        if(submitBtn) submitBtn.disabled = false;
        return;
    }
    if(submitBtn) submitBtn.disabled = false;
    if(auth.csrf_token) window.__csrfToken = auth.csrf_token;
    if(auth.branding) applyBranding(auth.branding);
    enterResellerDashboard(auth);
});
loginForm.addEventListener("submit", async function(event){

    event.preventDefault();

    const username = usernameInput.value.trim();
    const password = passwordInput.value.trim();

    if(!username || !password){
        loginMessage.textContent = "Please enter username and password.";
        loginMessage.classList.add("show");
        return;
    }

    loginMessage.classList.remove("show");
    loginScreen.style.visibility = "visible";
    loginScreen.style.pointerEvents = "auto";
    const submitBtn = loginForm.querySelector('button[type="submit"]');
    if(submitBtn) submitBtn.disabled = true;

    let auth;
    try{
        auth = await apiCall('login.php', { method:'POST', body:{ username, password } });
    }catch(e){
        console.error('Login request failed:', e);
        loginMessage.textContent = e.status === 401 ? "Invalid username or password." : "Unable to sign in right now. Please try again.";
        loginMessage.classList.add("show");
        if(submitBtn) submitBtn.disabled = false;
        return;
    }
    if(submitBtn) submitBtn.disabled = false;
    if(auth.csrf_token) window.__csrfToken = auth.csrf_token;
    if(auth.branding) applyBranding(auth.branding);

    /* Apply maintenance immediately after reseller authentication so the
       dashboard never flashes through before the full-screen lock appears. */
    if(auth.role === 'reseller'){
        setUserMaintenanceMode(!!auth.global_maintenance, auth.global_maintenance_message);
    } else {
        setUserMaintenanceMode(false);
    }

    /* One login form, two protected roles. */
    if(auth.role === 'admin'){
        document.body.classList.add('admin-mode');
        app.classList.remove('visible');
        app.style.display = 'none';
        app.setAttribute('aria-hidden','true');
        document.querySelectorAll('#app .bottom-nav, body > .bottom-nav, .bottom-nav').forEach(function(n){
            n.style.setProperty('display','none','important');
            n.style.setProperty('visibility','hidden','important');
            n.setAttribute('aria-hidden','true');
        });
        if(adminUsername) adminUsername.textContent = auth.account.username;
        loginScreen.style.transition = "opacity .35s ease, transform .35s ease";
        loginScreen.style.opacity = "0";
        loginScreen.style.transform = "translateY(-10px)";
        setTimeout(function(){
            loginScreen.style.display = "none";
            app.classList.remove("visible");
            app.style.display = "none";
            document.querySelectorAll('#app .bottom-nav').forEach(n=>n.style.display='none');
            adminApp.classList.add("visible");
            adminApp.setAttribute('aria-hidden','false');
            document.body.classList.add('admin-mode');
            document.querySelectorAll('#app .bottom-nav').forEach(function(n){
                n.style.display='none';
                n.setAttribute('aria-hidden','true');
            });
            window.scrollTo({ top:0, behavior:"instant" });
            adminSwitchTab('users');
            setAdminMaintenanceMode(!!auth.global_maintenance, auth.global_maintenance_message);
            loadAdminSettings();
            generateUserCredentials();
        },350);
        return;
    }

    /* IMPORTANT: show the user dashboard immediately after successful
       authentication. Do not block the UI on the secondary /me or order
       requests; a failed dashboard data request must never leave a black
       screen. */
    enterResellerDashboard(auth);

});

/* Full-screen maintenance lock for reseller accounts only. */
function setAdminMaintenanceMode(enabled, message){
  const overlay=document.getElementById('adminMaintenanceOverlay'); if(!overlay)return;
  const msg=document.getElementById('adminMaintenanceMessage'); if(msg)msg.textContent=message||'We are performing scheduled maintenance.';
  overlay.classList.toggle('visible',!!enabled); overlay.setAttribute('aria-hidden',enabled?'false':'true');
}
function exitAdminMaintenance(){ if(document.body.classList.contains('admin-mode')) setAdminMaintenanceMode(false); }
function setUserMaintenanceMode(enabled, message){
    const overlay = document.getElementById('userMaintenanceOverlay');
    if(!overlay) return;
    const msg = document.getElementById('userMaintenanceMessage');
    if(msg) msg.textContent = message || 'We are performing scheduled maintenance. Please check back shortly.';
    overlay.classList.toggle('visible', !!enabled);
    overlay.setAttribute('aria-hidden', enabled ? 'false' : 'true');
    document.body.classList.toggle('user-maintenance-active', !!enabled);
}

let maintenanceStatusTimer = null;
function startMaintenanceStatusMonitor(){
    if(maintenanceStatusTimer) clearInterval(maintenanceStatusTimer);
    maintenanceStatusTimer = setInterval(async function(){
        if(!app.classList.contains('visible') || document.body.classList.contains('admin-mode')) return;
        try{
            const me = await apiCall('me.php');
            if(me.role === 'reseller') setUserMaintenanceMode(!!me.global_maintenance, me.global_maintenance_message);
        }catch(e){}
    }, 15000);
}

/* Pulls the real session/wallet/order data and populates the dashboard.
   Called right after login, and on page load if a session cookie is
   already valid (see restoreSession below). */
async function restoreAuthenticatedSession(){
    let me;
    try{
        me = await apiCall('me.php');
    }catch(e){
        return false;
    }

    if(me.role === 'admin'){
        setUserMaintenanceMode(false);
        if(maintenanceStatusTimer) { clearInterval(maintenanceStatusTimer); maintenanceStatusTimer = null; }
        if(adminUsername) adminUsername.textContent = me.account.username;
        if(me.csrf_token) window.__csrfToken = me.csrf_token;
        loginScreen.style.display = 'none';
        app.classList.remove('visible');
        app.style.display = 'none';
        document.body.classList.add('admin-mode');
        app.classList.remove('visible');
        app.style.display='none';
        document.querySelectorAll('#app .bottom-nav, body > .bottom-nav, .bottom-nav').forEach(function(n){
            n.style.setProperty('display','none','important');
            n.style.setProperty('visibility','hidden','important');
            n.setAttribute('aria-hidden','true');
        });
        adminApp.classList.add('visible');
        adminApp.setAttribute('aria-hidden','false');
        window.__soundEnabled = me.sound_enabled !== false;
        adminSwitchTab('users');
        setAdminMaintenanceMode(!!me.global_maintenance, me.global_maintenance_message);
        loadAdminSettings();
        generateUserCredentials();
        return true;
    }

    if(me.csrf_token) window.__csrfToken = me.csrf_token;
    if(me.branding) applyBranding(me.branding);
    const r = me.reseller;
    if(!r) return false;
    dashboardUsername.textContent = r.username;
    applyAccountTypeUi(r.account_type);
    if(headerUsername) headerUsername.textContent = r.username.toUpperCase();
    document.getElementById('headerBalance').textContent = '₹ ' + Number(r.balance).toFixed(2);
    document.getElementById('statWalletBalance').textContent = '₹' + Number(r.balance).toFixed(2);
    const depositBal = document.getElementById('depositWalletBalance'); if(depositBal) depositBal.textContent = Number(r.balance).toFixed(2);
    window.__soundEnabled = me.sound_enabled !== false;
    window.__supportUrl = me.support_url || '';
    window.__groupUpdateUrl = me.group_update_url || '';
    applyDepositSettings(me.deposit_min_amount, me.deposit_max_amount);
    try{ handleZapupiReturnOnce(); }catch(e){}
    const supportWrap = document.getElementById('supportButtonWrap');
    if(supportWrap) supportWrap.style.display = window.__supportUrl ? 'block' : 'none';
    setUserMaintenanceMode(!!me.global_maintenance, me.global_maintenance_message || 'We are performing scheduled maintenance. Please check back shortly.');
    startMaintenanceStatusMonitor();
    /* Never let order/history loading prevent the dashboard from rendering. */
    try { await loadMyKeys(); } catch(e) { console.warn('My Keys refresh failed:', e); }
    catalogCache = null;
    return true;
}

/* Runs immediately while the splash covers the page. A valid server session
   decides whether the user or admin view is restored; localStorage never logs
   anyone in. */
let catalogCache = null;
(async function restoreSession(){
    try{
        const ok = await restoreAuthenticatedSession();
        if(ok && document.getElementById('adminApp').classList.contains('visible')) return;
        if(ok){
            loginScreen.style.display = 'none';
            loginScreen.style.visibility = 'hidden';
            loginScreen.style.pointerEvents = 'none';
            app.style.display = 'block';
            app.classList.add('visible');
            document.querySelectorAll('#app .bottom-nav').forEach(n=>{ n.style.display='grid'; n.style.visibility='visible'; });
            navigate('homePage', document.querySelector('.nav-btn[data-page="homePage"]'));
        } else {
            loginScreen.style.display = 'flex';
            loginScreen.style.visibility = 'visible';
            loginScreen.style.pointerEvents = 'auto';
        }
    }catch(e){
        console.warn('Session restore skipped:', e);
        loginScreen.style.display = 'flex';
        loginScreen.style.visibility = 'visible';
        loginScreen.style.pointerEvents = 'auto';
    } finally {
        splashBootReady=true;
        tryHideSplash();
    }
})();


/* =========================================================
   RESTORE USERNAME FOR CURRENT SESSION
   ========================================================= */

(function(){

    const saved =
        sessionStorage.getItem(
            "flashStarUsername"
        );

    if(saved){
        dashboardUsername.textContent =
            saved;
    }

})();


/* =========================================================
   NAVIGATION
   ========================================================= */

function navigate(pageId,button){
    if(pageId==='depositPage'){setTimeout(()=>{loadDepositHistory();refreshDepositMethods();},0);}

    const pages =
        document.querySelectorAll(".page");

    pages.forEach(function(page){
        page.classList.remove("active");
    });


    const target =
        document.getElementById(pageId);

    if(target){
        target.classList.add("active");
        // Collapse any legacy spacer between the fixed-height header and the
        // active user section. This affects only the user Store/Deposit/My Keys/API
        // pages and never changes the bottom navigation position.
        if(['storePage','depositPage','keyPage','apiPage','apiProductsPage','apiDocsPage'].includes(pageId)){
            requestAnimationFrame(function(){
                try{
                    const header=document.querySelector('#app .top-header');
                    const first=target.firstElementChild;
                    if(header && first){
                        const gap=Math.round(first.getBoundingClientRect().top-header.getBoundingClientRect().bottom);
                        if(gap>8){ target.style.marginTop=(-gap+6)+'px'; }
                        else { target.style.marginTop='0px'; }
                    }
                }catch(e){}
            });
        }
    }


    document
        .querySelectorAll(".nav-btn")
        .forEach(function(btn){
            btn.classList.remove("active");
        });


    if(button){
        button.classList.add("active");
    } else {
        const fallbackButton=document.querySelector('.bottom-nav .nav-btn[data-page=+pageId+]');
        if(fallbackButton) fallbackButton.classList.add('active');
    }
    if(app && app.classList.contains('visible')){
        document.querySelectorAll('#app .bottom-nav').forEach(function(n){ n.style.display='grid'; n.style.visibility='visible'; });
    }


    // Every section always opens from the very top; never preserve the previous page's scroll position.
    window.scrollTo({top:0,behavior:"auto"});
    if(document.scrollingElement) document.scrollingElement.scrollTop=0;
    document.documentElement.scrollTop=0;
    document.body.scrollTop=0;

    if(pageId === 'storePage') openStorePage();
    if(pageId === 'apiPage') { loadMyApiKeyInfo(); loadApiVariantBrowser(); }
    if(pageId === 'apiProductsPage') loadApiProductsDirectory();
    if(pageId === 'keyPage' || pageId === 'homePage') loadMyKeys();
    if(pageId === 'homePage') loadTopResellers();
    if(pageId === 'apiPage') apiCall('me.php').then(r => {
        const el = document.getElementById('apiWalletBalance');
        if(el) el.textContent = Number(r.reseller.balance).toFixed(2);
    }).catch(()=>{});

}


/* =========================================================
   LOGOUT
   ========================================================= */

function logout(){

    apiCall('logout.php', { method:'POST' }).catch(()=>{}); // best-effort — reset the UI regardless
    setUserMaintenanceMode(false);
    if(maintenanceStatusTimer) { clearInterval(maintenanceStatusTimer); maintenanceStatusTimer = null; }

    app.classList.remove("visible");
    app.style.display = "none";
    adminApp.classList.remove("visible");
    adminApp.setAttribute('aria-hidden','true');
    document.body.classList.remove('admin-mode');
    document.querySelectorAll('#app .bottom-nav').forEach(n=>{
        n.style.display='none';
        n.setAttribute('aria-hidden','true');
    });

    // The login screen may still have visibility:hidden/pointer-events:none
    // from the previous login transition. Reset every visual state on logout
    // so the page can never remain as a black/blank screen.
    loginScreen.style.display = "flex";
    loginScreen.style.visibility = "visible";
    loginScreen.style.pointerEvents = "auto";
    loginScreen.style.opacity = "0";
    loginScreen.style.transform = "translateY(10px)";

    try { sessionStorage.removeItem("flashStarUsername"); } catch(e) {}


    requestAnimationFrame(function(){

        requestAnimationFrame(function(){

            loginScreen.style.opacity = "1";
            loginScreen.style.transform =
                "translateY(0)";

        });

    });


    usernameInput.value = "";
    passwordInput.value = "";

    navigate(
        "homePage",
        document.querySelector(
            '.nav-btn[data-page="homePage"]'
        )
    );

}


/* =========================================================
   TOAST
   ========================================================= */

let toastTimer;

function showToast(message){

    const toast =
        document.getElementById("toast");

    toast.textContent = message;

    toast.classList.add("show");

    clearTimeout(toastTimer);

    toastTimer =
        setTimeout(function(){

            toast.classList.remove("show");

        },2500);

}


/* =========================================================
   STORE CATEGORY / PRODUCT / PLAN FLOW — all real data from
   /api/catalog.php (server-computed effective price per reseller,
   live stock counts). Fetched once per Store visit and cached in
   catalogCache until the next login/logout.
   ========================================================= */
function fillSelect(el, items, placeholder){
    el.innerHTML='';
    const first=document.createElement('option'); first.value=''; first.textContent=placeholder; el.appendChild(first);
    items.forEach(item=>{ const o=document.createElement('option'); o.value=item[0]; o.textContent=item[1]; el.appendChild(o); });
}

async function ensureCatalogLoaded(){
    if(catalogCache) return catalogCache;
    const data = await apiCall('catalog.php');
    catalogCache = data;
    return data;
}

async function openStorePage(){
    const categorySelect = document.getElementById('storeCategory');
    try{
        const { categories } = await ensureCatalogLoaded();
        fillSelect(categorySelect, categories.map(c => [String(c.id), c.name]), '— Select a category —');
    }catch(e){
        showToast(e.message || 'Could not load the store. Please try again.');
    }
}

function storeCategoryChanged(){
    const catId = document.getElementById('storeCategory').value;
    const product=document.getElementById('storeProduct');
    const plan=document.getElementById('storePlan');
    document.getElementById('storeOrder').classList.remove('show');
    plan.disabled=true; plan.innerHTML='<option value="">— Select Product First —</option>';
    if(!catId || !catalogCache){ product.disabled=true; product.innerHTML='<option value="">— Select Category First —</option>'; return; }
    const products = catalogCache.products.filter(p => String(p.category_id) === catId);
    product.disabled=false;
    fillSelect(product, products.map(p => [String(p.id), p.name]), '— Select a product —');
}
function storeProductChanged(){
    const productId=document.getElementById('storeProduct').value;
    const plan=document.getElementById('storePlan');
    document.getElementById('storeOrder').classList.remove('show');
    if(!productId || !catalogCache){plan.disabled=true;plan.innerHTML='<option value="">— Select Product First —</option>';return;}
    const product = catalogCache.products.find(p => String(p.id) === productId);
    plan.disabled=false;
    fillSelect(
        plan,
        product.plans.map(pl => [
            String(pl.id),
            pl.duration_label + (product.maintenance ? ' — UNDER MAINTENANCE' : (' — ₹' + Number(pl.price).toFixed(2)))
        ]),
        '— Select a plan —'
    );
}
function storePlanChanged(){
    const productId=document.getElementById('storeProduct').value, planId=document.getElementById('storePlan').value;
    const box=document.getElementById('storeOrder');
    if(!productId||!planId||!catalogCache){box.classList.remove('show');return;}
    const product = catalogCache.products.find(p => String(p.id) === productId);
    const plan = product.plans.find(pl => String(pl.id) === planId);
    document.getElementById('selectedPlanName').textContent = product.name + ' • ' + plan.duration_label;

    const maintNotice = document.getElementById('storeMaintenanceNotice');
    const priceRow = document.getElementById('storePriceRow');
    const stockRow = document.getElementById('storeStockRow');
    const buyBtn = document.getElementById('storeBuyBtn');
    const ytBtn = document.getElementById('storeYoutubeBtn');
    const ytUrl = plan.youtube_url || product.youtube_url || '';
    window.__storeYoutubeUrl = ytUrl;
    ytBtn.style.display = ytUrl ? 'inline-block' : 'none';

    if(product.maintenance){
        // Under maintenance: never show stock/price/buy/retry controls — the
        // product itself stays visible, only purchasing is disabled.
        maintNotice.style.display = 'block';
        priceRow.style.display = 'none';
        stockRow.style.display = 'none';
        buyBtn.style.display = 'none';
        box.classList.add('show');
        box.dataset.planId = '';
        box.dataset.stock = '0';
        return;
    }

    maintNotice.style.display = 'none';
    priceRow.style.display = '';
    stockRow.style.display = '';
    buyBtn.style.display = 'inline-block';
    document.getElementById('selectedPlanPrice').textContent = '₹' + Number(plan.price).toFixed(2) + (plan.price_source === 'custom' ? ' (Custom Price)' : '');
    document.getElementById('selectedPlanStock').textContent = Number(plan.stock||0)===0 ? 'OUT OF STOCK' : 'AVAILABLE';
    box.classList.add('show');
    box.dataset.planId = planId;
    box.dataset.stock = plan.stock;
}

async function buySelectedPlan(){
    const box = document.getElementById('storeOrder');
    const planId = parseInt(box.dataset.planId, 10);
    if(!planId){ showToast('Please select a plan first.'); return; }
    if(Number(box.dataset.stock) === 0){ showToast('This plan is out of stock right now.'); return; }

    const buyBtn = box.querySelector('button');
    buyBtn.disabled = true;
    const originalLabel = buyBtn.textContent;
    buyBtn.textContent = 'PROCESSING…';

    const idempotencyKey = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random());

    try{
        const { order } = await apiCall('buy.php', { method:'POST', body:{ plan_id: planId, quantity: 1, idempotency_key: idempotencyKey } });
        document.getElementById('headerBalance').textContent = '₹ ' + Number(order.remaining_balance).toFixed(2);
        document.getElementById('statWalletBalance').textContent = '₹' + Number(order.remaining_balance).toFixed(2);
        catalogCache = null; // stock just changed — force a fresh fetch next time Store opens
        showPurchaseResult(order);
        await loadMyKeys();
    }catch(e){
        if(e.status === 402){
            showToast('❌ Insufficient wallet balance. Please contact admin to add funds.');
        }else if(e.status === 409){
            showToast('❌ Out of stock — someone bought the last key. Please pick another plan.');
        }else{
            showToast('❌ ' + (e.message || 'Purchase failed. You have not been charged.'));
        }
    }finally{
        buyBtn.disabled = false;
        buyBtn.textContent = originalLabel;
    }
}


function copyPurchaseText(text, successMessage='Copied.'){
    const value=String(text||'').trim();
    if(!value)return;
    if(navigator.clipboard && window.isSecureContext){
        navigator.clipboard.writeText(value).then(()=>showToast(successMessage)).catch(()=>fallbackPurchaseCopy(value,successMessage));
        return;
    }
    fallbackPurchaseCopy(value,successMessage);
}
function fallbackPurchaseCopy(value,successMessage){
    const ta=document.createElement('textarea');
    ta.value=value; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select();
    try{document.execCommand('copy');showToast(successMessage);}catch(e){showToast('Could not copy key.');}
    ta.remove();
}
function getPurchaseKeys(order){
    return String(order?.license_key||'').split(/\n+/).map(k=>k.trim()).filter(Boolean);
}
function showPurchaseResult(order){
    const modal=document.getElementById('purchaseResultModal');
    const keysEl=document.getElementById('purchaseResultKeys');
    const infoEl=document.getElementById('purchaseResultInfo');
    const countEl=document.getElementById('purchaseResultKeysCount');
    const allBtn=document.getElementById('purchaseResultCopyAll');
    if(!modal||!keysEl)return;
    const keys=getPurchaseKeys(order);
    window.__purchaseResultKeys=keys;
    if(countEl)countEl.textContent=keys.length>1?' ('+keys.length+')':'';
    if(infoEl){
        infoEl.innerHTML='<b>'+escapeHtml(order?.product_name||'Product')+'</b> · '+escapeHtml(order?.duration_label||'—')+' · Order <b>'+escapeHtml(order?.order_code||'—')+'</b>'+(order?.provider_order_id?' · Provider <b>'+escapeHtml(order.provider_order_id)+'</b>':'')+(order?.expires_at?' · Expires <b>'+escapeHtml(order.expires_at)+'</b>':'');
    }
    keysEl.innerHTML=keys.length?keys.map((key,i)=>`<div class="purchase-result-key-row"><span class="purchase-result-key-value">${escapeHtml(key)}</span><button type="button" class="purchase-result-key-copy" onclick="copyPurchaseText(window.__purchaseResultKeys[${i}], 'Key copied.')">📋 COPY</button></div>`).join(''):'<div class="purchase-result-key-row"><span class="purchase-result-key-value">Key not available. Please check My Keys.</span></div>';
    if(allBtn)allBtn.style.display=keys.length>1?'inline-block':'none';
    modal.style.display='flex';
    modal.setAttribute('aria-hidden','false');
    document.body.classList.add('purchase-result-open');
}
function copyPurchaseAll(){
    const keys=Array.isArray(window.__purchaseResultKeys)?window.__purchaseResultKeys:[];
    if(keys.length)copyPurchaseText(keys.join('\n'),'All keys copied.');
}
function closePurchaseResult(){
    const modal=document.getElementById('purchaseResultModal');
    if(!modal)return;
    modal.style.display='none';
    modal.setAttribute('aria-hidden','true');
    document.body.classList.remove('purchase-result-open');
}
document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){
        const modal=document.getElementById('purchaseResultModal');
        if(modal&&modal.style.display!=='none')closePurchaseResult();
    }
});

/* =========================================================
   MY KEY — this reseller's own order history (server enforces
   this; there is no client-side filtering of "whose" data this is).
   Also drives the Home page stat cards (today/month counts, total
   spent) since Phase 1 doesn't have a dedicated stats endpoint yet —
   computed here from the same order list already being fetched.
   ========================================================= */
function copyMyKey(value){
    const text = String(value || '');
    if(!text) return;
    if(navigator.clipboard && window.isSecureContext){
        navigator.clipboard.writeText(text).then(()=>showToast('Key copied.')).catch(()=>showToast('Could not copy key.'));
        return;
    }
    const ta=document.createElement('textarea');
    ta.value=text; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select();
    try{document.execCommand('copy');showToast('Key copied.');}catch(e){showToast('Could not copy key.');}
    ta.remove();
}

async function loadMyKeys(){
    let orders;
    try{
        ({ orders } = await apiCall('my_orders.php'));
    }catch(e){
        return;
    }

    const totalCountEl = document.getElementById('myKeyTotalCount');
    const totalSpentEl = document.getElementById('myKeyTotalSpent');
    const listEl = document.getElementById('myKeyList');
    if(totalCountEl) totalCountEl.textContent = orders.length;
    const totalSpent = orders.reduce((s,o)=>s+Number(o.total_price),0);
    if(totalSpentEl) totalSpentEl.textContent = '₹' + totalSpent.toFixed(2);

    if(listEl){
        listEl.innerHTML = orders.length ? orders.map((o, index) => {
            const source = String(o.source || 'web').toLowerCase();
            const viaApi = source === 'api';
            const sourceLabel = viaApi ? '🔌 Via API' : '🌐 Site Purchase';
            const sourceClass = viaApi ? 'mykey-source-api' : 'mykey-source-web';
            const keys = String(o.license_key || '').split(/\n+/).map(k=>k.trim()).filter(Boolean);
            const keyHtml = keys.length ? keys.map((key, keyIndex) => `
                <div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:${keyIndex < keys.length-1 ? '7px' : '0'};">
                    <span style="flex:1;min-width:0;">${escapeHtml(key)}</span>
                    <button type="button" class="mykey-copy" title="Copy key" data-key="${escapeHtml(key)}" onclick="copyMyKey(this.dataset.key)">📋</button>
                </div>
            `).join('') : '<span>Key not available</span>';
            return `
                <div class="mykey-order-card">
                    <div class="mykey-product">🎬 ${escapeHtml(o.product_name || 'Product')}</div>
                    <div class="mykey-license">${keyHtml}</div>
                    <div class="mykey-meta-row">
                        <span class="mykey-chip">${escapeHtml(o.duration_label || '—')}</span>
                        <span class="mykey-chip">₹${Number(o.total_price || 0).toFixed(2)}</span>
                        <span class="mykey-chip ${sourceClass}">${sourceLabel}</span>
                    </div>
                    <div class="mykey-purchased">🛒 Purchased: ${escapeHtml(o.created_at || '—')}</div>
                    <div class="mykey-order-code">Order: ${escapeHtml(o.order_code || '—')} · Duration ID: ${escapeHtml(String(o.duration_id || o.plan_id || '—'))}</div>
                </div>
            `;
        }).join('') : '<div style="text-align:center;color:#9a94a8;font-size:12px;padding:14px;">No purchase history yet.</div>';
    }

    // Today / this-month counts for the Home page stat cards.
    const today = new Date().toDateString();
    const now = new Date();
    const keysToday = orders.filter(o => new Date(o.created_at).toDateString() === today).length;
    const keysMonth = orders.filter(o => {
        const d = new Date(o.created_at);
        return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
    }).length;
    const keysTodayEl = document.getElementById('statKeysToday');
    const keysMonthEl = document.getElementById('statKeysMonth');
    const totalSpentStatEl = document.getElementById('statTotalSpent');
    if(keysTodayEl) keysTodayEl.textContent = keysToday;
    if(keysMonthEl) keysMonthEl.textContent = keysMonth;
    if(totalSpentStatEl) totalSpentStatEl.textContent = '₹' + totalSpent.toFixed(2);
    loadTopResellers();
}

/* =========================================================
   GLOBAL TAP-TAP SOUND — one subtle sound per real user tap.
   Pointerdown is used instead of both pointerdown+click so mobile
   browsers never play the sound twice. Web Audio is created/resumed
   only from a real user gesture, so it works with mobile autoplay rules.
   ========================================================= */
(function(){
    let audioCtx = null;
    let lastTap = 0;
    const selector = 'button,a,select,[role="button"],.nav-btn,.plan-card,.product-card,.stat-card,.clickable,input[type="submit"],label';
    function tapSound(){
        const AC = window.AudioContext || window.webkitAudioContext;
        if(!AC) return;
        try{
            audioCtx = audioCtx || new AC();
            if(audioCtx.state === 'suspended') audioCtx.resume();
            const now = audioCtx.currentTime;
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(560, now);
            osc.frequency.exponentialRampToValueAtTime(320, now + 0.045);
            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.16, now + 0.005);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.10);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start(now);
            osc.stop(now + 0.105);
        }catch(e){}
    }
    document.addEventListener('pointerdown', function(e){
        if(window.__soundEnabled === false) return;
        const target = e.target && e.target.closest ? e.target.closest(selector) : null;
        if(!target) return;
        const now = Date.now();
        if(now - lastTap < 80) return;
        lastTap = now;
        tapSound();
    }, true);
})();

function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

</script>

<div id="adminMaintenanceOverlay" class="user-maintenance-overlay admin-maintenance-overlay" aria-hidden="true">
  <div class="maintenance-orbit" aria-hidden="true"><span class="orbit-ring ring-one"></span><span class="orbit-ring ring-two"></span><span class="orbit-core">✦</span><span class="orbit-signal signal-one"></span><span class="orbit-signal signal-two"></span><span class="orbit-signal signal-three"></span></div>
  <div class="maintenance-lock-title">SITE UNDER<br>MAINTENANCE MODE</div>
  <div class="maintenance-lock-line"></div>
  <div class="maintenance-lock-message" id="adminMaintenanceMessage">We are performing scheduled maintenance.</div>
  <div class="maintenance-lock-status"><span class="maintenance-live-dot"></span> ADMIN ACCESS AVAILABLE</div>
  <button type="button" class="maintenance-admin-back" onclick="exitAdminMaintenance()">← ADMIN BACK TO PANEL</button>
</div>

<style id="api-compact-mobile-fix-v1">
/* API USER PAGES — compact mobile sizing only. No layout/feature/backend changes. */
@media (max-width:520px){
  #apiPage .api-video-wrap,
  #apiDocsPage .api-docs-wrap,
  #apiProductsPage .api-docs-wrap{
    width:100%!important;
    max-width:none!important;
    padding-left:0!important;
    padding-right:0!important;
    box-sizing:border-box!important;
  }

  #apiPage .api-wallet-video,
  #apiProductsPage .api-wallet-card{
    font-size:13px!important;
    line-height:1.25!important;
    padding:11px 13px!important;
    margin-bottom:10px!important;
    border-radius:10px!important;
  }
  #apiPage .api-wallet-video b{font-size:16px!important}

  #apiPage .api-video-back,
  #apiDocsPage .api-back,
  #apiProductsPage .api-back{
    font-size:11px!important;
    padding:2px 0 9px!important;
    margin:0!important;
  }

  #apiPage .api-video-card{
    padding:15px 13px 16px!important;
    margin-bottom:11px!important;
    border-radius:10px!important;
  }
  #apiPage .api-video-title{
    font-size:10px!important;
    line-height:1.35!important;
    letter-spacing:1.15px!important;
    gap:6px!important;
    margin-bottom:11px!important;
  }
  #apiPage .api-video-title i{margin-left:3px!important}
  #apiPage .api-video-label{
    font-size:10px!important;
    letter-spacing:1.25px!important;
    margin:3px 0 7px!important;
  }

  #apiPage .api-video-keybox{
    gap:6px!important;
    padding:9px!important;
    min-height:48px!important;
    border-radius:5px!important;
  }
  #apiPage .api-video-keybox code{
    font-size:9px!important;
    line-height:1.35!important;
    min-width:0!important;
  }
  #apiPage .api-video-keybox button,
  #apiPage .api-video-copy-id{
    padding:7px 8px!important;
    font-size:8px!important;
    border-radius:5px!important;
  }
  #apiPage .api-video-warning{
    font-size:9px!important;
    line-height:1.4!important;
    margin:9px 0 12px!important;
  }

  #apiPage .api-video-dark-btn,
  #apiPage .api-video-gold-btn{
    height:44px!important;
    min-height:44px!important;
    font-size:11px!important;
    letter-spacing:1px!important;
    border-radius:4px!important;
  }

  #apiPage .api-variants-title{margin-bottom:9px!important}
  #apiPage .api-variants-title strong{
    padding:5px 7px!important;
    font-size:8px!important;
    letter-spacing:1px!important;
    border-radius:5px!important;
  }
  #apiPage .api-video-product{margin-top:6px!important;border-radius:5px!important}
  #apiPage .api-video-product-head{
    padding:11px 10px!important;
    font-size:12px!important;
  }
  #apiPage .api-video-plan-row{
    grid-template-columns:48px minmax(0,1fr) auto!important;
    gap:6px!important;
    padding:9px 8px!important;
  }
  #apiPage .api-video-plan-id{
    padding:6px 4px!important;
    font-size:9px!important;
  }
  #apiPage .api-video-plan-info b{font-size:11px!important;margin-bottom:2px!important}
  #apiPage .api-video-plan-info span{font-size:8px!important;line-height:1.35!important}
  #apiPage .api-video-copy-id{font-size:7px!important;padding:5px 6px!important}

  #apiPage .api-code-video-card{padding-bottom:13px!important}
  #apiPage .api-video-code{border-radius:5px!important}
  #apiPage .api-video-code pre{
    padding:12px 10px!important;
    font-size:9px!important;
    line-height:1.45!important;
    min-width:max-content!important;
  }
  #apiPage .api-video-code button{
    right:6px!important;
    top:6px!important;
    padding:5px 7px!important;
    font-size:8px!important;
    border-radius:4px!important;
  }

  #apiDocsPage .api-card,
  #apiProductsPage .api-card{
    padding:13px!important;
    margin-bottom:11px!important;
    border-radius:12px!important;
  }
  #apiDocsPage .api-heading,
  #apiProductsPage .api-heading{
    font-size:10px!important;
    letter-spacing:1.4px!important;
    gap:6px!important;
    margin-bottom:9px!important;
  }
  #apiDocsPage .api-card p,
  #apiProductsPage .api-card p{
    font-size:9px!important;
    line-height:1.45!important;
  }
  #apiDocsPage .api-endpoint,
  #apiProductsPage .api-endpoint{
    padding:9px!important;
    margin-top:7px!important;
    border-radius:8px!important;
  }
  #apiDocsPage .api-endpoint b,
  #apiProductsPage .api-endpoint b{
    padding:3px 5px!important;
    font-size:8px!important;
    margin-right:5px!important;
  }
  #apiDocsPage .api-endpoint strong,
  #apiProductsPage .api-endpoint strong{
    font-size:11px!important;
    line-height:1.35!important;
    overflow-wrap:anywhere!important;
  }
  #apiDocsPage .api-endpoint p,
  #apiProductsPage .api-endpoint p{margin:6px 0 3px!important}
  #apiDocsPage .api-endpoint small,
  #apiProductsPage .api-endpoint small{
    font-size:8px!important;
    overflow-wrap:anywhere!important;
  }
  #apiDocsPage .api-code-block pre{
    padding:12px 10px!important;
    font-size:8.5px!important;
    line-height:1.45!important;
    min-width:max-content!important;
  }
  #apiDocsPage .copy-code{
    right:6px!important;
    top:6px!important;
    padding:5px 7px!important;
    font-size:8px!important;
  }
  #apiProductsPage .api-gold-btn{
    height:40px!important;
    font-size:11px!important;
    border-radius:8px!important;
  }
  #apiProductsPage .api-product-toggle{padding:10px!important;font-size:11px!important}
  #apiProductsPage .api-duration-row{padding:9px 10px!important;gap:7px!important}
  #apiProductsPage .api-duration-row b{font-size:10px!important}
  #apiProductsPage .api-duration-row small{font-size:8px!important}
  #apiProductsPage .api-duration-id{gap:4px!important}
  #apiProductsPage .api-duration-id span{font-size:8px!important}
  #apiProductsPage .api-duration-id button{padding:4px 6px!important;font-size:8px!important}
}

@media (max-width:380px){
  #apiPage .api-video-card{padding-left:11px!important;padding-right:11px!important}
  #apiPage .api-video-title{font-size:9px!important;letter-spacing:.9px!important}
  #apiPage .api-video-gold-btn,#apiPage .api-video-dark-btn{font-size:10px!important}
  #apiPage .api-video-plan-row{grid-template-columns:44px minmax(0,1fr) auto!important}
  #apiPage .api-video-plan-info b{font-size:10px!important}
  #apiPage .api-video-plan-info span{font-size:7.5px!important}
  #apiPage .api-video-code pre,#apiDocsPage .api-code-block pre{font-size:8px!important}
}
</style>
<style id="purchase-result-ui">
/* PURCHASE RESULT — compact mobile broadcast-style key delivery panel. */
.purchase-result-modal{position:fixed;inset:0;z-index:9500;display:flex;align-items:flex-start;justify-content:center;padding:78px 12px 24px;box-sizing:border-box;background:rgba(2,6,16,.78);backdrop-filter:blur(5px);overflow:auto}
.purchase-result-card{width:min(100%,520px);box-sizing:border-box;background:linear-gradient(180deg,#16233b 0%,#101a2d 100%);border:1px solid rgba(212,175,55,.42);border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.45);overflow:hidden}
.purchase-result-topline{height:3px;background:#d9b43a}
.purchase-result-head{display:flex;align-items:center;gap:10px;padding:14px 14px 11px}
.purchase-result-icon{width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:50%;background:rgba(212,175,55,.13);border:1px solid rgba(212,175,55,.42);color:#dfbd45;font-weight:900;font-size:16px;flex:0 0 30px}
.purchase-result-kicker{color:#d9b43a;font:800 9px/1.2 ui-monospace,monospace;letter-spacing:1.5px}
.purchase-result-title{color:#f4f1e9;font-size:17px;font-weight:900;margin-top:3px}
.purchase-result-divider{height:1px;margin:0 14px;background:rgba(212,175,55,.28)}
.purchase-result-info{padding:10px 14px 4px;color:#9ca9bf;font-size:10px;line-height:1.5;overflow-wrap:anywhere}
.purchase-result-info b{color:#eee8dd;font-weight:800}
.purchase-result-keys-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 14px 7px;color:#d9b43a;font:800 10px/1.2 ui-monospace,monospace;letter-spacing:1px}
.purchase-copy-all{border:1px solid rgba(212,175,55,.38);background:#0a1120;color:#dfbd45;border-radius:7px;padding:6px 8px;font-size:9px;font-weight:800;white-space:nowrap}
.purchase-result-keys{padding:0 14px;display:flex;flex-direction:column;gap:7px}
.purchase-result-key-row{display:flex;align-items:center;gap:7px;min-width:0;padding:9px 9px;background:#080e1c;border:1px solid rgba(212,175,55,.18);border-radius:8px;box-sizing:border-box}
.purchase-result-key-value{min-width:0;flex:1;color:#e7c74e;font:600 11px/1.45 ui-monospace,monospace;overflow-wrap:anywhere;word-break:break-word}
.purchase-result-key-copy{flex:0 0 auto;border:1px solid rgba(212,175,55,.32);background:#25231e;color:#dfbd45;border-radius:7px;padding:6px 8px;font-size:9px;font-weight:800}
.purchase-result-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px 14px 14px}
.purchase-result-close,.purchase-result-mykeys{height:40px;border-radius:8px;font-size:10px;font-weight:900;letter-spacing:.5px}
.purchase-result-close{background:#080e1c;color:#aeb8cc;border:1px solid rgba(148,163,184,.24)}
.purchase-result-mykeys{background:linear-gradient(180deg,#e2b938,#c99d25);color:#fff;border:0}
@media(max-width:380px){.purchase-result-modal{padding:70px 8px 18px}.purchase-result-card{border-radius:11px}.purchase-result-head{padding:12px}.purchase-result-divider{margin:0 12px}.purchase-result-info{padding-left:12px;padding-right:12px}.purchase-result-keys-head{padding-left:12px;padding-right:12px}.purchase-result-keys{padding:0 12px}.purchase-result-actions{padding:10px 12px 12px}.purchase-result-key-value{font-size:10px}.purchase-result-key-copy{padding:5px 7px;font-size:8px}}
</style>

<style id="purchase-result-professional-center">
.purchase-result-modal{align-items:center!important;justify-content:center!important;padding:16px!important;background:rgba(2,6,16,.86)!important;backdrop-filter:blur(9px)!important}
.purchase-result-card{width:min(94vw,500px)!important;max-height:min(86vh,720px)!important;border-radius:18px!important;border:1px solid rgba(245,215,110,.42)!important;box-shadow:0 30px 90px rgba(0,0,0,.62),0 0 45px rgba(212,175,55,.08)!important}
.purchase-result-topline{height:4px!important;background:linear-gradient(90deg,transparent,#f5d76e,#d4af37,transparent)!important}
.purchase-result-head{padding:18px 18px 14px!important;justify-content:center!important;text-align:center!important;flex-direction:column!important;gap:8px!important}
.purchase-result-icon{width:42px!important;height:42px!important;flex-basis:42px!important;font-size:21px!important;box-shadow:0 0 24px rgba(245,215,110,.13)!important}
.purchase-result-kicker{font-size:9px!important;letter-spacing:2px!important}
.purchase-result-title{font-size:20px!important;letter-spacing:.4px!important}
.purchase-result-divider{margin:0 18px!important}
.purchase-result-info{text-align:center!important;padding:12px 18px 5px!important}
.purchase-result-keys-head{padding:12px 18px 8px!important}
.purchase-result-keys{padding:0 18px!important;max-height:42vh!important;overflow:auto!important}
.purchase-result-actions{padding:14px 18px 18px!important}
@media(max-width:380px){.purchase-result-modal{padding:10px!important}.purchase-result-card{width:96vw!important;max-height:90vh!important}.purchase-result-head{padding:14px!important}.purchase-result-title{font-size:18px!important}.purchase-result-keys{padding:0 14px!important}.purchase-result-actions{padding:11px 14px 14px!important}}
</style>
<style id="api-premium-polish-v5">
/* API PAGE — premium dashboard-style polish; functionality unchanged. */
#apiPage .api-video-wrap{
  max-width:820px!important;
  padding:8px 12px 92px!important;
}
#apiPage .api-wallet-video{
  position:relative;
  background:linear-gradient(135deg,#1d2941,#121b2d)!important;
  border-color:rgba(212,175,55,.34)!important;
  box-shadow:0 10px 28px rgba(0,0,0,.20),inset 0 1px 0 rgba(255,255,255,.035)!important;
}
#apiPage .api-video-back{margin:0 0 10px!important;padding:2px 0 8px!important;}
#apiPage .api-video-card{
  border-radius:14px!important;
  background:linear-gradient(180deg,#192640 0%,#141f35 100%)!important;
  box-shadow:0 12px 32px rgba(0,0,0,.18),inset 0 1px 0 rgba(255,255,255,.025)!important;
}
#apiPage .api-key-video-card{
  border-color:rgba(212,175,55,.34)!important;
  box-shadow:0 16px 40px rgba(0,0,0,.24),0 0 28px rgba(212,175,55,.045)!important;
}
#apiPage .api-site-url-label{margin-top:14px!important;}
#apiPage .api-site-url-box{border-color:rgba(212,175,55,.28)!important;background:#090f1d!important;}
#apiPage .api-site-url-box code{color:#f0d66d!important;}
#apiPage .api-video-title{margin-bottom:15px!important;}
#apiPage .api-video-dark-btn,#apiPage .api-video-gold-btn{border-radius:9px!important;}
#apiPage .api-variants-video-card,#apiPage .api-product-link-card,#apiPage .api-doc-video-card{
  border-color:rgba(212,175,55,.24)!important;
}
#apiPage .api-variants-video-card{padding-bottom:20px!important;}
#apiPage .api-product-link-card p{margin-top:-2px!important;}
@media(max-width:600px){
  #apiPage .api-video-wrap{padding:5px 0 88px!important;}
  #apiPage .api-video-card{border-radius:12px!important;}
  #apiPage .api-site-url-label{margin-top:13px!important;}
}
</style>
<style id="final-top-layout-cleanup">
/* FINAL LAST-WORD UI OVERRIDE: sections start directly below the site header. */
html,body{margin:0!important;padding:0!important;scroll-padding-top:0!important;background:#050913!important;overflow-x:hidden!important}
#app,#app.visible{margin:0!important;padding:0!important;min-height:100vh!important}
#app .app-shell{margin:0 auto!important;padding-top:0!important;box-sizing:border-box!important}
#app .top-header{margin-top:0!important;margin-bottom:0!important}
#app .page,#app .page.active,#storePage,#depositPage,#keyPage,#apiPage,#apiProductsPage,#apiDocsPage{margin:0!important;padding-top:0!important;padding-block-start:0!important;position:relative!important;top:0!important;transform:none!important;min-height:0!important;height:auto!important;flex:none!important;align-content:flex-start!important}
#app .page.active>:first-child,#storePage>:first-child,#depositPage>:first-child,#keyPage>:first-child,#apiPage>:first-child,#apiProductsPage>:first-child,#apiDocsPage>:first-child{margin-top:0!important;padding-top:0!important;padding-block-start:0!important}
#homePage,#storePage,#depositPage,#keyPage,#apiPage,#apiProductsPage,#apiDocsPage{padding-top:0!important;padding-block-start:0!important}
#storePage .store-buy-panel,#depositPage .deposit-wrap,#keyPage .center-panel,#apiPage .api-video-wrap,#apiProductsPage .api-docs-wrap,#apiDocsPage .api-docs-wrap{margin-top:0!important}
#apiPage .api-video-wrap,#apiDocsPage .api-docs-wrap,#apiProductsPage .api-docs-wrap,#depositPage .deposit-wrap,#keyPage .center-panel{padding-top:0!important}
/* Keep the dark navy base but remove obvious blue/purple accent borders. */
:root{--kp-purple:#d9b43a!important}
.update-banner{border-color:rgba(212,175,55,.42)!important;box-shadow:0 0 22px rgba(212,175,55,.07),inset 0 0 24px rgba(212,175,55,.035)!important}
.join-btn{background:rgba(212,175,55,.08)!important;border-color:rgba(212,175,55,.42)!important;color:#e6c653!important}
.api-video-card,.api-card{border-color:rgba(212,175,55,.22)!important}
.api-video-keybox button,.api-video-dark-btn,.api-video-copy-id,.api-card .api-code-row button,.api-tab{border-color:rgba(212,175,55,.24)!important}
.deposit-quick button{border-color:rgba(212,175,55,.20)!important;background:#111a2a!important}
.mykey-source-api,.mykey-source-web{border-color:rgba(212,175,55,.28)!important;color:#cbb66a!important}
.paytm-qr-card{background:linear-gradient(180deg,#111925 0%,#0b111d 100%)!important}
</style>
<style id="final-user-section-gap-fix-v2">
/* USER SECTION GAP FIX: keep Store, Deposit, My Keys and API content directly
   under the site header. The bottom navigation remains fixed at the bottom. */
#app .page.active{margin-top:0!important;padding-top:0!important;}
#app .page.active > :first-child{margin-top:0!important;padding-top:0!important;}
#storePage,#depositPage,#keyPage,#apiPage,#apiProductsPage,#apiDocsPage{
  margin-top:0!important;
  padding-top:0!important;
  padding-block-start:0!important;
}
#storePage .store-buy-panel,#depositPage .deposit-wrap,#keyPage .center-panel,
#apiPage .api-video-wrap,#apiProductsPage .api-docs-wrap,#apiDocsPage .api-docs-wrap{
  margin-top:0!important;
  padding-top:0!important;
}
@media(max-width:600px){
  #storePage,#depositPage,#keyPage,#apiPage,#apiProductsPage,#apiDocsPage{
    margin-top:0!important;
    padding-top:0!important;
  }
}
</style>
<script>
(function(){
  window.__resetPanelScroll=function(){
    try{window.scrollTo(0,0)}catch(e){}
    try{document.documentElement.scrollTop=0}catch(e){}
    try{document.body.scrollTop=0}catch(e){}
    try{if(document.scrollingElement)document.scrollingElement.scrollTop=0}catch(e){}
  };
  document.addEventListener('click',function(e){
    var b=e.target.closest&&e.target.closest('.bottom-nav .nav-btn');
    if(b) requestAnimationFrame(function(){window.__resetPanelScroll()});
  },true);
})();
</script>

<style id="anti-copy-protection">
/* Anti-copy / anti-selection protection for public UI.
   Editable controls remain selectable so normal forms are not broken. */
html, body, #app, #app * {
    -webkit-user-select: none !important;
    user-select: none !important;
    -webkit-touch-callout: none !important;
}
input, textarea, select, option, [contenteditable="true"], [contenteditable="true"] * {
    -webkit-user-select: text !important;
    user-select: text !important;
    -webkit-touch-callout: default !important;
}
img, svg {
    -webkit-user-drag: none !important;
    user-drag: none !important;
}
</style>
<script id="anti-copy-protection-js">
(function () {
    'use strict';

    function isEditable(el) {
        if (!el || !el.closest) return false;
        return !!el.closest('input, textarea, select, option, [contenteditable="true"]');
    }

    /* Block browser copy/cut/context-menu/drag actions on page content.
       Explicit form editing remains usable. */
    ['copy', 'cut', 'contextmenu', 'dragstart'].forEach(function (eventName) {
        document.addEventListener(eventName, function (event) {
            if (!isEditable(event.target)) {
                event.preventDefault();
                event.stopPropagation();
            }
        }, true);
    });

    /* Prevent common source/inspection/save/print shortcuts outside editable fields.
       This is only a deterrent; it is not a substitute for server-side security. */
    document.addEventListener('keydown', function (event) {
        if (isEditable(event.target)) return;

        var key = String(event.key || '').toLowerCase();
        var ctrlOrMeta = event.ctrlKey || event.metaKey;

        var blocked =
            key === 'f12' ||
            (ctrlOrMeta && key === 'u') ||
            (ctrlOrMeta && key === 's') ||
            (ctrlOrMeta && key === 'p') ||
            (ctrlOrMeta && event.shiftKey && ['i', 'j', 'c'].indexOf(key) !== -1) ||
            (ctrlOrMeta && key === 'c') ||
            (ctrlOrMeta && key === 'x') ||
            (ctrlOrMeta && key === 'a');

        if (blocked) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);

    /* Keep the protection applied if UI sections are replaced dynamically. */
    document.addEventListener('selectstart', function (event) {
        if (!isEditable(event.target)) {
            event.preventDefault();
        }
    }, true);
})();
</script>
</body>
</html>
<style id="admin-product-user-search-and-mode-fix">
#adminUserSearch{width:100%;box-sizing:border-box;background:#0b0f19;border:1px solid rgba(212,175,55,.28);color:#fff;border-radius:10px;padding:10px 12px;outline:none;}
#adminUserSearch:focus{border-color:rgba(245,215,110,.72);box-shadow:0 0 0 3px rgba(212,175,55,.08);}
.create-plan-provider-wrap input,#adminPlanProviderWrap input{width:100%;box-sizing:border-box;}
.create-plan-keys-wrap textarea,#adminPlanKeys{width:100%;box-sizing:border-box;min-height:100px;background:#0b0f19;border:1px solid rgba(212,175,55,.25);border-radius:10px;color:#fff;padding:10px;font-family:monospace;font-size:12px;resize:vertical;}
</style>
<script>
try{toggleAddPlanMode();}catch(e){}
</script>
