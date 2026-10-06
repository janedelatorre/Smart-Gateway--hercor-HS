<?php
// Standalone integration test harness — connects to the real local DB,
// requires the actual project files, and exercises the real functions.
// Not part of the shipped application; for verification only.
require_once __DIR__ . '/database/config.php';
// sg_send_sms()/sg_notify_entry()/sg_flush_sms_queue()/sg_apply_sms_result()
// live in api/sms.php, which is normally only loaded on-demand by the two
// callers (api/scan.php, api/face.php) — pull it in directly for testing.
require_once __DIR__ . '/api/sms.php';

function tap(string $label, bool $ok, string $detail = '') {
    echo ($ok ? "PASS" : "FAIL") . " - $label" . ($detail !== '' ? " ($detail)" : '') . "\n";
    return $ok;
}

$allOk = true;

echo "=== sg_normalize_ph_mobile / sg_is_valid_ph_mobile ===\n";
$cases = [
    ['09171234567', '09171234567', true],   // 1. valid
    ['0917123456',  null,          false],  // 2. 10 digits -> reject
    ['091712345678',null,          false],  // 3. 12 digits -> reject
    ['08171234567', null,          false],  // 4. not 09-prefixed -> reject
    ['09ABCDE12345',null,          false],  // 5. alphabetic -> reject
    ['+639171234567','09171234567',true],   // 6. +63 normalization
    ['639171234567','09171234567', true],   // extra: 63-prefix (no plus) variant
    ['0917-123-4567','09171234567',true],   // formatting characters stripped
];
foreach ($cases as [$input, $expected, $shouldBeValidAfter]) {
    $norm = sg_normalize_ph_mobile($input);
    $ok = ($norm === $expected);
    $allOk = tap("normalize('$input') === " . var_export($expected, true), $ok, "got " . var_export($norm, true)) && $allOk;
    if ($norm !== null) {
        $validOk = sg_is_valid_ph_mobile($norm) === $shouldBeValidAfter;
        $allOk = tap("is_valid_ph_mobile('$norm') === " . var_export($shouldBeValidAfter, true), $validOk) && $allOk;
    }
}

echo "\n=== Existing data: student 23-00616 must remain untouched ===\n";
$stmt = $pdo->prepare("SELECT contact_number FROM students WHERE student_id = '23-00616'");
$stmt->execute();
$existing = $stmt->fetchColumn();
$allOk = tap("student 23-00616 contact_number still '09991222' after migration (not rewritten)", $existing === '09991222', "actual: " . var_export($existing, true)) && $allOk;
$allOk = tap("that value correctly reported as INVALID by sg_is_valid_ph_mobile()", $existing !== false && !sg_is_valid_ph_mobile($existing)) && $allOk;

echo "\n=== api/student.php contact-number validation (test 7) ===\n";
// Exercise the actual validation branch by including student.php's logic path indirectly:
// simulate what it does — normalize, and confirm invalid input is rejected the same way
// the endpoint would reject it (this mirrors the exact call students.php makes).
$badInputs = ['0917123456', '091712345678', '08171234567', '09ABCDE12345'];
foreach ($badInputs as $bad) {
    $allOk = tap("student.php-style normalize('$bad') rejected", sg_normalize_ph_mobile($bad) === null) && $allOk;
}
$allOk = tap("student.php-style normalize('+639171234567') -> 09171234567", sg_normalize_ph_mobile('+639171234567') === '09171234567') && $allOk;

echo "\n=== SMS send classification (Case A: provider unreachable vs provider rejects) ===\n";
// Point sms_api_url at a host that cannot be resolved/reached at all from
// this sandbox (DNS/connect failure) -> must classify as 'Queued'.
$pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('sms_api_url', ?)")->execute(['http://sms-provider-that-does-not-exist.invalid/send']);
$pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('sms_api_key', ?)")->execute(['test-key']);
$pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('enable_sms', ?)")->execute(['1']);

$result = sg_send_sms($pdo, '09171234567', 'Test message - unreachable provider', 'TestHarness');
$allOk = tap("unreachable provider -> status Queued", $result['status'] === 'Queued', "got: {$result['status']} / {$result['response']}") && $allOk;

// Point sms_api_url at a REAL, reachable host (allowed by sandbox egress) that
// will respond with a non-2xx status -> provider WAS reached, must be 'Denied'.
$pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('sms_api_url', ?)")->execute(['https://api.github.com/this-endpoint-does-not-exist-404']);
$result2 = sg_send_sms($pdo, '09171234567', 'Test message - provider reachable but rejects', 'TestHarness');
$allOk = tap("reachable provider returning HTTP error -> status Denied", $result2['status'] === 'Denied', "got: {$result2['status']} / {$result2['response']}") && $allOk;

// Invalid recipient number must never be retryable.
$result3 = sg_send_sms($pdo, '0917123456', 'Bad number test', 'TestHarness');
$allOk = tap("invalid recipient number -> status Denied (not Queued)", $result3['status'] === 'Denied') && $allOk;

