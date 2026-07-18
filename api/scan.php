<?php
/**
 * =====================================================================
 * BARCODE SCAN API — PRIMARY AUTHENTICATION
 * =====================================================================
 * Workflow:
 *   1. Look up the scanned barcode value against students.student_id
 *   2. Not found            -> status: denied_unknown (client may offer backup face)
 *   3. Found but Inactive   -> status: denied_known (no backup needed; identity is known)
 *   4. Found and Active     -> record entry, send SMS, status: granted
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
$verifier = $_SESSION['username'] ?? 'Staff';

if ($barcode === '') {
    echo json_encode(['status' => 'denied_unknown', 'reason' => 'Empty or unreadable barcode.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ? LIMIT 1");
    $stmt->execute([$barcode]);
    $student = $stmt->fetch();

    if (!$student) {
        // Barcode does not exist in the database
        $log = $pdo->prepare("INSERT INTO entry_logs (student_id, fullname, grade, time_in, verification_method, verified_by, status, reason)
                               VALUES (?,?,?,NOW(),'Barcode',?, 'Denied', 'Invalid ID')");
        $log->execute([$barcode, null, null, $verifier]);

        echo json_encode(['status' => 'denied_unknown', 'reason' => 'Barcode not found in student database.']);
        exit;
    }

    if ($student['status'] !== 'Active') {
        $log = $pdo->prepare("INSERT INTO entry_logs (student_id, fullname, grade, time_in, verification_method, verified_by, status, reason)
                               VALUES (?,?,?,NOW(),'Barcode',?, 'Denied', 'Student status is Inactive')");
        $log->execute([$student['student_id'], $student['fullname'], $student['grade'], $verifier]);

        create_notification($pdo, null, 'warning', 'Entry Denied — Inactive Student', "{$student['fullname']} (ID: {$student['student_id']}) attempted entry but is marked Inactive.");

        echo json_encode([
            'status' => 'denied_known',
            'reason' => 'Student status is Inactive.',
            'student' => ['fullname' => $student['fullname'], 'student_id' => $student['student_id']]
        ]);
        exit;
    }

    // ---- Access granted: record entry ----
    $timeIn = date('Y-m-d H:i:s');
    $log = $pdo->prepare("INSERT INTO entry_logs (student_id, fullname, grade, time_in, verification_method, verified_by, status)
                           VALUES (?,?,?,?,'Barcode',?, 'Match')");
    $log->execute([$student['student_id'], $student['fullname'], $student['grade'], $timeIn, $verifier]);
    $entryLogId = (int) $pdo->lastInsertId();

    // ---- Automatically send SMS to parent/guardian ----
    if (!empty($student['contact_number'])) {
        sg_notify_entry($pdo, $entryLogId, $student['contact_number'], $student['fullname'], date('h:i A', strtotime($timeIn)), $verifier);
    }

    echo json_encode([
        'status' => 'granted',
        'student' => [
            'fullname' => $student['fullname'],
            'grade' => $student['grade'],
            'section' => $student['section'],
            'student_id' => $student['student_id'],
        ]
    ]);
} catch (Throwable $e) {
    error_log('scan.php error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'reason' => 'A server error occurred during verification.']);
}
