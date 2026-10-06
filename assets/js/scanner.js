/**
 * =====================================================================
 * SCANNER MODULE (V16 — kiosk cleanup + Entry/Exit + duplicate protection)
 * -----------------------------------------------------------------
 * WORKFLOW (must not be violated):
 *   1. Barcode scan (PHYSICAL SCANNER DEVICE ONLY — no webcam) is the
 *      PRIMARY authentication method. The device behaves as a keyboard
 *      (HID/keyboard-wedge): it "types" the barcode value into a hidden
 *      input and terminates with Enter. Students never see or touch a
 *      text field — the kiosk just says "SCAN YOUR STUDENT ID".
 *   2. Facial recognition unlocks ONLY after N CONSECUTIVE barcode scans
 *      come back "not found" (unrecognized barcode) since the last
 *      successful verification. The "Use Backup Facial Recognition"
 *      button stays hidden until then. N is configurable server-side
 *      (Settings → Scanner & Access) and is reported to the client via
 *      window.SG_INITIAL_FACE_GATE / the scan.php response.
 *   3. This is enforced SERVER-SIDE (see is_facial_fallback_unlocked()
 *      in includes/security.php, checked by api/face.php on every
 *      request) — the client-side gating here is a UX convenience only
 *      and cannot itself grant access to the facial endpoint.
 *   4. A scan against a barcode that IS found but belongs to an Inactive
 *      student does NOT count toward or reset the streak — identity is
 *      already known, so facial recognition wouldn't add anything.
 *   5. Any successful verification (barcode OR face) resets the streak
 *      to 0 for the next student.
 *   6. Facial recognition is NEVER triggered automatically after a
 *      successful barcode match. A staff-initiated "Verify Face" spot
 *      check is available for a short window after a grant (see
 *      CHALLENGE MODE below) — this is opt-in, not automatic, so it
 *      does not turn facial recognition into a primary/mandatory step.
 *
 * ENTRY / EXIT: the server resolves whether a granted verification is an
 * Entry or an Exit (based on the student's current state) and returns
 * transaction_type in the response — the client just displays it.
 *
 * DUPLICATE / ALREADY-INSIDE: the server may return status 'duplicate'
 * (same student scanned again within the cooldown) or 'already_inside'
 * (student is INSIDE and the minimum interval hasn't passed) instead of
 * 'granted' — neither of these represents a new transaction or SMS.
 * =====================================================================
 */
const API_SCAN = '../api/scan.php';
const API_FACE = '../api/face.php';
const API_KIOSK_STATUS = '../api/kiosk.php?action=status';
const csrfToken = document.getElementById('csrfToken').value;

// =====================================================================
// PHASE 3 — CASE B: kiosk cannot reach the Smart Gateway server.
// -----------------------------------------------------------------
// Per the Phase 3 architecture decision, this is deliberately NOT an
// offline verification/queue feature: no barcode/face matching happens
// here, no entry_logs/sms_logs row is ever created for these, and
// nothing is persisted client-side. This only detects that a request
// never reached the server (fetch() itself rejected — a normal
// Match/Denied/'error' JSON response means the server WAS reached, and
// keeps using showSystemError()/showDenied() exactly as before), shows
// a clearly distinct "SERVER UNAVAILABLE" state, and polls the existing
// read-only api/kiosk.php?action=status endpoint until the server
// responds again, then hands control straight back to normal scanning.
// =====================================================================
let sgServerUnavailable = false;
let sgReconnectPollTimer = null;

function sgIsNetworkFailure(err) {
    // fetch() rejects (TypeError) when the request never got a response at
    // all -- DNS failure, connection refused, timeout, offline. A server
    // response that happens to not be valid JSON is a different failure
    // and is not treated as "server unavailable".
    return err instanceof TypeError;
}

function sgShowServerUnavailable() {
    if (sgServerUnavailable) return; // already showing/polling
    sgServerUnavailable = true;
    clearAutoReset();

    setScanIconState('denied');
    document.getElementById('barcodeHint').textContent = 'Waiting to reconnect to the server...';
    document.getElementById('resultIcon').className = 'result-icon denied';
    document.getElementById('resultIcon').innerHTML = '<i class="bi bi-wifi-off"></i>';
    document.getElementById('resultStatus').textContent = 'SERVER UNAVAILABLE';
    document.getElementById('resultDetails').innerHTML = `
        <div class="fw-bold">The kiosk cannot reach the Smart Gateway server.</div>
        <div>No entry/exit was recorded and no ID was checked.</div>
        <div class="mt-2 small">Verification will resume automatically as soon as the connection is back. Please wait.</div>
        <div class="mt-2 small">${new Date().toLocaleTimeString()}</div>
    `;

    // No auto-reset timer while offline -- there is nothing useful to
    // reset back to, and it would just re-arm scanning against a server
    // that is still unreachable. Poll the lightweight status endpoint
    // instead and recover on its own once it succeeds.
    sgReconnectPollTimer = setInterval(sgTryReconnect, 5000);
}

function sgTryReconnect() {
    sgGet(API_KIOSK_STATUS)
        .then(() => sgClearServerUnavailable())
        .catch(() => { /* still down -- keep polling */ });
}

