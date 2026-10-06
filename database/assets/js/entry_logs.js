/**
 * =====================================================================
 * ENTRY LOGS MODULE - front-end logic
 * =====================================================================
 */
const API_LOGS = '../api/entry_logs.php';
let logsCurrentPage = 1;
let lastLogsData = [];

function loadLogs(page = 1) {
    logsCurrentPage = page;
    const params = new URLSearchParams({
        action: 'list',
        page: page,
        search: document.getElementById('searchLogs').value,
        date_from: document.getElementById('filterDateFrom').value,
        date_to: document.getElementById('filterDateTo').value,
        status: document.getElementById('filterStatusLog').value,
        method: document.getElementById('filterMethodLog').value,
        transaction_type: document.getElementById('filterTypeLog').value,
        grade: document.getElementById('filterGradeLog').value,
    });

    sgGet(`${API_LOGS}?${params.toString()}`).then(res => {
        if (!res.success) { sgToast('error', 'Failed to load entry logs'); return; }
        lastLogsData = res.data;
        renderLogsTable(res.data);
        renderLogsPagination(res.total, res.page, res.per_page);
        document.getElementById('logsSummary').textContent =
            `Showing ${res.data.length ? ((res.page - 1) * res.per_page + 1) : 0} to ${(res.page - 1) * res.per_page + res.data.length} of ${res.total} entries`;
    });
}

function renderLogsTable(logs) {
    const tbody = document.getElementById('logsTableBody');
    if (!logs.length) {
        tbody.innerHTML = `<tr><td colspan="9" class="text-center text-muted py-4">No entry logs found.</td></tr>`;
        return;
    }
    tbody.innerHTML = logs.map(l => {
        const dt = new Date(l.time_in.replace(' ', 'T'));
        return `<tr>
            <td>${dt.toLocaleDateString()}</td>
            <td>${dt.toLocaleTimeString()}</td>
            <td>${sgEscapeHtml(l.student_id ?? '-')}</td>
            <td>${sgEscapeHtml(l.fullname ?? 'Unknown')}</td>
            <td>${sgEscapeHtml(l.grade ?? '-')}</td>
            <td>${l.verification_method}</td>
            <td>${l.transaction_type ?? '-'}</td>
            <td>${l.status === 'Match' ? '<span class="badge-match">Match</span>' : `<span class="badge-denied">Denied</span>`}</td>
            <td>${sgEscapeHtml(l.verified_by ?? '-')}</td>
        </tr>`;
    }).join('');
}

function renderLogsPagination(total, page, perPage) {
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const ul = document.getElementById('logsPagination');
    let html = '';
    for (let i = 1; i <= totalPages; i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" onclick="loadLogs(${i});return false;">${i}</a></li>`;
    }
    ul.innerHTML = html;
}

['searchLogs', 'filterDateFrom', 'filterDateTo', 'filterStatusLog', 'filterMethodLog', 'filterTypeLog', 'filterGradeLog'].forEach(id => {
    document.getElementById(id).addEventListener('input', debounceLogs(() => loadLogs(1), 350));
    document.getElementById(id).addEventListener('change', () => loadLogs(1));
});
function debounceLogs(fn, delay) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), delay); }; }

// ---- Export helpers ----
function exportLogs(type) {
    if (!lastLogsData.length) { sgToast('info', 'No data to export.'); return; }
    if (type === 'excel') {
        let csv = 'Date,Time In,Student ID,Name,Grade,Method,Type,Status,Verified By\n';
        lastLogsData.forEach(l => {
            const dt = new Date(l.time_in.replace(' ', 'T'));
            csv += `${dt.toLocaleDateString()},${dt.toLocaleTimeString()},${l.student_id ?? ''},${l.fullname ?? ''},${l.grade ?? ''},${l.verification_method},${l.transaction_type ?? ''},${l.status},${l.verified_by ?? ''}\n`;
        });
        const blob = new Blob([csv], { type: 'text/csv' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'entry_logs.csv';
        link.click();
    } else {
        sgToast('info', 'Use your browser\'s Print dialog and choose "Save as PDF".');
        window.print();
    }
}

document.addEventListener('DOMContentLoaded', () => loadLogs(1));
