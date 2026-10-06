<?php
require_once __DIR__ . '/../database/config.php';
require_admin();

$pageTitle = 'Students';
$pageHeading = 'Students';
$pageSubheading = 'Manage registered student profiles and verification data';
$activePage = 'students';
$csrf = generate_csrf_token();
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sg-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <main class="sg-main">
        <div class="sg-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                
                <button class="btn btn-sg-primary" data-bs-toggle="modal" data-bs-target="#studentModal" onclick="openAddStudent()">
                    <i class="bi bi-plus-lg me-1"></i>Add Students
                </button>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-3">
                <div class="input-group" style="max-width:320px;">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" id="searchStudent" class="form-control" placeholder="Search Students.....">
                </div>
                <select id="filterGrade" class="form-select" style="max-width:180px;">
                    <option value="">All Grades</option>
                </select>
                <select id="filterStatus" class="form-select" style="max-width:160px;">
                    <option value="">All Status</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>

            <div class="table-responsive">
                <table class="table table-sg align-middle">
                    <thead>
                        <tr>
                            <th>Photo</th><th>Student ID</th><th>Name</th><th>Grade</th>
                            <th>Contact No.</th><th>Status</th><th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="studentsTableBody">
                        <tr><td colspan="7" class="text-center text-muted py-4">Loading students...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted" id="studentsSummary">Showing 0 entries</small>
                <nav><ul class="pagination pagination-sm mb-0" id="studentsPagination"></ul></nav>
            </div>
        </div>
    </main>
</div>

<!-- Add / Edit Student Modal -->
<div class="modal fade" id="studentModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content rounded-sg">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="studentModalTitle">Add New Student</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="studentForm">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="id" id="studentDbId">
          <div class="row g-3">
            <div class="col-md-7">
              <h6 class="fw-bold mb-3">Personal Information</h6>
              <div class="mb-3">
                <label class="form-label">Student ID</label>
                <input type="text" name="student_id" id="studentIdInput" class="form-control" placeholder="Enter student ID" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Full Name</label>
                <input type="text" name="fullname" class="form-control" placeholder="Enter full name" required>
              </div>
              <div class="row">
                <div class="col-6 mb-3">
                  <label class="form-label">Grade</label>
                  <select name="grade" class="form-select" required>
                    <option value="">Select grade</option>
                    <?php for ($g = 7; $g <= 12; $g++): ?>
                        <option value="Grade <?= $g ?>">Grade <?= $g ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
                <div class="col-6 mb-3">
                  <label class="form-label">Section</label>
                  <input type="text" name="section" class="form-control" placeholder="e.g. A">
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">Contact Number</label>
                <input type="text" name="contact_number" class="form-control" placeholder="09xxxxxxxxx"
                       pattern="^(09\d{9}|\+639\d{9}|639\d{9})$" maxlength="13" required>
                <small class="text-muted">Format: 09XXXXXXXXX (11 digits). +639XXXXXXXXX is also accepted and will be converted automatically.</small>
              </div>
              <div class="mb-3">
                <label class="form-label">Guardian Name</label>
                <input type="text" name="guardian_name" class="form-control" placeholder="Parent / Guardian full name">
              </div>
              <div class="mb-3">
                <label class="form-label">Email (Optional)</label>
                <input type="email" name="email" class="form-control" placeholder="Enter email">
              </div>
              <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                  <option value="Active">Active</option>
                  <option value="Inactive">Inactive</option>
                </select>
              </div>
            </div>

            <div class="col-md-5 text-center">
              <h6 class="fw-bold mb-3">Student Profile Photo</h6>
              <div class="rounded-sg mb-2 d-flex align-items-center justify-content-center bg-light" style="height:160px; overflow:hidden;">
                <img id="profilePhotoPreview" class="w-100 h-100 d-none" style="object-fit:cover;" alt="Student profile photo">
                <i id="profilePhotoPlaceholder" class="bi bi-person-badge text-secondary" style="font-size:4rem;"></i>
              </div>
              <input type="file" name="profile_photo" id="profilePhotoInput" class="form-control form-control-sm mb-1" accept="image/jpeg,image/png,image/webp">
              <p class="small text-muted mb-4">Formal photo (JPG, PNG or WEBP, max 5 MB) shown on the student profile and after verification. <strong>Not</strong> used for face recognition. Leave empty to keep the current photo.</p>

              <h6 class="fw-bold mb-3">Capture Face</h6>
              <div id="captureBox" class="rounded-sg mb-2 d-flex align-items-center justify-content-center bg-light" style="height:220px; overflow:hidden;">
                <video id="video" autoplay playsinline class="w-100 h-100 d-none" style="object-fit:cover;"></video>
                <img id="capturedPhoto" class="w-100 h-100 d-none" style="object-fit:cover;">
                <i id="capturePlaceholder" class="bi bi-person-circle text-secondary" style="font-size:5rem;"></i>
              </div>
              <canvas id="captureCanvas" class="d-none"></canvas>
              <div class="d-grid gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="startCameraBtn"><i class="bi bi-camera-fill me-1"></i>Open Camera</button>
                <button type="button" class="btn btn-success btn-sm d-none" id="captureBtn">Capture Photo</button>
                <button type="button" class="btn btn-link btn-sm d-none" id="retakeBtn">Retake</button>
              </div>
              <p class="small text-muted mt-2 mb-0">Used only as <strong>backup facial recognition</strong> if the barcode fails to scan. Capturing does not change the profile photo.</p>
              <p class="small text-success mt-1 mb-0 d-none" id="faceOnFileNote"><i class="bi bi-check-circle-fill me-1"></i>Face data on file &mdash; capture again only to replace it.</p>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sg-primary">Save Student</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/vendor/face-api/face-api.min.js"></script>
<script>
    window.SG_APP_URL = <?= json_encode(APP_URL) ?>;
</script>
<script src="<?= APP_URL ?>/assets/js/students.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
