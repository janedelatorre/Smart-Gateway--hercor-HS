<?php
require_once __DIR__ . '/../database/config.php';
require_login();

$pageTitle = 'SMS Notifications';
$pageHeading = 'SMS Notifications';
$pageSubheading = 'Monitor notification delivery, queue status, and retry activity';
$activePage = 'sms';
$csrf = generate_csrf_token();
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sg-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <main class="sg-main">
        <input type="hidden" id="csrfToken" value="<?= e($csrf) ?>">
        <div class="sg-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" onclick="exportSms()"><i class="bi bi-download me-1"></i>Export</button>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="text" id="searchSms" class="form-control" placeholder="Search recipient or message...">
                    </div>
                </div>
                <div class="col-md-3">
                    <select id="filterSmsStatus" class="form-select">
                        <option value="">All Status</option>
                        <option value="Match">Sent</option>
                        <option value="Denied">Failed</option>
                        <option value="Pending">Pending</option>
                        <option value="Queued">Queued (retrying)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select id="filterSmsSentBy" class="form-select" aria-label="Sent By">
                        <option value="">Sent By: All</option>
                        <option value="Kiosk">Kiosk</option>
                        <option value="Administrator">Administrator</option>
                        <option value="Staff">Staff</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sg align-middle">
                    <thead><tr><th>Date/Time</th><th>Recipient</th><th>Message</th><th>Status</th><th>Sent By</th><th>Action</th></tr></thead>
                    <tbody id="smsTableBody">
                        <tr><td colspan="6" class="text-center text-muted py-4">Loading SMS logs...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted" id="smsSummary">Showing 0 entries</small>
                <nav><ul class="pagination pagination-sm mb-0" id="smsPagination"></ul></nav>
            </div>
        </div>
    </main>
</div>

<!-- SMS detail modal: retry/queue info doesn't fit in the table without
     crowding it, so it lives here instead of adding more columns. -->
<div class="modal fade" id="smsDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">SMS Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-0">
          <dt class="col-4">Recipient</dt><dd class="col-8" id="smsDetailRecipient"></dd>
          <dt class="col-4">Status</dt><dd class="col-8" id="smsDetailStatus"></dd>
          <dt class="col-4">Message</dt><dd class="col-8" id="smsDetailMessage"></dd>
          <dt class="col-4">Sent By</dt><dd class="col-8" id="smsDetailSentBy"></dd>
          <dt class="col-4">Sent/Updated At</dt><dd class="col-8" id="smsDetailSentAt"></dd>
          <dt class="col-4" id="smsDetailRetryLabel">Retry Attempts</dt><dd class="col-8" id="smsDetailRetryCount"></dd>
          <dt class="col-4" id="smsDetailNextLabel">Next Retry</dt><dd class="col-8" id="smsDetailNextRetry"></dd>
          <dt class="col-4">Provider Response</dt><dd class="col-8" id="smsDetailResponse" style="word-break:break-word;"></dd>
          <dt class="col-4" id="smsDetailMsgIdLabel">Provider Message ID</dt><dd class="col-8" id="smsDetailMsgId"></dd>
        </dl>
      </div>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/js/sms.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
