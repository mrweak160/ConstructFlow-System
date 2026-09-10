<?php

require_once __DIR__ . '/../config/helpers.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Only actions reached from an already-authenticated page can carry a token.
// login / register_* / forgot_* / invite_accept run before a session exists,
// and logout sends no headers, so they are deliberately excluded.
if ($method === 'POST' && in_array($action, ['change_password', 'switch_team', 'invite_claim'], true)) {
    verifyCsrf();
}

// ════════════════════════════════════════════════════════════
// REGISTRATION
// ════════════════════════════════════════════════════════════

if ($method === 'POST' && $action === 'register_start') {
    $body      = getBody();
    $firstName = capitalizeWords(clean($body['first_name'] ?? ''));
    $lastName  = capitalizeWords(clean($body['last_name']  ?? ''));
    $gender    = clean($body['gender']     ?? '');
    $birth     = clean($body['birthdate']  ?? '');
    $address   = clean($body['address']    ?? '');
    $email     = strtolower(clean($body['email'] ?? ''));

    if (!$firstName || !$lastName || !$gender) {
        json_response(false, 'First name, last name, and gender are required.', [], 400);
    }
    if (!nameIsLongEnough($firstName) || !nameIsLongEnough($lastName)) {
        json_response(false, 'First and last name must be at least 2 characters.', [], 400);
    }

    if (!in_array($gender, ['male', 'female', 'other', 'prefer_not_to_say'], true)) {
        json_response(false, 'Please select a gender option.', [], 400);
    }

    $parts = explode('-', $birth);
    if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        json_response(false, 'Enter a valid birthdate.', [], 400);
    }
    if ($birth >= date('Y-m-d')) {
        json_response(false, 'Birthdate must be in the past.', [], 400);
    }
    if ($birth > date('Y-m-d', strtotime('-18 years'))) {
        json_response(false, 'You must be at least 18 to register a business account.', [], 400);
    }

    if (mb_strlen($address) < 5) {
        json_response(false, 'Enter your address.', [], 400);
    }
    if (mb_strlen($address) > 255) {
        json_response(false, 'Address is too long (255 characters max).', [], 400);
    }

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, 'Please enter a valid email address.', [], 400);
    }

    $db = getDB();

    // Already a real account?
    $stmt = $db->prepare('SELECT user_id FROM Users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_response(false, 'This email is already registered. Please log in or use Forgot Password.', [], 409);
    }

    $code       = generateSixCharCode();
    $codeHash   = hashCode($code);
    $fullName   = $firstName . ' ' . $lastName;

    // Resend cooldown: look at any existing pending registration for this email before overwriting it.
    $existing = $db->prepare('SELECT last_sent_at, resend_count FROM PendingRegistrations WHERE email = ?');
    $existing->execute([$email]);
    $prior = $existing->fetch();
    if ($prior && strtotime($prior['last_sent_at']) > time() - CODE_RESEND_COOLDOWN_SECONDS) {
        json_response(false, 'Please wait a moment before requesting another code.', [], 429);
    }

    if ($prior && (int)$prior['resend_count'] >= CODE_MAX_RESENDS) {
        json_response(false, 'Too many verification codes requested. Please try again later.', [], 429);
    }
    $carry = $prior ? (int)$prior['resend_count'] + 1 : 0;

    // One pending registration per email 
    $db->prepare('DELETE FROM PendingRegistrations WHERE email = ?')->execute([$email]);

    $db->prepare(
        'INSERT INTO PendingRegistrations
         (first_name, last_name, gender, birthdate, address, email, code_hash, expires_at, resend_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)'
    )->execute([$firstName, $lastName, $gender, $birth, $address, $email, $codeHash, CODE_TTL_MINUTES, $carry]);
    sendMail($email, $fullName, 'Verify your email — ConstructFlow', registrationCodeEmailBody($fullName, $code));

    json_response(true, 'A 6-character verification code has been sent to your email.', ['email' => $email]);
}


