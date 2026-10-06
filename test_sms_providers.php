<?php
// Dev-only: provider routing / request construction checks. No real SMS is sent.
// Uses an in-memory SQLite stand-in for the settings table; stubs the two app helpers.
define('SG_INTERNAL_CALL', true);
function get_setting(PDO $pdo, string $k, $d = null) { $s = $pdo->prepare("SELECT v FROM settings WHERE k=?"); $s->execute([$k]); $r = $s->fetchColumn(); return $r === false ? $d : $r; }
function sg_is_valid_ph_mobile(string $v): bool { return (bool) preg_match('/^09\d{9}$/', $v); }
function create_notification(...$a) {}
require __DIR__ . '/api/sms.php';

$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT)");
function setv($pdo, $k, $v) { $pdo->prepare("REPLACE INTO settings (k,v) VALUES (?,?)")->execute([$k, $v]); }
function tap($l, $ok, $d = '') { echo ($ok ? 'PASS' : 'FAIL') . " - $l" . ($d ? " ($d)" : '') . "\n"; return $ok; }
$ok = true;

$ok = tap('default provider (nothing set) = iprog', sg_get_active_sms_provider($pdo) === 'iprog') && $ok;
setv($pdo, 'sms_active_provider', 'bogus');
$ok = tap('invalid provider value falls back to iprog', sg_get_active_sms_provider($pdo) === 'iprog') && $ok;
setv($pdo, 'sms_active_provider', 'semaphore');
$ok = tap('switch to semaphore', sg_get_active_sms_provider($pdo) === 'semaphore') && $ok;
setv($pdo, 'sms_active_provider', 'iprog');
$ok = tap('switch back to iprog', sg_get_active_sms_provider($pdo) === 'iprog') && $ok;

$req = sg_build_semaphore_request('KEY123', 'MySender', '09171234567', 'hello');
$ok = tap('semaphore URL', $req['url'] === 'https://api.semaphore.co/api/v4/messages') && $ok;
$ok = tap('semaphore fields apikey/number/message/sendername', $req['fields'] === ['apikey'=>'KEY123','number'=>'09171234567','message'=>'hello','sendername'=>'MySender']) && $ok;

// iprog active, no config -> unchanged Pending message
setv($pdo, 'enable_sms', '1');
$r = sg_send_sms($pdo, '09171234567', 'x');
$ok = tap('iprog active + unconfigured -> Pending (iProg message)', $r['status'] === 'Pending' && strpos($r['response'], 'No SMS provider') !== false) && $ok;
// iprog active, unreachable host -> Queued via iprog path
setv($pdo, 'sms_api_url', 'http://iprog-does-not-exist.invalid/api'); setv($pdo, 'sms_api_key', 'IPROGSECRET');
$r = sg_send_sms($pdo, '09171234567', 'x');
$ok = tap('iprog active + unreachable -> Queued', $r['status'] === 'Queued' && strpos($r['response'], 'IPROGSECRET') === false) && $ok;

// semaphore active, not configured -> Pending, iprog not used
setv($pdo, 'sms_active_provider', 'semaphore');
$r = sg_send_sms($pdo, '09171234567', 'x');
$ok = tap('semaphore active + unconfigured -> Pending (Semaphore message, iProg not used)', $r['status'] === 'Pending' && strpos($r['response'], 'Semaphore') === 0) && $ok;
// semaphore configured -> routes to Semaphore (network is blocked in sandbox => Queued connectivity; or Denied if reachable)
setv($pdo, 'semaphore_api_key', 'SEMSECRET'); setv($pdo, 'semaphore_sender_name', 'MySender');
$r = sg_send_sms($pdo, '09171234567', 'x');
$ok = tap('semaphore active + configured -> routed to Semaphore', strpos($r['response'], 'Semaphore') === 0, $r['status'] . ': ' . $r['response']) && $ok;
$ok = tap('no API key in response', strpos($r['response'], 'SEMSECRET') === false && strpos($r['response'], 'IPROGSECRET') === false) && $ok;

// classifier
$m = sg_classify_semaphore_response(200, json_encode([['message_id'=>123,'status'=>'Pending']]), ['SEMSECRET']);
$ok = tap('Semaphore success payload -> Match + message_id', $m['status'] === 'Match' && $m['message_id'] === '123') && $ok;
$d = sg_classify_semaphore_response(403, '{"apikey":["Invalid API key SEMSECRET"]}', ['SEMSECRET']);
$ok = tap('invalid key payload -> Denied, key redacted', $d['status'] === 'Denied' && strpos($d['response'], 'SEMSECRET') === false) && $ok;
$f = sg_classify_semaphore_response(200, json_encode([['message_id'=>1,'status'=>'Failed']]));
$ok = tap('Semaphore Failed status -> Denied', $f['status'] === 'Denied') && $ok;

// iprog classifier unchanged
$ip = sg_classify_iprog_response(200, json_encode(['status'=>200,'message'=>'ok','message_id'=>'iSms-1']));
$ok = tap('iProg classifier unchanged', $ip['status'] === 'Match' && $ip['message_id'] === 'iSms-1') && $ok;

// disabled / invalid number checks apply to both providers
setv($pdo, 'enable_sms', '0');
$ok = tap('SMS disabled -> Pending for semaphore', sg_send_sms($pdo, '09171234567', 'x')['status'] === 'Pending') && $ok;
setv($pdo, 'enable_sms', '1');
$ok = tap('invalid number -> Denied', sg_send_sms($pdo, '123', 'x')['status'] === 'Denied') && $ok;
echo $ok ? "\nALL PASS\n" : "\nFAILURES\n";
