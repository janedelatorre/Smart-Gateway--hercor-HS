# SMART GATEWAY
### Campus Entry System Using Barcode Verification, Backup Facial Recognition, and SMS Notification

A capstone-ready, full-stack campus entry monitoring system built with **PHP 8, MySQL, and Bootstrap 5**.

---

## 1. Technology Stack

| Layer      | Technology |
|------------|------------|
| Frontend   | HTML5, Bootstrap 5, Bootstrap Icons, JavaScript (ES6), AJAX (fetch), Chart.js |
| Backend    | PHP 8 (PDO, prepared statements), MySQL, REST-like JSON APIs |
| Libraries  | jQuery, html5-qrcode (barcode/QR scanner), face-api.js (backup biometric), SweetAlert2, Chart.js |
| Environment| XAMPP (Apache + MySQL + PHP) |

---

## 2. Folder Structure

```
smart-gateway/
├── assets/
│   ├── css/style.css
│   ├── js/ (app.js, students.js, scanner.js, entry_logs.js, sms.js, users.js)
│   └── images/
├── uploads/
│   ├── student/   (captured/uploaded student photos)
│   └── face/
├── admin/
│   ├── dashboard.php   students.php   entry_logs.php   reports.php
│   ├── users.php       settings.php   profile.php      profile.php
│   ├── scanner.php     logout.php
├── includes/
│   ├── header.php  sidebar.php  navbar.php  footer.php
├── database/
│   ├── config.php   smart database.sql
├── api/
│   ├── student.php  scan.php  sms.php  face.php  user.php  entry_logs.php
├── login.php
├── index.php
└── README.md
```

---

## 3. Installation (XAMPP)

