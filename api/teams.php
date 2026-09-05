<?php
// ConstructFlow — Teams API

require_once __DIR__ . '/../config/helpers.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Every POST here is authenticated, so all of them require a token.
if ($method === 'POST') {
    verifyCsrf();
}

function assignableBy(string $actorRole): array {
    return match ($actorRole) {
        'administrator' => ['administrator', 'supervisor', 'field_inspector', 'field_worker', 'member'],
        default         => [],
    };
}

// ── POST ?action=create ──────────────────────────────────────
// Body: { name, description }
//
if ($method === 'POST' && $action === 'create') {
    $user = requireOwnerAccount();
    $body = getBody();
    $name = clean($body['name'] ?? '');
    $desc = clean($body['description'] ?? '');

    if (!$name) {
        json_response(false, 'Team name is required.', [], 400);
    }
    if (mb_strlen($name) > 150) {
        json_response(false, 'Team name is too long.', [], 400);
    }

    $db = getDB();
    $db->beginTransaction();
    try {
        $code = generateJoinCode();

        $db->prepare('INSERT INTO Teams (name, description, join_code, owner_id) VALUES (?, ?, ?, ?)')
           ->execute([$name, $desc ?: null, $code, $user['user_id']]);
        $teamId = (int)$db->lastInsertId();

        // The creator is the administrator, active immediately
        $db->prepare(
            'INSERT INTO TeamMembers (team_id, user_id, role, status, joined_at, assigned_at, assigned_by)
             VALUES (?, ?, "administrator", "active", NOW(), NOW(), ?)'
        )->execute([$teamId, $user['user_id'], $user['user_id']]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, 'Could not create the team. Please try again.', [], 500);
    }

    $_SESSION['active_team_id'] = $teamId;
    logActivity($user['user_id'], 'create_team', 'team', $teamId, "{$user['name']} created team '$name'.");

    json_response(true, 'Team created.', [
        'team_id'   => $teamId,
        'team_name' => $name,
        'role'      => 'administrator',
    ]);
}

// ════════════════════════════════════════════════════════════
// INVITATIONS
// ── POST ?action=invite ──────────────────────────────────────
// Body: { first_name, last_name, email, birthdate, role }
if ($method === 'POST' && $action === 'invite') {
    $m     = requireTeamRole('administrator');
    $actor = currentUser();
    $body  = getBody();
    $first = clean($body['first_name'] ?? '');
    $last  = clean($body['last_name']  ?? '');
    $email = strtolower(clean($body['email'] ?? ''));
    $birth = clean($body['birthdate']  ?? '');
    $role  = clean($body['role'] ?? '');

    if (!$first || !$last) {
        json_response(false, "Enter the member's first and last name.", [], 400);
    }
    if (mb_strlen($first) > 60 || mb_strlen($last) > 60) {
        json_response(false, 'Names are too long (60 characters max each).', [], 400);
    }
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, 'Enter a valid email address.', [], 400);
    }

    $parts = explode('-', $birth);
    if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        json_response(false, 'Enter a valid birthdate.', [], 400);
    }
    if ($birth >= date('Y-m-d')) {
        json_response(false, 'Birthdate must be in the past.', [], 400);
    }
    if ($birth > date('Y-m-d', strtotime('-15 years'))) {
        json_response(false, 'Team members must be at least 15 years old.', [], 400);
    }

    if (!in_array($role, assignableBy($m['role']), true)) {
        json_response(false, 'You cannot invite someone as that role.', [], 403);
    }
    $db = getDB();

    // Already on this team?
    $existing = $db->prepare(
        'SELECT tm.status, u.name FROM TeamMembers tm
         JOIN Users u ON tm.user_id = u.user_id
         WHERE tm.team_id = ? AND u.email = ? LIMIT 1'
    );
    $existing->execute([$m['team_id'], $email]);
    if ($prior = $existing->fetch()) {
        if ($prior['status'] === 'active') {
            json_response(false, "This email is already a member of this team.", [], 409);
        }
    }
    
    $live = $db->prepare(
        'SELECT invitation_id, last_sent_at, resend_count FROM Invitations
         WHERE team_id = ? AND email = ? AND accepted_at IS NULL AND revoked_at IS NULL
         ORDER BY invitation_id DESC LIMIT 1'
    );
    $live->execute([$m['team_id'], $email]);
    if ($old = $live->fetch()) {
        if (strtotime($old['last_sent_at']) > time() - INVITE_RESEND_SECONDS) {
            json_response(false, 'An invitation was just sent to that address. Wait a minute before sending another.', [], 429);
        }
        $db->prepare('UPDATE Invitations SET revoked_at = NOW() WHERE invitation_id = ?')
           ->execute([$old['invitation_id']]);
    }

        $fullName = $first . ' ' . $last;

    $token = generateInviteToken();
    $db->prepare(
        'INSERT INTO Invitations
         (team_id, email, first_name, last_name, birthdate, role, token_hash, invited_by, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))'
    )->execute([
        $m['team_id'], $email, $first, $last, $birth, $role,
        hashInviteToken($token), $actor['user_id'], INVITE_TTL_HOURS,
    ]);
    $inviteId = (int)$db->lastInsertId();

    $known = $db->prepare('SELECT name FROM Users WHERE email = ? LIMIT 1');
    $known->execute([$email]);
    $existingName = $known->fetchColumn();

    sendMail(
        $email,
        $existingName ?: $fullName,
        "You've been invited to {$m['team_name']} — ConstructFlow",
        inviteEmailBody($actor['name'], $m['team_name'], roleLabel($role), inviteAcceptUrl($token), (bool)$existingName)
    );

    logActivity($actor['user_id'], 'invite_sent', 'invitation', $inviteId,
        "{$actor['name']} invited $fullName ($email) as " . roleLabel($role) . '.');

    json_response(true, "Invitation sent to $email.", [
        'invitation_id' => $inviteId,
        'email'         => $email,
        'role'          => $role,
    ]);
}

