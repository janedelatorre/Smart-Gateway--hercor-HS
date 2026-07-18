<?php
/**
 * =====================================================================
 * NOTIFICATIONS API — In-app notification center
 * =====================================================================
 * Broadcast notifications (user_id IS NULL) are shared across all users;
 * marking one read marks it read for everyone, since the schema tracks a
 * single is_read flag per row rather than a per-user read state.
 */
require_once __DIR__ . '/../database/config.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}

$action = $_REQUEST['action'] ?? '';
$userId = (int) $_SESSION['user_id'];

try {
    switch ($action) {

        case 'list': {
            $limit = 15;
            $stmt = $pdo->prepare("SELECT id, type, title, message, is_read, created_at FROM notifications WHERE user_id = ? OR user_id IS NULL ORDER BY is_read ASC, created_at DESC LIMIT ?");
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $notifications = $stmt->fetchAll();

            $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
            $unreadStmt->execute([$userId]);
            $unreadCount = (int) $unreadStmt->fetchColumn();

            echo json_encode(['success' => true, 'notifications' => $notifications, 'unread_count' => $unreadCount]);
            break;
        }

        case 'mark_read': {
            if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'Invalid session token.']); exit;
            }
            $id = (int) ($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); exit; }

            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
            $stmt->execute([$id, $userId]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'mark_all_read': {
            if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'Invalid session token.']); exit;
            }
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('notifications.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
}
