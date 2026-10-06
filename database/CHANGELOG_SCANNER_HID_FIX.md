# Smart Gateway — Scanner HID Input Fix

## Purpose
Fix the scanner kiosk so standard USB/Bluetooth HID (keyboard-wedge) barcode scanners have a visible destination for the scanned Student ID while remaining non-editable to students.

## Changes
- Replaced the off-screen-only scanner input presentation with a visible, dark `SCANNED STUDENT ID` readonly field.
- Added a `Waiting for scan...` placeholder for the idle state.
- Added document-level keyboard-wedge capture so scanner input is not dependent on the hidden input retaining focus.
- Scanner characters are mirrored into the visible readonly field programmatically.
- Enter terminates the buffered barcode and starts the existing `verify_barcode` workflow.
- Added an inter-key timeout to prevent stale partial scan data from being carried into a later scan.
- Reset clears the displayed Student ID and restores `Waiting for scan...`.
- Preserved the existing Entry/Exit state machine, facial fallback, identity challenge, duplicate protection, SMS logic, and API behavior.
- Preserved the physical-scanner-only kiosk design; no webcam barcode scanning or manual editable Student ID field was added.

## Validation
- `node --check assets/js/scanner.js` — passed.
- PHP lint on all 31 PHP files in the extracted project — passed.
- Real barcode hardware/browser testing remains required.
