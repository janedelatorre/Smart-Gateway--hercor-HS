<?php
/**
 * =====================================================================
 * FACIAL RECOGNITION API — BACKUP AUTHENTICATION ONLY
 * =====================================================================
 * This endpoint must ONLY be invoked by the scanner UI after:
 *   - the barcode scanner failed to start/read, OR
 *   - the scanned barcode did not match any student, OR
 *   - staff manually clicked "Use Backup Facial Recognition".
 *
 * It performs 1:N matching of the captured face-api.js descriptor
 * (128-d vector) against all Active students' stored face_encoding
 * using Euclidean distance. A distance below the threshold (0.6,
 * the standard face-api.js recommendation) is considered a match.
 * =====================================================================
 */
define('SG_INTERNAL_CALL', true);
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/sms.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'reason' => 'Unauthorized. Please log in again.']);
    exit;
}

if (get_setting($pdo, 'enable_facial_recognition', '1') !== '1') {
    echo json_encode(['status' => 'denied', 'reason' => 'Facial recognition is disabled in Settings.']);
    exit;
}

$action = $_POST['action'] ?? '';
if ($action !== 'verify_face') {
    echo json_encode(['status' => 'error', 'reason' => 'Unknown action.']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['status' => 'error', 'reason' => 'Invalid session token.']);
    exit;
}

record_verification_attempt($pdo, 'face');
$throttle = is_verification_throttled($pdo, 'face');
if ($throttle['throttled']) {
    log_activity($pdo, 'Verification Throttled', 'Facial recognition endpoint rate-limited for IP ' . get_client_ip());
    echo json_encode(['status' => 'throttled', 'reason' => 'Too many facial recognition attempts. Please wait a moment and try again.', 'retry_after' => $throttle['retry_after']]);
    exit;
}

$verifier = $_SESSION['username'] ?? 'Staff';
$descriptorJson = $_POST['descriptor'] ?? '';
$incoming = json_decode($descriptorJson, true);

if (!is_array($incoming) || count($incoming) !== 128) {
    echo json_encode(['status' => 'denied', 'reason' => 'Invalid facial data captured. Please try again.']);
    exit;
}

$THRESHOLD = 0.6;

try {
    $stmt = $pdo->query("SELECT * FROM students WHERE status = 'Active' AND face_encoding IS NOT NULL AND face_encoding != ''");
    $students = $stmt->fetchAll();

    $bestMatch = null;
    $bestDistance = PHP_FLOAT_MAX;

    foreach ($students as $s) {
        $stored = json_decode($s['face_encoding'], true);
        if (!is_array($stored) || count($stored) !== 128) continue;

        $sum = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $diff = $incoming[$i] - $stored[$i];
            $sum += $diff * $diff;
        }
        $distance = sqrt($sum);

        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $bestMatch = $s;
        }
    }

    if ($bestMatch && $bestDistance <= $THRESHOLD) {
        $timeIn = date('Y-m-d H:i:s');
        $log = $pdo->prepare("INSERT INTO entry_logs (student_id, fullname, grade, time_in, verification_method, verified_by, status)
                               VALUES (?,?,?,?,'Facial Recognition',?, 'Match')");
        $log->execute([$bestMatch['student_id'], $bestMatch['fullname'], $bestMatch['grade'], $timeIn, $verifier]);
        $entryLogId = (int) $pdo->lastInsertId();

        if (!empty($bestMatch['contact_number'])) {
            sg_notify_entry($pdo, $entryLogId, $bestMatch['contact_number'], $bestMatch['fullname'], date('h:i A', strtotime($timeIn)), $verifier);
        }

        echo json_encode([
            'status' => 'granted',
            'student' => [
                'fullname' => $bestMatch['fullname'],
                'grade' => $bestMatch['grade'],
                'section' => $bestMatch['section'],
                'student_id' => $bestMatch['student_id'],
            ]
        ]);
    } else {
        $log = $pdo->prepare("INSERT INTO entry_logs (student_id, fullname, grade, time_in, verification_method, verified_by, status, reason)
                               VALUES (NULL, NULL, NULL, NOW(), 'Facial Recognition', ?, 'Denied', 'Face not recognized')");
        $log->execute([$verifier]);

        echo json_encode(['status' => 'denied', 'reason' => 'Face does not match any registered student.']);
    }
} catch (Throwable $e) {
    error_log('face.php error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'reason' => 'A server error occurred during facial verification.']);
}
