<?php
require_once __DIR__ . '/../database/config.php';
require_login();

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    $message = handle_self_password_change($pdo, $user, 'My Profile', 'Password updated successfully.');
}

$pageTitle = 'My Profile';
$pageHeading = 'My Profile';
$activePage = 'profile';
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
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="avatar-circle" style="width:64px;height:64px;font-size:1.8rem;"><i class="bi bi-person-fill"></i></div>
                        <h5 class="fw-bold mb-0">My Profile</h5>
                    </div>
                    <table class="table table-borderless mb-0">
                        <tr><th class="text-muted" style="width:40%;">Full Name</th><td><?= e($user['fullname']) ?></td></tr>
                        <tr><th class="text-muted">Username</th><td><?= e($user['username']) ?></td></tr>
                        <tr><th class="text-muted">Email</th><td><?= e($user['email'] ?: '-') ?></td></tr>
                        <tr><th class="text-muted">Role</th><td><?= e($user['role']) ?></td></tr>
                        <tr><th class="text-muted">Contact Number</th><td><?= e($user['contact_number'] ?: '-') ?></td></tr>
                    </table>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="sg-card h-100">
                    <h5 class="fw-bold mb-3">Change Password</h5>
                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="form" value="password">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" minlength="12" required>
                            <small class="text-muted">Min. 12 characters, with uppercase, lowercase, a number, and a special character.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="12" required>
                        </div>
                        <button type="submit" class="btn btn-sg-primary w-100">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
