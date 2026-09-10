<?php
// ConstructFlow — Work Orders API
// create · list · detail · update_status · stats


require_once __DIR__ . '/../config/helpers.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Every POST here is authenticated, so all of them require a token.
if ($method === 'POST') {
    verifyCsrf();
}

// ── POST ?action=create ──────────────────────────────────────
// Body: { report_id, assigned_to, severity, instructions, deadline }
if ($method === 'POST' && $action === 'create') {
    $m      = requireTeamRole('supervisor');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = getBody();

    $reportId     = (int)($body['report_id']   ?? 0);
    $assignedTo   = (int)($body['assigned_to'] ?? 0);
    $severity     = in_array($body['severity'] ?? '', ['low','moderate','high','critical'], true)
                    ? $body['severity'] : null;
    $instructions = clean($body['instructions'] ?? '');
    $deadline     = clean($body['deadline'] ?? '');

    if (!$reportId || !$assignedTo || !$severity) {
        json_response(false, 'Report, assigned worker, and severity are required.', [], 400);
    }

    $db = getDB();

    $report = $db->prepare('SELECT * FROM InspectionReports WHERE report_id = ? AND team_id = ?');
    $report->execute([$reportId, $teamId]);
    if (!$report->fetch()) {
        json_response(false, 'Inspection report not found.', [], 404);
    }

    // The check the original file described but never performed.
    $dupe = $db->prepare('SELECT wo_code FROM WorkOrders WHERE report_id = ? AND team_id = ? LIMIT 1');
    $dupe->execute([$reportId, $teamId]);
    if ($existing = $dupe->fetch()) {
        json_response(false, "A work order ({$existing['wo_code']}) already exists for this report.", [], 409);
    }

    // Assignee must be an active field worker IN THIS TEAM.
    $worker = $db->prepare(
        'SELECT 1 FROM TeamMembers tm
         JOIN Users u ON tm.user_id = u.user_id
         WHERE tm.team_id = ? AND tm.user_id = ?
           AND tm.role = "field_worker" AND tm.status = "active" AND u.is_active = 1'
    );
    $worker->execute([$teamId, $assignedTo]);
    if (!$worker->fetch()) {
        json_response(false, 'That person is not an active field worker in this team.', [], 400);
    }

    $code = generateWOCode($teamId);

    $db->prepare(
        'INSERT INTO WorkOrders
         (team_id, wo_code, report_id, created_by, assigned_to, severity, instructions, deadline)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$teamId, $code, $reportId, $actor['user_id'], $assignedTo, $severity, $instructions, $deadline ?: null]);
    $woId = (int)$db->lastInsertId();

    $db->prepare('UPDATE InspectionReports SET status = "assigned" WHERE report_id = ? AND team_id = ?')
       ->execute([$reportId, $teamId]);

    logActivity($actor['user_id'], 'create_work_order', 'work_order', $woId,
        "{$actor['name']} created and assigned work order $code.", $teamId);

    json_response(true, 'Work order created and assigned successfully.', [
        'wo_id'   => $woId,
        'wo_code' => $code,
    ]);
}

// ── GET ?action=list ─────────────────────────────────────────
// field_worker → only their own work orders
// supervisor   → all work orders in the team
if ($method === 'GET' && $action === 'list') {
    $m      = requireTeam();
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    $where  = ['w.team_id = ?'];
    $params = [$teamId];

    if ($m['role'] === 'field_worker') {
        $where[]  = 'w.assigned_to = ?';
        $params[] = $actor['user_id'];
    }
    if ($status = ($_GET['status'] ?? '')) {
        $where[]  = 'w.status = ?';
        $params[] = $status;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT w.*,
                r.report_code, r.title AS report_title, r.location_text,
                u_creator.name AS created_by_name,
                u_worker.name  AS assigned_to_name,
                (SELECT status  FROM FieldWorkUpdates WHERE wo_id = w.wo_id ORDER BY updated_at DESC LIMIT 1) AS latest_update_status,
                (SELECT remarks FROM FieldWorkUpdates WHERE wo_id = w.wo_id ORDER BY updated_at DESC LIMIT 1) AS latest_remarks
         FROM WorkOrders w
         JOIN InspectionReports r ON w.report_id = r.report_id
         JOIN Users u_creator ON w.created_by = u_creator.user_id
         LEFT JOIN Users u_worker ON w.assigned_to = u_worker.user_id
         $whereSQL
         ORDER BY w.created_at DESC"
    );
    $stmt->execute($params);

    json_response(true, 'Work orders retrieved.', ['work_orders' => $stmt->fetchAll()]);
}

