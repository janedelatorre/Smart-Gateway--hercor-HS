<?php
/**
 * =====================================================================
 * SMART GATEWAY — SECURITY & AUDIT LIBRARY (Phase 1 Foundation)
 * =====================================================================
 * Central helper functions used across the app for:
 *   - Audit logging (who did what, when, from where)
 *   - Login rate limiting / account lockout / CAPTCHA-after-N-failures
 *   - Password policy enforcement + reuse prevention
 *   - OTP generation/verification for the Forgot Password flow
 *   - In-app notification center helpers
 *
 * This file assumes database/config.php has already been included
 * (needs $pdo, session already started).
 * =====================================================================
 */

// ---------------------------------------------------------------------
// AUDIT LOGGING
// ---------------------------------------------------------------------

/**
 * Record an entry in the audit trail. Captures IP + user agent automatically.
 * $username defaults to the currently logged-in user if not provided.
 */
function log_activity(PDO $pdo, string $action, ?string $description = null, ?string $username = null): void {
    $username = $username ?? ($_SESSION['username'] ?? null);
    $ip = get_client_ip();
    $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

    // PHASE 6: bind created_at as a PHP-computed value (Asia/Manila, see
    // date_default_timezone_set() in database/config.php) rather than
    // leaving it to the column's DEFAULT CURRENT_TIMESTAMP. That default is
    // the DATABASE SERVER's own clock/timezone, which is not guaranteed to
    // be Asia/Manila on the real host (the earlier Phase 4.5 OTP/lockout
    // bug was this exact mismatch) -- and unlike next_retry_at, nothing
    // compares this value against NOW() for scheduling, so there's no
    // self-consistency requirement pulling the other way: it is purely a
    // human-displayed "when did this happen" timestamp (dashboard System
    // Activity, Audit Logs page), so it should read the intended PH wall
    // clock regardless of the DB server's own timezone.
    $stmt = $pdo->prepare("INSERT INTO audit_logs (username, action, description, ip_address, user_agent, created_at) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$username, $action, $description, $ip, $agent, date('Y-m-d H:i:s')]);
}

function get_client_ip(): string {
    // Respect common reverse-proxy headers (X-Forwarded-For) before falling back to REMOTE_ADDR
    $candidates = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ---------------------------------------------------------------------
// LOGIN RATE LIMITING / ACCOUNT LOCKOUT / CAPTCHA
// ---------------------------------------------------------------------

/**
 * Record a login attempt (success or failure) for rate-limiting purposes.
 */
function record_login_attempt(PDO $pdo, string $username, bool $success): void {
    $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?,?,?)");
    $stmt->execute([$username, get_client_ip(), $success ? 1 : 0]);
}

/**
 * Count recent failed attempts for a username within the lockout window (last 30 minutes).
 */
function count_recent_failed_attempts(PDO $pdo, string $username): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at >= (NOW() - INTERVAL 30 MINUTE)");
    $stmt->execute([$username]);
    return (int) $stmt->fetchColumn();
}

/**
 * Check if an account is currently locked. Returns remaining seconds if locked, or 0 if not.
 */
