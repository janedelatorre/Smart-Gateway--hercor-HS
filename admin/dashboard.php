<?php
require_once __DIR__ . '/../database/config.php';
require_admin(); // Administrator-only dashboard — Staff are routed to staff_dashboard.php

// ---- Stat card queries ----
$totalStudents = $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'Active'")->fetchColumn();

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

// ---- Weekly entries overview (last 7 days) ----
$weekly = $pdo->query("
    SELECT DATE(time_in) as d, COUNT(*) as total
    FROM entry_logs
    WHERE status = 'Match' AND time_in >= (CURDATE() - INTERVAL 6 DAY)
    GROUP BY DATE(time_in)
    ORDER BY d ASC
")->fetchAll();
$weeklyLabels = [];
$weeklyData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $weeklyLabels[] = date('D', strtotime($d));
    $match = array_filter($weekly, fn($r) => $r['d'] === $d);
    $weeklyData[] = $match ? (int) array_values($match)[0]['total'] : 0;
}

// ---- Granted vs Denied (today) pie ----
$pieData = [(int)$todayEntries, (int)$deniedEntries];

// ---- Recent entry logs ----
$recentLogs = $pdo->query("SELECT * FROM entry_logs ORDER BY time_in DESC LIMIT 6")->fetchAll();

// ---- SMS stats today ----
$smsSent = $pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE DATE(sent_at) = ? AND status = 'Match'");
$smsSent->execute([$today]);
$smsSent = $smsSent->fetchColumn();
$smsFailed = $pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE DATE(sent_at) = ? AND status = 'Denied'");
$smsFailed->execute([$today]);
$smsFailed = $smsFailed->fetchColumn();
$smsPending = $pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE DATE(sent_at) = ? AND status = 'Pending'");
$smsPending->execute([$today]);
$smsPending = $smsPending->fetchColumn();

// ---- Recent system activity (live audit trail) ----
$recentActivity = get_recent_audit_logs($pdo, 5);

$pageTitle = 'Dashboard';
$pageHeading = 'Dashboard';
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
                        <div class="stat-label">Total Students</div>
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

        <!-- Charts -->
        <div class="row g-3 mb-3">
            <div class="col-lg-8">
                <div class="sg-card h-100">
                    <h6 class="fw-bold mb-3">Entries Overview (This Week)</h6>
                   <div style="height:320px">
    <canvas id="weeklyChart"></canvas>
</div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="sg-card h-100">
                    <h6 class="fw-bold mb-3">Granted vs Denied (Today)</h6>
                   <div style="height:260px;">
    <canvas id="pieChart"></canvas>
</div>
                </div>
            </div>
        </div>

        <!-- Recent logs + SMS + Activity -->
        <div class="row g-3">
            <div class="col-lg-7">
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
            <div class="col-lg-5">
                <div class="sg-card mb-3">
                    <h6 class="fw-bold mb-3">SMS Notifications (Today)</h6>
                    <div class="d-flex align-items-center gap-4">
                      <div style="width:170px;height:170px;">
    <canvas id="smsDonut"></canvas>
</div>
                        <ul class="list-unstyled mb-0 small">
                            <li class="mb-2"><span class="badge-match me-2">&nbsp;</span>Sent: <strong><?= (int)$smsSent ?></strong></li>
                            <li class="mb-2"><span class="badge-denied me-2">&nbsp;</span>Failed: <strong><?= (int)$smsFailed ?></strong></li>
                            <li><span class="badge-pending me-2">&nbsp;</span>Pending: <strong><?= (int)$smsPending ?></strong></li>
                        </ul>
                    </div>
                </div>
                <div class="sg-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0">System Activity</h6>
                        <a href="<?= APP_URL ?>/admin/audit_logs.php" class="small text-decoration-none">View all</a>
                    </div>
                    <ul class="list-unstyled small mb-0">
                        <?php if (!$recentActivity): ?>
                            <li class="text-muted">No recent activity yet.</li>
                        <?php endif; ?>
                        <?php foreach ($recentActivity as $i => $act): ?>
                            <li class="<?= $i < count($recentActivity) - 1 ? 'mb-2 ' : '' ?>d-flex justify-content-between">
                                <span><i class="bi bi-dot text-sg-accent"></i><?= e($act['username'] ?? 'System') ?> — <?= e($act['action']) ?></span>
                                <span class="text-muted" title="<?= e(date('M j, Y h:i A', strtotime($act['created_at']))) ?>"><?= e(date('h:i A', strtotime($act['created_at']))) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </main>
</div>
 <?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
   
// Weekly Entries Line/Bar Chart
new Chart(document.getElementById('weeklyChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($weeklyLabels) ?>,
    datasets: [{
    label: 'Entries',
    data: <?= json_encode($weeklyData) ?>,

    borderColor: '#2563EB',
    backgroundColor: 'rgba(37,99,235,.12)',

    borderWidth: 3,
    tension: .4,
    fill: true,

    

    pointRadius: 5,
    pointHoverRadius: 7,

    pointHoverBackgroundColor: '#fff',
pointHoverBorderColor: '#2563EB',
pointHoverBorderWidth: 3,

    pointBackgroundColor: '#2563EB',
    pointBorderColor: '#fff',
    pointBorderWidth: 2


}]
    },
    options: {
    responsive: true,
    maintainAspectRatio: false,

animation: {
    duration: 1200,
    easing: 'easeOutQuart'
},

    interaction: {
        intersect: false,
        mode: 'index'
    },

    plugins: {
        legend: {
            display: false
        },
        tooltip: {
            backgroundColor: '#1f2937',
            titleColor: '#fff',
            bodyColor: '#fff',
            padding: 10
        }
    },

    scales: {
        x: {
            grid: {
                display: false
            }
        },
        y: {
            beginAtZero: true,
            ticks: {
                precision: 0
            },
            grid: {
                color: 'rgba(0,0,0,.06)'
            }
        }
    }
}});

// Granted vs Denied Pie
new Chart(document.getElementById('pieChart'), {
    type: 'doughnut',
    data: {
        labels: ['Granted', 'Denied'],
        datasets: [{ data: <?= json_encode($pieData) ?>, backgroundColor: ['#20C997', '#DC3545'] }]
    },
   options: {
    responsive: true,
    maintainAspectRatio: false,

    cutout: '70%',
    radius: '95%',

    plugins: {
        legend: {
            position: 'bottom',
            labels: {
                usePointStyle: true,
                padding: 20
            }
        }
    }
}
});

// SMS Donut
new Chart(document.getElementById('smsDonut'), {
    type: 'doughnut',
    data: {
        labels: ['Sent', 'Failed', 'Pending'],
        datasets: [{ data: [<?= (int)$smsSent ?>, <?= (int)$smsFailed ?>, <?= (int)$smsPending ?>], backgroundColor: ['#0B5ED7', '#DC3545', '#FFC107'] }]
    },
  options: {
    responsive: true,
    maintainAspectRatio: false,

    cutout: '72%',
    radius: '95%',

    plugins: {
        legend: {
            display: false
        }
    }
}
});
</script>
