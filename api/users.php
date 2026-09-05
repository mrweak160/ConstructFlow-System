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
if ($method === 'GET' && $action === 'list') {
    $m      = requireTeam();
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    $roleFilter = $_GET['role']   ?? '';
    $search     = clean($_GET['search'] ?? '');

    $where  = ['tm.team_id = ?', 'tm.status = "active"', 'u.is_active = 1'];
    $params = [$teamId];

    if ($roleFilter && isValidTeamRole($roleFilter)) {
        $where[]  = 'tm.role = ?';
        $params[] = $roleFilter;
    }
    if ($search) {
        $where[]  = '(u.name LIKE ? OR u.email LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT u.user_id, u.name, u.email, u.gender,
                tm.role, tm.status, tm.joined_at, u.is_active
         FROM TeamMembers tm
         JOIN Users u ON tm.user_id = u.user_id
         $whereSQL
         ORDER BY FIELD(tm.role,'administrator','supervisor','field_inspector','field_worker','member'), u.name"
    );
    $stmt->execute($params);

    json_response(true, 'Users retrieved.', [
        'users'   => $stmt->fetchAll(),
        'my_role' => $m['role'],
    ]);
}

// ── POST ?action=self_edit ───────────────────────────────────
if ($method === 'POST' && $action === 'self_edit') {
    $user = requireAuth();
    $name = clean(getBody()['name'] ?? '');

    if (!$name) {
        json_response(false, 'Name cannot be empty.', [], 400);
    }
    if (mb_strlen($name) > 100) {
        json_response(false, 'Name is too long.', [], 400);
    }

    getDB()->prepare('UPDATE Users SET name = ? WHERE user_id = ?')
           ->execute([$name, $user['user_id']]);

    logActivity($user['user_id'], 'self_edit', 'user', $user['user_id'],
        "{$user['name']} changed their display name to '$name'.");

    json_response(true, 'Name updated successfully.', ['name' => $name]);
}

json_response(false, 'Invalid action.', [], 400);
