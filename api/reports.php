<?php

require_once __DIR__ . '/../config/helpers.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Every POST here is authenticated, so all of them require a token.
if ($method === 'POST') {
    verifyCsrf();
}

// ── POST ?action=submit ──────────────────────────────────────
// Multipart: task_id, title, issue_type, description, location_text,
//            latitude, longitude, severity, photos[]
if ($method === 'POST' && $action === 'submit') {
    $m      = requireTeamRole('field_inspector');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = $_POST;   // multipart, for the photo upload

    $taskId        = (int)($body['task_id'] ?? 0);
    $title         = clean($body['title'] ?? '');
    $issue_type    = clean($body['issue_type'] ?? '');
    $description   = clean($body['description'] ?? '');
    $location_text = clean($body['location_text'] ?? '');
    $latitude      = isset($body['latitude'])  && $body['latitude']  !== '' ? (float)$body['latitude']  : null;
    $longitude     = isset($body['longitude']) && $body['longitude'] !== '' ? (float)$body['longitude'] : null;
    $severity      = in_array($body['severity'] ?? '', ['low','moderate','high','critical'], true)
                     ? $body['severity'] : 'low';


    if (!$taskId || !$title || !$issue_type || !$description || !$location_text) {
        json_response(false, 'A valid assigned task, title, issue type, description, and location are required.', [], 400);
    }

    $db = getDB();

    // The task must be in this team AND assigned to this inspector.
    $task = $db->prepare('SELECT * FROM InspectionTasks WHERE task_id = ? AND team_id = ? AND assigned_to = ?');
    $task->execute([$taskId, $teamId, $actor['user_id']]);
    $t = $task->fetch();

    if (!$t) {
        json_response(false, 'This task is not assigned to you.', [], 403);
    }
    if ($t['status'] !== 'assigned') {
        json_response(false, 'A report has already been submitted for this task.', [], 400);
    }

    $code = generateReportCode($teamId);

    $db->prepare(
        'INSERT INTO InspectionReports
         (team_id, report_code, task_id, submitted_by, title, issue_type, description,
          location_text, latitude, longitude, severity)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $teamId, $code, $taskId, $actor['user_id'], $title, $issue_type,
        $description, $location_text, $latitude, $longitude, $severity,
    ]);
    $reportId = (int)$db->lastInsertId();

    $db->prepare('UPDATE InspectionTasks SET status = "submitted" WHERE task_id = ? AND team_id = ?')
       ->execute([$taskId, $teamId]);

    $photoResult = handleMultiplePhotoUploads('photos', 'REP', $reportId);
    foreach ($photoResult['saved'] as $photoPath) {
        $db->prepare(
            'INSERT INTO PhotoEvidence (report_id, file_path, file_name, latitude, longitude)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$reportId, $photoPath, basename($photoPath), $latitude, $longitude]);
    }

    logActivity($actor['user_id'], 'submit_report', 'report', $reportId,
        "{$actor['name']} submitted inspection report $code.", $teamId);

    json_response(true, 'Inspection report submitted successfully.', [
        'report_id'      => $reportId,
        'report_code'    => $code,
        'photo_warnings' => $photoResult['errors'],
    ]);
}

// ── GET ?action=list ─────────────────────────────────────────
// field_inspector → own reports only
// supervisor      → all reports in the team
// Optional: ?severity=high&status=pending
if ($method === 'GET' && $action === 'list') {
    $m      = requireTeam();
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    $where  = ['r.team_id = ?'];
    $params = [$teamId];

    if ($m['role'] === 'field_inspector') {
        $where[]  = 'r.submitted_by = ?';
        $params[] = $actor['user_id'];
    }
    if ($sev = ($_GET['severity'] ?? '')) {
        $where[]  = 'r.severity = ?';
        $params[] = $sev;
    }
    if ($st = ($_GET['status'] ?? '')) {
        $where[]  = 'r.status = ?';
        $params[] = $st;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT r.*,
                u.name AS submitted_by_name,
                (SELECT file_path FROM PhotoEvidence WHERE report_id = r.report_id LIMIT 1) AS photo
         FROM InspectionReports r
         JOIN Users u ON r.submitted_by = u.user_id
         $whereSQL
         ORDER BY r.submitted_at DESC"
    );
    $stmt->execute($params);

    json_response(true, 'Reports retrieved.', ['reports' => $stmt->fetchAll()]);
}

// ── GET ?action=detail&id=1 ──────────────────────────────────
if ($method === 'GET' && $action === 'detail') {
    $m        = requireTeam();
    $actor    = currentUser();
    $teamId   = (int)$m['team_id'];
    $reportId = (int)($_GET['id'] ?? 0);
    $db       = getDB();

    $stmt = $db->prepare(
        'SELECT r.*, u.name AS submitted_by_name
         FROM InspectionReports r
         JOIN Users u ON r.submitted_by = u.user_id
         WHERE r.report_id = ? AND r.team_id = ?'
    );
    $stmt->execute([$reportId, $teamId]);
    $report = $stmt->fetch();

    if (!$report) {
        json_response(false, 'Report not found.', [], 404);
    }
    // An inspector sees only their own submissions.
    if ($m['role'] === 'field_inspector' && $report['submitted_by'] != $actor['user_id']) {
        json_response(false, 'Access denied.', [], 403);
    }

    $photos = $db->prepare('SELECT * FROM PhotoEvidence WHERE report_id = ?');
    $photos->execute([$reportId]);

    $wo = $db->prepare(
        'SELECT w.*, u.name AS assigned_to_name
         FROM WorkOrders w
         LEFT JOIN Users u ON w.assigned_to = u.user_id
         WHERE w.report_id = ? AND w.team_id = ?'
    );
    $wo->execute([$reportId, $teamId]);

    json_response(true, 'Report detail retrieved.', [
        'report'     => $report,
        'photos'     => $photos->fetchAll(),
        'work_order' => $wo->fetch() ?: null,
    ]);
}

// ── GET ?action=stats ────────────────────────────────────────
// Dashboard counters. Rewritten as one grouped query rather than the
// previous four separate statements with conditionally-built WHERE
// fragments, which were fragile and easy to get wrong.
if ($method === 'GET' && $action === 'stats') {
    $m      = requireTeamRole('field_inspector', 'supervisor');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    $where  = ['team_id = ?'];
    $params = [$teamId];
    if ($m['role'] === 'field_inspector') {
        $where[]  = 'submitted_by = ?';
        $params[] = $actor['user_id'];
    }
    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(DATE(submitted_at) = CURDATE()) AS reports_today,
            SUM(severity = 'critical' AND status NOT IN ('completed','rejected')) AS critical_flagged,
            SUM(status = 'completed') AS resolved
         FROM InspectionReports
         $whereSQL"
    );
    $stmt->execute($params);
    $row = $stmt->fetch();

    $total    = (int)$row['total'];
    $resolved = (int)$row['resolved'];

    json_response(true, 'Stats retrieved.', [
        'reports_today'    => (int)$row['reports_today'],
        'critical_flagged' => (int)$row['critical_flagged'],
        'resolution_rate'  => ($total > 0 ? round(($resolved / $total) * 100) : 0) . '%',
        'total'            => $total,
    ]);
}

json_response(false, 'Invalid action.', [], 400);
