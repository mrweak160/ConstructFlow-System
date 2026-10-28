<?php
// ConstructFlow — Inspection Tasks API
// create · list · reassign · update · cancel · close
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
    requireMaxLength(['Title' => [$title, 200], 'Location' => [$location_text, 255]]);
    $due_date      = clean($body['due_date'] ?? '');

    if (!$assignedTo || !$title || !$location_text) {
        json_response(false, 'Assigned inspector, title, and location are required.', [], 400);
    }
    // A due date is optional, but a date already in the past is never a real
    // deadline. Checked here because the <input min> attribute is only a hint
    // and can be bypassed by posting straight to the API.
    if ($due_date !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $due_date);
        if (!$d || $d->format('Y-m-d') !== $due_date) {
            json_response(false, 'Enter a valid due date.', [], 400);
        }
        // Compared by the database. PHP's date.timezone and the database
        // server's timezone are set independently, and while they disagree
        // on the calendar day this guard let a past date straight through.
        $past = getDB()->prepare('SELECT CAST(? AS DATE) < CURDATE()');
        $past->execute([$due_date]);
        if ((int)$past->fetchColumn() === 1) {
            json_response(false, 'The due date must be today or a later date.', [], 400);
        }
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

    // nextCode() can hand two simultaneous requests the same number; the
    // unique key turns the second away, so take the next one and retry.
    for ($try = 1; ; $try++) {
        $code = generateTaskCode($teamId);
        try {
            $db->prepare(
                'INSERT INTO InspectionTasks
                 (team_id, task_code, created_by, assigned_to, title, description, location_text, due_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$teamId, $code, $actor['user_id'], $assignedTo, $title, $description, $location_text, $due_date ?: null]);
            break;
        } catch (PDOException $e) {
            if ($try >= 3 || !isDuplicateCode($e)) throw $e;
        }
    }
    $taskId = (int)$db->lastInsertId();

    notifyUsers([$assignedTo], $teamId, "New inspection task: $code",
        "{$actor['name']} assigned you a new inspection task.", [
            'Task'         => "$code · $title",
            'Location'     => $location_text,
            'Due'          => notifyDate($due_date ?: null),
            'Instructions' => $description,
        ]);

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

    json_response(true, 'Tasks retrieved.', [
        'tasks' => markFormerMembers($stmt->fetchAll(), $teamId, [
            'created_by'  => 'created_by_name',
            'assigned_to' => 'assigned_to_name',
        ]),
    ]);
}

