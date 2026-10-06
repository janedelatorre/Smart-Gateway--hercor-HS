/**
 * =====================================================================
 * SMS NOTIFICATIONS MODULE - front-end logic
 * =====================================================================
 */
const API_SMS = '../api/sms.php';
const smsCsrf = document.getElementById('csrfToken').value;
let smsCurrentPage = 1;
let lastSmsData = [];

function loadSms(page = 1) {
    smsCurrentPage = page;
    const params = new URLSearchParams({
        action: 'list',
        page: page,
        search: document.getElementById('searchSms').value,
        status: document.getElementById('filterSmsStatus').value,
        sent_by_type: document.getElementById('filterSmsSentBy').value,
    });
    sgGet(`${API_SMS}?${params.toString()}`).then(res => {
        if (!res.success) { sgToast('error', 'Failed to load SMS logs'); return; }
        lastSmsData = res.data;
        renderSmsTable(res.data);
        renderSmsPagination(res.total, res.page, res.per_page);
        document.getElementById('smsSummary').textContent =
            `Showing ${res.data.length ? ((res.page - 1) * res.per_page + 1) : 0} to ${(res.page - 1) * res.per_page + res.data.length} of ${res.total} entries`;
    });
}

function statusBadge(status) {
    if (status === 'Match') return '<span class="badge-match">Sent</span>';
    if (status === 'Denied') return '<span class="badge-denied">Failed</span>';
    if (status === 'Queued') return '<span class="badge-pending"><i class="bi bi-arrow-repeat"></i> Queued</span>';
    return '<span class="badge-pending">Pending</span>';
}

function renderSmsTable(rows) {
    const tbody = document.getElementById('smsTableBody');
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">No SMS records found.</td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map(r => `
        <tr>
            <td>${new Date(r.sent_at.replace(' ', 'T')).toLocaleString()}</td>
            <td>${sgEscapeHtml(r.recipient)}</td>
            <td>${sgEscapeHtml(r.message)}</td>
            <td>${statusBadge(r.status)}</td>
            <td><span class="sg-sms-source source-${String(r.sent_by_type || 'System').toLowerCase()}">${sgEscapeHtml(r.sent_by_type || 'System')}</span><small class="d-block text-muted">${sgEscapeHtml(r.sent_by || '')}</small></td>
            <td>
                <button class="btn btn-sm btn-outline-secondary" onclick="showSmsDetail(${r.id})"><i class="bi bi-info-circle"></i></button>
                ${r.status !== 'Match' ? `<button class="btn btn-sm btn-outline-primary" onclick="resendSms(${r.id})"><i class="bi bi-arrow-repeat"></i> Resend</button>` : ''}
            </td>
        </tr>
    `).join('');
}

function showSmsDetail(id) {
    const r = lastSmsData.find(x => x.id === id);
    if (!r) return;
    document.getElementById('smsDetailRecipient').textContent = r.recipient;
    document.getElementById('smsDetailStatus').innerHTML = statusBadge(r.status);
    document.getElementById('smsDetailMessage').textContent = r.message;
    document.getElementById('smsDetailSentBy').textContent = `${r.sent_by_type || 'System'} (${r.sent_by || '-'})`;
    document.getElementById('smsDetailSentAt').textContent = new Date(r.sent_at.replace(' ', 'T')).toLocaleString();
    document.getElementById('smsDetailResponse').textContent = r.provider_response || '-';
    const hasMsgId = !!r.provider_message_id;
    document.getElementById('smsDetailMsgIdLabel').style.display = hasMsgId ? '' : 'none';
    document.getElementById('smsDetailMsgId').style.display = hasMsgId ? '' : 'none';
    document.getElementById('smsDetailMsgId').textContent = r.provider_message_id || '';

    const isQueue = r.status === 'Queued' || (r.retry_count && r.retry_count > 0);
    document.getElementById('smsDetailRetryLabel').style.display = isQueue ? '' : 'none';
    document.getElementById('smsDetailRetryCount').style.display = isQueue ? '' : 'none';
    document.getElementById('smsDetailRetryCount').textContent = r.retry_count ?? 0;
    document.getElementById('smsDetailNextLabel').style.display = r.status === 'Queued' ? '' : 'none';
    document.getElementById('smsDetailNextRetry').style.display = r.status === 'Queued' ? '' : 'none';
    document.getElementById('smsDetailNextRetry').textContent = r.next_retry_at ? new Date(r.next_retry_at.replace(' ', 'T')).toLocaleString() : '-';

    new bootstrap.Modal(document.getElementById('smsDetailModal')).show();
}

function renderSmsPagination(total, page, perPage) {
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const ul = document.getElementById('smsPagination');
    let html = '';
    for (let i = 1; i <= totalPages; i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" onclick="loadSms(${i});return false;">${i}</a></li>`;
    }
    ul.innerHTML = html;
}

function resendSms(id) {
    sgPost(API_SMS, { action: 'resend', id, csrf_token: smsCsrf }).then(res => {
        sgToast(res.success ? 'success' : 'error', res.message);
        loadSms(smsCurrentPage);
    });
}

function exportSms() {
    if (!lastSmsData.length) { sgToast('info', 'No data to export.'); return; }
    let csv = 'Date/Time,Recipient,Message,Status,Retry Count,Next Retry,Sent By Type,Sent By\n';
    lastSmsData.forEach(r => csv += `${r.sent_at},${r.recipient},"${r.message.replace(/"/g,'""')}",${r.status},${r.retry_count ?? 0},${r.next_retry_at || ''},${r.sent_by_type || 'System'},${r.sent_by}\n`);
    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'sms_notifications.csv';
    link.click();
}

document.getElementById('searchSms').addEventListener('input', debounceSms(() => loadSms(1), 350));
document.getElementById('filterSmsStatus').addEventListener('change', () => loadSms(1));
document.getElementById('filterSmsSentBy').addEventListener('change', () => loadSms(1));
function debounceSms(fn, delay) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), delay); }; }

document.addEventListener('DOMContentLoaded', () => loadSms(1));