if ($method === 'POST' && $action === 'register_verify') {
    $body  = getBody();
    $email = strtolower(clean($body['email'] ?? ''));
    $code  = strtoupper(clean($body['code']  ?? ''));

    if (!$email || !$code) {
        json_response(false, 'Email and verification code are required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM PendingRegistrations WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row) {
        json_response(false, 'No pending registration found for this email. Please start again.', [], 404);
    }

    $result = checkCode($row, $code);

    if ($result !== 'ok') {

        if ($result === 'wrong') {
            $db->prepare('UPDATE PendingRegistrations SET attempts = attempts + 1 WHERE pending_id = ?')
               ->execute([$row['pending_id']]);
        }
        json_response(false, codeErrorMessage($result), ['reason' => $result], 400);
    }

    $db->prepare('UPDATE PendingRegistrations SET verified_at = NOW() WHERE pending_id = ?')
       ->execute([$row['pending_id']]);

    json_response(true, 'Email verified. You can now set your password.', ['email' => $email]);
}

// ── POST ?action=register_resend ─────────────────────────────
// Body: { email }
if ($method === 'POST' && $action === 'register_resend') {
    $email = strtolower(clean(getBody()['email'] ?? ''));
    if (!$email) {
        json_response(false, 'Email is required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM PendingRegistrations WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row) {
        json_response(false, 'No pending registration found for this email. Please start again.', [], 404);
    }
    if (strtotime($row['last_sent_at']) > time() - CODE_RESEND_COOLDOWN_SECONDS) {
        json_response(false, 'Please wait a moment before requesting another code.', [], 429);
    }
    if ((int)$row['resend_count'] >= CODE_MAX_RESENDS) {
        json_response(false, 'Too many resend requests. Please try again later.', [], 429);
    }

    $code = generateSixCharCode();
    $db->prepare(
        'UPDATE PendingRegistrations
         SET code_hash = ?, attempts = 0, verified_at = NULL,
             expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
             last_sent_at = NOW(), resend_count = resend_count + 1
         WHERE pending_id = ?'
    )->execute([hashCode($code), CODE_TTL_MINUTES, $row['pending_id']]);

    $fullName = $row['first_name'] . ' ' . $row['last_name'];
    sendMail($email, $fullName, 'Your new ConstructFlow verification code', registrationCodeEmailBody($fullName, $code));

    json_response(true, 'A new verification code has been sent.');
}

// ── POST ?action=register_complete ───────────────────────────
if ($method === 'POST' && $action === 'register_complete') {
    $body    = getBody();
    $email   = strtolower(clean($body['email'] ?? ''));
    $pass    = $body['password']         ?? '';
    $confirm = $body['confirm_password'] ?? '';
    $teamName      = clean($body['team_name']        ?? '');
    $teamDesc      = clean($body['team_description'] ?? '');
    $termsAccepted = (bool)($body['terms_accepted']  ?? false);

    if (!$termsAccepted) {
        json_response(false, 'You must agree to the Terms of Service and Privacy Policy to continue.', [], 400);
    }
    if (mb_strlen($teamName) < 2) {
        json_response(false, 'Enter a name for your team.', [], 400);
    }
    if (mb_strlen($teamName) > 150) {
        json_response(false, 'Team name is too long (150 characters max).', [], 400);
    }
    if (mb_strlen($teamDesc) > 500) {
        json_response(false, 'Description is too long (500 characters max).', [], 400);
    }

    if (!$email || !$pass || !$confirm) {
        json_response(false, 'Password and confirmation are required.', [], 400);
    }
    if ($pass !== $confirm) {
        json_response(false, 'Passwords do not match.', [], 400);
    }
    if ($err = passwordPolicyError($pass)) {
        json_response(false, $err, [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM PendingRegistrations WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $pending = $stmt->fetch();

    if (!$pending || !$pending['verified_at']) {
        json_response(false, 'Please verify your email before creating a password.', [], 403);
    }

    // Re-check email uniqueness in case of a race between two tabs.
    $dupe = $db->prepare('SELECT user_id FROM Users WHERE email = ? LIMIT 1');
    $dupe->execute([$email]);
    if ($dupe->fetch()) {
        $db->prepare('DELETE FROM PendingRegistrations WHERE pending_id = ?')->execute([$pending['pending_id']]);
        json_response(false, 'This email is already registered. Please log in or use Forgot Password.', [], 409);
    }

    $fullName = $pending['first_name'] . ' ' . $pending['last_name'];
    $hash     = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);


    $db->beginTransaction();
    try {
        // account_type 'owner'
        $db->prepare(
            'INSERT INTO Users
             (name, gender, birthdate, address, email, password_hash, email_verified_at, account_type, terms_accepted_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), "owner", NOW())'
        )->execute([$fullName, $pending['gender'], $pending['birthdate'], $pending['address'], $email, $hash]);
        $userId = (int)$db->lastInsertId();
        if ($teamName !== '') {
            $db->prepare(
                'INSERT INTO Teams (name, description, owner_id) VALUES (?, ?, ?)'
            )->execute([$teamName, $teamDesc ?: null, $userId]);
            $teamId = (int)$db->lastInsertId();

            $db->prepare(
                'INSERT INTO TeamMembers (team_id, user_id, role, status, joined_at, assigned_by, assigned_at)
                 VALUES (?, ?, "administrator", "active", NOW(), ?, NOW())'
            )->execute([$teamId, $userId, $userId]);
        }

        $db->prepare('DELETE FROM PendingRegistrations WHERE pending_id = ?')
           ->execute([$pending['pending_id']]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, 'Could not finish creating your account. Please try again.', [], 500);
    }

    logActivity($userId, 'register', 'user', $userId, "$fullName created an owner account.");

    if ($teamName !== '') {
        logActivity($userId, 'create_team', 'team', $teamId,
            "$fullName created the team \"$teamName\".", $teamId);
    }

    json_response(true,
        $teamName !== ''
            ? "Your account and \"$teamName\" are ready. Log in to invite your crew."
            : 'Account created successfully! You can now log in.');
}

// ════════════════════════════════════════════════════════════
// INVITATIONS
// ════════════════════════════════════════════════════════════

// ── GET ?action=invite_lookup&token=… ────────────────────────
// Public. Tells the page what to render before anyone types anything.
if ($method === 'GET' && $action === 'invite_lookup') {
    $token = clean($_GET['token'] ?? '');
    $inv   = findLiveInvitation($token);

    // One message for missing, expired, revoked and already-accepted:
    // a guessed token should not be able to tell them apart.
    if (!$inv) {
        json_response(false, 'This invitation link is no longer valid. Ask your administrator to send a new one.', [], 404);
    }

    $stmt = getDB()->prepare('SELECT user_id FROM Users WHERE email = ? LIMIT 1');
    $stmt->execute([$inv['email']]);

    json_response(true, 'Invitation found.', [
        'team_name'        => $inv['team_name'],
        'inviter_name'     => $inv['inviter_name'],
        'email'            => $inv['email'],
        'role'             => $inv['role'],
        'role_label'       => roleLabel($inv['role']),
        'first_name'       => $inv['first_name'],
        'last_name'        => $inv['last_name'],
        'birthdate'        => $inv['birthdate'],
        // Decides which pane the page shows: set a password, or log in.
        'existing_account' => (bool)$stmt->fetchColumn(),
    ]);
}

// ── POST ?action=invite_accept ───────────────────────────────
// Body: { token, first_name, last_name, birthdate, gender, address, password, confirm_password }
if ($method === 'POST' && $action === 'invite_accept') {
    $body      = getBody();
    $token     = clean($body['token']      ?? '');
    $firstName = capitalizeWords(clean($body['first_name'] ?? ''));
    $lastName  = capitalizeWords(clean($body['last_name']  ?? ''));
    $birth     = clean($body['birthdate']  ?? '');
    $gender    = clean($body['gender']     ?? '');
    $address   = clean($body['address']    ?? '');
    $pass      = $body['password']         ?? '';
    $confirm   = $body['confirm_password'] ?? '';
    $termsAccepted = (bool)($body['terms_accepted'] ?? false);

    $inv = findLiveInvitation($token);
    if (!$inv) {
        json_response(false, 'This invitation link is no longer valid. Ask your administrator to send a new one.', [], 404);
    }

    if (!$termsAccepted) {
        json_response(false, 'You must agree to the Terms of Service and Privacy Policy to continue.', [], 400);
    }

    if (!$firstName || !$lastName) {
        json_response(false, 'First name and last name are required.', [], 400);
    }
    if (!nameIsLongEnough($firstName) || !nameIsLongEnough($lastName)) {
        json_response(false, 'First and last name must be at least 2 characters.', [], 400);
    }
    if (mb_strlen($firstName) > 60 || mb_strlen($lastName) > 60) {
        json_response(false, 'Names are too long (60 characters max each).', [], 400);
    }
    $parts = explode('-', $birth);
    if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        json_response(false, 'Enter a valid birthdate.', [], 400);
    }
    if ($birth >= date('Y-m-d')) {
        json_response(false, 'Birthdate must be in the past.', [], 400);
    }

    if (!in_array($gender, ['male', 'female', 'other', 'prefer_not_to_say'], true)) {
        json_response(false, 'Please select a gender option.', [], 400);
    }
    if (mb_strlen($address) < 5) {
        json_response(false, 'Enter your address.', [], 400);
    }
    if (mb_strlen($address) > 255) {
        json_response(false, 'Address is too long (255 characters max).', [], 400);
    }
    if (!$pass || !$confirm) {
        json_response(false, 'Password and confirmation are required.', [], 400);
    }
    if ($pass !== $confirm) {
        json_response(false, 'Passwords do not match.', [], 400);
    }
    if ($err = passwordPolicyError($pass)) {
        json_response(false, $err, [], 400);
    }

    $db = getDB();

    $dupe = $db->prepare('SELECT user_id FROM Users WHERE email = ? LIMIT 1');
    $dupe->execute([$inv['email']]);
    if ($dupe->fetch()) {
        json_response(false, 'An account already exists for this email. Log in and the invitation will be waiting.', ['existing_account' => true], 409);
    }

        $fullName = $firstName . ' ' . $lastName;
    $hash     = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);

    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO Users
             (name, gender, birthdate, address, email, password_hash, email_verified_at, account_type, terms_accepted_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), "member", NOW())'
        )->execute([$fullName, $gender, $birth, $address, $inv['email'], $hash]);
        $userId = (int)$db->lastInsertId();

        $db->prepare(
            'INSERT INTO TeamMembers (team_id, user_id, role, status, joined_at, assigned_by, assigned_at)
             VALUES (?, ?, ?, "active", NOW(), ?, NOW())'
        )->execute([$inv['team_id'], $userId, $inv['role'], $inv['invited_by']]);

        $db->prepare('UPDATE Invitations SET accepted_at = NOW() WHERE invitation_id = ?')
           ->execute([$inv['invitation_id']]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, 'Could not complete your registration. Please try again.', [], 500);
    }

    // Any OTHER outstanding invitation to this address is now stale.
    $db->prepare(
        'UPDATE Invitations SET revoked_at = NOW()
         WHERE email = ? AND accepted_at IS NULL AND revoked_at IS NULL'
    )->execute([$inv['email']]);

    $invitedName = trim($inv['first_name'] . ' ' . $inv['last_name']);
    $nameNote    = ($invitedName !== '' && strcasecmp($invitedName, $fullName) !== 0)
        ? " (invited as \"$invitedName\")"
        : '';

    logActivity($userId, 'invite_accepted', 'team', (int)$inv['team_id'],
        "$fullName joined {$inv['team_name']} as " . roleLabel($inv['role']) . ".$nameNote",
        (int)$inv['team_id']);

    json_response(true, "Welcome to {$inv['team_name']}. You can now log in.", [
        'team_name' => $inv['team_name'],
        'role'      => $inv['role'],
    ]);
}