// ── GET ?action=invites ──────────────────────────────────────
if ($method === 'GET' && $action === 'invites') {
    $m  = requireTeamRole('administrator');
    $db = getDB();

    $stmt = $db->prepare(
        'SELECT i.invitation_id, i.email, i.role, i.created_at, i.expires_at,
                (i.expires_at <= NOW()) AS is_expired,
                u.name AS invited_by_name
         FROM Invitations i
         JOIN Users u ON i.invited_by = u.user_id
         WHERE i.team_id = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL
         ORDER BY i.created_at DESC'
    );
    $stmt->execute([$m['team_id']]);

    json_response(true, 'Invitations retrieved.', ['invitations' => $stmt->fetchAll()]);
}

// ── POST ?action=resend_invite ───────────────────────────────
if ($method === 'POST' && $action === 'resend_invite') {
    $m     = requireTeamRole('administrator');
    $actor = currentUser();
    $id    = (int)(getBody()['invitation_id'] ?? 0);

    if (!$id) {
        json_response(false, 'Invitation is required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM Invitations
         WHERE invitation_id = ? AND team_id = ? AND accepted_at IS NULL AND revoked_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$id, $m['team_id']]);
    $inv = $stmt->fetch();

    if (!$inv) {
        json_response(false, 'That invitation no longer exists.', [], 404);
    }
    if (strtotime($inv['last_sent_at']) > time() - INVITE_RESEND_SECONDS) {
        json_response(false, 'That invitation was just sent. Wait a minute before resending.', [], 429);
    }
    if ((int)$inv['resend_count'] >= INVITE_MAX_RESENDS) {
        json_response(false, 'This invitation has been resent too many times. Revoke it and send a new one.', [], 429);
    }

    $token = generateInviteToken();
    $db->prepare(
        'UPDATE Invitations
         SET token_hash = ?, expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR),
             last_sent_at = NOW(), resend_count = resend_count + 1
         WHERE invitation_id = ?'
    )->execute([hashInviteToken($token), INVITE_TTL_HOURS, $id]);

    $known = $db->prepare('SELECT name FROM Users WHERE email = ? LIMIT 1');
    $known->execute([$inv['email']]);
    $existingName = $known->fetchColumn();

    sendMail(
        $inv['email'],
        $existingName ?: $inv['email'],
        "Your invitation to {$m['team_name']} — ConstructFlow",
        inviteEmailBody($actor['name'], $m['team_name'], roleLabel($inv['role']), inviteAcceptUrl($token), (bool)$existingName)
    );

    logActivity($actor['user_id'], 'invite_resent', 'invitation', $id,
        "{$actor['name']} resent the invitation to {$inv['email']}.");

    json_response(true, "Invitation resent to {$inv['email']}.");
}

