/**
 * =====================================================================
 * SCANNER MODULE
 * -----------------------------------------------------------------
 * WORKFLOW (must not be violated):
 *   1. Barcode scan is the PRIMARY authentication method.
 *   2. Facial recognition is executed ONLY when:
 *        a) the barcode scanner fails to read, OR
 *        b) the decoded barcode does not match any student, OR
 *        c) staff manually clicks "Use Backup Facial Recognition".
 *   3. Facial recognition is NEVER triggered after a successful
 *      barcode match.
 * =====================================================================
 */
const API_SCAN = '../api/scan.php';
const API_FACE = '../api/face.php';
const csrfToken = document.getElementById('csrfToken').value;

let html5QrCode = null;
let scannerRunning = false;
let faceModelsLoaded = false;
let faceStream = null;

// ---- Live clock ----
function tickClock() {
    const now = new Date();
    document.getElementById('clockTime').textContent = now.toLocaleTimeString();
    document.getElementById('clockDate').textContent = now.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
}
setInterval(tickClock, 1000);
tickClock();

// =====================================================================
// STEP 1: BARCODE SCANNING (PRIMARY)
// Two interchangeable input modes: (a) webcam via html5-qrcode,
// (b) physical USB/Bluetooth HID scanner (keyboard-wedge emulation).
// Both funnel into the SAME handleBarcodeResult() -> api/scan.php call,
// so no backend changes are needed to support a physical device.
// =====================================================================
const deviceInput = document.getElementById('deviceScanInput');

document.getElementById('modeCamera').addEventListener('change', () => switchScanMode('camera'));
document.getElementById('modeDevice').addEventListener('change', () => switchScanMode('device'));

function switchScanMode(mode) {
    const cameraWrap = document.getElementById('cameraModeWrap');
    const deviceWrap = document.getElementById('deviceModeWrap');

    if (mode === 'device') {
        stopScanner(); // release the webcam so the device doesn't compete for the panel
        cameraWrap.classList.add('d-none');
        cameraWrap.classList.remove('d-flex', 'flex-column', 'flex-grow-1');
        deviceWrap.classList.remove('d-none');
        deviceWrap.classList.add('d-flex', 'flex-column', 'flex-grow-1');
        focusDeviceInput();
    } else {
        deviceWrap.classList.add('d-none');
        deviceWrap.classList.remove('d-flex', 'flex-column', 'flex-grow-1');
        cameraWrap.classList.remove('d-none');
        cameraWrap.classList.add('d-flex', 'flex-column', 'flex-grow-1');
    }
}

function focusDeviceInput() {
    // Keep the hidden text field focused so the HID scanner's keystrokes land in it,
    // even if the staff member clicks elsewhere on the page by accident.
    if (!document.getElementById('modeDevice').checked) return;
    deviceInput.focus();
}

// Physical scanners "type" the barcode value then send Enter as a terminator.
deviceInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        const value = deviceInput.value.trim();
        deviceInput.value = '';
        if (value.length > 0) {
            handleBarcodeResult(value);
        }
    }
});

// Re-focus automatically after every click/result so the next scan is captured immediately
document.addEventListener('click', focusDeviceInput);
setInterval(focusDeviceInput, 1500);

document.getElementById('startScannerBtn').addEventListener('click', () => {
    if (scannerRunning) { stopScanner(); return; }
    startScanner();
});

function startScanner() {
    html5QrCode = new Html5Qrcode('qr-reader');
    document.getElementById('barcodeCorners').classList.remove('d-none');
    document.getElementById('barcodePanelWrap').classList.add('scanning');
    setIdleResult();

    html5QrCode.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 240, height: 140 } },
        (decodedText) => {
            document.getElementById('barcodeHint').textContent = `Scanned: ${decodedText}`;
            handleBarcodeResult(decodedText);
        },
        () => { /* scan failure per-frame is normal, ignore */ }
    ).then(() => {
        scannerRunning = true;
        document.getElementById('startScannerBtn').innerHTML = '<i class="bi bi-stop-circle me-1"></i>Stop Scanner';
    }).catch(() => {
        sgToast('error', 'Unable to access camera for barcode scanning.');
        // Barcode scanner failed entirely -> allow backup facial recognition immediately
        showBarcodeNotFound('Camera unavailable — scanner failed to start.');
    });
}

function stopScanner() {
    if (html5QrCode && scannerRunning) {
        html5QrCode.stop().then(() => {
            scannerRunning = false;
            document.getElementById('startScannerBtn').innerHTML = '<i class="bi bi-upc-scan me-1"></i>Start Scanner';
            document.getElementById('barcodeCorners').classList.add('d-none');
            document.getElementById('barcodePanelWrap').classList.remove('scanning');
        });
    }
}

function handleBarcodeResult(barcodeValue) {
    stopScanner();
    sgPost(API_SCAN, { action: 'verify_barcode', barcode: barcodeValue, csrf_token: csrfToken })
        .then(res => {
            if (res.status === 'granted') {
                showGranted(res.student, 'Barcode');
            } else if (res.status === 'denied_known') {
                // Barcode matched a real student, but access is denied (e.g., inactive) — no backup needed
                showDenied(res.reason, res.student);
            } else if (res.status === 'throttled') {
                showThrottled(res.reason, res.retry_after);
            } else {
                // Barcode not found / invalid -> offer backup facial recognition
                showBarcodeNotFound(res.reason || 'Barcode not recognized.');
            }
            focusDeviceInput(); // ready for the next physical scan immediately
        })
        .catch(() => {
            showBarcodeNotFound('Connection error while verifying barcode.');
            focusDeviceInput();
        });
}

function showBarcodeNotFound(reason) {
    document.getElementById('barcodeHint').textContent = reason;
    showDenied(reason, null, true); // true = eligible for backup
}

