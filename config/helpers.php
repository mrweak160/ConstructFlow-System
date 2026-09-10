<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

// ── TEAM ROLES ───────────────────────────────────────────────
const TEAM_ROLES     = ['administrator', 'supervisor', 'field_inspector', 'field_worker', 'member'];

function isValidTeamRole(string $role): bool {
    return in_array($role, TEAM_ROLES, true);
}

// ── RESPONSE ─────────────────────────────────────────────────
function json_response(bool $success, string $message, array $data = [], int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

// ── SESSION ──────────────────────────────────────────────────
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => APP_HTTPS,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

function currentUser(): ?array {
    startSecureSession();
    $sessionUser = $_SESSION['user'] ?? null;
    if (!$sessionUser) {
        return null;
    }

    $stmt = getDB()->prepare(
        'SELECT user_id, name, email, is_active, email_verified_at, account_type, avatar_path
         FROM Users WHERE user_id = ? LIMIT 1'
    );

    $stmt->execute([$sessionUser['user_id']]);
    $row = $stmt->fetch();

    if (!$row || !$row['is_active'] || !$row['email_verified_at']) {
        session_destroy();
        return null;
    }

    return [
        'user_id'       => (int)$row['user_id'],
        'name'          => $row['name'],
        'email'         => $row['email'],
        'account_type'  => $row['account_type'],
        'avatar_path'   => $row['avatar_path'],
        'active_team_id'=> $_SESSION['active_team_id'] ?? null,
    ];
}

function requireAuth(): array {
    $user = currentUser();
    if (!$user) {
        json_response(false, 'Unauthorized. Please log in.', [], 401);
    }
    return $user;
}

// ── TEAM MEMBERSHIP ──────────────────────────────────────────
function userMemberships(int $userId): array {
    $stmt = getDB()->prepare(
        'SELECT tm.team_id, tm.role, tm.status, t.name AS team_name, t.owner_id
         FROM TeamMembers tm
         JOIN Teams t ON tm.team_id = t.team_id
         WHERE tm.user_id = ? AND tm.status IN ("pending","active") AND t.is_active = 1
         ORDER BY t.name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function currentMembership(): ?array {
    $user   = currentUser();
    $teamId = $_SESSION['active_team_id'] ?? null;
    if (!$user || !$teamId) {
        return null;
    }

    $stmt = getDB()->prepare(
        'SELECT tm.membership_id, tm.team_id, tm.role, tm.status, t.name AS team_name, t.owner_id
         FROM TeamMembers tm
         JOIN Teams t ON tm.team_id = t.team_id
         WHERE tm.user_id = ? AND tm.team_id = ? AND t.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$user['user_id'], $teamId]);
    $m = $stmt->fetch();

    if (!$m || $m['status'] !== 'active' || !isValidTeamRole($m['role'])) {
        unset($_SESSION['active_team_id']);
        return null;
    }
    return $m;
}

/** Caller must be logged in AND have an active team selected. */
function requireTeam(): array {
    requireAuth();
    $m = currentMembership();
    if (!$m) {
        json_response(false, 'Select a team first, or wait for your join request to be approved.', [], 403);
    }
    return $m;
}

function requireTeamRole(string ...$roles): array {
    $m = requireTeam();
    if (!in_array($m['role'], $roles, true)) {
        json_response(false, 'Access denied. Insufficient permissions.', [], 403);
    }
    return $m;
}

// ── ACCOUNT TYPE ─────────────────────────────────────────────

function accountType(int $userId): string {
    $stmt = getDB()->prepare('SELECT account_type FROM Users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (string)($stmt->fetchColumn() ?: 'member');
}

/** Caller must be logged in AND hold an owner account. */
function requireOwnerAccount(): array {
    $user = requireAuth();
    if (accountType($user['user_id']) !== 'owner') {
        json_response(false,
            'Your account cannot create a team. Teams are created by the business owner, '
          . 'who then invites everyone else by email.', [], 403);
    }
    return $user;
}


function countAdmins(int $teamId): int {
    $stmt = getDB()->prepare(
        'SELECT COUNT(*) FROM TeamMembers
         WHERE team_id = ? AND role = "administrator" AND status = "active"'
    );
    $stmt->execute([$teamId]);
    return (int)$stmt->fetchColumn();
}

function isTeamAdmin(int $teamId, int $userId): bool {
    $stmt = getDB()->prepare(
        'SELECT 1 FROM TeamMembers
         WHERE team_id = ? AND user_id = ? AND role = "administrator" AND status = "active"
         LIMIT 1'
    );
    $stmt->execute([$teamId, $userId]);
    return (bool)$stmt->fetch();
}

function assertNotLastAdmin(int $teamId, int $userId): void {
    if (!isTeamAdmin($teamId, $userId)) {
        return;
    }
    if (countAdmins($teamId) <= 1) {
        json_response(false,
            "This is the team's only administrator. Make someone else an administrator first.",
            ['needs_successor' => true], 409);
    }
}

// ── INVITATIONS ──────────────────────────────────────────────
const INVITE_TTL_HOURS      = 72;
const INVITE_MAX_RESENDS    = 5;
const INVITE_RESEND_SECONDS = 60;

/** 32 random bytes, hex encoded. Emailed once, never stored. */
function generateInviteToken(): string {
    return bin2hex(random_bytes(32));
}

/** Same one-way treatment the 6-character codes get. */
function hashInviteToken(string $token): string {
    return hash('sha256', $token);
}

function inviteAcceptUrl(string $token): string {
    return APP_URL . '/setup-account.html?invite=' . urlencode($token);
}

function findLiveInvitation(string $token): ?array {
    if ($token === '') {
        return null;
    }
    $stmt = getDB()->prepare(
        'SELECT i.*, t.name AS team_name, u.name AS inviter_name
         FROM Invitations i
         JOIN Teams t ON i.team_id = t.team_id
         JOIN Users u ON i.invited_by = u.user_id
         WHERE i.token_hash = ?
           AND i.accepted_at IS NULL
           AND i.revoked_at IS NULL
           AND i.expires_at > NOW()
           AND t.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([hashInviteToken($token)]);
    return $stmt->fetch() ?: null;
}

function roleLabel(string $role): string {
    return match ($role) {
        'administrator'   => 'Administrator',
        'supervisor'      => 'Supervisor',
        'field_inspector' => 'Field Inspector',
        'field_worker'    => 'Field Worker',
        default           => 'Member',
    };
}

// ── CSRF ─────────────────────────────────────────────────────
function issueCsrfToken(): string {
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    startSecureSession();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$sent)) {
        json_response(false, 'Invalid or missing security token. Please refresh and try again.', [], 403);
    }
}

// ── LOGIN THROTTLING ─────────────────────────────────────────
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MIN   = 15;

function recordLoginAttempt(string $email, bool $ok): void {
    try {
        getDB()->prepare(
            'INSERT INTO LoginAttempts (email, ip_address, successful) VALUES (?, ?, ?)'
        )->execute([$email, $_SERVER['REMOTE_ADDR'] ?? null, $ok ? 1 : 0]);
    } catch (Exception $e) {
        // throttling bookkeeping must never break a login
    }
}

/** Number of failed attempts for this email in the recent window. */
function recentFailedLogins(string $email): int {
    $stmt = getDB()->prepare(
        'SELECT COUNT(*) FROM LoginAttempts
         WHERE email = ? AND successful = 0
           AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $stmt->execute([$email, LOGIN_WINDOW_MIN]);
    return (int)$stmt->fetchColumn();
}

function clearLoginAttempts(string $email): void {
    getDB()->prepare('DELETE FROM LoginAttempts WHERE email = ? AND successful = 0')
           ->execute([$email]);
}

// ── 6-CHARACTER VERIFICATION CODES ───────────────────────────
const CODE_ALPHABET     = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
const CODE_LENGTH       = 6;
const CODE_TTL_MINUTES  = 10;
const CODE_MAX_ATTEMPTS = 5;
const CODE_MAX_RESENDS  = 5;   // per hour, enforced by caller checking last_sent_at/resend_count
const CODE_RESEND_COOLDOWN_SECONDS = 30;

function generateSixCharCode(): string {
    $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits  = '23456789';

    $chars = [];
    for ($i = 0; $i < 3; $i++) {
        $chars[] = $letters[random_int(0, strlen($letters) - 1)];
    }
    for ($i = 0; $i < 3; $i++) {
        $chars[] = $digits[random_int(0, strlen($digits) - 1)];
    }

    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }

    return implode('', $chars);
}

function hashCode(string $code): string {
    return hash('sha256', strtoupper($code));
}

function checkCode(array $row, string $submitted): string {
    // 'ok' | 'expired' | 'locked' | 'wrong'
    if (strtotime($row['expires_at']) < time()) {
        return 'expired';
    }
    if ((int)$row['attempts'] >= CODE_MAX_ATTEMPTS) {
        return 'locked';
    }
    return hash_equals($row['code_hash'], hashCode($submitted)) ? 'ok' : 'wrong';
}

function codeErrorMessage(string $result): string {
    return match ($result) {
        'expired' => 'This verification code has expired. Please request a new code.',
        'locked'  => 'Too many incorrect attempts. Please request a new code.',
        'wrong'   => 'Invalid verification code. Please try again.',
        default   => 'Something went wrong. Please try again.',
    };
}

// ── PASSWORD POLICY ──────────────────────────────────────────
const PASSWORD_POLICY_TEXT = 'Password must contain at least 8 characters, 1 uppercase letter, 1 lowercase letter, and 1 number.';

/** Returns an error message, or null if the password is acceptable. */
function passwordPolicyError(string $pass): ?string {
    if (strlen($pass) < 8)                return PASSWORD_POLICY_TEXT;
    if (!preg_match('/[A-Z]/', $pass))     return PASSWORD_POLICY_TEXT;
    if (!preg_match('/[a-z]/', $pass))     return PASSWORD_POLICY_TEXT;
    if (!preg_match('/[0-9]/', $pass))     return PASSWORD_POLICY_TEXT;
    return null;
}

/* "mark JUSTINE"  -> "Mark Justine" */
function capitalizeWords(string $name): string {
    $name = mb_strtolower(trim($name));
    return preg_replace_callback(
        "/(^|[\s\-'])(\p{L})/u",
        fn($m) => $m[1] . mb_strtoupper($m[2]),
        $name
    );
}

/* no 1 letter name */
function nameIsLongEnough(string $name): bool {
    return mb_strlen(trim($name)) >= 2;
}

// ── ACTIVITY LOG ─────────────────────────────────────────────
function logActivity(
    ?int $userId,
    string $action,
    string $targetType = null,
    int $targetId = null,
    string $description = null,
    ?int $teamId = null
): void {
    try {
        $teamId = $teamId ?? ($_SESSION['active_team_id'] ?? null);
        getDB()->prepare(
            'INSERT INTO ActivityLogs (user_id, team_id, action, target_type, target_id, description, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $teamId, $action, $targetType, $targetId, $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {
        // logging failure should not break the request
    }
}

// ── CODE GENERATORS ──────────────────────────────────────────
function nextCode(string $table, string $column, string $prefix, int $teamId): string {
    $stmt = getDB()->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING($column, ?) AS UNSIGNED)), 0)
         FROM $table WHERE team_id = ?"
    );
    // +2: SUBSTRING is 1-indexed and we skip the prefix plus its hyphen.
    $stmt->execute([strlen($prefix) + 2, $teamId]);
    $next = (int)$stmt->fetchColumn() + 1;
    return $prefix . '-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function generateReportCode(int $teamId): string {
    return nextCode('InspectionReports', 'report_code', 'REP', $teamId);
}

