<?php
require_once __DIR__ . '/../database/config.php';
require_admin();

// ---- Dashboard data ----
$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status = 'Active'")->fetchColumn();
$today = date('Y-m-d');

// Range comparison (not DATE(time_in) = ?) so the idx_entry_logs_time_in index can be used.
$todayStart = $today . ' 00:00:00';
$todayEnd   = $today . ' 23:59:59';

// "Today's Entries" / "Students Entered Today" are explicitly Entry-only
// metrics (see their sub-labels below) — filtered by transaction_type so an
// Exit scan is never counted as an "entry". "Granted vs Denied" (the pie
// chart) intentionally stays unfiltered: it represents ALL successful
// verifications today (Entry + Exit) against denials, so it keeps its own
// $todayAllGranted total rather than reusing $todayEntries.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status = 'Match' AND transaction_type = 'Entry'");
$stmt->execute([$todayStart, $todayEnd]);
$todayEntries = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status = 'Match'");
$stmt->execute([$todayStart, $todayEnd]);
$todayAllGranted = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status = 'Denied'");
$stmt->execute([$todayStart, $todayEnd]);
$deniedEntries = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status = 'Match' AND transaction_type = 'Entry' AND student_id IS NOT NULL");
$stmt->execute([$todayStart, $todayEnd]);
$uniqueStudentsToday = (int)$stmt->fetchColumn();

