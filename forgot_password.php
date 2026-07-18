<?php
/**
 * =====================================================================
 * FORGOT PASSWORD — 3-step flow
 * =====================================================================
 * Step 1: Enter username -> look up account -> send OTP via SMS
 * Step 2: Enter the 6-digit OTP code (delivered through the existing
 *         SMS module, reusing sg_send_sms())
 * Step 3: Set a new password (policy + reuse prevention enforced,
 *         same rules as Profile / Change Password / Users)
 * =====================================================================
 */
define('SG_INTERNAL_CALL', true);
require_once __DIR__ . '/database/config.php';
require_once __DIR__ . '/api/sms.php'; // provides sg_send_sms()

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . dashboard_url_for_role($_SESSION['role'] ?? null));
    exit;
}

$error = '';
$info = '';
$step = $_SESSION['fp_step'] ?? 1;

// ---------------------------------------------------------------------
// STEP 1: identify the account and send the OTP
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'identify') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session token. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        if ($username === '') {
            $error = 'Please enter your username.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'Active' LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'No active account found with that username.';
            } elseif (empty($user['contact_number'])) {
                $error = 'This account has no contact number on file. Please contact your system administrator.';
            } else {
                $code = create_otp($pdo, (int) $user['id'], 'password_reset', 10);
                $message = "Smart Gateway: Your password reset code is {$code}. It expires in 10 minutes. Do not share this code.";
                $result = sg_send_sms($pdo, $user['contact_number'], $message, 'System');

                $stmt2 = $pdo->prepare("INSERT INTO sms_logs (recipient, message, status, provider_response, sent_by) VALUES (?,?,?,?,?)");
                $stmt2->execute([$user['contact_number'], $message, $result['status'], $result['response'], 'System']);

                $_SESSION['fp_user_id']  = (int) $user['id'];
                $_SESSION['fp_username'] = $user['username'];
                $_SESSION['fp_masked_contact'] = preg_replace('/^(\d{4}).*(\d{2})$/', '$1****$2', $user['contact_number']);
                $_SESSION['fp_step'] = 2;
                unset($_SESSION['fp_otp_attempts']);
                log_activity($pdo, 'Password Reset Requested', 'OTP sent for forgot-password flow', $username);

                header('Location: forgot_password.php');
                exit;
            }
        }
    }
}

// ---------------------------------------------------------------------
// STEP 2: verify the OTP
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'verify_otp') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session token. Please refresh and try again.';
    } elseif (empty($_SESSION['fp_user_id'])) {
        $error = 'Your session expired. Please start again.';
        $_SESSION['fp_step'] = 1;
    } else {
        $code = trim($_POST['otp'] ?? '');
        if (verify_otp($pdo, (int) $_SESSION['fp_user_id'], $code)) {
            unset($_SESSION['fp_otp_attempts']);
            $_SESSION['fp_otp_verified'] = true;
            $_SESSION['fp_step'] = 3;
            header('Location: forgot_password.php');
            exit;
        } else {
            $_SESSION['fp_otp_attempts'] = ($_SESSION['fp_otp_attempts'] ?? 0) + 1;
            if ($_SESSION['fp_otp_attempts'] >= 5) {
                // Too many wrong guesses — require a fresh code rather than allow further brute-forcing.
                log_activity($pdo, 'Password Reset Requested', 'OTP verification locked after repeated failed attempts', $_SESSION['fp_username'] ?? null);
                unset($_SESSION['fp_user_id'], $_SESSION['fp_username'], $_SESSION['fp_masked_contact'], $_SESSION['fp_otp_verified'], $_SESSION['fp_otp_attempts']);
                $_SESSION['fp_step'] = 1;
                $error = 'Too many incorrect attempts. Please start again and request a new code.';
            } else {
                $error = 'Invalid or expired code. Please try again or request a new one.';
            }
        }
    }
}

// ---- Resend OTP (from step 2) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'resend_otp') {
    if (!empty($_SESSION['fp_user_id']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
        // Basic cooldown: don't resend if a valid unexpired code was created in the last 45 seconds
        $stmt = $pdo->prepare("SELECT created_at FROM otp_codes WHERE user_id = ? AND purpose='password_reset' AND used=0 ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$_SESSION['fp_user_id']]);
        $lastCreated = $stmt->fetchColumn();

        if ($lastCreated && (time() - strtotime($lastCreated)) < 45) {
            $error = 'Please wait a moment before requesting another code.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['fp_user_id']]);
            $user = $stmt->fetch();
            if ($user) {
                $code = create_otp($pdo, (int) $user['id'], 'password_reset', 10);
                $message = "Smart Gateway: Your password reset code is {$code}. It expires in 10 minutes. Do not share this code.";
                $result = sg_send_sms($pdo, $user['contact_number'], $message, 'System');
                $pdo->prepare("INSERT INTO sms_logs (recipient, message, status, provider_response, sent_by) VALUES (?,?,?,?,?)")
                    ->execute([$user['contact_number'], $message, $result['status'], $result['response'], 'System']);
                unset($_SESSION['fp_otp_attempts']);
                $info = 'A new code has been sent.';
            }
        }
    }
}

// ---------------------------------------------------------------------
// STEP 3: reset the password
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'reset') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid session token. Please refresh and try again.';
    } elseif (empty($_SESSION['fp_user_id']) || empty($_SESSION['fp_otp_verified'])) {
        $error = 'Your session expired. Please start again.';
        $_SESSION['fp_step'] = 1;
    } else {
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $policyErrors = validate_password_strength($new);

        if ($policyErrors) {
            $error = 'Password does not meet requirements: ' . implode(' ', $policyErrors);
        } elseif ($new !== $confirm) {
            $error = 'New password and confirmation do not match.';
        } elseif (is_password_reused($pdo, (int) $_SESSION['fp_user_id'], $new)) {
            $error = 'You cannot reuse a recent password. Please choose a different one.';
        } else {
            set_user_password($pdo, (int) $_SESSION['fp_user_id'], $new);
            log_activity($pdo, 'Password Change', 'Password reset via Forgot Password OTP flow', $_SESSION['fp_username'] ?? null);

            unset($_SESSION['fp_user_id'], $_SESSION['fp_username'], $_SESSION['fp_masked_contact'], $_SESSION['fp_otp_verified'], $_SESSION['fp_step']);

            header('Location: login.php?notice=reset_success');
            exit;
        }
    }
}

