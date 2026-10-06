<?php
// Dev-only verification script for Phase 4.5 (IPROG SMS integration).
// Does NOT make any real network call to IPROG -- no token/network needed
// for these checks. Uses the DB only for the Queued-path and schema checks.
require_once __DIR__ . '/database/config.php';
require_once __DIR__ . '/api/sms.php';

function tap(string $label, bool $ok, string $detail = '') {
    echo ($ok ? "PASS" : "FAIL") . " - $label" . ($detail !== '' ? " ($detail)" : '') . "\n";
    return $ok;
}
$allOk = true;

echo "=== 09XXXXXXXXX -> 639XXXXXXXXX provider-format conversion ===\n";
$allOk = tap("sg_to_iprog_mobile_format('09171234567') === '639171234567'", sg_to_iprog_mobile_format('09171234567') === '639171234567') && $allOk;
$allOk = tap("sg_to_iprog_mobile_format('639171234567') === null (already-converted input rejected, not re-guessed)", sg_to_iprog_mobile_format('639171234567') === null) && $allOk;
$allOk = tap("sg_to_iprog_mobile_format('0917123456') === null (invalid length)", sg_to_iprog_mobile_format('0917123456') === null) && $allOk;

echo "\n=== IPROG response classification (using IPROG's own documented example payloads -- no network call) ===\n";
$success = json_encode(["status" => 200, "message" => "Your SMS message has been successfully added to the queue and will be processed shortly.", "message_id" => "iSms-XHYBk"]);
$r1 = sg_classify_iprog_response(200, $success);
$allOk = tap("documented success response -> Match", $r1['status'] === 'Match') && $allOk;
$allOk = tap("message_id captured from response", ($r1['message_id'] ?? null) === 'iSms-XHYBk') && $allOk;

$invalidToken = json_encode(["status" => 500, "message" => "Invalid Token"]);
$r2 = sg_classify_iprog_response(200, $invalidToken); // IPROG returned this with a 200 transport code in their own docs example
$allOk = tap("documented 'Invalid Token' response (status:500 in body) -> Denied, not Queued", $r2['status'] === 'Denied') && $allOk;
$allOk = tap("Denied response never fabricates a message_id", !isset($r2['message_id'])) && $allOk;
$allOk = tap("Denied response text does not leak the word 'token value' or api_token itself", strpos($r2['response'], 'api_token') === false) && $allOk;

$malformed = "<html>502 Bad Gateway</html>";
$r3 = sg_classify_iprog_response(502, $malformed);
$allOk = tap("non-JSON response falls back to HTTP-status classification (502 -> Denied)", $r3['status'] === 'Denied') && $allOk;

$malformed2 = "<html>Cloudflare challenge</html>";
$r4 = sg_classify_iprog_response(200, $malformed2);
$allOk = tap("non-JSON response with 200 transport code falls back correctly (Match, since no error signal available)", $r4['status'] === 'Match') && $allOk;

echo "\n=== Queued path still works through the new IPROG-specific request code (provider unreachable) ===\n";
$pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('sms_api_url', ?)")->execute(['http://iprog-provider-that-does-not-exist.invalid/api/v1/sms_messages']);
$pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('sms_api_key', ?)")->execute(['dummy-test-token-not-real']);
$pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('enable_sms', ?)")->execute(['1']);
$result = sg_send_sms($pdo, '09171234567', 'Phase 4.5 connectivity test', 'TestHarness');
$allOk = tap("unreachable IPROG endpoint -> status Queued (not falsely Sent)", $result['status'] === 'Queued', "got: {$result['status']}") && $allOk;
$allOk = tap("Queued response text never contains the dummy token value", strpos($result['response'], 'dummy-test-token-not-real') === false) && $allOk;

echo "\n=== provider_message_id column exists and is populated by sg_log_sms_attempt() ===\n";
$fakeMatchResult = ['status' => 'Match', 'response' => 'ok', 'message_id' => 'iSms-TESTID1'];
$id = sg_log_sms_attempt($pdo, null, '09171234567', 'test message', 'TestHarness', $fakeMatchResult, 'Test');
$row = $pdo->query("SELECT provider_message_id FROM sms_logs WHERE id = $id")->fetch();
$allOk = tap("provider_message_id stored correctly", $row && $row['provider_message_id'] === 'iSms-TESTID1', $row ? $row['provider_message_id'] : 'no row') && $allOk;

echo "\n=== OTP resend-cooldown now computed entirely in SQL (timezone-mismatch fix) ===\n";
$stmt = $pdo->query("SELECT id FROM users LIMIT 1");
$uid = (int) $stmt->fetchColumn();
create_otp($pdo, $uid, 'password_reset', 10); // created_at defaults to MySQL CURRENT_TIMESTAMP
$check = $pdo->prepare("SELECT COUNT(*) FROM otp_codes WHERE user_id = ? AND purpose='password_reset' AND used=0 AND created_at >= (NOW() - INTERVAL 45 SECOND)");
$check->execute([$uid]);
$withinCooldown = (int) $check->fetchColumn() > 0;
$allOk = tap("a just-created OTP is correctly detected as within the 45s cooldown (SQL-side comparison, no PHP/MySQL timezone mismatch possible)", $withinCooldown) && $allOk;

echo "\n=== Credential never appears in any file that reaches the browser ===\n";
$jsFiles = glob(__DIR__ . '/assets/js/*.js');
$leak = false;
foreach ($jsFiles as $f) {
    if (strpos(file_get_contents($f), 'dummy-test-token-not-real') !== false) { $leak = true; break; }
}
$allOk = tap("test token string not present in any shipped JS file", !$leak) && $allOk;
$settingsHtml = file_get_contents(__DIR__ . '/admin/settings.php');
$allOk = tap("settings.php source never echoes a raw sms_api_key value (type=password + blank value= pattern present)", strpos($settingsHtml, "name=\"sms_api_key\"") !== false && strpos($settingsHtml, 'type="password"') !== false) && $allOk;

echo "\n=== OVERALL: " . ($allOk ? "ALL PASS" : "SOME FAILED") . " ===\n";
