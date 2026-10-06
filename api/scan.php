<?php
/**
 * =====================================================================
 * BARCODE SCAN API — PRIMARY AUTHENTICATION
 * =====================================================================
 * Workflow:
 *   1. Look up the scanned barcode value against students.student_id
 *   2. Not found            -> status: denied_unknown (client may offer backup face)
 *   3. Found but Inactive   -> status: denied_known (no backup needed; identity is known)
 *   4. Found and Active     -> resolve ENTRY vs EXIT vs duplicate/interval,
 *                              record the transaction, send SMS, respond.
 *
 * ENTRY / EXIT STATE MACHINE (V16):
 *   A student's current state (INSIDE / OUTSIDE) is derived from their most
 *   recent granted transaction (see get_student_access_state()) rather than
 *   stored separately. A valid verification while OUTSIDE always produces
 *   an Entry transaction; while INSIDE it produces an Exit transaction —
 *   but only once the configured minimum interval has passed. Scans that
 *   arrive faster than the duplicate-scan cooldown, or while still inside
 *   that minimum interval, are NOT written as new transactions and do NOT
 *   trigger another SMS (see is_duplicate_scan() / can_process_exit()).
 * =====================================================================
 */
define('SG_INTERNAL_CALL', true);
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/sms.php';
header('Content-Type: application/json');

// Guarantee a single well-formed JSON body even if PHP emits a warning or
// hits an uncatchable fatal error — the kiosk parses this with res.json().
sg_api_json_boot('scan.php', 'A server error occurred during verification.');

