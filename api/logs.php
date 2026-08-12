<?php
// ConstructFlow — Activity Logs API
// Handles: list logs, clear logs, export

require_once __DIR__ . '/../config/helpers.php';
setCORSHeaders();
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET /api/logs.php?action=list ────────────────────────────
// Returns system activity logs. Administrator only.
// Optional: ?limit=50&offset=0
if ($method === 'GET' && $action === 'list') {
    requireRole('administrator');
    $db     = getDB();
    $limit  = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);

    $stmt = $db->prepare(
        'SELECT l.*, u.name AS user_name
         FROM ActivityLogs l
         LEFT JOIN Users u ON l.user_id = u.user_id
         ORDER BY l.logged_at DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->execute([$limit, $offset]);

    $total = (int)$db->query('SELECT COUNT(*) FROM ActivityLogs')->fetchColumn();

    json_response(true, 'Logs retrieved.', [
        'logs'   => $stmt->fetchAll(),
        'total'  => $total,
        'limit'  => $limit,
        'offset' => $offset,
    ]);
}

// ── POST /api/logs.php?action=clear ──────────────────────────
// Clears all activity logs. Administrator only.
if ($method === 'POST' && $action === 'clear') {
    $admin = requireRole('administrator');
    $db    = getDB();
    $db->query('DELETE FROM ActivityLogs');
    // Re-log the clear action itself
    logActivity($admin['user_id'], 'clear_logs', null, null, "{$admin['name']} cleared all activity logs.");
    json_response(true, 'Activity logs cleared.');
}

// ── GET /api/logs.php?action=export ──────────────────────────
// Exports all logs as CSV and saves to GeneratedReports.
if ($method === 'GET' && $action === 'export') {
    $admin = requireRole('administrator');
    $db    = getDB();

    $stmt = $db->query(
        'SELECT l.logged_at, u.name AS user, l.action, l.target_type, l.target_id, l.description, l.ip_address
         FROM ActivityLogs l
         LEFT JOIN Users u ON l.user_id = u.user_id
         ORDER BY l.logged_at DESC'
    );
    $rows = $stmt->fetchAll();

    // Build CSV
    $filename = 'constructflow_logs_' . date('Ymd_His') . '.csv';
    $dir      = __DIR__ . '/../uploads/reports/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $path = $dir . $filename;

    $fp = fopen($path, 'w');
    fputcsv($fp, ['Timestamp', 'User', 'Action', 'Target Type', 'Target ID', 'Description', 'IP Address']);
    foreach ($rows as $row) fputcsv($fp, $row);
    fclose($fp);

    // Record in GeneratedReports
    $db->prepare(
        'INSERT INTO GeneratedReports (title, report_type, generated_by, file_path) VALUES (?, ?, ?, ?)'
    )->execute(["Activity Log Export " . date('Y-m-d'), 'activity_log', $admin['user_id'], 'uploads/reports/' . $filename]);

    logActivity($admin['user_id'], 'export_logs', null, null, "{$admin['name']} exported activity logs.");

    // Stream the CSV directly to browser
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

json_response(false, 'Invalid action.', [], 400);
