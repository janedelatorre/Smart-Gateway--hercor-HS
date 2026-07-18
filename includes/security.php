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

    $stmt = $pdo->prepare("INSERT INTO audit_logs (username, action, description, ip_address, user_agent) VALUES (?,?,?,?,?)");
    $stmt->execute([$username, $action, $description, $ip, $agent]);
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
        $stmt = $pdo->prepare("UPDATE users SET locked_until = (NOW() + INTERVAL ? MINUTE) WHERE username = ?");
        $stmt->execute([$lockoutMinutes, $username]);
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
 * Validate password strength against policy: 12+ chars, upper, lower, number, special.
 * Returns an array of violation messages (empty array = passes all rules).
 */
function validate_password_strength(string $password): array {
    $errors = [];
    if (strlen($password) < 12) $errors[] = 'Password must be at least 12 characters long.';
    if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must include at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $password)) $errors[] = 'Password must include at least one lowercase letter.';
    if (!preg_match('/[0-9]/', $password)) $errors[] = 'Password must include at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = 'Password must include at least one special character.';
    if (in_array(strtolower($password), SG_COMMON_PASSWORDS, true)) $errors[] = 'This password is too common. Please choose a stronger one.';
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
    $stmt = $pdo->prepare("INSERT INTO otp_codes (user_id, code_hash, purpose, expires_at) VALUES (?,?,?, NOW() + INTERVAL ? MINUTE)");
    $stmt->execute([$userId, $hash, $purpose, $expiresInMinutes]);

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
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?,?,?,?)");
    $stmt->execute([$userId, $type, $title, $message]);
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

    $policyErrors = validate_password_strength($new);
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
