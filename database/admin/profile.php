<?php
require_once __DIR__ . '/../database/config.php';
require_login();

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
if (!$user) { header('Location: ' . APP_URL . '/login.php'); exit; }

$message = '';
$csrf = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'profile') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid session token. Please refresh the page and try again.</div>';
    } else {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $contact = trim((string)($_POST['contact_number'] ?? ''));
        $fullname = trim($firstName . ' ' . $lastName);

        if ($firstName === '' || $lastName === '') {
            $message = '<div class="alert alert-danger">First Name and Last Name are required.</div>';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = '<div class="alert alert-danger">Please enter a valid email address.</div>';
        } elseif ($contact !== '' && !preg_match('/^[0-9+\-\s]{7,15}$/', $contact)) {
            $message = '<div class="alert alert-danger">Please enter a valid contact number.</div>';
        } else {
            $photoPath = $user['profile_picture'] ?? null;
            if (!empty($_FILES['profile_picture']['name'])) {
                if ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
                    $message = '<div class="alert alert-danger">The profile picture could not be uploaded.</div>';
                } else {
                    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['profile_picture']['tmp_name']);
                    if (!isset($allowed[$mime])) {
                        $message = '<div class="alert alert-danger">Profile picture must be JPG, PNG, or WEBP.</div>';
                    } elseif ($_FILES['profile_picture']['size'] > 2 * 1024 * 1024) {
                        $message = '<div class="alert alert-danger">Profile picture must be 2 MB or smaller.</div>';
                    } else {
                        $dir = __DIR__ . '/../uploads/profile';
                        if (!is_dir($dir)) @mkdir($dir, 0755, true);
                        $filename = 'user_' . (int)$user['id'] . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
                        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dir . '/' . $filename)) {
                            $photoPath = 'profile/' . $filename;
                        } else {
                            $message = '<div class="alert alert-danger">The profile picture could not be saved.</div>';
                        }
                    }
                }
            }

            if ($message === '') {
                $stmt = $pdo->prepare("UPDATE users SET fullname=?, first_name=?, last_name=?, email=?, contact_number=?, profile_picture=? WHERE id=?");
                $stmt->execute([$fullname, $firstName, $lastName, $email !== '' ? $email : null, $contact !== '' ? $contact : null, $photoPath, (int)$user['id']]);
                $_SESSION['fullname'] = $fullname;
                $_SESSION['profile_picture'] = $photoPath;
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();
                log_activity($pdo, 'Profile Updated', "User updated their profile");
                $message = '<div class="alert alert-success">Profile updated successfully.</div>';
            }
        }
    }
}

$firstName = $user['first_name'] ?? '';
$lastName = $user['last_name'] ?? '';
if ($firstName === '' && $lastName === '') {
    $parts = preg_split('/\s+/', trim((string)$user['fullname']), 2);
    $firstName = $parts[0] ?? '';
    $lastName = $parts[1] ?? '';
}
$photoUrl = !empty($user['profile_picture']) ? APP_URL . '/uploads/' . ltrim($user['profile_picture'], '/') : '';

$pageTitle = 'Update Profile';
$pageHeading = 'Update Profile';
$pageSubheading = 'Manage your account information and profile details';
$activePage = 'profile';
require_once __DIR__ . '/../includes/header.php';
?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sg-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>
    <main class="sg-main sg-profile-page">
        <div class="mb-3">
            <a href="<?= APP_URL ?>/admin/<?= (($_SESSION['role'] ?? '') === 'Administrator' ? 'settings.php' : 'staff_settings.php') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Account Settings</a>
        </div>
        <?= $message ?>
        <form method="POST" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="form" value="profile">
            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="sg-card text-center sg-profile-summary-card">
                        <h5 class="fw-bold mb-3">Update Profile</h5>
                        <div class="sg-profile-photo-wrap mx-auto mb-3">
                            <?php if ($photoUrl): ?><img src="<?= e($photoUrl) ?>" alt="Profile picture" id="profilePreview"><?php else: ?><div class="sg-profile-photo-placeholder" id="profilePreview"><i class="bi bi-person-fill"></i></div><?php endif; ?>
                        </div>
                        <label class="btn btn-outline-secondary btn-sm" for="profilePicture"><i class="bi bi-camera me-1"></i>Upload / Change Photo</label>
                        <input type="file" id="profilePicture" name="profile_picture" accept="image/jpeg,image/png,image/webp" class="d-none">
                        <p class="small text-muted mt-2 mb-0">JPG, PNG, or WEBP · max 2 MB</p>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="sg-card sg-profile-information-card">
                        <h5 class="fw-bold mb-3">Personal Information</h5>
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" value="<?= e($firstName) ?>" required></div>
                            <div class="col-md-6"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" value="<?= e($lastName) ?>" required></div>
                            <div class="col-md-6"><label class="form-label">Email Address</label><input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>"></div>
                            <div class="col-md-6"><label class="form-label">Contact Number</label><input type="text" name="contact_number" class="form-control" value="<?= e($user['contact_number'] ?? '') ?>"></div>
                        </div>
                    </div>
                    <div class="sg-card mt-3 sg-account-details-card">
                        <h5 class="fw-bold mb-3">Account Details</h5>
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Role / Designation</label><input type="text" class="form-control" value="<?= e($user['role']) ?>" readonly></div>
                            <div class="col-md-6"><label class="form-label">Staff ID</label><input type="text" class="form-control" value="<?= e($user['staff_id'] ?? '') ?>" readonly aria-readonly="true"><small class="text-muted d-block mt-1"><i class="bi bi-lock-fill me-1"></i>Staff ID cannot be edited.</small></div>
                            <div class="col-md-6"><label class="form-label">Username</label><input type="text" class="form-control" value="<?= e($user['username']) ?>" readonly></div>
                            <div class="col-md-6"><label class="form-label">Account Status</label><input type="text" class="form-control" value="<?= e($user['status']) ?>" readonly></div>
                        </div>
                        <div class="text-end mt-4"><button type="submit" class="btn btn-sg-primary"><i class="bi bi-person-check me-1"></i>Update Profile</button></div>
                    </div>
                </div>
            </div>
        </form>
    </main>
</div>
<script>
document.getElementById('profilePicture')?.addEventListener('change', function(){
    const file=this.files?.[0]; if(!file) return;
    const url=URL.createObjectURL(file); const old=document.getElementById('profilePreview');
    if(old.tagName==='IMG'){old.src=url;} else {const img=document.createElement('img'); img.id='profilePreview'; img.alt='Profile picture'; img.src=url; old.replaceWith(img);}
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
