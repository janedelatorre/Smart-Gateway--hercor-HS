<?php
/**
 * Role-selection screen — shown when login.php is visited with no ?role= yet.
 * Same visual design/shell as the credentials screen, per the spec's requirement
 * that both destinations "maintain the same visual design."
 * Included directly from login.php ($pdo, e(), etc. are already available).
 */
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

    <section class="login-right role-home-right">
        <div class="login-card role-selection-card text-center">
            <div class="welcome-kicker">WELCOME TO</div>
            <h3 class="fw-bold mb-1">Smart Gateway</h3>
            <p class="mb-4">Please select how you'd like to sign in.</p>

            <div class="role-options d-grid gap-3">
                <a href="login.php?role=admin" class="role-option admin">
                    <span class="role-icon"><i class="bi bi-person-gear"></i></span>
                    <span class="role-copy"><strong>Administrator</strong><span>Access the admin portal for system management and user control.</span></span>
                    <span class="role-arrow"><i class="bi bi-arrow-right"></i></span>
                </a>
                <a href="login.php?role=staff" class="role-option staff">
                    <span class="role-icon"><i class="bi bi-people"></i></span>
                    <span class="role-copy"><strong>Staff</strong><span>Access the staff portal for daily campus services and records.</span></span>
                    <span class="role-arrow"><i class="bi bi-arrow-right"></i></span>
                </a>
            </div>

            <div class="home-copyright"><span></span><small>&copy; <?= date('Y') ?> Smart Gateway. All rights reserved.</small><span></span></div>
        </div>
    </section>
</div>

</body>
</html>
