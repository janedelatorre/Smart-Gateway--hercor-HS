<?php
/**
 * =====================================================================
 * STUDENT API
 * Actions: list, create, update, delete
 * =====================================================================
 */
require_once __DIR__ . '/../database/config.php';
header('Content-Type: application/json');

if (!require_login_api()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in again.']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'Administrator') {
    echo json_encode(['success' => false, 'message' => 'Access denied. Administrator role required.']);
    exit;
}

const SG_STUDENT_PHOTO_MAX_BYTES = 5 * 1024 * 1024; // same 5 MB ceiling this endpoint already used

/**
 * Validate an uploaded STUDENT PROFILE PHOTO (formal display photo — stored in
 * students.photo). This is unrelated to the facial-recognition descriptor
 * (students.face_encoding), which arrives separately as `face_descriptor`.
 * Returns ['ok' => true, 'ext' => 'jpg'|'png'|'webp'] or ['ok' => false, 'message' => ...].
 * Real MIME is detected server-side from the file content, never from the client's claim.
 */
function sg_validate_profile_photo(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'message' => 'Profile photo must be 5 MB or smaller.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'message' => 'The profile photo could not be uploaded.'];
    }
    if (($file['size'] ?? 0) > SG_STUDENT_PHOTO_MAX_BYTES) {
        return ['ok' => false, 'message' => 'Profile photo must be 5 MB or smaller.'];
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'message' => 'Profile photo must be a JPG, PNG, or WEBP image.'];
    }
    return ['ok' => true, 'ext' => $allowed[$mime]];
}

/** Save a validated profile photo under a random server-generated name; returns the filename stored in students.photo, or null. */
function sg_store_profile_photo(array $file, string $ext): ?string {
    $dir = __DIR__ . '/../uploads/student/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $filename = 'std_' . bin2hex(random_bytes(12)) . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], $dir . $filename) ? $filename : null;
}

$action = $_REQUEST['action'] ?? '';
$newPhotoFilename = null; // set once a new profile photo is written, so it can be cleaned up if the save then fails

