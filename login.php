<?php
/**
 * =====================================================================
 * LOGIN PAGE
 * =====================================================================
 * Handles staff/administrator authentication using prepared statements
 * and password_verify() against the hashed password stored in DB.
 */
require_once __DIR__ . '/database/config.php';

// Already logged in? go straight to the correct dashboard for their role
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . dashboard_url_for_role($_SESSION['role'] ?? null));
    exit;
}

$error = '';
$notice = '';
$showCaptcha = false;

if (($_GET['notice'] ?? '') === 'session_expired') {
    $notice = 'Your session expired due to inactivity. Please log in again.';
} elseif (($_GET['notice'] ?? '') === 'kiosk_expired') {
    $notice = 'This kiosk station is locked or its session expired. An Administrator must authorize it again.';
}
// reset_success is now handled by the SweetAlert popup below.
// Previously:
// if (($_GET['notice'] ?? '') === 'reset_success') {
//     $notice = 'Your password has been reset successfully. Please sign in with your new password.';
// }


// ---- Role selection (Administrator / Staff) — same visual design, separate portal ----
$roleMap = ['admin' => 'Administrator', 'staff' => 'Staff'];
$selectedRoleKey = $_GET['role'] ?? $_POST['role'] ?? '';
$selectedRole = $roleMap[$selectedRoleKey] ?? null;

// No role chosen yet -> show the role-selection screen instead of the credentials form
if ($selectedRole === null) {
    require_once __DIR__ . '/includes/role_select_view.php';
    exit;
}

// Decide up front whether this username currently needs a CAPTCHA (checked both
// before submission — e.g. after a redirect — and after a failed POST below).
$usernameForCaptchaCheck = trim($_POST['username'] ?? '');
if ($usernameForCaptchaCheck !== '' && should_show_captcha($pdo, $usernameForCaptchaCheck)) {
    $showCaptcha = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session token. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $captchaInput = $_POST['captcha'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } elseif ($showCaptcha && $captchaInput === '') {
            // Threshold was just crossed on a previous attempt; this submission predates
            // the CAPTCHA field appearing in the user's form. Ask them to solve it now.
            $error = 'Please complete the security check below and try again.';
        } elseif ($showCaptcha && !verify_captcha($captchaInput)) {
            $error = 'Incorrect CAPTCHA answer. Please try again.';
            $showCaptcha = true; // keep the challenge up after a failed attempt
        } else {
            // Lockout is checked BEFORE password verification, so a locked account
            // never leaks whether the submitted password was even correct.
            $lockedSeconds = get_lockout_seconds_remaining($pdo, $username);
            if ($lockedSeconds > 0) {
                $minutes = (int) ceil($lockedSeconds / 60);
                $error = "This account is locked due to repeated failed login attempts. Please try again in {$minutes} minute(s).";
            } else {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && $user['status'] === 'Active' && password_verify($password, $user['password'])) {
                    // Credentials are correct — but make sure they picked the right portal.
                    if ($user['role'] !== $selectedRole) {
                        $error = "This account is registered as {$user['role']}, not {$selectedRole}. Please go back and choose the correct portal.";
                        log_activity($pdo, 'Login Failed', "Correct credentials but wrong portal selected ({$selectedRole})", $username);
                    } else {
                        record_login_attempt($pdo, $username, true);
                        clear_failed_logins($pdo, $username);

                        session_regenerate_id(true);
                        $_SESSION['user_id']       = $user['id'];
                        $_SESSION['username']      = $user['username'];
                        $_SESSION['fullname']      = $user['fullname'];
                        $_SESSION['role']          = $user['role'];
                        $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
                        $_SESSION['last_activity'] = time();

                        $pdo->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?")
                            ->execute([get_client_ip(), $user['id']]);
                        log_activity($pdo, 'Login Successful', null, $username);

                        header('Location: ' . dashboard_url_for_role($user['role']));
                        exit;
                    }
                } else {
                    record_login_attempt($pdo, $username, false);

                    if ($user) {
                        $justLocked = register_failed_login($pdo, $username);
                        if ($justLocked) {
                            $lockoutMinutes = (int) get_setting($pdo, 'lockout_duration_minutes', 15);
                            $error = "Too many failed attempts. This account has been locked for {$lockoutMinutes} minutes.";
                            create_notification($pdo, null, 'warning', 'Account Locked', "Account '{$username}' was locked for {$lockoutMinutes} minutes after repeated failed login attempts.");
                        } else {
                            $error = 'Invalid username or password.';
                        }
                    } else {
                        $error = 'Invalid username or password.';
                    }

                    log_activity($pdo, 'Login Failed', 'Incorrect username or password', $username);
                    $showCaptcha = should_show_captcha($pdo, $username);
                }
            }
        }
    }
}

