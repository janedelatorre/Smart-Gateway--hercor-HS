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

    if (!require_login_api()) {
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
        $sentByType = trim($_GET['sent_by_type'] ?? '');

        $where = [];
        $params = [];
        if ($search !== '') { $where[] = "(s.recipient LIKE ? OR s.message LIKE ? OR s.sent_by LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
        if ($status !== '') { $where[] = "s.status = ?"; $params[] = $status; }
        $sourceExpr = "CASE WHEN s.sent_by = 'Kiosk Station' THEN 'Kiosk' WHEN u.role = 'Administrator' THEN 'Administrator' WHEN u.role = 'Staff' THEN 'Staff' ELSE 'System' END";
        if (in_array($sentByType, ['Kiosk','Administrator','Staff'], true)) {
            $where[] = "($sourceExpr) = ?"; $params[] = $sentByType;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $count = $pdo->prepare("SELECT COUNT(*) FROM sms_logs s LEFT JOIN users u ON u.username = s.sent_by $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $pdo->prepare("SELECT s.*, ($sourceExpr) AS sent_by_type FROM sms_logs s LEFT JOIN users u ON u.username = s.sent_by $whereSql ORDER BY s.sent_at DESC LIMIT $perPage OFFSET $offset");
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
        sg_apply_sms_result($pdo, (int) $row['id'], (int) ($row['retry_count'] ?? 0), $result);

        $messages = [
            'Match'  => 'SMS resent successfully.',
            'Queued' => 'Provider/Internet still unavailable — queued for automatic retry.',
        ];
        echo json_encode(['success' => true, 'message' => $messages[$result['status']] ?? 'Resend attempt failed.']);
        exit;
    }

    // Manual "flush now" — also called automatically by the admin/staff
    // notification-bell poll (assets/js/notifications.js) so a backlog that
    // built up while no one was on an admin page still drains on its own.
    if ($action === 'flush_queue') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid session token.']); exit;
        }
        $flushed = sg_flush_sms_queue($pdo, 20);
        echo json_encode(['success' => true, 'flushed' => $flushed]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

/**
 * Send an SMS message and log the attempt.
 * Returns ['status' => 'Match'|'Denied'|'Pending'|'Queued', 'response' => string]
 *
 * PHASE 3: 'Queued' is distinct from 'Denied'. 'Denied' means the provider
 * was actually reached and explicitly rejected the message (bad request,
 * bad API key, provider-side error) -- retrying that automatically would
 * just fail again the same way, so it is treated as permanent. 'Queued'
 * means the request never got a response from the provider at all (DNS
 * failure, connection refused, timeout) -- that is Case A from the Phase 3
 * plan (server reachable, SMS provider/Internet unavailable) and IS worth
 * retrying automatically once connectivity to the provider comes back.
 */
function sg_send_sms(PDO $pdo, string $recipient, string $message, string $sentBy = 'System'): array {
    $smsEnabled = get_setting($pdo, 'enable_sms', '1');

    if ($smsEnabled !== '1') {
        return ['status' => 'Pending', 'response' => 'SMS notifications are disabled in Settings.'];
    }

    // Canonical PH mobile format check (see sg_is_valid_ph_mobile() /
    // sg_normalize_ph_mobile() in includes/security.php). A bad number is
    // never retryable, so this is always 'Denied', never 'Queued'.
    if (!sg_is_valid_ph_mobile($recipient)) {
        return ['status' => 'Denied', 'response' => 'Invalid recipient number format (expected 09XXXXXXXXX).'];
    }

    // Single routing point: every SMS (entry notifications, OTP, resend,
    // queue flush) comes through here and is dispatched to the ONE active
    // provider. No automatic fallback to the other provider.
    if (sg_get_active_sms_provider($pdo) === 'semaphore') {
        return sg_send_sms_semaphore($pdo, $recipient, $message);
    }
    return sg_send_sms_iprog($pdo, $recipient, $message);
}

const SG_SMS_PROVIDERS = ['iprog' => 'iProg', 'semaphore' => 'Semaphore'];

/** Active provider: settings key sms_active_provider; anything unknown/missing => iprog (existing behavior). */
function sg_get_active_sms_provider(PDO $pdo): string {
    $p = strtolower(trim((string) get_setting($pdo, 'sms_active_provider', 'iprog')));
    return isset(SG_SMS_PROVIDERS[$p]) ? $p : 'iprog';
}

/** Semaphore config: DB setting first, then environment variable fallback. Server-side only. */
function sg_semaphore_config(PDO $pdo): array {
    $key = trim((string) get_setting($pdo, 'semaphore_api_key', ''));
    if ($key === '') $key = trim((string) getenv('SG_SEMAPHORE_API_KEY'));
    $sender = trim((string) get_setting($pdo, 'semaphore_sender_name', ''));
    if ($sender === '') $sender = trim((string) getenv('SG_SEMAPHORE_SENDER_NAME'));
    return ['api_key' => $key, 'sender_name' => $sender];
}

/** Remove any secret from text before it is returned/logged/stored. */
function sg_redact_secret(string $text, array $secrets): string {
    foreach ($secrets as $secret) {
        if ($secret !== '') $text = str_replace($secret, '[REDACTED]', $text);
    }
    return $text;
}

/** Build the Semaphore POST fields (separate function so the request can be verified without a network call). */
function sg_build_semaphore_request(string $apiKey, string $senderName, string $recipient, string $message): array {
    return [
        'url'    => 'https://api.semaphore.co/api/v4/messages',
        'fields' => [
            'apikey'     => $apiKey,
            'number'     => $recipient, // canonical 09XXXXXXXXX is accepted by Semaphore as-is
            'message'    => $message,
            'sendername' => $senderName,
        ],
    ];
}

function sg_send_sms_semaphore(PDO $pdo, string $recipient, string $message): array {
    $cfg = sg_semaphore_config($pdo);
    if ($cfg['api_key'] === '' || $cfg['sender_name'] === '') {
        return ['status' => 'Pending', 'response' => 'Semaphore: API key and/or approved Sender Name not configured (Settings > SMS Provider).'];
    }
    if (!function_exists('curl_init')) {
        return ['status' => 'Denied', 'response' => 'PHP cURL extension is not enabled on this server.'];
    }
    $req = sg_build_semaphore_request($cfg['api_key'], $cfg['sender_name'], $recipient, $message);

    $ch = curl_init($req['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($req['fields']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlError) {
        $err = sg_redact_secret($curlError, [$cfg['api_key']]);
        error_log('SMS provider=semaphore connectivity error: ' . $err);
        return ['status' => 'Queued', 'response' => "Semaphore connectivity error (retryable): $err", 'curl_errno' => $curlErrno];
    }
    $result = sg_classify_semaphore_response($httpCode, (string) $response, [$cfg['api_key']]);
    if ($result['status'] === 'Denied') {
        error_log('SMS provider=semaphore failed: ' . $result['response']);
    }
    return $result;
}

/**
 * Classify a Semaphore response. Success = HTTP 2xx and a JSON array of message
 * objects (each with message_id). "Match" means accepted by Semaphore, not
 * confirmed handset delivery. Anything else is a provider rejection (Denied).
 */
function sg_classify_semaphore_response(int $httpCode, string $raw, array $secrets = []): array {
    $decoded = json_decode($raw, true);
    if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded) && isset($decoded[0]) && is_array($decoded[0]) && !empty($decoded[0]['message_id'])) {
        $first = $decoded[0];
        $st = strtolower((string) ($first['status'] ?? ''));
        if ($st === 'failed' || $st === 'refused') {
            return ['status' => 'Denied', 'response' => 'Semaphore: message ' . $st];
        }
        return ['status' => 'Match', 'response' => 'Semaphore: accepted (' . ($first['status'] ?? 'Queued') . ')', 'message_id' => (string) $first['message_id']];
    }
    $detail = sg_redact_secret(mb_substr($raw, 0, 300), $secrets);
    return ['status' => 'Denied', 'response' => "Semaphore: HTTP $httpCode: $detail"];
}

function sg_send_sms_iprog(PDO $pdo, string $recipient, string $message): array {
    $apiUrl = get_setting($pdo, 'sms_api_url', '');
    $apiKey = get_setting($pdo, 'sms_api_key', '');

    if ($apiUrl === '' || $apiKey === '') {
        // No provider configured yet — log message so the workflow can be demoed end-to-end.
        return ['status' => 'Pending', 'response' => 'No SMS provider configured (see Settings > SMS API URL/Key).'];
    }

    if (!function_exists('curl_init')) {
        // Provider IS configured but the PHP cURL extension is not enabled.
        // This will not fix itself on retry -- it is a server config
        // problem, not a transient connectivity one -- so it is 'Denied'.
        return ['status' => 'Denied', 'response' => 'PHP cURL extension is not enabled on this server.'];
    }

    // ---- IPROG SMS: POST api_token / phone_number / message ----
    // Endpoint/token are read from the existing sms_api_url / sms_api_key
    // settings (Settings > SMS) -- no new configuration mechanism, and the
    // token is never sent to the browser: get_setting() is a server-side-only
    // PHP call, and admin/settings.php already renders this field blank
    // (type="password", never echoes the stored value).
    //
    // PHASE 4.5: IPROG expects the Philippine mobile number as 639XXXXXXXXX,
    // while Smart Gateway's own canonical stored/validated format stays
    // 09XXXXXXXXX (Phase 3) -- this conversion is provider-request-only and
    // never touches what's stored in the students table or sms_logs.recipient.
    $iprogNumber = sg_to_iprog_mobile_format($recipient);
    if ($iprogNumber === null) {
        // Should be unreachable given the sg_is_valid_ph_mobile() check above,
        // but never guess/send a malformed number to a paid provider.
        return ['status' => 'Denied', 'response' => 'Could not convert recipient to the provider\'s expected phone number format.'];
    }

    $payload = json_encode([
        'api_token'    => $apiKey,
        'phone_number' => $iprogNumber,
        'message'      => $message,
        // sms_provider intentionally omitted -- default (0, shared sender)
        // is what this account uses; $senderId/sms_sender_id setting is not
        // an IPROG parameter and is not sent.
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
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlError) {
        // The request never reached the provider (could not resolve host,
        // connection refused/timed out, network unreachable, etc.) --
        // this is exactly "SMS provider or Internet unavailable" from a
        // server that itself is fine, so it is queued rather than denied.
        return ['status' => 'Queued', 'response' => "Connectivity error (retryable): $curlError", 'curl_errno' => $curlErrno];
    }

    return sg_classify_iprog_response($httpCode, $response);
}

/**
 * PHASE 4.5: classify an IPROG response. IPROG's own success/error signal
 * lives INSIDE the JSON body ("status": 200 or "status": "success" for
 * success; a different value -- e.g. {"status":500,"message":"Invalid
 * Token"} -- for a provider-side rejection), not necessarily in the HTTP
 * transport status code alone, per IPROG's documented examples. So the
 * JSON body is checked first; the HTTP status code is only a fallback for
 * a response IPROG never documented (e.g. an upstream proxy/HTML error
 * page), so a transport-level failure still gets classified sensibly.
 *
 * IMPORTANT: "Match" here means "accepted by IPROG for delivery" (their
 * message_id/queue confirmation), NOT confirmed handset delivery -- IPROG's
 * status-check endpoint (GET /sms_messages/status) is what would tell you
 * final delivery, and is intentionally NOT polled automatically here (would
 * mean extra billed-adjacent API calls / a bigger delivery-status
 * subsystem than this phase's minimal-scope integration calls for).
 */
function sg_classify_iprog_response(int $httpCode, string $rawResponse): array {
    $decoded = json_decode($rawResponse, true);

    if (is_array($decoded) && array_key_exists('status', $decoded)) {
        $status = $decoded['status'];
        $accepted = ($status === 200 || $status === '200' || $status === 'success');
        if ($accepted) {
            $result = ['status' => 'Match', 'response' => $decoded['message'] ?? $rawResponse];
            if (!empty($decoded['message_id'])) {
                $result['message_id'] = (string) $decoded['message_id'];
            }
            return $result;
        }
        // Provider was reached and it rejected the request (bad token, invalid
        // number, insufficient credits, etc.) -- permanent, not retryable.
        return ['status' => 'Denied', 'response' => 'IPROG: ' . ($decoded['message'] ?? $rawResponse)];
    }

    // Not the documented IPROG JSON shape -- fall back to the plain HTTP
    // status code so an unexpected response (proxy error page, etc.) still
    // gets a sensible Match/Denied classification instead of crashing.
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['status' => 'Match', 'response' => $rawResponse];
    }
    return ['status' => 'Denied', 'response' => "HTTP $httpCode: $rawResponse"];
}

/**
 * Provider-request-only conversion: Smart Gateway's canonical stored format
 * (09XXXXXXXXX, Phase 3) -> the 639XXXXXXXXX format IPROG's API expects.
 * Never used for storage/display/validation -- sg_is_valid_ph_mobile()
 * remains the source of truth for what's a valid Smart Gateway number.
 * Returns null (never guesses) if $canonical isn't already the expected
 * 09XXXXXXXXX shape.
 */
function sg_to_iprog_mobile_format(string $canonical): ?string {
    if (!preg_match('/^09\d{9}$/', $canonical)) return null;
    return '63' . substr($canonical, 1);
}

/** How long to wait before the next automatic retry, given how many retries have already happened. */
function sg_sms_retry_backoff_seconds(int $retryCount): int {
    // 2 min, 5 min, 15 min, 30 min, then hourly.
    $steps = [120, 300, 900, 1800];
    return $steps[$retryCount] ?? 3600;
}

/** Above this many retries, stop auto-retrying and surface it as a real failure needing attention. */
const SG_SMS_MAX_RETRIES = 8;

/**
 * Apply the outcome of a send attempt to an existing sms_logs row --
 * shared by the automatic queue flush and the manual "Resend" button so
 * both age/cap retries the same way.
 */
function sg_apply_sms_result(PDO $pdo, int $smsLogId, int $priorRetryCount, array $result): void {
    if ($result['status'] === 'Queued') {
        $retryCount = $priorRetryCount + 1;
        if ($retryCount > SG_SMS_MAX_RETRIES) {
            // Give up automatically retrying; this now needs a human to look at it
            // (bad number that slipped through, provider account issue, etc.).
            // sent_at here is PHP-bound (not SQL NOW()) -- it is only ever
            // displayed (SMS Notifications table/detail modal), never
            // compared for scheduling, so it follows the same PHASE 6
            // display-timestamp fix as log_activity()/create_notification().
            // next_retry_at (NULL here -- retries are over) is unaffected.
            $pdo->prepare("UPDATE sms_logs SET status = 'Denied', retry_count = ?, next_retry_at = NULL, provider_response = ?, sent_at = ? WHERE id = ?")
                ->execute([$retryCount, $result['response'] . ' (max retries exceeded)', date('Y-m-d H:i:s'), $smsLogId]);
            $row = $pdo->prepare("SELECT recipient FROM sms_logs WHERE id = ?");
            $row->execute([$smsLogId]);
            create_notification($pdo, null, 'error', 'SMS Delivery Failed', "Guardian SMS to {$row->fetchColumn()} exceeded the maximum retry attempts.");
            return;
        }
        // PHASE 4.5.1: bind the backoff as a SQL DATE_ADD(NOW(), INTERVAL ...)
        // expression, not a PHP-computed date() string. sg_flush_sms_queue()'s
        // eligibility check is `next_retry_at <= NOW()` -- evaluated entirely
        // by MySQL -- so the value written here must come from that same
        // clock. A PHP date()/time() string is stamped using PHP's configured
        // Asia/Manila timezone; if the DB server's own timezone differs (as
        // in this sandbox: MySQL runs in UTC), the stored value silently
        // drifts by the gap between the two clocks (proven live: an intended
        // 120-second backoff was actually ~8h2m before this fix), delaying
        // every retry by that same gap. DATE_ADD(NOW(), INTERVAL ... SECOND)
        // guarantees the write and the later comparison use the identical
        // clock, exactly like the atomic-claim UPDATE just above already does.
        $backoffSeconds = sg_sms_retry_backoff_seconds($retryCount - 1);
        $pdo->prepare("UPDATE sms_logs SET status = 'Queued', retry_count = ?, next_retry_at = DATE_ADD(NOW(), INTERVAL ? SECOND), provider_response = ? WHERE id = ?")
            ->execute([$retryCount, $backoffSeconds, $result['response'], $smsLogId]);
        return;
    }

    // Match / Denied / Pending: terminal for this attempt, same as before Phase 3.
    // sent_at PHP-bound here too -- same PHASE 6 display-timestamp fix, no
    // scheduling column involved in this branch at all.
    $messageId = $result['message_id'] ?? null;
    $sentAt = date('Y-m-d H:i:s');
    if ($messageId !== null) {
        $pdo->prepare("UPDATE sms_logs SET status = ?, provider_response = ?, provider_message_id = ?, sent_at = ? WHERE id = ?")
            ->execute([$result['status'], $result['response'], $messageId, $sentAt, $smsLogId]);
    } else {
        $pdo->prepare("UPDATE sms_logs SET status = ?, provider_response = ?, sent_at = ? WHERE id = ?")
            ->execute([$result['status'], $result['response'], $sentAt, $smsLogId]);
    }

    if ($result['status'] === 'Denied') {
        $row = $pdo->prepare("SELECT recipient FROM sms_logs WHERE id = ?");
        $row->execute([$smsLogId]);
        create_notification($pdo, null, 'error', 'SMS Delivery Failed', "Guardian SMS to {$row->fetchColumn()} could not be sent.");
    }
}

/**
 * Attempt to send every due 'Queued' message, oldest first. Called
 * opportunistically after a new scan's own SMS attempt (see
 * sg_notify_entry()) and via api/sms.php?action=flush_queue on the
 * existing admin/staff notification poll -- there is no cron/task
 * scheduler in this stack, so this is how the backlog actually drains.
 * Returns how many rows were attempted.
 */
function sg_flush_sms_queue(PDO $pdo, int $limit = 10): int {
    $stmt = $pdo->prepare("SELECT id, recipient, message, sent_by, retry_count FROM sms_logs
                            WHERE status = 'Queued' AND (next_retry_at IS NULL OR next_retry_at <= NOW())
                            ORDER BY sent_at ASC LIMIT " . (int) $limit);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $attempted = 0;
    foreach ($rows as $row) {
        // ---- Duplicate-retry protection ----
        // The opportunistic flush (after every new scan's own SMS attempt)
        // and the ~30s admin/staff poll flush can overlap in time, and two
        // admin tabs can each be polling independently, so more than one
        // request can reach this function while the same row is still due.
        // Atomically claim the row first: push next_retry_at a short way
        // into the future so a concurrent flush's WHERE clause no longer
        // matches it, and only proceed if THIS request is the one that
        // actually changed the row (affected rowcount = 1). Whichever
        // process loses the race sends nothing for this row.
        $claim = $pdo->prepare("UPDATE sms_logs SET next_retry_at = DATE_ADD(NOW(), INTERVAL 60 SECOND)
                                 WHERE id = ? AND status = 'Queued' AND (next_retry_at IS NULL OR next_retry_at <= NOW())");
        $claim->execute([$row['id']]);
        if ($claim->rowCount() !== 1) {
            continue; // another concurrent flush already claimed this row
        }
        $attempted++;

        try {
            $result = sg_send_sms($pdo, $row['recipient'], $row['message'], $row['sent_by'] ?? 'System');
        } catch (Throwable $e) {
            error_log(sprintf('sg_flush_sms_queue: send failed for sms_logs.id=%d: %s', $row['id'], $e->getMessage()));
            continue; // leave it Queued with the 60s claim window as its next_retry_at; picked up next flush
        }
        sg_apply_sms_result($pdo, (int) $row['id'], (int) $row['retry_count'], $result);
    }

    return $attempted;
}

/**
 * Shared "record the outcome of one sg_send_sms() attempt" logic -- used by
 * both sg_notify_entry() (Entry/Exit notifications) and forgot_password.php
 * (OTP delivery), so both get the same Queued/retry_count/next_retry_at
 * bookkeeping and the same Denied notification, instead of the OTP flow
 * having its own separate, simpler INSERT that skipped retry scheduling.
 * Returns the new sms_logs.id.
 */
function sg_log_sms_attempt(PDO $pdo, ?int $entryLogId, string $recipient, string $message, string $sentBy, array $result, string $failureContext = 'SMS'): int {
    $isQueued = $result['status'] === 'Queued';
    $retryCount = $isQueued ? 1 : 0;
    $messageId = $result['message_id'] ?? null;

    // PHASE 4.5.1: same fix/reasoning as sg_apply_sms_result() below -- the
    // backoff is bound as a SQL DATE_ADD(NOW(), INTERVAL ... SECOND)
    // expression (MySQL's own clock) rather than a PHP date()/time() string,
    // so it matches the clock sg_flush_sms_queue()'s `next_retry_at <= NOW()`
    // check uses. A non-Queued row has no retry, so next_retry_at is a plain
    // bound NULL in that case -- no DATE_ADD needed either way.
    // sent_at is PHP-bound (PHASE 6 display-timestamp fix) in both branches
    // below -- it's display-only (SMS Notifications table), never compared
    // for scheduling, so unlike next_retry_at it should NOT come from the
    // column's DEFAULT CURRENT_TIMESTAMP (the DB server's own clock).
    $sentAt = date('Y-m-d H:i:s');
    if ($isQueued) {
        $backoffSeconds = sg_sms_retry_backoff_seconds(0);
        $stmt = $pdo->prepare("INSERT INTO sms_logs (entry_log_id, recipient, message, status, retry_count, next_retry_at, provider_response, provider_message_id, sent_by, sent_at) VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?,?,?,?)");
        $stmt->execute([$entryLogId, $recipient, $message, $result['status'], $retryCount, $backoffSeconds, $result['response'], $messageId, $sentBy, $sentAt]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO sms_logs (entry_log_id, recipient, message, status, retry_count, next_retry_at, provider_response, provider_message_id, sent_by, sent_at) VALUES (?,?,?,?,?, NULL, ?,?,?,?)");
        $stmt->execute([$entryLogId, $recipient, $message, $result['status'], $retryCount, $result['response'], $messageId, $sentBy, $sentAt]);
    }
    $id = (int) $pdo->lastInsertId();

    if ($result['status'] === 'Denied') {
        create_notification($pdo, null, 'error', 'SMS Delivery Failed', "{$failureContext} could not be sent.");
    }
    return $id;
}

function sg_notify_entry(PDO $pdo, int $entryLogId, string $recipient, string $studentName, string $timeIn, string $sentBy = 'System', string $transactionType = 'Entry'): void {
    if (!$recipient) return;
    $verb = $transactionType === 'Exit' ? 'left' : 'entered';
    $message = "Smart Gateway Alert: {$studentName} {$verb} the campus at {$timeIn}.";

    // This function is called AFTER a verification has already succeeded and
    // been written to entry_logs. Notification problems (provider down, cURL
    // extension missing, sms_logs unavailable) must never propagate back up
    // and turn a granted entry into "ACCESS DENIED", so everything below is
    // contained and logged rather than thrown. Delivery failures are still
    // recorded in sms_logs / the notification centre exactly as before.
    try {
        $result = sg_send_sms($pdo, $recipient, $message, $sentBy);
    } catch (Throwable $e) {
        error_log(sprintf('sg_notify_entry: SMS send failed: %s in %s on line %d', $e->getMessage(), $e->getFile(), $e->getLine()));
        $result = ['status' => 'Denied', 'response' => 'SMS send failed: ' . $e->getMessage()];
    }

    try {
        sg_log_sms_attempt($pdo, $entryLogId, $recipient, $message, $sentBy, $result, "Guardian SMS for {$studentName}");
    } catch (Throwable $e) {
        error_log(sprintf('sg_notify_entry: could not record SMS log: %s in %s on line %d', $e->getMessage(), $e->getFile(), $e->getLine()));
    }

    // Opportunistic drain: this call already happens after the kiosk's
    // response was sent (see sg_send_response_then_continue() in
    // api/scan.php / api/face.php), so a few extra queued sends here add
    // no latency the operator/student can feel, and it means the backlog
    // shrinks every time connectivity/the provider comes back, not only
    // when the 30s admin poll happens to run.
    try {
        sg_flush_sms_queue($pdo, 5);
    } catch (Throwable $e) {
        error_log(sprintf('sg_notify_entry: opportunistic queue flush failed: %s', $e->getMessage()));
    }
}
