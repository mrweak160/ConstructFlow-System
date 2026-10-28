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

/** Who created the team. Their role is fixed and they cannot be removed. */
function teamOwnerId(int $teamId): int {
    $st = getDB()->prepare('SELECT owner_id FROM Teams WHERE team_id = ?');
    $st->execute([$teamId]);
    return (int)$st->fetchColumn();
}

function assignableBy(string $actorRole): array {
    return match ($actorRole) {
        'administrator' => ['administrator', 'supervisor', 'field_inspector', 'field_worker', 'member'],
        default         => [],
    };
}

/**
 * Stops an inspector leaving, or losing the role, while work is still
 * waiting on them: a task they have not inspected, or a site verification
 * they were named for. Only they could file that report or sign that
 * check, so either would be stranded. A supervisor reassigns them first.
 */
function assertNoOpenInspection(int $teamId, int $userId, string $name, string $doing): void {
    $db = getDB();
    $t  = $db->prepare('SELECT COUNT(*) FROM InspectionTasks WHERE team_id = ? AND assigned_to = ? AND status = "assigned"');
    $t->execute([$teamId, $userId]);
    $tasks = (int)$t->fetchColumn();
    $v  = $db->prepare('SELECT COUNT(*) FROM WorkOrders WHERE team_id = ? AND verify_inspector_id = ? AND status = "pending_inspector"');
    $v->execute([$teamId, $userId]);
    $checks = (int)$v->fetchColumn();
    if (!$tasks && !$checks) return;

    $parts = [];
    if ($tasks)  $parts[] = "$tasks open inspection task" . ($tasks === 1 ? '' : 's');
    if ($checks) $parts[] = "$checks site verification" . ($checks === 1 ? '' : 's');
    json_response(false,
        "$name still has " . implode(' and ', $parts) . '. Have a supervisor reassign '
        . ($tasks + $checks === 1 ? 'it' : 'them') . " before $doing.",
        ['open_tasks' => $tasks, 'open_verifications' => $checks], 409);
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

        $db->prepare('INSERT INTO Teams (name, description, owner_id) VALUES (?, ?, ?)')
           ->execute([$name, $desc ?: null, $user['user_id']]);
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
    $first = capitalizeWords(clean($body['first_name'] ?? ''));
    $last  = capitalizeWords(clean($body['last_name']  ?? ''));
    $email = strtolower(clean($body['email'] ?? ''));
    $birth = clean($body['birthdate']  ?? '');
    $role  = clean($body['role'] ?? '');

    if (!$first || !$last) {
        json_response(false, "Enter the member's first and last name.", [], 400);
    }
    if (!nameIsLongEnough($first) || !nameIsLongEnough($last)) {
        json_response(false, "The member's first and last name must be at least 2 characters.", [], 400);
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
    if ($birth > date('Y-m-d', strtotime('-18 years'))) {
        json_response(false, 'Team members must be at least 18 years old.', [], 400);
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
    
    // "Was it just sent?" is computed by MySQL rather than by PHP. The two
    // clocks are not guaranteed to agree — PHP reads date.timezone while
    // MySQL reads the server's own — and measuring a MySQL datetime against
    // PHP's time() throttles every invitation for the size of that gap. On
    // this machine the gap is six hours, which blocked re-inviting anyone.
    $live = $db->prepare(
        'SELECT invitation_id, resend_count,
                (last_sent_at > DATE_SUB(NOW(), INTERVAL ' . INVITE_RESEND_SECONDS . ' SECOND)) AS too_soon
           FROM Invitations
          WHERE team_id = ? AND email = ? AND accepted_at IS NULL AND revoked_at IS NULL
          ORDER BY invitation_id DESC LIMIT 1'
    );
    $live->execute([$m['team_id'], $email]);
    if ($old = $live->fetch()) {
        if ((int)$old['too_soon'] === 1) {
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
                i.last_sent_at,
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
        'SELECT *,
                (last_sent_at > DATE_SUB(NOW(), INTERVAL ' . INVITE_RESEND_SECONDS . ' SECOND)) AS too_soon
           FROM Invitations
          WHERE invitation_id = ? AND team_id = ? AND accepted_at IS NULL AND revoked_at IS NULL
          LIMIT 1'
    );
    $stmt->execute([$id, $m['team_id']]);
    $inv = $stmt->fetch();

    if (!$inv) {
        json_response(false, 'That invitation no longer exists.', [], 404);
    }
    // Same reasoning as the duplicate-invite guard above: MySQL compares its
    // own clock, so the throttle is sixty seconds wherever this is deployed.
    if ((int)$inv['too_soon'] === 1) {
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
                u.name, u.email, u.avatar_path, (tm.user_id = t.owner_id) AS is_owner
         FROM TeamMembers tm
         JOIN Users u ON tm.user_id = u.user_id
         JOIN Teams t ON t.team_id = tm.team_id
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

// ── GET ?action=member_profile&user_id=… ─────────────────────
// The details a member gave when they joined, for the administrator who
// invited them and answers for the team. Nobody else is shown a
// colleague's birthdate, address or phone number.
if ($method === 'GET' && $action === 'member_profile') {
    $m      = requireTeamRole('administrator');
    $userId = (int)($_GET['user_id'] ?? 0);

    $stmt = getDB()->prepare(
        'SELECT u.user_id, u.name, u.email, u.avatar_path, u.gender, u.birthdate,
                u.address, u.contact_number, tm.role, tm.joined_at
           FROM TeamMembers tm JOIN Users u ON u.user_id = tm.user_id
          WHERE tm.team_id = ? AND tm.user_id = ? AND tm.status = "active" LIMIT 1'
    );
    $stmt->execute([$m['team_id'], $userId]);
    $p = $stmt->fetch();
    if (!$p) {
        json_response(false, 'That person is not an active member of this team.', [], 404);
    }
    json_response(true, 'Member profile.', ['member' => $p]);
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

    if ($userId === (int)$actor['user_id']) {
        json_response(false, 'You cannot change your own role. Ask another administrator to do it.', [], 403);
    }
    // Another administrator's role can be changed; the owner's never.
    if ($userId === teamOwnerId((int)$m['team_id'])) {
        json_response(false, "{$target['name']} owns this team, so their role cannot be changed.", [], 403);
    }
    assertNotLastAdmin((int)$m['team_id'], $userId);
    if ($target['member_role'] === 'supervisor') {
        assertNotLastSupervisor((int)$m['team_id'], $target['name']);
    }


    // This team's work only: someone on two teams is not held back here by
    // a job in the other one.
    $open = $db->prepare("SELECT COUNT(*) FROM WorkOrders w WHERE w.team_id = ? AND w.status <> 'completed' AND w.wo_id IN (SELECT wo_id FROM WorkOrderAssignees WHERE user_id = ?)");
    $open->execute([$m['team_id'], $userId]);
    $openCount = (int)$open->fetchColumn();

    if ($openCount > 0 && $target['member_role'] === 'field_worker') {
        json_response(false,
            "{$target['name']} still has $openCount unfinished work order" . ($openCount === 1 ? '' : 's') .
            '. Have a supervisor reassign them before changing this role.',
            ['open_work_orders' => $openCount], 409);
    }
    if ($target['member_role'] === 'field_inspector') {
        assertNoOpenInspection((int)$m['team_id'], $userId, $target['name'], 'changing this role');
    }

    $db->prepare(
        'UPDATE TeamMembers SET role = ?, assigned_by = ?, assigned_at = NOW()
         WHERE team_id = ? AND user_id = ?'
    )->execute([$newRole, $actor['user_id'], $m['team_id'], $userId]);

    logActivity($actor['user_id'], 'assign_role', 'team', (int)$m['team_id'],
        "{$actor['name']} set {$target['name']} as $newRole.");

    $label = str_replace('_', ' ', $newRole);
    json_response(true, "{$target['name']} is now " . ($newRole === 'administrator' ? 'an ' : 'a ') . $label . '.', [
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
        json_response(false, 'You cannot remove yourself. Ask another administrator to remove you from the Members page.', [], 400);
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

    if ($userId === teamOwnerId((int)$m['team_id'])) {
        json_response(false, "{$target['name']} owns this team and cannot be removed. The team ends only when it is deleted.", [], 403);
    }
    assertNotLastAdmin((int)$m['team_id'], $userId);
    if ($target['role'] === 'supervisor') {
        assertNotLastSupervisor((int)$m['team_id'], $target['name']);
    }

    $open = $db->prepare(
        "SELECT COUNT(*) FROM WorkOrders w
          WHERE w.team_id = ? AND w.status <> 'completed'
            AND w.wo_id IN (SELECT wo_id FROM WorkOrderAssignees WHERE user_id = ?)"
    );
    $open->execute([$m['team_id'], $userId]);
    $openCount = (int)$open->fetchColumn();

    if ($openCount > 0) {
        json_response(false,
            "{$target['name']} still has $openCount unfinished work order" . ($openCount === 1 ? '' : 's') .
            '. Reassign them first, then remove this member.', ['open_work_orders' => $openCount], 409);
    }
    assertNoOpenInspection((int)$m['team_id'], $userId, $target['name'], 'removing this member');

    $db->prepare('UPDATE TeamMembers SET status = "removed" WHERE team_id = ? AND user_id = ?')
       ->execute([$m['team_id'], $userId]);

    logActivity($actor['user_id'], 'remove_member', 'team', (int)$m['team_id'],
        "{$actor['name']} removed {$target['name']} from the team.");

    json_response(true, "{$target['name']} has been removed from the team.");
}

// ── GET ?action=former_members ───────────────────────────────
// Everyone removed from this team, newest first. Their names stay on the
// records they made; the administrator can erase the rest of their
// personal details. Nothing else about them is sent.
if ($method === 'GET' && $action === 'former_members') {
    $m    = requireTeamRole('administrator');
    $stmt = getDB()->prepare(
        'SELECT tm.user_id, tm.role, tm.updated_at AS removed_at, u.name, u.avatar_path,
                (u.is_active = 0 AND u.email LIKE "%@deleted.invalid") AS erased,
                EXISTS (SELECT 1 FROM TeamMembers o JOIN Teams t ON t.team_id = o.team_id
                         WHERE o.user_id = tm.user_id AND o.status IN ("pending","active")
                           AND t.is_active = 1) AS elsewhere
           FROM TeamMembers tm
           JOIN Users u ON u.user_id = tm.user_id
          WHERE tm.team_id = ? AND tm.status = "removed"
          ORDER BY tm.updated_at DESC'
    );
    $stmt->execute([$m['team_id']]);
    json_response(true, 'Former members retrieved.', ['former_members' => $stmt->fetchAll()]);
}

// ── POST ?action=erase_member ────────────────────────────────
// Body: { user_id, password }. Erases a former member's personal details;
// the name stays on their records, marked "(deleted)". It cannot be
// undone, so the administrator confirms with their own password. Someone
// still on another team is refused: the account is theirs as well.
if ($method === 'POST' && $action === 'erase_member') {
    $m      = requireTeamRole('administrator');
    $actor  = currentUser();
    $body   = getBody();
    $userId = (int)($body['user_id'] ?? 0);
    // Checked rather than cast: an array here would print a warning
    // ahead of the JSON body.
    $pass   = is_string($body['password'] ?? null) ? $body['password'] : '';

    if (!$userId) {
        json_response(false, 'User is required.', [], 400);
    }
    if ($pass === '') {
        json_response(false, 'Enter your password to confirm.', [], 400);
    }

    $db = getDB();
    $st = $db->prepare('SELECT password_hash FROM Users WHERE user_id = ?');
    $st->execute([$actor['user_id']]);
    // 403, not 401: the app reads a 401 as a sign-in that has expired.
    if (!password_verify($pass, (string)$st->fetchColumn())) {
        json_response(false, 'That password is incorrect.', [], 403);
    }

    $st = $db->prepare(
        'SELECT u.name, u.email, u.is_active FROM TeamMembers tm
           JOIN Users u ON u.user_id = tm.user_id
          WHERE tm.team_id = ? AND tm.user_id = ? AND tm.status = "removed" LIMIT 1'
    );
    $st->execute([$m['team_id'], $userId]);
    $target = $st->fetch();

    if (!$target) {
        json_response(false, 'Only someone already removed from this team can be erased.', [], 404);
    }
    if (isErasedAccount($target)) {
        json_response(false, 'Their personal details have already been erased.', [], 409);
    }
    if (userMemberships($userId)) {
        json_response(false, "{$target['name']} is still on another team, so their account can't be erased from here.", [], 409);
    }

    try {
        eraseAccount($userId, (int)$actor['user_id'], 'erase_member', fn(int $kept) =>
            "{$actor['name']} erased the personal details of a former member (account #$userId)"
            . ($kept === 0 ? '; the account had no records and was removed.'
                           : "; $kept linked record(s) kept under a name marked deleted."), true);
    } catch (Throwable $e) {
        json_response(false, 'The details could not be erased. Please try again.', [], 500);
    }

    json_response(true, "{$target['name']}'s personal details have been erased.");
}

// ── POST ?action=delete_team ─────────────────────────────────
// Body: { password }. Allowed once the administrator is the last active
// member. The team is hidden for good, not erased: its reports, work
// orders and sealed activity log are safety records, so the database
// keeps them. Pending invitations are revoked so no old link reopens it.
if ($method === 'POST' && $action === 'delete_team') {
    $m      = requireTeamRole('administrator');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $sent   = getBody()['password'] ?? '';
    $pass   = is_string($sent) ? $sent : '';

    if ($pass === '') {
        json_response(false, 'Enter your password to confirm.', [], 400);
    }
    $db = getDB();
    $st = $db->prepare('SELECT password_hash FROM Users WHERE user_id = ?');
    $st->execute([$actor['user_id']]);
    // 403, not 401: the app reads a 401 as a sign-in that has expired.
    if (!password_verify($pass, (string)$st->fetchColumn())) {
        json_response(false, 'That password is incorrect.', [], 403);
    }

    $st = $db->prepare('SELECT COUNT(*) FROM TeamMembers WHERE team_id = ? AND status = "active" AND user_id <> ?');
    $st->execute([$teamId, $actor['user_id']]);
    $others = (int)$st->fetchColumn();
    if ($others > 0) {
        json_response(false, "Remove the other $others member" . ($others === 1 ? '' : 's')
            . ' first. A team can be deleted only when you are its last member.', ['other_members' => $others], 409);
    }

    $st = $db->prepare('SELECT name FROM Teams WHERE team_id = ?');
    $st->execute([$teamId]);
    $name = (string)$st->fetchColumn();

    $db->beginTransaction();
    try {
        logActivity($actor['user_id'], 'delete_team', 'team', $teamId,
            "{$actor['name']} deleted the team \"$name\". Its records are kept.");
        $db->prepare('UPDATE Invitations SET revoked_at = NOW()
                       WHERE team_id = ? AND accepted_at IS NULL AND revoked_at IS NULL')->execute([$teamId]);
        $db->prepare('UPDATE TeamMembers SET status = "removed" WHERE team_id = ? AND status = "pending"')->execute([$teamId]);
        $db->prepare('UPDATE Teams SET is_active = 0 WHERE team_id = ?')->execute([$teamId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        json_response(false, 'The team could not be deleted. Please try again.', [], 500);
    }

    // No team now: the gate offers to create or join another.
    unset($_SESSION['active_team_id']);
    json_response(true, "\"$name\" has been deleted.", ['redirect' => 'team.html']);
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
