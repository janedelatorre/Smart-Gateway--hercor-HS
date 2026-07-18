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
            $fields = ['school_name', 'school_address', 'school_contact', 'timezone'];
            foreach ($fields as $f) {
                $val = trim($_POST[$f] ?? '');
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$f, $val, $val]);
            }
            $message = '<div class="alert alert-success">General settings saved successfully.</div>';
        } elseif ($form === 'system') {
            $toggles = ['enable_sms', 'enable_facial_recognition', 'enable_barcode', 'auto_backup'];
            foreach ($toggles as $t) {
                $val = isset($_POST[$t]) ? '1' : '0';
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$t, $val, $val]);
            }
            $days = (int) ($_POST['maintain_logs_days'] ?? 365);
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('maintain_logs_days', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                ->execute([$days, $days]);
            $message = '<div class="alert alert-success">System settings saved successfully.</div>';
        } elseif ($form === 'sms') {
            $fields = ['sms_api_url', 'sms_api_key', 'sms_sender_id'];
            foreach ($fields as $f) {
                $val = trim($_POST[$f] ?? '');
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$f, $val, $val]);
            }
            $message = '<div class="alert alert-success">SMS provider settings saved successfully.</div>';
        }
    }
}

$get = fn($k, $d = '') => get_setting($pdo, $k, $d);

$pageTitle = 'Settings';
$pageHeading = 'Settings';
$activePage = 'settings';
$csrf = generate_csrf_token();
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sg-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <main class="sg-main">
        <?= $message ?>
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="sg-card h-100">
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
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="auto_backup" id="autoBackup" <?= $get('auto_backup','1')==='1'?'checked':'' ?>>
                            <label class="form-check-label fw-600" for="autoBackup">Auto Backup</label>
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
                            <input type="text" name="sms_api_key" class="form-control" placeholder="Your API key" value="<?= e($get('sms_api_key')) ?>">
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