if (!sg_scanner_access_ok($pdo)) {
    echo json_encode(['status' => 'error', 'reason_code' => 'unauthorized', 'reason' => 'Unauthorized. Please log in or ask an administrator to authorize this kiosk again.']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action !== 'verify_barcode') {
    echo json_encode(['status' => 'error', 'reason' => 'Unknown action.']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['status' => 'error', 'reason' => 'Invalid session token.']);
    exit;
}

record_verification_attempt($pdo, 'scan');
$throttle = is_verification_throttled($pdo, 'scan');
if ($throttle['throttled']) {
    log_activity($pdo, 'Verification Throttled', 'Barcode scan endpoint rate-limited for IP ' . get_client_ip());
    echo json_encode(['status' => 'throttled', 'reason' => 'Too many scan attempts. Please wait a moment and try again.', 'retry_after' => $throttle['retry_after']]);
    exit;
}

$barcode = trim($_POST['barcode'] ?? '');
$verifier = sg_current_verifier_label($pdo); // real Admin/Staff username, or "Kiosk Station"

function sg_student_photo_url(?string $photo): ?string {
    return $photo ? APP_URL . '/uploads/student/' . rawurlencode(basename($photo)) : null;
}

if ($barcode === '') {
    // An empty/unreadable scan is the clearest case of "the scanner failed to
    // read" — it counts toward the facial fallback streak same as an unknown
    // barcode value, and no entry_logs row is written since there's nothing to log.
    $streak = register_barcode_failure();
    echo json_encode([
        'status' => 'denied_unknown',
        'reason' => 'Empty or unreadable barcode.',
        'facial_fallback_unlocked' => is_facial_fallback_unlocked($pdo),
        'attempts_remaining' => max(0, get_facial_fallback_threshold($pdo) - $streak),
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ? LIMIT 1");
    $stmt->execute([$barcode]);
    $student = $stmt->fetch();

    if (!$student) {
        // Barcode does not exist in the database — this is a genuine "scan
        // failed to identify anyone" result, so it counts toward the facial
        // fallback streak.
        // Timestamp is bound as a PHP value (not SQL NOW()) so it consistently
        // uses the app's configured timezone (Asia/Manila) regardless of the
        // database server's own timezone setting — keeps denied-scan log times
        // consistent with granted-scan log times below.
        $log = $pdo->prepare("INSERT INTO entry_logs (student_id, fullname, grade, time_in, verification_method, transaction_type, verified_by, status, reason)
                               VALUES (?,?,?,?,'Barcode','Entry',?, 'Denied', 'Invalid ID')");
        $log->execute([$barcode, null, null, date('Y-m-d H:i:s'), $verifier]);

        $streak = register_barcode_failure();
        echo json_encode([
            'status' => 'denied_unknown',
            'reason' => 'Barcode not found in student database.',
            'facial_fallback_unlocked' => is_facial_fallback_unlocked($pdo),
            'attempts_remaining' => max(0, get_facial_fallback_threshold($pdo) - $streak),
        ]);
        exit;
    }

    if ($student['status'] !== 'Active') {
        // Identity IS known here (just inactive) — this isn't a "scanner
        // couldn't identify anyone" failure, so it does not count toward or
        // reset the facial fallback streak. Facial recognition would only
        // re-confirm an identity that's already resolved, so it isn't offered.
        $log = $pdo->prepare("INSERT INTO entry_logs (student_id, fullname, grade, time_in, verification_method, transaction_type, verified_by, status, reason)
                               VALUES (?,?,?,?,'Barcode','Entry',?, 'Denied', 'Student status is Inactive')");
        $log->execute([$student['student_id'], $student['fullname'], $student['grade'], date('Y-m-d H:i:s'), $verifier]);

        create_notification($pdo, null, 'warning', 'Entry Denied — Inactive Student', "{$student['fullname']} (ID: {$student['student_id']}) attempted entry but is marked Inactive.");

        echo json_encode([
            'status' => 'denied_known',
            'reason' => 'Student status is Inactive.',
            'student' => ['fullname' => $student['fullname'], 'student_id' => $student['student_id']],
            'facial_fallback_unlocked' => is_facial_fallback_unlocked($pdo),
        ]);
        exit;
    }

    // ---- Identity resolved and Active: resolve Entry vs Exit vs duplicate ----
    // Serialize per-student so two near-simultaneous scans (two stations, a
    // double-tap) can't both pass the checks below and create two transactions.
    $locked = acquire_student_scan_lock($pdo, $student['student_id']);

    if (!$locked) {
        // BUGFIX (Phase 1): the lock was previously acquired but never
        // actually enforced — execution fell through into the duplicate /
        // entry-exit / insert logic even when GET_LOCK() timed out, which
        // defeats the whole purpose of serializing concurrent scans of the
        // same student. Fail safe instead: do not touch entry_logs, do not
        // send SMS, and ask the caller to retry.
        echo json_encode([
            'status' => 'busy',
            'reason' => 'This ID is currently being processed by another request. Please try again in a moment.',
            'student' => ['fullname' => $student['fullname'], 'student_id' => $student['student_id']],
            'facial_fallback_unlocked' => false,
        ]);
        exit;
    }

    try {
        if (is_duplicate_scan($pdo, $student['student_id'])) {
            // Same student scanned again within the cooldown window — do not
            // write a new transaction and do not send another SMS.
            // BUGFIX (Phase 1): release the lock explicitly before exit().
            // PHP does NOT run a try/finally's finally block when exit()/die()
            // is called inside the try (verified empirically) — the finally
            // below at the end of this block was therefore being skipped on
            // this early-return path. It happened to be masked in practice
            // because this script uses a fresh, non-persistent DB connection
            // per request (MySQL auto-releases GET_LOCK() on disconnect), but
            // that's an implicit, fragile guarantee — release deterministically.
            release_student_scan_lock($pdo, $student['student_id']);
            $locked = false;
            echo json_encode([
                'status' => 'duplicate',
                'reason' => 'You have already successfully scanned. Access has already been granted. Please wait a moment.',
                'student' => ['fullname' => $student['fullname'], 'student_id' => $student['student_id']],
                'facial_fallback_unlocked' => false,
            ]);
            exit;
        }

        $state = get_student_access_state($pdo, $student['student_id']);

        if ($state === 'INSIDE') {
            $exitCheck = can_process_exit($pdo, $student['student_id']);
            if (!$exitCheck['allowed']) {
                // Already inside and the minimum interval hasn't passed yet —
                // do not create another Entry transaction and do not send SMS.
                // BUGFIX (Phase 1): same explicit-release fix as the duplicate
                // branch above — exit() here also used to skip the finally.
                release_student_scan_lock($pdo, $student['student_id']);
                $locked = false;
                $waitMinutes = max(1, (int) ceil($exitCheck['wait_seconds'] / 60));
                echo json_encode([
                    'status' => 'already_inside',
                    'reason' => "{$student['fullname']} is already inside. Exit can be processed in about {$waitMinutes} minute(s).",
                    'student' => ['fullname' => $student['fullname'], 'student_id' => $student['student_id']],
                    'wait_seconds' => $exitCheck['wait_seconds'],
                    'facial_fallback_unlocked' => false,
                ]);
                exit;
            }
            $transactionType = 'Exit';
        } else {
            $transactionType = 'Entry';
        }

        // ---- Access granted: record the transaction ----
        $timeIn = date('Y-m-d H:i:s');
        $log = $pdo->prepare("INSERT INTO entry_logs (student_id, fullname, grade, time_in, verification_method, transaction_type, verified_by, status)
                               VALUES (?,?,?,?,'Barcode',?,?, 'Match')");
        $log->execute([$student['student_id'], $student['fullname'], $student['grade'], $timeIn, $transactionType, $verifier]);
        $entryLogId = (int) $pdo->lastInsertId();

        // A successful barcode verification always resets the facial fallback streak.
        reset_barcode_fail_streak();

        // Remember this grant briefly so staff can optionally spot-check the
        // presenter's face against the ID they just scanned (see the
        // "Verify Face" challenge button / api/face.php verify_face_challenge).
        // This is what stops someone from using another student's physical ID
        // card undetected — see includes/security.php notes on ID borrowing.
        $_SESSION['sg_last_grant'] = ['student_id' => $student['student_id'], 'at' => time()];

        // ---- Automatically send SMS to parent/guardian (genuine transaction only) ----
        $responseBody = json_encode([
            'status' => 'granted',
            'transaction_type' => $transactionType,
            'facial_fallback_unlocked' => false,
            'student' => [
                'fullname' => $student['fullname'],
                'grade' => $student['grade'],
                'section' => $student['section'],
                'student_id' => $student['student_id'],
                'photo_url' => sg_student_photo_url($student['photo']),
            ]
        ]);

        // PERFORMANCE (Phase 2.5): the student has already been verified and
        // the transaction is already committed — nothing below this point
        // can change that outcome. Previously the SMS attempt (which can
        // block for several seconds against a slow/unreachable provider,
        // see CURLOPT_TIMEOUT in api/sms.php) ran BEFORE the response was
        // sent, so a slow SMS provider directly delayed the kiosk's
        // "granted" screen. Send the response to the client first, then do
        // the notification — SMS remains just as isolated/non-blocking for
        // correctness as before (still try/caught, still never able to
        // turn a granted entry into a denial), it just no longer makes the
        // person standing at the gate wait for it.
        sg_send_response_then_continue($responseBody);

        // NOTIFICATION IS NOT PART OF VERIFICATION: the transaction above is
        // already committed and the student has already been verified. An SMS
        // provider that is unconfigured, down, or throwing must never turn a
        // successful verification into ACCESS DENIED, so this is isolated.
        if (!empty($student['contact_number'])) {
            try {
                sg_notify_entry($pdo, $entryLogId, $student['contact_number'], $student['fullname'], date('h:i A', strtotime($timeIn)), $verifier, $transactionType);
            } catch (Throwable $smsError) {
                error_log(sprintf('scan.php SMS notification failed (verification still granted): %s in %s on line %d', $smsError->getMessage(), $smsError->getFile(), $smsError->getLine()));
            }
        }
    } finally {
        if ($locked) release_student_scan_lock($pdo, $student['student_id']);
    }
} catch (Throwable $e) {
    // A BACKEND failure is not a failed barcode attempt: the streak is
    // deliberately left untouched here, so a server error never consumes one
    // of the student's three scans or falsely unlocks facial fallback.
    // The client distinguishes this from denied_unknown via status 'error'.
    echo json_encode(sg_api_exception_response($e, 'scan.php', 'A server error occurred during verification.'));
}