function get_lockout_seconds_remaining(PDO $pdo, string $username): int {
    $stmt = $pdo->prepare("SELECT locked_until FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $lockedUntil = $stmt->fetchColumn();
    if (!$lockedUntil) return 0;
    $remaining = strtotime($lockedUntil) - time();
    return max(0, $remaining);
}

/**
 * Increment failed_attempts for a user; lock the account if the configured threshold is exceeded.
 * Returns true if this failure just triggered a lockout.
 */
function register_failed_login(PDO $pdo, string $username): bool {
    $maxAttempts = (int) get_setting($pdo, 'max_login_attempts', 5);
    $lockoutMinutes = (int) get_setting($pdo, 'lockout_duration_minutes', 15);

    $stmt = $pdo->prepare("UPDATE users SET failed_attempts = failed_attempts + 1 WHERE username = ?");
    $stmt->execute([$username]);

    $stmt = $pdo->prepare("SELECT failed_attempts FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $attempts = (int) $stmt->fetchColumn();

    if ($attempts >= $maxAttempts) {
        // PHP-computed value, not SQL NOW()+INTERVAL — mirrors the convention
        // already established in api/scan.php: keeps this consistent with the
        // app's configured timezone (Asia/Manila) regardless of the database
        // server's own timezone. Mixing MySQL NOW() with PHP's strtotime()/
        // time() here previously made locked_until compare as already-expired
        // whenever the DB server's clock timezone differs from PHP's, which
        // silently disabled account lockout entirely — see get_lockout_seconds_remaining().
        $lockedUntil = date('Y-m-d H:i:s', time() + $lockoutMinutes * 60);
        $stmt = $pdo->prepare("UPDATE users SET locked_until = ? WHERE username = ?");
        $stmt->execute([$lockedUntil, $username]);
        log_activity($pdo, 'Account Locked', "Locked for {$lockoutMinutes} minutes after {$attempts} failed attempts", $username);
        return true;
    }
    return false;
}

/**
 * Reset failed_attempts/lockout on a successful login.
 */
function clear_failed_logins(PDO $pdo, string $username): void {
    $stmt = $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE username = ?");
    $stmt->execute([$username]);
}

/**
 * Should a CAPTCHA challenge be shown for this username right now?
 */
function should_show_captcha(PDO $pdo, string $username): bool {
    if ($username === '') return false;
    $threshold = (int) get_setting($pdo, 'captcha_after_attempts', 3);
    return count_recent_failed_attempts($pdo, $username) >= $threshold;
}

/**
 * Generate a simple self-hosted math CAPTCHA (no external API/keys needed —
 * works on localhost/XAMPP as well as any live deployment).
 * Stores the answer in the session; returns the question text to display.
 */
function generate_captcha(): string {
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    $_SESSION['captcha_answer'] = $a + $b;
    return "What is {$a} + {$b}?";
}

function verify_captcha(string $input): bool {
    $expected = $_SESSION['captcha_answer'] ?? null;
    $ok = $expected !== null && (int) trim($input) === (int) $expected;
    unset($_SESSION['captcha_answer']); // one-time use
    return $ok;
}

// ---------------------------------------------------------------------
// PASSWORD POLICY & HISTORY
// ---------------------------------------------------------------------

/** A small blocklist of common/weak passwords rejected outright. */
const SG_COMMON_PASSWORDS = [
    'password', 'password123', '12345678', '123456789', 'qwerty123',
    'admin123', 'letmein123', 'welcome123', 'iloveyou123', 'password1234',
];

/**
 * Validate password strength against policy: minimum 6 characters only.
 * Returns an array of violation messages (empty array = passes all rules).
 */
function validate_password_strength(string $password): array {
    $errors = [];
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters long.';
    return $errors;
}

/**
 * Stronger policy for SELF-SERVICE password reset/change (the Change
 * Password page and the Forgot Password OTP reset flow) — both pages'
 * client-side minlength="12" already signaled this pre-existing, stronger
 * requirement. validate_password_strength() above is only the general
 * account-creation baseline (Administrator creating a Staff/Admin account,
 * see api/user.php) and must never be used here, or this stronger
 * requirement would silently be downgraded to 6 characters.
 */
function validate_password_reset_strength(string $password): array {
    $errors = [];
    if (strlen($password) < 12) $errors[] = 'Password must be at least 12 characters long.';
    if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must include at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $password)) $errors[] = 'Password must include at least one lowercase letter.';
    if (!preg_match('/[0-9]/', $password)) $errors[] = 'Password must include at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = 'Password must include at least one special character.';
    return $errors;
}

/**
 * Check whether $plainPassword matches any of the user's last N stored password hashes.
 */
function is_password_reused(PDO $pdo, int $userId, string $plainPassword): bool {
    $limit = (int) get_setting($pdo, 'password_history_limit', 5);
    $stmt = $pdo->prepare("SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $oldHash) {
        if (password_verify($plainPassword, $oldHash)) return true;
    }
    // Also check current password (not yet in history at time of first change)
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $current = $stmt->fetchColumn();
    if ($current && password_verify($plainPassword, $current)) return true;

    return false;
}

/**
 * Hash a new password with Argon2id (falls back to bcrypt automatically if the
 * PHP build lacks Argon2 support) and archive the PREVIOUS hash into password_history.
 */
function set_user_password(PDO $pdo, int $userId, string $plainPassword): void {
    // Archive current password before overwriting it
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $currentHash = $stmt->fetchColumn();
    if ($currentHash) {
        $pdo->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?,?)")->execute([$userId, $currentHash]);
    }

    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    $newHash = password_hash($plainPassword, $algo);
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $userId]);
}

// ---------------------------------------------------------------------
// OTP (Forgot Password, delivered via SMS using the existing SMS module)
// ---------------------------------------------------------------------

/**
 * Generate a 6-digit OTP, store its hash (never store the plain code), and return
 * the PLAIN code so the caller can send it via SMS immediately.
 */
function create_otp(PDO $pdo, int $userId, string $purpose = 'password_reset', int $expiresInMinutes = 10): string {
    // Invalidate any previous unused OTPs of the same purpose for this user
    $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE user_id = ? AND purpose = ? AND used = 0")->execute([$userId, $purpose]);

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    // PHP-computed expiry, not SQL NOW()+INTERVAL — same fix/reasoning as
    // register_failed_login()'s locked_until above: avoids expires_at being
    // read back via strtotime() as already-expired when the DB server's own
    // timezone differs from PHP's configured Asia/Manila, which previously
    // broke OTP verification (every code appeared pre-expired).
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresInMinutes * 60);
    $stmt = $pdo->prepare("INSERT INTO otp_codes (user_id, code_hash, purpose, expires_at) VALUES (?,?,?,?)");
    $stmt->execute([$userId, $hash, $purpose, $expiresAt]);

    return $code;
}

