# Smart Gateway — Merged Phase 8 + V17 Kiosk Improvements

## Base
V16 Phase 8 Student Photo is the functional base.

## Kept from Phase 8
- Student Profile Photo upload and display
- Separate `students.photo` and `students.face_encoding`
- Stable-face auto-capture workflow
- Complete Reports filters, Grade Level filter, Entry vs Exit chart, and printable report header
- Admin/Staff session timeout setting
- Entry & Exit Logs navigation label
- Existing shared JSON API timeout/safety behavior

## Selectively merged from V17
- Stronger kiosk session schema
- Kiosk status: Authorized / Locked / Expired
- Kiosk IP address and user-agent recording
- Administrator-only kiosk authorization/revocation
- Kiosk idle timeout and stale-session expiry
- Kiosk station management in Settings
- Kiosk-expiry login notice
- Server-side kiosk-aware verifier attribution (`Kiosk Station`)
- Scanner page guard and kiosk authorization flow
- API unauthorized reason code for kiosk/session expiry

## Deliberately not merged
- V17 removal of Phase 8 Report features
- V17 replacement of stable-face auto-capture
- V17 removal of Admin/Staff Session Timeout UI
- V17 dashboard metric changes that blur Entry-only and all-verification counts
- V17 simplification of `database/config.php` that removes the shared JSON API login timeout helper

## Upgrade
For an existing Phase 8 database, run `database/migration_v17_kiosk_sessions_upgrade.sql` after backing up the database. For a fresh installation, import `database/smart database.sql`.