// ── POST ?action=revoke_invite ───────────────────────────────
// Body: { invitation_id }
if ($method === 'POST' && $action === 'revoke_invite') {
    $m     = requireTeamRole('administrator');
    $actor = currentUser();
    $id    = (int)(getBody()['invitation_id'] ?? 0);

    if (!$id) {
        json_response(false, 'Invitation is required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT email FROM Invitations
         WHERE invitation_id = ? AND team_id = ? AND accepted_at IS NULL AND revoked_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$id, $m['team_id']]);
    $email = $stmt->fetchColumn();

    if (!$email) {
        json_response(false, 'That invitation no longer exists.', [], 404);
    }

    $db->prepare('UPDATE Invitations SET revoked_at = NOW() WHERE invitation_id = ?')->execute([$id]);

    logActivity($actor['user_id'], 'invite_revoked', 'invitation', $id,
        "{$actor['name']} revoked the invitation to $email.");

    json_response(true, "Invitation to $email cancelled. The link no longer works.");
}

// ── GET ?action=my_teams ─────────────────────────────────────
if ($method === 'GET' && $action === 'my_teams') {
    $user = requireAuth();
    json_response(true, 'Teams retrieved.', ['teams' => userMemberships($user['user_id'])]);
}

// ── GET ?action=detail ───────────────────────────────────────
if ($method === 'GET' && $action === 'detail') {
    $m  = requireTeam();
    $db = getDB();

    $stmt = $db->prepare(
        'SELECT t.team_id, t.name, t.description, t.owner_id, t.created_at,
                u.name AS owner_name,
                (SELECT COUNT(*) FROM TeamMembers WHERE team_id = t.team_id AND status = "active")  AS member_count,
                (SELECT COUNT(*) FROM Invitations
                  WHERE team_id = t.team_id AND accepted_at IS NULL
                    AND revoked_at IS NULL AND expires_at > NOW())  AS pending_invites
         FROM Teams t JOIN Users u ON t.owner_id = u.user_id
         WHERE t.team_id = ?'
    );
    $stmt->execute([$m['team_id']]);
    $team = $stmt->fetch();

    $team['admin_count'] = countAdmins((int)$m['team_id']);
    $team['can_rename']  = $m['role'] === 'administrator';

    json_response(true, 'Team detail retrieved.', ['team' => $team, 'my_role' => $m['role']]);
}

// ── GET ?action=members ──────────────────────────────────────
if ($method === 'GET' && $action === 'members') {
    $m  = requireTeam();
    $db = getDB();

    $stmt = $db->prepare(
        'SELECT tm.membership_id, tm.user_id, tm.role, tm.status, tm.joined_at,
                u.name, u.email
         FROM TeamMembers tm
         JOIN Users u ON tm.user_id = u.user_id
         WHERE tm.team_id = ? AND tm.status = "active"
         ORDER BY FIELD(tm.role,"administrator","supervisor","field_inspector","field_worker","member"), u.name'
    );
    $stmt->execute([$m['team_id']]);

    $adminCount = countAdmins((int)$m['team_id']);

        json_response(true, 'Members retrieved.', [
        'members'     => $stmt->fetchAll(),
        'my_role'     => $m['role'],
        'assignable'  => assignableBy($m['role']),
        'admin_count' => $adminCount,
    ]);
}

