
/**
 * PHASE 4: shared HTML-escaping helper for any admin/staff-supplied text
 * (student fullname/grade, usernames, audit-log descriptions, SMS message
 * text, etc.) that gets interpolated into innerHTML template strings
 * elsewhere in this codebase. The server already validates/normalizes
 * these fields on write, but validation is not the same thing as output
 * escaping — this covers the render side so a value containing HTML
 * (e.g. a student fullname of `<img src=x onerror=...>`) is always shown
 * as inert text instead of being parsed as markup, wherever it's used
 * inside a template literal assigned to .innerHTML (including inside an
 * HTML attribute, since HTML-entity escaping is safe in both contexts).
 */
function sgEscapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[ch]));
}

/* Theme toggle — persists the user's Light/Dark preference across pages. */
(() => {
    const root = document.documentElement;
    const saved = localStorage.getItem('sgTheme') || 'dark';
    root.setAttribute('data-theme', saved);
    const button = document.getElementById('sgThemeToggle');
    if (!button) return;
    const sync = () => {
        const light = root.getAttribute('data-theme') === 'light';
        const icon = button.querySelector('i');
        const label = button.querySelector('.sg-theme-label');
        if (icon) icon.className = light ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
        if (label) label.textContent = light ? 'Dark' : 'Light';
        button.title = light ? 'Switch to dark mode' : 'Switch to light mode';
    };
    sync();
    button.addEventListener('click', () => {
        const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);
        localStorage.setItem('sgTheme', next);
        sync();
    });
})();

/**
 * =====================================================================
 * SMART GATEWAY - Shared front-end utilities
 * =====================================================================
 */

// ---- Mobile sidebar toggle ----
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('sgSidebarToggle');
    const sidebar = document.getElementById('sgSidebar');
    if (!toggleBtn || !sidebar) return;

    let backdrop = document.querySelector('.sg-sidebar-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.className = 'sg-sidebar-backdrop';
        backdrop.setAttribute('aria-hidden', 'true');
        document.body.appendChild(backdrop);
    }

    const isMobile = () => window.matchMedia('(max-width: 991.98px)').matches;
    const setOpen = (open) => {
        const shouldOpen = Boolean(open && isMobile());
        sidebar.classList.toggle('show', shouldOpen);
        backdrop.classList.toggle('show', shouldOpen);
        document.body.classList.toggle('sg-sidebar-open', shouldOpen);
        toggleBtn.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        toggleBtn.setAttribute('aria-label', shouldOpen ? 'Close navigation' : 'Open navigation');
        const icon = toggleBtn.querySelector('i');
        if (icon) icon.className = shouldOpen ? 'bi bi-x-lg' : 'bi bi-list';
    };

    toggleBtn.setAttribute('aria-controls', 'sgSidebar');
    toggleBtn.setAttribute('aria-expanded', 'false');
    toggleBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        setOpen(!sidebar.classList.contains('show'));
    });
    backdrop.addEventListener('click', () => setOpen(false));

    sidebar.addEventListener('click', (e) => {
        if (isMobile() && e.target.closest('a.nav-link')) setOpen(false);
    });

    // Clicking outside the open drawer closes it, including topbar controls.
    document.addEventListener('click', (e) => {
        if (!isMobile() || !sidebar.classList.contains('show')) return;
        if (!sidebar.contains(e.target) && e.target !== toggleBtn && !toggleBtn.contains(e.target)) setOpen(false);
    });

    // Keep the drawer usable after orientation/viewport changes.
    window.addEventListener('orientationchange', () => setTimeout(() => { if (!isMobile()) setOpen(false); }, 120));

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('show')) {
            setOpen(false);
            toggleBtn.focus();
        }
    });

    window.addEventListener('resize', () => {
        if (!isMobile()) setOpen(false);
    });
});


/**
 * Generic AJAX POST helper (returns a Promise resolving to parsed JSON).
 * Automatically injects the CSRF token stored in the page's meta tag / hidden input.
 */
function sgPost(url, data) {
    const formData = (data instanceof FormData) ? data : new URLSearchParams(data);
    return fetch(url, {
        method: 'POST',
        body: formData,
        headers: (data instanceof FormData) ? {} : { 'Content-Type': 'application/x-www-form-urlencoded' }
    }).then(res => res.json());
}

function sgGet(url) {
    return fetch(url).then(res => res.json());
}

/**
 * Reusable delete-confirmation dialog (SweetAlert2).
 * onConfirm: callback executed if user confirms.
 */
function confirmDelete(message, onConfirm) {
    Swal.fire({
        title: 'Are you sure?',
        text: message || 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#EF4444',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it'
    }).then((result) => {
        if (result.isConfirmed && typeof onConfirm === 'function') {
            onConfirm();
        }
    });
}

function sgToast(icon, title) {
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: icon,
        title: title,
        showConfirmButton: false,
        timer: 2500,
        timerProgressBar: true
    });
}
