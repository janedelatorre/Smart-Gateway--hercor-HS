<?php
require_once __DIR__ . '/../database/config.php';
require_login();

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = handle_self_password_change($pdo, $user, 'Change Password page', 'Password updated successfully. Please use your new password next time you log in.');
}

$pageTitle = 'Change Password';
$pageHeading = 'Change Password';
$activePage = 'settings';
$csrf = generate_csrf_token();
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sg-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <main class="sg-main d-flex justify-content-center">
        <div class="sg-card" style="max-width:480px; width:100%;">
            <?= $message ?>
            <h5 class="fw-bold mb-3">Change Password</h5>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <div class="mb-3">
                    <label class="form-label fw-600">Current Password</label>
                    <input type="password" name="current_password" class="form-control" placeholder="Enter current password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-600">New Password</label>
                    <div class="input-group">
                        <input type="password" name="new_password" id="newPass" class="form-control" placeholder="Enter new password" minlength="12" required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePw('newPass', this)"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-600">Confirm New Password</label>
                    <div class="input-group">
                        <input type="password" name="confirm_password" id="confirmPass" class="form-control" placeholder="Confirm new password" minlength="12" required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePw('confirmPass', this)"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-sg-primary w-100">Update Password</button>
            </form>
        </div>
    </main>
</div>
<script>
function togglePw(id, btn) {
    const field = document.getElementById(id);
    const icon = btn.querySelector('i');
    if (field.type === 'password') { field.type = 'text'; icon.classList.replace('bi-eye','bi-eye-slash'); }
    else { field.type = 'password'; icon.classList.replace('bi-eye-slash','bi-eye'); }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