// ── POST ?action=assign_role ─────────────────────────────────
if ($method === 'POST' && $action === 'assign_role') {
    $m    = requireTeamRole('administrator');
    $body = getBody();
    $userId  = (int)($body['user_id'] ?? 0);
    $newRole = clean($body['role'] ?? '');
    $actor   = currentUser();

    if (!$userId || !$newRole) {
        json_response(false, 'User and role are required.', [], 400);
    }

    if (!in_array($newRole, assignableBy($m['role']), true)) {
        json_response(false, 'That is not a role you can assign.', [], 403);
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT tm.role AS member_role, u.name
         FROM TeamMembers tm
         JOIN Users u ON tm.user_id = u.user_id
         WHERE tm.team_id = ? AND tm.user_id = ? AND tm.status = "active" LIMIT 1'
    );
    $stmt->execute([$m['team_id'], $userId]);
    $target = $stmt->fetch();

    if (!$target) {
        json_response(false, 'That person is not an active member of this team.', [], 404);
    }
    if ($target['member_role'] === $newRole) {
        json_response(false, "{$target['name']} already has that role.", [], 400);
    }

    if ($target['member_role'] === 'administrator') {
        json_response(false,
            $userId === $actor['user_id']
                ? 'You cannot change your own role. Another administrator has to remove you from the team.'
                : "Another administrator's role cannot be changed. They have to be removed by an administrator instead.",
            [], 403);
    }
    assertNotLastAdmin((int)$m['team_id'], $userId);


    $open = $db->prepare("SELECT COUNT(*) FROM WorkOrders WHERE assigned_to = ? AND status <> 'completed'");
    $open->execute([$userId]);
    $openCount = (int)$open->fetchColumn();

    if ($openCount > 0 && $target['member_role'] === 'field_worker') {
        json_response(false,
            "{$target['name']} still has $openCount unfinished work order" . ($openCount === 1 ? '' : 's') .
            '. Have a supervisor reassign them before changing this role.',
            ['open_work_orders' => $openCount], 409);
    }

    $db->prepare(
        'UPDATE TeamMembers SET role = ?, assigned_by = ?, assigned_at = NOW()
         WHERE team_id = ? AND user_id = ?'
    )->execute([$newRole, $actor['user_id'], $m['team_id'], $userId]);

    logActivity($actor['user_id'], 'assign_role', 'team', (int)$m['team_id'],
        "{$actor['name']} set {$target['name']} as $newRole.");

    json_response(true, "{$target['name']} is now a " . str_replace('_', ' ', $newRole) . '.', [
        'admin_count' => countAdmins((int)$m['team_id']),
    ]);
}

// ── POST ?action=remove_member ───────────────────────────────
// Body: { user_id }
if ($method === 'POST' && $action === 'remove_member') {
    $m      = requireTeamRole('administrator');
    $userId = (int)(getBody()['user_id'] ?? 0);
    $actor  = currentUser();

    if (!$userId) {
        json_response(false, 'User is required.', [], 400);
    }
    if ($userId === $actor['user_id']) {
        json_response(false, 'Use "Leave team" to remove yourself.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT tm.role, u.name FROM TeamMembers tm
         JOIN Users u ON tm.user_id = u.user_id
         WHERE tm.team_id = ? AND tm.user_id = ? AND tm.status = "active" LIMIT 1'
    );
    $stmt->execute([$m['team_id'], $userId]);
    $target = $stmt->fetch();

    if (!$target) {
        json_response(false, 'That person is not an active member of this team.', [], 404);
    }

    assertNotLastAdmin((int)$m['team_id'], $userId);

    $open = $db->prepare(
        "SELECT COUNT(*) FROM WorkOrders WHERE assigned_to = ? AND status <> 'completed'"
    );
    $open->execute([$userId]);
    $openCount = (int)$open->fetchColumn();

    if ($openCount > 0) {
        json_response(false,
            "{$target['name']} still has $openCount unfinished work order" . ($openCount === 1 ? '' : 's') .
            '. Reassign them first, then remove this member.', ['open_work_orders' => $openCount], 409);
    }

    $db->prepare('UPDATE TeamMembers SET status = "removed" WHERE team_id = ? AND user_id = ?')
       ->execute([$m['team_id'], $userId]);

    logActivity($actor['user_id'], 'remove_member', 'team', (int)$m['team_id'],
        "{$actor['name']} removed {$target['name']} from the team.");

    json_response(true, "{$target['name']} has been removed from the team.");
}

// ── POST ?action=update_team ─────────────────────────────────
if ($method === 'POST' && $action === 'update_team') {
    $m     = requireTeamRole('administrator');
    $actor = currentUser();
    $body  = getBody();
    $name  = clean($body['name'] ?? '');
    $desc  = clean($body['description'] ?? '');

    if (!$name) {
        json_response(false, 'Team name cannot be empty.', [], 400);
    }
    if (mb_strlen($name) > 150) {
        json_response(false, 'Team name is too long.', [], 400);
    }

    $db  = getDB();
    $old = $db->prepare('SELECT name FROM Teams WHERE team_id = ?');
    $old->execute([$m['team_id']]);
    $oldName = $old->fetchColumn();

    $db->prepare('UPDATE Teams SET name = ?, description = ? WHERE team_id = ?')
       ->execute([$name, $desc ?: null, $m['team_id']]);

    logActivity($actor['user_id'], 'update_team', 'team', (int)$m['team_id'],
        "{$actor['name']} renamed the team from '$oldName' to '$name'.");

    json_response(true, 'Team updated.', ['name' => $name]);
}

json_response(false, 'Invalid action.', [], 400);
