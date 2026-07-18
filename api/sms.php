<?php
/**
 * =====================================================================
 * SMS INTEGRATION MODULE
 * =====================================================================
 * Provides a single function, sg_send_sms(), used by the scan/face
 * verification APIs to automatically notify parents/guardians after
 * a successful entry, and by the SMS Notifications page to resend
 * failed messages.
 *
 * This module is provider-agnostic: plug in any REST-based SMS
 * gateway (Semaphore, Twilio, iTexMo, etc.) by filling in
 * `sms_api_url` and `sms_api_key` under Settings. If no provider is
 * configured, messages are logged (status = Pending) so the rest of
 * the system keeps working during development/demo.
 *
 * IMPORTANT: This file is a library — it should be `require_once`'d,
 * not called directly as a page (guarded below).
 * =====================================================================
 */

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__) && !defined('SG_INTERNAL_CALL')) {
    // Allow direct GET/POST hits only for the "resend" AJAX action used by the SMS page.
    require_once __DIR__ . '/../database/config.php';
    header('Content-Type: application/json');

    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }

    $action = $_REQUEST['action'] ?? '';

    if ($action === 'list') {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');

        $where = [];
        $params = [];
        if ($search !== '') { $where[] = "(recipient LIKE ? OR message LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
        if ($status !== '') { $where[] = "status = ?"; $params[] = $status; }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $count = $pdo->prepare("SELECT COUNT(*) FROM sms_logs $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM sms_logs $whereSql ORDER BY sent_at DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        exit;
    }

    if ($action === 'resend') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid session token.']); exit;
        }
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM sms_logs WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['success' => false, 'message' => 'SMS record not found.']); exit; }

        $result = sg_send_sms($pdo, $row['recipient'], $row['message'], $_SESSION['username'] ?? 'System');
        $pdo->prepare("UPDATE sms_logs SET status = ?, provider_response = ?, sent_at = NOW() WHERE id = ?")
            ->execute([$result['status'], $result['response'], $id]);

        echo json_encode(['success' => true, 'message' => $result['status'] === 'Match' ? 'SMS resent successfully.' : 'Resend attempt failed.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

/**
 * Send an SMS message and log the attempt.
 * Returns ['status' => 'Match'|'Denied', 'response' => string]
 */
function sg_send_sms(PDO $pdo, string $recipient, string $message, string $sentBy = 'System'): array {
    $apiUrl = get_setting($pdo, 'sms_api_url', '');
    $apiKey = get_setting($pdo, 'sms_api_key', '');
    $senderId = get_setting($pdo, 'sms_sender_id', 'SmartGateway');
    $smsEnabled = get_setting($pdo, 'enable_sms', '1');

    if ($smsEnabled !== '1') {
        return ['status' => 'Pending', 'response' => 'SMS notifications are disabled in Settings.'];
    }

    // Basic PH mobile number sanity check
    if (!preg_match('/^[0-9+]{7,15}$/', $recipient)) {
        return ['status' => 'Denied', 'response' => 'Invalid recipient number format.'];
    }

    if ($apiUrl === '' || $apiKey === '') {
        // No provider configured yet — log message so the workflow can be demoed end-to-end.
        return ['status' => 'Pending', 'response' => 'No SMS provider configured (see Settings > SMS API URL/Key).'];
    }

    // ---- Generic REST call to the configured SMS gateway ----
    $payload = json_encode([
        'apikey'  => $apiKey,
        'number'  => $recipient,
        'message' => $message,
        'sendername' => $senderId,
    ]);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['status' => 'Denied', 'response' => 'cURL error: ' . $curlError];
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['status' => 'Match', 'response' => $response];
    }
    return ['status' => 'Denied', 'response' => "HTTP $httpCode: $response"];
}

/**
 * Convenience wrapper: builds the entry notification message, sends it,
 * and inserts a row into sms_logs linked to the given entry log id.
 */
function sg_notify_entry(PDO $pdo, int $entryLogId, string $recipient, string $studentName, string $timeIn, string $sentBy = 'System'): void {
    if (!$recipient) return;
    $message = "Smart Gateway Alert: {$studentName} entered the campus at {$timeIn}.";
    $result = sg_send_sms($pdo, $recipient, $message, $sentBy);
    $stmt = $pdo->prepare("INSERT INTO sms_logs (entry_log_id, recipient, message, status, provider_response, sent_by) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$entryLogId, $recipient, $message, $result['status'], $result['response'], $sentBy]);

    if ($result['status'] === 'Denied') {
        create_notification($pdo, null, 'error', 'SMS Delivery Failed', "Guardian SMS for {$studentName} could not be sent.");
    }
}