$captchaQuestion = $showCaptcha ? generate_captcha() : null;
$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In | Smart Gateway</title>
<meta name="description" content="Secure staff and administrator sign-in for Smart Gateway, Hercor College's campus entry verification system.">
<meta name="robots" content="noindex, nofollow">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/polish.css">
<link rel="icon" type="image/png" href="favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="favicon.svg" />
<link rel="shortcut icon" href="favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png" />
<link rel="manifest" href="site.webmanifest" />
</head>
<body>

<div class="login-wrapper role-homepage">
    <section class="login-left">
        <div class="hero-orbit" aria-hidden="true"></div>
        <div class="hero-content">
            <div class="brand-row">
                <div class="logo-crest has-image"><img src="assets/images/logo/school-logo.png" alt="Hercor College Logo"></div>
                <div class="brand-copy">
                    <strong>Hercor College</strong>
                    <span>HIGH SCHOOL DEPARTMENT</span>
                </div>
            </div>
            <div class="eyebrow-line"></div>
            <h1 class="hero-title">Smart Gateway<span>Campus Entry System</span></h1>
            <p class="hero-copy">Secure, automated, and efficient campus entry for students, faculty and staff — powered by barcode verification and real-time monitoring.</p>
            <div class="login-features">
                <div class="login-feature"><div class="feature-icon"><i class="bi bi-shield-check"></i></div><strong>Secure</strong><small>Verified access for a safer campus.</small></div>
                <div class="login-feature"><div class="feature-icon"><i class="bi bi-gear-fill"></i></div><strong>Automated</strong><small>Faster and easier entry process.</small></div>
                <div class="login-feature"><div class="feature-icon"><i class="bi bi-link-45deg"></i></div><strong>Connected</strong><small>A unified system for everyone.</small></div>
            </div>
        </div>
    </section>

    <div class="login-right role-home-right">
        <div class="login-card">
            <a href="login.php" class="small text-decoration-none d-inline-flex align-items-center mb-3"><i class="bi bi-arrow-left me-1"></i>Back to portal selection</a>
            <div class="role-pill mb-3"><i class="bi bi-shield-lock-fill"></i><?= e($selectedRole) ?> Portal</div>
            <h3 class="fw-bold mb-1">Welcome back!</h3>
            <p class="mb-4">Sign in to your <?= e(strtolower($selectedRole)) ?> portal.</p>

            <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($error) ?></div>
            <?php endif; ?>

            <?php if (in_array($_GET['notice'] ?? '', ['session_expired', 'kiosk_expired'], true)): ?>
            <div class="alert alert-warning py-2"><i class="bi bi-clock-history me-1"></i><?= e($notice) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="role" value="<?= e($selectedRoleKey) ?>">

                <div class="mb-3">
                    <label class="form-label fw-600">Username</label>
                    <input type="text" name="username" autocomplete="username" class="form-control" placeholder="Enter your username" value="<?= e($usernameForCaptchaCheck) ?>" required autofocus>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-600">Password</label>
                    <div class="input-group">
                        <input type="password" autocomplete="current-password" name="password" id="passwordField" class="form-control" placeholder="Enter your password" required>
                        <button class="btn" type="button" id="togglePassword"><i class="bi bi-eye"></i></button>
                    </div>
                </div>

                <?php if ($captchaQuestion): ?>
                <div class="mb-3">
                    <label class="form-label fw-600">Security Check</label>
                    <input type="text" name="captcha" class="form-control" placeholder="<?= e($captchaQuestion) ?>" required autocomplete="off">
                    <small class="text-muted"><?= e($captchaQuestion) ?></small>
                </div>
                <?php endif; ?>

                <div class="d-flex justify-content-end align-items-center mb-4">
                    <a href="forgot_password.php" class="small text-decoration-none">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-sg-primary w-100" id="loginSubmitBtn">
                    <span id="loginBtnText">Sign in <i class="bi bi-arrow-right ms-1"></i></span>
                    <span id="loginBtnSpinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status"></span>
                </button>
                <div class="auth-divider"></div>
                <p class="text-center small mb-0">&copy; <?= date('Y') ?> Smart Gateway System</p>
            </form>
        </div>
    </div>
</div>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>

<?php if (($_GET['notice'] ?? '') === 'reset_success'): ?>
<script>
Swal.fire({ icon: 'success', title: 'Password Reset Successful',text: 'Your password has been updated successfully.', confirmButtonText: 'Sign In',confirmButtonColor: '#3B82F6'
}).then(() => {
    document.querySelector('input[name="username"]').focus();
});
</script>
<?php endif; ?>
<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const field = document.getElementById('passwordField');
    const icon = this.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
});

// Loading animation while authenticating (Section 1 requirement)
document.getElementById('loginForm').addEventListener('submit', function () {
    document.getElementById('loginBtnText').textContent = 'Signing in...';
    document.getElementById('loginBtnSpinner').classList.remove('d-none');
    document.getElementById('loginSubmitBtn').disabled = true;
});

</script>
</body>
</html>