// ── POST ?action=invite_claim ────────────────────────────────
// Body: { token }
// For somebody who already has an account. Adds the membership only.
// Their account_type is left alone: an owner invited onto someone
// else's crew stays an owner, and a member stays a member.
if ($method === 'POST' && $action === 'invite_claim') {
    $user  = requireAuth();
    $token = clean(getBody()['token'] ?? '');
    $inv   = findLiveInvitation($token);

    if (!$inv) {
        json_response(false, 'This invitation link is no longer valid. Ask your administrator to send a new one.', [], 404);
    }

    // The invitation names an address. Someone signed in as a different
    // person cannot spend it, or a forwarded email would be a free pass.
    if (strtolower($user['email']) !== strtolower($inv['email'])) {
        json_response(false,
            "This invitation was sent to {$inv['email']}. Sign in as that account to accept it.", [], 403);
    }

    $db = getDB();

    $existing = $db->prepare('SELECT status FROM TeamMembers WHERE team_id = ? AND user_id = ? LIMIT 1');
    $existing->execute([$inv['team_id'], $user['user_id']]);
    $prior = $existing->fetch();

    $db->beginTransaction();
    try {
        if ($prior) {
            if ($prior['status'] === 'active') {
                $db->rollBack();
                json_response(false, "You're already a member of {$inv['team_name']}.", [], 409);
            }
            // A pending join request or a previous removal is simply
            // overwritten — an invitation outranks both.
            $db->prepare(
                'UPDATE TeamMembers
                 SET role = ?, status = "active", joined_at = NOW(), assigned_by = ?, assigned_at = NOW()
                 WHERE team_id = ? AND user_id = ?'
            )->execute([$inv['role'], $inv['invited_by'], $inv['team_id'], $user['user_id']]);
        } else {
            $db->prepare(
                'INSERT INTO TeamMembers (team_id, user_id, role, status, joined_at, assigned_by, assigned_at)
                 VALUES (?, ?, ?, "active", NOW(), ?, NOW())'
            )->execute([$inv['team_id'], $user['user_id'], $inv['role'], $inv['invited_by']]);
        }

        $db->prepare('UPDATE Invitations SET accepted_at = NOW() WHERE invitation_id = ?')
           ->execute([$inv['invitation_id']]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, 'Could not add you to the team. Please try again.', [], 500);
    }

    $_SESSION['active_team_id'] = (int)$inv['team_id'];

    logActivity($user['user_id'], 'invite_accepted', 'team', (int)$inv['team_id'],
        "{$user['name']} joined {$inv['team_name']} as " . roleLabel($inv['role']) . '.', (int)$inv['team_id']);

    json_response(true, "You've joined {$inv['team_name']}.", [
        'team_id'   => (int)$inv['team_id'],
        'team_name' => $inv['team_name'],
        'role'      => $inv['role'],
    ]);
}

