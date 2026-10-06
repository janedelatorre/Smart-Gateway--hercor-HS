<?php
/**
 * Shared top navbar.
 * Expects $pageHeading (string) for the page's H1-style title.
 * Optional $pageSubheading (string) renders a small line under the heading.
 */
$pageHeading = $pageHeading ?? 'Dashboard';
$pageSubheading = $pageSubheading ?? '';
$userFullname = $_SESSION['fullname'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'Staff';
$userPhoto = $_SESSION['profile_picture'] ?? null;
$userPhotoUrl = $userPhoto ? APP_URL . '/uploads/' . ltrim($userPhoto, '/') : '';
$navbarCsrfToken = generate_csrf_token();
?>
<div class="sg-topbar sg-dashboard-topbar">
    <div class="sg-dashboard-title-wrap">
        <button class="btn sg-mobile-menu d-lg-none" id="sgSidebarToggle" type="button" aria-label="Toggle navigation">
            <i class="bi bi-list"></i>
        </button>
        <div>
            <h1><?= e(strtoupper($pageHeading)) ?></h1>
            <?php if ($pageSubheading !== ''): ?><p><?= e($pageSubheading) ?></p><?php endif; ?>
        </div>
    </div>

    <div class="sg-topbar-actions">
        <div class="sg-dashboard-actions">
        <div class="dropdown">
            <button class="btn sg-notification-button position-relative" type="button" id="sgNotifBtn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell fs-5"></i>
                <span id="sgNotifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow-sm p-0" style="width:340px;max-width:90vw;">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                    <span class="fw-bold">Notifications</span>
                    <button type="button" class="btn btn-sm btn-link p-0" id="sgNotifMarkAll">Mark all read</button>
                </div>
                <div id="sgNotifList" class="overflow-auto" style="max-height:320px;">
                    <div class="text-center text-muted small py-4">Loading...</div>
                </div>
            </div>
        </div>
        <button type="button" class="btn sg-theme-toggle" id="sgThemeToggle" aria-label="Switch theme" title="Switch theme">
            <i class="bi bi-sun-fill"></i><span class="sg-theme-label">Light</span>
        </button>
        </div>
        <div class="sg-admin-profile dropdown">
        <?php
        ?>
        <input type="hidden" id="sgNavCsrfToken" value="<?= e($navbarCsrfToken) ?>">
        <button class="sg-profile-button dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="sg-profile-avatar">
                <?php if ($userPhotoUrl): ?>
                    <img src="<?= e($userPhotoUrl) ?>" alt="<?= e($userFullname) ?>">
                <?php else: ?>
                    <i class="bi bi-person-fill"></i>
                <?php endif; ?>
            </span>
            <span class="sg-profile-copy">
                <strong><?= e($userFullname) ?></strong>
                <small><?= e($userRole) ?></small>
            </span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end sg-profile-menu shadow">
            <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/<?= ($userRole === 'Administrator' ? 'settings.php' : 'staff_settings.php') ?>"><i class="bi bi-gear me-2"></i>Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/admin/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
        </ul>
    </div>
    </div>
</div>
