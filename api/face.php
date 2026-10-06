<?php
/**
 * =====================================================================
 * FACIAL RECOGNITION API — BACKUP / SECONDARY AUTHENTICATION
 * =====================================================================
 * Runs in one of two server-authorized modes:
 *
 *   1. FALLBACK MODE (default — the thesis's core "backup" workflow):
 *      Only proceeds once the SERVER'S OWN session state (not anything
 *      sent by the client) confirms enough consecutive barcode scans
 *      have come back "not found" since the last successful verification
 *      — see is_facial_fallback_unlocked() in includes/security.php.
 *      Identity is UNKNOWN at this point, so it performs 1:N matching
 *      of the captured descriptor against every Active student.
 *
 *   2. CHALLENGE MODE (identity spot-check, guards against ID borrowing —
 *      "Student A's barcode + Student B's face"): a staff member can
 *      challenge the identity behind an entry that was JUST granted by
 *      barcode in this same session (see the "Verify Face" button that
 *      appears briefly on a granted result). This performs a 1:1 check
 *      against ONLY that specific student's stored descriptor. It is
 *      authorized purely from $_SESSION['sg_last_grant'], written by
 *      api/scan.php at the moment of grant and valid for a short window
 *      — a client cannot invoke a 1:1 check against an arbitrary
 *      student_id it weren't just actually granted to.
 *
 *      NOTE ON SCOPE: per the thesis design, facial recognition is a
 *      SECONDARY method and is never run automatically on every entry
 *      (that would make it primary, and would require a camera at every
 *      single scan). Challenge mode is therefore opt-in per transaction,
 *      triggered by staff when they want to confirm the ID wasn't
 *      borrowed — see CHANGELOG_UI_V16 for the reasoning.
 * =====================================================================
 */
define('SG_INTERNAL_CALL', true);
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/sms.php';
header('Content-Type: application/json');

// Same JSON safety net as api/scan.php (see includes/security.php).
sg_api_json_boot('face.php', 'A server error occurred during facial verification.');

if (!sg_scanner_access_ok($pdo)) {
    echo json_encode(['status' => 'error', 'reason_code' => 'unauthorized', 'reason' => 'Unauthorized. Please log in or ask an administrator to authorize this kiosk again.']);
    exit;
}

if (get_setting($pdo, 'enable_facial_recognition', '1') !== '1') {
    echo json_encode(['status' => 'denied', 'reason' => 'Facial recognition is disabled in Settings.']);
    exit;
}

$action = $_POST['action'] ?? '';
if (!in_array($action, ['verify_face', 'verify_face_challenge'], true)) {
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

$verifier = sg_current_verifier_label($pdo); // real Admin/Staff username, or "Kiosk Station"
$descriptorJson = $_POST['descriptor'] ?? '';
$incoming = json_decode($descriptorJson, true);

if (!is_array($incoming) || count($incoming) !== 128) {
    // Face descriptor extraction succeeded client-side but produced something
    // malformed — distinct from "no face detected", which the client never
    // sends a descriptor for at all.
    echo json_encode(['status' => 'denied', 'reason_code' => 'invalid_descriptor', 'reason' => 'Invalid facial data captured. Please try again.']);
    exit;
}

const SG_FACE_THRESHOLD = 0.6;

function sg_face_photo_url(?string $photo): ?string {
    return $photo ? APP_URL . '/uploads/student/' . rawurlencode(basename($photo)) : null;
}

function sg_euclidean_distance(array $a, array $b): float {
    $sum = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $diff = $a[$i] - $b[$i];
        $sum += $diff * $diff;
    }
    return sqrt($sum);
}

