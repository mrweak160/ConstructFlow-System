<?php
// ConstructFlow — Users API
// Handles: list users, create user, edit user, deactivate user
// All endpoints require Administrator role except /me

require_once __DIR__ . '/../config/helpers.php';
setCORSHeaders();
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET /api/users.php?action=list ───────────────────────────
// Returns all users. Admins see all; others see only field workers (for assign dropdown).
if ($method === 'GET' && $action === 'list') {
    $user   = requireAuth();
    $db     = getDB();
    $role   = $_GET['role'] ?? '';
    $search = sanitize($_GET['search'] ?? '');

    $where  = [];
    $params = [];

    // Non-admins can only see field workers (for the assign dropdown)
    if ($user['role'] === 'supervisor') {
        $where[]  = 'role IN ("field_inspector","field_worker")';
        $where[]  = 'is_active = 1';
    } elseif ($user['role'] !== 'administrator') {
        $where[]  = 'role = "field_worker"';
        $where[]  = 'is_active = 1';
    }
    if ($role) {
        $where[]  = 'role = ?';
        $params[] = $role;
    }
    if ($search) {
        $where[]  = '(name LIKE ? OR email LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $db->prepare(
        "SELECT user_id, name, email, role, is_active, created_at
         FROM Users $whereSQL ORDER BY created_at DESC"
    );
    $stmt->execute($params);

    json_response(true, 'Users retrieved.', ['users' => $stmt->fetchAll()]);
}

// ── POST /api/users.php?action=create ────────────────────────
// Administrator creates a new user.
// Body: { name, email, password, role }
if ($method === 'POST' && $action === 'create') {
    requireRole('administrator');
    $body = getBody();

    $name  = sanitize($body['name']  ?? '');
    $email = sanitize($body['email'] ?? '');
    $pass  = $body['password'] ?? '';
    $role  = in_array($body['role'] ?? '', ['field_inspector','supervisor','field_worker','administrator'])
             ? $body['role'] : null;

    if (!$name || !$email || !$pass || !$role) {
        json_response(false, 'Name, email, password, and role are required.', [], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, 'Invalid email format.', [], 400);
    }
    if (strlen($pass) < 8) {
        json_response(false, 'Password must be at least 8 characters.', [], 400);
    }

    $db = getDB();

    // Check for duplicate email
    $check = $db->prepare('SELECT user_id FROM Users WHERE email = ?');
    $check->execute([$email]);
    if ($check->fetch()) {
        json_response(false, 'A user with this email already exists.', [], 409);
    }

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $stmt = $db->prepare(
        'INSERT INTO Users (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$name, $email, $hash, $role]);
    $newId = (int)$db->lastInsertId();

    $admin = currentUser();
    logActivity($admin['user_id'], 'create_user', 'user', $newId, "{$admin['name']} created user '$name' ($role).");

    json_response(true, 'User created successfully.', ['user_id' => $newId]);
}

// ── POST /api/users.php?action=edit ──────────────────────────
// Administrator edits an existing user.
// Body: { user_id, name, email, role, is_active }
if ($method === 'POST' && $action === 'edit') {
    $admin = requireRole('administrator');
    $body  = getBody();

    $userId   = (int)($body['user_id']  ?? 0);
    $name     = sanitize($body['name']  ?? '');
    $email    = sanitize($body['email'] ?? '');
    $role     = in_array($body['role'] ?? '', ['field_inspector','supervisor','field_worker','administrator'])
                ? $body['role'] : null;
    $isActive = isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1;

    if (!$userId || !$name || !$email || !$role) {
        json_response(false, 'User ID, name, email, and role are required.', [], 400);
    }

    $db = getDB();

    // Prevent admin from deactivating themselves
    if ($userId === $admin['user_id'] && !$isActive) {
        json_response(false, 'You cannot deactivate your own account.', [], 400);
    }

    $db->prepare(
        'UPDATE Users SET name = ?, email = ?, role = ?, is_active = ? WHERE user_id = ?'
    )->execute([$name, $email, $role, $isActive, $userId]);

    logActivity($admin['user_id'], 'edit_user', 'user', $userId, "{$admin['name']} edited user ID $userId.");
    json_response(true, 'User updated successfully.');
}

// ── POST /api/users.php?action=reset_password ─────────────────
// Administrator resets a user's password.
// Body: { user_id, new_password }
if ($method === 'POST' && $action === 'reset_password') {
    $admin = requireRole('administrator');
    $body  = getBody();

    $userId  = (int)($body['user_id']      ?? 0);
    $newPass = $body['new_password'] ?? '';

    if (!$userId || strlen($newPass) < 8) {
        json_response(false, 'User ID and a password of at least 8 characters are required.', [], 400);
    }

    $hash = password_hash($newPass, PASSWORD_BCRYPT);
    getDB()->prepare('UPDATE Users SET password_hash = ? WHERE user_id = ?')
           ->execute([$hash, $userId]);

    logActivity($admin['user_id'], 'reset_password', 'user', $userId, "{$admin['name']} reset password for user ID $userId.");
    json_response(true, 'Password reset successfully.');
}

// ── POST /api/users.php?action=self_edit ──────────────────────
// Any logged-in user updates their OWN name. No role or email
// change allowed here — that stays admin-only via ?action=edit.
// Body: { name }
if ($method === 'POST' && $action === 'self_edit') {
    $user = requireAuth();
    $body = getBody();

    $name = sanitize($body['name'] ?? '');

    if (!$name) {
        json_response(false, 'Name cannot be empty.', [], 400);
    }

    $db = getDB();
    $db->prepare('UPDATE Users SET name = ? WHERE user_id = ?')
       ->execute([$name, $user['user_id']]);

    logActivity($user['user_id'], 'self_edit', 'user', $user['user_id'], "{$user['name']} updated their own profile name to '$name'.");

    json_response(true, 'Name updated successfully.', ['name' => $name]);
}

json_response(false, 'Invalid action.', [], 400);