/**
 * Verify a submitted OTP code for a user. On success, marks it used (one-time only).
 */
function verify_otp(PDO $pdo, int $userId, string $inputCode, string $purpose = 'password_reset'): bool {
    $stmt = $pdo->prepare("SELECT id, code_hash, expires_at FROM otp_codes
                            WHERE user_id = ? AND purpose = ? AND used = 0
                            ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$userId, $purpose]);
    $row = $stmt->fetch();

    if (!$row) return false;
    if (strtotime($row['expires_at']) < time()) return false;
    if (!password_verify($inputCode, $row['code_hash'])) return false;

    $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE id = ?")->execute([$row['id']]);
    return true;
}

// ---------------------------------------------------------------------
// NOTIFICATION CENTER (helper now; UI built out in a later phase)
// ---------------------------------------------------------------------

/**
 * Create an in-app notification. $userId = null broadcasts to all users.
 */
function create_notification(PDO $pdo, ?int $userId, string $type, string $title, ?string $message = null): void {
    // PHASE 6: same PHP-computed-timestamp fix as log_activity() above, for
    // the same reason -- this created_at is only ever displayed (the
    // notification bell), never compared for scheduling.
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?,?,?,?,?)");
    $stmt->execute([$userId, $type, $title, $message, date('Y-m-d H:i:s')]);
}

/**
 * Fetch the most recent audit log entries (used by the Dashboard's
 * "System Activity" widget for live data instead of placeholders).
 */
function get_recent_audit_logs(PDO $pdo, int $limit = 5): array {
    $stmt = $pdo->prepare("SELECT username, action, description, created_at FROM audit_logs ORDER BY created_at DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Shared handler for a user changing their OWN password (used by both
 * My Profile and the dedicated Change Password page, which previously
 * duplicated this exact validation/update flow).
 * Reads current_password/new_password/confirm_password/csrf_token from
 * $_POST. Returns ready-to-echo alert HTML (success or error), or '' if
 * this wasn't a matching POST submission.
 */
function handle_self_password_change(PDO $pdo, array $user, string $sourceLabel, string $successMessage): string {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        return '<div class="alert alert-danger">Invalid session token.</div>';
    }

    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!password_verify($current, $user['password'])) {
        return '<div class="alert alert-danger">Current password is incorrect.</div>';
    }

    $policyErrors = validate_password_reset_strength($new);
    if ($policyErrors) {
        return '<div class="alert alert-danger"><strong>Password does not meet requirements:</strong><ul class="mb-0">' .
            implode('', array_map(fn($e) => '<li>' . e($e) . '</li>', $policyErrors)) . '</ul></div>';
    }
    if ($new !== $confirm) {
        return '<div class="alert alert-danger">New password and confirmation do not match.</div>';
    }
    if (is_password_reused($pdo, (int) $user['id'], $new)) {
        return '<div class="alert alert-danger">You cannot reuse a recent password. Please choose a different one.</div>';
    }

    set_user_password($pdo, (int) $user['id'], $new);
    log_activity($pdo, 'Password Change', "User changed their own password via {$sourceLabel}");
    return '<div class="alert alert-success">' . e($successMessage) . '</div>';
}

// ---------------------------------------------------------------------
// BARCODE FAILURE STREAK -> FACIAL RECOGNITION FALLBACK GATE
// ---------------------------------------------------------------------
// Tracks consecutive "barcode not recognized" results at this scanner
// station since the last successful verification (barcode OR face).
// Stored server-side in the session (never trust a client-sent count),
// so api/face.php can independently refuse to run facial matching until
// the threshold is actually met — a hidden/disabled button on the
// frontend is UX only, not the security control.

const SG_FACIAL_FALLBACK_THRESHOLD = 3; // fallback default if the 'facial_fallback_threshold' setting is missing/invalid

/** Record one more consecutive "barcode not recognized" result. Returns the new streak count. */
function register_barcode_failure(): int {
    $_SESSION['barcode_fail_streak'] = ($_SESSION['barcode_fail_streak'] ?? 0) + 1;
    return (int) $_SESSION['barcode_fail_streak'];
}

/** Reset the streak — called after ANY successful verification (barcode or face). */
function reset_barcode_fail_streak(): void {
    $_SESSION['barcode_fail_streak'] = 0;
    // The facial-attempt counter belongs to ONE unlocked fallback window, so it
    // always starts again from 0 whenever the barcode streak is reset (success,
    // or facial lockout -- see api/face.php).
    unset($_SESSION['face_fail_count'], $_SESSION['face_last_attempt_at']);
}