function sgClearServerUnavailable() {
    if (!sgServerUnavailable) return;
    sgServerUnavailable = false;
    clearInterval(sgReconnectPollTimer);
    sgReconnectPollTimer = null;
    // Same idle-reset sequence scheduleAutoReset() uses on a normal result
    // timeout -- returns the kiosk straight to "SCAN YOUR STUDENT ID".
    setIdleResult();
    resetDeviceScanInput();
    setScanIconState('idle');
    document.getElementById('barcodeHint').textContent = 'Connection restored. Please scan your student ID to verify your campus access.';
    // Facial fallback was unlocked when the connection dropped (camera was stopped then): reopen it.
    if (facialFallbackUnlocked && !faceStream && !faceProcessing) startFaceCapture();
}

// How long a completed result stays on screen before the kiosk
// automatically returns to "SCAN YOUR STUDENT ID" and clears the
// previous student's information (privacy — see item W/6 of the spec).
const AUTO_RESET_MS = 6000;
// How long after a barcode grant the "Verify Face" identity spot-check
// stays available — must match the server-side window in api/face.php.
const CHALLENGE_WINDOW_MS = 30000;

let faceModelsLoaded = false;
let faceStream = null;
let autoResetTimer = null;
let challengeTimer = null;

// ---- Phase 4: stable-face auto-capture state ----
// A face must be continuously detected for FACE_STABLE_HOLD_MS before we
// auto-submit — this avoids capturing a blink, a pass-by, or motion blur.
// faceProcessing is the double-submission guard: once true, neither the
// stability loop nor a stray manual click can start a second verification
// request while one is already in flight.
let faceProcessing = false;
let faceDetectionInterval = null;
let faceStableSince = null;
const FACE_STABLE_HOLD_MS = 900;       // how long a face must stay detected before auto-capture fires
const FACE_DETECTION_POLL_MS = 200;    // how often we check for a stable face
const FACE_STABILITY_TIMEOUT_MS = 15000; // if nothing stabilizes in this long, offer a manual fallback

// ---- Facial ATTEMPT counting (see performFaceCapture) ----
// Only "face detected AND compared against registered faces AND not recognized"
// is a failed attempt, and the SERVER owns the real count (api/face.php).
// faceFailCount is a display-only mirror of it. "No face in frame" never
// reaches the server, so it can never be counted.
let faceFailCount = (window.SG_INITIAL_FACE_GATE && window.SG_INITIAL_FACE_GATE.faceAttempts) || 0;
let faceMaxAttempts = (window.SG_INITIAL_FACE_GATE && window.SG_INITIAL_FACE_GATE.faceMaxAttempts) || 3;
let faceCooldownUntil = 0;               // debounce: no new attempt may start before this timestamp
let faceTimeoutTimer = null;
const FACE_RETRY_COOLDOWN_MS = 3000;     // pause after a counted failure before the next attempt may start

/** Face-panel status line. warn=true tints it; otherwise the current "n/max failed attempts" is appended while camera is retrying. */
function setFaceHint(message, warn) {
    const el = document.getElementById('faceHint');
    el.classList.toggle('text-warning', !!warn);
    el.textContent = (!warn && faceFailCount > 0 && facialFallbackUnlocked)
        ? `${message} (${faceFailCount}/${faceMaxAttempts} failed attempts)` : message;
}

// Mirrors the SERVER's barcode-failure-streak state (see api/scan.php /
// api/face.php + is_facial_fallback_unlocked() in includes/security.php).
// This is display-only — api/face.php independently re-checks the real
// session streak server-side, so this flag can't be used to bypass anything
// even if tampered with in devtools.
let facialFallbackUnlocked = false;
const facialFallbackThreshold = (window.SG_INITIAL_FACE_GATE && window.SG_INITIAL_FACE_GATE.threshold) || 3;

function updateFaceGateUI(unlocked, attemptsRemaining) {
    const wasUnlocked = facialFallbackUnlocked;
    facialFallbackUnlocked = !!unlocked;
    const idle = document.getElementById('faceIdle');
    if (facialFallbackUnlocked) {
        idle.innerHTML = `<i class="bi bi-camera-video fs-1 d-block mb-2"></i>` +
            `Facial fallback is available.<br><strong>Opening camera automatically...</strong>`;
        if (!wasUnlocked && !faceStream && !faceProcessing) {
            setTimeout(() => startFaceCapture(), 80);
        }
    } else {
        // Gate is locked: make sure no camera/loop is left running and the
        // countdown text (hidden while the camera was open) is visible again.
        if (faceStream) stopFaceCamera();
        faceFailCount = 0;
        faceCooldownUntil = 0;
        idle.classList.remove('d-none');
        const remaining = typeof attemptsRemaining === 'number' ? attemptsRemaining : facialFallbackThreshold;
        idle.innerHTML = `<i class="bi bi-camera-video-off fs-1 d-block mb-2"></i>Unlocks after ${remaining} more failed barcode scan${remaining === 1 ? '' : 's'}`;
    }
}

// ---- Live clock ----
function tickClock() {
    const now = new Date();
    document.getElementById('clockTime').textContent = now.toLocaleTimeString();
    document.getElementById('clockDate').textContent = now.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
}
setInterval(tickClock, 1000);
tickClock();

// Sync the facial-fallback gate with the server's actual session state at
// load time (see admin/scanner.php), instead of assuming a fresh streak.
if (window.SG_INITIAL_FACE_GATE) {
    updateFaceGateUI(window.SG_INITIAL_FACE_GATE.unlocked, window.SG_INITIAL_FACE_GATE.remaining);
}

