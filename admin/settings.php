<?php
require_once __DIR__ . '/../database/config.php';
require_admin(); // Only Administrators can change system settings

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid session token.</div>';
    } else {
        $form = $_POST['form'] ?? '';
        if ($form === 'general') {
            $fields = ['school_name', 'school_address', 'school_contact'];
            foreach ($fields as $f) {
                $val = trim($_POST[$f] ?? '');
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$f, $val, $val]);
            }
            // Validate against the fixed option list actually offered in the UI —
            // a direct POST could otherwise store an arbitrary string here.
            $allowedTimezones = ['Asia/Manila', 'UTC', 'Asia/Singapore', 'Asia/Hong_Kong'];
            $tz = $_POST['timezone'] ?? '';
            if (in_array($tz, $allowedTimezones, true)) {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('timezone', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$tz, $tz]);
            }
            log_activity($pdo, 'Settings Updated', 'General settings (school info / timezone) updated');
            $message = '<div class="alert alert-success">General settings saved successfully.</div>';
        } elseif ($form === 'system') {
            $toggles = ['enable_sms', 'enable_facial_recognition', 'enable_barcode'];
            foreach ($toggles as $t) {
                $val = isset($_POST[$t]) ? '1' : '0';
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$t, $val, $val]);
            }
            // Clamp to the fixed option list actually offered in the UI.
            $allowedLogDays = [30, 90, 180, 365, 730];
            $days = (int) ($_POST['maintain_logs_days'] ?? 365);
            if (!in_array($days, $allowedLogDays, true)) $days = 365;
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('maintain_logs_days', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                ->execute([$days, $days]);
            log_activity($pdo, 'Settings Updated', 'System settings (feature toggles / log retention) updated');
            $message = '<div class="alert alert-success">System settings saved successfully.</div>';
        } elseif ($form === 'security') {
            // Session Timeout + Kiosk Timeout — Administrator-only (this whole
            // page is already require_admin()-gated above, so a Staff session
            // can never reach this branch via the UI, a direct URL, or a
            // crafted POST/API call). Two independent settings saved together
            // since they live in the same Security card — see Phase 2/3 for
            // why they are deliberately separate timeouts.
            $raw = trim($_POST['session_timeout_minutes'] ?? '');
            $kioskRaw = trim($_POST['kiosk_timeout_minutes'] ?? '');
            $sessionValid = $raw !== '' && ctype_digit($raw) && (int) $raw >= 10 && (int) $raw <= 120;
            $kioskValid = $kioskRaw !== '' && ctype_digit($kioskRaw) && (int) $kioskRaw >= 5 && (int) $kioskRaw <= 60;

            if (!$sessionValid || !$kioskValid) {
                $errors = [];
                if (!$sessionValid) $errors[] = 'Session Timeout must be a whole number between 10 and 120 minutes.';
                if (!$kioskValid) $errors[] = 'Kiosk Timeout must be a whole number between 5 and 60 minutes.';
                $message = '<div class="alert alert-danger">' . implode(' ', $errors) . ' No changes were saved.</div>';
            } else {
                $minutes = (int) $raw;
                $kioskMinutes = (int) $kioskRaw;
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('session_timeout_minutes', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$minutes, $minutes]);
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('kiosk_timeout_minutes', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$kioskMinutes, $kioskMinutes]);
                log_activity($pdo, 'Settings Updated', "Session timeout changed to {$minutes} minutes; kiosk timeout changed to {$kioskMinutes} minutes");
                $message = '<div class="alert alert-success">Session and kiosk timeout settings saved successfully.</div>';
            }
        } elseif ($form === 'sms') {
            $fields = ['sms_api_url', 'sms_sender_id'];
            foreach ($fields as $f) {
                $val = trim($_POST[$f] ?? '');
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$f, $val, $val]);
            }
            // The API key is never echoed back to the page (see below), so an empty
            // submission means "leave the existing key unchanged" rather than "clear it".
            $newKey = trim($_POST['sms_api_key'] ?? '');
            if ($newKey !== '') {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('sms_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$newKey, $newKey]);
            }
            log_activity($pdo, 'Settings Updated', 'SMS provider settings updated');
            $message = '<div class="alert alert-success">SMS provider settings saved successfully.</div>';
        } elseif ($form === 'kiosk_revoke') {
            $message = kiosk_revoke($pdo, (int) ($_POST['kiosk_id'] ?? 0), $_SESSION['username'] ?? 'Administrator')
                ? '<div class="alert alert-success">Kiosk station locked.</div>'
                : '<div class="alert alert-warning">That kiosk station is no longer active.</div>';
        } elseif ($form === 'kiosk_permissions') {
            $selected = $_POST['kiosk_manager_user_ids'] ?? [];
            if (!is_array($selected)) $selected = [];
            $selected = array_values(array_unique(array_filter(array_map('intval', $selected), fn($v) => $v > 0)));
            // Only persist IDs that actually belong to active users.
            if ($selected) {
                $ph = implode(',', array_fill(0, count($selected), '?'));
                $st = $pdo->prepare("SELECT id FROM users WHERE status='Active' AND id IN ($ph)");
                $st->execute($selected);
                $selected = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            }
            $json = json_encode($selected);
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('kiosk_manager_user_ids', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                ->execute([$json, $json]);
            log_activity($pdo, 'Kiosk Access Updated', 'Updated which users may authorize or lock kiosk stations');
            $message = '<div class="alert alert-success">Kiosk access permissions updated successfully.</div>';
        } elseif ($form === 'scanner') {
            // Clamp every value to a sane range server-side — a direct POST
            // could otherwise store an unusable (e.g. negative or huge) value.
            $cooldown = max(0, min(120, (int) ($_POST['duplicate_scan_cooldown_seconds'] ?? 5)));
            $interval = max(1, min(240, (int) ($_POST['min_entry_exit_interval_minutes'] ?? 15)));
            $threshold = max(1, min(10, (int) ($_POST['facial_fallback_threshold'] ?? 3)));

            foreach ([
                'duplicate_scan_cooldown_seconds' => $cooldown,
                'min_entry_exit_interval_minutes' => $interval,
                'facial_fallback_threshold' => $threshold,
            ] as $key => $val) {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$key, $val, $val]);
            }
            log_activity($pdo, 'Settings Updated', 'Scanner & access settings (cooldown / interval / facial threshold) updated');
            $message = '<div class="alert alert-success">Scanner &amp; access settings saved successfully.</div>';
        }
    }
}

