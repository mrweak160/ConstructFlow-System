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
    // The pickers that use this are a supervisor's. Field staff have no use
    // for a roster, and it no longer carries email or gender for anyone.
    $m      = requireTeamRole('supervisor', 'administrator');
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
        $where[]  = 'u.name LIKE ?';
        $params[] = "%$search%";
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT u.user_id, u.name, tm.role
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
    $body = getBody();
    $name = capitalizeWords(clean($body['name'] ?? ''));

    // Optional here: accounts made before the field existed have none, and
    // an empty box leaves it unset. Only touched when the form sent it, so
    // a caller that edits the name alone cannot wipe it.
    $hasContact = array_key_exists('contact_number', $body);
    $rawContact = clean($body['contact_number'] ?? '');
    $contact    = $rawContact === '' ? null : normalizeContactNumber($rawContact);
    if ($hasContact && $rawContact !== '' && $contact === null) {
        json_response(false, CONTACT_NUMBER_ERROR, [], 400);
    }

    if (!$name) {
        json_response(false, 'Name cannot be empty.', [], 400);
    }
    if (!nameIsLongEnough($name)) {
        json_response(false, 'Name must be at least 2 characters.', [], 400);
    }
    if (mb_strlen($name) > 100) {
        json_response(false, 'Name is too long.', [], 400);
    }

    if ($hasContact) {
        getDB()->prepare('UPDATE Users SET name = ?, contact_number = ? WHERE user_id = ?')
               ->execute([$name, $contact, $user['user_id']]);
    } else {
        getDB()->prepare('UPDATE Users SET name = ? WHERE user_id = ?')
               ->execute([$name, $user['user_id']]);
    }

    logActivity($user['user_id'], 'self_edit', 'user', $user['user_id'],
        "{$user['name']} changed their display name to '$name'.");

    json_response(true, 'Profile updated.', [
        'name'           => $name,
        'contact_number' => $hasContact ? $contact : ($user['contact_number'] ?? null),
    ]);
}

// ── POST ?action=avatar_upload ───────────────────────────────
// multipart/form-data: avatar (file), csrf_token
//
if ($method === 'POST' && $action === 'avatar_upload') {
    $user = requireAuth();
    $db   = getDB();

    $result = processAvatarUpload('avatar', (int)$user['user_id']);
    if (isset($result['error'])) {
        json_response(false, $result['error'], [], 400);
    }

    $old = $db->prepare('SELECT avatar_path FROM Users WHERE user_id = ?');
    $old->execute([$user['user_id']]);
    $oldPath = $old->fetchColumn() ?: null;

    $db->prepare('UPDATE Users SET avatar_path = ? WHERE user_id = ?')
       ->execute([$result['path'], $user['user_id']]);

    deleteAvatarFile($oldPath);

    logActivity($user['user_id'], 'avatar_upload', 'user', (int)$user['user_id'],
        "{$user['name']} updated their profile photo.");

    json_response(true, 'Profile photo updated.', ['avatar_path' => $result['path']]);
}

// ── POST ?action=avatar_remove ───────────────────────────────
if ($method === 'POST' && $action === 'avatar_remove') {
    $user = requireAuth();
    $db   = getDB();

    $old = $db->prepare('SELECT avatar_path FROM Users WHERE user_id = ?');
    $old->execute([$user['user_id']]);
    $oldPath = $old->fetchColumn() ?: null;

    if (!$oldPath) {
        json_response(true, 'No photo to remove.', ['avatar_path' => null]);
    }

    $db->prepare('UPDATE Users SET avatar_path = NULL WHERE user_id = ?')
       ->execute([$user['user_id']]);

    deleteAvatarFile($oldPath);

    logActivity($user['user_id'], 'avatar_remove', 'user', (int)$user['user_id'],
        "{$user['name']} removed their profile photo.");

    json_response(true, 'Profile photo removed.', ['avatar_path' => null]);
}

json_response(false, 'Invalid action.', [], 400);
