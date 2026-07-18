<?php
/**
 * =====================================================================
 * USER MANAGEMENT API — Administrator only
 * =====================================================================
 */
require_once __DIR__ . '/../database/config.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}
if (($_SESSION['role'] ?? '') !== 'Administrator') {
    echo json_encode(['success' => false, 'message' => 'Access denied. Administrator role required.']); exit;
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        case 'list': {
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = 10;
            $offset = ($page - 1) * $perPage;
            $search = trim($_GET['search'] ?? '');

            $where = '';
            $params = [];
            if ($search !== '') {
                $where = "WHERE username LIKE ? OR fullname LIKE ?";
                $params = ["%$search%", "%$search%"];
            }

            $count = $pdo->prepare("SELECT COUNT(*) FROM users $where");
            $count->execute($params);
            $total = (int) $count->fetchColumn();

            $stmt = $pdo->prepare("SELECT id, username, fullname, email, contact_number, role, status FROM users $where ORDER BY fullname ASC LIMIT $perPage OFFSET $offset");
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
            break;
        }

        case 'create':
        case 'update': {
            if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'Invalid session token.']); exit;
            }
            $id       = (int) ($_POST['id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $fullname = trim($_POST['fullname'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $contact  = trim($_POST['contact_number'] ?? '');
            $role     = in_array($_POST['role'] ?? '', ['Administrator','Staff']) ? $_POST['role'] : 'Staff';
            $status   = in_array($_POST['status'] ?? '', ['Active','Inactive']) ? $_POST['status'] : 'Active';
            $password = (string) ($_POST['password'] ?? '');

            if ($username === '' || $fullname === '') {
                echo json_encode(['success' => false, 'message' => 'Username and Full Name are required.']); exit;
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Invalid email address format.']); exit;
            }
            if ($contact !== '' && !preg_match('/^[0-9+\-\s]{7,15}$/', $contact)) {
                echo json_encode(['success' => false, 'message' => 'Invalid contact number format.']); exit;
            }

            $dupe = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $dupe->execute([$username, $id]);
            if ($dupe->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Username already taken.']); exit;
            }

            if ($action === 'create') {
                if ($password === '') {
                    echo json_encode(['success' => false, 'message' => 'Password is required for new users.']); exit;
                }
                $policyErrors = validate_password_strength($password);
                if ($policyErrors) {
                    echo json_encode(['success' => false, 'message' => 'Password does not meet requirements: ' . implode(' ', $policyErrors)]); exit;
                }
                $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
                $hash = password_hash($password, $algo);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, fullname, email, contact_number, role, status) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$username, $hash, $fullname, $email, $contact, $role, $status]);
                log_activity($pdo, 'User Created', "Created user '{$username}' (role: {$role})");
                echo json_encode(['success' => true, 'message' => 'User created successfully.']);
            } else {
                if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid user record.']); exit; }
                if ($password !== '') {
                    $policyErrors = validate_password_strength($password);
                    if ($policyErrors) {
                        echo json_encode(['success' => false, 'message' => 'Password does not meet requirements: ' . implode(' ', $policyErrors)]); exit;
                    }
                    if (is_password_reused($pdo, $id, $password)) {
                        echo json_encode(['success' => false, 'message' => 'That password was used recently. Please choose a different one.']); exit;
                    }
                    $stmt = $pdo->prepare("UPDATE users SET username=?, fullname=?, email=?, contact_number=?, role=?, status=? WHERE id=?");
                    $stmt->execute([$username, $fullname, $email, $contact, $role, $status, $id]);
                    set_user_password($pdo, $id, $password);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username=?, fullname=?, email=?, contact_number=?, role=?, status=? WHERE id=?");
                    $stmt->execute([$username, $fullname, $email, $contact, $role, $status, $id]);
                }
                log_activity($pdo, 'User Updated', "Updated user '{$username}' (id: {$id})");
                echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
            }
            break;
        }

        case 'delete': {
            if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'Invalid session token.']); exit;
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => 'You cannot delete your own account while logged in.']); exit;
            }
            $target = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $target->execute([$id]);
            $targetUsername = $target->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            log_activity($pdo, 'User Deleted', "Deleted user '{$targetUsername}' (id: {$id})");
            echo json_encode(['success' => true, 'message' => 'User deleted.']);
            break;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('user.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}
