<?php
/**
 * Shared sidebar navigation.
 * Expects $activePage variable (string) to be set to highlight the current menu item.
 * Values: dashboard, students, entry_logs, sms, reports, users, audit_logs, settings, profile
 */
$activePage = $activePage ?? '';
function nav_active($page, $current) { return $page === $current ? 'active' : ''; }
$role = $_SESSION['role'] ?? 'Staff';
$dashboardUrl = dashboard_url_for_role($role);
?>
<aside class="sg-sidebar" id="sgSidebar">
    <div class="brand">
        <div class="crest has-image">
            <img src="<?= APP_URL ?>/assets/images/logo/school-logo.png" alt="School Logo">
        </div>
        <div>
            <strong>SMART GATEWAY</strong>
            <small>Campus Entry System</small>
        </div>
    </div>

    <nav class="nav flex-column py-2 flex-grow-1">
        <a class="nav-link <?= nav_active('dashboard', $activePage) ?>" href="<?= e($dashboardUrl) ?>">
            <i class="bi bi-grid-1x2-fill"></i> Dashboard
        </a>
        <a class="nav-link <?= nav_active('students', $activePage) ?>" href="<?= APP_URL ?>/admin/students.php">
            <i class="bi bi-person-lines-fill"></i> Students
        </a>
        <a class="nav-link <?= nav_active('scanner', $activePage) ?>" href="<?= APP_URL ?>/admin/scanner.php">
            <i class="bi bi-upc-scan"></i> Scan Entry
        </a>
        <a class="nav-link <?= nav_active('entry_logs', $activePage) ?>" href="<?= APP_URL ?>/admin/entry_logs.php">
            <i class="bi bi-journal-text"></i> Entry Logs
        </a>
        <a class="nav-link <?= nav_active('sms', $activePage) ?>" href="<?= APP_URL ?>/admin/sms_notifications.php">
            <i class="bi bi-chat-dots-fill"></i> SMS Notifications
        </a>
        <a class="nav-link <?= nav_active('reports', $activePage) ?>" href="<?= APP_URL ?>/admin/reports.php">
            <i class="bi bi-bar-chart-fill"></i> Reports
        </a>
        <?php if ($role === 'Administrator'): ?>
        <a class="nav-link <?= nav_active('users', $activePage) ?>" href="<?= APP_URL ?>/admin/users.php">
            <i class="bi bi-people-fill"></i> Users
        </a>
        <a class="nav-link <?= nav_active('audit_logs', $activePage) ?>" href="<?= APP_URL ?>/admin/audit_logs.php">
            <i class="bi bi-shield-lock-fill"></i> Audit Logs
        </a>
        <?php endif; ?>
        <a class="nav-link <?= nav_active('settings', $activePage) ?>" href="<?= APP_URL ?>/admin/settings.php">
            <i class="bi bi-gear-fill"></i> Settings
        </a>
    </nav>

    <div class="p-3 border-top border-secondary border-opacity-25">
        <a href="<?= APP_URL ?>/admin/logout.php" class="nav-link text-white-50">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </div>
</aside>
