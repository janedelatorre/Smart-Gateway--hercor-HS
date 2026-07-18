<?php
/**
 * =====================================================================
 * ENTRY LOGS API — read-only listing with search/filter/pagination
 * =====================================================================
 */
require_once __DIR__ . '/../database/config.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
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
    $status   = trim($_GET['status'] ?? '');
    $method   = trim($_GET['method'] ?? '');

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(student_id LIKE ? OR fullname LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%";
    }
    if ($dateFrom !== '') { $where[] = "DATE(time_in) >= ?"; $params[] = $dateFrom; }
    if ($dateTo !== '')   { $where[] = "DATE(time_in) <= ?"; $params[] = $dateTo; }
    if ($status !== '')   { $where[] = "status = ?"; $params[] = $status; }
    if ($method !== '')   { $where[] = "verification_method = ?"; $params[] = $method; }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $count = $pdo->prepare("SELECT COUNT(*) FROM entry_logs $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM entry_logs $whereSql ORDER BY time_in DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $data, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
} catch (Throwable $e) {
    error_log('entry_logs.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
}
