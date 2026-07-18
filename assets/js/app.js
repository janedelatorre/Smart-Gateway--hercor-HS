/**
 * =====================================================================
 * SMART GATEWAY - Shared front-end utilities
 * =====================================================================
 */

// ---- Mobile sidebar toggle ----
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('sgSidebarToggle');
    const sidebar = document.getElementById('sgSidebar');
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('show');
        });
        document.addEventListener('click', function (e) {
            if (window.innerWidth < 992 && sidebar.classList.contains('show') &&
                !sidebar.contains(e.target) && e.target !== toggleBtn) {
                sidebar.classList.remove('show');
            }
        });
    }
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
        confirmButtonColor: '#DC3545',
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