// =====================================================================
// STEP 1: BARCODE SCANNING (PRIMARY) — physical scanner device only.
// Standard USB/Bluetooth HID scanners behave like keyboards: they send
// characters as key events and usually terminate the scan with Enter.
//
// IMPORTANT: Do not depend on a disabled/hidden input being the scanner
// target. We capture the keyboard-wedge stream at document level, mirror
// it into the visible READONLY Student ID field, and submit on Enter.
// This keeps the kiosk student-facing while making scanner input visible
// and testable.
// =====================================================================
const deviceInput = document.getElementById('deviceScanInput');
let scannerBuffer = '';
let lastScannerKeyAt = 0;
let scanProcessing = false;
const SCANNER_INTERKEY_TIMEOUT_MS = 750;

function focusDeviceInput() {
    if (!deviceInput || scanProcessing) return;
    if (document.activeElement !== deviceInput) deviceInput.focus({ preventScroll: true });
}

function resetDeviceScanInput() {
    if (!deviceInput) return;
    scanProcessing = false;
    scannerBuffer = '';
    lastScannerKeyAt = 0;
    deviceInput.value = '';
    deviceInput.placeholder = 'Waiting for scan...';
    focusDeviceInput();
}

function updateVisibleScanValue(value) {
    if (!deviceInput) return;
    deviceInput.value = value;
    deviceInput.placeholder = value ? '' : 'Waiting for scan...';
}

// Global listener: HID/keyboard-wedge scanners work even if focus moves away
// from the readonly display. The display remains readonly, so students cannot
// edit it directly; scanner.js writes the received value programmatically.
document.addEventListener('keydown', (e) => {
    if (scanProcessing) return;

    // Ignore browser/OS shortcuts and non-character keys other than Enter.
    if (e.ctrlKey || e.altKey || e.metaKey) return;

    // Do not hijack ordinary keyboard interaction with future form controls.
    // The readonly scanner display is the intentional exception.
    const target = e.target;
    const isEditableTarget = target && (
        target === deviceInput ||
        target.tagName === 'TEXTAREA' ||
        target.tagName === 'SELECT' ||
        target.isContentEditable
    );
    if (isEditableTarget && target !== deviceInput) return;

    const now = Date.now();
    if (scannerBuffer && now - lastScannerKeyAt > SCANNER_INTERKEY_TIMEOUT_MS) {
        scannerBuffer = '';
        updateVisibleScanValue('');
    }

    if (e.key === 'Enter') {
        e.preventDefault();
        const value = scannerBuffer.trim();
        if (value.length > 0) {
            scannerBuffer = '';
            lastScannerKeyAt = 0;
            updateVisibleScanValue(value);
            handleBarcodeResult(value);
        }
        return;
    }

    // Barcode characters are normally printable single-key values.
    if (e.key && e.key.length === 1) {
        e.preventDefault();
        scannerBuffer += e.key;
        lastScannerKeyAt = now;
        updateVisibleScanValue(scannerBuffer);
    }
});

// Keep the readonly display focused for normal HID behavior as well, while
// the global listener above means the scanner is not dependent on focus.
document.addEventListener('click', focusDeviceInput);
setInterval(focusDeviceInput, 1000);
focusDeviceInput();

function setScanIconState(state) {
    // state: 'idle' | 'scanning' | 'granted' | 'denied'
    const icon = document.getElementById('barcodeIcon');
    const corners = document.getElementById('barcodeCorners');
    const frame = document.getElementById('barcodeFrame');
    if (!icon) return;
    icon.className = 'bi sg-kiosk-scan-icon ' + (
        state === 'scanning' ? 'bi-arrow-repeat sg-kiosk-icon-busy' :
        state === 'granted' ? 'bi-check-circle-fill sg-kiosk-icon-granted' :
        state === 'denied' ? 'bi-x-circle-fill sg-kiosk-icon-denied' :
        'bi-upc-scan'
    );
    corners.classList.toggle('d-none', state !== 'scanning');
    frame.classList.toggle('scanning', state === 'scanning');
}

// Server-side session/kiosk authorization loss should return to login.
function sgHandleAuthLoss(res) {
    if (res && res.reason_code === 'unauthorized') {
        window.location.href = '../login.php?notice=kiosk_expired';
        return true;
    }
    return false;
}

