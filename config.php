<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

// ---- Environment (override with ZB_ENV / ZB_DB_* variables) ----
function env_or(string $key, string $default): string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? '') === '443'
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
// The base URL is built from the request host. In production, whitelist the
// allowed hosts via ZB_ALLOWED_HOSTS (comma-separated) so a poisoned
// Host/forwarded header can never be turned into a phishing link (open
// redirect via the generated BASE_URL).
$allowedHosts = array_values(array_filter(array_map('trim', explode(',', env_or('ZB_ALLOWED_HOSTS', '')))));
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if ($allowedHosts && !in_array($host, $allowedHosts, true)) {
    $host = $allowedHosts[0];
}
$baseDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

// ---- Hardened session cookie ----
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

// ---- Error display: full locally, hidden in production ----
if (env_or('ZB_ENV', 'development') === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
} else {
    ini_set('display_errors', '1');
}

define('BASE_URL', ($isHttps ? 'https://' : 'http://') . $host . ($baseDir === '' ? '/' : $baseDir . '/'));
define('ROOT_PATH', __DIR__);

// ---- Database settings (env overridable for deployment) ----
define('DB_HOST', env_or('ZB_DB_HOST', 'localhost'));
define('DB_NAME', env_or('ZB_DB_NAME', 'zoeys_billiard'));
define('DB_USER', env_or('ZB_DB_USER', 'root'));
define('DB_PASS', env_or('ZB_DB_PASS', ''));

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Self-triggering "lazy cron" for the automatic daily transaction email
// report. The check itself is cheap; the build+send runs at most once a day
// (guarded by an atomic claim inside maybe_send_daily_report). Only fires on
// GET requests so critical POST flows (checkout, save) are never delayed.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    ignore_user_abort(true);
    set_time_limit(90);
    maybe_send_daily_report();
}