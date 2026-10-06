-- =====================================================================
-- MIGRATION V22 PHASE 3: Contact number + SMS retry queue
-- =====================================================================
-- For EXISTING installations only. Run this once against your existing
-- database (phpMyAdmin > SQL tab, or
-- `mysql -u root smartgatewayproject_dev < database/migration_v22_phase3_contact_sms_queue.sql`).
--
-- Safe to run once; re-running will error with "Duplicate column name" /
-- "Duplicate key name", which just means it's already applied.
--
-- SCOPE (per the Phase 3 architecture decision — online-first,
-- offline-resilient SMS only, NOT offline verification): this migration
-- only touches sms_logs. There is no entry_logs change — Case B (kiosk
-- cannot reach the server) creates no transaction and therefore has
-- nothing to key/de-duplicate; see api/scan.php / api/face.php / SG_SERVER
-- unreachable handling in assets/js/scanner.js for that path.
--
-- WHAT THIS DOES NOT DO (by design):
-- It does NOT rewrite any existing students.contact_number value.
-- Inspection of the current seed/backup data found at least one row
-- that does not match the enforced PH format (student 23-00616,
-- contact_number '09991222' -- 8 digits, not 11). Silently reformatting
-- or blanking values like this on migration risks corrupting a real
-- guardian contact with no way to recover the original digits, so
-- format enforcement is applied going forward only, at write-time
-- (student create/update) and at send-time (sg_send_sms). See the
-- SELECT at the bottom to find existing non-conforming numbers so they
-- can be corrected by staff who can verify the right value with the
-- guardian -- they stay exactly as stored until then.
-- =====================================================================

-- ---- sms_logs: queue state for Case A (server reachable, SMS provider/Internet down) ----
ALTER TABLE sms_logs
    MODIFY COLUMN status ENUM('Match','Denied','Pending','Queued') NOT NULL DEFAULT 'Pending';

ALTER TABLE sms_logs
    ADD COLUMN retry_count INT NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN next_retry_at DATETIME DEFAULT NULL AFTER retry_count;

CREATE INDEX idx_sms_logs_queue ON sms_logs(status, next_retry_at);

-- ---- Report only: existing contact numbers that do not match the enforced
-- ---- 09XXXXXXXXX format. Review and correct manually -- not rewritten here.
SELECT id, student_id, fullname, contact_number
FROM students
WHERE contact_number IS NOT NULL
  AND contact_number != ''
  AND contact_number NOT REGEXP '^09[0-9]{9}$';