try {
    switch ($action) {

        case 'list': {
            $page    = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = 10;
            $offset  = ($page - 1) * $perPage;
            $search  = trim($_GET['search'] ?? '');
            $grade   = trim($_GET['grade'] ?? '');
            $status  = trim($_GET['status'] ?? '');

            $where = [];
            $params = [];
            if ($search !== '') {
                $where[] = "(student_id LIKE ? OR fullname LIKE ?)";
                $params[] = "%$search%"; $params[] = "%$search%";
            }
            if ($grade !== '') { $where[] = "grade = ?"; $params[] = $grade; }
            if ($status !== '') { $where[] = "status = ?"; $params[] = $status; }
            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM students $whereSql");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT id, student_id, fullname, grade, section, contact_number, guardian_name, email, photo, status,
                                    (face_encoding IS NOT NULL AND face_encoding <> '') AS has_face
                                    FROM students $whereSql ORDER BY fullname ASC LIMIT $perPage OFFSET $offset");
            $stmt->execute($params);
            $data = $stmt->fetchAll();

            $grades = $pdo->query("SELECT DISTINCT grade FROM students ORDER BY grade")->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode(['success' => true, 'data' => $data, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'grades' => $grades]);
            break;
        }

        case 'create':
        case 'update': {
            if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'Invalid session token.']); exit;
            }

            $id             = (int) ($_POST['id'] ?? 0);
            $studentId      = trim($_POST['student_id'] ?? '');
            $fullname       = trim($_POST['fullname'] ?? '');
            $grade          = trim($_POST['grade'] ?? '');
            $section        = trim($_POST['section'] ?? '');
            $contact        = trim($_POST['contact_number'] ?? '');
            $guardian       = trim($_POST['guardian_name'] ?? '');
            $email          = trim($_POST['email'] ?? '');
            $status         = in_array($_POST['status'] ?? '', ['Active','Inactive']) ? $_POST['status'] : 'Active';
            $faceDescriptor = trim($_POST['face_descriptor'] ?? '');

            if ($studentId === '' || $fullname === '' || $grade === '' || $contact === '') {
                echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']); exit;
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Invalid email address format.']); exit;
            }

            // PHASE 3: canonical PH mobile format. Accepts 09XXXXXXXXX,
            // +639XXXXXXXXX, 639XXXXXXXXX as input; normalizes to
            // 09XXXXXXXXX before it ever reaches the students table, so
            // every number written FROM THIS POINT FORWARD is guaranteed
            // canonical. sg_normalize_ph_mobile() never guesses -- it
            // returns null for anything else (wrong length, wrong prefix,
            // letters, etc.) and this rejects the save rather than storing
            // something sg_send_sms() would just turn around and reject.
            $normalizedContact = sg_normalize_ph_mobile($contact);
            if ($normalizedContact === null) {
                echo json_encode(['success' => false, 'message' => 'Invalid contact number. Use 09XXXXXXXXX (11 digits) or +639XXXXXXXXX.']); exit;
            }
            $contact = $normalizedContact;

            // STUDENT PROFILE PHOTO (students.photo) — validated here, written only after the
            // ID/duplicate checks below pass. Independent of the face descriptor.
            $photoFile = null;
            $photoExt = null;
            if (!empty($_FILES['profile_photo']['name'])) {
                $check = sg_validate_profile_photo($_FILES['profile_photo']);
                if (!$check['ok']) {
                    echo json_encode(['success' => false, 'message' => $check['message']]); exit;
                }
                $photoFile = $_FILES['profile_photo'];
                $photoExt = $check['ext'];
            }

            if ($action === 'create') {
                $dupe = $pdo->prepare("SELECT id FROM students WHERE student_id = ?");
                $dupe->execute([$studentId]);
                if ($dupe->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Student ID already exists.']); exit;
                }

                $photoFilename = null;
                if ($photoFile) {
                    $photoFilename = $newPhotoFilename = sg_store_profile_photo($photoFile, $photoExt);
                    if (!$photoFilename) { echo json_encode(['success' => false, 'message' => 'The profile photo could not be saved.']); exit; }
                }

                $stmt = $pdo->prepare("INSERT INTO students (student_id, fullname, grade, section, contact_number, guardian_name, email, photo, face_encoding, status)
                                        VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$studentId, $fullname, $grade, $section, $contact, $guardian, $email, $photoFilename, $faceDescriptor ?: null, $status]);
                log_activity($pdo, 'Student Created', "Created student '{$fullname}' (ID: {$studentId})");
                echo json_encode(['success' => true, 'message' => 'Student added successfully.']);
            } else {
                if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid student record.']); exit; }

                $dupe = $pdo->prepare("SELECT id FROM students WHERE student_id = ? AND id != ?");
                $dupe->execute([$studentId, $id]);
                if ($dupe->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Student ID already used by another record.']); exit;
                }

                $existing = $pdo->prepare("SELECT photo FROM students WHERE id = ?");
                $existing->execute([$id]);
                $oldPhoto = $existing->fetchColumn();

                $photoFilename = null;
                if ($photoFile) {
                    $photoFilename = $newPhotoFilename = sg_store_profile_photo($photoFile, $photoExt);
                    if (!$photoFilename) { echo json_encode(['success' => false, 'message' => 'The profile photo could not be saved.']); exit; }
                }

                // photo and face_encoding are updated independently: photo only when a new
                // profile photo was uploaded; face_encoding only when a new descriptor was captured.
                if ($photoFilename) {
                    $stmt = $pdo->prepare("UPDATE students SET student_id=?, fullname=?, grade=?, section=?, contact_number=?, guardian_name=?, email=?, photo=?, face_encoding=COALESCE(NULLIF(?,''), face_encoding), status=? WHERE id=?");
                    $stmt->execute([$studentId, $fullname, $grade, $section, $contact, $guardian, $email, $photoFilename, $faceDescriptor, $status, $id]);

                    if ($oldPhoto && $oldPhoto !== $photoFilename) {
                        $oldPath = __DIR__ . '/../uploads/student/' . basename($oldPhoto);
                        if (is_file($oldPath)) @unlink($oldPath);
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE students SET student_id=?, fullname=?, grade=?, section=?, contact_number=?, guardian_name=?, email=?, face_encoding=COALESCE(NULLIF(?,''), face_encoding), status=? WHERE id=?");
                    $stmt->execute([$studentId, $fullname, $grade, $section, $contact, $guardian, $email, $faceDescriptor, $status, $id]);
                }
                log_activity($pdo, 'Student Updated', "Updated student '{$fullname}' (ID: {$studentId})");
                echo json_encode(['success' => true, 'message' => 'Student updated successfully.']);
            }
            break;
        }

        case 'delete': {
            if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'Invalid session token.']); exit;
            }
            $id = (int) ($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); exit; }

            $target = $pdo->prepare("SELECT student_id, fullname, photo FROM students WHERE id = ?");
            $target->execute([$id]);
            $targetRow = $target->fetch();

            $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
            $stmt->execute([$id]);

            if ($targetRow) {
                log_activity($pdo, 'Student Deleted', "Deleted student '{$targetRow['fullname']}' (ID: {$targetRow['student_id']})");
                if (!empty($targetRow['photo'])) {
                    $photoPath = __DIR__ . '/../uploads/student/' . basename($targetRow['photo']);
                    if (is_file($photoPath)) @unlink($photoPath);
                }
            }
            echo json_encode(['success' => true, 'message' => 'Student deleted.']);
            break;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    if ($newPhotoFilename && is_file(__DIR__ . '/../uploads/student/' . $newPhotoFilename)) {
        @unlink(__DIR__ . '/../uploads/student/' . $newPhotoFilename); // don't leave an orphan file if the DB write failed
    }
    error_log('student.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
}