function handleBarcodeResult(barcodeValue) {
    if (!barcodeValue || scanProcessing) return;
    scanProcessing = true;
    clearAutoReset();
    setScanIconState('scanning');
    document.getElementById('barcodeHint').textContent = 'Verifying your ID...';

    sgPost(API_SCAN, { action: 'verify_barcode', barcode: barcodeValue, csrf_token: csrfToken })
        .then(res => {
            if (sgHandleAuthLoss(res)) return;
            if (res.status === 'granted') {
                updateFaceGateUI(false);
                setScanIconState('granted');
                showGranted(res.student, 'Barcode', res.transaction_type);
            } else if (res.status === 'duplicate') {
                setScanIconState('denied');
                showDuplicate(res.reason, res.student);
            } else if (res.status === 'already_inside') {
                setScanIconState('denied');
                showAlreadyInside(res.reason, res.student, res.wait_seconds);
            } else if (res.status === 'denied_known') {
                updateFaceGateUI(res.facial_fallback_unlocked);
                setScanIconState('denied');
                showDenied(res.reason, res.student, res.facial_fallback_unlocked);
            } else if (res.status === 'throttled') {
                setScanIconState('idle');
                showThrottled(res.reason, res.retry_after);
            } else if (res.status === 'error') {
                // BACKEND FAILURE — NOT a failed barcode attempt.
                // The server did not increment the failure streak for this,
                // so the client must not touch the facial-fallback gate
                // either: a database/server problem must never consume one of
                // the student's three barcode attempts, nor unlock the backup
                // method on the strength of an error that says nothing about
                // whether the ID is valid.
                setScanIconState('denied');
                showSystemError(res.reason, res.debug);
            } else {
                setScanIconState('denied');
                showBarcodeNotFound(res.reason || 'Barcode not recognized.', res.facial_fallback_unlocked, res.attempts_remaining);
            }
        })
        .catch(err => {
            // Network drop or an unparseable response — same reasoning as the
            // 'error' branch above: this is not evidence of an invalid ID, so
            // it does not count as a failed barcode attempt.
            console.error('Barcode verification request failed:', err);
            if (sgIsNetworkFailure(err)) {
                // CASE B: the kiosk could not reach the server at all. No
                // transaction was created, nothing was verified — show the
                // dedicated offline state instead of a generic error, and
                // let sgShowServerUnavailable() own recovery from here.
                sgShowServerUnavailable();
                scanProcessing = false;
                return;
            }
            setScanIconState('denied');
            showSystemError('Connection error while verifying barcode. Please scan again.');
        })
        .finally(() => {
            if (!sgServerUnavailable) scheduleAutoReset();
        });
}

/**
 * Shown when verification could not be COMPLETED (server/database/network
 * failure) as opposed to completing with a denial. Deliberately does not call
 * updateFaceGateUI(), so the facial-fallback gate keeps whatever state the
 * server last reported and the 3-strike count is left intact.
 */
function showSystemError(reason, debug) {
    const message = reason || 'Verification service is temporarily unavailable.';
    document.getElementById('barcodeHint').textContent = message;
    document.getElementById('resultIcon').className = 'result-icon denied';
    document.getElementById('resultIcon').innerHTML = '<i class="bi bi-exclamation-triangle"></i>';
    document.getElementById('resultStatus').textContent = 'VERIFICATION UNAVAILABLE';
    document.getElementById('resultDetails').innerHTML = `
        <div class="fw-bold">System error:</div>
        <div>${message}</div>
        <div class="mt-2 small">This is not a failed scan — please try again or notify staff.</div>
        <div class="mt-2 small">${new Date().toLocaleTimeString()}</div>
    `;
    // debug is only ever populated by the server on a LOCAL environment.
    if (debug && debug.message) {
        console.error('Smart Gateway verification error:', debug.message, 'at', debug.file + ':' + debug.line, debug.fix || '');
    }
    // Not a student transaction — intentionally NOT added to the session log.
}

function showBarcodeNotFound(reason, unlocked, attemptsRemaining) {
    document.getElementById('barcodeHint').textContent = reason;
    updateFaceGateUI(unlocked, attemptsRemaining);
    showDenied(reason, null, unlocked);
}

// =====================================================================
// STEP 2: BACKUP FACIAL RECOGNITION (ONLY ON BARCODE FAILURE / MANUAL)
// =====================================================================
async function startFaceCapture() {
    faceCooldownUntil = 0;
    document.getElementById('faceIdle').classList.add('d-none');
    document.getElementById('faceCorners').classList.remove('d-none');
    document.getElementById('facePanelWrap').classList.add('scanning');

    // ---- A. CAMERA UNAVAILABLE (insecure context / API missing) ----
    if (!window.isSecureContext && !['localhost','127.0.0.1'].includes(location.hostname)) {
        return failFace('Camera unavailable. This page must be loaded over HTTPS or localhost.', false);
    }
    if (!navigator.mediaDevices?.getUserMedia) {
        return failFace('Camera unavailable. This browser does not provide camera access.', false);
    }

    try {
        // Request the camera first so camera permission/errors are reported accurately.
        faceStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'user' } }, audio: false });
        const video = document.getElementById('faceVideo');
        video.srcObject = faceStream;
        video.classList.remove('d-none');
        await video.play();
    } catch (err) {
        // ---- B. CAMERA PERMISSION DENIED / CAMERA UNAVAILABLE ----
        const name = err?.name || '';
        const message = name === 'NotAllowedError' ? 'Camera permission was denied. Please allow camera access and try again.'
            : name === 'NotFoundError' ? 'Camera unavailable. No camera was found on this device.'
            : name === 'NotReadableError' ? 'Camera unavailable. It may be in use by another application.'
            : 'Camera unavailable. Please check the camera connection or browser permission.';
        return failFace(message, false);
    }

    try {
        document.getElementById('faceHint').textContent = 'Preparing facial verification...';
        if (!faceModelsLoaded) {
            if (typeof faceapi === 'undefined') throw new Error('Face recognition library failed to load.');
            document.getElementById('faceHint').textContent = 'Loading facial recognition models...'; // MODEL LOADING
            const MODEL_URL = (window.SG_APP_URL || '') + '/assets/models'; // self-hosted (Phase 2) — was CDN
            try {
                await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
                await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
                await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
            } catch (modelErr) {
                // MODEL LOAD ERROR — do not continue as if recognition works.
                return failFace('Facial recognition models could not be loaded. Please check the connection to this device and try again, or contact staff.', false);
            }
            faceModelsLoaded = true; // MODEL READY
        }
        document.getElementById('faceHint').textContent = 'Position your face inside the guide.';
        startFaceStabilityLoop();
    } catch (err) {
        // ---- E. FACIAL RECOGNITION / MODEL ERROR ----
        return failFace('Facial recognition could not start. Please try again or contact staff.', true);
    }
}

