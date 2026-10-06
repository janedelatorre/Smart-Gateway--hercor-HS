# V21 Scanner, Kiosk Permissions, Staff Settings and Login Glow

- Staff Settings now permits operational System Settings and Scanner & Access Settings.
- Administrator Settings adds per-user Kiosk Access Authorization. Administrators always remain allowed.
- Authorized Staff can authorize or lock kiosk stations; enforcement is server-side in api/kiosk.php.
- Scanner verification cards now use uniform heights.
- Removed the numeric `3.` prefix from Verification Result.
- Reduced the Access Granted success check size.
- Facial fallback now opens the camera automatically when the configured barcode-failure threshold is reached. No tap-to-capture/manual capture button is required.
- Login/portal hero styling now has a stronger teal atmospheric left panel and cyan curved glow matching the supplied reference.
- Added migration_v21_kiosk_permissions.sql and default setting seed.