// ════════════════════════════════════════════════════════════
// LOGIN / SESSION
// ════════════════════════════════════════════════════════════

// ── POST ?action=login ───────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $body  = getBody();
    $email = strtolower(clean($body['email'] ?? ''));
    $pass  = $body['password'] ?? '';

    if (!$email || !$pass) {
        json_response(false, 'Email and password are required.', [], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, 'Please enter a valid email address.', [], 400);
    }

    if (recentFailedLogins($email) >= LOGIN_MAX_ATTEMPTS) {
        logActivity(null, 'login_throttled', 'user', null, "Too many failed logins for: $email");
        json_response(false, 'Too many failed attempts. Please try again in ' . LOGIN_WINDOW_MIN . ' minutes.', [], 429);
    }

    $stmt = getDB()->prepare(
    'SELECT user_id, name, email, password_hash, is_active, account_type
     FROM Users WHERE email = ? LIMIT 1'
    );
    
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Same generic message whether the email doesn't exist or the
    // password is wrong
    if (!$user || !$user['password_hash'] || !password_verify($pass, $user['password_hash'])) {
        recordLoginAttempt($email, false);
        logActivity(null, 'login_failed', 'user', null, "Failed login attempt for: $email");
        json_response(false, 'Invalid email or password.', [], 401);
    }
    if (!$user['is_active']) {
        recordLoginAttempt($email, false);
        json_response(false, 'Invalid email or password.', [], 401); // deactivated ≠ "account exists" signal
    }

    recordLoginAttempt($email, true);
    clearLoginAttempts($email);
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'user_id' => (int)$user['user_id'],
        'name'    => $user['name'],
        'email'   => $user['email'],
    ];

    // Sent to the client but deliberately NOT stored in the session:
    // currentUser() re-reads it from the database on every request, so
    // a stale session can never grant team-creation rights.
    $loginUser = $_SESSION['user'] + ['account_type' => $user['account_type']];

    $memberships = userMemberships((int)$user['user_id']);
    $active      = array_values(array_filter($memberships, fn($m) => $m['status'] === 'active'));
    if (count($active) === 1) {
        $_SESSION['active_team_id'] = (int)$active[0]['team_id'];
    }

    logActivity((int)$user['user_id'], 'login', 'user', (int)$user['user_id'], "{$user['name']} logged in.");

    json_response(true, 'Login successful.', [
        'user'           => $loginUser,
        'memberships'    => $memberships,
        'active_team_id' => $_SESSION['active_team_id'] ?? null,
        'csrf_token'     => issueCsrfToken(),
    ]);
}