/**
 * Polls the live video feed for a face using the SAME already-loaded
 * face-api.js models/pipeline used everywhere else in this file — no
 * second face-recognition pipeline, no duplicate model loading. Once a
 * face has been continuously detected for FACE_STABLE_HOLD_MS, it
 * automatically triggers performFaceCapture() — no "Take Photo" click
 * required. If detection takes longer, the camera remains active and the
 * interface continues waiting for a stable face automatically.
 */
function startFaceStabilityLoop() {
    stopFaceStabilityLoop(); // never run two loops at once
    faceStableSince = null;
    const video = document.getElementById('faceVideo');
    let timedOut = false;

    faceTimeoutTimer = setTimeout(() => {
        timedOut = true;
        if (!faceProcessing && faceStream) {
            setFaceHint('Camera is active. Position your face clearly inside the guide; verification will start automatically.');
        }
    }, FACE_STABILITY_TIMEOUT_MS);

    faceDetectionInterval = setInterval(async () => {
        if (faceProcessing || !faceStream) return; // duplicate-submission guard also protects the poll loop
        // DEBOUNCE: right after an attempt, keep the camera open but do not
        // start another attempt (and keep showing the last result/count).
        if (Date.now() < faceCooldownUntil) { faceStableSince = null; return; }
        let detection;
        try {
            detection = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions());
        } catch (err) {
            return; // transient detector hiccup — try again next tick, not a failure
        }
        if (faceProcessing || !faceStream) return; // an in-flight capture may have started while we awaited detection

        if (!detection) {
            // NO FACE DETECTED: NOT a failed attempt. The camera stays open
            // and we simply keep looking until a face actually appears.
            faceStableSince = null;
            if (!timedOut) setFaceHint('Position your face inside the guide.');
            return;
        }
        if (faceStableSince === null) {
            faceStableSince = Date.now();
            setFaceHint('Face detected. Hold still...');
            return;
        }
        if (Date.now() - faceStableSince >= FACE_STABLE_HOLD_MS) {
            stopFaceStabilityLoop();
            performFaceCapture();
        }
    }, FACE_DETECTION_POLL_MS);
}

function stopFaceStabilityLoop() {
    if (faceDetectionInterval) { clearInterval(faceDetectionInterval); faceDetectionInterval = null; }
    if (faceTimeoutTimer) { clearTimeout(faceTimeoutTimer); faceTimeoutTimer = null; }
    faceStableSince = null;
}

/**
 * Hand control back to the detection loop WITHOUT counting anything and
 * WITHOUT closing the camera. Used for every outcome that is not a real
 * "face compared and rejected" result (no face, detector error, rate limit,
 * server busy/error, debounce).
 */
function resumeFaceDetection(message, cooldownMs, warn) {
    faceProcessing = false;
    faceCooldownUntil = Date.now() + (cooldownMs || 0);
    if (!faceStream || !facialFallbackUnlocked) return; // camera/gate was closed meanwhile (e.g. barcode granted)
    setFaceHint(message, warn);
    startFaceStabilityLoop();
}

function failFace(message, keepCameraRunning) {
    faceProcessing = false;
    stopFaceStabilityLoop();
    document.getElementById('faceHint').textContent = message;
    sgToast('error', message);
    if (!keepCameraRunning) {
        stopFaceCamera();
        document.getElementById('faceIdle').innerHTML = '<i class="bi bi-camera-video-off fs-1 d-block mb-2"></i>Facial camera unavailable';
    }
    resetFaceButtons();
}

/**
 * The single face-capture-and-verify routine. Called automatically once a
 * face has been stably detected (see startFaceStabilityLoop()), with no manual tap required. Not a second
 * verification pipeline — this is the same capture/descriptor/API-call
 * logic that previously lived only in the button's click handler.
 */
