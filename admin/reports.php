<?php
require_once __DIR__ . '/../database/config.php';
require_login();

$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo   = $_GET['to'] ?? date('Y-m-d');

// ---- Summary stats ----
$stmt = $pdo->prepare("SELECT COUNT(*) FROM entry_logs WHERE DATE(time_in) BETWEEN ? AND ? AND status='Match'");
$stmt->execute([$dateFrom, $dateTo]);
$totalEntries = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM entry_logs WHERE DATE(time_in) BETWEEN ? AND ? AND status='Denied'");
$stmt->execute([$dateFrom, $dateTo]);
$deniedEntries = $stmt->fetchColumn();

$totalStudents = $pdo->query("SELECT COUNT(*) FROM students WHERE status='Active'")->fetchColumn();

$stmt = $pdo->prepare("SELECT AVG(TIME_TO_SEC(TIME(time_in))) as avg_sec FROM entry_logs WHERE DATE(time_in) BETWEEN ? AND ? AND status='Match'");
$stmt->execute([$dateFrom, $dateTo]);
$avgSec = $stmt->fetchColumn();
$avgTime = $avgSec ? date('h:i A', mktime(0, 0, (int)$avgSec)) : '-';

// ---- Hourly distribution for chart ----
$stmt = $pdo->prepare("SELECT HOUR(time_in) as hr, COUNT(*) as total FROM entry_logs WHERE DATE(time_in) BETWEEN ? AND ? AND status='Match' GROUP BY HOUR(time_in) ORDER BY hr");
$stmt->execute([$dateFrom, $dateTo]);
$hourlyRaw = $stmt->fetchAll();
$hourlyMap = [];
foreach ($hourlyRaw as $r) { $hourlyMap[(int)$r['hr']] = (int)$r['total']; }
$hourLabels = [];
$hourData = [];
for ($h = 6; $h <= 18; $h++) {
    $hourLabels[] = date('ga', mktime($h, 0, 0));
    $hourData[] = $hourlyMap[$h] ?? 0;
}

// ---- Peak hour ----
$peakHour = '-';
if ($hourlyMap) {
    $peakH = array_keys($hourlyMap, max($hourlyMap))[0];
    $peakHour = date('g A', mktime($peakH, 0, 0));
}

// ---- Method breakdown ----
$stmt = $pdo->prepare("SELECT verification_method, COUNT(*) as total FROM entry_logs WHERE DATE(time_in) BETWEEN ? AND ? AND status='Match' GROUP BY verification_method");
$stmt->execute([$dateFrom, $dateTo]);
$methodRaw = $stmt->fetchAll();
$methodLabels = array_column($methodRaw, 'verification_method');
$methodData = array_map('intval', array_column($methodRaw, 'total'));

$pageTitle = 'Reports';
$pageHeading = 'Reports';
$activePage = 'reports';
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sg-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <main class="sg-main">
        <div class="sg-card mb-3 sg-no-print">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small text-muted">From</label>
                    <input type="date" name="from" value="<?= e($dateFrom) ?>" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">To</label>
                    <input type="date" name="to" value="<?= e($dateTo) ?>" class="form-control">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sg-primary w-100">Generate</button>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-secondary w-100" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
                </div>
            </form>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-3">
                <div class="sg-card stat-card"><div class="icon-box bg-icon-blue"><i class="bi bi-box-arrow-in-right"></i></div>
                    <div><div class="stat-value"><?= (int)$totalEntries ?></div><div class="stat-label">Total Entries</div></div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="sg-card stat-card"><div class="icon-box bg-icon-green"><i class="bi bi-mortarboard-fill"></i></div>
                    <div><div class="stat-value"><?= (int)$totalStudents ?></div><div class="stat-label">Total Students</div></div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="sg-card stat-card"><div class="icon-box bg-icon-purple"><i class="bi bi-clock-fill"></i></div>
                    <div><div class="stat-value" style="font-size:1.2rem;"><?= e($avgTime) ?></div><div class="stat-label">Average Entry Time</div></div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="sg-card stat-card"><div class="icon-box bg-icon-red"><i class="bi bi-shield-slash-fill"></i></div>
                    <div><div class="stat-value"><?= (int)$deniedEntries ?></div><div class="stat-label">Denied Entries</div></div></div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-8">
                <div class="sg-card h-100">
                    <div class="d-flex justify-content-between">
                        <h6 class="fw-bold mb-3">Entries Overview (by Hour)</h6>
                        <span class="text-muted small">Peak hour: <strong><?= e($peakHour) ?></strong></span>
                    </div>
                    <canvas id="hourlyChart" height="110"></canvas>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="sg-card h-100">
                    <h6 class="fw-bold mb-3">Verification Method Breakdown</h6>
                    <canvas id="methodChart" height="180"></canvas>
                </div>
            </div>
        </div>

        <div class="text-end">
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()" title="Opens your browser's print dialog — choose 'Save as PDF' as the destination"><i class="bi bi-printer me-1"></i>Print / Save as PDF</button>
            <a href="entry_logs.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel (via Entry Logs)</a>
        </div>
    </main>
</div>

<script>
new Chart(document.getElementById('hourlyChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($hourLabels) ?>, datasets: [{ label: 'Entries', data: <?= json_encode($hourData) ?>, backgroundColor: '#0B5ED7', borderRadius: 6 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
new Chart(document.getElementById('methodChart'), {
    type: 'doughnut',
    data: { labels: <?= json_encode($methodLabels ?: ['No data']) ?>, datasets: [{ data: <?= json_encode($methodData ?: [1]) ?>, backgroundColor: ['#0B5ED7', '#20C997', '#FFC107'] }] },
    options: { plugins: { legend: { position: 'bottom' } } }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
