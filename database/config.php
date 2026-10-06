<?php
/**
 * =====================================================================
 * SMART GATEWAY - Environment-Aware Configuration
 * =====================================================================
 * This single file works unchanged across all deployment targets:
 *   - Your laptop (XAMPP, http://localhost/...)
 *   - A dedicated LAN server PC (accessed via http://192.168.x.x/...)
 *   - A live shared-hosting or VPS deployment (https://yourdomain.com)
 *
 * HOW IT WORKS
 * ------------
 * 1. DATABASE CREDENTIALS: auto-selected based on the hostname the
 *    request came in on. Any machine YOU control directly (localhost,
 *    127.0.0.1, or any private LAN IP/hostname) is treated as the
 *    "local" environment and uses the local XAMPP MySQL credentials.
 *    Anything else (your real domain) is treated as "production" and
 *    uses the credentials you fill in under PRODUCTION CREDENTIALS
 *    below — fill those in ONCE when you deploy, and never touch this
 *    file again when moving between your laptop and the live server.
 *
 * 2. APP_URL, PROTOCOL, BASE PATH: computed automatically from the
 *    current request and this file's location on disk — no manual
 *    editing needed even if you deploy into a subfolder.
 *
 * 3. SESSION SECURITY: cookie flags (Secure/SameSite) are tightened
 *    automatically whenever the site is served over HTTPS.
 * =====================================================================
 */

// ---- Detect the current host (CLI-safe fallback for cron/scripts) ----
$sgHost = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$sgHostOnly = explode(':', $sgHost)[0]; // strip port, if any

date_default_timezone_set('Asia/Manila');
// ---- Decide if this request is running on a machine WE control locally ----
$sgIsLocalEnv = (
    $sgHostOnly === 'localhost' ||
    $sgHostOnly === '127.0.0.1' ||
    $sgHostOnly === '::1' ||
    str_ends_with($sgHostOnly, '.local') ||
    preg_match('/^192\.168\.\d{1,3}\.\d{1,3}$/', $sgHostOnly) ||
    preg_match('/^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $sgHostOnly) ||
    preg_match('/^172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}$/', $sgHostOnly) ||
    PHP_SAPI === 'cli'
);

// =====================================================================
// LOCAL CREDENTIALS (XAMPP default — used on your laptop / LAN server)
// =====================================================================
$sgLocalDb = [
    'host'    => 'localhost',
    'name'    => 'smart_gateway_v1_8',
    'user'    => 'root',
    'pass'    => '',              // XAMPP default MySQL password is empty
];

// =====================================================================
// PRODUCTION CREDENTIALS — fill these in ONCE when you deploy live.
// (e.g. shared hosting cPanel MySQL Databases page, or your VPS DB)
// =====================================================================
$sgProductionDb = [
    'host'    => 'localhost',                  // usually still 'localhost' even on shared hosting
    'name'    => 'u483372788_Smart_gateway',          // e.g. u123456_smart_gateway
    'user'    => 'u483372788_smartgateway',          // e.g. u123456_sguser
    'pass'    => 'Janelyn@18',
];

$sgDbConfig = $sgIsLocalEnv ? $sgLocalDb : $sgProductionDb;

define('APP_ENV', $sgIsLocalEnv ? 'local' : 'production');
define('DB_HOST', $sgDbConfig['host']);
define('DB_NAME', $sgDbConfig['name']);
define('DB_USER', $sgDbConfig['user']);
define('DB_PASS', $sgDbConfig['pass']);
define('DB_CHARSET', 'utf8mb4');

// ---- Detect protocol (HTTPS-aware, including behind reverse proxies) ----
$sgIsHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    ($_SERVER['SERVER_PORT'] ?? '') == 443 ||
    (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);
define('IS_HTTPS', $sgIsHttps);
$sgProtocol = $sgIsHttps ? 'https' : 'http';

// ---- Compute the app's base URL from its actual location on disk ----
// This works no matter which page includes config.php, and no matter
// whether the project lives at the domain root or in a subfolder.
$sgDocumentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$sgProjectRoot  = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/'); // parent of /database
$sgBasePath = ($sgDocumentRoot !== '' && str_starts_with($sgProjectRoot, $sgDocumentRoot))
    ? substr($sgProjectRoot, strlen($sgDocumentRoot))
    : '';

define('APP_URL', PHP_SAPI === 'cli'
    ? 'http://localhost'  // placeholder for CLI/cron contexts; not used for redirects there
    : $sgProtocol . '://' . $sgHost . $sgBasePath);

// ---- Session hardening (must be set BEFORE session_start()) ----
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $sgIsHttps,   // cookies only sent over HTTPS in production
        'httponly' => true,         // not accessible to JavaScript
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---- Baseline security headers (defense-in-depth; safe no-op on CLI) ----
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ---- Error visibility: verbose locally, silent (logged only) in production ----
if (APP_ENV === 'local') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// ---- Establish the PDO connection ----
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements -> SQL injection protection
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Never leak raw DB credentials/errors to the client
    error_log('DB Connection Error: ' . $e->getMessage());
    if (APP_ENV === 'local') {
        die('Database connection failed: ' . $e->getMessage() . ' — check the $sgLocalDb credentials in database/config.php.');
    }
    die('Database connection failed. Please check your configuration or contact the system administrator.');
}

// ---- CSRF token generator/helper ----
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $token);
}

// ---- Simple XSS-safe output helper ----
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ---- Auth guard: require an active session (also enforces idle session timeout) ----
function require_login() {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }

    global $pdo;
    $timeoutMinutes = (int) get_setting($pdo, 'session_timeout_minutes', 30);
    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > ($timeoutMinutes * 60)) {
        $username = $_SESSION['username'] ?? null;
        $_SESSION = [];
        session_destroy();
        if (function_exists('log_activity')) {
            // Best-effort log; session is already gone so pass username explicitly
            log_activity($pdo, 'Session Expired', 'Auto-logout after ' . $timeoutMinutes . ' minutes of inactivity', $username);
        }
        header('Location: ' . APP_URL . '/login.php?notice=session_expired');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// ---- Resolve the correct dashboard URL for a given role ----
function dashboard_url_for_role(?string $role): string {
    return ($role === 'Administrator')
        ? APP_URL . '/admin/dashboard.php'
        : APP_URL . '/admin/staff_dashboard.php';
}

// ---- Role guard: restrict a page to Administrator only ----
function require_admin() {
    require_login();
    if (($_SESSION['role'] ?? '') !== 'Administrator') {
        // Send non-admins to THEIR dashboard, never back to the admin-only page
        // they were just denied — that would otherwise create a redirect loop.
        header('Location: ' . dashboard_url_for_role($_SESSION['role'] ?? null) . '?error=access_denied');
        exit;
    }
}

// ---- Role guard: restrict a page to Staff only (Administrators bounce to their own dashboard) ----
function require_staff() {
    require_login();
    if (($_SESSION['role'] ?? '') !== 'Staff') {
        header('Location: ' . dashboard_url_for_role($_SESSION['role'] ?? null));
        exit;
    }
}

// ---- Fetch a setting value by key (with fallback default) ----
function get_setting(PDO $pdo, string $key, $default = null) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

// ---- Load Phase 1 security & audit library (rate limiting, lockout, CAPTCHA, OTP, audit logs) ----
require_once __DIR__ . '/../includes/security.php';
