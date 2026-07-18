<?php
require_once __DIR__ . '/../database/config.php';
require_login();

$pageTitle = 'Entry Logs';
$pageHeading = 'Entry Logs';
$activePage = 'entry_logs';
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sg-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <main class="sg-main">
        <div class="sg-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="fw-bold mb-0">Entry Logs</h5>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" onclick="exportLogs('pdf')"><i class="bi bi-file-earmark-pdf me-1"></i>Export PDF</button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="exportLogs('excel')"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-md-3">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="text" id="searchLogs" class="form-control" placeholder="Search name or student ID...">
                    </div>
                </div>
                <div class="col-md-2">
                    <input type="date" id="filterDateFrom" class="form-control" title="From date">
                </div>
                <div class="col-md-2">
                    <input type="date" id="filterDateTo" class="form-control" title="To date">
                </div>
                <div class="col-md-2">
                    <select id="filterStatusLog" class="form-select">
                        <option value="">All Status</option>
                        <option value="Match">Match</option>
                        <option value="Denied">Denied</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select id="filterMethodLog" class="form-select">
                        <option value="">All Methods</option>
                        <option value="Barcode">Barcode</option>
                        <option value="Facial Recognition">Facial Recognition</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sg align-middle">
                    <thead>
                        <tr><th>Date</th><th>Time In</th><th>Student ID</th><th>Name</th><th>Grade</th><th>Method</th><th>Status</th><th>Verified By</th></tr>
                    </thead>
                    <tbody id="logsTableBody">
                        <tr><td colspan="8" class="text-center text-muted py-4">Loading entry logs...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted" id="logsSummary">Showing 0 entries</small>
                <nav><ul class="pagination pagination-sm mb-0" id="logsPagination"></ul></nav>
            </div>
        </div>
    </main>
</div>

<script src="<?= APP_URL ?>/assets/js/entry_logs.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
