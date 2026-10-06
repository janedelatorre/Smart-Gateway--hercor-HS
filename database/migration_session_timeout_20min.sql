-- =====================================================================
-- SMART GATEWAY — Session Timeout Default Update (30 -> 20 minutes)
-- =====================================================================
-- Prior to this migration there was no Settings UI to change
-- session_timeout_minutes, so any row still holding '30' is guaranteed
-- to be the untouched original default, not an Administrator's
-- intentional choice. Safe to update in place.
--
-- IDEMPOTENT: safe to run multiple times and on a fresh v16 import
-- (which already seeds '20' directly).
--
-- HOW TO RUN (XAMPP / phpMyAdmin)
--   Select the smartgatewayproject_dev database, Import tab, choose
--   this file, Go. Or:
--   mysql -u root smartgatewayproject_dev < migration_session_timeout_20min.sql
-- =====================================================================

-- Update only if the row exists and still holds the untouched old default.
UPDATE settings SET setting_value = '20'
WHERE setting_key = 'session_timeout_minutes' AND setting_value = '30';

-- Insert the row if it's missing entirely (older pre-v16 installs).
INSERT INTO settings (setting_key, setting_value)
SELECT 'session_timeout_minutes', '20'
WHERE NOT EXISTS (
    SELECT 1 FROM settings WHERE setting_key = 'session_timeout_minutes'
);