// ── GET ?action=me ───────────────────────────────────────────
if ($method === 'GET' && $action === 'me') {
    $user = currentUser();
    if (!$user) {
        json_response(false, 'Not authenticated.', [], 401);
    }

    $memberships = userMemberships($user['user_id']);
    $membership  = currentMembership();

    json_response(true, 'Authenticated.', [
        'user'           => [
            'user_id'      => $user['user_id'],
            'name'         => $user['name'],
            'email'        => $user['email'],
            // Whether this account may create a team. team.js uses it
            // to pick which of the three gate screens to show.
            'account_type' => $user['account_type'],
            'avatar_path'  => $user['avatar_path'],
        ],
        'memberships'    => $memberships,
        'active_team_id' => $membership['team_id'] ?? null,
        'team_name'      => $membership['team_name'] ?? null,
        'role'           => $membership['role'] ?? null,
        'csrf_token'     => issueCsrfToken(),
    ]);
}

// ── POST ?action=switch_team ──────────────────────────────────
if ($method === 'POST' && $action === 'switch_team') {
    $user   = requireAuth();
    $teamId = (int)(getBody()['team_id'] ?? 0);
    if (!$teamId) {
        json_response(false, 'Team ID is required.', [], 400);
    }

    $stmt = getDB()->prepare(
        'SELECT tm.role, t.name FROM TeamMembers tm
         JOIN Teams t ON tm.team_id = t.team_id
         WHERE tm.user_id = ? AND tm.team_id = ? AND tm.status = "active" AND t.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$user['user_id'], $teamId]);
    $m = $stmt->fetch();

    if (!$m) {
        json_response(false, 'You are not an active member of that team.', [], 403);
    }

    $_SESSION['active_team_id'] = $teamId;
    json_response(true, 'Team switched.', ['team_id' => $teamId, 'team_name' => $m['name'], 'role' => $m['role']]);
}

