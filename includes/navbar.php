<?php
/**
 * Shared top navbar.
 * Expects $pageHeading (string) for the page's H1-style title.
 */
$pageHeading = $pageHeading ?? 'Dashboard';
$userFullname = $_SESSION['fullname'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'Staff';
$navbarCsrfToken = generate_csrf_token();
?>
<div class="sg-topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn btn-light d-lg-none" id="sgSidebarToggle" type="button" aria-label="Toggle navigation">
            <i class="bi bi-list fs-5"></i>
        </button>
        <h4 class="mb-0 fw-bold text-sg-primary"><?= e($pageHeading) ?></h4>
    </div>

    <div class="d-flex align-items-center gap-2">
        <input type="hidden" id="sgNavCsrfToken" value="<?= e($navbarCsrfToken) ?>">
        <div class="dropdown">
            <button class="btn btn-light position-relative" type="button" id="sgNotifBtn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell fs-5"></i>
                <span id="sgNotifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow-sm p-0" style="width: 340px; max-width: 90vw;">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                    <span class="fw-bold">Notifications</span>
                    <button type="button" class="btn btn-sm btn-link p-0" id="sgNotifMarkAll">Mark all read</button>
                </div>
                <div id="sgNotifList" class="overflow-auto" style="max-height: 320px;">
                    <div class="text-center text-muted small py-4">Loading...</div>
                </div>
            </div>
        </div>

        <div class="dropdown">
            <button class="btn btn-light d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle fs-5"></i>
                <span class="d-none d-sm-inline fw-600"><?= e($userFullname) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><span class="dropdown-item-text text-muted small"><?= e($userRole) ?></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/admin/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</div>
