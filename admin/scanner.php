<?php
require_once __DIR__ . '/../database/config.php';
require_login();

$pageTitle = 'Scan Entry';
$pageHeading = 'Scan Entry';
$activePage = 'scanner';
$csrf = generate_csrf_token();
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sg-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

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
            <!-- STEP 1: Barcode Scanner -->
            <div class="col-lg-4">
                <div class="scanner-panel" id="barcodePanelWrap">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">1. Scan Barcode / QR Code</h6>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Scanner input mode">
                            <input type="radio" class="btn-check" name="scanMode" id="modeCamera" checked>
                            <label class="btn btn-outline-light" for="modeCamera" title="Use webcam"><i class="bi bi-camera-fill"></i></label>
                            <input type="radio" class="btn-check" name="scanMode" id="modeDevice">
                            <label class="btn btn-outline-light" for="modeDevice" title="Use physical scanner device"><i class="bi bi-upc-scan"></i></label>
                        </div>
                    </div>

                    <!-- Camera-based scanning (html5-qrcode) -->
                    <div id="cameraModeWrap" class="d-flex flex-column flex-grow-1">
                        <div class="scanner-frame" id="barcodeFrame">
                            <div id="qr-reader" style="width:100%;"></div>
                            <div class="scan-corners d-none" id="barcodeCorners">
                                <span class="tl"></span><span class="tr"></span><span class="bl"></span><span class="br"></span>
                            </div>
                        </div>
                        <p class="text-center small mt-2 mb-0" id="barcodeHint">Scan student ID barcode</p>
                        <div class="d-grid mt-2">
                            <button class="btn btn-outline-light btn-sm" id="startScannerBtn"><i class="bi bi-upc-scan me-1"></i>Start Scanner</button>
                        </div>
                    </div>

                    <!-- Physical USB / Bluetooth HID scanner input (keyboard-wedge mode) -->
                    <div id="deviceModeWrap" class="d-none">
                        <div class="scanner-frame d-flex flex-column align-items-center justify-content-center text-center p-3">
                            <i class="bi bi-usb-symbol fs-1 text-sg-accent mb-2"></i>
                            <p class="small text-white-50 mb-2">Plug in your USB/Bluetooth barcode scanner and scan a student ID.<br>No setup needed — the device types directly into the box below.</p>
                            <input type="text" id="deviceScanInput" class="form-control text-center" placeholder="Ready — scan now..." autocomplete="off">
                        </div>
                        <p class="text-center small mt-2 mb-0 text-white-50">Listening for physical scanner input</p>
                    </div>
                </div>
            </div>

            <!-- STEP 2: Backup Face Capture (hidden until needed) -->
            <div class="col-lg-4">
                <div class="scanner-panel" id="facePanelWrap">
                    <h6>2. Capture Face <span class="badge bg-warning text-dark ms-1">Backup Only</span></h6>
                    <div class="scanner-frame" id="faceFrame">
                        <video id="faceVideo" autoplay playsinline class="w-100 h-100 d-none" style="object-fit:cover;"></video>
                        <canvas id="faceCanvas" class="d-none"></canvas>
                        <div class="text-center text-white-50" id="faceIdle">
                            <i class="bi bi-camera-video-off fs-1 d-block mb-2"></i>
                            Waiting for barcode failure or manual trigger
                        </div>
                        <div class="scan-corners d-none" id="faceCorners">
                            <span class="tl"></span><span class="tr"></span><span class="bl"></span><span class="br"></span>
                        </div>
                    </div>
                    <p class="text-center small mt-2 mb-0" id="faceHint">&nbsp;</p>
                    <div class="d-grid gap-2 mt-2">
                        <button class="btn btn-warning btn-sm" id="useBackupFaceBtn">
                            <i class="bi bi-person-bounding-box me-1"></i>Use Backup Facial Recognition
                        </button>
                        <button class="btn btn-success btn-sm d-none" id="captureFaceBtn">Capture &amp; Verify</button>
                    </div>
                </div>
            </div>

            <!-- STEP 3: Verification Result -->
            <div class="col-lg-4">
                <div class="result-panel" id="resultPanel">
                    <div class="result-icon idle" id="resultIcon"><i class="bi bi-hourglass-split"></i></div>
                    <h6 class="text-uppercase text-white-50 mb-1">3. Verification Result</h6>
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
                    <thead><tr><th>Time</th><th>Student ID</th><th>Name</th><th>Method</th><th>Status</th></tr></thead>
                    <tbody id="sessionScanBody">
                        <tr><td colspan="5" class="text-center text-muted py-3">No scans yet this session.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- html5-qrcode barcode scanner library -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<!-- face-api.js for backup facial recognition -->
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/scanner.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