function generateTaskCode(int $teamId): string {
    return nextCode('InspectionTasks', 'task_code', 'TASK', $teamId);
}

function generateWOCode(int $teamId): string {
    return nextCode('WorkOrders', 'wo_code', 'WO', $teamId);
}

// ── INPUT ────────────────────────────────────────────────────
function clean(mixed $val): string {
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$val));
}

function getBody(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? $_POST;
}

// ── FILE UPLOAD ──────────────────────────────────────────────
const ALLOWED_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

const MAX_PHOTO_BYTES  = 10 * 1024 * 1024;
const MAX_PHOTOS_COUNT = 10;

function handleMultiplePhotoUploads(string $fieldName, string $prefix, int $ownerId): array {
    $result = ['saved' => [], 'errors' => []];

    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName]['name'])) {
        return $result;
    }
    $dir = __DIR__ . '/../uploads/photos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $total = count($_FILES[$fieldName]['name']);

    for ($i = 0; $i < $total; $i++) {
        $originalName = $_FILES[$fieldName]['name'][$i] ?: 'file ' . ($i + 1);

        if ($i >= MAX_PHOTOS_COUNT) {
            $result['errors'][] = "$originalName: only the first " . MAX_PHOTOS_COUNT . ' photos per submission are accepted.';
            continue;
        }
        if ($_FILES[$fieldName]['error'][$i] !== UPLOAD_ERR_OK) {
            $result['errors'][] = "$originalName: upload failed.";
            continue;
        }

        $tmpName = $_FILES[$fieldName]['tmp_name'][$i];
        if (!is_uploaded_file($tmpName)) {
            $result['errors'][] = "$originalName: upload could not be verified.";
            continue;
        }

        if (filesize($tmpName) > MAX_PHOTO_BYTES) {
            $result['errors'][] = "$originalName: file is larger than 10MB.";
            continue;
        }

        $mime = mime_content_type($tmpName);
        if (!isset(ALLOWED_IMAGE_TYPES[$mime])) {
            $result['errors'][] = "$originalName: only JPG, PNG or WEBP images are accepted.";
            continue;
        }

        if (@getimagesize($tmpName) === false) {
            $result['errors'][] = "$originalName: file is not a valid image.";
            continue;
        }

        $ext      = ALLOWED_IMAGE_TYPES[$mime];
        $filename = $prefix . $ownerId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

        if (move_uploaded_file($tmpName, $dir . $filename)) {
            $result['saved'][] = 'uploads/photos/' . $filename;
        } else {
            $result['errors'][] = "$originalName: could not be saved.";
        }
    }

    return $result;
}