// =====================================================================
// STEP 2: BACKUP FACIAL RECOGNITION (ONLY ON BARCODE FAILURE / MANUAL)
// =====================================================================
document.getElementById('useBackupFaceBtn').addEventListener('click', async () => {
    stopScanner();
    await startFaceCapture();
});

async function startFaceCapture() {
    document.getElementById('faceIdle').classList.add('d-none');
    document.getElementById('faceCorners').classList.remove('d-none');
    document.getElementById('facePanelWrap').classList.add('scanning');
    document.getElementById('faceHint').textContent = 'Loading face verification models...';

    try {
        if (!faceModelsLoaded) {
            const MODEL_URL = 'https://justadudewhohacks.github.io/face-api.js/models';
            await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
            await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
            await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
            faceModelsLoaded = true;
        }
        faceStream = await navigator.mediaDevices.getUserMedia({ video: true });
        const video = document.getElementById('faceVideo');
        video.srcObject = faceStream;
        video.classList.remove('d-none');
        document.getElementById('faceHint').textContent = 'Position face within frame';
        document.getElementById('useBackupFaceBtn').classList.add('d-none');
        document.getElementById('captureFaceBtn').classList.remove('d-none');
    } catch (err) {
        document.getElementById('faceHint').textContent = 'Camera unavailable for facial recognition.';
        sgToast('error', 'Unable to access camera for facial recognition.');
    }
}

document.getElementById('captureFaceBtn').addEventListener('click', async () => {
    const video = document.getElementById('faceVideo');
    const canvas = document.getElementById('faceCanvas');
    canvas.width = video.videoWidth || 320;
    canvas.height = video.videoHeight || 240;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    document.getElementById('faceHint').textContent = 'Analyzing face...';

    const detection = await faceapi.detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions())
        .withFaceLandmarks().withFaceDescriptor();

    stopFaceCamera();

    if (!detection) {
        document.getElementById('faceHint').textContent = 'No face detected. Try again.';
        showDenied('Face not detected', null, true);
        resetFaceButtons();
        return;
    }

    const descriptor = Array.from(detection.descriptor);
    sgPost(API_FACE, { action: 'verify_face', descriptor: JSON.stringify(descriptor), csrf_token: csrfToken })
        .then(res => {
            if (res.status === 'granted') {
                showGranted(res.student, 'Facial Recognition');
            } else if (res.status === 'throttled') {
                showThrottled(res.reason, res.retry_after);
            } else {
                showDenied(res.reason || 'Face does not match any active student.', null);
            }
            resetFaceButtons();
        });
});

function stopFaceCamera() {
    if (faceStream) { faceStream.getTracks().forEach(t => t.stop()); faceStream = null; }
    document.getElementById('faceVideo').classList.add('d-none');
    document.getElementById('faceCorners').classList.add('d-none');
    document.getElementById('facePanelWrap').classList.remove('scanning');
}

function resetFaceButtons() {
    document.getElementById('captureFaceBtn').classList.add('d-none');
    document.getElementById('useBackupFaceBtn').classList.remove('d-none');
    document.getElementById('faceIdle').classList.remove('d-none');
}

// =====================================================================
// STEP 3: RESULT DISPLAY
// =====================================================================
function setIdleResult() {
    document.getElementById('resultIcon').className = 'result-icon idle';
    document.getElementById('resultIcon').innerHTML = '<i class="bi bi-hourglass-split"></i>';
    document.getElementById('resultStatus').textContent = 'Awaiting Scan';
    document.getElementById('resultDetails').innerHTML = '';
}

function showGranted(student, method) {
    document.getElementById('resultIcon').className = 'result-icon granted';
    document.getElementById('resultIcon').innerHTML = '<i class="bi bi-check-lg"></i>';
    document.getElementById('resultStatus').textContent = 'ACCESS GRANTED';
    document.getElementById('resultDetails').innerHTML = `
        <div class="fw-bold text-white fs-5">${student.fullname}</div>
        <div>${student.grade}${student.section ? ' - Section ' + student.section : ''}</div>
        <div>Student ID: ${student.student_id}</div>
        <div class="mt-2 small">Verified via ${method} &middot; ${new Date().toLocaleTimeString()}</div>
    `;
    prependSessionRow(student.student_id, student.fullname, method, 'Match');
    sgToast('success', `Access granted: ${student.fullname}`);
}

function showDenied(reason, student, eligibleForBackup = false) {
    document.getElementById('resultIcon').className = 'result-icon denied';
    document.getElementById('resultIcon').innerHTML = '<i class="bi bi-x-lg"></i>';
    document.getElementById('resultStatus').textContent = 'ACCESS DENIED';
    document.getElementById('resultDetails').innerHTML = `
        <div class="fw-bold">Reason:</div>
        <div>${reason}</div>
        ${eligibleForBackup ? '<div class="mt-2 small text-warning">You may use backup facial recognition.</div>' : ''}
        <div class="mt-2 small">${new Date().toLocaleTimeString()}</div>
    `;
    prependSessionRow('-', student ? student.fullname : 'Unknown', eligibleForBackup ? 'Barcode' : 'System', 'Denied');
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

function prependSessionRow(studentId, name, method, status) {
    const tbody = document.getElementById('sessionScanBody');
    if (tbody.children.length === 1 && tbody.children[0].children.length === 1) {
        tbody.innerHTML = ''; // remove "no scans yet" placeholder
    }
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>${new Date().toLocaleTimeString()}</td>
        <td>${studentId}</td>
        <td>${name}</td>
        <td>${method}</td>
        <td>${status === 'Match' ? '<span class="badge-match">Match</span>' : '<span class="badge-denied">Denied</span>'}</td>
    `;
    tbody.prepend(row);
}