// ---------------------------------------------------------------------
// FACIAL VERIFICATION ATTEMPT LIMIT (per unlocked fallback window)
// ---------------------------------------------------------------------
// Only a face that was DETECTED and actually compared against the registered
// facial data, and did not match, is counted (api/face.php 'no_match').
// "No face in frame" never reaches the server, so it can never be counted.
const SG_FACE_MAX_ATTEMPTS = 3;               // failed facial verifications before facial fallback locks again
const SG_FACE_MIN_ATTEMPT_INTERVAL = 2.0;     // seconds -- server-side debounce between two facial attempts

function get_face_fail_count(): int {
    return (int) ($_SESSION['face_fail_count'] ?? 0);
}

/** Record one actual facial verification failure. Returns the new count. */
function register_face_failure(): int {
    $_SESSION['face_fail_count'] = get_face_fail_count() + 1;
    return (int) $_SESSION['face_fail_count'];
}

function get_barcode_fail_streak(): int {
    return (int) ($_SESSION['barcode_fail_streak'] ?? 0);
}

/** Administrator-configurable number of consecutive barcode misses required to unlock facial fallback (Settings → Scanner & Access). */
function get_facial_fallback_threshold(PDO $pdo): int {
    $val = (int) get_setting($pdo, 'facial_fallback_threshold', SG_FACIAL_FALLBACK_THRESHOLD);
    return $val > 0 ? $val : SG_FACIAL_FALLBACK_THRESHOLD;
}

/** Has this station accumulated enough consecutive barcode misses to unlock facial fallback? */
function is_facial_fallback_unlocked(PDO $pdo): bool {
    return get_barcode_fail_streak() >= get_facial_fallback_threshold($pdo);
}

// ---------------------------------------------------------------------
// ENTRY / EXIT STATE MACHINE
// ---------------------------------------------------------------------
// A student's current campus state (INSIDE / OUTSIDE) is derived from
// their most recent granted ('Match') entry_logs row rather than stored
// in a separate column — this keeps a single source of truth and avoids
// the state and the log ever drifting out of sync with each other.
// See database/migration_v16_entry_exit_settings.sql for the schema change.

