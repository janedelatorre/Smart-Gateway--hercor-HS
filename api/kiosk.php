<?php
/**
 * =====================================================================
 * KIOSK API
 * Actions: status, authorize, revoke
 * =====================================================================
 * status     GET   Staff session or kiosk cookie. Read-only: never extends a
 *                  kiosk's idle timer. Used by the kiosk page to notice expiry.
 * authorize  POST  Authorized Admin/Staff kiosk manager + CSRF. Turns THIS device into a kiosk
 *                  (HttpOnly cookie, token hash stored in kiosk_sessions) and
 *                  ends the Administrator's own login on this device, so
 *                  transactions from here are attributed to "Kiosk Station".
 * revoke     POST  Authorized Admin/Staff kiosk manager + CSRF. Locks this device's kiosk, or a
 *                  specific station when `id` is supplied.
 * =====================================================================
 */
require_once __DIR__ . '/../database/config.php';
header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

try {
    if ($action === 'status') {
        $staff = sg_staff_session_ok();
        echo json_encode(['success' => true, 'authorized' => $staff || kiosk_is_valid($pdo, false), 'mode' => $staff ? 'staff' : 'kiosk']);
        exit;
    }

    if (!in_array($action, ['authorize', 'revoke'], true)) {
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'POST required.']);
        exit;
    }
    if (!sg_staff_session_ok()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in again.']);
        exit;
    }
    if (!kiosk_user_can_manage($pdo)) {
        echo json_encode(['success' => false, 'message' => 'Access denied. Your account is not authorized to manage kiosk stations.']);
        exit;
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid session token.']);
        exit;
    }

    $adminName = $_SESSION['username'] ?? 'Authorized User';

    if ($action === 'authorize') {
        if (kiosk_is_valid($pdo, false)) {
            echo json_encode(['success' => false, 'message' => 'This device is already an authorized kiosk.']);
            exit;
        }
        kiosk_authorize($pdo, (int) $_SESSION['user_id'], $adminName);
        // End the authenticated manager login on this device: the kiosk runs on its own session.
        $_SESSION = [];
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Kiosk authorized.']);
        exit;
    }

    // revoke
    $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
    if (kiosk_revoke($pdo, $id, $adminName)) {
        echo json_encode(['success' => true, 'message' => 'Kiosk locked.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'No active kiosk session to lock.']);
    }
} catch (Throwable $e) {
    error_log('kiosk.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
}
