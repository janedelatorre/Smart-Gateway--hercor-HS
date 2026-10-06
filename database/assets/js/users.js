/**
 * =====================================================================
 * USERS MODULE - front-end logic
 * =====================================================================
 */
const API_USER = '../api/user.php';
let usersCurrentPage = 1;

function loadUsers(page = 1) {
    usersCurrentPage = page;
    const search = document.getElementById('searchUser').value;
    sgGet(`${API_USER}?action=list&page=${page}&search=${encodeURIComponent(search)}`).then(res => {
        if (!res.success) { sgToast('error', 'Failed to load users'); return; }
        renderUsersTable(res.data);
        renderUsersPagination(res.total, res.page, res.per_page);
        document.getElementById('usersSummary').textContent =
            `Showing ${res.data.length ? ((res.page - 1) * res.per_page + 1) : 0} to ${(res.page - 1) * res.per_page + res.data.length} of ${res.total} entries`;
    });
}

function renderUsersTable(users) {
    const tbody = document.getElementById('usersTableBody');
    if (!users.length) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4">No users found.</td></tr>`;
        return;
    }
    tbody.innerHTML = users.map(u => `
        <tr>
            <td>${sgEscapeHtml(u.username)}</td>
            <td>${sgEscapeHtml(u.fullname)}</td>
            <td>${sgEscapeHtml(u.role)}</td>
            <td>${u.status === 'Active' ? '<span class="badge-match">Active</span>' : '<span class="badge-denied">Inactive</span>'}</td>
            <td>
                <button class="btn btn-sm sg-action-edit" onclick='openEditUser(${sgEscapeHtml(JSON.stringify(u))})'><i class="bi bi-pencil-fill text-primary"></i></button>
                <button class="btn btn-sm sg-action-delete" onclick="deleteUser(${u.id})"><i class="bi bi-trash-fill text-danger"></i></button>
            </td>
        </tr>
    `).join('');
}

function renderUsersPagination(total, page, perPage) {
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const ul = document.getElementById('usersPagination');
    let html = '';
    for (let i = 1; i <= totalPages; i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" onclick="loadUsers(${i});return false;">${i}</a></li>`;
    }
    ul.innerHTML = html;
}

document.getElementById('searchUser').addEventListener('input', debounceUsers(() => loadUsers(1), 350));
function debounceUsers(fn, delay) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), delay); }; }

function openAddUser() {
    document.getElementById('userModalTitle').textContent = 'Add User';
    document.getElementById('userForm').reset();
    document.getElementById('userDbId').value = '';
    document.getElementById('usernameInput').disabled = false;
    document.getElementById('passwordHint').textContent = '(minimum 6 characters)';
    document.querySelector('#userForm [name=password]').required = true;
}

function openEditUser(user) {
    document.getElementById('userModalTitle').textContent = 'Edit User';
    const form = document.getElementById('userForm');
    form.reset();
    document.getElementById('userDbId').value = user.id;
    form.username.value = user.username;
    form.fullname.value = user.fullname;
    form.email.value = user.email || '';
    form.contact_number.value = user.contact_number || '';
    form.role.value = user.role;
    form.status.value = user.status;
    document.getElementById('passwordHint').textContent = '(minimum 6 characters)';
    form.password.required = false;
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

document.getElementById('userForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', document.getElementById('userDbId').value ? 'update' : 'create');
    sgPost(API_USER, formData).then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('userModal')).hide();
            sgToast('success', res.message);
            loadUsers(usersCurrentPage);
        } else {
            sgToast('error', res.message);
        }
    });
});

function deleteUser(id) {
    confirmDelete('This user account will be permanently removed.', () => {
        sgPost(API_USER, { action: 'delete', id, csrf_token: document.querySelector('#userForm [name=csrf_token]').value })
            .then(res => {
                sgToast(res.success ? 'success' : 'error', res.message);
                if (res.success) loadUsers(usersCurrentPage);
            });
    });
}

document.addEventListener('DOMContentLoaded', () => loadUsers(1));
