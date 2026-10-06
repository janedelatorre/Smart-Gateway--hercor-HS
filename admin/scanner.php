<?php
require_once __DIR__ . '/../database/config.php';
// Admin/Staff session OR an authorized kiosk session (students have neither).
sg_require_scanner_access($pdo);
$isKioskMode = sg_is_kiosk_mode($pdo);
$canManageKiosk = kiosk_user_can_manage($pdo);
$deviceKiosk = $canManageKiosk ? kiosk_current($pdo, false) : null; // this device's kiosk session, for authorized manager controls

$pageTitle = 'Scan Entry';
$pageHeading = 'Scan Entry';
$pageSubheading = 'Verify student entry and exit using barcode and facial recognition';
$activePage = 'scanner';
$isScanEntryFullscreen = true;
$csrf = generate_csrf_token();

// The facial-fallback streak lives server-side in the session (see
// includes/security.php). Surface its CURRENT state on page load so a
// refresh mid-streak doesn't wrongly re-hide an already-unlocked backup
// button — scanner.js otherwise has no way to know the streak didn't
// start at 0 this time.
$initialFaceGateUnlocked = is_facial_fallback_unlocked($pdo);
$initialFaceGateRemaining = max(0, get_facial_fallback_threshold($pdo) - get_barcode_fail_streak());

require_once __DIR__ . '/../includes/header.php';
?>
<div class="sg-scan-entry-fullscreen">
    <header class="sg-scan-entry-topbar">
        <?php if ($isKioskMode): ?>
        <span></span><!-- kiosk: no navigation back into the admin area -->
        <?php else: ?>
        <a href="<?= e(dashboard_url_for_role($_SESSION['role'] ?? 'Staff')) ?>" class="btn sg-scan-back"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <?php endif; ?>
        <div class="sg-scan-title"><i class="bi bi-upc-scan me-2"></i>SCAN ENTRY</div>
        <div class="sg-topbar-actions">
            <?php if ($isKioskMode): ?>
            <span class="badge bg-success me-2"><i class="bi bi-display me-1"></i>Kiosk Station</span>
            <?php elseif ($canManageKiosk && $deviceKiosk): ?>
            <button type="button" class="btn sg-theme-toggle me-2" id="kioskLockBtn" title="Lock this kiosk"><i class="bi bi-lock-fill"></i><span class="sg-theme-label">Lock Kiosk</span></button>
            <?php elseif ($canManageKiosk): ?>
            <button type="button" class="btn sg-theme-toggle me-2" id="kioskAuthorizeBtn" title="Run this device as a kiosk"><i class="bi bi-display"></i><span class="sg-theme-label">Kiosk Mode</span></button>
            <?php endif; ?>
            <button type="button" class="btn sg-theme-toggle" id="sgThemeToggle" aria-label="Switch theme" title="Switch theme"><i class="bi bi-sun-fill"></i><span class="sg-theme-label">Light</span></button>
        </div>
    </header>
    <div class="sg-content sg-scan-content">

    <main class="sg-main">
        <input type="hidden" id="csrfToken" value="<?= e($csrf) ?>">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <span class="text-muted small">Primary authentication: <strong class="text-sg-primary">Barcode Scan</strong> &middot; Backup: Facial Recognition</span>
            </div>
            <div class="text-end">
                <div class="fw-bold" id="clockTime">--:--:-- --</div>
                <div class="text-muted small" id="clockDate">-</div>
            </div>
        </div>

        <div class="row g-3">
            <!-- STEP 1: Barcode Scanner (physical scanner device only — no webcam, no manual typing) -->
            <div class="col-lg-4">
                <div class="scanner-panel sg-kiosk-scan-panel" id="barcodePanelWrap">
                    <h6 class="mb-2">Student ID</h6>
                    <div class="sg-kiosk-scan-frame" id="barcodeFrame">
                        <i class="bi bi-upc-scan sg-kiosk-scan-icon" id="barcodeIcon"></i>
                        <div class="scan-corners d-none" id="barcodeCorners">
                            <span class="tl"></span><span class="tr"></span><span class="bl"></span><span class="br"></span>
                        </div>
                    </div>
                    <div class="sg-kiosk-scan-message">
                        <div class="sg-kiosk-scan-title">SCAN YOUR STUDENT ID</div>
                        <p class="sg-kiosk-scan-sub" id="barcodeHint">Please scan your student ID to verify your campus access.</p>
                    </div>
                    <label for="deviceScanInput" class="sg-kiosk-scan-id-label">SCANNED STUDENT ID</label>
                    <!-- Keyboard-wedge scanners send their barcode as keyboard events. This readonly
                         field is intentionally visible so the kiosk can show exactly what was received.
                         Students cannot type into it; scanner.js captures the HID keystrokes globally. -->
                    <input type="text" id="deviceScanInput" class="sg-kiosk-scan-id-input"
                           autocomplete="off" autocapitalize="off" spellcheck="false"
                           readonly aria-readonly="true" placeholder="Waiting for scan...">
                </div>
            </div>

            <!-- STEP 2: Backup Face Capture (hidden until needed) -->
            <div class="col-lg-4">
                <div class="scanner-panel" id="facePanelWrap">
                    <h6>Facial Recognition <span class="badge bg-warning text-dark ms-1">Automatic Backup</span></h6>
                    <div class="scanner-frame" id="faceFrame">
                        <video id="faceVideo" autoplay playsinline class="w-100 h-100 d-none" style="object-fit:cover;"></video>
                        <canvas id="faceCanvas" class="d-none"></canvas>
                        <div class="text-center text-white-50" id="faceIdle">
                            <i class="bi bi-camera-video-off fs-1 d-block mb-2"></i>
                            Camera opens automatically when facial fallback is available
                        </div>
                        <div class="scan-corners d-none" id="faceCorners">
                            <span class="tl"></span><span class="tr"></span><span class="bl"></span><span class="br"></span>
                        </div>
                    </div>
                    <p class="text-center small mt-2 mb-0" id="faceHint">&nbsp;</p>
                    <div class="sg-face-auto-note mt-2"><i class="bi bi-camera-video me-1"></i>No tap is required. The camera starts and verifies automatically when fallback is unlocked.</div>
                </div>
            </div>

            <!-- STEP 3: Verification Result -->
            <div class="col-lg-4">
                <div class="result-panel" id="resultPanel">
                    <div class="result-icon idle" id="resultIcon"><i class="bi bi-hourglass-split"></i></div>
                    <h6 class="text-uppercase mb-1" id="resultStepLabel">Verification Result</h6>
                    <h4 class="fw-bold mb-2" id="resultStatus">Awaiting Scan</h4>
                    <div id="resultDetails" class="text-white-50"></div>
                </div>
            </div>
        </div>

        <!-- Recent scans on this session -->
        <div class="sg-card mt-3">
            <h6 class="fw-bold mb-3">Recent Scans (This Session)</h6>
            <div class="table-responsive">
                <table class="table table-sg mb-0">
                    <thead><tr><th>Time</th><th>Student ID</th><th>Name</th><th>Method</th><th>Type</th><th>Status</th></tr></thead>
                    <tbody id="sessionScanBody">
                        <tr><td colspan="6" class="text-center text-muted py-3">No scans yet this session.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    </div>