// Build a complete 7-day dataset so the chart can drive the dashboard KPIs.
$weeklyRows = $pdo->query("
    SELECT DATE(time_in) AS d,
           SUM(status = 'Match' AND transaction_type = 'Entry') AS granted,
           SUM(status = 'Match') AS all_granted,
           SUM(status = 'Denied') AS denied,
           COUNT(DISTINCT CASE WHEN status = 'Match' AND transaction_type = 'Entry' AND student_id IS NOT NULL THEN student_id END) AS unique_students
    FROM entry_logs
    WHERE time_in >= (CURDATE() - INTERVAL 6 DAY)
    GROUP BY DATE(time_in)
    ORDER BY d ASC
")->fetchAll();
$smsWeeklyRows = $pdo->query("
    SELECT DATE(sent_at) AS d,
           SUM(status = 'Match') AS sent,
           SUM(status = 'Pending') AS pending,
           SUM(status = 'Denied') AS failed
    FROM sms_logs
    WHERE sent_at >= (CURDATE() - INTERVAL 6 DAY)
    GROUP BY DATE(sent_at)
    ORDER BY d ASC
")->fetchAll();

$weeklyStats = [];
$weeklyLabels = [];
$weeklyData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $row = null;
    foreach ($weeklyRows as $candidate) {
        if ($candidate['d'] === $d) { $row = $candidate; break; }
    }
    $smsRow = null;
    foreach ($smsWeeklyRows as $candidate) {
        if ($candidate['d'] === $d) { $smsRow = $candidate; break; }
    }
    $stats = [
        'date' => $d,
        'label' => date('D', strtotime($d)),
        'display' => date('M j, Y', strtotime($d)),
        'granted' => (int)($row['granted'] ?? 0),
        'allGranted' => (int)($row['all_granted'] ?? 0),
        'denied' => (int)($row['denied'] ?? 0),
        'unique' => (int)($row['unique_students'] ?? 0),
        'smsSent' => (int)($smsRow['sent'] ?? 0),
        'smsPending' => (int)($smsRow['pending'] ?? 0),
        'smsFailed' => (int)($smsRow['failed'] ?? 0),
    ];
    $weeklyStats[] = $stats;
    $weeklyLabels[] = $stats['label'];
    $weeklyData[] = $stats['granted'];
}

$recentLogs = $pdo->query("\n    SELECT el.*, s.photo AS student_photo\n    FROM entry_logs el\n    LEFT JOIN students s ON s.student_id = el.student_id\n    ORDER BY el.time_in DESC\n    LIMIT 5\n")->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE sent_at BETWEEN ? AND ? AND status = 'Match'");
$stmt->execute([$todayStart, $todayEnd]);
$smsSent = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE sent_at BETWEEN ? AND ? AND status = 'Denied'");
$stmt->execute([$todayStart, $todayEnd]);
$smsFailed = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE sent_at BETWEEN ? AND ? AND status = 'Pending'");
$stmt->execute([$todayStart, $todayEnd]);
$smsPending = (int)$stmt->fetchColumn();

$recentActivity = get_recent_audit_logs($pdo, 5);

$pageTitle = 'Dashboard';
$pageHeading = 'SYSTEM ADMINISTRATOR DASHBOARD';
$pageSubheading = 'Hercor College High School Department';
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="sg-content sg-dashboard-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <main class="sg-main sg-dashboard-main">
        <div class="sg-welcome">Welcome Back!</div>

        <!-- Top statistics -->
        <section class="sg-dashboard-grid sg-stat-grid">
            <article class="sg-dashboard-card sg-stat-card stat-blue">
                <div class="sg-stat-icon"><i class="bi bi-people-fill"></i></div>
                <div class="sg-stat-copy"><div class="sg-stat-label">Total Students</div><div class="sg-stat-value"><?= number_format($totalStudents) ?></div><div class="sg-stat-sub">Total Registered Students</div></div>
            </article>
            <article class="sg-dashboard-card sg-stat-card stat-green">
                <div class="sg-stat-icon"><i class="bi bi-person-plus-fill"></i></div>
                <div class="sg-stat-copy"><div class="sg-stat-label" id="kpiUniqueLabel">Students Entered Today</div><div class="sg-stat-value" id="kpiUniqueValue"><?= number_format($uniqueStudentsToday) ?><span class="sg-stat-denom"> / <?= number_format($totalStudents) ?></span></div><div class="sg-stat-sub">Unique Students</div></div>
            </article>
            <article class="sg-dashboard-card sg-stat-card stat-purple">
                <div class="sg-stat-icon"><i class="bi bi-people-fill"></i></div>
                <div class="sg-stat-copy"><div class="sg-stat-label" id="kpiGrantedLabel">Today's Entries</div><div class="sg-stat-value" id="kpiGrantedValue"><?= number_format($todayEntries) ?></div><div class="sg-stat-sub">Successful Entry Scans</div></div>
            </article>
            <article class="sg-dashboard-card sg-stat-card stat-red">
                <div class="sg-stat-icon"><i class="bi bi-slash-circle"></i></div>
                <div class="sg-stat-copy"><div class="sg-stat-label" id="kpiDeniedLabel">Today's Denied</div><div class="sg-stat-value" id="kpiDeniedValue"><?= number_format($deniedEntries) ?></div><div class="sg-stat-sub">Access Denied Attempts</div></div>
            </article>
        </section>

        <!-- Charts -->
        <section class="sg-dashboard-grid sg-chart-grid">
            <article class="sg-dashboard-card sg-weekly-card">
                <div class="sg-card-heading">
                    <h2><i class="bi bi-bar-chart-fill"></i> Entry Overview This Week <span class="sg-chart-selection" id="chartSelectionLabel">Today</span></h2>
                </div>
                <div class="sg-weekly-chart-wrap"><canvas id="weeklyChart"></canvas></div>
            </article>
            <article class="sg-dashboard-card sg-pie-card">
                <div class="sg-card-heading"><h2><i class="bi bi-star-fill"></i> Granted vs Denied <span id="pieSelectionLabel">Today</span></h2></div>
                <div class="sg-pie-wrap"><canvas id="pieChart"></canvas><div class="sg-pie-center"><strong id="pieTotal"><?= number_format($todayAllGranted + $deniedEntries) ?></strong><span>Total Scans</span></div></div>
                <div class="sg-pie-legend"><span><i class="dot granted"></i> Granted — <strong id="pieGranted"><?= number_format($todayAllGranted) ?></strong></span><span><i class="dot denied"></i> Denied — <strong id="pieDenied"><?= number_format($deniedEntries) ?></strong></span></div>
            </article>
        </section>

        <!-- Bottom dashboard panels -->
        <section class="sg-dashboard-grid sg-bottom-grid">
            <article class="sg-dashboard-card sg-logs-card">
                <div class="sg-card-heading"><h2><i class="bi bi-clock"></i> Recent Entry Logs</h2><a href="<?= APP_URL ?>/admin/entry_logs.php">View All <i class="bi bi-arrow-right"></i></a></div>
                <div class="table-responsive">
                    <table class="sg-dashboard-table">
                        <thead><tr><th>#</th><th>STUDENT NAME</th><th>GRADE LEVEL</th><th>TIME IN</th><th>STATUS</th></tr></thead>
                        <tbody>
                        <?php if (!$recentLogs): ?><tr><td colspan="5" class="text-center text-muted py-4">No entry logs yet.</td></tr><?php endif; ?>
                        <?php foreach ($recentLogs as $i => $log):
                            $photo = trim((string)($log['student_photo'] ?? ''));
                            $photoUrl = $photo !== '' ? APP_URL . '/uploads/student/' . rawurlencode(basename($photo)) : '';
                            $isMatch = ($log['status'] ?? '') === 'Match';
                        ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><div class="sg-student-cell"><?php if ($photoUrl): ?><img src="<?= e($photoUrl) ?>" alt=""><?php else: ?><span class="sg-student-avatar"><i class="bi bi-person-fill"></i></span><?php endif; ?><span><?= e($log['fullname'] ?? 'Unknown Person') ?><small><?= e($log['student_id'] ?? '') ?></small></span></div></td>
                                <td><?= e($log['grade'] ?? '-') ?></td>
                                <td><?= e(date('h:i A', strtotime($log['time_in']))) ?></td>
                                <td><span class="sg-status <?= $isMatch ? 'granted' : 'denied' ?>"><i class="bi <?= $isMatch ? 'bi-check-circle-fill' : 'bi-dash-circle-fill' ?>"></i> <?= $isMatch ? 'Granted' : 'Denied' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <div class="sg-right-stack">
                <article class="sg-dashboard-card sg-sms-card">
                    <div class="sg-card-heading"><h2><i class="bi bi-send-fill"></i> SMS Notifications <span id="smsSelectionLabel">Today</span></h2></div>
                    <div class="sg-sms-body">
                        <div class="sg-sms-donut"><canvas id="smsDonut"></canvas><div class="sg-sms-center"><strong id="smsCenterValue"><?= number_format($smsSent) ?></strong><span>Sent</span></div></div>
                        <div class="sg-sms-legend"><div><i class="dot sent"></i><span>Sent</span><strong id="smsSentValue"><?= number_format($smsSent) ?></strong></div><div><i class="dot pending"></i><span>Pending</span><strong id="smsPendingValue"><?= number_format($smsPending) ?></strong></div><div><i class="dot failed"></i><span>Failed</span><strong id="smsFailedValue"><?= number_format($smsFailed) ?></strong></div></div>
                    </div>
                </article>

                <article class="sg-dashboard-card sg-activity-card">
                    <div class="sg-card-heading"><h2><i class="bi bi-people-fill"></i> System Activity</h2><a href="<?= APP_URL ?>/admin/audit_logs.php">View All <i class="bi bi-arrow-right"></i></a></div>
                    <ul class="sg-activity-list">
                        <?php if (!$recentActivity): ?><li class="text-muted">No recent activity yet.</li><?php endif; ?>
                        <?php foreach ($recentActivity as $act): ?>
                            <li><span class="sg-activity-main"><i class="bi bi-person-fill"></i><span><strong><?= e($act['username'] ?? 'System') ?></strong> - <?= e($act['action']) ?></span></span><time><?= e(date('h:i A', strtotime($act['created_at']))) ?></time></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            </div>
        </section>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
(() => {
    const weeklyStats = <?= json_encode($weeklyStats) ?>;
    const labels = weeklyStats.map(x => x.label);
    const weekly = weeklyStats.map(x => x.granted);
    const totalStudents = <?= (int)$totalStudents ?>;
    const initialDate = <?= json_encode($today) ?>;
    const initialGranted = <?= (int)$todayAllGranted ?>;
    const initialDenied = <?= (int)$deniedEntries ?>;

    function applyDayStats(index) {
        const day = weeklyStats[index];
        if (!day) return;
        const suffix = day.date === initialDate ? 'Today' : day.display;
        document.getElementById('kpiUniqueLabel').textContent = `Students Entered — ${suffix}`;
        document.getElementById('kpiUniqueValue').innerHTML = `${day.unique}<span class="sg-stat-denom"> / ${totalStudents}</span>`;
        document.getElementById('kpiGrantedLabel').textContent = `Entries — ${suffix}`;
        document.getElementById('kpiGrantedValue').textContent = day.granted;
        document.getElementById('kpiDeniedLabel').textContent = `Denied — ${suffix}`;
        document.getElementById('kpiDeniedValue').textContent = day.denied;
        document.getElementById('chartSelectionLabel').textContent = suffix;
        document.getElementById('pieSelectionLabel').textContent = suffix;
        document.getElementById('pieTotal').textContent = day.allGranted + day.denied;
        document.getElementById('pieGranted').textContent = day.allGranted;
        document.getElementById('pieDenied').textContent = day.denied;
        document.getElementById('smsCenterValue').textContent = day.smsSent;
        document.getElementById('smsSentValue').textContent = day.smsSent;
        document.getElementById('smsPendingValue').textContent = day.smsPending;
        document.getElementById('smsFailedValue').textContent = day.smsFailed;
        document.getElementById('smsSelectionLabel').textContent = suffix;
        if (smsChart) {
            smsChart.data.datasets[0].data = [day.smsSent, day.smsPending, day.smsFailed];
            smsChart.update();
        }
        if (pieChart) {
            pieChart.data.datasets[0].data = [day.allGranted, day.denied];
            pieChart.update();
        }
        weeklyChart?.setActiveElements([{datasetIndex:0,index}]);
        weeklyChart?.tooltip.setActiveElements([{datasetIndex:0,index}], {x:0,y:0});
        weeklyChart?.update();
    }

    const weeklyCanvas = document.getElementById('weeklyChart');
    let weeklyChart = null;
    if (weeklyCanvas) {
        weeklyChart = new Chart(weeklyCanvas, {
            type:'line',
            data:{ labels, datasets:[{ data:weekly, borderColor:'#2E86D1', backgroundColor:'rgba(46,134,209,.15)', borderWidth:2.5, tension:.35, fill:true, pointRadius:5, pointHoverRadius:7, pointBackgroundColor:'#2E86D1', pointBorderColor:'#fff', pointBorderWidth:2 }] },
            options:{
                responsive:true, maintainAspectRatio:false,
                interaction:{intersect:false,mode:'index'},
                onClick:(event, elements) => {
                    if (elements.length) applyDayStats(elements[0].index);
                },
                onHover:(event, elements) => { event.native.target.style.cursor = elements.length ? 'pointer' : 'default'; },
                plugins:{legend:{display:false},tooltip:{backgroundColor:'#1f2937',titleColor:'#fff',bodyColor:'#fff',padding:10,callbacks:{title:(items)=>weeklyStats[items[0].dataIndex]?.display || ''}}},
                scales:{x:{grid:{display:false},ticks:{color:'#65738B',font:{size:12,weight:'600'}}},y:{beginAtZero:true,ticks:{precision:0,color:'#65738B'},grid:{color:'rgba(84,105,140,.10)'}}}
            }
        });
    }

    let pieChart = null;
    const pieCanvas = document.getElementById('pieChart');
    if (pieCanvas) pieChart = new Chart(pieCanvas,{type:'doughnut',data:{labels:['Granted','Denied'],datasets:[{data:[initialGranted,initialDenied],backgroundColor:['#65A9A3','#D9A7B0'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'73%',plugins:{legend:{display:false}}}});

    let smsChart = null;
    const smsCanvas = document.getElementById('smsDonut');
    if (smsCanvas) smsChart = new Chart(smsCanvas,{type:'doughnut',data:{labels:['Sent','Pending','Failed'],datasets:[{data:[<?= (int)$smsSent ?>,<?= (int)$smsPending ?>,<?= (int)$smsFailed ?>],backgroundColor:['#69A9A3','#E8C36A','#D88992'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'72%',plugins:{legend:{display:false}}}});

    const initialIndex = Math.max(0, weeklyStats.findIndex(x => x.date === initialDate));
    applyDayStats(initialIndex);
})();
</script>
