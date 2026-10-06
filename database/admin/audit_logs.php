<?php
require_once __DIR__ . '/../database/config.php';
require_login(); // Read-only trail — both Administrator and Staff may view it; only Administrators can reach Users/Settings which is where the sensitive write actions live.

$pageTitle = 'Audit Logs';
$pageHeading = 'Audit Logs';
$pageSubheading = 'Review security and system activity records';
$activePage = 'audit_logs';
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sg-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <main class="sg-main">
        <div class="sg-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" onclick="exportAuditLogs()"><i class="bi bi-file-earmark-excel me-1"></i>Export CSV</button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
                </div>
            </div>

            <div class="row g-2 mb-3 sg-no-print">
                <div class="col-md-3">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="text" id="searchAudit" class="form-control" placeholder="Search description, action, user, IP...">
                    </div>
                </div>
                <div class="col-md-2">
                    <input type="date" id="filterAuditDateFrom" class="form-control" title="From date">
                </div>
                <div class="col-md-2">
                    <input type="date" id="filterAuditDateTo" class="form-control" title="To date">
                </div>
                <div class="col-md-2">
                    <select id="filterAuditEventType" class="form-select" title="Event type">
                        <option value="">All Event Types</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select id="filterAuditUser" class="form-select" title="User">
                        <option value="">All Users</option>
                    </select>
                </div>
            </div>



            <div class="table-responsive">
                <table class="table table-sg align-middle">
                    <thead>
                        <!-- removing ip address -->
                        <tr><th>Date/Time</th><th>User</th><th>Event</th><th>Description</th></tr>
                    </thead>
                    <tbody id="auditTableBody">
                        <tr><td colspan="4" class="text-center text-muted py-4">Loading audit logs...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted" id="auditSummary">Showing 0 entries</small>
                <nav><ul class="pagination pagination-sm mb-0" id="auditPagination"></ul></nav>
            </div>
        </div>
    </main>
</div>

<script src="<?= APP_URL ?>/assets/js/audit_logs.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
