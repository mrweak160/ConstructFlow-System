<?php

require_once __DIR__ . '/../config/helpers.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Every POST here is authenticated, so all of them require a token.
if ($method === 'POST') {
    verifyCsrf();
}

// ── GET ?action=list ─────────────────────────────────────────
// Optional: ?limit=50&offset=0
if ($method === 'GET' && $action === 'list') {
    $m      = requireTeamRole('administrator');
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    $limit  = min(max((int)($_GET['limit'] ?? 50), 1), 200);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $stmt = $db->prepare(
        "SELECT l.*, u.name AS user_name
         FROM ActivityLogs l
         LEFT JOIN Users u ON l.user_id = u.user_id
         WHERE l.team_id = ?
         ORDER BY l.logged_at DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute([$teamId]);

    $totalStmt = $db->prepare('SELECT COUNT(*) FROM ActivityLogs WHERE team_id = ?');
    $totalStmt->execute([$teamId]);

    json_response(true, 'Logs retrieved.', [
        'logs'   => $stmt->fetchAll(),
        'total'  => (int)$totalStmt->fetchColumn(),
        'limit'  => $limit,
        'offset' => $offset,
    ]);
}

// ── POST ?action=clear ───────────────────────────────────────
// Clears this team's logs only.
if ($method === 'POST' && $action === 'clear') {
    $m      = requireTeamRole('administrator');
    $admin  = currentUser();
    $teamId = (int)$m['team_id'];

    getDB()->prepare('DELETE FROM ActivityLogs WHERE team_id = ?')->execute([$teamId]);

    // Re-log the clear itself so the trail is never completely empty.
    logActivity($admin['user_id'], 'clear_logs', null, null,
        "{$admin['name']} cleared the team's activity logs.", $teamId);

    json_response(true, 'Activity logs cleared.');
}

// ── GET ?action=export ───────────────────────────────────────
if ($method === 'GET' && $action === 'export') {
    $m      = requireTeamRole('administrator');
    $admin  = currentUser();
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    $stmt = $db->prepare(
        'SELECT l.logged_at, u.name AS user, l.action, l.target_type, l.target_id, l.description, l.ip_address
         FROM ActivityLogs l
         LEFT JOIN Users u ON l.user_id = u.user_id
         WHERE l.team_id = ?
         ORDER BY l.logged_at DESC'
    );
    $stmt->execute([$teamId]);
    $rows = $stmt->fetchAll();

    $filename = 'constructflow_logs_team' . $teamId . '_' . date('Ymd_His') . '.csv';
    $dir      = __DIR__ . '/../uploads/reports/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $path = $dir . $filename;
    $safeCell = function ($v) {
        $v = (string)$v;
        return (isset($v[0]) && str_contains('=+-@', $v[0])) ? "'" . $v : $v;
    };

    $fp = fopen($path, 'w');
    fputcsv($fp, ['Timestamp', 'User', 'Action', 'Target Type', 'Target ID', 'Description', 'IP Address']);
    foreach ($rows as $row) {
        fputcsv($fp, array_map($safeCell, array_values($row)));
    }
    fclose($fp);

    $db->prepare(
        'INSERT INTO GeneratedReports (team_id, title, report_type, generated_by, file_path)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $teamId, 'Activity Log Export ' . date('Y-m-d'), 'activity_log',
        $admin['user_id'], 'uploads/reports/' . $filename,
    ]);

    logActivity($admin['user_id'], 'export_logs', null, null,
        "{$admin['name']} exported the team's activity logs.", $teamId);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

json_response(false, 'Invalid action.', [], 400);
