<?php
require_once __DIR__ . '/../database/config.php';
require_login();

// Logout changes server state, so it is POST-only and CSRF-protected.
// A GET (including the old logout.php?confirm=1 link) never logs anyone out;
// it only shows the confirmation page below.
$logoutError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        log_activity($pdo, 'Logout');
        $_SESSION = [];
        session_destroy();
        header('Location: ../login.php');
        exit;
    }
    http_response_code(403);
    $logoutError = 'Your logout request could not be verified. Please try again.';
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
            <?php if ($logoutError !== ''): ?><div class="alert alert-danger small"><?= e($logoutError) ?></div><?php endif; ?>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= e(generate_csrf_token()) ?>">
                <div class="d-flex gap-2 logout-actions mt-3">
                    <a href="<?= e(dashboard_url_for_role($_SESSION['role'] ?? null)) ?>" class="btn btn-light px-4">Cancel</a>
                    <button type="submit" class="btn btn-danger px-4">Logout</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