</div>

<!-- face-api.js for backup facial recognition (self-hosted — see assets/vendor/face-api, assets/models) -->
<script src="<?= APP_URL ?>/assets/vendor/face-api/face-api.min.js"></script>
<script>
    // Lets the plain (non-PHP) scanner.js resolve asset URLs correctly even
    // when the app is deployed in a subfolder — mirrors how PHP pages already
    // compute APP_URL for their own <link>/<script> tags.
    window.SG_APP_URL = <?= json_encode(APP_URL) ?>;
</script>
<script>
    // Initial facial-fallback gate state, as of page load (server-side truth — see above).
    window.SG_INITIAL_FACE_GATE = {
        unlocked: <?= $initialFaceGateUnlocked ? 'true' : 'false' ?>,
        remaining: <?= (int) $initialFaceGateRemaining ?>,
        threshold: <?= (int) get_facial_fallback_threshold($pdo) ?>,
        faceAttempts: <?= (int) get_face_fail_count() ?>,
        faceMaxAttempts: <?= (int) SG_FACE_MAX_ATTEMPTS ?>
    };
</script>
<script>
    // Kiosk controls. Authorization/locking are enforced server-side (api/kiosk.php);
    // this only drives the buttons and lets a kiosk notice its own expiry.
    (function () {
        const csrf = document.getElementById('csrfToken').value;
        const authBtn = document.getElementById('kioskAuthorizeBtn');
        const lockBtn = document.getElementById('kioskLockBtn');
        if (authBtn) authBtn.addEventListener('click', () => {
            Swal.fire({
                title: 'Run this device as a kiosk?',
                text: 'This device will scan without a login and record transactions as "Kiosk Station". You will be signed out here.',
                icon: 'question', showCancelButton: true, confirmButtonText: 'Authorize Kiosk'
            }).then(r => {
                if (!r.isConfirmed) return;
                sgPost('../api/kiosk.php', { action: 'authorize', csrf_token: csrf }).then(res => {
                    if (res.success) { window.location.href = 'scanner.php'; }
                    else { Swal.fire('Could not authorize', res.message || 'Please try again.', 'error'); }
                });
            });
        });
        if (lockBtn) lockBtn.addEventListener('click', () => {
            sgPost('../api/kiosk.php', { action: 'revoke', csrf_token: csrf }).then(res => {
                if (res.success) { window.location.reload(); }
                else { Swal.fire('Could not lock kiosk', res.message || 'Please try again.', 'error'); }
            });
        });
        <?php if ($isKioskMode): ?>
        setInterval(() => {
            fetch('../api/kiosk.php?action=status', { cache: 'no-store' }).then(r => r.json()).then(res => {
                if (res && res.authorized === false) window.location.href = '../login.php?notice=kiosk_expired';
            }).catch(() => {});
        }, 30000);
        <?php endif; ?>
    })();
</script>
<script src="<?= APP_URL ?>/assets/js/scanner.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
