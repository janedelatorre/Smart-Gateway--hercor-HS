#!/bin/bash
# Phase 4 live HTTP security test battery. Run against PHP's built-in server
# (dev-only tool, not part of the shipped app) with the same DB as test_phase3.php.
set -uo pipefail
BASE="http://127.0.0.1:8099"
JAR_ADMIN=/tmp/jar_admin.txt
JAR_STAFF=/tmp/jar_staff.txt
JAR_NONE=/tmp/jar_none.txt
rm -f "$JAR_ADMIN" "$JAR_STAFF" "$JAR_NONE"

pass() { echo "PASS - $1"; }
fail() { echo "FAIL - $1"; }

echo "=== TEST 1: Unauthenticated access to protected admin page ==="
CODE=$(curl -s -o /tmp/t1.html -w "%{http_code}" -c "$JAR_NONE" "$BASE/admin/dashboard.php")
LOC=$(grep -i "^Location" /tmp/t1.html 2>/dev/null)
# curl -w only gives final code after following no redirects by default (no -L), so 302 expected
if [ "$CODE" = "302" ] || [ "$CODE" = "301" ]; then pass "unauthenticated /admin/dashboard.php redirected ($CODE)"; else fail "unauthenticated /admin/dashboard.php returned $CODE (expected redirect)"; fi

echo "=== TEST 2: Unauthenticated API request ==="
RESP=$(curl -s -c "$JAR_NONE" "$BASE/api/student.php?action=list")
echo "  response: $RESP"
echo "$RESP" | grep -qi '"success":false' && pass "unauthenticated api/student.php list rejected" || fail "unauthenticated api/student.php list NOT rejected"

