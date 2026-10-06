<?php
require_once __DIR__ . '/../database/config.php';
require_login();

$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-d');
if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

$allowedGrades = array_map(fn($g) => "Grade $g", range(7, 12));
$grade = $_GET['grade'] ?? '';
$grade = in_array($grade, $allowedGrades, true) ? $grade : '';
$gradeClause = $grade !== '' ? ' AND grade = ?' : '';
$gradeParam = $grade !== '' ? [$grade] : [];
$start = $dateFrom . ' 00:00:00';
$end = $dateTo . ' 23:59:59';
$params = [$start, $end, ...$gradeParam];

function report_count(PDO $pdo, string $sql, array $params = []): int {
    $s = $pdo->prepare($sql); $s->execute($params); return (int)$s->fetchColumn();
}

$totalLogs = report_count($pdo, "SELECT COUNT(*) FROM entry_logs WHERE time_in BETWEEN ? AND ?$gradeClause", $params);
$granted = report_count($pdo, "SELECT COUNT(*) FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status='Match'$gradeClause", $params);
$denied = report_count($pdo, "SELECT COUNT(*) FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status='Denied'$gradeClause", $params);
$totalStudents = $grade !== ''
    ? report_count($pdo, "SELECT COUNT(*) FROM students WHERE status='Active' AND grade=?", [$grade])
    : report_count($pdo, "SELECT COUNT(*) FROM students WHERE status='Active'");
$uniqueStudents = report_count($pdo, "SELECT COUNT(DISTINCT student_id) FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status='Match' AND student_id IS NOT NULL$gradeClause", $params);
$smsSent = report_count($pdo, "SELECT COUNT(*) FROM sms_logs WHERE sent_at BETWEEN ? AND ? AND status='Match'", [$start, $end]);

$successRate = $totalLogs > 0 ? round(($granted / $totalLogs) * 100, 1) : 0;
$deniedRate = $totalLogs > 0 ? round(($denied / $totalLogs) * 100, 1) : 0;
$avgPerDay = max(1, (int)((strtotime($dateTo) - strtotime($dateFrom)) / 86400) + 1);
$avgDaily = round($totalLogs / $avgPerDay, 1);

$stmt = $pdo->prepare("SELECT DATE(time_in) day, COUNT(*) total, SUM(status='Match') granted, SUM(status='Denied') denied FROM entry_logs WHERE time_in BETWEEN ? AND ?$gradeClause GROUP BY DATE(time_in) ORDER BY day");
$stmt->execute($params); $dailyRows = $stmt->fetchAll();
$dailyMap = [];
foreach ($dailyRows as $r) $dailyMap[$r['day']] = $r;
$dailyLabels=[]; $dailyTotal=[]; $dailyGranted=[]; $dailyDenied=[];
$period = new DatePeriod(new DateTime($dateFrom), new DateInterval('P1D'), (new DateTime($dateTo))->modify('+1 day'));
foreach ($period as $dt) { $d=$dt->format('Y-m-d'); $dailyLabels[]=$dt->format('M j'); $dailyTotal[]=(int)($dailyMap[$d]['total']??0); $dailyGranted[]=(int)($dailyMap[$d]['granted']??0); $dailyDenied[]=(int)($dailyMap[$d]['denied']??0); }
$peakDay='-'; $peakDayCount=0;
foreach ($dailyMap as $d=>$r) if ((int)$r['total'] > $peakDayCount) { $peakDayCount=(int)$r['total']; $peakDay=date('M j, Y', strtotime($d)); }

$stmt=$pdo->prepare("SELECT HOUR(time_in) hr, COUNT(*) total FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status='Match'$gradeClause GROUP BY HOUR(time_in) ORDER BY hr");
$stmt->execute($params); $hourMap=[]; foreach($stmt->fetchAll() as $r) $hourMap[(int)$r['hr']]=(int)$r['total'];
$hourLabels=[]; $hourData=[]; for($h=6;$h<=18;$h++){ $hourLabels[]=date('g A',mktime($h,0,0)); $hourData[]=$hourMap[$h]??0; }
$peakHour='-'; $peakHourCount=0; foreach($hourMap as $h=>$n) if($n>$peakHourCount){$peakHourCount=$n;$peakHour=date('g A',mktime($h,0,0));}

$stmt=$pdo->prepare("SELECT verification_method, COUNT(*) total FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status='Match'$gradeClause GROUP BY verification_method ORDER BY total DESC");
$stmt->execute($params); $methodRows=$stmt->fetchAll();
$methodLabels=array_column($methodRows,'verification_method'); $methodData=array_map('intval',array_column($methodRows,'total'));

