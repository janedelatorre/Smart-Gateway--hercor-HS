-- =====================================================================
-- SMART GATEWAY — KIOSK/STATION AUTHORIZATION MIGRATION
-- =====================================================================
-- Adds server-side kiosk/station authorization so students can use the
-- scanner without an Admin/Staff account, while an Admin/Staff session
-- is still required to CREATE a kiosk session in the first place.
--
-- This is purely additive: no existing table/column is changed or
-- dropped, and no data is lost. Safe to run on a pre-kiosk database and
-- safe to re-run (CREATE TABLE IF NOT EXISTS / conditional INSERT).
--
-- HOW TO RUN (XAMPP / phpMyAdmin)
--   Select the smartgatewayproject_dev database, Import tab, choose
--   this file, Go. Or:
--   mysql -u root smartgatewayproject_dev < migration_kiosk_sessions.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- Table: kiosk_sessions
-- ---------------------------------------------------------------------
-- One row per authorized kiosk/station session. Only a hash of the
-- station's session token is stored (never the plaintext token — same
-- pattern as otp_codes.code_hash), so a database read alone can never
-- be used to impersonate an authorized kiosk. A session is valid only
-- while revoked_at IS NULL and last_activity is within the configured
-- kiosk_timeout_minutes window (enforced in includes/security.php).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kiosk_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash VARCHAR(255) NOT NULL,
    station_label VARCHAR(100) DEFAULT NULL,
    authorized_by VARCHAR(50) NOT NULL,
    authorized_by_user_id INT DEFAULT NULL,
    authorized_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    revoked_reason VARCHAR(30) DEFAULT NULL,
    FOREIGN KEY (authorized_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Guard index creation against re-running this file on an already-migrated DB.
SET @sg_has_kiosk_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'kiosk_sessions'
      AND INDEX_NAME   = 'idx_kiosk_sessions_lookup'
);
SET @sg_sql := IF(@sg_has_kiosk_idx = 0,
    'CREATE INDEX idx_kiosk_sessions_lookup ON kiosk_sessions(token_hash, revoked_at)',
    'DO 0');
PREPARE sg_stmt FROM @sg_sql;
EXECUTE sg_stmt;
DEALLOCATE PREPARE sg_stmt;

-- ---------------------------------------------------------------------
-- Setting: kiosk_timeout_minutes (separate from session_timeout_minutes —
-- Admin/Staff and Kiosk inactivity timeouts are intentionally independent)
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value)
SELECT 'kiosk_timeout_minutes', '10'
WHERE NOT EXISTS (
    SELECT 1 FROM settings WHERE setting_key = 'kiosk_timeout_minutes'
);