async function performFaceCapture() {
    // ---- Double-submission guard: covers a stray click while the auto
    // trigger already fired, and a stray auto-trigger while a manual click
    // is already in flight. ----
    if (faceProcessing) return;
    faceProcessing = true;
    stopFaceStabilityLoop();
    clearAutoReset();

    const video = document.getElementById('faceVideo');
    const canvas = document.getElementById('faceCanvas');
    canvas.width = video.videoWidth || 320;
    canvas.height = video.videoHeight || 240;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    setFaceHint('Analyzing face...');

    let detection;
    try {
        detection = await faceapi.detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks().withFaceDescriptor();
    } catch (err) {
        // Detector/model error: a technical problem, NOT a failed verification.
        // Not counted; the camera stays open and the loop simply tries again.
        return resumeFaceDetection('Facial analysis hiccup. Please hold still...', 1000);
    }

    if (!detection) {
        // NO FACE (the person moved/left between the stable-face check and
        // the capture). NOT a failed attempt: nothing is sent to the server,
        // nothing is counted, no "Denied" row, and the camera stays open.
        return resumeFaceDetection('Position your face inside the guide.', 0);
    }

    // The camera is deliberately LEFT RUNNING during verification: it only
    // closes on success, on lockout, or when the barcode path resolves the scan.
    setFaceHint('Verifying identity...');

    const descriptor = Array.from(detection.descriptor);
    try {
        const res = await sgPost(API_FACE, { action: 'verify_face', descriptor: JSON.stringify(descriptor), csrf_token: csrfToken });
        if (sgHandleAuthLoss(res)) return;

        if (res.status === 'granted') {
            // ---- F. SUCCESSFUL MATCH ----
            // Server reset the barcode streak and the facial counter. Stop the
            // camera, lock the gate again and show the "Unlocks after N more
            // failed barcode scans" countdown in the face panel.
            stopFaceCamera();
            facialFallbackUnlocked = false;
            updateFaceGateUI(false, facialFallbackThreshold);
            setFaceHint('Verification successful. Facial fallback locked.');
            showGranted(res.student, 'Facial Recognition', res.transaction_type);
        } else if (res.status === 'duplicate' || res.status === 'already_inside') {
            // Face WAS recognized (identity resolved) — not a failure. The
            // server consumed the fallback window, so mirror that here.
            stopFaceCamera();
            facialFallbackUnlocked = false;
            updateFaceGateUI(false, facialFallbackThreshold);
            setFaceHint('Face recognized. Facial fallback locked.');
            if (res.status === 'duplicate') showDuplicate(res.reason, res.student);
            else showAlreadyInside(res.reason, res.student, res.wait_seconds);
        } else if (res.status === 'throttled') {
            // Rate limit / server-side debounce: NOT a failed attempt.
            if (res.reason_code !== 'face_debounce') showThrottled(res.reason, res.retry_after);
            resumeFaceDetection('Please hold still...', (res.retry_after || 3) * 1000);
        } else if (res.status === 'busy') {
            resumeFaceDetection('System busy. Retrying...', 1500); // not counted
        } else if (res.status === 'error') {
            // Backend failure on the face endpoint — a system error, not
            // "face not recognized". Not counted. Camera stays open; retry later.
            showSystemError(res.reason, res.debug);
            resumeFaceDetection('Verification service is temporarily unavailable.', 5000);
        } else if (res.status === 'denied' && res.reason_code === 'no_match') {
            // ---- D. FACE DETECTED, COMPARED, NOT RECOGNIZED = one real failed attempt ----
            faceFailCount = res.face_attempts || (faceFailCount + 1);
            faceMaxAttempts = res.face_max_attempts || faceMaxAttempts;
            const attemptMsg = `Facial verification failed — ${faceFailCount}/${faceMaxAttempts} attempts`;
            if (res.face_locked) {
                // ---- 3rd failure: lock facial, close camera, back to barcode ----
                stopFaceCamera();
                facialFallbackUnlocked = false;
                updateFaceGateUI(false, typeof res.attempts_remaining === 'number' ? res.attempts_remaining : facialFallbackThreshold);
                setFaceHint('Facial verification is temporarily locked. Please scan your student ID barcode.', true);
                document.getElementById('barcodeHint').textContent = 'Facial verification is temporarily locked. Please scan your student ID barcode.';
                showDenied(`${attemptMsg}. Facial verification is temporarily locked. Please scan your student ID barcode.`, null, false, 'Facial Recognition');
            } else {
                setFaceHint(attemptMsg + '. Adjust your position and hold still to retry.', true);
                showDenied(attemptMsg + '. Please try again.', null, false, 'Facial Recognition');
                resumeFaceDetection(attemptMsg + '. Adjust your position and hold still to retry.', FACE_RETRY_COOLDOWN_MS, true);
            }
        } else if (res.status === 'denied' && res.reason_code === 'invalid_descriptor') {
            resumeFaceDetection('Could not read your face clearly. Please hold still...', 1500); // not counted
        } else if (res.status === 'denied') {
            // Server refused facial verification outright (fallback not unlocked
            // server-side, or facial recognition disabled in Settings): close the
            // camera and resync the UI with the server instead of retrying.
            stopFaceCamera();
            facialFallbackUnlocked = false;
            updateFaceGateUI(false, typeof res.attempts_remaining === 'number' ? res.attempts_remaining : facialFallbackThreshold);
            setFaceHint('Facial verification is locked. Please scan your student ID barcode.', true);
            showDenied(res.reason || 'Facial verification is not available right now.', null, false, 'System');
        } else {
            resumeFaceDetection('Please hold still...', 5000); // unknown response: not counted
        }
    } catch (err) {
        console.error('Face verification request failed:', err);
        if (sgIsNetworkFailure(err)) {
            // CASE B: the server was never reached. Nothing was verified or
            // counted. Close the camera while offline; sgClearServerUnavailable()
            // reopens it once the connection is back.
            stopFaceCamera();
            sgShowServerUnavailable();
            return;
        }
        showSystemError('Connection error while verifying face. Please try again.');
        resumeFaceDetection('Verification service is temporarily unavailable.', 5000); // not counted
    } finally {
        resetFaceButtons();
        if (!sgServerUnavailable) scheduleAutoReset();
    }
}