$step = $_SESSION['fp_step'] ?? 1;
$maskedContact = $_SESSION['fp_masked_contact'] ?? '';
$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | Smart Gateway</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="login-wrapper">
    <div class="login-left d-none d-lg-flex">
        <div class="logo-crest has-image">
            <img src="assets/images/logo/school-logo.png" alt="School Logo">
        </div>
        <h1>Smart Gateway</h1>
        <p class="fs-5 mb-4" style="z-index:1;">Campus Entry System</p>
        <p class="subtitle">Secure &middot; Automated &middot; Efficient</p>
    </div>

    <div class="login-right">
        <div class="login-card">
            <a href="login.php" class="small text-decoration-none text-muted d-inline-flex align-items-center mb-2"><i class="bi bi-arrow-left me-1"></i>Back to sign in</a>

            <!-- Step indicator -->
            <div class="d-flex align-items-center gap-2 mb-4">
                <?php for ($i = 1; $i <= 3; $i++): ?>
                    <div class="rounded-circle d-flex align-items-center justify-content-center fw-600 <?= $i <= $step ? 'bg-primary text-white' : 'bg-light text-muted' ?>" style="width:28px;height:28px;font-size:0.8rem;"><?= $i ?></div>
                    <?php if ($i < 3): ?><div class="flex-grow-1" style="height:2px; background: <?= $i < $step ? '#0B5ED7' : '#e9ecef' ?>;"></div><?php endif; ?>
                <?php endfor; ?>
            </div>

            <?php if ($info): ?><div class="alert alert-info py-2"><?= e($info) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($error) ?></div><?php endif; ?>

            <?php if ($step === 1): ?>
                <h3 class="fw-bold mb-1">Forgot your password?</h3>
                <p class="text-muted mb-4">Enter your username and we'll send a verification code by SMS.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="form" value="identify">
                    <div class="mb-3">
                        <label class="form-label fw-600">Username</label>
                        <input type="text" name="username" class="form-control" placeholder="Enter your username" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-sg-primary w-100">Send Verification Code</button>
                </form>

            <?php elseif ($step === 2): ?>
                <h3 class="fw-bold mb-1">Enter verification code</h3>
                <p class="text-muted mb-4">We sent a 6-digit code by SMS to <strong><?= e($maskedContact) ?></strong>.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="form" value="verify_otp">
                    <div class="mb-3">
                        <label class="form-label fw-600">Verification Code</label>
                        <input type="text" name="otp" class="form-control text-center fs-3 fw-bold" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="123456" style="letter-spacing:.35rem" required>
                    </div>
                    <button type="submit" class="btn btn-sg-primary w-100 mb-2">Verify Code</button>
                </form>
                <form method="POST" class="text-center">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="form" value="resend_otp">
                    <button type="submit" class="btn btn-link btn-sm text-decoration-none">Didn't get a code? Resend</button>
                </form>

            <?php elseif ($step === 3): ?>
                <h3 class="fw-bold mb-1">Set a new password</h3>
                <p class="text-muted mb-4">Choose a strong password you haven't used recently.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="form" value="reset">
                    <div class="mb-3">
    <label class="form-label fw-600">New Password</label>

    <div class="input-group">
        <input
            type="password"
            id="newPassword"
            name="new_password"
            class="form-control"
            minlength="12"
            required>

        <button
            class="btn btn-outline-secondary"
            type="button"
            onclick="togglePassword('newPassword', this)">
            <i class="bi bi-eye"></i>
        </button>
    </div>

    <small class="text-muted">
        Min. 12 characters, with uppercase, lowercase, a number, and a special character.
    </small>
</div>

<div class="mb-3">
    <label class="form-label fw-600">Confirm New Password</label>

    <div class="input-group">
        <input
            type="password"
            id="confirmPassword"
            name="confirm_password"
            class="form-control"
            minlength="12"
            required>

        <button
            class="btn btn-outline-secondary"
            type="button"
            onclick="togglePassword('confirmPassword', this)">
            <i class="bi bi-eye"></i>
        </button>
    </div>
</div>
                    <button type="submit" class="btn btn-sg-primary w-100">Reset Password</button>
                </form>
            <?php endif; ?>

            <p class="text-center text-muted small mt-4 mb-0">&copy; <?= date('Y') ?> Smart Gateway System</p>
        </div>
    </div>
</div>

<script>
function togglePassword(id, button){

    const input=document.getElementById(id);
    const icon=button.querySelector("i");

    if(input.type==="password"){

        input.type="text";

        icon.classList.remove("bi-eye");
        icon.classList.add("bi-eye-slash");

    }else{

        input.type="password";

        icon.classList.remove("bi-eye-slash");
        icon.classList.add("bi-eye");

    }

}
</script>

</body>
</html>