// ── AVATARS ──────────────────────────────────────────────────
const AVATAR_SIZE      = 256;
const MAX_AVATAR_BYTES = 5 * 1024 * 1024;

function processAvatarUpload(string $fieldName, int $userId): array {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'No photo was received.'];
    }

    $tmp = $_FILES[$fieldName]['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        return ['error' => 'Upload could not be verified.'];
    }
    if (filesize($tmp) > MAX_AVATAR_BYTES) {
        return ['error' => 'Photo must be smaller than 5MB.'];
    }

    $mime = mime_content_type($tmp);
    if (!isset(ALLOWED_IMAGE_TYPES[$mime])) {
        return ['error' => 'Only JPG, PNG or WEBP images are accepted.'];
    }
    $info = @getimagesize($tmp);
    if ($info === false) {
        return ['error' => 'That file is not a valid image.'];
    }

    [$w, $h] = $info;
    if ($w < 64 || $h < 64) {
        return ['error' => 'Photo must be at least 64×64 pixels.'];
    }

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png'  => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
        default      => false,
    };
    if (!$src) {
        return ['error' => 'That image could not be read.'];
    }

    $side = min($w, $h);
    $sx   = (int)(($w - $side) / 2);
    $sy   = (int)(($h - $side) / 2);

    $dst = imagecreatetruecolor(AVATAR_SIZE, AVATAR_SIZE);
    // PNG/WEBP transparency would otherwise come out black once it is JPEG.
    imagefilledrectangle($dst, 0, 0, AVATAR_SIZE, AVATAR_SIZE,
        imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, AVATAR_SIZE, AVATAR_SIZE, $side, $side);
    imagedestroy($src);

    $dir = __DIR__ . '/../uploads/avatars/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = 'av' . $userId . '_' . bin2hex(random_bytes(8)) . '.jpg';
    $ok = imagejpeg($dst, $dir . $filename, 85);
    imagedestroy($dst);

    if (!$ok) {
        return ['error' => 'The photo could not be saved.'];
    }
    return ['path' => 'uploads/avatars/' . $filename];
}

function deleteAvatarFile(?string $path): void {
    if (!$path || !str_starts_with($path, 'uploads/avatars/') || str_contains($path, '..')) {
        return;
    }
    $full = __DIR__ . '/../' . $path;
    if (is_file($full)) @unlink($full);
}
