<?php
// ConstructFlow — Authentication API
// Handles: login, logout, session check

require_once __DIR__ . '/../config/helpers.php';
setCORSHeaders();
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET /api/auth.php?action=me ───────────────────────────────
// Returns current logged-in user info.
if ($method === 'GET' && $action === 'me') {
    $user = currentUser();
    if ($user) {
        json_response(true, 'Authenticated.', ['user' => $user]);
    } else {
        json_response(false, 'Not authenticated.', [], 401);
    }
}

// ── POST /api/auth.php?action=login ──────────────────────────
// Body: { email, password }
if ($method === 'POST' && $action === 'login') {
    $body  = getBody();
    $email = sanitize($body['email'] ?? '');
    $pass  = $body['password'] ?? '';
    // Optional: which portal the person is signing into (e.g. the login
    // page's "System Access Role" selector, sent as one of ALLOWED_ROLES).
    // Present whenever the UI exposes separate role portals.
    $requestedRole = $body['requested_role'] ?? '';

    if (!$email || !$pass) {
        json_response(false, 'Email and password are required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM Users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($pass, $user['password_hash'])) {
        logActivity(null, 'login_failed', 'user', null, "Failed login attempt for: $email");
        json_response(false, 'Invalid email or password.', [], 401);
    }

    // Defense in depth: never trust that a role stored on the account is
    // one of the roles the system actually knows how to authorize. If the
    // stored role is missing/unexpected, refuse to establish a session
    // rather than letting an unrecognized role silently fall through any
    // downstream role check.
    if (!isValidRole($user['role'])) {
        logActivity($user['user_id'], 'login_failed', 'user', $user['user_id'], "Login blocked for {$user['name']}: invalid role '{$user['role']}'.");
        json_response(false, 'This account has an invalid role configuration. Contact an administrator.', [], 403);
    }

    // If the client told us which portal it's authenticating into (e.g. an
    // "Admin Login" screen), the authenticated account's actual role must
    // match it. This must be enforced here, server side, before a session
    // is created — a client-side redirect after login is not sufficient,
    // since it can be skipped or tampered with.
    if ($requestedRole !== '' && $requestedRole !== $user['role']) {
        logActivity($user['user_id'], 'login_failed', 'user', $user['user_id'], "{$user['name']} attempted to log into the '$requestedRole' portal but is a '{$user['role']}'.");
        json_response(false, 'Your account is not authorized to access this portal.', [], 403);
    }

    // Store user in session (never store password hash)
    $_SESSION['user'] = [
        'user_id' => $user['user_id'],
        'name'    => $user['name'],
        'email'   => $user['email'],
        'role'    => $user['role'],
    ];

    logActivity($user['user_id'], 'login', 'user', $user['user_id'], "{$user['name']} logged in.");

    json_response(true, 'Login successful.', ['user' => $_SESSION['user']]);
}

// ── POST /api/auth.php?action=logout ─────────────────────────
if ($method === 'POST' && $action === 'logout') {
    $user = currentUser();
    if ($user) {
        logActivity($user['user_id'], 'logout', 'user', $user['user_id'], "{$user['name']} logged out.");
    }
    session_destroy();
    json_response(true, 'Logged out successfully.');
}

// ── POST /api/auth.php?action=change_password ─────────────────
// Body: { current_password, new_password }
if ($method === 'POST' && $action === 'change_password') {
    $user = requireAuth();
    $body = getBody();
    $curr = $body['current_password'] ?? '';
    $new  = $body['new_password'] ?? '';

    if (!$curr || !$new) {
        json_response(false, 'Both current and new password are required.', [], 400);
    }
    if (strlen($new) < 8) {
        json_response(false, 'New password must be at least 8 characters.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT password_hash FROM Users WHERE user_id = ?');
    $stmt->execute([$user['user_id']]);
    $row  = $stmt->fetch();

    if (!$row || !password_verify($curr, $row['password_hash'])) {
        json_response(false, 'Current password is incorrect.', [], 401);
    }

    $hash = password_hash($new, PASSWORD_BCRYPT);
    $db->prepare('UPDATE Users SET password_hash = ? WHERE user_id = ?')
       ->execute([$hash, $user['user_id']]);

    logActivity($user['user_id'], 'change_password', 'user', $user['user_id'], "{$user['name']} changed their password.");
    json_response(true, 'Password changed successfully.');
}

json_response(false, 'Invalid action.', [], 400);
