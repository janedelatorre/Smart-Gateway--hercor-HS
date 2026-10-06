<?php
require_once __DIR__ . '/../database/config.php';
require_staff();

$message = '';
$csrf = generate_csrf_token();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid session token. Please refresh the page.</div>';
    } else {
        $form = $_POST['form'] ?? '';
        if ($form === 'system') {
            $toggles = ['enable_sms', 'enable_facial_recognition', 'enable_barcode'];
            foreach ($toggles as $t) {
                $val = isset($_POST[$t]) ? '1' : '0';
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$t, $val, $val]);
            }
            $allowedLogDays = [30, 90, 180, 365, 730];
            $days = (int) ($_POST['maintain_logs_days'] ?? 365);
            if (!in_array($days, $allowedLogDays, true)) $days = 365;
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('maintain_logs_days', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                ->execute([$days, $days]);
            log_activity($pdo, 'Settings Updated', 'Staff updated permitted system settings');
            $message = '<div class="alert alert-success">System settings saved successfully.</div>';
        } elseif ($form === 'scanner') {
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
            log_activity($pdo, 'Settings Updated', 'Staff updated scanner & access settings');
            $message = '<div class="alert alert-success">Scanner &amp; access settings saved successfully.</div>';
        }
    }
}

$get = fn($k, $d = '') => get_setting($pdo, $k, $d);
$schoolName = get_setting($pdo, 'school_name', 'Hercor College');
$pageTitle='Settings'; $pageHeading='Settings'; $pageSubheading='Manage your staff account preferences'; $activePage='settings';
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sg-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>
    <main class="sg-main sg-settings-page sg-staff-settings-page">
        <?= $message ?>
        <div class="sg-card mb-3 sg-account-shortcuts">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div><h5 class="fw-bold mb-1">Staff Account Settings</h5><p class="text-muted small mb-0">Manage your profile, interface preferences, system feature settings, and scanner/access behavior.</p></div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= APP_URL ?>/admin/profile.php" class="btn btn-outline-primary"><i class="bi bi-person me-1"></i>My Profile</a>
                    <a href="<?= APP_URL ?>/admin/change_password.php" class="btn btn-outline-secondary"><i class="bi bi-key me-1"></i>Change Password</a>
                </div>
            </div>
        </div>

        <div class="row g-3 align-items-start">
            <!-- PHASE 6: the "Appearance" theme dropdown here saved to a
                 user_theme_<id> setting that nothing in the app ever reads --
                 the actual dark/light toggle (shared with Admin) lives in the
                 topbar and uses localStorage, independently of this control.
                 Removed as dead/non-functional rather than leaving a
                 System-Appearance-style control on the Staff-facing page;
                 the real theme toggle is untouched and still works for Staff. -->
            <div class="col-lg-12">
                <div class="sg-card">
                    <h5 class="fw-bold mb-2">Access Level</h5>
                    <div class="d-flex align-items-center gap-3">
                        <div class="sg-settings-access-icon"><i class="bi bi-shield-check"></i></div>
                        <div><strong>Staff</strong><div class="text-muted small">Daily campus operations, scanning, logs, reports, analytics, system feature settings, scanner/access settings and personal account settings.</div></div>
                    </div>
                    <hr>
                    <div class="small text-muted"><i class="bi bi-lock-fill me-1"></i>General school information, user management, security timeouts, kiosk permission management and SMS provider credentials remain Administrator-only.</div>
                </div>
            </div>
        </div>

        <div class="row g-3 mt-3 align-items-start">
            <div class="col-lg-6">
                <div class="sg-card">
                    <h5 class="fw-bold mb-1">System Settings</h5>
                    <p class="text-muted small mb-3">Staff may manage operational feature toggles and log retention.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="form" value="system">
                        <?php foreach ([['enable_sms','SMS Notifications'],['enable_facial_recognition','Facial Recognition'],['enable_barcode','Barcode Scanning']] as [$key,$label]): ?>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" name="<?= e($key) ?>" id="staff_<?= e($key) ?>" <?= $get($key,'1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="staff_<?= e($key) ?>"><?= e($label) ?></label>
                        </div>
                        <?php endforeach; ?>
                        <div class="mb-3">
                            <label class="form-label">Maintain Logs For</label>
                            <select name="maintain_logs_days" class="form-select">
                                <?php foreach ([30=>'30 days',90=>'90 days',180=>'180 days',365=>'1 year',730=>'2 years'] as $v=>$label): ?>
                                <option value="<?= $v ?>" <?= (int)$get('maintain_logs_days','365') === $v ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sg-primary"><i class="bi bi-check2 me-1"></i>Save System Settings</button>
                    </form>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="sg-card">
                    <h5 class="fw-bold mb-1">Scanner &amp; Access Settings</h5>
                    <p class="text-muted small mb-3">Tune scanner timing and when automatic facial fallback becomes available.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="form" value="scanner">
                        <div class="mb-3"><label class="form-label">Duplicate Scan Cooldown (seconds)</label><input type="number" min="0" max="120" name="duplicate_scan_cooldown_seconds" class="form-control" value="<?= e($get('duplicate_scan_cooldown_seconds','5')) ?>"></div>
                        <div class="mb-3"><label class="form-label">Minimum Entry-to-Exit Interval (minutes)</label><input type="number" min="1" max="240" name="min_entry_exit_interval_minutes" class="form-control" value="<?= e($get('min_entry_exit_interval_minutes','15')) ?>"></div>
                        <div class="mb-3"><label class="form-label">Facial Recognition Trigger (failed barcode attempts)</label><input type="number" min="1" max="10" name="facial_fallback_threshold" class="form-control" value="<?= e($get('facial_fallback_threshold','3')) ?>"><small class="text-muted">When the threshold is reached, the facial camera opens automatically.</small></div>
                        <button type="submit" class="btn btn-sg-primary"><i class="bi bi-check2 me-1"></i>Save Scanner Settings</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="sg-card mt-3">
            <h5 class="fw-bold mb-1">System Information</h5>
            <div class="row g-3 mt-1">
                <div class="col-md-6"><span class="text-muted small d-block">School</span><strong><?= e($schoolName) ?></strong></div>
                <div class="col-md-6"><span class="text-muted small d-block">Current Role</span><strong>Staff</strong></div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
