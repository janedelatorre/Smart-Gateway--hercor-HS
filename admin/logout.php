<?php
require_once __DIR__ . '/../database/config.php';
require_login();

// Handle actual logout action
if (isset($_GET['confirm']) && $_GET['confirm'] === '1') {
    log_activity($pdo, 'Logout');
    $_SESSION = [];
    session_destroy();
    header('Location: ../login.php');
    exit;
}

$pageTitle = 'Logout';
$pageHeading = 'Logout';
$activePage = '';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="sg-content sg-logout-page" style="margin-left:0;">
    <div class="d-flex align-items-center justify-content-center" style="min-height:100vh;padding:1rem;">
        <div class="sg-card text-center p-5" style="max-width:420px;width:100%;">
            <div class="logout-icon-wrap">
                <i class="bi bi-box-arrow-right"></i>
            </div>
            <h4 class="fw-bold">Are you sure you want to log out?</h4>
            <p class="text-muted">You will be logged out from the system.</p>
            <div class="d-flex gap-2 logout-actions mt-3">
                <a href="<?= e(dashboard_url_for_role($_SESSION['role'] ?? null)) ?>" class="btn btn-light px-4">Cancel</a>
                <a href="logout.php?confirm=1" class="btn btn-danger px-4">Logout</a>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
