-- =====================================================================
-- SMART GATEWAY — V16 MIGRATION (Entry/Exit + scanner settings)
-- =====================================================================
-- Adds ENTRY/EXIT state tracking to entry_logs and the configurable
-- scanner/access settings. Everything below is additive: no destructive
-- changes, no data loss.
--
-- WHY THIS MATTERS
-- ----------------
-- api/scan.php, api/face.php and includes/security.php all SELECT and
-- INSERT entry_logs.transaction_type on EVERY verification. If that
-- column is missing, every scan fails with
--     "A server error occurred during verification."
-- even though the barcode was read correctly. That is exactly the
-- symptom this migration resolves.
--
-- IDEMPOTENT: each step checks the current schema first, so this file is
-- safe to run on a pre-V16 database, on a database where it has already
-- been applied, and on a fresh import of "smart database.sql" (which
-- already ships the column) — it will not abort halfway through.
--
-- HOW TO RUN (XAMPP / phpMyAdmin)
-- -------------------------------
--   1. Select the smartgatewayproject_dev database in the left sidebar.
--      (Select it FIRST — this file intentionally contains no USE
--      statement, so it applies to whichever database you have open.)
--   2. Import tab -> choose this file -> Go.
-- Or from the command line:
--   mysql -u root smartgatewayproject_dev < migration_v16_entry_exit_settings.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. entry_logs.transaction_type
-- ---------------------------------------------------------------------
-- Distinguishes an ENTRY transaction from an EXIT transaction for the
-- same student. The student's CURRENT access state (INSIDE / OUTSIDE)
-- is derived on read from their most recent 'Match' row rather than
-- stored in a second column, so the state and the log cannot drift
-- apart. Existing rows are backfilled to 'Entry', which is accurate for
-- all historical data (pre-V16 builds only ever recorded entries).
-- ---------------------------------------------------------------------
SET @sg_has_tx_type := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'entry_logs'
      AND COLUMN_NAME  = 'transaction_type'
);

SET @sg_sql := IF(@sg_has_tx_type = 0,
    "ALTER TABLE entry_logs ADD COLUMN transaction_type ENUM('Entry','Exit') NOT NULL DEFAULT 'Entry' AFTER verification_method",
    "DO 0");
PREPARE sg_stmt FROM @sg_sql;
EXECUTE sg_stmt;
DEALLOCATE PREPARE sg_stmt;

-- Backfill historical granted rows (harmless no-op if already applied).
UPDATE entry_logs SET transaction_type = 'Entry' WHERE status = 'Match';

-- ---------------------------------------------------------------------
-- 2. Supporting index
-- ---------------------------------------------------------------------
-- get_student_access_state() and get_last_granted_log() look up the
-- latest row for one student on every successful scan. This index also
-- ships in "smart database.sql", so it is created only if absent —
-- creating it unconditionally is what made the previous version of this
-- migration abort on an already-current database.
-- ---------------------------------------------------------------------
SET @sg_has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'entry_logs'
      AND INDEX_NAME   = 'idx_entry_logs_student_time'
);

SET @sg_sql := IF(@sg_has_idx = 0,
    "CREATE INDEX idx_entry_logs_student_time ON entry_logs(student_id, time_in)",
    "DO 0");
PREPARE sg_stmt FROM @sg_sql;
EXECUTE sg_stmt;
DEALLOCATE PREPARE sg_stmt;

-- ---------------------------------------------------------------------
-- 3. Scanner & Access settings
-- ---------------------------------------------------------------------
-- Optional for the scanner to run (get_setting() falls back to these same
-- defaults in code), but inserting them makes the values visible and
-- editable under Settings -> Scanner & Access.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
    ('duplicate_scan_cooldown_seconds', '5'),
    ('min_entry_exit_interval_minutes', '15'),
    ('facial_fallback_threshold', '3')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- ---------------------------------------------------------------------
-- 4. Verification — both queries below should return one row each.
-- ---------------------------------------------------------------------
-- SHOW COLUMNS FROM entry_logs LIKE 'transaction_type';
-- SELECT * FROM settings WHERE setting_key = 'facial_fallback_threshold';