$stmt=$pdo->prepare("SELECT transaction_type, COUNT(*) total FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status='Match'$gradeClause GROUP BY transaction_type");
$stmt->execute($params); $typeRows=$stmt->fetchAll(); $typeMap=['Entry'=>0,'Exit'=>0]; foreach($typeRows as $r) $typeMap[$r['transaction_type']]=(int)$r['total'];

$stmt=$pdo->prepare("SELECT grade, COUNT(*) total FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status='Match' AND grade IS NOT NULL$gradeClause GROUP BY grade ORDER BY total DESC, grade");
$stmt->execute($params); $gradeRows=$stmt->fetchAll();

$stmt=$pdo->prepare("SELECT COALESCE(reason,'Unspecified') reason, COUNT(*) total FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status='Denied'$gradeClause GROUP BY reason ORDER BY total DESC, reason LIMIT 8");
$stmt->execute($params); $denialRows=$stmt->fetchAll();

$stmt=$pdo->prepare("SELECT student_id, COALESCE(MAX(fullname),'Unknown') fullname, MAX(grade) grade, COUNT(*) total FROM entry_logs WHERE time_in BETWEEN ? AND ? AND status='Match' AND student_id IS NOT NULL$gradeClause GROUP BY student_id ORDER BY total DESC, fullname LIMIT 10");
$stmt->execute($params); $topStudents=$stmt->fetchAll();

$stmt=$pdo->prepare("SELECT DATE(time_in) day, COUNT(*) total, SUM(status='Match') granted, SUM(status='Denied') denied FROM entry_logs WHERE time_in BETWEEN ? AND ?$gradeClause GROUP BY DATE(time_in) ORDER BY day DESC LIMIT 14");
$stmt->execute($params); $recentDaily=array_reverse($stmt->fetchAll());

$reportSchoolName = get_setting($pdo, 'school_name', 'Hercor College');
$reportSchoolAddress = get_setting($pdo, 'school_address', '');
$reportSchoolContact = get_setting($pdo, 'school_contact', '');
$reportPreparedBy = $_SESSION['fullname'] ?? ($_SESSION['username'] ?? 'System');
$reportGeneratedAt = date('F j, Y h:i A');
$reportTitle = 'Campus Entry & Exit Report';

