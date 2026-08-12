<?php
// ConstructFlow — Inspection Tasks API
// Handles: create task, list tasks, get single task

require_once __DIR__ . '/../config/helpers.php';
setCORSHeaders();
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── POST /api/tasks.php?action=create ──────────────────────────
// Supervisor creates an inspection task and assigns it to a
// specific Field Inspector. A Supervisor may assign multiple
// tasks to the same Inspector — no restriction on open task count.
// Body: { assigned_to, title, description, location_text, due_date }
if ($method === 'POST' && $action === 'create') {
    $user = requireRole('supervisor');
    $body = getBody();

    $assignedTo    = (int)($body['assigned_to'] ?? 0);
    $title         = sanitize($body['title'] ?? '');
    $description   = sanitize($body['description'] ?? '');
    $location_text = sanitize($body['location_text'] ?? '');
    $due_date      = sanitize($body['due_date'] ?? '');

    if (!$assignedTo || !$title || !$location_text) {
        json_response(false, 'Assigned inspector, title, and location are required.', [], 400);
    }

    $db = getDB();

    // Verify the assigned user is an active field inspector
    $inspector = $db->prepare('SELECT * FROM Users WHERE user_id = ? AND role = "field_inspector" AND is_active = 1');
    $inspector->execute([$assignedTo]);
    if (!$inspector->fetch()) {
        json_response(false, 'Assigned user is not an active field inspector.', [], 400);
    }

    $code = generateTaskCode();

    $stmt = $db->prepare(
        'INSERT INTO InspectionTasks
         (task_code, created_by, assigned_to, title, description, location_text, due_date)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $code,
        $user['user_id'],
        $assignedTo,
        $title,
        $description,
        $location_text,
        $due_date ?: null,
    ]);
    $taskId = (int)$db->lastInsertId();

    logActivity(
        $user['user_id'], 'create_task',
        'task', $taskId,
        "{$user['name']} created and assigned inspection task $code."
    );

    json_response(true, 'Inspection task created and assigned successfully.', [
        'task_id'   => $taskId,
        'task_code' => $code,
    ]);
}

// ── GET /api/tasks.php?action=list ──────────────────────────────
// Returns tasks based on role:
//   field_inspector → only tasks assigned to them
//   supervisor / administrator → all tasks
// Optional filter: ?status=assigned
if ($method === 'GET' && $action === 'list') {
    $user   = requireAuth();
    $db     = getDB();
    $status = $_GET['status'] ?? '';

    $where  = [];
    $params = [];

    if ($user['role'] === 'field_inspector') {
        $where[]  = 't.assigned_to = ?';
        $params[] = $user['user_id'];
    }
    if ($status) {
        $where[]  = 't.status = ?';
        $params[] = $status;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

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

// ── GET /api/tasks.php?action=detail&id=1 ───────────────────────
// Returns a single task with any linked report.
if ($method === 'GET' && $action === 'detail') {
    $user   = requireAuth();
    $taskId = (int)($_GET['id'] ?? 0);
    $db     = getDB();

    $stmt = $db->prepare(
        'SELECT t.*,
                u_creator.name  AS created_by_name,
                u_assignee.name AS assigned_to_name
         FROM InspectionTasks t
         JOIN Users u_creator  ON t.created_by  = u_creator.user_id
         JOIN Users u_assignee ON t.assigned_to = u_assignee.user_id
         WHERE t.task_id = ?'
    );
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();

    if (!$task) {
        json_response(false, 'Task not found.', [], 404);
    }

    // Field Inspector can only view their own assigned tasks
    if ($user['role'] === 'field_inspector' && $task['assigned_to'] != $user['user_id']) {
        json_response(false, 'Access denied.', [], 403);
    }

    $report = $db->prepare('SELECT * FROM InspectionReports WHERE task_id = ?');
    $report->execute([$taskId]);

    json_response(true, 'Task detail retrieved.', [
        'task'   => $task,
        'report' => $report->fetch() ?: null,
    ]);
}

// ── POST /api/tasks.php?action=close ─────────────────────────
// Supervisor reviews the submitted report and closes the task.
if ($method === 'POST' && $action === 'close') {
    $user = requireRole('supervisor');
    $body = getBody();
    $taskId = (int)($body['task_id'] ?? 0);

    if (!$taskId) {
        json_response(false, 'Task ID is required.', [], 400);
    }

    $db = getDB();
    $task = $db->prepare('SELECT * FROM InspectionTasks WHERE task_id = ?');
    $task->execute([$taskId]);
    $t = $task->fetch();

    if (!$t) {
        json_response(false, 'Task not found.', [], 404);
    }
    if ($t['status'] !== 'submitted') {
        json_response(false, 'Only submitted tasks can be closed.', [], 400);
    }

    $db->prepare('UPDATE InspectionTasks SET status = "closed" WHERE task_id = ?')
       ->execute([$taskId]);

    logActivity($user['user_id'], 'close_task', 'task', $taskId, "{$user['name']} reviewed and closed task {$t['task_code']}.");

    json_response(true, 'Task closed successfully.');
}

json_response(false, 'Invalid action.', [], 400);