// ── GET ?action=detail&id=1 ──────────────────────────────────
if ($method === 'GET' && $action === 'detail') {
    $m      = requireTeam();
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $woId   = (int)($_GET['id'] ?? 0);
    $db     = getDB();

    $stmt = $db->prepare(
        'SELECT w.*,
                r.report_code, r.title AS report_title, r.location_text,
                r.description AS report_description,
                u_creator.name AS created_by_name,
                u_worker.name  AS assigned_to_name
         FROM WorkOrders w
         JOIN InspectionReports r ON w.report_id = r.report_id
         JOIN Users u_creator ON w.created_by = u_creator.user_id
         LEFT JOIN Users u_worker ON w.assigned_to = u_worker.user_id
         WHERE w.wo_id = ? AND w.team_id = ?'
    );
    $stmt->execute([$woId, $teamId]);
    $wo = $stmt->fetch();

    if (!$wo) {
        json_response(false, 'Work order not found.', [], 404);
    }
    // Loose != on purpose is a bug source; compare as integers.
    if ($m['role'] === 'field_worker' && (int)$wo['assigned_to'] !== (int)$actor['user_id']) {
        json_response(false, 'Access denied.', [], 403);
    }

    $updates = $db->prepare(
        'SELECT fu.*, u.name AS updated_by_name
         FROM FieldWorkUpdates fu
         JOIN Users u ON fu.updated_by = u.user_id
         WHERE fu.wo_id = ?
         ORDER BY fu.updated_at DESC'
    );
    $updates->execute([$woId]);
    $allUpdates = $updates->fetchAll();

    if ($allUpdates) {
        $updateIds    = array_column($allUpdates, 'update_id');
        $placeholders = implode(',', array_fill(0, count($updateIds), '?'));
        $photoStmt = $db->prepare("SELECT * FROM FieldWorkPhotos WHERE update_id IN ($placeholders) ORDER BY photo_id");
        $photoStmt->execute($updateIds);

        $photosByUpdate = [];
        foreach ($photoStmt->fetchAll() as $p) {
            $photosByUpdate[$p['update_id']][] = $p;
        }
        foreach ($allUpdates as &$u) {
            $u['photos'] = $photosByUpdate[$u['update_id']] ?? [];
        }
        unset($u);
    }

    json_response(true, 'Work order detail retrieved.', [
        'work_order' => $wo,
        'updates'    => $allUpdates,
    ]);
}

// ── POST ?action=update_status ───────────────────────────────
// Multipart: wo_id, status, remarks, photos[]
if ($method === 'POST' && $action === 'update_status') {
    $m      = requireTeamRole('field_worker', 'supervisor');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = $_POST ?: getBody();

    $woId    = (int)($body['wo_id'] ?? 0);
    $status  = in_array($body['status'] ?? '', ['in_progress','on_hold','completed'], true)
               ? $body['status'] : null;
    $remarks = clean($body['remarks'] ?? '');

    if (!$woId || !$status) {
        json_response(false, 'Work order ID and status are required.', [], 400);
    }

    $db = getDB();
    $wo = $db->prepare('SELECT * FROM WorkOrders WHERE wo_id = ? AND team_id = ?');
    $wo->execute([$woId, $teamId]);
    $w = $wo->fetch();

    if (!$w) {
        json_response(false, 'Work order not found.', [], 404);
    }
    if ($m['role'] === 'field_worker' && (int)$w['assigned_to'] !== (int)$actor['user_id']) {
        json_response(false, 'You can only update your own work orders.', [], 403);
    }
   
    if ($w['status'] === 'completed' && $m['role'] === 'field_worker') {
        json_response(false, 'This work order is already completed. Ask your supervisor to reopen it.', [], 409);
    }

    $db->prepare(
        'INSERT INTO FieldWorkUpdates (wo_id, updated_by, status, remarks) VALUES (?, ?, ?, ?)'
    )->execute([$woId, $actor['user_id'], $status, $remarks]);
    $updateId = (int)$db->lastInsertId();

     $photoResult = handleMultiplePhotoUploads('photos', 'WO', $woId);
    foreach ($photoResult['saved'] as $photoPath) {
        $db->prepare(
            'INSERT INTO FieldWorkPhotos (update_id, file_path, file_name) VALUES (?, ?, ?)'
        )->execute([$updateId, $photoPath, basename($photoPath)]);
    }

    $db->prepare('UPDATE WorkOrders SET status = ? WHERE wo_id = ? AND team_id = ?')
       ->execute([$status, $woId, $teamId]);

    // Keep the parent report's status in step with the work order.
    $reportStatus = match ($status) {
        'completed'   => 'completed',
        'in_progress' => 'in_progress',
        default       => null,      // on_hold has no report-level equivalent
    };
    if ($reportStatus) {
        $db->prepare('UPDATE InspectionReports SET status = ? WHERE report_id = ? AND team_id = ?')
           ->execute([$reportStatus, $w['report_id'], $teamId]);
    }

    logActivity($actor['user_id'], 'update_work_order', 'work_order', $woId,
        "{$actor['name']} updated work order {$w['wo_code']} to '$status'.", $teamId);

    json_response(true, 'Work order status updated successfully.', [
        'photo_warnings' => $photoResult['errors'],
    ]);
    
}

// ── GET ?action=stats ────────────────────────────────────────
if ($method === 'GET' && $action === 'stats') {
    $m      = requireTeamRole('supervisor');
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    $wo = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(status IN ('pending','in_progress')) AS ongoing,
            SUM(status = 'completed') AS completed,
            SUM(severity IN ('high','critical') AND status <> 'completed') AS high_severity
         FROM WorkOrders WHERE team_id = ?"
    );
    $wo->execute([$teamId]);
    $w = $wo->fetch();

    $rep = $db->prepare(
        "SELECT COUNT(*) AS total, SUM(status = 'pending') AS pending
         FROM InspectionReports WHERE team_id = ?"
    );
    $rep->execute([$teamId]);
    $r = $rep->fetch();

    json_response(true, 'Supervisor stats retrieved.', [
        'total_reports'    => (int)$r['total'],
        'pending_reports'  => (int)$r['pending'],
        'ongoing_orders'   => (int)$w['ongoing'],
        'completed_orders' => (int)$w['completed'],
        'high_severity'    => (int)$w['high_severity'],
    ]);
}

json_response(false, 'Invalid action.', [], 400);
