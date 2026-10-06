/**
 * =====================================================================
 * AUDIT LOGS MODULE - front-end logic
 * =====================================================================
 */
const API_AUDIT = '../api/audit_logs.php';
let auditCurrentPage = 1;
let lastAuditData = [];
let auditFiltersPopulated = false;

const AUDIT_BADGE_MAP = {
    'Login Successful': 'badge-match',
    'User Created': 'badge-match',
    'Student Created': 'badge-match',
    'User Updated': 'badge-match',
    'Student Updated': 'badge-match',
    'Password Change': 'badge-match',
    'Logout': 'badge-match',
    'Login Failed': 'badge-denied',
    'Account Locked': 'badge-denied',
    'User Deleted': 'badge-denied',
    'Student Deleted': 'badge-denied',
    'Verification Throttled': 'badge-denied',
    'Session Expired': 'badge-pending',
    'Password Reset Requested': 'badge-pending',
};

function auditBadge(action) {
    const cls = AUDIT_BADGE_MAP[action] || 'badge-pending';
    return `<span class="${cls}">${action}</span>`;
}

function loadAuditLogs(page = 1) {
    auditCurrentPage = page;
    const params = new URLSearchParams({
        action: 'list',
        page: page,
        search: document.getElementById('searchAudit').value,
        date_from: document.getElementById('filterAuditDateFrom').value,
        date_to: document.getElementById('filterAuditDateTo').value,
        event_type: document.getElementById('filterAuditEventType').value,
        user: document.getElementById('filterAuditUser').value,
    });

    sgGet(`${API_AUDIT}?${params.toString()}`).then(res => {
        if (!res.success) { sgToast('error', res.message || 'Failed to load audit logs'); return; }
        lastAuditData = res.data;
        renderAuditTable(res.data);
        renderAuditPagination(res.total, res.page, res.per_page);
        populateAuditFilters(res.event_types, res.users);
        document.getElementById('auditSummary').textContent =
            `Showing ${res.data.length ? ((res.page - 1) * res.per_page + 1) : 0} to ${(res.page - 1) * res.per_page + res.data.length} of ${res.total} entries`;
    });
}

function populateAuditFilters(eventTypes, users) {
    if (auditFiltersPopulated) return; // preserve the user's current selection on subsequent loads
    const typeSelect = document.getElementById('filterAuditEventType');
    const userSelect = document.getElementById('filterAuditUser');

    (eventTypes || []).forEach(t => {
        const opt = document.createElement('option');
        opt.value = t; opt.textContent = t;
        typeSelect.appendChild(opt);
    });
    (users || []).forEach(u => {
        const opt = document.createElement('option');
        opt.value = u; opt.textContent = u;
        userSelect.appendChild(opt);
    });
    auditFiltersPopulated = true;
}

function renderAuditTable(rows) {
    const tbody = document.getElementById('auditTableBody');
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-4">No audit log entries found.</td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map(r => {
        const dt = new Date(r.created_at.replace(' ', 'T'));
        return `<tr>
            <td>${dt.toLocaleString()}</td>
            <td>${sgEscapeHtml(r.username ?? 'System')}</td>
            <td>${auditBadge(r.action)}</td>
            <td>${sgEscapeHtml(r.description ?? '-')}</td>
            
        </tr>`;
    }).join('');
}

function renderAuditPagination(total, page, perPage) {
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const ul = document.getElementById('auditPagination');
    let html = '';
    for (let i = 1; i <= totalPages; i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" onclick="loadAuditLogs(${i});return false;">${i}</a></li>`;
    }
    ul.innerHTML = html;
}

['searchAudit', 'filterAuditDateFrom', 'filterAuditDateTo', 'filterAuditEventType', 'filterAuditUser'].forEach(id => {
    document.getElementById(id).addEventListener('input', debounceAudit(() => loadAuditLogs(1), 350));
    document.getElementById(id).addEventListener('change', () => loadAuditLogs(1));
});
function debounceAudit(fn, delay) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), delay); }; }

// ---- Export CSV ----
function exportAuditLogs() {
    if (!lastAuditData.length) { sgToast('info', 'No data to export.'); return; }
    let csv = 'Date/Time,User,Event,Description\n';
    lastAuditData.forEach(r => {
        const dt = new Date(r.created_at.replace(' ', 'T'));
        const desc = (r.description ?? '').replace(/"/g, '""');
      // csv += `${dt.toLocaleString()},${r.username ?? 'System'},${r.action},"${desc}",${r.ip_address ?? ''}\n`;//
     csv += `${dt.toLocaleString()},${r.username ?? 'System'},${r.action},"${desc}"\n`;
    });
    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'audit_logs.csv';
    link.click();
}

document.addEventListener('DOMContentLoaded', () => loadAuditLogs(1));
