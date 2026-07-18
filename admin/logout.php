<?php
require_once __DIR__ . '/../database/config.php';
require_login();

// Handle actual logout action
if (isset($_GET['confirm']) && $_GET['confirm'] === '1') {
    // Clear remember token in DB
    if (!empty($_SESSION['user_id'])) {
        $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")->execute([$_SESSION['user_id']]);
    }
    log_activity($pdo, 'Logout');
    $_SESSION = [];
    session_destroy();
    setcookie('sg_remember', '', time() - 3600, '/');
    header('Location: ../login.php');
    exit;
}

$pageTitle = 'Logout';
$pageHeading = 'Logout';
$activePage = '';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="sg-content" style="margin-left:0;">
    <div class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
        <div class="sg-card text-center p-5" style="max-width:420px;">
            <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle bg-light" style="width:110px;height:110px;">
                <i class="bi bi-box-arrow-right text-sg-primary" style="font-size:2.8rem;"></i>
            </div>
            <h4 class="fw-bold">Are you sure you want to logout?</h4>
            <p class="text-muted">You will be logged out from the system</p>
            <div class="d-flex gap-2 justify-content-center mt-3">
                <a href="<?= e(dashboard_url_for_role($_SESSION['role'] ?? null)) ?>" class="btn btn-light px-4">Cancel</a>
                <a href="logout.php?confirm=1" class="btn btn-danger px-4">Logout</a>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
