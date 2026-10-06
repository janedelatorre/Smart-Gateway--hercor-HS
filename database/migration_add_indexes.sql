-- =====================================================================
-- MIGRATION: Add performance indexes (production readiness review)
-- =====================================================================
-- For EXISTING installations only. Fresh installs already get these
-- indexes from smart database.sql. Run this once against your existing
-- database (phpMyAdmin > SQL tab, or `mysql -u root smartgatewayproject_dev <
-- database/migration_add_indexes.sql`).
--
-- Safe to run once; re-running will error with "Duplicate key name" if
-- an index already exists, which just means it's already applied.
-- =====================================================================

CREATE INDEX idx_entry_logs_time_in ON entry_logs(time_in);
CREATE INDEX idx_entry_logs_status ON entry_logs(status);
CREATE INDEX idx_sms_logs_sent_at ON sms_logs(sent_at);
CREATE INDEX idx_notifications_user_read ON notifications(user_id, is_read);
CREATE INDEX idx_students_status ON students(status);
