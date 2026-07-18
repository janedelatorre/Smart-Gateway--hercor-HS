<?php
require_once __DIR__ . '/../database/config.php';
require_staff(); // Staff-only dashboard — Administrators are routed to dashboard.php

// ---- Stat card queries (same underlying data as the admin dashboard) ----
$today = date('Y-m-d');
$todayEntries = $pdo->prepare("SELECT COUNT(*) FROM entry_logs WHERE DATE(time_in) = ? AND status = 'Match'");
$todayEntries->execute([$today]);
$todayEntries = $todayEntries->fetchColumn();

$deniedEntries = $pdo->prepare("SELECT COUNT(*) FROM entry_logs WHERE DATE(time_in) = ? AND status = 'Denied'");
$deniedEntries->execute([$today]);
$deniedEntries = $deniedEntries->fetchColumn();

$smsToday = $pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE DATE(sent_at) = ?");
$smsToday->execute([$today]);
$smsToday = $smsToday->fetchColumn();

$totalStudents = $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'Active'")->fetchColumn();

// ---- Granted vs Denied (today) pie ----
$pieData = [(int)$todayEntries, (int)$deniedEntries];

// ---- Recent entry logs ----
$recentLogs = $pdo->query("SELECT * FROM entry_logs ORDER BY time_in DESC LIMIT 8")->fetchAll();

$pageTitle = 'Staff Dashboard';
$pageHeading = 'Staff Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sg-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <main class="sg-main">

        <!-- Stat Cards -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-3">
                <div class="sg-card stat-card">
                    <div class="icon-box bg-icon-blue"><i class="bi bi-people-fill"></i></div>
                    <div>
                        <div class="stat-value"><?= (int)$totalStudents ?></div>
                        <div class="stat-label">Active Students</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="sg-card stat-card">
                    <div class="icon-box bg-icon-green"><i class="bi bi-box-arrow-in-right"></i></div>
                    <div>
                        <div class="stat-value"><?= (int)$todayEntries ?></div>
                        <div class="stat-label">Today's Entries</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="sg-card stat-card">
                    <div class="icon-box bg-icon-red"><i class="bi bi-x-circle-fill"></i></div>
                    <div>
                        <div class="stat-value"><?= (int)$deniedEntries ?></div>
                        <div class="stat-label">Denied Entries</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="sg-card stat-card">
                    <div class="icon-box bg-icon-purple"><i class="bi bi-chat-dots-fill"></i></div>
                    <div>
                        <div class="stat-value"><?= (int)$smsToday ?></div>
                        <div class="stat-label">SMS Sent Today</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions + Granted/Denied -->
        <div class="row g-3 mb-3">
            <div class="col-lg-8">
                <div class="sg-card h-100">
                    <h6 class="fw-bold mb-3">Quick Actions</h6>
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <a href="<?= APP_URL ?>/admin/scanner.php" class="sg-card d-flex flex-column align-items-center justify-content-center text-decoration-none py-4 h-100">
                                <i class="bi bi-upc-scan fs-2 text-sg-accent mb-2"></i>
                                <span class="fw-600 text-body">Scan Entry</span>
                            </a>
                        </div>
                        <div class="col-6 col-md-4">
                            <a href="<?= APP_URL ?>/admin/entry_logs.php" class="sg-card d-flex flex-column align-items-center justify-content-center text-decoration-none py-4 h-100">
                                <i class="bi bi-journal-text fs-2 text-sg-accent mb-2"></i>
                                <span class="fw-600 text-body">Entry Logs</span>
                            </a>
                        </div>
                        <div class="col-6 col-md-4">
                            <a href="<?= APP_URL ?>/admin/sms_notifications.php" class="sg-card d-flex flex-column align-items-center justify-content-center text-decoration-none py-4 h-100">
                                <i class="bi bi-chat-dots-fill fs-2 text-sg-accent mb-2"></i>
                                <span class="fw-600 text-body">SMS Notifications</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="sg-card h-100">
                    <h6 class="fw-bold mb-3">Granted vs Denied (Today)</h6>
                    <canvas id="pieChart" height="180"></canvas>
                </div>
            </div>
        </div>

        <!-- Recent logs -->
        <div class="row g-3">
            <div class="col-12">
                <div class="sg-card h-100">
                    <h6 class="fw-bold mb-3">Recent Entry Logs</h6>
                    <div class="table-responsive">
                        <table class="table table-sg mb-0">
                            <thead><tr><th>Name</th><th>Grade</th><th>Time In</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php if (!$recentLogs): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">No entry logs yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td><?= e($log['fullname'] ?? 'Unknown') ?></td>
                                    <td><?= e($log['grade'] ?? '-') ?></td>
                                    <td><?= date('h:i A', strtotime($log['time_in'])) ?></td>
                                    <td>
                                        <?php if ($log['status'] === 'Match'): ?>
                                            <span class="badge-match">Match</span>
                                        <?php else: ?>
                                            <span class="badge-denied">Denied</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
// Granted vs Denied Pie
new Chart(document.getElementById('pieChart'), {
    type: 'doughnut',
    data: {
        labels: ['Granted', 'Denied'],
        datasets: [{ data: <?= json_encode($pieData) ?>, backgroundColor: ['#20C997', '#DC3545'] }]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