try {
    // =================================================================
    // CHALLENGE MODE — 1:1 spot-check against the student JUST granted
    // entry by barcode in this session (prevents ID borrowing).
    // =================================================================
    if ($action === 'verify_face_challenge') {
        $grant = $_SESSION['sg_last_grant'] ?? null;
        $windowSeconds = 30;
        if (!$grant || (time() - ($grant['at'] ?? 0)) > $windowSeconds) {
            echo json_encode(['status' => 'error', 'reason' => 'No recent granted entry available to verify. Challenge window has expired.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ? LIMIT 1");
        $stmt->execute([$grant['student_id']]);
        $target = $stmt->fetch();
        if (!$target || empty($target['face_encoding'])) {
            echo json_encode(['status' => 'error', 'reason' => 'No stored face on file for this student to compare against.']);
            exit;
        }

        $stored = json_decode($target['face_encoding'], true);
        if (!is_array($stored) || count($stored) !== 128) {
            echo json_encode(['status' => 'error', 'reason' => 'Stored facial data for this student is invalid.']);
            exit;
        }

        $distance = sg_euclidean_distance($incoming, $stored);
        if ($distance <= SG_FACE_THRESHOLD) {
            log_activity($pdo, 'Identity Verified', "Face spot-check confirmed {$target['fullname']} (ID: {$target['student_id']})");
            echo json_encode(['status' => 'match', 'reason' => 'Identity confirmed. Face matches the scanned ID.']);
        } else {
            log_activity($pdo, 'Identity Mismatch Flagged', "Barcode {$target['student_id']} ({$target['fullname']}) was used, but the captured face did NOT match the ID's registered photo. Possible ID borrowing — verified by {$verifier}.");
            create_notification($pdo, null, 'error', 'Identity Mismatch Flagged', "Barcode {$target['student_id']} was scanned, but the face presented does not match {$target['fullname']}'s registered face. Please verify in person.");
            echo json_encode(['status' => 'mismatch', 'reason' => 'IDENTITY MISMATCH — the face presented does not match this ID. Please verify the student in person.']);
        }
        exit;
    }

    // =================================================================
    // FALLBACK MODE — identity unknown, 1:N match (existing behavior)
    // =================================================================
    // SERVER-SIDE ENFORCEMENT of the "N failed barcode attempts" business rule.
    // The frontend hides the backup-face button until unlocked, but that is a UX
    // convenience only — this check is what actually prevents facial recognition
    // from being invoked before the barcode has genuinely failed enough times.
    if (!is_facial_fallback_unlocked($pdo)) {
        echo json_encode([
            'status' => 'denied',
            'reason_code' => 'fallback_locked',
            'reason' => 'Facial recognition is only available after ' . get_facial_fallback_threshold($pdo) . ' failed barcode attempts.',
            'attempts_remaining' => max(0, get_facial_fallback_threshold($pdo) - get_barcode_fail_streak()),
        ]);
        exit;
    }

    // DEBOUNCE (server side): two facial attempts can never be evaluated -- and
    // so can never be counted -- closer together than SG_FACE_MIN_ATTEMPT_INTERVAL,
    // even if a client submits camera frames in a tight loop. Not a failure.
    $nowTs = microtime(true);
    $lastTs = (float) ($_SESSION['face_last_attempt_at'] ?? 0);
    if ($lastTs > 0 && ($nowTs - $lastTs) < SG_FACE_MIN_ATTEMPT_INTERVAL) {
        echo json_encode([
            'status' => 'throttled',
            'reason_code' => 'face_debounce',
            'reason' => 'Please hold still for the next facial verification attempt.',
            'retry_after' => (int) ceil(SG_FACE_MIN_ATTEMPT_INTERVAL - ($nowTs - $lastTs)),
            'face_attempts' => get_face_fail_count(),
            'face_max_attempts' => SG_FACE_MAX_ATTEMPTS,
        ]);
        exit;
    }
    $_SESSION['face_last_attempt_at'] = $nowTs;

    $stmt = $pdo->query("SELECT * FROM students WHERE status = 'Active' AND face_encoding IS NOT NULL AND face_encoding != ''");
    $students = $stmt->fetchAll();

    $bestMatch = null;
    $bestDistance = PHP_FLOAT_MAX;

    foreach ($students as $s) {
        $stored = json_decode($s['face_encoding'], true);
        if (!is_array($stored) || count($stored) !== 128) continue;
        $distance = sg_euclidean_distance($incoming, $stored);
        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $bestMatch = $s;
        }
    }

    if (!$bestMatch || $bestDistance > SG_FACE_THRESHOLD) {
        // A face WAS detected and compared and did not match: this is the only
        // thing that counts as a failed facial attempt. After SG_FACE_MAX_ATTEMPTS
        // of them facial fallback locks again: the barcode streak is reset to 0
        // (which also clears the facial counter), so the kiosk must see the
        // configured number of NEW failed barcode scans before facial unlocks.
        $log = $pdo->prepare("INSERT INTO entry_logs (student_id, fullname, grade, time_in, verification_method, transaction_type, verified_by, status, reason)
                               VALUES (NULL, NULL, NULL, ?, 'Facial Recognition', 'Entry', ?, 'Denied', 'Face not recognized')");
        $log->execute([date('Y-m-d H:i:s'), $verifier]);

        $faceFails = register_face_failure();
        $faceLocked = $faceFails >= SG_FACE_MAX_ATTEMPTS;
        if ($faceLocked) {
            reset_barcode_fail_streak();
            log_activity($pdo, 'Facial Fallback Locked', 'Facial verification failed ' . SG_FACE_MAX_ATTEMPTS . ' times at this station; facial fallback locked until the barcode failure threshold is reached again.');
        }

        echo json_encode([
            'status' => 'denied',
            'reason_code' => 'no_match',
            'reason' => 'Identity could not be verified. Please try again or contact staff.',
            'face_attempts' => min($faceFails, SG_FACE_MAX_ATTEMPTS),
            'face_max_attempts' => SG_FACE_MAX_ATTEMPTS,
            'face_locked' => $faceLocked,
            'facial_fallback_unlocked' => !$faceLocked,
            'attempts_remaining' => max(0, get_facial_fallback_threshold($pdo) - get_barcode_fail_streak()),
        ]);
        exit;
    }

    // ---- Identity resolved via face: resolve Entry vs Exit / duplicate, same as barcode ----
    $locked = acquire_student_scan_lock($pdo, $bestMatch['student_id']);

    if (!$locked) {
        // BUGFIX (Phase 1): same fix as api/scan.php — an unacquired lock
        // must not fall through into unprotected duplicate/entry-exit/insert
        // logic. Fail safe and ask the caller to retry.
        echo json_encode([
            'status' => 'busy',
            'reason' => 'This student is currently being processed by another request. Please try again in a moment.',
            'student' => ['fullname' => $bestMatch['fullname'], 'student_id' => $bestMatch['student_id']],
        ]);
        exit;
    }

    try {
        if (is_duplicate_scan($pdo, $bestMatch['student_id'])) {
            // BUGFIX (Phase 1): explicit release before exit() — see the
            // matching comment in api/scan.php for why this is necessary
            // (exit() inside a try skips its finally block in PHP).
            release_student_scan_lock($pdo, $bestMatch['student_id']);
            $locked = false;
            reset_barcode_fail_streak(); // face WAS recognized -> fallback has done its job; also clears the facial counter
            echo json_encode([
                'status' => 'duplicate',
                'reason' => 'You have already successfully scanned. Access has already been granted. Please wait a moment.',
                'student' => ['fullname' => $bestMatch['fullname'], 'student_id' => $bestMatch['student_id']],
            ]);
            exit;
        }

        $state = get_student_access_state($pdo, $bestMatch['student_id']);
        if ($state === 'INSIDE') {
            $exitCheck = can_process_exit($pdo, $bestMatch['student_id']);
            if (!$exitCheck['allowed']) {
                // BUGFIX (Phase 1): explicit release before exit() — see
                // api/scan.php for details.
                release_student_scan_lock($pdo, $bestMatch['student_id']);
                $locked = false;
                reset_barcode_fail_streak(); // face WAS recognized -- same reasoning as 'duplicate' above
                $waitMinutes = max(1, (int) ceil($exitCheck['wait_seconds'] / 60));
                echo json_encode([
                    'status' => 'already_inside',
                    'reason' => "{$bestMatch['fullname']} is already inside. Exit can be processed in about {$waitMinutes} minute(s).",
                    'student' => ['fullname' => $bestMatch['fullname'], 'student_id' => $bestMatch['student_id']],
                    'wait_seconds' => $exitCheck['wait_seconds'],
                ]);
                exit;
            }
            $transactionType = 'Exit';
        } else {
            $transactionType = 'Entry';
        }

        $timeIn = date('Y-m-d H:i:s');
        $log = $pdo->prepare("INSERT INTO entry_logs (student_id, fullname, grade, time_in, verification_method, transaction_type, verified_by, status)
                               VALUES (?,?,?,?,'Facial Recognition',?,?, 'Match')");
        $log->execute([$bestMatch['student_id'], $bestMatch['fullname'], $bestMatch['grade'], $timeIn, $transactionType, $verifier]);
        $entryLogId = (int) $pdo->lastInsertId();

        // A successful facial verification also resets the barcode failure streak
        // (per the same rule as a successful barcode scan) so the next student
        // starts with a clean slate rather than an already-unlocked fallback.
        reset_barcode_fail_streak();

        $responseBody = json_encode([
            'status' => 'granted',
            'transaction_type' => $transactionType,
            'student' => [
                'fullname' => $bestMatch['fullname'],
                'grade' => $bestMatch['grade'],
                'section' => $bestMatch['section'],
                'student_id' => $bestMatch['student_id'],
                'photo_url' => sg_face_photo_url($bestMatch['photo']),
            ]
        ]);

        // PERFORMANCE (Phase 2.5): respond first, notify after — see the
        // matching comment in api/scan.php for why (SMS can block for
        // several seconds against a slow/unreachable provider).
        sg_send_response_then_continue($responseBody);

        // SMS is a notification, not part of verification — see api/scan.php.
        if (!empty($bestMatch['contact_number'])) {
            try {
                sg_notify_entry($pdo, $entryLogId, $bestMatch['contact_number'], $bestMatch['fullname'], date('h:i A', strtotime($timeIn)), $verifier, $transactionType);
            } catch (Throwable $smsError) {
                error_log(sprintf('face.php SMS notification failed (verification still granted): %s in %s on line %d', $smsError->getMessage(), $smsError->getFile(), $smsError->getLine()));
            }
        }
    } finally {
        if ($locked) release_student_scan_lock($pdo, $bestMatch['student_id']);
    }
} catch (Throwable $e) {
    echo json_encode(sg_api_exception_response($e, 'face.php', 'A server error occurred during facial verification.'));
}