function stopFaceCamera() {
    stopFaceStabilityLoop();
    document.getElementById('faceIdle').classList.remove('d-none'); // countdown/lock status visible again
    if (faceStream) { faceStream.getTracks().forEach(t => t.stop()); faceStream = null; }
    document.getElementById('faceVideo').classList.add('d-none');
    document.getElementById('faceCorners').classList.add('d-none');
    document.getElementById('facePanelWrap').classList.remove('scanning');
}

function resetFaceButtons() {
    faceProcessing = false;
    // Facial verification is automatic; there are no capture buttons to reset.
}


// =====================================================================
// IDENTITY SPOT-CHECK ("Verify Face" challenge — guards against a
// borrowed physical ID: Student A's barcode + Student B's face).
// Opt-in, staff-triggered, available briefly after a barcode grant.
// See api/face.php action=verify_face_challenge for the server-side
// 1:1 comparison and the 30s authorization window.
// =====================================================================
async function runIdentityChallenge() {
    const btn = document.getElementById('verifyFaceChallengeBtn');
    const out = document.getElementById('challengeResult');
    if (!btn || !out) return;
    if (!navigator.mediaDevices?.getUserMedia) {
        out.textContent = 'Camera unavailable for spot-check.';
        return;
    }
    btn.disabled = true;
    out.textContent = 'Opening camera for spot-check...';
    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'user' } }, audio: false });
        const video = document.createElement('video');
        video.autoplay = true; video.playsInline = true; video.muted = true;
        video.srcObject = stream;
        await video.play();
        await new Promise(r => setTimeout(r, 500)); // brief settle time
        if (!faceModelsLoaded) {
            out.textContent = 'Loading facial recognition models...'; // MODEL LOADING
            const MODEL_URL = (window.SG_APP_URL || '') + '/assets/models'; // self-hosted (Phase 2) — was CDN
            try {
                await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
                await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
                await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
            } catch (modelErr) {
                // MODEL LOAD ERROR — distinct from a camera problem; don't mislabel it as one.
                stream.getTracks().forEach(t => t.stop());
                out.textContent = 'Facial recognition models could not be loaded. Please try again or contact staff.';
                btn.disabled = false;
                return;
            }
            faceModelsLoaded = true; // MODEL READY
        }
        const detection = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks().withFaceDescriptor();
        stream.getTracks().forEach(t => t.stop());
        if (!detection) { out.textContent = 'No face detected. Please try again.'; btn.disabled = false; return; }

        const descriptor = Array.from(detection.descriptor);
        const res = await sgPost(API_FACE, { action: 'verify_face_challenge', descriptor: JSON.stringify(descriptor), csrf_token: csrfToken });
        if (sgHandleAuthLoss(res)) return;
        if (res.status === 'match') {
            out.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Identity confirmed.</span>';
        } else if (res.status === 'mismatch') {
            out.innerHTML = '<span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i>IDENTITY MISMATCH — verify in person.</span>';
        } else {
            out.textContent = res.reason || 'Could not complete spot-check.';
        }
    } catch (err) {
        if (stream) stream.getTracks().forEach(t => t.stop());
        out.textContent = 'Camera unavailable for spot-check.';
    }
    btn.classList.add('d-none');
}

// =====================================================================
// STEP 3: RESULT DISPLAY
// =====================================================================
function clearAutoReset() {
    if (autoResetTimer) { clearTimeout(autoResetTimer); autoResetTimer = null; }
    if (challengeTimer) { clearTimeout(challengeTimer); challengeTimer = null; }
}

function scheduleAutoReset() {
    clearAutoReset();
    autoResetTimer = setTimeout(() => {
        setIdleResult();
        resetDeviceScanInput();
        setScanIconState('idle');
        document.getElementById('barcodeHint').textContent = 'Please scan your student ID to verify your campus access.';
    }, AUTO_RESET_MS);
}

function setIdleResult() {
    document.getElementById('resultIcon').className = 'result-icon idle';
    document.getElementById('resultIcon').innerHTML = '<i class="bi bi-hourglass-split"></i>';
    document.getElementById('resultStatus').textContent = 'Awaiting Scan';
    // Privacy: clear the previous student's photo/name/ID from the visible kiosk.
    document.getElementById('resultDetails').innerHTML = '';
}

function studentPhotoMarkup(student) {
    if (student && student.photo_url) {
        return `<img src="${sgEscapeHtml(student.photo_url)}" alt="${sgEscapeHtml(student.fullname)}" class="sg-kiosk-result-photo">`;
    }
    return `<div class="sg-kiosk-result-photo sg-kiosk-result-photo-fallback"><i class="bi bi-person-fill"></i></div>`;
}