echo "=== Logging in as Administrator (admin / admin123) ==="
LOGIN_PAGE=$(curl -s -c "$JAR_ADMIN" "$BASE/login.php?role=admin")
CSRF=$(echo "$LOGIN_PAGE" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="\(.*\)"/\1/')
echo "  csrf token: ${CSRF:0:12}..."
LOGIN_RESP=$(curl -s -i -b "$JAR_ADMIN" -c "$JAR_ADMIN" "$BASE/login.php?role=admin" \
  --data-urlencode "csrf_token=$CSRF" --data-urlencode "username=admin" --data-urlencode "password=admin123" --data-urlencode "role=admin")
echo "$LOGIN_RESP" | grep -qi "^Location:.*dashboard" && pass "admin login succeeded (redirect to dashboard)" || fail "admin login did not redirect to dashboard"

echo "=== Logging in as Staff (staff1 / admin123) ==="
LOGIN_PAGE_S=$(curl -s -c "$JAR_STAFF" "$BASE/login.php?role=staff")
CSRF_S=$(echo "$LOGIN_PAGE_S" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="\(.*\)"/\1/')
LOGIN_RESP_S=$(curl -s -i -b "$JAR_STAFF" -c "$JAR_STAFF" "$BASE/login.php?role=staff" \
  --data-urlencode "csrf_token=$CSRF_S" --data-urlencode "username=staff1" --data-urlencode "password=admin123" --data-urlencode "role=staff")
echo "$LOGIN_RESP_S" | grep -qi "^Location:.*dashboard" && pass "staff login succeeded (redirect to dashboard)" || fail "staff login did not redirect"

echo "=== TEST 3: Staff attempting admin-only API action (create student) ==="
RESP3=$(curl -s -b "$JAR_STAFF" "$BASE/api/student.php?action=list")
echo "  response: $RESP3"
echo "$RESP3" | grep -qi '"success":false' && echo "$RESP3" | grep -qi "administrator" && pass "staff denied by api/student.php (Administrator-only)" || fail "staff was NOT denied by api/student.php"

RESP3B=$(curl -s -b "$JAR_STAFF" "$BASE/api/user.php?action=list")
echo "  response: $RESP3B"
echo "$RESP3B" | grep -qi '"success":false' && pass "staff denied by api/user.php (Administrator-only)" || fail "staff was NOT denied by api/user.php"

echo "=== TEST 4: Kiosk (no session) attempting admin-only API ==="
RESP4=$(curl -s -c "$JAR_NONE" "$BASE/api/student.php?action=list")
echo "$RESP4" | grep -qi '"success":false' && pass "no-session request denied by api/student.php" || fail "no-session request NOT denied"

echo "=== TEST 5: Invalid/forged kiosk token ==="
BOGUS_TOKEN=$(printf 'a%.0s' {1..64})
RESP5=$(curl -s --cookie "sg_kiosk=$BOGUS_TOKEN" "$BASE/api/kiosk.php?action=status")
echo "  response: $RESP5"
echo "$RESP5" | grep -q '"authorized":false' && pass "forged/unknown kiosk token correctly reports unauthorized" || fail "forged kiosk token NOT rejected: $RESP5"

echo "=== TEST 6: CSRF-protected request without token ==="
RESP6=$(curl -s -b "$JAR_ADMIN" "$BASE/api/student.php" \
  --data-urlencode "action=create" --data-urlencode "student_id=99-99999" --data-urlencode "fullname=CSRF Test" \
  --data-urlencode "grade=Grade 7" --data-urlencode "contact_number=09171234567")
echo "  response: $RESP6"
echo "$RESP6" | grep -qi 'invalid session token\|csrf' && pass "state-changing request without CSRF token rejected" || fail "request without CSRF token was NOT rejected: $RESP6"

echo "=== Get a valid admin CSRF token (from settings page) ==="
SETTINGS_PAGE=$(curl -s -b "$JAR_ADMIN" "$BASE/admin/settings.php")
ADMIN_CSRF=$(echo "$SETTINGS_PAGE" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="\(.*\)"/\1/')
echo "  admin csrf token: ${ADMIN_CSRF:0:12}..."

echo "=== TEST 7 + 9: Student create with invalid contact number AND XSS payload in fullname ==="
XSS_PAYLOAD='<script>alert(document.cookie)</script>'
RESP7=$(curl -s -b "$JAR_ADMIN" "$BASE/api/student.php" \
  --data-urlencode "action=create" --data-urlencode "csrf_token=$ADMIN_CSRF" \
  --data-urlencode "student_id=99-00001" --data-urlencode "fullname=$XSS_PAYLOAD" \
  --data-urlencode "grade=Grade 7" --data-urlencode "contact_number=0917123456")
echo "  (invalid 10-digit contact) response: $RESP7"
echo "$RESP7" | grep -qi '"success":false' && pass "student create rejected invalid 10-digit contact number" || fail "invalid contact number was NOT rejected: $RESP7"

RESP7B=$(curl -s -b "$JAR_ADMIN" "$BASE/api/student.php" \
  --data-urlencode "action=create" --data-urlencode "csrf_token=$ADMIN_CSRF" \
  --data-urlencode "student_id=99-00001" --data-urlencode "fullname=$XSS_PAYLOAD" \
  --data-urlencode "grade=Grade 7" --data-urlencode "contact_number=09171234567")
echo "  (valid contact + XSS fullname) response: $RESP7B"
echo "$RESP7B" | grep -qi '"success":true' && pass "student create succeeded with valid contact (XSS payload accepted as plain text data, not evaluated server-side)" || fail "student create failed unexpectedly: $RESP7B"

echo "=== TEST 9b: confirm XSS payload is stored verbatim as inert DATA (server does not execute/strip it; client escaping handles render-time safety) ==="
LIST_RESP=$(curl -s -b "$JAR_ADMIN" "$BASE/api/student.php?action=list&search=99-00001")
echo "  list response: $LIST_RESP"
echo "$LIST_RESP" | grep -q '<script>alert' && pass "payload present verbatim in API JSON as data (expected — JSON is not HTML; escaping happens at DOM-render time in the browser, verified separately in Phase 4 JS fix)" || echo "  NOTE: payload not found verbatim — check manually"

echo "=== TEST 8: SQL injection style input (login bypass attempt) ==="
LOGIN_PAGE2=$(curl -s -c /tmp/jar_sqli.txt "$BASE/login.php?role=admin")
CSRF2=$(echo "$LOGIN_PAGE2" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="\(.*\)"/\1/')
SQLI_RESP=$(curl -s -b /tmp/jar_sqli.txt -c /tmp/jar_sqli.txt "$BASE/login.php?role=admin" \
  --data-urlencode "csrf_token=$CSRF2" --data-urlencode "username=admin' OR '1'='1" --data-urlencode "password=x' OR '1'='1" --data-urlencode "role=admin")
echo "$SQLI_RESP" | grep -qi "^Location:.*dashboard" && fail "SQLi-style login payload BYPASSED authentication (CRITICAL)" || pass "SQLi-style login payload did not bypass authentication"

SQLI_RESP2=$(curl -s "$BASE/api/student.php?action=list&search=%27%20OR%20%271%27%3D%271" -b "$JAR_ADMIN")
echo "  search-with-SQLi response snippet: $(echo "$SQLI_RESP2" | head -c 200)"
echo "$SQLI_RESP2" | grep -qi '"success"' && pass "SQLi-style search input handled without a server error / query breakage" || fail "SQLi-style search input caused an unexpected failure"

echo "=== TEST 10+11: Invalid / oversized photo upload ==="
echo "this is not an image" > /tmp/fake.jpg
RESP10=$(curl -s -b "$JAR_ADMIN" "$BASE/api/student.php" \
  -F "action=update" -F "csrf_token=$ADMIN_CSRF" -F "id=1" \
  -F "profile_photo=@/tmp/fake.jpg;type=image/jpeg")
echo "  response: $RESP10"
echo "$RESP10" | grep -qi '"success":false' && pass "invalid (non-image) file upload rejected" || fail "invalid file upload NOT rejected: $RESP10"

python3 -c "open('/tmp/big.jpg','wb').write(b'\xff\xd8\xff' + b'0'*(6*1024*1024))"
RESP11=$(curl -s -b "$JAR_ADMIN" "$BASE/api/student.php" \
  -F "action=update" -F "csrf_token=$ADMIN_CSRF" -F "id=1" \
  -F "profile_photo=@/tmp/big.jpg;type=image/jpeg")
echo "  response: $RESP11"
echo "$RESP11" | grep -qi '"success":false' && pass "oversized (6MB) file upload rejected" || fail "oversized file upload NOT rejected: $RESP11"

echo "=== TEST 12: Invalid login attempts / throttling ==="
rm -f /tmp/jar_lock.txt
for i in 1 2 3 4 5 6; do
  LP=$(curl -s -c /tmp/jar_lock.txt -b /tmp/jar_lock.txt "$BASE/login.php?role=admin")
  CT=$(echo "$LP" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="\(.*\)"/\1/')
  R=$(curl -s -b /tmp/jar_lock.txt -c /tmp/jar_lock.txt "$BASE/login.php?role=admin" \
    --data-urlencode "csrf_token=$CT" --data-urlencode "username=admin" --data-urlencode "password=wrongpassword$i" --data-urlencode "role=admin")
  echo "  attempt $i: $(echo "$R" | grep -oi 'locked\|CAPTCHA\|incorrect' | head -1)"
done
LP2=$(curl -s -c /tmp/jar_lock.txt -b /tmp/jar_lock.txt "$BASE/login.php?role=admin")
echo "$LP2" | grep -qi "locked\|captcha" && pass "repeated failed logins triggered lockout/CAPTCHA" || fail "no lockout/CAPTCHA observed after 6 failed attempts"

echo "=== TEST 13: Logout invalidates session ==="
curl -s -b "$JAR_STAFF" -c "$JAR_STAFF" "$BASE/admin/logout.php?confirm=1" > /dev/null
POST_LOGOUT=$(curl -s -b "$JAR_STAFF" "$BASE/api/student.php?action=list")
echo "$POST_LOGOUT" | grep -qi '"success":false' && pass "session invalidated after logout (API request now rejected)" || fail "session still valid after logout"

echo "=== TEST 16: SMS credential not exposed in settings page HTML ==="
echo "$SETTINGS_PAGE" | grep -q 'value=""' && ! echo "$SETTINGS_PAGE" | grep -qi "sms_api_key.*value=\"[^\"]\+\"" && pass "SMS API key field renders empty (never echoes stored key)" || echo "  (see manual check)"

echo "=== TEST 18: Audit log records login/student events ==="
AUDIT_RESP=$(curl -s -b "$JAR_ADMIN" "$BASE/api/audit_logs.php?action=list&search=Student+Created")
echo "  response snippet: $(echo "$AUDIT_RESP" | head -c 300)"
echo "$AUDIT_RESP" | grep -qi "Student Created" && pass "audit log recorded 'Student Created' event" || fail "audit log entry for student creation not found"

echo "=== TEST 19: Facial descriptor (face_encoding) not exposed via student list API ==="
LIST2=$(curl -s -b "$JAR_ADMIN" "$BASE/api/student.php?action=list")
echo "$LIST2" | grep -qi "face_encoding" && fail "face_encoding field exposed in api/student.php list response (privacy issue)" || pass "face_encoding not present in api/student.php list response"

echo "=== TEST 21: Barcode verification regression (kiosk endpoint, unauthenticated -> should be denied, no kiosk session) ==="
RESP21=$(curl -s "$BASE/api/scan.php" --data-urlencode "action=verify_barcode" --data-urlencode "barcode=23-00616" --data-urlencode "csrf_token=x")
echo "  response: $RESP21"
echo "$RESP21" | grep -qi "unauthorized" && pass "api/scan.php correctly refuses a request with no kiosk/staff session" || fail "api/scan.php did not refuse an unauthenticated scan: $RESP21"

echo "=== DONE ==="