// ── POST ?action=logout ──────────────────────────────────────
if ($method === 'POST' && $action === 'logout') {
    $user = currentUser();
    if ($user) {
        logActivity($user['user_id'], 'logout', 'user', $user['user_id'], "{$user['name']} logged out.");
    }
    $_SESSION = [];
    session_destroy();
    json_response(true, 'Logged out successfully.');
}

// ── POST ?action=change_password ─────────────────────────────
if ($method === 'POST' && $action === 'change_password') {
    $user    = requireAuth();
    $body    = getBody();
    $curr    = $body['current_password'] ?? '';
    $new     = $body['new_password']     ?? '';
    $confirm = $body['confirm_password'] ?? $new; // tolerate callers that don't send it

    if (!$curr || !$new) {
        json_response(false, 'Both current and new password are required.', [], 400);
    }
    if ($new !== $confirm) {
        json_response(false, 'Passwords do not match.', [], 400);
    }
    if ($err = passwordPolicyError($new)) {
        json_response(false, $err, [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT password_hash FROM Users WHERE user_id = ?');
    $stmt->execute([$user['user_id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($curr, $row['password_hash'])) {
        json_response(false, 'Current password is incorrect.', [], 401);
    }

    $db->prepare('UPDATE Users SET password_hash = ? WHERE user_id = ?')
       ->execute([password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $user['user_id']]);

    session_regenerate_id(true);

    logActivity($user['user_id'], 'change_password', 'user', $user['user_id'], "{$user['name']} changed their password.");
    json_response(true, 'Password changed successfully.');
}

// ════════════════════════════════════════════════════════════
// FORGOT PASSWORD
// ════════════════════════════════════════════════════════════

// ── POST ?action=forgot_start ────────────────────────────────
if ($method === 'POST' && $action === 'forgot_start') {
    $email = strtolower(clean(getBody()['email'] ?? ''));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, 'Please enter a valid email address.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT user_id, name, is_active FROM Users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $generic = 'If that email is registered, a verification code has been sent.';

    if (!$user || !$user['is_active']) {
        json_response(true, $generic);
    }

    $existing = $db->prepare(
        'SELECT last_sent_at, resend_count FROM PasswordResetCodes
         WHERE user_id = ? AND consumed_at IS NULL ORDER BY reset_id DESC LIMIT 1'
    );
    $existing->execute([$user['user_id']]);
    $prior = $existing->fetch();
    if ($prior && strtotime($prior['last_sent_at']) > time() - CODE_RESEND_COOLDOWN_SECONDS) {
        json_response(true, $generic); 
    }

    // Invalidate any previous outstanding reset code for this user.
    $db->prepare('UPDATE PasswordResetCodes SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL')
       ->execute([$user['user_id']]);

    $code = generateSixCharCode();
    $db->prepare(
        'INSERT INTO PasswordResetCodes (user_id, code_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
    )->execute([$user['user_id'], hashCode($code), CODE_TTL_MINUTES]);

    sendMail($email, $user['name'], 'Reset your ConstructFlow password', passwordResetCodeEmailBody($user['name'], $code));
    logActivity((int)$user['user_id'], 'password_reset_requested', 'user', (int)$user['user_id'], 'Requested a password reset code.');

    json_response(true, $generic);
}

// ── POST ?action=forgot_verify ───────────────────────────────
//  email, code
if ($method === 'POST' && $action === 'forgot_verify') {
    $body  = getBody();
    $email = strtolower(clean($body['email'] ?? ''));
    $code  = strtoupper(clean($body['code']  ?? ''));

    if (!$email || !$code) {
        json_response(false, 'Email and verification code are required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT prc.* FROM PasswordResetCodes prc
         JOIN Users u ON prc.user_id = u.user_id
         WHERE u.email = ? AND prc.consumed_at IS NULL
         ORDER BY prc.reset_id DESC LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row) {
        // Generic on purpose — do not confirm whether the email exists.
        json_response(false, codeErrorMessage('wrong'), ['reason' => 'wrong'], 400);
    }

    $result = checkCode($row, $code);

    if ($result !== 'ok') {
        if ($result === 'wrong') {
            $db->prepare('UPDATE PasswordResetCodes SET attempts = attempts + 1 WHERE reset_id = ?')
               ->execute([$row['reset_id']]);
        }
        json_response(false, codeErrorMessage($result), ['reason' => $result], 400);
    }

    $db->prepare('UPDATE PasswordResetCodes SET verified_at = NOW() WHERE reset_id = ?')
       ->execute([$row['reset_id']]);

    json_response(true, 'Code verified. You can now set a new password.', ['email' => $email]);
}

// ── POST ?action=forgot_resend ───────────────────────────────
// email 
if ($method === 'POST' && $action === 'forgot_resend') {
    $email = strtolower(clean(getBody()['email'] ?? ''));
    $generic = 'If that email is registered, a new code has been sent.';
    if (!$email) {
        json_response(false, 'Email is required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT prc.reset_id, prc.last_sent_at, prc.resend_count, u.user_id, u.name
         FROM PasswordResetCodes prc
         JOIN Users u ON prc.user_id = u.user_id
         WHERE u.email = ? AND prc.consumed_at IS NULL
         ORDER BY prc.reset_id DESC LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row) {
        json_response(true, $generic); // generic, same reasoning as forgot_start
    }
    if (strtotime($row['last_sent_at']) > time() - CODE_RESEND_COOLDOWN_SECONDS) {
        json_response(true, $generic);
    }
    if ((int)$row['resend_count'] >= CODE_MAX_RESENDS) {
        json_response(true, $generic);
    }

    $code = generateSixCharCode();
    $db->prepare(
        'UPDATE PasswordResetCodes
         SET code_hash = ?, attempts = 0, verified_at = NULL,
             expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
             last_sent_at = NOW(), resend_count = resend_count + 1
         WHERE reset_id = ?'
    )->execute([hashCode($code), CODE_TTL_MINUTES, $row['reset_id']]);

    sendMail($email, $row['name'], 'Your new ConstructFlow password reset code', passwordResetCodeEmailBody($row['name'], $code));

    json_response(true, $generic);
}

// ── POST ?action=forgot_reset ─────────────────────────────────
//  email, new_password, confirm_password 
if ($method === 'POST' && $action === 'forgot_reset') {
    $body    = getBody();
    $email   = strtolower(clean($body['email'] ?? ''));
    $pass    = $body['new_password']     ?? '';
    $confirm = $body['confirm_password'] ?? '';

    if (!$email || !$pass || !$confirm) {
        json_response(false, 'New password and confirmation are required.', [], 400);
    }
    if ($pass !== $confirm) {
        json_response(false, 'Passwords do not match.', [], 400);
    }
    if ($err = passwordPolicyError($pass)) {
        json_response(false, $err, [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT prc.* FROM PasswordResetCodes prc
         JOIN Users u ON prc.user_id = u.user_id
         WHERE u.email = ? AND prc.consumed_at IS NULL AND prc.verified_at IS NOT NULL
         ORDER BY prc.reset_id DESC LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row) {
        json_response(false, 'Please verify your reset code before setting a new password.', [], 403);
    }
    if (strtotime($row['expires_at']) < time()) {
        json_response(false, codeErrorMessage('expired'), ['reason' => 'expired'], 400);
    }

    $db->prepare('UPDATE Users SET password_hash = ? WHERE user_id = ?')
       ->execute([password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $row['user_id']]);

    // Invalidate so this code can never be reused.
    $db->prepare('UPDATE PasswordResetCodes SET consumed_at = NOW() WHERE reset_id = ?')
       ->execute([$row['reset_id']]);

    logActivity((int)$row['user_id'], 'password_reset', 'user', (int)$row['user_id'], 'Password reset via forgot-password flow.');

    json_response(true, 'Your password has been successfully reset. You can now log in.');
}

json_response(false, 'Invalid action.', [], 400);
