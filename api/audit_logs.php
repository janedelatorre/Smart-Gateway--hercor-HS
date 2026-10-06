<?php
/**
 * =====================================================================
 * AUDIT LOGS API — read-only for any authenticated user (Administrator or Staff)
 * =====================================================================
 * Serves the audit_logs table (written to via includes/security.php's
 * log_activity() helper across the whole app) to the Audit Logs page.
 * Staff have read access to their own accountability trail (logins,
 * scanner activity, etc.) the same way they already see a snapshot of
 * it on their dashboard — this endpoint is read-only, so it introduces
 * no new write/privilege surface for Staff.
 * Actions: list
 * =====================================================================
 */
require_once __DIR__ . '/../database/config.php';
header('Content-Type: application/json');

if (!require_login_api()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if (!in_array($_SESSION['role'] ?? '', ['Administrator', 'Staff'], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$action = $_GET['action'] ?? '';
if ($action !== 'list') {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

try {
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 10;
    $offset  = ($page - 1) * $perPage;

    $search   = trim($_GET['search'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo   = trim($_GET['date_to'] ?? '');
    $eventType = trim($_GET['event_type'] ?? '');
    $user     = trim($_GET['user'] ?? '');

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(description LIKE ? OR action LIKE ? OR username LIKE ? OR ip_address LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
    }
    if ($dateFrom !== '') { $where[] = "DATE(created_at) >= ?"; $params[] = $dateFrom; }
    if ($dateTo !== '')   { $where[] = "DATE(created_at) <= ?"; $params[] = $dateTo; }
    if ($eventType !== '') { $where[] = "action = ?"; $params[] = $eventType; }
    if ($user !== '')     { $where[] = "username = ?"; $params[] = $user; }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $count = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM audit_logs $whereSql ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    // Distinct filter option lists (kept in sync automatically as new action types appear)
    $eventTypes = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
    $users = $pdo->query("SELECT DISTINCT username FROM audit_logs WHERE username IS NOT NULL AND username != '' ORDER BY username ASC")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'event_types' => $eventTypes,
        'users' => $users,
    ]);
} catch (Throwable $e) {
    error_log('audit_logs.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}
