<?php
// ConstructFlow — Inspection Tasks API
// create · list · detail · close
//

require_once __DIR__ . '/../config/helpers.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Every POST here is authenticated, so all of them require a token.
if ($method === 'POST') {
    verifyCsrf();
}

// ── POST ?action=create ──────────────────────────────────────
// Body: { assigned_to, title, description, location_text, due_date }
if ($method === 'POST' && $action === 'create') {
    $m      = requireTeamRole('supervisor');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = getBody();

    $assignedTo    = (int)($body['assigned_to'] ?? 0);
    $title         = clean($body['title'] ?? '');
    $description   = clean($body['description'] ?? '');
    $location_text = clean($body['location_text'] ?? '');
    $due_date      = clean($body['due_date'] ?? '');

    if (!$assignedTo || !$title || !$location_text) {
        json_response(false, 'Assigned inspector, title, and location are required.', [], 400);
    }

    $db = getDB();

    // The assignee must be a field inspector IN THIS TEAM. Checking
    // only the role would let a supervisor assign work to an inspector
    // belonging to somebody else's team.
    $ins = $db->prepare(
        'SELECT 1 FROM TeamMembers tm
         JOIN Users u ON tm.user_id = u.user_id
         WHERE tm.team_id = ? AND tm.user_id = ?
           AND tm.role = "field_inspector" AND tm.status = "active" AND u.is_active = 1'
    );
    $ins->execute([$teamId, $assignedTo]);
    if (!$ins->fetch()) {
        json_response(false, 'That person is not an active field inspector in this team.', [], 400);
    }

    $code = generateTaskCode($teamId);

    $db->prepare(
        'INSERT INTO InspectionTasks
         (team_id, task_code, created_by, assigned_to, title, description, location_text, due_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$teamId, $code, $actor['user_id'], $assignedTo, $title, $description, $location_text, $due_date ?: null]);
    $taskId = (int)$db->lastInsertId();

    logActivity($actor['user_id'], 'create_task', 'task', $taskId,
        "{$actor['name']} created and assigned inspection task $code.", $teamId);

    json_response(true, 'Inspection task created and assigned successfully.', [
        'task_id'   => $taskId,
        'task_code' => $code,
    ]);
}

// ── GET ?action=list ─────────────────────────────────────────
// field_inspector → only tasks assigned to them
// supervisor      → every task in their team
// Optional filter: ?status=assigned
if ($method === 'GET' && $action === 'list') {
    $m      = requireTeam();
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $db     = getDB();
    $status = $_GET['status'] ?? '';

    // team_id is the first condition and is never optional.
    $where  = ['t.team_id = ?'];
    $params = [$teamId];

    if ($m['role'] === 'field_inspector') {
        $where[]  = 't.assigned_to = ?';
        $params[] = $actor['user_id'];
    }
    if ($status) {
        $where[]  = 't.status = ?';
        $params[] = $status;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT t.*,
                u_creator.name  AS created_by_name,
                u_assignee.name AS assigned_to_name,
                (SELECT report_id   FROM InspectionReports WHERE task_id = t.task_id LIMIT 1) AS report_id,
                (SELECT report_code FROM InspectionReports WHERE task_id = t.task_id LIMIT 1) AS report_code
         FROM InspectionTasks t
         JOIN Users u_creator  ON t.created_by  = u_creator.user_id
         JOIN Users u_assignee ON t.assigned_to = u_assignee.user_id
         $whereSQL
         ORDER BY t.created_at DESC"
    );
    $stmt->execute($params);

    json_response(true, 'Tasks retrieved.', ['tasks' => $stmt->fetchAll()]);
}

// ── GET ?action=detail&id=1 ──────────────────────────────────
if ($method === 'GET' && $action === 'detail') {
    $m      = requireTeam();
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $taskId = (int)($_GET['id'] ?? 0);
    $db     = getDB();

    // team_id in the WHERE clause is what stops ID enumeration across
    // teams: a task belonging to another team simply is not found.
    $stmt = $db->prepare(
        'SELECT t.*,
                u_creator.name  AS created_by_name,
                u_assignee.name AS assigned_to_name
         FROM InspectionTasks t
         JOIN Users u_creator  ON t.created_by  = u_creator.user_id
         JOIN Users u_assignee ON t.assigned_to = u_assignee.user_id
         WHERE t.task_id = ? AND t.team_id = ?'
    );
    $stmt->execute([$taskId, $teamId]);
    $task = $stmt->fetch();

    if (!$task) {
        json_response(false, 'Task not found.', [], 404);
    }
    if ($m['role'] === 'field_inspector' && $task['assigned_to'] != $actor['user_id']) {
        json_response(false, 'Access denied.', [], 403);
    }

    $report = $db->prepare('SELECT * FROM InspectionReports WHERE task_id = ? AND team_id = ?');
    $report->execute([$taskId, $teamId]);

    json_response(true, 'Task detail retrieved.', [
        'task'   => $task,
        'report' => $report->fetch() ?: null,
    ]);
}

// ── POST ?action=close ───────────────────────────────────────
// Body: { task_id }
if ($method === 'POST' && $action === 'close') {
    $m      = requireTeamRole('supervisor');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $taskId = (int)(getBody()['task_id'] ?? 0);

    if (!$taskId) {
        json_response(false, 'Task ID is required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM InspectionTasks WHERE task_id = ? AND team_id = ?');
    $stmt->execute([$taskId, $teamId]);
    $t = $stmt->fetch();

    if (!$t) {
        json_response(false, 'Task not found.', [], 404);
    }
    if ($t['status'] !== 'submitted') {
        json_response(false, 'Only submitted tasks can be closed.', [], 400);
    }

    $db->prepare('UPDATE InspectionTasks SET status = "closed" WHERE task_id = ? AND team_id = ?')
       ->execute([$taskId, $teamId]);

    logActivity($actor['user_id'], 'close_task', 'task', $taskId,
        "{$actor['name']} reviewed and closed task {$t['task_code']}.", $teamId);

    json_response(true, 'Task closed successfully.');
}

json_response(false, 'Invalid action.', [], 400);
