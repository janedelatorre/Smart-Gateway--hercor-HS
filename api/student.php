<?php
/**
 * =====================================================================
 * STUDENT API
 * Actions: list, create, update, delete
 * =====================================================================
 */
require_once __DIR__ . '/../database/config.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in again.']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

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

            $stmt = $pdo->prepare("SELECT id, student_id, fullname, grade, section, contact_number, guardian_name, email, photo, status
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
            $photoData      = trim($_POST['photo_data'] ?? '');

            if ($studentId === '' || $fullname === '' || $grade === '' || $contact === '') {
                echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']); exit;
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Invalid email address format.']); exit;
            }

            // Validate contact number format (basic PH mobile format)
            if (!preg_match('/^[0-9+\-\s]{7,15}$/', $contact)) {
                echo json_encode(['success' => false, 'message' => 'Invalid contact number format.']); exit;
            }

            // Handle base64 captured photo (validated + saved as JPEG)
            $photoFilename = null;
            if ($photoData && preg_match('/^data:image\/(jpeg|png);base64,/', $photoData)) {
                $imgData = substr($photoData, strpos($photoData, ',') + 1);
                $imgBinary = base64_decode($imgData, true);
                if ($imgBinary !== false && strlen($imgBinary) < 5 * 1024 * 1024 && @getimagesizefromstring($imgBinary) !== false) { // 5MB limit + genuine image check
                    $photoFilename = 'std_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $studentId) . '_' . time() . '.jpg';
                    $uploadDir = __DIR__ . '/../uploads/student/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    file_put_contents($uploadDir . $photoFilename, $imgBinary);
                }
            }

            if ($action === 'create') {
                $dupe = $pdo->prepare("SELECT id FROM students WHERE student_id = ?");
                $dupe->execute([$studentId]);
                if ($dupe->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Student ID already exists.']); exit;
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
    error_log('student.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
}
