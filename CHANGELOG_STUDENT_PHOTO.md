# Student profile photo separation + kiosk timeout clock fix

## Student profile photo vs facial recognition
- `students.photo` = formal profile photo, set ONLY by the new "Student Profile Photo" upload in the Add/Edit modal (JPG/PNG/WEBP, max 5 MB, real MIME checked server-side, random server-generated filename in `uploads/student/`, old file removed on replacement, new file removed if the save fails).
- `students.face_encoding` = face descriptor, set ONLY by the existing "Capture Face" flow. The captured frame is no longer uploaded and never becomes the profile photo (`photo_data` removed).
- Edit: no new photo keeps `photo`; new photo replaces `photo` only; recapture updates `face_encoding` only.
- Fixed: `api/scan.php` / `api/face.php` built photo URLs as `/uploads/<file>` while files live in `/uploads/student/`, so the verification photo never loaded.
- `face_encoding` is never sent to the browser; the student list only returns a `has_face` yes/no flag.
- Fixed: a previous student's face capture no longer carries over into the next Add/Edit modal.

## Kiosk timeout clock fix (includes/security.php, kiosk_is_valid)
- Idle time was `time() - strtotime(last_activity)`: PHP is pinned to Asia/Manila while `last_activity` is written by the database's `NOW()`. When the DB server runs in another time zone (e.g. UTC hosting) a freshly authorized kiosk was treated as expired immediately. It now uses `TIMESTAMPDIFF(SECOND, last_activity, NOW())`, i.e. one clock.

No database changes.