// ── POST ?action=reassign ────────────────────────────────────
// Body: { task_id, assigned_to }
//
// Moves a task nobody has inspected yet to another inspector — the person
// it went to may have left the team, changed role or be unavailable. Once
// a report exists the task belongs to that report and stays put.
if ($method === 'POST' && $action === 'reassign') {
    $m      = requireTeamRole('supervisor');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = getBody();
    $taskId = (int)($body['task_id'] ?? 0);
    $newId  = (int)($body['assigned_to'] ?? 0);

    if (!$taskId || !$newId) {
        json_response(false, 'Task and inspector are required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM InspectionTasks WHERE task_id = ? AND team_id = ?');
    $stmt->execute([$taskId, $teamId]);
    $t = $stmt->fetch();
    if (!$t) {
        json_response(false, 'Task not found.', [], 404);
    }
    if ($t['status'] !== 'assigned') {
        json_response(false, 'This task has already been inspected, so it stays with the inspector who filed the report.', [], 409);
    }
    if ((int)$t['assigned_to'] === $newId) {
        json_response(false, 'That inspector already has this task.', [], 400);
    }
    $chk = $db->prepare(
        'SELECT u.name FROM TeamMembers tm JOIN Users u ON tm.user_id = u.user_id
          WHERE tm.team_id = ? AND tm.user_id = ?
            AND tm.role = "field_inspector" AND tm.status = "active" AND u.is_active = 1'
    );
    $chk->execute([$teamId, $newId]);
    $name = $chk->fetchColumn();
    if (!$name) {
        json_response(false, 'That person is not an active field inspector in this team.', [], 400);
    }

    $db->prepare('UPDATE InspectionTasks SET assigned_to = ?, due_reminder_sent_at = NULL WHERE task_id = ? AND team_id = ?')
       ->execute([$newId, $taskId, $teamId]);

    $rows = [
        'Task'     => "{$t['task_code']} · {$t['title']}",
        'Location' => $t['location_text'],
        'Due'      => notifyDate($t['due_date']),
    ];
    notifyUsers([$newId], $teamId, "New inspection task: {$t['task_code']}",
        "{$actor['name']} handed you an inspection task.", $rows + ['Instructions' => $t['description']]);
    notifyUsers([(int)$t['assigned_to']], $teamId, "Task moved: {$t['task_code']}",
        "{$actor['name']} handed this task to $name. You no longer need to inspect it.", $rows);

    logActivity($actor['user_id'], 'reassign_task', 'task', $taskId,
        "{$actor['name']} reassigned task {$t['task_code']} to $name.", $teamId);

    json_response(true, "Task {$t['task_code']} reassigned to $name.");
}

// ── POST ?action=update ──────────────────────────────────────
// Body: { task_id, title, description, location_text, due_date }
//
// Corrects a task nobody has inspected yet. Once a report exists the task
// is part of that record and stays as it was filed. An unchanged due date
// may already be past — the task is simply overdue — but a new one may not.
if ($method === 'POST' && $action === 'update') {
    $m      = requireTeamRole('supervisor');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = getBody();

    $taskId        = (int)($body['task_id'] ?? 0);
    $title         = clean($body['title'] ?? '');
    $description   = clean($body['description'] ?? '');
    $location_text = clean($body['location_text'] ?? '');
    requireMaxLength(['Title' => [$title, 200], 'Location' => [$location_text, 255]]);
    $due_date      = clean($body['due_date'] ?? '');

    if (!$taskId || !$title || !$location_text) {
        json_response(false, 'Title and location are required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM InspectionTasks WHERE task_id = ? AND team_id = ?');
    $stmt->execute([$taskId, $teamId]);
    $t = $stmt->fetch();
    if (!$t) {
        json_response(false, 'Task not found.', [], 404);
    }
    if ($t['status'] !== 'assigned') {
        json_response(false, 'Only a task that has not been inspected yet can be edited.', [], 409);
    }
    if ($due_date !== '' && $due_date !== (string)$t['due_date']) {
        $d = DateTime::createFromFormat('Y-m-d', $due_date);
        if (!$d || $d->format('Y-m-d') !== $due_date) {
            json_response(false, 'Enter a valid date.', [], 400);
        }
        $past = getDB()->prepare('SELECT CAST(? AS DATE) < CURDATE()');
        $past->execute([$due_date]);
        if ((int)$past->fetchColumn() === 1) {
            json_response(false, 'The date must be today or a later date.', [], 400);
        }
    }

    $db->prepare(
        'UPDATE InspectionTasks
            SET due_reminder_sent_at = IF(due_date <=> ?, due_reminder_sent_at, NULL),
                title = ?, description = ?, location_text = ?, due_date = ?
          WHERE task_id = ? AND team_id = ?'
    )->execute([$due_date ?: null, $title, $description, $location_text, $due_date ?: null, $taskId, $teamId]);

    notifyUsers([(int)$t['assigned_to']], $teamId, "Task updated: {$t['task_code']}",
        "{$actor['name']} changed the details of your inspection task.", [
            'Task'         => "{$t['task_code']} · $title",
            'Location'     => $location_text,
            'Due'          => notifyDate($due_date ?: null),
            'Instructions' => $description,
        ]);

    logActivity($actor['user_id'], 'update_task', 'task', $taskId,
        "{$actor['name']} edited task {$t['task_code']}.", $teamId);

    json_response(true, "Task {$t['task_code']} updated.");
}

// ── POST ?action=cancel ──────────────────────────────────────
// Body: { task_id, reason }
//
// For a task that is no longer needed. Only before it is inspected, and
// with a reason, which goes into the activity log.
if ($method === 'POST' && $action === 'cancel') {
    $m      = requireTeamRole('supervisor');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = getBody();
    $taskId = (int)($body['task_id'] ?? 0);
    $reason = clean($body['reason'] ?? '');

    if (!$taskId) {
        json_response(false, 'Task is required.', [], 400);
    }
    if ($reason === '') {
        json_response(false, 'Say why the task is being cancelled.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM InspectionTasks WHERE task_id = ? AND team_id = ?');
    $stmt->execute([$taskId, $teamId]);
    $t = $stmt->fetch();
    if (!$t) {
        json_response(false, 'Task not found.', [], 404);
    }
    if ($t['status'] !== 'assigned') {
        json_response(false, 'Only a task that has not been inspected yet can be cancelled.', [], 409);
    }

    $db->prepare('UPDATE InspectionTasks SET status = "cancelled" WHERE task_id = ? AND team_id = ?')
       ->execute([$taskId, $teamId]);

    notifyUsers([(int)$t['assigned_to']], $teamId, "Task cancelled: {$t['task_code']}",
        "{$actor['name']} cancelled this inspection task. You no longer need to inspect it.", [
            'Task'   => "{$t['task_code']} · {$t['title']}",
            'Reason' => $reason,
        ]);

    logActivity($actor['user_id'], 'cancel_task', 'task', $taskId,
        "{$actor['name']} cancelled task {$t['task_code']}: $reason", $teamId);

    json_response(true, "Task {$t['task_code']} cancelled.");
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
    // Closed without a work order means no repair is needed: the report is
    // closed with it (shown as "Closed") instead of waiting in Pending.
    $db->prepare('UPDATE InspectionReports SET status = "rejected" WHERE task_id = ? AND team_id = ? AND status = "pending"')
       ->execute([$taskId, $teamId]);

    logActivity($actor['user_id'], 'close_task', 'task', $taskId,
        "{$actor['name']} reviewed and closed task {$t['task_code']}.", $teamId);

    json_response(true, 'Task closed successfully.');
}

json_response(false, 'Invalid action.', [], 400);