$pageTitle='Reports'; $pageHeading='Reports'; $pageSubheading='Operational summary, access activity, and verification results'; $activePage='reports';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<div class="sg-content">
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>
<main class="sg-main sg-reports-page">
  <div class="sg-card mb-3 sg-no-print">
    <form method="GET" class="row g-3 align-items-end" id="reportFilterForm">
      <div class="col-sm-6 col-lg-3"><label class="form-label">From Date</label><div class="sg-date-field"><input type="date" name="from" value="<?=e($dateFrom)?>" class="form-control" aria-label="From Date"></div></div>
      <div class="col-sm-6 col-lg-3"><label class="form-label">End Date</label><div class="sg-date-field"><input type="date" name="to" value="<?=e($dateTo)?>" class="form-control" aria-label="End Date"></div></div>
      <div class="col-sm-6 col-lg-2"><label class="form-label">Grade Level</label><select name="grade" class="form-select"><option value="">All Grades</option><?php foreach($allowedGrades as $g): ?><option value="<?=e($g)?>" <?=$grade===$g?'selected':''?>><?=e($g)?></option><?php endforeach; ?></select></div>
      <div class="col-sm-6 col-lg-4 d-flex gap-2"><button type="submit" class="btn btn-sg-primary flex-fill"><i class="bi bi-filter me-1"></i>Apply Filters</button><button type="button" class="btn btn-outline-info flex-fill" onclick="printReport(false)"><i class="bi bi-printer me-1"></i>Print</button><button type="button" class="btn btn-outline-light flex-fill" onclick="printReport(true)"><i class="bi bi-file-earmark-pdf me-1"></i>Save as PDF</button></div>
    </form>
  </div>

  <section class="sg-report-document" id="reportDocument">
    <header class="sg-report-cover mb-3">
      <div class="sg-report-brand-line"><div class="sg-report-logo"><img src="<?=APP_URL?>/assets/images/logo/school-logo.png" alt="Hercor College"></div><div><h2><?=e($reportSchoolName)?></h2><p><?=e($reportSchoolAddress)?><?=($reportSchoolAddress && $reportSchoolContact)?' · ':''?><?=e($reportSchoolContact)?></p></div></div>
      <div class="sg-report-cover-title"><span>OFFICIAL SYSTEM REPORT</span><h1><?=e($reportTitle)?></h1><p><?=e($grade ?: 'All Grade Levels')?> &nbsp;•&nbsp; <?=e(date('F j, Y',strtotime($dateFrom)))?> – <?=e(date('F j, Y',strtotime($dateTo)))?></p></div>
    </header>

    <div class="sg-card mb-3 sg-report-summary">
      <div class="sg-card-heading"><h2><i class="bi bi-file-text"></i> Executive Summary</h2></div>
      <p>For the selected reporting period, the Smart Gateway system recorded <strong><?=number_format($totalLogs)?></strong> access transactions involving <strong><?=number_format($uniqueStudents)?></strong> unique students. <strong><?=number_format($granted)?></strong> transactions were granted and <strong><?=number_format($denied)?></strong> were denied, producing a grant rate of <strong><?=$successRate?>%</strong>. The average activity level was <strong><?=number_format($avgDaily,1)?></strong> transactions per day. The highest-volume day was <strong><?=e($peakDay)?></strong>, while the busiest granted-scan hour was <strong><?=e($peakHour)?></strong>.</p>
      <div class="sg-report-meta"><span>Generated <?=e($reportGeneratedAt)?></span><span>Prepared by <?=e($reportPreparedBy)?></span></div>
    </div>

    <div class="row g-3 mb-3">
      <?php $kpis=[['Total Transactions',$totalLogs,'bi-activity','blue'],['Granted',$granted,'bi-check-circle-fill','green'],['Denied',$denied,'bi-shield-x','red'],['Unique Students',$uniqueStudents,'bi-people-fill','purple'],['Active Students',$totalStudents,'bi-person-check-fill','blue'],['SMS Sent',$smsSent,'bi-send-fill','green']]; foreach($kpis as $k): ?>
      <div class="col-6 col-xl-2"><div class="sg-card stat-card sg-report-kpi"><div class="icon-box bg-icon-<?=$k[3]?>"><i class="bi <?=$k[2]?>"></i></div><div><div class="stat-value"><?=number_format($k[1])?></div><div class="stat-label"><?=e($k[0])?></div></div></div></div>
      <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-lg-8"><div class="sg-card"><div class="sg-card-heading"><h2><i class="bi bi-graph-up"></i> Activity Trend</h2><span class="sg-report-note">Daily transactions</span></div><div class="sg-report-chart-wrap sg-report-chart-tall"><canvas id="dailyReportChart"></canvas></div></div></div>
      <div class="col-lg-4"><div class="sg-card h-100"><div class="sg-card-heading"><h2><i class="bi bi-pie-chart-fill"></i> Verification Methods</h2></div><div class="sg-report-method-wrap"><canvas id="methodChart"></canvas></div></div></div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-lg-8"><div class="sg-card"><div class="sg-card-heading"><h2><i class="bi bi-clock-history"></i> Hourly Activity</h2><span class="sg-report-note">Granted scans • peak <?=e($peakHour)?></span></div><div class="sg-report-chart-wrap"><canvas id="hourlyChart"></canvas></div></div></div>
      <div class="col-lg-4"><div class="sg-card h-100"><div class="sg-card-heading"><h2><i class="bi bi-arrow-left-right"></i> Entry vs Exit</h2></div><div class="sg-report-method-wrap"><canvas id="typeChart"></canvas></div></div></div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-lg-6"><div class="sg-card h-100"><div class="sg-card-heading"><h2><i class="bi bi-mortarboard"></i> Activity by Grade</h2></div><div class="table-responsive"><table class="table sg-report-table"><thead><tr><th>Grade</th><th class="text-end">Granted Transactions</th><th class="text-end">Share</th></tr></thead><tbody><?php foreach($gradeRows as $r): $share=$granted>0?round(((int)$r['total']/$granted)*100,1):0; ?><tr><td><?=e($r['grade'])?></td><td class="text-end"><?=number_format((int)$r['total'])?></td><td class="text-end"><?=$share?>%</td></tr><?php endforeach; if(!$gradeRows): ?><tr><td colspan="3" class="text-center text-muted">No graded activity in this period.</td></tr><?php endif; ?></tbody></table></div></div></div>
      <div class="col-lg-6"><div class="sg-card h-100"><div class="sg-card-heading"><h2><i class="bi bi-exclamation-triangle"></i> Denial Reasons</h2></div><div class="table-responsive"><table class="table sg-report-table"><thead><tr><th>Reason</th><th class="text-end">Count</th><th class="text-end">Share</th></tr></thead><tbody><?php foreach($denialRows as $r): $share=$denied>0?round(((int)$r['total']/$denied)*100,1):0; ?><tr><td><?=e($r['reason'])?></td><td class="text-end"><?=number_format((int)$r['total'])?></td><td class="text-end"><?=$share?>%</td></tr><?php endforeach; if(!$denialRows): ?><tr><td colspan="3" class="text-center text-muted">No denied transactions.</td></tr><?php endif; ?></tbody></table></div></div></div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-lg-6"><div class="sg-card h-100"><div class="sg-card-heading"><h2><i class="bi bi-person-lines-fill"></i> Most Active Students</h2></div><div class="table-responsive"><table class="table sg-report-table"><thead><tr><th>Student</th><th>Grade</th><th class="text-end">Transactions</th></tr></thead><tbody><?php foreach($topStudents as $r): ?><tr><td><?=e($r['fullname'])?><div class="small text-muted"><?=e($r['student_id'])?></div></td><td><?=e($r['grade'] ?: '—')?></td><td class="text-end fw-bold"><?=number_format((int)$r['total'])?></td></tr><?php endforeach; if(!$topStudents): ?><tr><td colspan="3" class="text-center text-muted">No student activity.</td></tr><?php endif; ?></tbody></table></div></div></div>
      <div class="col-lg-6"><div class="sg-card h-100"><div class="sg-card-heading"><h2><i class="bi bi-calendar3"></i> Daily Summary</h2></div><div class="table-responsive"><table class="table sg-report-table"><thead><tr><th>Date</th><th class="text-end">Total</th><th class="text-end">Granted</th><th class="text-end">Denied</th></tr></thead><tbody><?php foreach($recentDaily as $r): ?><tr><td><?=e(date('M j, Y',strtotime($r['day'])))?></td><td class="text-end"><?=number_format((int)$r['total'])?></td><td class="text-end"><?=number_format((int)$r['granted'])?></td><td class="text-end"><?=number_format((int)$r['denied'])?></td></tr><?php endforeach; if(!$recentDaily): ?><tr><td colspan="4" class="text-center text-muted">No activity recorded.</td></tr><?php endif; ?></tbody></table></div></div></div>
    </div>

    <footer class="sg-report-footer"><span>Smart Gateway Campus Entry System</span><span>Confidential system-generated report</span><span>Page content reflects the selected date and grade filters.</span></footer>
  </section>
