/**
 * =====================================================================
 * NOTIFICATION CENTER - front-end logic
 * Loaded on every admin page via includes/footer.php. Polls the bell
 * dropdown for unread notifications and handles mark-read actions.
 * =====================================================================
 */
const API_NOTIFICATIONS = '../api/notifications.php';
const NOTIF_POLL_MS = 30000;

const NOTIF_ICONS = {
    success: 'bi-check-circle-fill text-success',
    warning: 'bi-exclamation-triangle-fill text-warning',
    error: 'bi-x-circle-fill text-danger',
    info: 'bi-info-circle-fill text-info'
};

function sgNotifCsrf() {
    const el = document.getElementById('sgNavCsrfToken');
    return el ? el.value : '';
}

function sgNotifTimeAgo(dateStr) {
    const seconds = Math.floor((Date.now() - new Date(dateStr.replace(' ', 'T'))) / 1000);
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
    if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
    return Math.floor(seconds / 86400) + 'd ago';
}

function renderNotifications(notifications, unreadCount) {
    const badge = document.getElementById('sgNotifBadge');
    const list = document.getElementById('sgNotifList');
    if (!badge || !list) return;

    if (unreadCount > 0) {
        badge.textContent = unreadCount > 9 ? '9+' : unreadCount;
        badge.classList.remove('d-none');
    } else {
        badge.classList.add('d-none');
    }

    if (!notifications.length) {
        list.innerHTML = '<div class="text-center text-muted small py-4">No notifications yet.</div>';
        return;
    }

    list.innerHTML = notifications.map(n => {
        const iconClass = NOTIF_ICONS[n.type] || NOTIF_ICONS.info;
        const unreadClass = n.is_read == 0 ? 'bg-light' : '';
        return `
            <div class="d-flex gap-2 px-3 py-2 border-bottom sg-notif-item ${unreadClass}" data-id="${n.id}" style="cursor:pointer;">
                <i class="bi ${iconClass} mt-1"></i>
                <div class="flex-grow-1">
                    <div class="small fw-bold">${escapeNotifHtml(n.title)}</div>
                    ${n.message ? `<div class="small text-muted">${escapeNotifHtml(n.message)}</div>` : ''}
                    <div class="small text-muted">${sgNotifTimeAgo(n.created_at)}</div>
                </div>
            </div>
        `;
    }).join('');

    list.querySelectorAll('.sg-notif-item').forEach(item => {
        item.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            sgPost(API_NOTIFICATIONS, { action: 'mark_read', id, csrf_token: sgNotifCsrf() }).then(() => {
                this.classList.remove('bg-light');
                loadNotifications();
            });
        });
    });
}

function escapeNotifHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

function loadNotifications() {
    sgGet(API_NOTIFICATIONS + '?action=list').then(res => {
        if (res && res.success) {
            renderNotifications(res.notifications, res.unread_count);
        }
    }).catch(() => {});
}

// PHASE 3: drains the server-side SMS retry queue (Case A — server reachable,
// SMS provider/Internet was down when the message was first attempted).
// Reuses this existing poll instead of adding a second timer; a queued
// message otherwise only retries the next time a new scan happens to fire
// the opportunistic flush in api/sms.php sg_notify_entry().
const API_SMS_QUEUE = '../api/sms.php';
function flushSmsQueue() {
    sgPost(API_SMS_QUEUE, { action: 'flush_queue', csrf_token: sgNotifCsrf() }).catch(() => {});
}

document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('sgNotifBtn')) return;

    loadNotifications();
    setInterval(loadNotifications, NOTIF_POLL_MS);
    flushSmsQueue();
    setInterval(flushSmsQueue, NOTIF_POLL_MS);

    const markAllBtn = document.getElementById('sgNotifMarkAll');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            sgPost(API_NOTIFICATIONS, { action: 'mark_all_read', csrf_token: sgNotifCsrf() }).then(() => {
                loadNotifications();
            });
        });
    }
});
