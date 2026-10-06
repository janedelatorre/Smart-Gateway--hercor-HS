-- =====================================================================
-- MIGRATION: Drop unused remember_token column (Remember Me removed)
-- =====================================================================
-- The Remember Me feature has been fully removed from the application
-- (login.php, logout.php). This column is no longer read or written
-- anywhere in the codebase. Dropping it is optional cleanup — the app
-- works fine whether or not you run this. Not applied automatically
-- since it's a schema change; run it yourself when convenient.
-- =====================================================================

ALTER TABLE users DROP COLUMN remember_token;
