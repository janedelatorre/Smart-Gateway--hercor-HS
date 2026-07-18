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
            <td>${r.recipient}</td>
            <td>${r.message}</td>
            <td>${statusBadge(r.status)}</td>
            <td>${r.sent_by}</td>
            <td>${r.status !== 'Match' ? `<button class="btn btn-sm btn-outline-primary" onclick="resendSms(${r.id})"><i class="bi bi-arrow-repeat"></i> Resend</button>` : '-'}</td>
        </tr>
    `).join('');
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
    let csv = 'Date/Time,Recipient,Message,Status,Sent By\n';
    lastSmsData.forEach(r => csv += `${r.sent_at},${r.recipient},"${r.message.replace(/"/g,'""')}",${r.status},${r.sent_by}\n`);
    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'sms_notifications.csv';
    link.click();
}

document.getElementById('searchSms').addEventListener('input', debounceSms(() => loadSms(1), 350));
document.getElementById('filterSmsStatus').addEventListener('change', () => loadSms(1));
function debounceSms(fn, delay) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), delay); }; }

document.addEventListener('DOMContentLoaded', () => loadSms(1));