</main></div>
<script>
function printReport(savePdf){
  const oldTitle=document.title;
  if(savePdf){ document.title='Smart_Gateway_Campus_Entry_Report_<?=e($dateFrom)?>_to_<?=e($dateTo)?>'; }
  window.print();
  setTimeout(()=>{document.title=oldTitle;},1200);
}
document.addEventListener('DOMContentLoaded', function(){
 if(typeof Chart==='undefined') return;
 const light=document.documentElement.getAttribute('data-theme')==='light';
 const text=light?'#475569':'#c4d8e2', grid=light?'rgba(71,85,105,.12)':'rgba(183,207,218,.10)';
 Chart.defaults.color=text; Chart.defaults.borderColor=grid;
 new Chart(document.getElementById('dailyReportChart'),{type:'line',data:{labels:<?=json_encode($dailyLabels)?>,datasets:[{label:'Granted',data:<?=json_encode($dailyGranted)?>,borderColor:'#22C55E',backgroundColor:'rgba(34,197,94,.10)',fill:true,tension:.35},{label:'Denied',data:<?=json_encode($dailyDenied)?>,borderColor:'#EF4444',backgroundColor:'rgba(239,68,68,.06)',fill:true,tension:.35}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,ticks:{precision:0},grid:{color:grid}}}}});
 new Chart(document.getElementById('hourlyChart'),{type:'bar',data:{labels:<?=json_encode($hourLabels)?>,datasets:[{label:'Granted Scans',data:<?=json_encode($hourData)?>,backgroundColor:'#06B6D4',borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{maxRotation:0,autoSkip:true,maxTicksLimit:13},grid:{display:false}},y:{beginAtZero:true,ticks:{precision:0},grid:{color:grid}}}}});
 new Chart(document.getElementById('methodChart'),{type:'doughnut',data:{labels:<?=json_encode($methodLabels?:['No data'])?>,datasets:[{data:<?=json_encode($methodData?:[1])?>,backgroundColor:['#3B82F6','#8B5CF6','#22C55E'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});
 new Chart(document.getElementById('typeChart'),{type:'doughnut',data:{labels:['Entry','Exit'],datasets:[{data:[<?=(int)$typeMap['Entry']?>,<?=(int)$typeMap['Exit']?>],backgroundColor:['#22C55E','#F59E0B'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