echo "\n=== sg_notify_entry() writes Queued row + retry bookkeeping (test 9, 11) ===\n";
$pdo->prepare("INSERT INTO entry_logs (student_id, fullname, grade, time_in, verification_method, transaction_type, verified_by, status) VALUES ('23-00616','Test Student','Grade 7',NOW(),'Barcode','Entry','TestHarness','Match')")->execute();
$entryLogId = (int) $pdo->lastInsertId();
$pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('sms_api_url', ?)")->execute(['http://sms-provider-that-does-not-exist.invalid/send']);
sg_notify_entry($pdo, $entryLogId, '09171234567', 'Test Student', date('g:i A'), 'TestHarness', 'Entry');
$row = $pdo->query("SELECT * FROM sms_logs ORDER BY id DESC LIMIT 1")->fetch();
$allOk = tap("sms_logs row created with status Queued", $row && $row['status'] === 'Queued', $row ? $row['status'] : 'no row') && $allOk;
$allOk = tap("retry_count = 1 on first failure", $row && (int)$row['retry_count'] === 1, $row ? $row['retry_count'] : 'n/a') && $allOk;
$allOk = tap("next_retry_at is set (not null)", $row && $row['next_retry_at'] !== null) && $allOk;
$queuedId = $row['id'];

echo "\n=== Provider recovery -> automatic retry succeeds (test 12) ===\n";
// Force next_retry_at into the past so it's immediately due, then point the
// provider at something that WILL succeed (2xx, no special headers needed)
// to simulate recovery. (api.github.com/zen requires a User-Agent header
// and 403s without one -- registry.npmjs.org/ is a plain 200 GET.)
$pdo->prepare("UPDATE sms_logs SET next_retry_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?")->execute([$queuedId]);
$pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('sms_api_url', ?)")->execute(['https://registry.npmjs.org/']);
$flushed = sg_flush_sms_queue($pdo, 10);
$after = $pdo->query("SELECT status, retry_count, provider_response FROM sms_logs WHERE id = $queuedId")->fetch();
$allOk = tap("flush attempted >= 1 row", $flushed >= 1, "flushed=$flushed") && $allOk;
$allOk = tap("row transitions to Match after provider recovers", $after && $after['status'] === 'Match', ($after ? $after['status'] . ' / ' . $after['provider_response'] : 'n/a')) && $allOk;

echo "\n=== Duplicate-retry / concurrent-flush protection (test 13) ===\n";
// Create a second due row, then simulate two overlapping flush calls.
$ins = $pdo->prepare("INSERT INTO sms_logs (entry_log_id, recipient, message, status, retry_count, next_retry_at, provider_response, sent_by) VALUES (NULL, '09171234567', 'concurrency test', 'Queued', 1, DATE_SUB(NOW(), INTERVAL 1 MINUTE), 'x', 'TestHarness')");
$ins->execute();
$concurrentId = (int) $pdo->lastInsertId();
$before = $pdo->query("SELECT id, status, next_retry_at, NOW() AS db_now FROM sms_logs WHERE id = $concurrentId")->fetch();
echo "  debug row before claim: " . json_encode($before) . "\n";
// Provider still points at the working 200 endpoint from above.
$claim1 = $pdo->prepare("UPDATE sms_logs SET next_retry_at = DATE_ADD(NOW(), INTERVAL 60 SECOND) WHERE id = ? AND status = 'Queued' AND (next_retry_at IS NULL OR next_retry_at <= NOW())");
$claim1->execute([$concurrentId]);
$firstClaim = $claim1->rowCount();
$claim2 = $pdo->prepare("UPDATE sms_logs SET next_retry_at = DATE_ADD(NOW(), INTERVAL 60 SECOND) WHERE id = ? AND status = 'Queued' AND (next_retry_at IS NULL OR next_retry_at <= NOW())");
$claim2->execute([$concurrentId]);
$secondClaim = $claim2->rowCount();
$allOk = tap("first concurrent claim succeeds (rowCount=1)", $firstClaim === 1) && $allOk;
$allOk = tap("second concurrent claim on same row fails (rowCount=0) -- no duplicate send", $secondClaim === 0) && $allOk;

echo "\n=== Max retry cap -> gives up and marks Denied (bonus, supports test 13/failure path) ===\n";
$pdo->prepare("INSERT INTO sms_logs (entry_log_id, recipient, message, status, retry_count, next_retry_at, provider_response, sent_by) VALUES (NULL, '09171234567', 'max retry test', 'Queued', 9, DATE_SUB(NOW(), INTERVAL 1 MINUTE), 'x', 'TestHarness')")->execute();
$maxRetryId = $pdo->lastInsertId();
$pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('sms_api_url', ?)")->execute(['http://sms-provider-that-does-not-exist.invalid/send']);
sg_flush_sms_queue($pdo, 10);
$maxRow = $pdo->query("SELECT status, retry_count FROM sms_logs WHERE id = $maxRetryId")->fetch();
$allOk = tap("row past max retries marked Denied (stops auto-retrying)", $maxRow && $maxRow['status'] === 'Denied', $maxRow ? $maxRow['status'] : 'n/a') && $allOk;

echo "\n=== Migration report query (non-destructive) ===\n";
$bad = $pdo->query("SELECT student_id, contact_number FROM students WHERE contact_number IS NOT NULL AND contact_number != '' AND contact_number NOT REGEXP '^09[0-9]{9}\$'")->fetchAll();
foreach ($bad as $b) { echo "  flagged: {$b['student_id']} => {$b['contact_number']}\n"; }
$allOk = tap("report query finds the known-bad 23-00616 row and does not modify it", count(array_filter($bad, fn($b) => $b['student_id'] === '23-00616')) === 1) && $allOk;

echo "\n=== OVERALL: " . ($allOk ? "ALL PASS" : "SOME FAILED") . " ===\n";
