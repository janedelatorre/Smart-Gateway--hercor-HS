#!/bin/bash
set -uo pipefail
BASE="http://127.0.0.1:8099"

pass() { echo "PASS - $1"; }
fail() { echo "FAIL - $1"; }

echo "=== Seed a valid kiosk session directly (bypassing the admin-authorize UI, since we're not driving a browser) ==="
KTOKEN=$(php -r 'echo bin2hex(random_bytes(32));')
KHASH=$(php -r "echo hash('sha256', '$KTOKEN');")
mysql -u root smartgatewayproject_dev -e "INSERT INTO kiosk_sessions (token_hash, authorized_by, authorized_by_name, status, ip_address, user_agent, authorized_at, last_activity) VALUES ('$KHASH', 1, 'admin', 'Authorized', '127.0.0.1', 'test-harness', NOW(), NOW());"
echo "  kiosk session seeded"

echo "=== TEST: valid kiosk session reports authorized via api/kiosk.php?action=status ==="
RESP=$(curl -s --cookie "sg_kiosk=$KTOKEN" "$BASE/api/kiosk.php?action=status")
echo "  response: $RESP"
echo "$RESP" | grep -q '"authorized":true' && pass "valid kiosk token reports authorized" || fail "valid kiosk token NOT recognized: $RESP"

echo "=== TEST (regression): Barcode verification via kiosk session, valid student ==="
# api/scan.php requires a CSRF token bound to the KIOSK's own session context.
# The kiosk page itself doesn't use PHP $_SESSION csrf (kiosk has no login session),
# so check what sg_scanner_access_ok()/api/scan.php actually requires for CSRF.
grep -n "verify_csrf_token" api/scan.php | head -3
SCAN_RESP=$(curl -s --cookie "sg_kiosk=$KTOKEN" "$BASE/api/scan.php" --data-urlencode "action=verify_barcode" --data-urlencode "barcode=2024-400831" --data-urlencode "csrf_token=x")
echo "  response: $SCAN_RESP"

echo "=== TEST (regression): Facial endpoint auth guard under kiosk session (no image = should fail validation, not auth) ==="
FACE_RESP=$(curl -s --cookie "sg_kiosk=$KTOKEN" "$BASE/api/face.php" --data-urlencode "action=verify_face" --data-urlencode "csrf_token=x")
echo "  response: $FACE_RESP"

echo "=== TEST (regression): duplicate-scan / already-inside protection (scan same student twice quickly) ==="
SCAN1=$(curl -s --cookie "sg_kiosk=$KTOKEN" "$BASE/api/scan.php" --data-urlencode "action=verify_barcode" --data-urlencode "barcode=2024-461738" --data-urlencode "csrf_token=x")
SCAN2=$(curl -s --cookie "sg_kiosk=$KTOKEN" "$BASE/api/scan.php" --data-urlencode "action=verify_barcode" --data-urlencode "barcode=2024-461738" --data-urlencode "csrf_token=x")
echo "  scan1: $SCAN1"
echo "  scan2: $SCAN2"

echo "=== TEST (regression): Entry/Exit toggling across two scans ==="
mysql -u root smartgatewayproject_dev -e "SELECT student_id, transaction_type, status, time_in FROM entry_logs ORDER BY id DESC LIMIT 5;"

echo "=== TEST: server-unavailable kiosk behavior is client-side only (JS) -- confirm via code, not HTTP ==="
grep -q "sgShowServerUnavailable" assets/js/scanner.js && pass "scanner.js still contains the Case B server-unavailable handling (code-level regression check)" || fail "sgShowServerUnavailable missing from scanner.js!"
grep -q "TypeError" assets/js/scanner.js && pass "network-failure detection (sgIsNetworkFailure) still present" || fail "sgIsNetworkFailure logic missing!"

echo "=== TEST: contact-number validation regression (server-side) ==="
php -r '
require_once "database/config.php";
require_once "includes/security.php";
var_dump(sg_normalize_ph_mobile("09171234567") === "09171234567");
var_dump(sg_normalize_ph_mobile("+639171234567") === "09171234567");
var_dump(sg_normalize_ph_mobile("0917123456") === null);
'

echo "=== TEST: Phase 2 local assets still present (no reverted CDN) ==="
grep -q "cdn.jsdelivr.net\|cdnjs.cloudflare.com" admin/students.php admin/scanner.php login.php 2>/dev/null && fail "a CDN reference reappeared in a page that should use local assets" || pass "no CDN references found in reviewed pages (Phase 2 local-assets intact)"
[ -f assets/vendor/face-api/face-api.min.js ] && pass "local face-api.js vendor file still present" || fail "local face-api.js vendor file missing!"

echo "=== TEST: Phase 2.5 response-before-SMS behavior intact (code-level) ==="
grep -q "sg_send_response_then_continue" api/scan.php && pass "api/scan.php still uses sg_send_response_then_continue (response-before-SMS)" || fail "response-before-SMS call missing from api/scan.php!"

echo "=== TEST: Phase 3 SMS queue/backoff functions still present ==="
for fn in sg_flush_sms_queue sg_apply_sms_result sg_sms_retry_backoff_seconds SG_SMS_MAX_RETRIES; do
  grep -q "$fn" api/sms.php && pass "api/sms.php still defines/uses $fn" || fail "$fn missing from api/sms.php!"
done

echo "=== DONE ==="
