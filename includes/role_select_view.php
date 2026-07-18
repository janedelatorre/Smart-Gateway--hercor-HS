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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="login-wrapper">
    <!-- Left panel: branding -->
    <div class="login-left d-none d-lg-flex">
        <div class="logo-crest has-image">
            <img src="assets/images/logo/school-logo.png" alt="School Logo">
        </div>
        <h1>Smart Gateway</h1>
        <p class="fs-5 mb-4" style="z-index:1;">Campus Entry System</p>
        <p class="subtitle">Secure &middot; Automated &middot; Efficient</p>
        <p class="mt-4 opacity-75" style="max-width:380px; z-index:1;">
            Smart monitoring of student entry for a safer campus — powered by barcode
            verification with backup facial recognition and instant parent SMS alerts.
        </p>
    </div>

    <!-- Right panel: role selection -->
    <div class="login-right">
        <div class="login-card text-center">
            <h3 class="fw-bold mb-1">Welcome back</h3>
            <p class="text-muted mb-4">Please select how you'd like to sign in</p>

            <div class="d-grid gap-3">
                <a href="login.php?role=admin" class="btn btn-sg-primary d-flex align-items-center justify-content-center gap-2 py-3">
                    <i class="bi bi-person-badge-fill fs-5"></i>
                    <span class="fw-600">Administrator</span>
                </a>
                <a href="login.php?role=staff" class="btn btn-outline-secondary d-flex align-items-center justify-content-center gap-2 py-3">
                    <i class="bi bi-person-fill fs-5"></i>
                    <span class="fw-600">Staff</span>
                </a>
            </div>

            <p class="text-center text-muted small mt-4 mb-0">&copy; <?= date('Y') ?> Smart Gateway System</p>
        </div>
    </div>
</div>

</body>
</html>