$get = fn($k, $d = '') => get_setting($pdo, $k, $d);

kiosk_expire_stale($pdo);
$kioskStations = $pdo->query("SELECT id, status, authorized_by_name, authorized_at, last_activity, ended_at, ended_by FROM kiosk_sessions ORDER BY id DESC LIMIT 10")->fetchAll();
$activeUsersForKiosk = $pdo->query("SELECT id, username, fullname, role FROM users WHERE status='Active' ORDER BY role, fullname")->fetchAll();
$kioskManagerIds = json_decode((string)$get('kiosk_manager_user_ids','[]'), true);
if (!is_array($kioskManagerIds)) $kioskManagerIds = [];
$kioskManagerIds = array_map('intval', $kioskManagerIds);

$pageTitle = 'Settings';
$pageHeading = 'Settings';
$pageSubheading = 'Configure system preferences, security, and gateway services';
$activePage = 'settings';
$csrf = generate_csrf_token();
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sg-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <main class="sg-main sg-settings-page">
        <?= $message ?>

        <div class="sg-card mb-3 sg-account-shortcuts">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-1">Account Settings</h5>
                    <p class="text-muted small mb-0">Manage your profile and account security.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= APP_URL ?>/admin/profile.php" class="btn btn-outline-primary"><i class="bi bi-person me-1"></i>My Profile</a>
                    <a href="<?= APP_URL ?>/admin/change_password.php" class="btn btn-outline-secondary"><i class="bi bi-key me-1"></i>Change Password</a>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="sg-card sg-general-settings">
                    <h5 class="fw-bold mb-3">General Settings</h5>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="form" value="general">
                        <div class="mb-3">
                            <label class="form-label">School Name</label>
                            <input type="text" name="school_name" class="form-control" value="<?= e($get('school_name')) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">School Address</label>
                            <input type="text" name="school_address" class="form-control" value="<?= e($get('school_address')) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">School Contact Number</label>
                            <input type="text" name="school_contact" class="form-control" value="<?= e($get('school_contact')) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Timezone</label>
                            <select name="timezone" class="form-select">
                                <?php foreach (['Asia/Manila','UTC','Asia/Singapore','Asia/Hong_Kong'] as $tz): ?>
                                    <option value="<?= e($tz) ?>" <?= $get('timezone') === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sg-primary">Save Changes</button>
                    </form>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="sg-card mb-3">
                    <h5 class="fw-bold mb-3">System Settings</h5>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="form" value="system">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="enable_sms" id="enableSms" <?= $get('enable_sms','1')==='1'?'checked':'' ?>>
                            <label class="form-check-label fw-600" for="enableSms">Enable SMS Notifications</label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="enable_facial_recognition" id="enableFace" <?= $get('enable_facial_recognition','1')==='1'?'checked':'' ?>>
                            <label class="form-check-label fw-600" for="enableFace">Enable Facial Recognition (Backup)</label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="enable_barcode" id="enableBarcode" <?= $get('enable_barcode','1')==='1'?'checked':'' ?>>
                            <label class="form-check-label fw-600" for="enableBarcode">Enable Barcode Scanning (Primary)</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Maintain Logs (days)</label>
                            <select name="maintain_logs_days" class="form-select">
                                <?php foreach ([30,90,180,365,730] as $d): ?>
                                    <option value="<?= $d ?>" <?= (int)$get('maintain_logs_days','365')===$d?'selected':'' ?>><?= $d ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sg-primary">Save Changes</button>
                    </form>
                </div>

                <div class="sg-card mb-3">
                    <h5 class="fw-bold mb-3">Security</h5>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="form" value="security">
                        <div class="mb-3">
                            <label class="form-label">Session Timeout</label>
                            <input type="number" min="10" max="120" step="1" name="session_timeout_minutes" class="form-control" value="<?= e($get('session_timeout_minutes','20')) ?>">
                            <small class="text-muted">Automatically logs out inactive Admin and Staff accounts after the selected number of minutes. (10–120)</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kiosk Timeout</label>
                            <input type="number" min="5" max="60" step="1" name="kiosk_timeout_minutes" class="form-control" value="<?= e($get('kiosk_timeout_minutes','10')) ?>">
                            <small class="text-muted">Automatically locks the authorized scanner kiosk after the selected period of inactivity. (5–60)</small>
                        </div>
                        <button type="submit" class="btn btn-sg-primary">Save Changes</button>
                    </form>
                </div>

                <div class="sg-card mb-3">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-2">
                        <div>
                            <h5 class="fw-bold mb-1">Kiosk Access Authorization</h5>
                            <p class="text-muted small mb-0">Choose which active users may authorize a device as a kiosk or lock an active kiosk. Administrators always retain access.</p>
                        </div>
                        <span class="badge bg-info-subtle text-info-emphasis">Administrator Controlled</span>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="form" value="kiosk_permissions">
                        <div class="sg-kiosk-user-grid my-3">
                            <?php foreach ($activeUsersForKiosk as $u): ?>
                            <?php $always = $u['role'] === 'Administrator'; ?>
                            <label class="sg-kiosk-user-option <?= $always ? 'is-admin' : '' ?>">
                                <input class="form-check-input" type="checkbox" name="kiosk_manager_user_ids[]" value="<?= (int)$u['id'] ?>" <?= ($always || in_array((int)$u['id'], $kioskManagerIds, true)) ? 'checked' : '' ?> <?= $always ? 'disabled' : '' ?>>
                                <span>
                                    <strong><?= e($u['fullname']) ?></strong>
                                    <small><?= e($u['username']) ?> · <?= e($u['role']) ?><?= $always ? ' · Always allowed' : '' ?></small>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn btn-sg-primary"><i class="bi bi-shield-check me-1"></i>Save Kiosk Permissions</button>
                    </form>
                </div>

                <div class="sg-card mb-3">
                    <h5 class="fw-bold mb-3">Kiosk Station</h5>
                    <p class="text-muted small mb-3">Manage authorized scanner stations. Kiosk timeout remains separate from the Admin/Staff session timeout above.</p>
                    <div class="table-responsive">
                        <table class="table table-sg table-sm mb-0">
                            <thead><tr><th>#</th><th>Status</th><th>Authorized By</th><th>Last Activity</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php if (!$kioskStations): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No kiosk stations yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($kioskStations as $k): ?>
                                <tr>
                                    <td><?= (int) $k['id'] ?></td>
                                    <td><?= $k['status'] === 'Authorized' ? '<span class="badge-match">Authorized</span>' : ($k['status'] === 'Locked' ? '<span class="badge-denied">Locked</span>' : '<span class="badge-pending">Session Expired</span>') ?></td>
                                    <td><?= e($k['authorized_by_name'] ?? '-') ?></td>
                                    <td><?= e($k['last_activity'] ?? '-') ?></td>
                                    <td>
                                        <?php if ($k['status'] === 'Authorized'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="form" value="kiosk_revoke">
                                            <input type="hidden" name="kiosk_id" value="<?= (int) $k['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-light" title="Lock this kiosk"><i class="bi bi-lock-fill text-danger"></i></button>
                                        </form>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="sg-card mb-3">
                    <h5 class="fw-bold mb-3">Scanner &amp; Access Settings</h5>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="form" value="scanner">
                        <div class="mb-3">
                            <label class="form-label">Duplicate Scan Cooldown (seconds)</label>
                            <input type="number" min="0" max="120" name="duplicate_scan_cooldown_seconds" class="form-control" value="<?= e($get('duplicate_scan_cooldown_seconds','5')) ?>">
                            <small class="text-muted">A repeat scan of the same student within this window is ignored — no duplicate transaction, no duplicate SMS.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Minimum Entry-to-Exit Interval (minutes)</label>
                            <input type="number" min="1" max="240" name="min_entry_exit_interval_minutes" class="form-control" value="<?= e($get('min_entry_exit_interval_minutes','15')) ?>">
                            <small class="text-muted">How long a student must remain marked "inside" before a later scan is processed as an Exit.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Facial Recognition Trigger (failed barcode attempts)</label>
                            <input type="number" min="1" max="10" name="facial_fallback_threshold" class="form-control" value="<?= e($get('facial_fallback_threshold','3')) ?>">
                            <small class="text-muted">Number of consecutive unrecognized barcode scans before backup facial recognition unlocks.</small>
                        </div>
                        <button type="submit" class="btn btn-sg-primary">Save Changes</button>
                    </form>
                </div>

                <div class="sg-card">
                    <h5 class="fw-bold mb-3">SMS Provider (API)</h5>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="form" value="sms">
                        <div class="mb-3">
                            <label class="form-label">SMS API URL</label>
                            <input type="text" name="sms_api_url" class="form-control" placeholder="https://your-sms-provider.com/api/send" value="<?= e($get('sms_api_url')) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">SMS API Key</label>
                            <input type="password" name="sms_api_key" class="form-control" autocomplete="off"
                                   placeholder="<?= $get('sms_api_key') !== '' ? 'Key on file — leave blank to keep it unchanged' : 'Your API key' ?>" value="">
                            <small class="text-muted">For security, the saved key is never shown here. Leave blank to keep the current key.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sender ID</label>
                            <input type="text" name="sms_sender_id" class="form-control" value="<?= e($get('sms_sender_id','SmartGateway')) ?>">
                        </div>
                        <button type="submit" class="btn btn-sg-primary">Save Changes</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