1. Install [XAMPP](https://www.apachefriends.org/) and start **Apache** + **MySQL**.
2. Copy the `smart-gateway` folder into `C:/xampp/htdocs/` (Windows) or `/Applications/XAMPP/htdocs/` (Mac).
3. Open **phpMyAdmin** (`http://localhost/phpmyadmin`), create nothing manually — instead:
   - Click **Import**, choose `database/smart database.sql`, and run it.
   - This creates the `smartgatewayproject_dev` database with all tables and seed data.
4. `database/config.php` is **environment-aware** — it auto-detects whether it's running on
   `localhost`, a LAN IP (192.168.x.x / 10.x.x.x), or a real domain, and picks the matching
   database credentials automatically. The local/XAMPP defaults (`root` / empty password)
   already match a stock XAMPP install, so **no editing is needed for local use**.
5. Visit `http://localhost/smart-gateway/login.php` in your browser.

### Default login credentials (seed data)

| Username | Password  | Role          |
|----------|-----------|---------------|
| admin    | admin123  | Administrator |
| staff1   | admin123  | Staff         |
| staff2   | admin123  | Staff         |

**Change these passwords immediately after first login** (Profile → Update Profile).

---

## 4. Deployment (Shared Hosting / VPS / LAN Server)

`database/config.php` is designed so **you never need to edit URLs by hand** when moving
between environments — `APP_URL`, HTTPS detection, and session cookie security are all
computed automatically from the request. The **only** thing to fill in once is your live
database credentials:

1. Open `database/config.php` and find the `$sgProductionDb` array near the top.
2. Replace the three `CHANGE_ME_...` placeholders with the database name, username, and
   password your host (cPanel → MySQL Databases, or your VPS) gives you.
3. Upload the whole project folder to your host (`public_html/` or a subfolder), import
   `database/smart database.sql` via phpMyAdmin on that host, and enable SSL on your domain.
4. Visit `https://yourdomain.com/...` — the app will automatically detect it's no longer
   on `localhost`/a private LAN IP and switch to the production database credentials and
   HTTPS-secure session cookies. Your local XAMPP setup keeps working unchanged.

**Note on LAN deployment** (e.g., a dedicated PC at the campus gate): since PHP always
connects to MySQL on `localhost` from its own perspective regardless of which IP a browser
used to reach it, LAN-IP access (`http://192.168.x.x/...`) is automatically treated as a
"local" environment and reuses the XAMPP credentials — no extra configuration needed. Keep
in mind the camera-based scanner and face capture require HTTPS on any non-`localhost`
address, per browser security policy; use **Physical Scanner Device mode** (see Section 7
below) on LAN stations if you don't want to set up a local SSL certificate.

---

## 5. Core Workflow — Primary Barcode + Backup Facial Recognition

The scanner page (`admin/scanner.php`) enforces the following strict order, matching the required system design:

```
Student arrives
   ↓
Scan Student Barcode  (html5-qrcode, primary)
   ↓
Barcode found in database?
   NO  → Access Denied ("Invalid ID") + option to use Backup Facial Recognition
   YES ↓
Student status Active?
   NO  → Access Denied ("Inactive status") — no backup needed, identity already known
   YES ↓
Record entry (entry_logs) → Send SMS to guardian → ACCESS GRANTED
```

Facial recognition (`api/face.php`, powered by face-api.js in the browser + Euclidean-distance
matching on the server) is **only ever triggered** when:
- the barcode camera/scanner fails to initialize, **or**
- the scanned barcode does not match any student record, **or**
- staff manually click **"Use Backup Facial Recognition"**.

It is never used as a first step, and never runs automatically after a successful barcode match.

---

## 6. SMS Integration

`api/sms.php` exposes a reusable `sg_send_sms()` / `sg_notify_entry()` function used
automatically after every granted entry. It is provider-agnostic:

1. Go to **Settings → SMS Provider (API)**.
2. Enter your SMS gateway's REST endpoint URL and API key (e.g. Semaphore, iTexMo, Twilio-compatible gateway).
3. Until configured, messages are logged with status **Pending** so the rest of the
   system (dashboard stats, entry flow, etc.) can still be demonstrated end-to-end.

---

## 7. Facial Recognition Notes

- Uses **face-api.js** purely as **backup** biometric verification — never primary.
- When a student's photo is captured in the Students module, a 128-point face
  descriptor is extracted client-side and stored in `students.face_encoding` (JSON).
- During backup verification, the captured face is compared (Euclidean distance,
  threshold `0.6`) against all Active students' stored descriptors (1:N matching).
- Model files are loaded from the public face-api.js model CDN
  (`https://justadudewhohacks.github.io/face-api.js/models`). For a production/offline
  deployment, download the model weights and host them locally, then update the
  `MODEL_URL` constants in `assets/js/students.js` and `assets/js/scanner.js`.

---

## 8. Security Measures Implemented

- **Prepared statements** (PDO) throughout — no raw SQL concatenation.
- **Password hashing** via `password_hash()` / `password_verify()` (bcrypt).
- **Session-based authentication** with `session_regenerate_id()` on login.
- **Role-based access control** (`require_admin()` guards Users & Settings pages).
- **CSRF tokens** on every state-changing form/AJAX request.
- **Output escaping** via the `e()` helper (XSS protection).
- **Upload validation**: only base64 JPEG/PNG accepted, size-capped, renamed server-side;
  `uploads/.htaccess` blocks script execution in the upload directories.
- **`database/.htaccess`** denies direct web access to config/schema files.

---

## 9. Database Schema Summary

- `users` — admin/staff accounts, roles, hashed passwords
- `students` — student profile, photo, face descriptor, status
- `entry_logs` — every scan attempt (granted or denied), method used, verifier
- `sms_logs` — every SMS attempt with delivery status
- `settings` — key/value system configuration (school info, feature toggles, SMS provider)

See `database/smart database.sql` for full column definitions and seed data.

---

## 10. Notes for Thesis Defense / Demo

- The Reports module (`admin/reports.php`) lets you pick a date range and view total
  entries, denied entries, average entry time, and peak hour, backed by live queries.
- Entry Logs support CSV export ("Export Excel") and browser print-to-PDF ("Export PDF").
- All AJAX endpoints return uniform `{ success, message, data }` (or `{ status, reason }`
  for the scanner APIs) JSON, making it straightforward to swap in a native mobile
  client later if desired.


## Merged Final Candidate — Phase 8 + V17 Kiosk
This build uses **V16 Phase 8 Student Photo** as the base and selectively incorporates the stronger **V17 kiosk station** architecture. Student profile photos remain separate from facial-recognition descriptors, while kiosk authorization, status tracking, idle expiration, and Administrator-controlled locking are strengthened. Phase 8 reporting and stable-face auto-capture are intentionally retained.

For an existing Phase 8 database, review and run `database/migration_v17_kiosk_sessions_upgrade.sql` after creating a backup.
