-- =====================================================================
-- SMART GATEWAY — MERGED FINAL KIOSK SESSION UPGRADE
-- =====================================================================
-- Upgrades an existing Phase 8 kiosk_sessions table to the V17-style
-- kiosk station structure. Student, entry, SMS, and facial data are not
-- changed. Back up the database before running this migration.
-- Fresh installations should import database/smart database.sql instead.
-- =====================================================================

SET @has_old := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kiosk_sessions' AND COLUMN_NAME = 'revoked_at');
SET @rename_sql := IF(@has_old > 0, 'RENAME TABLE kiosk_sessions TO kiosk_sessions_v16_legacy', 'DO 0');
PREPARE sg_rename FROM @rename_sql; EXECUTE sg_rename; DEALLOCATE PREPARE sg_rename;

CREATE TABLE IF NOT EXISTS kiosk_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    authorized_by INT DEFAULT NULL,
    authorized_by_name VARCHAR(100) DEFAULT NULL,
    status ENUM('Authorized','Locked','Expired') NOT NULL DEFAULT 'Authorized',
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    authorized_at DATETIME NOT NULL,
    last_activity DATETIME NOT NULL,
    ended_at DATETIME DEFAULT NULL,
    ended_by VARCHAR(100) DEFAULT NULL,
    INDEX idx_kiosk_status_activity (status, last_activity)
) ENGINE=InnoDB;

SET @has_legacy := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kiosk_sessions_v16_legacy');
SET @copy_sql := IF(@has_legacy > 0, 'INSERT IGNORE INTO kiosk_sessions (id, token_hash, authorized_by, authorized_by_name, status, authorized_at, last_activity, ended_at, ended_by) SELECT id, token_hash, authorized_by_user_id, authorized_by, IF(revoked_at IS NULL, 'Authorized', 'Locked'), authorized_at, last_activity, revoked_at, revoked_reason FROM kiosk_sessions_v16_legacy', 'DO 0');
PREPARE sg_copy FROM @copy_sql; EXECUTE sg_copy; DEALLOCATE PREPARE sg_copy;

INSERT INTO settings (setting_key, setting_value) VALUES ('kiosk_timeout_minutes', '10') ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- The legacy table is retained intentionally until the merged workflow has
-- been verified. It may then be archived or dropped manually.
