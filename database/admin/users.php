<?php
require_once __DIR__ . '/../database/config.php';
require_admin(); // Only Administrators can manage users

$pageTitle = 'Users';
$pageHeading = 'Users';
$pageSubheading = 'Manage authorized Smart Gateway system accounts';
$activePage = 'users';
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
                
                <button class="btn btn-sg-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openAddUser()">
                    <i class="bi bi-plus-lg me-1"></i>Add User
                </button>
            </div>

            <div class="input-group mb-3" style="max-width:280px;">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" id="searchUser" class="form-control" placeholder="Search user....">
            </div>

            <div class="table-responsive">
                <table class="table table-sg align-middle">
                    <thead><tr><th>Username</th><th>Full name</th><th>Role</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody id="usersTableBody">
                        <tr><td colspan="5" class="text-center text-muted py-4">Loading users...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted" id="usersSummary">Showing 0 entries</small>
                <nav><ul class="pagination pagination-sm mb-0" id="usersPagination"></ul></nav>
            </div>
        </div>
    </main>
</div>

<!-- Add / Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content rounded-sg">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="userModalTitle">Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="userForm">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="id" id="userDbId">
          <div class="mb-3">
              <label class="form-label">Username</label>
              <input type="text" name="username" id="usernameInput" class="form-control" required>
          </div>
          <div class="mb-3">
              <label class="form-label">Full Name</label>
              <input type="text" name="fullname" class="form-control" required>
          </div>
          <div class="mb-3">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control">
          </div>
          <div class="mb-3">
              <label class="form-label">Contact Number</label>
              <input type="text" name="contact_number" class="form-control">
          </div>
          <div class="row">
            <div class="col-6 mb-3">
                <label class="form-label">Role</label>
                <select name="role" class="form-select">
                    <option value="Staff">Staff</option>
                    <option value="Administrator">Administrator</option>
                </select>
            </div>
            <div class="col-6 mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
          </div>
          <div class="mb-3" id="passwordGroup">
              <label class="form-label">Password <span id="passwordHint" class="text-muted small"></span></label>
              <input type="password" name="password" class="form-control" placeholder="Enter password" minlength="6" autocomplete="new-password">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sg-primary">Save User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/js/users.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