function showGranted(student, method, transactionType) {
    const isExit = transactionType === 'Exit';
    document.getElementById('resultIcon').className = 'result-icon granted';
    document.getElementById('resultIcon').innerHTML = '<i class="bi bi-check-lg"></i>';
    document.getElementById('resultStatus').textContent = isExit ? 'EXIT VERIFIED' : 'ENTRY VERIFIED';
    document.getElementById('resultDetails').innerHTML = `
        ${studentPhotoMarkup(student)}
        <div class="fw-bold text-white fs-5 mt-2">${sgEscapeHtml(student.fullname)}</div>
        <div>Student ID: ${sgEscapeHtml(student.student_id)}</div>
        <div>${sgEscapeHtml(student.grade || '')}${student.section ? ' - Section ' + sgEscapeHtml(student.section) : ''}</div>
        <div class="mt-2 small text-sg-accent">
            <i class="bi bi-check-circle-fill me-1"></i>${method === 'Barcode' ? 'ID VERIFIED' : 'FACE VERIFIED'}
        </div>
        <div class="badge ${isExit ? 'bg-info' : 'bg-success'} mt-2">${isExit ? 'EXIT GRANTED' : 'ACCESS GRANTED'}</div>
        <div class="mt-2 small text-white-50">Verified via ${method} &middot; ${new Date().toLocaleTimeString()}</div>
        ${method === 'Barcode' ? `
        <div class="mt-2 sg-kiosk-challenge">
            <button type="button" class="btn btn-outline-light btn-sm" id="verifyFaceChallengeBtn">
                <i class="bi bi-person-bounding-box me-1"></i>Verify Face (spot-check)
            </button>
            <div class="small mt-1" id="challengeResult"></div>
        </div>` : ''}
    `;
    prependSessionRow(student.student_id, student.fullname, method, transactionType, 'Match'); // prependSessionRow() escapes internally
    sgToast('success', `${isExit ? 'Exit' : 'Access'} granted: ${student.fullname}`);

    const challengeBtn = document.getElementById('verifyFaceChallengeBtn');
    if (challengeBtn) {
        challengeBtn.addEventListener('click', runIdentityChallenge);
        // The server-side spot-check window is 30s; hide the option once it's expired
        // so staff never see an option that would just error out.
        challengeTimer = setTimeout(() => challengeBtn.classList.add('d-none'), CHALLENGE_WINDOW_MS);
    }
}

function showDenied(reason, student, eligibleForBackup = false, methodLabel = null) {
    document.getElementById('resultIcon').className = 'result-icon denied';
    document.getElementById('resultIcon').innerHTML = '<i class="bi bi-x-lg"></i>';
    document.getElementById('resultStatus').textContent = 'ACCESS DENIED';
    document.getElementById('resultDetails').innerHTML = `
        <div class="fw-bold">Reason:</div>
        <div>${reason}</div>
        ${eligibleForBackup ? '<div class="mt-2 small text-warning">You may use backup facial recognition.</div>' : ''}
        <div class="mt-2 small">${new Date().toLocaleTimeString()}</div>
    `;
    prependSessionRow('-', student ? student.fullname : 'Unknown', methodLabel || (eligibleForBackup ? 'Barcode' : 'System'), '-', 'Denied'); // prependSessionRow() escapes internally
}

function showDuplicate(reason, student) {
    document.getElementById('resultIcon').className = 'result-icon idle';
    document.getElementById('resultIcon').innerHTML = '<i class="bi bi-check2-circle"></i>';
    document.getElementById('resultStatus').textContent = 'ALREADY SCANNED';
    document.getElementById('resultDetails').innerHTML = `
        ${student ? `<div class="fw-bold text-white">${sgEscapeHtml(student.fullname)}</div>` : ''}
        <div class="mt-1">${reason}</div>
    `;
    // Not a new transaction — intentionally NOT added to the session log.
}

function showAlreadyInside(reason, student, waitSeconds) {
    document.getElementById('resultIcon').className = 'result-icon idle';
    document.getElementById('resultIcon').innerHTML = '<i class="bi bi-door-open"></i>';
    document.getElementById('resultStatus').textContent = 'ALREADY INSIDE';
    const waitMin = Math.max(1, Math.ceil((waitSeconds || 0) / 60));
    document.getElementById('resultDetails').innerHTML = `
        ${student ? `<div class="fw-bold text-white">${sgEscapeHtml(student.fullname)}</div>` : ''}
        <div class="mt-1">${reason}</div>
        <div class="small mt-2 text-white-50">Try again in about ${waitMin} minute(s) to exit.</div>
    `;
    // Not a new transaction — intentionally NOT added to the session log.
}

function showThrottled(reason, retryAfter) {
    document.getElementById('resultIcon').className = 'result-icon idle';
    document.getElementById('resultIcon').innerHTML = '<i class="bi bi-hourglass-bottom"></i>';
    document.getElementById('resultStatus').textContent = 'PLEASE WAIT';
    const waitText = retryAfter ? ` Try again in about ${retryAfter} seconds.` : '';
    document.getElementById('resultDetails').innerHTML = `
        <div class="fw-bold">${reason || 'Too many attempts. Please wait a moment.'}</div>
        <div class="mt-2 small">${waitText}</div>
    `;
    sgToast('warning', reason || 'Too many attempts. Please wait a moment.');
}

function prependSessionRow(studentId, name, method, transactionType, status) {
    const tbody = document.getElementById('sessionScanBody');
    if (tbody.children.length === 1 && tbody.children[0].children.length === 1) {
        tbody.innerHTML = ''; // remove "no scans yet" placeholder
    }
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>${new Date().toLocaleTimeString()}</td>
        <td>${sgEscapeHtml(studentId)}</td>
        <td>${sgEscapeHtml(name)}</td>
        <td>${sgEscapeHtml(method)}</td>
        <td>${transactionType && transactionType !== '-' ? sgEscapeHtml(transactionType) : '-'}</td>
        <td>${status === 'Match' ? '<span class="badge-match">Match</span>' : `<span class="badge-denied">Denied</span>`}</td>
    `;
    tbody.prepend(row);
}
