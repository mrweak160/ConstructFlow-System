<?php

require_once __DIR__ . '/../config/helpers.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Every POST here is authenticated, so all of them require a token.
if ($method === 'POST') {
    verifyCsrf();
}

/**
 * ?from=YYYY-MM-DD&to=YYYY-MM-DD, both optional, both inclusive. Returns
 * the extra WHERE text and its arguments; a date that is not a real date
 * is ignored rather than trusted.
 */
function logRange(): array {
    $ok = function (string $d): bool {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    };
    $from = (string)($_GET['from'] ?? '');
    $to   = (string)($_GET['to']   ?? '');
    $sql = ''; $args = [];
    if ($ok($from)) { $sql .= ' AND l.logged_at >= ?'; $args[] = $from . ' 00:00:00'; }
    if ($ok($to))   { $sql .= ' AND l.logged_at <= ?'; $args[] = $to   . ' 23:59:59'; }
    return [$sql, $args];
}

// ── GET ?action=list ─────────────────────────────────────────
// Optional: ?limit=50&offset=0&from=YYYY-MM-DD&to=YYYY-MM-DD
if ($method === 'GET' && $action === 'list') {
    $m      = requireTeamRole('administrator');
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    $limit  = min(max((int)($_GET['limit'] ?? 50), 1), 200);
    $offset = max((int)($_GET['offset'] ?? 0), 0);
    [$rangeSql, $rangeArgs] = logRange();

    $stmt = $db->prepare(
        "SELECT l.*, u.name AS user_name
         FROM ActivityLogs l
         LEFT JOIN Users u ON l.user_id = u.user_id
         WHERE l.team_id = ?$rangeSql
         ORDER BY l.logged_at DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute(array_merge([$teamId], $rangeArgs));

    $totalStmt = $db->prepare("SELECT COUNT(*) FROM ActivityLogs l WHERE l.team_id = ?$rangeSql");
    $totalStmt->execute(array_merge([$teamId], $rangeArgs));

    json_response(true, 'Logs retrieved.', [
        'logs'   => $stmt->fetchAll(),
        'total'  => (int)$totalStmt->fetchColumn(),
        'limit'  => $limit,
        'offset' => $offset,
    ]);
}

// ── GET ?action=verify ───────────────────────────────────────
// Checks this team's log against its signatures (config/logseal.php).
// There is no clear action: an audit trail its own administrator can wipe
// is not an audit trail.
if ($method === 'GET' && $action === 'verify') {
    $m = requireTeamRole('administrator');
    json_response(true, 'Log checked.', verifyActivityLog((int)$m['team_id']));
}

// ── GET ?action=export ───────────────────────────────────────
if ($method === 'GET' && $action === 'export') {
    $m      = requireTeamRole('administrator');
    $admin  = currentUser();
    $teamId = (int)$m['team_id'];
    $db     = getDB();
    [$rangeSql, $rangeArgs] = logRange();   // the period on the page

    $stmt = $db->prepare(
        // ip_address is deliberately NOT exported. It is still recorded in
        // ActivityLogs for the audit trail, but a CSV gets emailed and shared,
        // and an IP is personal data under the Data Privacy Act.
        // The role is the person's role in this team now; someone who has
        // left shows as a former member.
        'SELECT l.logged_at, u.name AS user, u.email, l.action, tm.role, tm.status AS member_status,
                l.user_id, l.description
         FROM ActivityLogs l
         LEFT JOIN Users u        ON l.user_id = u.user_id
         LEFT JOIN TeamMembers tm ON tm.user_id = l.user_id AND tm.team_id = l.team_id
         WHERE l.team_id = ?' . $rangeSql . '
           AND l.action <> "close_task"   -- left out of the export on request
         ORDER BY l.logged_at DESC'
    );
    $stmt->execute(array_merge([$teamId], $rangeArgs));
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
    $roleLabel = [
        'administrator'   => 'Administrator',
        'supervisor'      => 'Supervisor',
        'field_inspector' => 'Field Inspector',
        'field_worker'    => 'Field Worker',
        'member'          => 'Member',
    ];
    fputcsv($fp, ['Timestamp', 'User', 'Email', 'Action', 'Role', 'User ID', 'Description']);
    foreach ($rows as $row) {
        $role = match (true) {
            $row['user_id'] === null                                 => 'System',
            $row['role'] === null || $row['member_status'] === 'removed' => 'Former member',
            default => $roleLabel[$row['role']] ?? ucfirst((string)$row['role']),
        };
        fputcsv($fp, array_map($safeCell, [
            $row['logged_at'], $row['user'] ?? 'System', $row['email'], $row['action'],
            $role, $row['user_id'], $row['description'],
        ]));
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