/** 'INSIDE' if the student's last granted transaction was an Entry, otherwise 'OUTSIDE' (default when no history exists). */
function get_student_access_state(PDO $pdo, string $studentId): string {
    $stmt = $pdo->prepare("SELECT transaction_type FROM entry_logs
                            WHERE student_id = ? AND status = 'Match'
                            ORDER BY time_in DESC, id DESC LIMIT 1");
    $stmt->execute([$studentId]);
    $lastType = $stmt->fetchColumn();
    return ($lastType === 'Entry') ? 'INSIDE' : 'OUTSIDE';
}

/** Timestamp + id of the student's most recent granted transaction, or null if they have none. */
function get_last_granted_log(PDO $pdo, string $studentId): ?array {
    $stmt = $pdo->prepare("SELECT id, time_in, transaction_type FROM entry_logs
                            WHERE student_id = ? AND status = 'Match'
                            ORDER BY time_in DESC, id DESC LIMIT 1");
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Duplicate-scan protection (Settings → Duplicate Scan Cooldown, default 5s).
 * True if this student already has a granted transaction within the cooldown
 * window — the caller should skip writing a new log / sending SMS and show
 * an "already scanned" message instead.
 */
function is_duplicate_scan(PDO $pdo, string $studentId): bool {
    $cooldown = (int) get_setting($pdo, 'duplicate_scan_cooldown_seconds', 5);
    if ($cooldown <= 0) return false;
    $last = get_last_granted_log($pdo, $studentId);
    if (!$last) return false;
    return (time() - strtotime($last['time_in'])) < $cooldown;
}

/**
 * When a student is currently INSIDE, an Exit transaction may only be
 * processed once the configured minimum interval has passed since their
 * Entry (Settings → Minimum Entry-to-Exit Interval, default 15 minutes).
 * Returns ['allowed' => bool, 'wait_seconds' => int remaining].
 */
function can_process_exit(PDO $pdo, string $studentId): array {
    $minMinutes = (int) get_setting($pdo, 'min_entry_exit_interval_minutes', 15);
    $last = get_last_granted_log($pdo, $studentId);
    if (!$last) return ['allowed' => true, 'wait_seconds' => 0];
    $elapsed = time() - strtotime($last['time_in']);
    $requiredSeconds = max(0, $minMinutes * 60);
    if ($elapsed >= $requiredSeconds) return ['allowed' => true, 'wait_seconds' => 0];
    return ['allowed' => false, 'wait_seconds' => $requiredSeconds - $elapsed];
}

/**
 * Serialize concurrent scan requests for the SAME student (e.g. two scanner
 * stations, or a double-tap) using a MySQL named lock, so two near-
 * simultaneous requests can never both pass the duplicate/interval checks
 * above and write two transactions for one physical scan. Always paired
 * with release_student_scan_lock() in a finally block by the caller.
 * Returns true if the lock was acquired within the timeout.
 */
function acquire_student_scan_lock(PDO $pdo, string $studentId, int $timeoutSeconds = 3): bool {
    $name = 'sg_scan_' . md5($studentId);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute([$name, $timeoutSeconds]);
    return (int) $stmt->fetchColumn() === 1;
}

function release_student_scan_lock(PDO $pdo, string $studentId): void {
    $name = 'sg_scan_' . md5($studentId);
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute([$name]);
}

// ---------------------------------------------------------------------
// PHASE 2: VERIFICATION RATE LIMITING (abuse protection for scan.php / face.php)
// ---------------------------------------------------------------------

/**
 * Idempotently ensure the verification_attempts table exists. Safe to call on
 * every request (CREATE TABLE IF NOT EXISTS is a cheap metadata check) so
 * existing deployments are upgraded automatically without a manual re-import.
 */
function ensure_verification_attempts_table(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS verification_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL,
        endpoint ENUM('scan','face') NOT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_verification_attempts_lookup (ip_address, endpoint, attempted_at)
    ) ENGINE=InnoDB");
    $checked = true;
}

/**
 * Record one verification attempt (scan or face) against the caller's IP.
 * Called regardless of the eventual match/deny outcome — throttling is
 * about request volume, not result.
 */
function record_verification_attempt(PDO $pdo, string $endpoint): void {
    ensure_verification_attempts_table($pdo);
    $stmt = $pdo->prepare("INSERT INTO verification_attempts (ip_address, endpoint) VALUES (?, ?)");
    $stmt->execute([get_client_ip(), $endpoint]);
}

/**
 * Count attempts from the caller's IP against a given endpoint within the last $windowSeconds.
 */
function count_recent_verification_attempts(PDO $pdo, string $endpoint, int $windowSeconds): int {
    ensure_verification_attempts_table($pdo);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM verification_attempts WHERE ip_address = ? AND endpoint = ? AND attempted_at >= (NOW() - INTERVAL ? SECOND)");
    $stmt->bindValue(1, get_client_ip());
    $stmt->bindValue(2, $endpoint);
    $stmt->bindValue(3, $windowSeconds, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

/**
 * Check whether the caller's IP has exceeded the configured verification rate
 * limit for the given endpoint ('scan' or 'face'). Threshold/window are
 * settings-table-driven, matching the max_login_attempts pattern.
 * Returns ['throttled' => bool, 'retry_after' => int seconds].
 */
function is_verification_throttled(PDO $pdo, string $endpoint): array {
    $maxAttempts = (int) get_setting($pdo, 'verification_rate_limit_max', 30);
    $windowSeconds = (int) get_setting($pdo, 'verification_rate_limit_window_seconds', 60);
    $count = count_recent_verification_attempts($pdo, $endpoint, $windowSeconds);
    if ($count >= $maxAttempts) {
        return ['throttled' => true, 'retry_after' => $windowSeconds];
    }
    return ['throttled' => false, 'retry_after' => 0];
}

// ---------------------------------------------------------------------
// KIOSK STATION SESSIONS (V17)
// ---------------------------------------------------------------------
// A kiosk is a device an Administrator has authorized to run the scanner
// WITHOUT an Admin/Staff login. It is deliberately separate from the
// Admin/Staff session:
//   - its own HttpOnly cookie ('sg_kiosk') holding a random token that
//     JavaScript can never read;
//   - only sha256(token) is stored, in kiosk_sessions;
//   - its own idle timeout (Settings -> Kiosk Station, 5-60 min,
//     default 10), enforced here on the server — not by JavaScript;
//   - states: Authorized -> Locked (revoked by an admin) or
//     Expired (idle timeout). Expired/Locked kiosks must be authorized
//     again by an Administrator.
// Only authorization / lock / expiry are audited — never per-scan.
// Students have no accounts and can never obtain a kiosk session:
// authorization requires an authenticated Administrator session.

const SG_KIOSK_COOKIE = 'sg_kiosk';
const SG_KIOSK_LABEL = 'Kiosk Station';
const SG_KIOSK_TIMEOUT_DEFAULT = 10;
const SG_KIOSK_TIMEOUT_MIN = 5;
const SG_KIOSK_TIMEOUT_MAX = 60;

/** Idempotent, like ensure_verification_attempts_table(); mirrors database/migration_v17_kiosk_sessions.sql. */
function kiosk_ensure_table(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS kiosk_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        authorized_by INT DEFAULT NULL,
        authorized_by_name VARCHAR(100) DEFAULT NULL,
        status ENUM('Authorized','Locked','Expired') NOT NULL DEFAULT 'Authorized',
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        authorized_at DATETIME NOT NULL,
        last_activity DATETIME NOT NULL,
        ended_at DATETIME DEFAULT NULL,
        ended_by VARCHAR(100) DEFAULT NULL,
        INDEX idx_kiosk_status_activity (status, last_activity)
    ) ENGINE=InnoDB");
    $checked = true;
}

/** Kiosk idle timeout in minutes, always within 5-60 (falls back to 10 if the stored value is invalid). */
function kiosk_timeout_minutes(PDO $pdo): int {
    $v = (int) get_setting($pdo, 'kiosk_timeout_minutes', SG_KIOSK_TIMEOUT_DEFAULT);
    return ($v >= SG_KIOSK_TIMEOUT_MIN && $v <= SG_KIOSK_TIMEOUT_MAX) ? $v : SG_KIOSK_TIMEOUT_DEFAULT;
}

function kiosk_set_cookie(string $token): void {
    if (headers_sent()) return;
    setcookie(SG_KIOSK_COOKIE, $token, [
        'expires'  => $token === '' ? time() - 3600 : 0,
        'path'     => '/',
        'secure'   => defined('IS_HTTPS') ? IS_HTTPS : false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if ($token === '') unset($_COOKIE[SG_KIOSK_COOKIE]); else $_COOKIE[SG_KIOSK_COOKIE] = $token;
}

/**
 * Mark every Authorized kiosk whose idle time exceeded the timeout as Expired
 * (audited once per kiosk). Used by the Settings page so the list is accurate
 * even for stations nobody has touched since they went idle.
 */
function kiosk_expire_stale(PDO $pdo): void {
    kiosk_ensure_table($pdo);
    $cutoff = date('Y-m-d H:i:s', time() - kiosk_timeout_minutes($pdo) * 60);
    $sel = $pdo->prepare("SELECT id FROM kiosk_sessions WHERE status = 'Authorized' AND last_activity < ?");
    $sel->execute([$cutoff]);
    foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $id) {
        kiosk_mark_expired($pdo, (int) $id);
    }
}

function kiosk_mark_expired(PDO $pdo, int $id): void {
    $upd = $pdo->prepare("UPDATE kiosk_sessions SET status = 'Expired', ended_at = ?, ended_by = 'System' WHERE id = ? AND status = 'Authorized'");
    $upd->execute([date('Y-m-d H:i:s'), $id]);
    if ($upd->rowCount() === 1) {
        log_activity($pdo, 'Kiosk Session Expired', "Kiosk station #{$id} expired after " . kiosk_timeout_minutes($pdo) . ' minutes of inactivity. An Administrator must authorize it again.', SG_KIOSK_LABEL);
    }
}

/**
 * The kiosk session presented by THIS request's cookie, or null when there is
 * none / it is Locked / it Expired / it is unknown. Resolved once per request.
 * $touch = true refreshes last_activity (real usage); pass false for read-only
 * checks (status polling, admin UI) so they never keep a kiosk alive.
 */
function kiosk_current(PDO $pdo, bool $touch = true, bool $refresh = false): ?array {
    static $loaded = false;
    static $row = null;
    if ($refresh) { $loaded = false; $row = null; }
    if ($loaded) return $row;
    $loaded = true;

    $token = $_COOKIE[SG_KIOSK_COOKIE] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) return $row = null;

    kiosk_ensure_table($pdo);
    $stmt = $pdo->prepare("SELECT * FROM kiosk_sessions WHERE token_hash = ? LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    $found = $stmt->fetch();

    if (!$found || $found['status'] !== 'Authorized') {
        kiosk_set_cookie('');
        return $row = null;
    }

    $idleSeconds = time() - strtotime($found['last_activity']);
    if ($idleSeconds > kiosk_timeout_minutes($pdo) * 60) {
        kiosk_mark_expired($pdo, (int) $found['id']);
        kiosk_set_cookie('');
        return $row = null;
    }

    if ($touch && $idleSeconds >= 5) {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE kiosk_sessions SET last_activity = ? WHERE id = ? AND status = 'Authorized'")->execute([$now, $found['id']]);
        $found['last_activity'] = $now;
    }
    return $row = $found;
}

function kiosk_is_valid(PDO $pdo, bool $touch = true): bool {
    return kiosk_current($pdo, $touch) !== null;
}

/**
 * Whether the currently authenticated Admin/Staff account may authorize or
 * lock kiosk stations. Administrators always retain this permission. For
 * Staff, Administrators can explicitly grant it in Settings -> Kiosk Access.
 */
function kiosk_user_can_manage(PDO $pdo): bool {
    if (!sg_staff_session_ok()) return false;
    if (($_SESSION['role'] ?? '') === 'Administrator') return true;
    $raw = get_setting($pdo, 'kiosk_manager_user_ids', '[]');
    $ids = json_decode((string) $raw, true);
    if (!is_array($ids)) $ids = [];
    return in_array((int) ($_SESSION['user_id'] ?? 0), array_map('intval', $ids), true);
}

/**
 * Authorize THIS device as a kiosk. The caller must already have verified an
 * Administrator session + CSRF. Returns the new kiosk id; the token itself is
 * only ever sent to the browser as an HttpOnly cookie.
 */
function kiosk_authorize(PDO $pdo, int $adminId, string $adminName): int {
    kiosk_ensure_table($pdo);
    $token = bin2hex(random_bytes(32));
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO kiosk_sessions (token_hash, authorized_by, authorized_by_name, status, ip_address, user_agent, authorized_at, last_activity)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([hash('sha256', $token), $adminId, $adminName, 'Authorized', get_client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255), $now, $now]);
    $id = (int) $pdo->lastInsertId();
    kiosk_set_cookie($token);
    log_activity($pdo, 'Kiosk Authorized', "Kiosk station #{$id} authorized by {$adminName}", $adminName);
    kiosk_current($pdo, false, true);
    return $id;
}

/**
 * Lock (revoke) a kiosk — this device's own session when $id is null, or a
 * specific station by id. Returns false if there was nothing Authorized to lock.
 */
function kiosk_revoke(PDO $pdo, ?int $id, string $by): bool {
    kiosk_ensure_table($pdo);
    $current = kiosk_current($pdo, false);
    if ($id === null) {
        if (!$current) return false;
        $id = (int) $current['id'];
    }
    $upd = $pdo->prepare("UPDATE kiosk_sessions SET status = 'Locked', ended_at = ?, ended_by = ? WHERE id = ? AND status = 'Authorized'");
    $upd->execute([date('Y-m-d H:i:s'), $by, $id]);
    if ($upd->rowCount() !== 1) return false;
    log_activity($pdo, 'Kiosk Locked', "Kiosk station #{$id} locked by {$by}", $by);
    if ($current && (int) $current['id'] === $id) kiosk_set_cookie('');
    kiosk_current($pdo, false, true);
    return true;
}

// JSON API SAFETY NET (used by api/scan.php and api/face.php)
// ---------------------------------------------------------------------
// The scanner kiosk parses every API response with res.json(). If PHP
// emits a warning/notice/HTML error page before or after our JSON, the
// client's parse fails and the student is shown a misleading denial.
// These two helpers guarantee that a verification endpoint ALWAYS emits
// exactly one well-formed JSON object, while still recording the real
// exception (message + file + line) in the PHP error log for debugging.

/**
 * Call once at the top of a JSON API endpoint, immediately after
 * config.php is loaded.
 *   - suppresses inline display of warnings/notices (they would be
 *     printed INTO the JSON body; config.php turns them on for pages)
 *   - buffers output so any stray output can be discarded
 *   - registers a shutdown handler that turns an otherwise-uncatchable
 *     fatal error (E_ERROR, parse/type errors outside try/catch) into a
 *     valid JSON error response instead of a blank/HTML reply.
 * $genericReason is what the client sees; details only ever go to the log.
 */
function sg_api_json_boot(string $context, string $genericReason): void {
    ini_set('display_errors', '0');   // never inline-print into the JSON body
    ini_set('log_errors', '1');       // but keep logging everything
    ob_start();

    register_shutdown_function(function () use ($context, $genericReason) {
        $fatal = error_get_last();
        $isFatal = $fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);

        $buffered = '';
        while (ob_get_level() > 0) {
            $buffered .= (string) ob_get_clean();
        }

        if ($isFatal) {
            error_log(sprintf('%s FATAL: %s in %s on line %d', $context, $fatal['message'], $fatal['file'], $fatal['line']));
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode(['status' => 'error', 'reason' => $genericReason]);
            return;
        }

        // Normal completion: emit only the JSON the endpoint produced,
        // dropping any stray leading/trailing output (BOM, whitespace,
        // a notice that slipped through) that would break res.json().
        $trimmed = trim($buffered);
        $start = strpos($trimmed, '{');
        if ($start !== false && $start > 0) {
            error_log($context . ' WARNING: discarded ' . $start . ' byte(s) of stray output before the JSON body.');
            $trimmed = substr($trimmed, $start);
        }
        echo $trimmed;
    });
}

/**
 * PERFORMANCE (Phase 2.5): send the JSON response to the client now and,
 * where the SAPI supports it, close the connection immediately — so any
 * post-response work the caller does afterward (e.g. the SMS notification
 * in api/scan.php / api/face.php, which is already fully isolated from the
 * verification outcome) no longer adds to how long the person at the gate
 * waits for their "granted" screen.
 *
 * Safe by construction:
 *  - Drains sg_api_json_boot()'s output buffer first, so its shutdown
 *    handler has nothing left to (re-)echo — no risk of a second JSON blob.
 *  - Falls back to a plain flush() when fastcgi_finish_request() doesn't
 *    exist (e.g. PHP's built-in server, mod_php) — script correctness is
 *    identical either way; only the early-disconnect speed win is
 *    unavailable there.
 *  - Does not exit() and does not touch locks/finally blocks — callers keep
 *    doing whatever cleanup (lock release, etc.) they already did after the
 *    echo, unchanged.
 */
function sg_send_response_then_continue(string $jsonBody): void {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo $jsonBody;
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}


/**
 * Standard exception handler for a verification endpoint.
 * Logs message + file + line (never credentials or SQL parameters), and
 * returns a generic client-safe reason. On a LOCAL environment only, a
 * missing-schema exception additionally names the migration to run —
 * this is the difference between "A server error occurred" and knowing
 * the database has not been migrated yet.
 * Returns the response array for the caller to json_encode().
 */
function sg_api_exception_response(Throwable $e, string $context, string $genericReason): array {
    error_log(sprintf('%s error: %s in %s on line %d', $context, $e->getMessage(), $e->getFile(), $e->getLine()));

    $response = ['status' => 'error', 'reason' => $genericReason];

    // SQLSTATE 42S22 = unknown column, 42S02 = missing table. Both mean the
    // database schema is older than this codebase.
    $sqlState = ($e instanceof PDOException) ? (string) $e->getCode() : '';
    $schemaMismatch = in_array($sqlState, ['42S22', '42S02'], true);

    if ($schemaMismatch) {
        $response['error_code'] = 'schema_outdated';
        error_log($context . ' hint: the database schema is missing a column/table this version requires — run database/migration_v16_entry_exit_settings.sql against the smartgatewayproject_dev database.');
    }

    if (defined('APP_ENV') && APP_ENV === 'local') {
        // Local/dev only: surface the actual exception text (no credentials,
        // no bound parameters) so the developer is not left guessing.
        $response['debug'] = [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ];
        if ($schemaMismatch) {
            $response['debug']['fix'] = 'Run database/migration_v16_entry_exit_settings.sql against the smartgatewayproject_dev database.';
        }
    }

    return $response;
}
// ---------------------------------------------------------------------
// SCANNER ACCESS = Admin/Staff session OR authorized kiosk session
// ---------------------------------------------------------------------

/** A logged-in Administrator/Staff account (students never have accounts). */
function sg_staff_session_ok(): bool {
    return !empty($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['Administrator', 'Staff'], true);
}

function sg_scanner_access_ok(PDO $pdo): bool {
    return sg_staff_session_ok() || kiosk_is_valid($pdo);
}

/** True when the scanner is being used through a kiosk session rather than a staff login. */
function sg_is_kiosk_mode(PDO $pdo): bool {
    return !sg_staff_session_ok() && kiosk_is_valid($pdo);
}

/** Who a transaction is attributed to: the real Admin/Staff user, or "Kiosk Station". */
function sg_current_verifier_label(PDO $pdo): string {
    if (sg_staff_session_ok()) return $_SESSION['username'] ?? 'Staff';
    return kiosk_is_valid($pdo) ? SG_KIOSK_LABEL : 'Staff';
}

/** Page guard for the scanner screen (replaces plain require_login()). */
function sg_require_scanner_access(PDO $pdo): void {
    if (sg_staff_session_ok()) {
        require_login(); // keeps the Admin/Staff idle timeout exactly as before
        return;
    }
    $hadKioskCookie = !empty($_COOKIE[SG_KIOSK_COOKIE]);
    if (kiosk_is_valid($pdo)) return;
    header('Location: ' . APP_URL . '/login.php' . ($hadKioskCookie ? '?notice=kiosk_expired' : ''));
    exit;
}

/**
 * =====================================================================
 * PHASE 3: Philippine mobile contact number normalization
 * =====================================================================
 * Canonical stored/sent format is 09XXXXXXXXX (11 digits, starts "09").
 * +639XXXXXXXXX is accepted as INPUT and normalized to that canonical
 * form before it is stored or handed to the SMS provider. Anything else
 * (too short/long, wrong prefix, non-numeric) is rejected by the caller
 * using sg_is_valid_ph_mobile() -- this function never guesses or pads;
 * it returns null rather than silently reshaping something that isn't
 * actually one of the two accepted input formats, so existing data that
 * doesn't match either shape is left completely untouched by callers
 * that check the return value before writing anything.
 */
function sg_normalize_ph_mobile(string $raw): ?string {
    $trimmed = trim($raw);
    // Strip common formatting characters a user might type/paste: spaces,
    // dashes, dots, parentheses. Does not strip digits or the leading '+'.
    $stripped = preg_replace('/[\s\-\.\(\)]/', '', $trimmed);

    if (preg_match('/^09\d{9}$/', $stripped)) {
        return $stripped; // already canonical
    }
    if (preg_match('/^\+639\d{9}$/', $stripped)) {
        return '0' . substr($stripped, 3); // +639XXXXXXXXX -> 09XXXXXXXXX
    }
    // Also accept the same number typed as 639XXXXXXXXX (no plus) or
    // 6309XXXXXXXXX, since these are common copy/paste variants of the
    // same two accepted shapes.
    if (preg_match('/^639\d{9}$/', $stripped)) {
        return '0' . substr($stripped, 2);
    }

    return null; // not one of the accepted input shapes -- caller must not store/send this
}

/** True only for an already-canonical 09XXXXXXXXX number (11 digits). */
function sg_is_valid_ph_mobile(string $value): bool {
    return (bool) preg_match('/^09\d{9}$/', $value);
}
